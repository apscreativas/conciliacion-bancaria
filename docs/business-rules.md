# Business Rules

Reglas de negocio que viven en el código. Si cambias alguna, actualiza este documento en el mismo commit.

---

## 1. Multi-tenancy (Teams)

- Cada usuario pertenece a uno o más `Team` (pivot `team_user` con `role`).
- El contexto activo es `User.current_team_id`.
- Al registrarse, se crea automáticamente un **personal team** (`personal_team=true`) cuyo `name` es `"{firstName}'s Team"` (`app/Models/User.php:18-31`).
- El owner del team **no puede salir** del team (`TeamMemberController::destroy:96`). Debe eliminar el team o transferir ownership.
- Si un miembro es removido y su `current_team_id` apuntaba al team eliminado, el sistema busca automáticamente otro team (`ownedTeams` → `teams` → crear uno personal nuevo) para evitar dejar al usuario sin contexto.
- Solo el owner del team puede:
    - Invitar/eliminar miembros.
    - Editar el team (`TeamController::update`).
    - Configurar la tolerancia.

---

## 2. Invitaciones a Team (two-step)

- `GET /team-invitations/{token}` muestra landing. **No auto-une** aunque el usuario esté logueado — esto previene CSRF y ataques de auto-join por links maliciosos.
- `POST /team-invitations/{token}/join` efectivamente une al usuario.
- Si el invitado no está logueado al hacer POST, se guarda `url.intended` y se redirige a login.
- Si el invitado **es el owner del team** al que se le invitó, la invitación se elimina con un mensaje informativo.
- Token: 32 caracteres generados automáticamente en `TeamInvitation@booted`.
- La invitación se **elimina** tras aceptarla.
- Tests: `tests/Feature/SecurityAuditTest.php` cubre este flujo.

---

## 3. Importación de XML (CFDI)

### Validaciones síncronas (en `FileUploadController::store`)

| Validación | Error |
|---|---|
| Extensión `.xml` | "No es un archivo XML" |
| MIME en `application/xml`, `text/xml`, `text/plain` | "El tipo de archivo no es XML válido" |
| Tamaño ≤ 10 MB | "El archivo excede el tamaño máximo de 10MB" |
| XML parseable por `CfdiParserService` | "XML Inválido - ..." |
| `tipo_comprobante != 'I'` OR `metodo_pago != 'PPD'` | "Esta factura es PPD (...). Suba el Complemento de Pago correspondiente" |
| Team RFC coincide con emisor OR receptor (si team tiene RFC configurado) | "El RFC del equipo ({rfc}) no coincide con el Emisor ({e}) ni con el Receptor ({r}) del XML" |
| UUID no existe ya para el team | "Duplicado ({file}): Esta factura ya fue registrada anteriormente" |

### Regla especial: Complemento de Pago (tipo `P`)

- El XML tipo `P` no tiene `Total` útil (es 0). El parser lee `<pago20:Pago>` o `<pago10:Pago>` y suma los `Monto`.
- `fecha_emision` se reemplaza con `<Pago FechaPago>`.
- Si un complemento tiene múltiples `<Pago>` con fechas distintas, se usa el primero como referencia pero se suma todos los montos (ver comment en `CfdiParserService.php:15-17` — mejora futura: crear una factura por `<Pago>`).
- Si `monto_total <= 0` tras sumar, el XML se rechaza.

### Regla especial: PPD

- Facturas `tipo_comprobante=I & metodo_pago=PPD` (Pago en Parcialidades o Diferido) **se rechazan al subir**. El usuario debe subir el Complemento de Pago correspondiente.
- `ProcessXmlUpload` tiene doble guard: aunque una factura PPD bypasee la validación sync (ej. re-encolada manualmente), el job la marca como `rechazado` (`ProcessXmlUpload.php:54-59`).

### RFC: validación flexible

El team puede ser **emisor** (uploads de ventas) o **receptor** (uploads de gastos). Validación:

```php
$emisorRfc === $teamRfcUpper || $receptorRfc === $teamRfcUpper
```

Si no configuras `team.rfc`, no se valida RFC.

### Flujo híbrido sync + async

1. Validaciones de formato + dedupe UUID: sync (feedback inmediato).
2. `Archivo` se crea con `estatus=pendiente`.
3. `ProcessXmlUpload::dispatch` encola sobre `imports`.
4. El job re-valida (defensa) y crea `Factura`.

---

## 4. Importación de estados de cuenta

### Validaciones sync

- Requiere `bank_code` (ID del `BankFormat` del team).
- Parser valida sincrónicamente (lee el archivo con el formato seleccionado). Si falla devuelve 422 sin encolar.
- Dedup por `checksum` MD5 del archivo — si ya existe un `Archivo` con el mismo checksum (y `estatus != 'fallido'`) se rechaza con toast warning.

### Dedup de movimientos individuales (en `ProcessBankStatement`)

**Regla**: dos movimientos son duplicados si comparten **team_id + fecha + monto + descripcion** (comparación directa, case-sensitive de la descripción).

- El índice `movimientos_dedup_index (team_id, fecha, monto, descripcion)` cubre esta query.
- El `hash` SHA-256 se calcula y guarda por compatibilidad (hay un `UNIQUE(team_id, hash)`), **pero no es la fuente de dedup**.
- Comando `app:recalculate-movement-hashes` existe para recalcular hashes tras cambios y deduplica migrando `conciliacions` al registro más antiguo (el `keepId`) antes de borrar.

### DynamicStatementParser

- Límite de tamaño: 10 MB.
- Columnas se especifican como letras Excel (`A`, `B`, `AB`, etc.) → convertidas a índice 0-based.
- `start_row` 1-based (fila donde empiezan los datos).
- Soporta:
    - `amount_column` único (signo determina tipo abono/cargo).
    - `debit_column` + `credit_column` separados (si debit > 0 → cargo, si credit > 0 → abono).
    - `type_column` (busca "abono/depósito/deposito/crédito" en strings).
- Formatos de fecha soportados:
    - Numérico Excel
    - `d/m/y`, `d/m/Y`
    - `d-m-y`, `d-m-Y`
    - ISO / fallback `Carbon::parse`
- **CSV injection**: celdas que empiezan con `=`, `+`, `-`, `@`, `\t`, `\r` se prefijan con `'` antes de guardarse (`DynamicStatementParser::sanitizeCellValue`).
- Si la fila `start_row` no tiene datos válidos, el parser lanza excepción explicita.

---

## 5. Conciliación — Reglas

### 5.1 Manual (`ReconciliationController::store`)

- Requiere `invoice_ids[]`, `movement_ids[]`, opcional `conciliacion_at`, opcional `confirm_multi_rfc`.
- Ownership: todas las facturas deben ser del team del usuario (403 si no).
- **Validación de RFC**: si hay más de 1 factura y tienen RFCs distintos, requiere `confirm_multi_rfc=true`. Caso de uso: payouts de Stripe que agrupan facturas de distintos contribuyentes.
- **Tolerancia NO se valida en manual**. El frontend advierte, pero el backend permite.
- Dispatcha `MatcherService::reconcile($invoiceIds, $movementIds, 'manual', $date)`.

### 5.2 Automática — `MatcherService::findMatches`

- Filtra facturas y movimientos no conciliados del team en un mes/año específico.
- Límite: 5000 registros por lado (`$maxRecords`).
- Para cada par (factura, movimiento):
    - Si `abs(monto_factura - monto_movimiento) > tolerancia`, se descarta.
    - Score 0-100 sumando 3 pilares:
        - **Monto (0-33)**: `33 * (1 - diff/tolerancia)`. Si `tolerancia=0`, es 33 o 0 (match exacto o nada).
        - **Fecha (0-33)**: `max(0, 33 * (1 - abs_days/30))`. Misma fecha = 33, 30+ días = 0.
        - **Descripción (0-34)**: máximo 34 por uno de:
            - RFC del invoice aparece en descripción del movimiento.
            - UUID o fragmento hexadecimal coincide.
            - Tokens del nombre del receptor coinciden con la descripción (usando `DescriptionParser::nameMatchScore`).
- Confianza:
    - `high ≥ 80`
    - `medium ≥ 50`
    - `low < 50`
- Deduplicación global: cada factura y movimiento aparece en máximo un match (el de mayor score).

### 5.3 Reconciliation — Saldo restante (`MatcherService::reconcile`)

Algoritmo aplicado dentro de `DB::transaction` con `lockForUpdate`:

1. Inicializar `invoiceRemaining[id] = monto` y `movementRemaining[id] = monto`.
2. Epsilon para floats: `0.001`.
3. Para cada factura:
    - Si ya está pagada (remaining < epsilon), skip.
    - Para cada movimiento:
        - Si ya está usado, skip.
        - `amountToApply = min(invoiceRemaining, movementRemaining)`, redondeado a 2 decimales.
        - Si `amountToApply >= epsilon`:
            - Crear `Conciliacion` con `monto_aplicado = amountToApply`.
            - Restar `amountToApply` de ambos remainders (y redondear).
            - Si la factura queda pagada, break al siguiente invoice.
4. Todas las `Conciliacion` del batch comparten `group_id` UUID.

**Ejemplo correcto** (2 facturas $100 + 2 movimientos $100):
- Inv1 vs Mov1: aplica $100. Inv1 pagada. Break.
- Inv2 vs Mov1: skip (Mov1 usado).
- Inv2 vs Mov2: aplica $100. Inv2 pagada.
- Total: 2 registros `Conciliacion` sumando $200. **Sin double-apply**.

### 5.4 Auto-conciliación batch (`ReconciliationController::batch`)

- Frontend envía `matches[]` con pares (invoice_id, movement_id).
- Backend verifica ownership de cada par.
- Llama `MatcherService::reconcile` uno a uno con `type='automatico'` y `date=movement.fecha`.
- Cada par genera un `group_id` distinto.

### 5.5 Desconciliación

- `DELETE /reconciliation/{id}`: elimina un solo registro. Verifica ownership vía `conciliacion.factura.team_id`.
- `DELETE /reconciliation/group/{groupId}`: elimina todos los registros de un grupo. Filtra por `team_id` (tenant-scoped, 404 si no se encuentra).

---

## 6. Tolerancia

- 1 registro por team en `tolerancias` (`firstOrCreate` con `team_id`).
- Solo el owner del team puede editarla (`ToleranciaController::edit:21`).
- Campos: `monto` (decimal), `dias` (int — **no se usa actualmente** en el matcher, solo `monto`).
- Aplica **solamente** a la auto-conciliación. La manual no la valida.

---

## 7. Exports

Ver `flows/export.md` para el flujo completo.

- Rate limit: `throttle:10,1` (10 solicitudes por minuto por IP/usuario).
- Crea `ExportRequest` con `filters` JSON y `status=queued`.
- Jobs async sobre cola `exports` con `timeout=600`, `tries=3`, `backoff=[30,120,300]`.
- Archivos guardados en `exports/{teamId}/{userId}/{uuid}.{ext}` (storage default).
- Download solo permite al `user_id` dueño del export (403 si es otro usuario del mismo team).
- Si el export está `queued` más de 2 minutos, el status endpoint devuelve `is_offline=true` (señal de worker caído).

---

## 8. Dashboard

- Stats por mes/año actual:
    - Facturas pendientes (count + suma)
    - Movimientos pendientes tipo `abono/Abono` (count + suma)
    - Conciliaciones del mes actual
    - Recent activity: últimas 5 conciliaciones
- Comparación vs mes anterior: conciliaciones, pagos, facturas.

---

## 9. Filtros globales (month / year)

- Middleware `SetGlobalDateFilters` persiste `month`/`year` en sesión (`global_month`, `global_year`).
- Si request tiene `month`/`year`, se actualiza sesión.
- Si no, se usa el valor de sesión o `now()->month`/`now()->year` como default.
- Se mergea en `$request` para que los controllers los vean siempre.

---

## 10. Flags y feature toggles

No hay feature flags en el código. Los cambios de comportamiento se manejan por migraciones y despliegue.

---

## 11. Nómina quincenal (Finanzas Fase 3B)

El comando `nomina:generar` (`app/Console/Commands/GenerarNomina.php`, schedule diario 01:30) materializa la nómina de los `empleados` activos como `egresos`. Reglas:

- **Quincenas**: dos por mes — día **15** (Q1) y **último día del mes** (Q2). La fecha de **pago** se ajusta al **día hábil anterior** si cae en sábado/domingo (reusa `RecurrenceCalculator::applyDiaHabil` vía `PayrollCalculator`). Sin festivos en v1. La **fecha nominal** (15 / fin de mes, sin ajustar) define elegibilidad/periodo; la **fecha de pago** (ajustada) es la `fecha` del egreso.
- **Mitad por quincena**: los salarios del empleado son **mensuales**; cada quincena genera la mitad. Fiscal = `salario_fiscal / 2`; complemento = `(salario_real - salario_fiscal) / 2` (redondeados a 2 decimales).
- **Dos conceptos por quincena** (`concepto_nomina`): `fiscal` y `complemento`.
- **Mapeo de categoría por clasificación** (por nombre exacto, categoría activa `tipo=egreso` del team):
    - Parte **fiscal** de empleado `clasificacion='tecnica'` → **"Nómina técnica facturable"** (grupo `costo_venta`/COGS).
    - Parte **fiscal** de empleado `administrativa` o sin clasificar (null) → **"Nómina fiscal"** (grupo `gasto_operativo`).
    - Parte **complemento** (cualquier clasificación) → **"Nómina complemento / real"**.
    - Si el team no tiene la categoría requerida, ese egreso se **omite** (se registra `Log::warning`); el comando no truena.
- **Complemento ≤ 0 omitido**: si `salario_real == salario_fiscal` (o real < fiscal, imposible por validación) no se crea el egreso de complemento.
- **Elegibilidad por fecha nominal**: no se genera una quincena cuya fecha **nominal** sea anterior a `fecha_entrada` ni posterior a `fecha_baja`. Por eso un alta a mitad de periodo (ej. día 16) **no** cobra la Q1 de ese mes, y una **baja a mitad de periodo** (ej. día 20) cobra la Q1 (nominal 15 ≤ 20) pero **no** la Q2 (nominal 30 > 20).
- **Empleados inactivos** (`activo=false`) se omiten por completo.
- **Idempotencia**: garantizada por el índice único `egresos_empleado_periodo_unique (empleado_id, fecha, concepto_nomina)` + `exists()` + `try/catch` de `QueryException`. Cambiar la `clasificacion` de un empleado entre corridas **no** duplica el egreso fiscal de una quincena ya generada (la clave es `concepto_nomina`, no la categoría).
- **Origen**: los egresos de nómina se marcan `origen='recurrente'` y portan `empleado_id`.

---

## 12. Ingresos manuales (Finanzas Fase 4)

El CRUD `/cash-income` (`IngresoManualController`, modelo `IngresoManual`) registra los **ingresos reales que NO pasan por banco** — pagos en efectivo. Es el espejo de los egresos manuales (Fase 2) con categorías de ingreso. Reglas:

- **Naturaleza del registro:** efectivo no bancario. El campo `metodo` es enum(`efectivo`,`otro`) con default `efectivo` (no el set de 4 métodos de egresos). El ingreso bancario ya vive en `conciliacions` (factura ↔ abono conciliado); esto captura **solo** el efectivo que el banco no registra.
- **Categoría tipo=ingreso:** `categoria_id` es requerida a nivel app y validada con `exists` scoped al team **y `tipo=ingreso`** — una categoría de egreso se rechaza (422). En DB es nullable con `nullOnDelete` (borrar la categoría no borra el ingreso, queda "Sin categoría").
- **Empresa opcional:** `empresa_id` opcional + `exists` scoped al team; `nullOnDelete`. `monto` > 0 (`gt:0`); `cliente` opcional.
- **Acceso:** **cualquier miembro del team** (captura operativa, igual que egresos; sin owner-gate). `store` setea `team_id` + `user_id` (creador). Tenancy por `TeamOwned`: un registro de otro team → 404.
- **Fuente del P&L:** es una de las dos fuentes de **ingresos** del Estado de Resultados (Fase 5), junto con los ingresos bancarios conciliados con `empresa_id` asignado (ver PRD §4.1). No hay riesgo de doble conteo: el efectivo manual no aparece en `movimientos`.
- **Totales:** `total` y `totalsByCategoria` (con bucket "Sin categoría") se calculan sobre el conjunto filtrado, no solo la página; `per_page` con whitelist (basura → 25, evita `paginate(0)` → 500).

---

## Referencias

- `app/Services/Reconciliation/MatcherService.php`
- `app/Services/Reconciliation/DescriptionParser.php`
- `app/Services/Xml/CfdiParserService.php`
- `app/Services/Parsers/DynamicStatementParser.php`
- `app/Http/Controllers/FileUploadController.php`
- `app/Http/Controllers/ReconciliationController.php`
- `app/Http/Middleware/SetGlobalDateFilters.php`
- `app/Console/Commands/GenerarNomina.php`
- `app/Services/Finance/PayrollCalculator.php`
- `app/Http/Controllers/IngresoManualController.php`
