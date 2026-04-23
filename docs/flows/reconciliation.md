# Flow: Reconciliation

Flujo de conciliación manual y automática entre facturas y movimientos.

**Controller**: `app/Http/Controllers/ReconciliationController.php`
**Service**: `app/Services/Reconciliation/MatcherService.php`
**Auxiliar**: `app/Services/Reconciliation/DescriptionParser.php`

---

## Conceptos clave

| Concepto | Descripción |
|---|---|
| **Conciliación** | Vinculación de una o más facturas con uno o más movimientos mediante registros en tabla `conciliacions` |
| **Group ID** | UUID que agrupa todos los registros `Conciliacion` creados en una sola operación. Permite desconciliar "como grupo" |
| **Saldo restante** | Algoritmo en `MatcherService::reconcile` que trackea `monto - ya_aplicado` para evitar double-application |
| **Score** | Puntaje 0-100 en auto-matching. Tres pilares: Monto (33) + Fecha (33) + Descripción (34) |
| **Confianza** | `high ≥ 80`, `medium ≥ 50`, `low < 50` |
| **Tolerancia** | Diferencia máxima (en pesos) permitida entre monto de factura y movimiento. Aplica solo a auto-match |

---

## Flujo 1 — Conciliación manual

### Entry point

```
GET  /reconciliation          → Workbench UI
POST /reconciliation          → Ejecuta conciliación
```

### UI

Página `Reconciliation/Workbench.vue`:
- Columna izquierda: facturas pendientes (max 200) filtradas por mes/año o rango de fechas.
- Columna derecha: movimientos pendientes tipo `abono` (max 200).
- Usuario selecciona múltiples elementos en cada columna.
- `ReconciliationModal` muestra diferencia y pide confirmación.

### Request al backend

```json
POST /reconciliation
{
  "invoice_ids": [1, 2, 3],
  "movement_ids": [10, 11],
  "conciliacion_at": "2026-04-15",       // opcional
  "confirm_multi_rfc": false             // opcional
}
```

### Validaciones (`ReconciliationController::store`)

1. `invoice_ids` y `movement_ids` requeridos, arrays.
2. Ownership: todas las facturas del request pertenecen al team (403 si no).
3. **Multi-RFC guardrail**: si hay >1 factura y sus `rfc` no son todos iguales, se requiere `confirm_multi_rfc=true`. Caso de uso: Stripe payouts que bundlean facturas de distintos contribuyentes.
4. **Tolerancia NO se valida**. El frontend puede advertir pero el backend permite.

### Ejecución

`MatcherService::reconcile($invoiceIds, $movementIds, 'manual', $date)` — ver sección "Algoritmo" abajo.

### Response

Redirect `back()` con flash `success`: "Conciliación manual registrada exitosamente."

---

## Flujo 2 — Conciliación automática

### Entry point

```
GET  /reconciliation/auto     → Lista sugerencias
POST /reconciliation/batch    → Aplica seleccionadas
```

### Fase 1: Sugerencias (`ReconciliationController::auto`)

1. Obtiene tolerancia del team (default 0).
2. Llama `MatcherService::findMatches($teamId, $tolerance, $month, $year)`.
3. Renderiza `Reconciliation/Matches.vue` con `matches[]`.

### `MatcherService::findMatches` — algoritmo

1. Carga facturas del team no conciliadas en el mes/año (límite 5000).
2. Carga movimientos del team no conciliados tipo abono en el mes/año (límite 5000).
3. Pre-parsea todas las descripciones con `DescriptionParser` (extrae RFCs, fragmentos UUID, tokens de nombre).
4. Para cada par (factura × movimiento):
    - Skip si `abs(monto_f - monto_m) > tolerancia`.
    - Calcular score por pilares:
        - **Monto**: `33 * (1 - diff/tolerancia)`. Exacto = 33, borde = 0.
        - **Fecha**: `max(0, 33 * (1 - abs_days/30))`. Mismo día = 33, 30+ días = 0.
        - **Descripción**: 0 o 34 (no incremental). Se activa si:
            - RFC del invoice aparece en descripción del movimiento, o
            - Fragmento UUID (6+ hex chars) coincide, o
            - Tokens del `nombre` del receptor matchean (vía `DescriptionParser::nameMatchScore`).
    - `score = min(monto + fecha + desc, 100)`.
    - Confianza: `high ≥80`, `medium ≥50`, else `low`.
5. Ordena matches por score descendente.
6. **Deduplicación global**: cada factura y cada movimiento aparece en máximo 1 match (el de mayor score gana).
7. Devuelve array de matches con `invoice`, `movement`, `score`, `difference`, `confidence`, `match_reasons`.

### Fase 2: Aplicación batch (`ReconciliationController::batch`)

```json
POST /reconciliation/batch
{
  "matches": [
    { "invoice_id": 1, "movement_id": 10 },
    { "invoice_id": 2, "movement_id": 11 }
  ]
}
```

- Valida ownership de cada par.
- Por cada par: `MatcherService::reconcile([inv_id], [mov_id], 'automatico', $movement->fecha)`.
- Cada par genera un `group_id` UUID distinto (no se agrupan).

---

## Algoritmo de reconciliación (`MatcherService::reconcile`)

Aplicado siempre dentro de `DB::transaction` con `lockForUpdate` sobre facturas y movimientos.

### Inputs

- `$invoiceIds` — array de IDs de factura.
- `$movementIds` — array de IDs de movimiento.
- `$type` — `'manual'` o `'automatico'`.
- `$date` — fecha a registrar en `fecha_conciliacion`.

### Algoritmo de saldo restante

```
groupId = UUID()
invoiceRemaining  = { id => monto }  ∀ invoice
movementRemaining = { id => monto }  ∀ movement
epsilon = 0.001

para cada invoice:
    si invoiceRemaining[id] < epsilon: skip
    para cada movement:
        si movementRemaining[id] < epsilon: skip
        amount = min(invoiceRemaining, movementRemaining)
        amount = round(amount, 2)
        si amount >= epsilon:
            Conciliacion::create(
              group_id, factura_id, movimiento_id,
              monto_aplicado=amount, tipo, estatus='conciliado',
              fecha_conciliacion=date
            )
            invoiceRemaining  -= amount
            movementRemaining -= amount
            si invoiceRemaining < epsilon: break (siguiente invoice)
```

### Ejemplo 1:1

- Inv 1: $100, Mov 1: $100 → 1 Conciliacion por $100.

### Ejemplo 1:N

- Inv 1: $150, Mov 1: $100, Mov 2: $50 → 2 Conciliaciones ($100 y $50), total aplicado $150.

### Ejemplo N:M (2×2 equal)

- Inv 1 $100, Inv 2 $100, Mov 1 $100, Mov 2 $100
- Inv 1 vs Mov 1: $100 aplicado. Inv 1 pagado → break.
- Inv 2 vs Mov 1: skip (Mov 1 usado).
- Inv 2 vs Mov 2: $100 aplicado. Inv 2 pagado.
- **Total**: 2 registros, $200 aplicados. Sin double-apply.

Ver `decisions/0003-matcher-remaining-amount.md`.

---

## Desconciliación

### Un solo registro

```
DELETE /reconciliation/{id}
```

- Busca el registro.
- Verifica ownership vía `conciliacion.factura.team_id`.
- Borra. Otras `Conciliacion` del mismo `group_id` **no se tocan**.

### Grupo completo

```
DELETE /reconciliation/group/{groupId}
```

- Borra todos los registros con ese `group_id` **y** `team_id = current_team_id`.
- Si no se borró nada (grupo no existe o no es del team) → 404 (previene enumeration).

---

## Historial (`/reconciliation/history`)

- Paginador agrupado por `group_id`.
- Filtros soportados: `search`, `month`, `year`, `date_from`, `date_to`, `amount_min`, `amount_max`, `per_page`.
- Para cada grupo paginado, hace una segunda query para traer detalles completos (facturas + movimientos + user) y transforma la estructura:

```json
{
  "id": "<group_id>",
  "created_at": "...",
  "user": {...},
  "invoices": [...],
  "movements": [...],
  "total_invoices": 250.00,
  "total_movements": 250.00,
  "total_applied": 250.00
}
```

---

## Status Dashboard (`/reconciliation/status`)

Vista ejecutiva con 4 listas paralelas:
- `pendingInvoices` (max 200)
- `pendingMovements` (max 200, solo abonos)
- `conciliatedInvoices` (max 50)
- `conciliatedMovements` (max 50)

+ sumas totales de cada. Filtros: search, date_from/to, month/year, amount_min/max, sort independiente por facturas y movimientos.

---

## Tests relevantes

- `tests/Feature/MatcherServiceTest.php` — auto-match en mes/año con tolerancia.
- `tests/Unit/ReconciliationLogicTest.php` — algoritmo de saldo restante.
- `tests/Feature/ReconciliationTest.php` — store/batch/destroy end-to-end.
- `tests/Feature/ReconciliationTenancyTest.php` — aislamiento entre teams.
- `tests/Feature/SecurityAuditTest.php` — `matcher service source code contains record limit`, `destroy group only deletes own team records`.

---

## Referencias

- `app/Http/Controllers/ReconciliationController.php`
- `app/Services/Reconciliation/MatcherService.php`
- `app/Services/Reconciliation/DescriptionParser.php`
- `resources/js/Pages/Reconciliation/`
