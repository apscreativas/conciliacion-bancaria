# SDD — Fundamentos: dimensión empresa + catálogo de categorías (Fase 0)

> SDD-lite. Módulo: Finanzas / Fundamentos · Fase: 0 · Autor: Juan + Claude · Fecha: 2026-06-26 · Estado: implementado

## 1. Objetivo
Crear la base de datos y administración de las **unidades de negocio** (`empresas`) y el **catálogo de cuentas gerencial** (`categorias`) sobre los que se apoyan las Fases 1–6. Liga al PRD `docs/prd/finanzas-egresos-multiempresa.md`, Fase 0. Es 100% aditivo: tablas y CRUD nuevos, sin tocar el motor de conciliación.

## 2. Alcance
- **Incluye:** migraciones `empresas` + `categorias`; modelos `Empresa`/`Categoria` con `TeamOwned`; factories; `FinanzasCatalogoSeeder` (3 empresas + catálogo §4.2, idempotente, por team); CRUD en Settings (solo owner) con páginas Inertia; links en sidebar; i18n; tests.
- **NO incluye (no-goals):** cambios en `conciliacions`/asignación de `empresa_id` (Fase 1); egresos/ingresos/nómina/P&L/dashboard (Fases 2–6); scheduler (Fase 3).

## 3. Modelo de datos (delta)
```
empresas:   id, team_id(FK cascade), nombre, slug, color(nullable), activo(bool=true), orden(int=0), timestamps
            unique(team_id, slug)
categorias: id, team_id(FK cascade), nombre,
            tipo enum(ingreso,egreso),
            grupo enum(ingreso,costo_venta,gasto_operativo,abajo_ebitda),
            naturaleza enum(fijo,variable) NULLABLE,
            activo(bool=true), orden(int=0), timestamps
            unique(team_id, nombre)
```
Ambos modelos: `use TeamOwned; use HasFactory;`, casts `activo=>boolean`, `orden=>integer`.

## 4. Endpoints / rutas
Resource `->except('show')` bajo el grupo `auth`. **Rutas/Vue pages en inglés** (`companies`/`categories`, CLAUDE.md §5.2); modelos/columnas en español. Autorización vía Policy. Ver tabla completa en `docs/endpoints.md` → "Settings — Empresas y Categorías".

| Método | Ruta | Controller | Notas |
|---|---|---|---|
| GET | `/settings/companies` | `EmpresaController@index` | `viewAny` → miembro del team |
| POST/PUT/DELETE | `/settings/companies...` | `EmpresaController` | `EmpresaPolicy` create/update/delete → **solo owner** (403) |
| GET | `/settings/categories` | `CategoriaController@index` | `viewAny` → miembro del team |
| POST/PUT/DELETE | `/settings/categories...` | `CategoriaController` | `CategoriaPolicy` → **solo owner** |

## 5. Archivos tocados
1. `database/migrations/2026_06_26_000001_create_empresas_table.php`, `..._000002_create_categorias_table.php`
2. `app/Models/Empresa.php`, `app/Models/Categoria.php`
3. `database/factories/EmpresaFactory.php`, `CategoriaFactory.php`
4. `database/seeders/FinanzasCatalogoSeeder.php` (+ registro en `DatabaseSeeder.php`)
5. `app/Http/Controllers/EmpresaController.php`, `CategoriaController.php`
6. `app/Http/Requests/EmpresaRequest.php`, `CategoriaRequest.php` (validación reutilizada, CLAUDE.md §3.3)
7. `app/Policies/EmpresaPolicy.php`, `CategoriaPolicy.php`, `app/Policies/Concerns/ChecksTeamOwnership.php` (autz owner, reusa `User::ownsTeam`)
8. `routes/web.php` (2 resources)
9. `resources/js/Pages/Settings/{Companies,Categories}/{Index,Create}.vue`
10. `resources/js/Layouts/AuthenticatedLayout.vue` (2 links, gated owner)
11. `lang/es.json`, `lang/en.json`
12. `tests/Feature/{EmpresaTest,CategoriaTest,FinanzasCatalogoSeederTest}.php`

## 6. Reglas de negocio y casos borde
- **Tenancy:** `TeamOwned` filtra por `current_team_id`; defense-in-depth `where('team_id', ...)` en `index`. Registro de otro team → 404 (route-model binding scopeado).
- **Autorización:** vía `EmpresaPolicy`/`CategoriaPolicy` (trait `ChecksTeamOwnership` → `User::ownsTeam`, con guard de `currentTeam` null). `viewAny` abierto a miembros; `create/update/delete` solo owner → 403.
- **Unicidad:** `nombre` **y** `slug` únicos por team (ambos validados en `EmpresaRequest`). El slug se deriva de `Str::slug(nombre)` en `prepareForValidation`; validar su unicidad evita un 500 por el índice `unique(team_id, slug)` cuando dos nombres distintos producen el mismo slug. Nombre sin alfanuméricos (slug vacío) → 422.
- **`naturaleza` nullable + invariante:** `CategoriaRequest::withValidator` exige ingreso ⇒ grupo `ingreso` y `naturaleza` null; egreso ⇒ grupo de egreso y `naturaleza` fijo/variable. El form Vue ajusta opciones según `tipo` para espejarlo.
- **`activo`:** `prepareForValidation` lo castea a booleano (`$this->boolean`), evitando que un PUT sin el campo reactive el registro.
- **Seeder idempotente:** `updateOrCreate` por `(team_id, slug)` / `(team_id, nombre)`; re-ejecutar no duplica (verificado: 3 empresas + 21 categorías estables).

## 7. Plan de pruebas
- **Pest feature:** `EmpresaTest`, `CategoriaTest` — CRUD owner feliz, validación required/unique, enums inválidos.
- **Tenancy:** acceso a registro de otro team → 404; mutación por miembro no-owner → 403.
- **Seeder:** `FinanzasCatalogoSeederTest` — 3 empresas + catálogo por team; re-run no duplica.
- Resultado: **12 passed (47 assertions)** (incluye slug-colisión, slug vacío, invariante tipo/grupo/naturaleza, y 403 de categorías). Suite completa: 69 passed / 13 failed (los 13 son baseline preexistente, 0 regresiones nuevas).

## 8. Impacto en lo existente
- ¿Tenancy/transacciones/colas/migraciones/contratos Inertia? Solo **migraciones nuevas** (aditivas) y **rutas/páginas nuevas**. No cambia props de páginas existentes.
- ¿Toca el motor de conciliación? **NO.** Cero cambios en `MatcherService`, `conciliacions`, jobs o parsers.

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Colisión de `slug` entre nombres distintos | Violación unique(team,slug) → 500 | **Mitigado:** `EmpresaRequest` valida unicidad del slug derivado → 422 en vez de 500 (cubierto por test) |
| Combinación incoherente tipo/grupo/naturaleza | P&L de fases futuras mal agrupado | **Mitigado:** invariante en `CategoriaRequest::withValidator` + form Vue que ajusta opciones |
| Seeder corre sin teams (DB vacía) | No siembra nada | Esperado; siembra al existir un team. Idempotente al re-correr |
| No-owner intenta mutar | Acceso indebido | `EmpresaPolicy`/`CategoriaPolicy` (`$this->authorize`) → 403 en create/update/delete; links ocultos en UI |

## 10. Definition of Done
DoD §8 del PRD: **A** pruebas ✓ (8 nuevas verdes, 0 regresiones) · **B** docs ✓ (`domain.md`, `endpoints.md`, este SDD, PRD marcado) · **C** commit atómico en `feature/finanzas-fase0` (pendiente) · **D** gate financiero N/A en Fase 0 (no mueve dinero; motor intacto).
