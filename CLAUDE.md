# CLAUDE.md — Reglas operativas para Claude en este repositorio

> Este archivo es la guía **específica** para Claude Code (o cualquier agente IA) al trabajar en este repo.
> Para convenciones de stack Laravel/Vue ver `AGENTS.md`. Para docs técnicas ver `docs/INDEX.md`.

---

## 0. Contexto del proyecto en una línea

App Laravel 12 + Inertia v2 + Vue 3 (TS) multi-tenant (por `Team`) para conciliación de CFDI (XML) contra estados de cuenta bancarios, con colas Redis (`imports`, `exports`) y exportación asíncrona Excel/PDF.

---

## 1. Reglas obligatorias (NO negociables)

### 1.1 Antes de modificar CUALQUIER archivo

1. **Leer el archivo completo** — nunca editar basándose solo en nombre, grep parcial o docs.
2. **Leer sus callers** si tocas un método público — `grep` del nombre del método en todo el repo.
3. **Leer las migraciones afectadas** si tocas un modelo — el esquema real vive en `database/migrations/`.
4. **Verificar si hay tests** en `tests/Feature/` y `tests/Unit/` que cubran el área — corre esos tests antes y después del cambio.

### 1.2 No asumir comportamiento

- **Nunca** dar por sentado que la documentación refleja el código. Cuando código y docs contradicen → **el código gana**, y **actualizas la doc** en la misma rama.
- Los nombres de archivo/clase **no** son contratos: lee la implementación.
- Las auditorías en `docs/_archive/` están marcadas SUPERSEDED — son histórico, no referencia actual.

### 1.3 Tenancy — la regla que más se viola

- Todos los modelos de dominio (`Factura`, `Movimiento`, `Conciliacion`, `Archivo`, `BankFormat`, `ExportRequest`, `Tolerancia`) usan el trait `App\Models\Traits\TeamOwned` que aplica un **global scope automático** que filtra por `team_id = Auth::user()->current_team_id`.
- Esto significa:
    - Cualquier `Factura::find($id)` ya está filtrado al `current_team_id` del usuario.
    - `::withoutGlobalScopes()` solo se usa en comandos artisan, migraciones, seeders o tests (nunca en código de request).
    - Al crear registros bajo contexto de usuario, `team_id` se setea automáticamente.
- **Defense in depth**: aun con el scope, los controllers hacen `where('team_id', auth()->user()->current_team_id)` de nuevo — no quitar esta duplicación aunque "parezca redundante".
- Modelo excepción: `Banco` **NO** usa `TeamOwned` (datos de referencia globales).

### 1.4 No tocar sin entender

Áreas críticas que requieren lectura profunda **antes** de cualquier cambio:

| Archivo | Por qué es frágil |
|---|---|
| `app/Services/Reconciliation/MatcherService.php` | Transacción con `lockForUpdate`, algoritmo de `remainingAmount` con epsilon `0.001`. Un bug aquí = corrupción financiera |
| `app/Http/Controllers/FileUploadController.php` | Validación híbrida sync/async, sanitización MIME, dedupe UUID/checksum, validación RFC flexible, rechazo PPD |
| `app/Jobs/ProcessXmlUpload.php` y `ProcessBankStatement.php` | Transiciones de estado `archivo.estatus` → `pendiente`/`procesando`/`procesado`/`fallido`/`duplicado`/`rechazado` |
| `app/Services/Parsers/DynamicStatementParser.php` | Parseo de múltiples formatos de fecha, CSV injection sanitization, `amount_column` vs `debit/credit` |
| `app/Models/Traits/TeamOwned.php` | Un cambio aquí impacta TODOS los modelos de dominio |
| `app/Http/Middleware/SetGlobalDateFilters.php` | Inyecta `month`/`year` en `$request` globalmente desde sesión — afecta dashboard, listados, matcher |

---

## 2. Flujo de trabajo obligatorio para cualquier cambio no-trivial

```
1. AUDIT        → Leer código afectado, callers, tests existentes, migraciones
2. UNDERSTAND   → Escribir en 3-5 líneas qué hace hoy y qué debe hacer después
3. PLAN         → Listar archivos a tocar y orden. Si son >3 archivos, pedir confirmación
4. VALIDATE     → Confirmar con el usuario si hay impacto en: tenancy, transacciones, colas, migraciones, contratos Inertia
5. IMPLEMENT    → Cambios mínimos. Nada de refactor oportunista fuera del scope
6. TEST         → `vendor/bin/sail artisan test --compact --filter=<relevante>` antes de declarar terminado
7. UPDATE DOCS  → Si aplica: `docs/endpoints.md`, `docs/domain.md`, `docs/business-rules.md`, `docs/flows/*`
```

Si el cambio es una corrección pequeña (<20 líneas, 1 archivo, no toca tenancy/cola/transacción): puedes saltar los pasos 3 y 4.

---

## 3. Reglas técnicas

### 3.1 Base de datos

- Nunca `DB::` si puedes usar `Model::query()`.
- Migraciones nuevas **deben** incluir `team_id` si el modelo es de dominio.
- Dedup de movimientos: **comparación directa por columnas** (`team_id` + `fecha` + `monto` + `descripcion`). El índice `movimientos_dedup_index` cubre esta query. El `hash` se mantiene por compatibilidad, **no** es la fuente de dedup.
- Conciliación **siempre** dentro de `DB::transaction` con `lockForUpdate` sobre facturas y movimientos seleccionados.

### 3.2 Colas

- Jobs pesados → `ShouldQueue` con `onQueue('imports')` o `onQueue('exports')`.
- Todos los jobs existentes tienen `tries=3` y `backoff=[30,120,300]`. Los nuevos deben seguir este patrón.
- En producción los workers son procesos Docker (`queue-imports`, `queue-exports`) o Supervisor (ver `README.md`).
- Si un job toca `Archivo` o `ExportRequest`, **debe** manejar el estado `fallido`/`failed` en `failed()`.

### 3.3 Controllers

- Usar `auth()->user()->current_team_id` para tenant, nunca pasarlo por request.
- Validación: en-línea para casos simples; extraer a `FormRequest` cuando >6 reglas o se reutilice.
- Rutas que disparan jobs pesados deben usar throttle (`routes/web.php:47` usa `throttle:10,1` en export como referencia).
- Para 403 vs 404: usar 403 cuando el recurso existe pero el usuario no es dueño; usar 404 cuando no existe en el team del usuario (también previene enumeration).

### 3.4 Validaciones backend que SÍ se aplican

- Upload XML: extensión, MIME whitelist, tamaño ≤10MB, UUID único por team, RFC del team coincide con emisor O receptor, rechazo `tipo=I & metodo=PPD`.
- Upload statement: requiere `bank_code` (BankFormat del team), parser valida sincronamente antes de encolar, dedup por checksum.
- Conciliación manual: ownership de todas las facturas/movimientos, validación de RFC idéntico a menos que `confirm_multi_rfc=true`.
- Tolerancia: **solo** aplica al auto-matcher. La conciliación manual **no** bloquea por tolerancia (decisión de negocio — ver `docs/business-rules.md`).

### 3.5 No duplicar lógica

- Parseo de fechas bancarias → usar `DynamicStatementParser::parseDate`.
- Extracción de RFC/UUID desde texto → usar `DescriptionParser`.
- Parseo de CFDI → usar `CfdiParserService`.
- Export de conciliación → extender `ReconciliationExport`/`ReconciliationPdfExport`, no duplicar queries.

---

## 4. Reglas de documentación

### 4.1 Regla del workspace

Todo cambio que toque funcionalidad, reglas de conciliación, imports/parsers, modelo de datos, rutas, endpoints, o flujo UI **debe** reflejarse en `docs/` en el mismo commit/PR. Si no está documentado, el cambio está incompleto.

### 4.2 Qué archivo tocar según el cambio

| Cambias… | Actualizas… |
|---|---|
| `routes/web.php` | `docs/endpoints.md` |
| Migración o model relationship | `docs/domain.md` |
| Lógica en `Services/Reconciliation/` | `docs/business-rules.md` + `docs/flows/reconciliation.md` |
| `FileUploadController`, parsers, jobs de imports | `docs/flows/import-{xml,statement}.md` |
| `Jobs/GenerateReconciliation*` o `ExportRequest` | `docs/flows/export.md` |
| Middleware, shared Inertia props, providers | `docs/architecture.md` |
| Command artisan nuevo | `docs/operations.md` |
| Validaciones, rate limits, autorizaciones | `docs/security.md` |
| Decisión arquitectónica no obvia | Nuevo `docs/decisions/NNNN-titulo.md` |

### 4.3 Al detectar inconsistencia

Si encuentras que código y docs contradicen y **no** puedes arreglar ambos en el mismo cambio:
- Deja un bloque `> ⚠️ DOC DRIFT: ...` arriba del documento afectado con la fecha y una línea explicando la divergencia.
- Abre un issue o repórtalo al usuario.
- **No silencies la divergencia** "arreglando" la doc a un estado que no refleja el código.

### 4.4 Al detectar una regla no documentada

Si encuentras lógica de negocio en código que no está en docs (ej. "PPD se rechaza al subir"), añadirla a `docs/business-rules.md` **es parte de cerrar el cambio**, aunque tu cambio no la haya introducido.

---

## 5. Convenciones del proyecto (basadas en estructura real)

### 5.1 PHP

- PHP `^8.2` (ver `composer.json`). Dev env Sail usa 8.5.2. No uses features que requieran 8.3+ sin confirmar.
- `declare(strict_types=1)` **no** se usa hoy en el repo — no lo introduzcas solo.
- Constructor property promotion para servicios inyectables.
- Return types explícitos en métodos públicos.

### 5.2 Nombres

- Modelos y columnas en español cuando son de dominio (`Factura.monto`, `Movimiento.descripcion`, `Conciliacion.fecha_conciliacion`). NO los traduzcas.
- Servicios en inglés (`MatcherService`, `DescriptionParser`).
- Rutas en inglés (`/reconciliation`, `/movements`, `/invoices`, `/bank-formats`).
- Vue pages en inglés (`Reconciliation/Workbench.vue`).
- Traits custom en `app/Models/Traits/`.
- La tabla `conciliacions` tiene typo histórico (falta la `e`) — **no renombrar**, hay backfills y tests dependientes.

### 5.3 Frontend

- Composition API + `<script setup>` + TypeScript.
- `<Link>` de Inertia, nunca `<a href>` para navegación interna.
- Navegación programática: `router.visit()`.
- i18n: `laravel-vue-i18n` con archivos en `lang/*.json`. Locale por defecto `es`, persistido en `localStorage`.
- Ziggy para `route()` en JS/Vue.
- Componentes compartidos en `resources/js/Components/`. Específicos de pantalla en `Pages/<Seccion>/Partials/`.

### 5.4 Tests

- Pest 4 para tests nuevos. Feature por defecto, Unit solo si es lógica pura.
- `RefreshDatabase` (cuidado: es costoso — lleva eso en cuenta con muchos tests).
- Factories en `database/factories/` para todos los modelos de dominio.
- Preferir `actingAs($user)` + `$this->postJson(route('x'))` sobre URLs hardcodeadas.
- Tests de tenancy (`ReconciliationTenancyTest`, `SecurityAuditTest`) son **no negociables** — no removerlos.

---

## 6. Cosas que NO hacer

- ❌ No usar `DB::raw` sin razón documentable — preferir Eloquent.
- ❌ No hacer `Model::withoutGlobalScopes()` en controllers — indica que estás rompiendo tenancy.
- ❌ No crear archivos `.md` adicionales en root — docs van en `docs/`.
- ❌ No deshabilitar middleware `SetGlobalDateFilters` — otros controllers dependen de que `month`/`year` estén presentes.
- ❌ No renombrar la tabla `conciliacions`.
- ❌ No introducir `soft deletes` sin consenso — el sistema usa hard delete con cascade para permitir re-import.
- ❌ No usar `env()` fuera de `config/`.
- ❌ No actualizar Tailwind: hay inconsistencia `v3` + `v4` pendiente — ver `docs/decisions/0005`.
- ❌ No correr `--no-verify`, `--force` en git, ni saltar hooks.

---

## 7. Cuando el usuario pide algo ambiguo

Si el usuario dice "arregla el matcher" o "optimiza la carga":
1. Pregunta qué síntoma está viendo (no asumas bug).
2. Pide reproducir con datos.
3. Verifica contra tests existentes (`MatcherServiceTest.php`, `RegressionTest.php`, `FileUploadTest.php`).
4. **Rechaza** hacer cambios especulativos en áreas críticas §1.4 sin síntoma concreto.

---

## 8. Referencias rápidas

- Stack y versiones: `AGENTS.md` §Foundational Context.
- Setup local: `README.md`.
- Deploy prod + Supervisor + Nginx: `README.md`.
- Docs técnicas: `docs/INDEX.md` (fuente de verdad viva).
- Legado y auditorías viejas: `docs/_archive/` — solo lectura.
