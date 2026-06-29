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
- Rate limiting custom: solo `throttle:10,1` en `GET /reconciliation/export`. El resto de rutas usa el rate limiter global de Laravel (60/min por defecto).
- `route()` en Vue disponible vía Ziggy (`ZiggyVue` en `resources/js/app.ts`).
