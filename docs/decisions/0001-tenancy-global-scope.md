# ADR 0001 — Tenancy por Global Scope

**Estado**: Aceptada
**Fecha**: 2026-01-25 (introducción del trait `TeamOwned`)

## Contexto

La aplicación soporta múltiples teams (razones sociales) bajo una misma instalación. Cada usuario puede pertenecer a varios teams y tiene un `current_team_id` activo. Toda factura, movimiento, conciliación, archivo, configuración de banco y tolerancia pertenece a un team específico.

Riesgo: que un controller o query olvide filtrar por `team_id` y exponga datos de otros teams.

## Decisión

Aplicar un **global scope de Eloquent** vía el trait `App\Models\Traits\TeamOwned` a todos los modelos de dominio. Este trait:

1. Registra un `Builder::where('team_id', Auth::user()->current_team_id)` en todas las queries del modelo.
2. Setea `team_id` automáticamente al crear si no está presente.

Se aplica a: `Factura`, `Movimiento`, `Conciliacion`, `Archivo`, `BankFormat`, `ExportRequest`, `Tolerancia`.

**No** se aplica a: `Banco` (referencia global), `Team`, `User`, `TeamInvitation` (son entidades de tenencia, no datos scoped).

## Defense in depth

Aun con el global scope, los controllers mantienen un `where('team_id', auth()->user()->current_team_id)` explícito. Esta redundancia es deliberada y no debe eliminarse, por tres razones:

1. Testing cross-team usa `withoutGlobalScopes` y el filtro explícito previene regresiones.
2. Es más fácil de auditar visualmente en code review.
3. Laravel upgrades futuros pueden cambiar semántica de global scopes.

Adicionalmente, operaciones masivas (`ReconciliationController::batch`, `::store`) verifican ownership de cada ID recibido antes de actuar (abort 403 si no).

## Alternativas consideradas

- **Librería externa (Stancl/Tenancy)**: sobredimensionada, orientada a multi-BD por tenant. Este proyecto usa una sola BD con columna `team_id`.
- **Solo filtrado explícito en controllers**: fácil olvidar. Causa exposición silenciosa.
- **Policies por modelo**: útil para operaciones específicas, pero no cubre queries de listado (donde ocurren leaks más comunes).

## Consecuencias

✅ Queries default son seguras. Un dev junior que escriba `Factura::find($id)` no expone datos de otro team.
✅ Menos código boilerplate en controllers.
✅ `team_id` se setea automáticamente al crear registros.

⚠️ Cuando necesitas bypassear (migrations, commands, seeders, algunos tests), hay que usar `withoutGlobalScopes()` explícitamente — añade fricción.
⚠️ Performance: cada query añade una condición. Mitigado con índices en `team_id`.
⚠️ Nuevos modelos de dominio **deben** recordar aplicar el trait. Se detecta en tests de tenancy.

## Tests de protección

- `tests/Feature/ReconciliationTenancyTest.php`
- `tests/Feature/SecurityAuditTest.php` (bloque `destroy group only deletes own team records`)

## Referencias

- `app/Models/Traits/TeamOwned.php`
- `docs/architecture.md` §Tenancy
- `docs/security.md` §1
