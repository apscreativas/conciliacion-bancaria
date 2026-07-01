# Endpoints

Todas las rutas están protegidas por middleware `auth` excepto donde se indique. Todas también pasan por `SetGlobalDateFilters` y `HandleInertiaRequests`.

Evidencia: `routes/web.php`, `routes/auth.php`.

---

## Público (sin auth)

| Método | URI | Controller@action | Nombre | Renderiza / Acción |
|---|---|---|---|---|
| GET | `/` | closure | — | `Welcome` Inertia |
| GET | `/team-invitations/{token}` | `TeamInvitationController@show` | `team-invitations.accept` | `Teams/InvitationLanding` — landing page. GET seguro, NO auto-une |
| POST | `/team-invitations/{token}/join` | `TeamInvitationController@accept` | `team-invitations.join` | Une al usuario al team (requiere auth, redirige a `login` si no) |

---

## Autenticación (Breeze, `routes/auth.php`)

### Grupo `guest`

| Método | URI | Controller@action | Nombre |
|---|---|---|---|
| GET | `/register` | `RegisteredUserController@create` | `register` |
| POST | `/register` | `RegisteredUserController@store` | — |
| GET | `/login` | `AuthenticatedSessionController@create` | `login` |
| POST | `/login` | `AuthenticatedSessionController@store` | — |
| GET | `/forgot-password` | `PasswordResetLinkController@create` | `password.request` |
| POST | `/forgot-password` | `PasswordResetLinkController@store` | `password.email` |
| GET | `/reset-password/{token}` | `NewPasswordController@create` | `password.reset` |
| POST | `/reset-password` | `NewPasswordController@store` | `password.store` |

### Grupo `auth`

| Método | URI | Controller@action | Nombre |
|---|---|---|---|
| GET | `/verify-email` | `EmailVerificationPromptController` | `verification.notice` |
| GET | `/verify-email/{id}/{hash}` | `VerifyEmailController` (`signed`, `throttle:6,1`) | `verification.verify` |
| POST | `/email/verification-notification` | `EmailVerificationNotificationController@store` (`throttle:6,1`) | `verification.send` |
| GET | `/confirm-password` | `ConfirmablePasswordController@show` | `password.confirm` |
| POST | `/confirm-password` | `ConfirmablePasswordController@store` | — |
| PUT | `/password` | `PasswordController@update` | `password.update` |
| POST | `/logout` | `AuthenticatedSessionController@destroy` | `logout` |

---

## Dashboard

| Método | URI | Controller@action | Nombre | Middleware extra | Notas |
|---|---|---|---|---|---|
| GET | `/dashboard` | `DashboardController@index` | `dashboard` | `verified` | Stats por mes/año, comparación vs mes anterior |

---

## Profile

| Método | URI | Controller@action | Nombre |
|---|---|---|---|
| GET | `/profile` | `ProfileController@edit` | `profile.edit` |
| PATCH | `/profile` | `ProfileController@update` | `profile.update` |
| DELETE | `/profile` | `ProfileController@destroy` | `profile.destroy` |

---

## Uploads

| Método | URI | Controller@action | Nombre | Notas |
|---|---|---|---|---|
| POST | `/upload/files` | `FileUploadController@store` | `upload.store` | Híbrido: acepta `files[]` (XML) y/o `statement` + `bank_code`. Ver `flows/import-xml.md` y `flows/import-statement.md` |

---

## Teams

| Método | URI | Controller@action | Nombre |
|---|---|---|---|
| GET | `/teams/create` | `TeamController@create` | `teams.create` |
| POST | `/teams` | `TeamController@store` | `teams.store` |
| PUT | `/teams/{team}` | `TeamController@update` | `teams.update` |
| PUT | `/current-team` | `CurrentTeamController@update` | `current-team.update` |
| GET | `/teams/members` | `TeamMemberController@index` | `teams.show` |
| POST | `/teams/members` | `TeamMemberController@store` | `team-members.store` |
| DELETE | `/teams/{team}/members/{user}` | `TeamMemberController@destroy` | `team-members.destroy` |
| DELETE | `/team-invitations/{invitation}` | `TeamInvitationController@destroy` | `team-invitations.destroy` |

---

## Reconciliación

| Método | URI | Controller@action | Nombre | Middleware extra |
|---|---|---|---|---|
| GET | `/reconciliation` | `ReconciliationController@index` | `reconciliation.index` | — |
| POST | `/reconciliation` | `ReconciliationController@store` | `reconciliation.store` | — |
| GET | `/reconciliation/auto` | `ReconciliationController@auto` | `reconciliation.auto` | — |
| POST | `/reconciliation/batch` | `ReconciliationController@batch` | `reconciliation.batch` | — |
| DELETE | `/reconciliation/{id}` | `ReconciliationController@destroy` | `reconciliation.destroy` | — |
| DELETE | `/reconciliation/group/{groupId}` | `ReconciliationController@destroyGroup` | `reconciliation.group.destroy` | — |
| PATCH | `/reconciliation/group/{groupId}/empresa` | `ReconciliationController@updateGroupEmpresa` | `reconciliation.group.empresa.update` | Asigna/des-asigna `empresa_id` a todas las filas del grupo (Finanzas Fase 1). Scope `team_id` (404 si ajeno); `empresa_id` nullable + `exists` scoped (422 si de otro team) |
| GET | `/reconciliation/history` | `ReconciliationController@history` | `reconciliation.history` | — |
| GET | `/reconciliation/status` | `ReconciliationController@status` | `reconciliation.status` | — |
| GET | `/reconciliation/export` | `ReconciliationController@export` | `reconciliation.export` | **`throttle:10,1`** |
| GET | `/reconciliation/export/{id}/status` | `ReconciliationController@checkExportStatus` | `reconciliation.export.status` | — |
| GET | `/reconciliation/export/{id}/download` | `ReconciliationController@downloadExport` | `reconciliation.export.download` | — |

### Pages Inertia renderizadas

| Endpoint | Page |
|---|---|
| `/reconciliation` | `Reconciliation/Workbench` (props: `invoices`, `movements`, `tolerance`, `filters`) |
| `/reconciliation/auto` | `Reconciliation/Matches` (props: `matches`, `tolerance`) |
| `/reconciliation/history` | `Reconciliation/History` (props: `reconciledGroups` paginator con transform custom —cada grupo incluye `empresa`—, `empresas` lista activa del team, `filters`) |
| `/reconciliation/status` | `Reconciliation/Status` (props: `conciliatedInvoices`, `conciliatedMovements`, `pendingInvoices`, `pendingMovements` + totales + `filters`) |

### Flujo de export

1. `GET /reconciliation/export?format=xlsx|pdf&...filters` → crea `ExportRequest` status `queued`, dispatcha job, responde JSON `{id, status, message}` si `wantsJson()`.
2. Frontend hace polling `GET /reconciliation/export/{id}/status` → devuelve `{status, error_message, is_offline}` (`is_offline=true` si `queued > 2min`).
3. Al completar: `GET /reconciliation/export/{id}/download` → `Storage::download(...)`.

Ver `flows/export.md` para detalle.

---

## Movimientos

| Método | URI | Controller@action | Nombre | Notas |
|---|---|---|---|---|
| GET | `/movements` | `MovimientoController@index` | `movements.index` | Vista dual: lista de `files` (archivos) + paginator de `movements` |
| POST | `/movements/batch-destroy` | `MovimientoController@batchDestroy` | `movements.batch-destroy` | Borra múltiples archivos por IDs |
| GET | `/movements/{file}` | `MovimientoController@show` | `movements.show` | **JSON** — devuelve movimientos de un archivo |
| DELETE | `/movements/{file}` | `MovimientoController@destroy` | `movements.destroy` | Cascade delete archivo + movimientos |

### Filtros soportados en `/movements`

- `month`, `year`, `date` (single date, filtra por `archivos.created_at`)
- `date_from`, `date_to` (sobre `movimientos.fecha`)
- `amount_min`, `amount_max`
- `per_page` (`10` | `25` | `50` | `all` → 10000)
- `sort_by` (`fecha` | `monto` | `bank`), `sort_order` (`asc` | `desc`)

---

## Facturas

| Método | URI | Controller@action | Nombre |
|---|---|---|---|
| GET | `/invoices` | `FacturaController@index` | `invoices.index` |
| POST | `/invoices/batch-destroy` | `FacturaController@batchDestroy` | `invoices.batch-destroy` |
| DELETE | `/invoices/{file}` | `FacturaController@destroy` | `invoices.destroy` |

### Filtros soportados en `/invoices`

- `search` (sobre `archivos.original_name`, `archivos.checksum`, `facturas.nombre/rfc/monto`)
- `month`, `year`, `date` (exact), `date_from`, `date_to`
- `amount_min`, `amount_max`
- `sort` (`total` | `fecha_emision` | `estado` | `tipo` | `created_at`), `direction`
- `per_page` (`10` | ... | `all` → 10000)

---

## Egresos (Finanzas Fase 2)

Resource `->except('show')`. Modelo/tabla en español (`Egreso`/`egresos`); **rutas y Vue pages en inglés** (`expenses`/`Expenses`). **Acceso: cualquier miembro del team** (captura operativa; sin owner-gate). Tenancy por `TeamOwned` (registro de otro team → 404 vía route-model binding). Validación en `EgresoRequest`: `categoria_id` requerida + `exists` scoped (team **y `tipo=egreso`**), `monto` > 0, `empresa_id` opcional + `exists` scoped.

| Método | URI | Controller@action | Nombre | Tipo respuesta |
|---|---|---|---|---|
| GET | `/expenses` | `EgresoController@index` | `expenses.index` | Inertia `Expenses/Index` (props: `egresos` paginator, `empresas`, `categorias`, `total`, `totalsByCategoria`, `filters`) |
| GET | `/expenses/create` | `EgresoController@create` | `expenses.create` | Inertia `Expenses/Create` |
| POST | `/expenses` | `EgresoController@store` | `expenses.store` | Redirect (set `user_id`, `origen='manual'`) |
| GET | `/expenses/{expense}/edit` | `EgresoController@edit` | `expenses.edit` | Inertia `Expenses/Create` con `egreso` |
| PUT/PATCH | `/expenses/{expense}` | `EgresoController@update` | `expenses.update` | Redirect |
| DELETE | `/expenses/{expense}` | `EgresoController@destroy` | `expenses.destroy` | Redirect |

### Filtros soportados en `/expenses`
- `empresa_id`, `categoria_id` (single-select)
- `date_from`, `date_to` (sobre `egresos.fecha`); fallback `month`/`year` (`SetGlobalDateFilters`)
- `amount_min`, `amount_max`; `per_page`
- Totales (`total` + `totalsByCategoria`) calculados sobre el conjunto filtrado.

---

## Egresos recurrentes (Finanzas Fase 3)

Resource `recurring-expenses` `->except('show')`. CRUD de **plantillas** que el comando `egresos:generar-recurrentes` materializa en egresos. **Acceso: cualquier miembro del team** (tenancy `TeamOwned` + `ensureOwnTeam`). `store` computa `proxima_generacion` (primera ocurrencia); `update` la recomputa solo si `pagos_generados==0`. Validación en `EgresoRecurrenteRequest` (`categoria_id` requerida tipo=egreso, `monto`>0, `frecuencia` ∈ mensual/bimestral/trimestral/anual — **no quincenal**, reglas condicionales de vigencia).

| Método | URI | Controller@action | Nombre | Tipo respuesta |
|---|---|---|---|---|
| GET | `/recurring-expenses` | `EgresoRecurrenteController@index` | `recurring-expenses.index` | Inertia `RecurringExpenses/Index` (`plantillas`, `empresas`, `categorias`) |
| GET | `/recurring-expenses/create` | `@create` | `recurring-expenses.create` | Inertia `RecurringExpenses/Create` |
| POST | `/recurring-expenses` | `@store` | `recurring-expenses.store` | Redirect |
| GET | `/recurring-expenses/{recurring_expense}/edit` | `@edit` | `recurring-expenses.edit` | Inertia `RecurringExpenses/Create` con `plantilla` |
| PUT/PATCH | `/recurring-expenses/{recurring_expense}` | `@update` | `recurring-expenses.update` | Redirect |
| DELETE | `/recurring-expenses/{recurring_expense}` | `@destroy` | `recurring-expenses.destroy` | Redirect (los egresos generados se conservan) |

Comando + schedule documentados en `docs/operations.md` (sección Scheduler).

---

## Empleados (Finanzas Fase 3B)

Resource `employees` `->except('show')`. Plantilla de personal que el comando `nomina:generar` materializa en egresos de nómina. Modelo/columnas en español (`Empleado`); **rutas y Vue pages en inglés** (`employees`/`Employees`). **Acceso: solo owner del team en TODAS las habilidades** (incluido `viewAny`/`view`) vía `EmpleadoPolicy` — los salarios (fiscal/real) son sensibles. Un no-owner recibe **403**. Tenancy por `TeamOwned` (registro de otro team → 404 vía route-model binding). Validación en `EmpleadoRequest`: `empresa_id` requerida + `exists` scoped al team, `salario_fiscal`/`salario_real` > 0 con `salario_real >= salario_fiscal`, `fecha_baja >= fecha_entrada`, `clasificacion` ∈ tecnica/administrativa (nullable).

| Método | URI | Controller@action | Nombre | Tipo respuesta |
|---|---|---|---|---|
| GET | `/employees` | `EmpleadoController@index` | `employees.index` | Inertia `Employees/Index` (props: `empleados` paginator, `empresas`) |
| GET | `/employees/create` | `EmpleadoController@create` | `employees.create` | Inertia `Employees/Create` |
| POST | `/employees` | `EmpleadoController@store` | `employees.store` | Redirect (set `user_id`, `team_id`) |
| GET | `/employees/{employee}/edit` | `EmpleadoController@edit` | `employees.edit` | Inertia `Employees/Create` con `empleado` |
| PUT/PATCH | `/employees/{employee}` | `EmpleadoController@update` | `employees.update` | Redirect |
| DELETE | `/employees/{employee}` | `EmpleadoController@destroy` | `employees.destroy` | Redirect (la nómina ya generada se conserva) |

Comando `nomina:generar` + schedule documentados en `docs/operations.md`.

---

## Ingresos manuales (Finanzas Fase 4)

Resource `cash-income` `->except('show')`. Captura del ingreso real en **efectivo** (no bancario); espejo de Egresos (Fase 2) con categorías `tipo=ingreso`. Modelo/columnas en español (`IngresoManual`/`ingresos_manuales`); **rutas y Vue pages en inglés** (`cash-income`/`CashIncome`). **Acceso: cualquier miembro del team** (captura operativa; sin owner-gate). Tenancy por `TeamOwned` (registro de otro team → 404 vía route-model binding) + `ensureOwnTeam` (defense-in-depth) en edit/update/destroy. Validación en `IngresoManualRequest`: `categoria_id` requerida + `exists` scoped (team **y `tipo=ingreso`**), `monto` > 0 (`gt:0`), `empresa_id` opcional + `exists` scoped, `cliente` opcional, `metodo` ∈ efectivo/otro (default `efectivo`). `empresa_id`/`metodo` vacíos se normalizan en `prepareForValidation`.

| Método | URI | Controller@action | Nombre | Tipo respuesta |
|---|---|---|---|---|
| GET | `/cash-income` | `IngresoManualController@index` | `cash-income.index` | Inertia `CashIncome/Index` (props: `ingresos` paginator, `empresas`, `categorias`, `total`, `totalsByCategoria`, `filters`) |
| GET | `/cash-income/create` | `IngresoManualController@create` | `cash-income.create` | Inertia `CashIncome/Create` |
| POST | `/cash-income` | `IngresoManualController@store` | `cash-income.store` | Redirect (set `user_id`, `team_id`) |
| GET | `/cash-income/{cash_income}/edit` | `IngresoManualController@edit` | `cash-income.edit` | Inertia `CashIncome/Create` con `ingreso` |
| PUT/PATCH | `/cash-income/{cash_income}` | `IngresoManualController@update` | `cash-income.update` | Redirect |
| DELETE | `/cash-income/{cash_income}` | `IngresoManualController@destroy` | `cash-income.destroy` | Redirect |

### Filtros soportados en `/cash-income`
- `empresa_id`, `categoria_id` (single-select)
- `date_from`, `date_to` (sobre `ingresos_manuales.fecha`); fallback `month`/`year` (`SetGlobalDateFilters`)
- `amount_min`, `amount_max`; `per_page` (whitelist 10/25/50/100/all; basura → 25)
- Totales (`total` + `totalsByCategoria`, con bucket "Sin categoría") calculados sobre el conjunto filtrado.

---

## Dashboard ejecutivo (Finanzas Fase 6)

Estado de Resultados nivel CEO/consejo. Consume `ProfitLossService` (con `team_id` explícito) + `PeriodResolver` + **`FinanceAnalyticsService`** (analítica temporal v2). **Acceso: solo owner del team** (`ChecksTeamOwnership::ownsCurrentTeam`) en **todos** los métodos — un no-owner recibe **403**. Rutas y Vue page en inglés (`executive`/`Executive/`), dominio en español. La UI usa **ApexCharts** (`vue3-apexcharts`) para las gráficas. El export PDF asíncrono reusa el patrón de `ReconciliationController` (cola `exports`, `ExportRequest`, polling).

| Método | URI | Controller@action | Nombre | Middleware extra |
|---|---|---|---|---|
| GET | `/executive` | `ExecutiveController@index` | `executive` | — (solo owner, 403) |
| GET | `/executive/export` | `ExecutiveController@export` | `executive.export` | **`throttle:10,1`** (solo owner, 403) |
| GET | `/executive/export/{id}/status` | `ExecutiveController@checkExportStatus` | `executive.export.status` | — (solo owner; scope team + ownership por `user_id`) |
| GET | `/executive/export/{id}/download` | `ExecutiveController@downloadExport` | `executive.export.download` | — (solo owner; scope team + ownership por `user_id`) |

### Page Inertia renderizada

| Endpoint | Page (props) |
|---|---|
| `/executive` | `Executive/Index` (props: `pnl`, `pnlPrev`, `pnlYoY`, `porEmpresa`, `tuChecador`, `empresas`, **`series`**, **`ingresoEmpresaSeries`**, **`egresosPorCategoria`**, **`egresosPorNaturaleza`**, **`topProveedores`**, **`nominaRollup`**, `filters`) |

- `pnl`/`pnlPrev`/`pnlYoY`: `ProfitLossService::forPeriod` del periodo actual, el anterior (misma granularidad) y el mismo periodo del año anterior (YoY) — todos con `empresa_id` + `team_id` explícito.
- `porEmpresa`: un P&L por cada empresa activa del team (margen por unidad de negocio).
- `tuChecador`: P&L de la empresa con `slug='tu-checador'` si existe (tarjeta de ingreso recurrente); `null` si no existe (degrada con gracia).
- **`series`** (v2): serie mensual (`FinanceAnalyticsService::monthlySeries`) de los últimos `months` meses terminando en el ancla, respetando el filtro de empresa. Alimenta tendencias, sparklines de KPIs, márgenes en el tiempo y composición apilada de egresos.
- **`ingresoEmpresaSeries`** (v2): ingreso mensual por empresa (`ingresoPorEmpresaMensual`), **siempre consolidado multi-empresa** (ignora el filtro de empresa por diseño), con bucket "sin asignar".
- **`egresosPorCategoria`** / **`egresosPorNaturaleza`** / **`topProveedores`** / **`nominaRollup`** (v2): desgloses del rango seleccionado (respetan `empresa_id`).
- `filters`: `granularidad` (`mensual`|`trimestral`|`semestral`|`anual`, default `mensual`), `empresa_id` (null=consolidado), `month`, `year` (ancla de `SetGlobalDateFilters`), **`months`** (`6`|`12`, default `12`).

### Filtros / parámetros (query)

- `granularidad` — granularidad del periodo (`PeriodResolver`); valor no reconocido → `mensual`.
- `empresa_id` — int positivo o vacío (consolidado, incluye "sin asignar").
- **`months`** (v2) — ventana de tendencia en meses; **solo `6` o `12`**, cualquier otro valor → `12` (default).
- `month`/`year` — ancla; ya inyectados por `SetGlobalDateFilters`, default `now()`.

### Flujo de export PDF

1. `GET /executive/export?granularidad&empresa_id&month&year&months` → valida (incl. `months` in:6,12), crea `ExportRequest` (`type='pl_pdf'`, status `queued`, `filters` incluyen `team_id` y `months`), dispatcha `GenerateProfitLossPdfJob` (cola `exports`), responde JSON `{id, status:'queued', message}` si `wantsJson()`. El PDF incluye la serie mensual + desgloses en **tablas** (dompdf no hace charts).
2. Polling `GET /executive/export/{id}/status` → `{status, error_message, is_offline}` (`is_offline=true` si `queued > 2min`).
3. Al completar: `GET /executive/export/{id}/download` → `Storage::download(...)`.

Ver `docs/operations.md` (Colas) para el job y `docs/flows/export.md` para el patrón base.

---

## Settings — Tolerance

| Método | URI | Controller@action | Nombre | Autorización |
|---|---|---|---|---|
| GET | `/settings/tolerance` | `ToleranciaController@edit` | `settings.tolerance` | Solo owner del team (`user_id === team->user_id`) |
| POST | `/settings/tolerance` | `ToleranciaController@update` | `settings.tolerance.update` | Solo owner |

---

## Bank Formats

| Método | URI | Controller@action | Nombre | Tipo respuesta |
|---|---|---|---|---|
| GET | `/bank-formats` | `BankFormatController@index` | `bank-formats.index` | Inertia `BankFormats/Index` |
| GET | `/bank-formats/create` | `BankFormatController@create` | `bank-formats.create` | Inertia `BankFormats/Create` |
| POST | `/bank-formats` | `BankFormatController@store` | `bank-formats.store` | Redirect |
| GET | `/bank-formats/{bankFormat}` | `BankFormatController@show` (auto) | `bank-formats.show` | (route::resource default) |
| GET | `/bank-formats/{bankFormat}/edit` | `BankFormatController@edit` | `bank-formats.edit` | Inertia `BankFormats/Create` con `format` |
| PUT/PATCH | `/bank-formats/{bankFormat}` | `BankFormatController@update` | `bank-formats.update` | Redirect |
| DELETE | `/bank-formats/{bankFormat}` | `BankFormatController@destroy` | `bank-formats.destroy` | Redirect |
| POST | `/bank-formats/preview` | `BankFormatController@preview` | `bank-formats.preview` | **JSON** `{rows, filename}` — primeras 100 filas |
| GET | `/api/bank-formats` | `BankFormatController@list` | `bank-formats.list` | **JSON** lista con `banco` eager-loaded |

---

## Settings — Empresas y Categorías (Finanzas Fase 0)

Resource routes (`->except('show')`). Modelos/columnas en español (`Empresa`, `Categoria`), pero **rutas y Vue pages en inglés** (convención CLAUDE.md §5.2): `companies` / `categories`.

**Autorización vía Policy** (`EmpresaPolicy`, `CategoriaPolicy`, auto-descubiertas): `viewAny` → cualquier miembro del team; `create/update/delete` → solo **owner del team** (`User::ownsTeam`). Los controllers llaman `$this->authorize(...)`; los `EmpresaRequest`/`CategoriaRequest` (FormRequests) hacen la validación. El sidebar y el `index` ocultan acciones a no-owners.

| Método | URI | Controller@action | Nombre | Tipo respuesta |
|---|---|---|---|---|
| GET | `/settings/companies` | `EmpresaController@index` | `settings.companies.index` | Inertia `Settings/Companies/Index` |
| GET | `/settings/companies/create` | `EmpresaController@create` | `settings.companies.create` | Inertia `Settings/Companies/Create` |
| POST | `/settings/companies` | `EmpresaController@store` | `settings.companies.store` | Redirect |
| GET | `/settings/companies/{company}/edit` | `EmpresaController@edit` | `settings.companies.edit` | Inertia `Settings/Companies/Create` con `empresa` |
| PUT/PATCH | `/settings/companies/{company}` | `EmpresaController@update` | `settings.companies.update` | Redirect |
| DELETE | `/settings/companies/{company}` | `EmpresaController@destroy` | `settings.companies.destroy` | Redirect |
| GET | `/settings/categories` | `CategoriaController@index` | `settings.categories.index` | Inertia `Settings/Categories/Index` |
| GET | `/settings/categories/create` | `CategoriaController@create` | `settings.categories.create` | Inertia `Settings/Categories/Create` |
| POST | `/settings/categories` | `CategoriaController@store` | `settings.categories.store` | Redirect |
| GET | `/settings/categories/{category}/edit` | `CategoriaController@edit` | `settings.categories.edit` | Inertia `Settings/Categories/Create` con `categoria` |
| PUT/PATCH | `/settings/categories/{category}` | `CategoriaController@update` | `settings.categories.update` | Redirect |
| DELETE | `/settings/categories/{category}` | `CategoriaController@destroy` | `settings.categories.destroy` | Redirect |

Acceso a un registro de otro team → **404** (global scope `TeamOwned` en el route-model binding). Mutación por un miembro no-owner → **403** (Policy). Validación: `nombre` único por team, **slug único por team** (evita 500 por colisión de slug derivado), y en categorías invariante `tipo`/`grupo`/`naturaleza` (ingreso ⇒ grupo `ingreso` sin naturaleza; egreso ⇒ grupo de egreso con naturaleza).

---

## Health check

| Método | URI | Nombre |
|---|---|---|
| GET | `/up` | — (configurado en `bootstrap/app.php:11`) |

---

## Notas generales

- El grupo `auth` se cierra justo antes de `require __DIR__.'/auth.php'`. Todas las rutas de dominio requieren sesión.
- `verified` solo aplica a `/dashboard`.
- Rate limiting custom: `throttle:10,1` en `GET /reconciliation/export` y `GET /executive/export`. El resto de rutas usa el rate limiter global de Laravel (60/min por defecto).
- `route()` en Vue disponible vía Ziggy (`ZiggyVue` en `resources/js/app.ts`).
