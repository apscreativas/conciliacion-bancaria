# Architecture

## Stack

| Capa | Tecnología | Versión |
|---|---|---|
| Lenguaje backend | PHP | `^8.2` (dev env 8.5.2) |
| Framework | Laravel | `^12.0` |
| Autenticación | Laravel Breeze | `^2.3` |
| SPA bridge | Inertia.js | `^2.0` |
| Frontend | Vue 3 + `<script setup>` + TypeScript | Vue `^3.4`, TS `^5.6` |
| Bundler | Vite | `^6.0` |
| Estilos | Tailwind CSS | `v3.2.1` (+ `@tailwindcss/vite v4` — ver `decisions/0005`) |
| i18n | laravel-vue-i18n | `^2.8` |
| Base de datos | MySQL | 8.4 (via Sail) |
| Cache + colas | Redis | 7.x (via Sail) |
| Workers async | Jobs `ShouldQueue` sobre Redis | — |
| Excel | Maatwebsite/Excel | `^3.1` |
| PDF | barryvdh/laravel-dompdf | `^3.1` |
| Storage | Local + S3 (flysystem) | — |
| Tests | Pest | `^4.3` |
| Entorno dev | Laravel Sail (Docker) | — |

---

## Estructura del código (Laravel 12)

```
app/
├── Console/Commands/          # facturas:backfill-cfdi-types, queue:cleanup-stuck, app:recalculate-movement-hashes
├── Exports/                   # ReconciliationExport (multi-sheet), ReconciliationPdfExport, Sheets/, Traits/
├── Http/
│   ├── Controllers/           # Dominio + Auth/ (Breeze)
│   ├── Middleware/            # SetGlobalDateFilters, HandleInertiaRequests
│   └── Requests/              # Auth/LoginRequest, ProfileUpdateRequest
├── Interfaces/
│   └── BankParserInterface.php
├── Jobs/                      # ProcessXmlUpload, ProcessBankStatement, GenerateReconciliation{Excel,Pdf}ExportJob
├── Mail/TeamInvitationMail.php
├── Models/
│   ├── Traits/                # TeamOwned (global scope), HasCreator, UserOwned
│   └── *.php                  # Factura, Movimiento, Conciliacion, Team, User, etc.
├── Notifications/ResetPasswordNotification.php
├── Policies/TeamPolicy.php
├── Providers/AppServiceProvider.php
└── Services/
    ├── Parsers/               # Contracts/, AbstractBankParser, DynamicStatementParser, StatementParserFactory
    ├── Reconciliation/        # MatcherService, DescriptionParser
    └── Xml/                   # CfdiParserService
```

Laravel 12 ya no usa `app/Http/Kernel.php`. El middleware se configura en `bootstrap/app.php`.

---

## Tenancy (multi-tenant por Team)

El modelo de tenencia se basa en el concepto `Team`. Cada usuario tiene un `current_team_id` y todas las operaciones se filtran por él.

### Tres capas de defensa

1. **Global scope automático** — el trait `TeamOwned` (`app/Models/Traits/TeamOwned.php`) añade un `Builder::where('team_id', Auth::user()->current_team_id)` a todas las consultas de modelos que lo usan. También asigna `team_id` al crear.
2. **Filtro explícito en controllers** — aun con el scope, cada controller vuelve a filtrar por `team_id` como defense in depth. No eliminar.
3. **Validación de ownership antes de operaciones masivas** — `ReconciliationController@batch` y `@store` verifican que cada ID recibido pertenezca al team, abortando con `403` si no (`ReconciliationController.php:134-139`, `:174`).

### Modelos con `TeamOwned`

- `Factura`, `Movimiento`, `Conciliacion`, `Archivo`, `BankFormat`, `ExportRequest`, `Tolerancia`.
- **Excepciones**: `Banco` (referencia global), `Team`, `User`, `TeamInvitation` (entidades de tenencia).

### Cambio de team

- Ruta `PUT /current-team` → `CurrentTeamController@update`.
- Llama `$user->switchTeam($team)`. Valida que el usuario pertenezca via `belongsToTeam()`.

### Creación automática de team personal

- `User@booted` (`app/Models/User.php:18-31`) crea un `Team` personal (`personal_team = true`) al crear el usuario y le asigna `current_team_id`.

---

## Middleware

Registrado en `bootstrap/app.php:14-18` (append al grupo web):

| Middleware | Propósito |
|---|---|
| `App\Http\Middleware\SetGlobalDateFilters` | Persiste `month`/`year` en sesión y los inyecta en `$request`. Valor default: `now()->month` / `now()->year`. **Muchos controllers dependen de que esto esté presente** |
| `App\Http\Middleware\HandleInertiaRequests` | Comparte props globales con todas las pages Inertia |
| `Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets` | Laravel nativo, preload de assets |

### Props compartidas por Inertia

`HandleInertiaRequests::share()` (`app/Http/Middleware/HandleInertiaRequests.php:30-88`) expone:

```json
{
  "auth": {
    "user": {
      "id", "name", "email", "current_team_id", "profile_photo_url",
      "current_team": { "id", "name", "user_id", "personal_team" },
      "all_teams": [ ... ]
    }
  },
  "flash": {
    "success", "error", "warning", "toasts"
  },
  "filters": { "month", "year" },
  "available_years": [ 2024, 2025, 2026 ]
}
```

`available_years` se calcula consultando `YEAR(fecha_emision)` de facturas y `YEAR(fecha)` de movimientos del team actual, más el año en curso.

---

## Providers

`app/Providers/AppServiceProvider.php`:

- `Vite::prefetch(concurrency: 3)` — optimiza carga de assets.
- Force HTTPS cuando el host incluye `ngrok-free.dev` (útil para testing con tunneling).

No hay otros providers custom.

---

## Estructura frontend

```
resources/js/
├── app.ts                     # Inertia bootstrap + Ziggy + i18n
├── bootstrap.ts               # axios setup + 401/419 interceptor
├── types/                     # *.d.ts
├── Layouts/
│   ├── AuthenticatedLayout.vue
│   └── GuestLayout.vue
├── Components/                # UI primitives + compartidos
│   ├── AdvancedFilters.vue
│   ├── DatePicker.vue
│   ├── UploadModal.vue
│   ├── ConfirmationModal.vue
│   ├── EmptyState.vue
│   ├── LanguageSwitcher.vue
│   └── ...
└── Pages/
    ├── Auth/                  # 6 pages (Login, Register, etc.)
    ├── BankFormats/           # Create, Index
    ├── Dashboard.vue
    ├── Invoices/Index.vue
    ├── Movements/Index.vue
    ├── Profile/               # Edit + Partials/
    ├── Reconciliation/        # Workbench, Matches, History, Status + Partials/
    ├── Settings/Tolerance.vue
    ├── Teams/                 # Create, InvitationLanding, Show
    └── Welcome.vue
```

---

## Internacionalización (i18n)

- Plugin: `laravel-vue-i18n`.
- Archivos en `lang/*.json` (resueltos vía `import.meta.glob`).
- Locale default: `es`. Almacenado en `localStorage` bajo la clave `locale`.
- Switch mediante `Components/LanguageSwitcher.vue`.

---

## Build y bundling

- `vue-tsc && vite build` para producción.
- `vite` dev server durante desarrollo.
- Script `composer run dev` (`composer.json:52-54`) arranca concurrente: `artisan serve`, `queue:listen`, `pail`, `npm run dev`.

---

## Sanctum y Sessions

- `laravel/sanctum ^4.0` está instalado pero **no** hay rutas `/api/*` protegidas con Sanctum actualmente. Solo el endpoint `GET /api/bank-formats` existe como JSON y está dentro del grupo `auth` (sesión).
- Auth por sesión (Breeze). Configuración endurecida en `.env.example`:
  - `SESSION_DRIVER=database`
  - `SESSION_LIFETIME=60`
  - `SESSION_ENCRYPT=true`

---

## Referencias

- Bootstrap: `bootstrap/app.php`
- Middleware: `app/Http/Middleware/`
- Shared props: `app/Http/Middleware/HandleInertiaRequests.php`
- Tenancy trait: `app/Models/Traits/TeamOwned.php`
- Frontend entry: `resources/js/app.ts`
- Auto personal team: `app/Models/User.php:18-31`
