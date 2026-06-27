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
Resource `->except('show')` bajo el grupo `auth`. Ver tabla completa en `docs/endpoints.md` → "Settings — Empresas y Categorías".

| Método | Ruta | Controller | Notas |
|---|---|---|---|
| GET | `/settings/empresas` | `EmpresaController@index` | miembro del team |
| POST/PUT/DELETE | `/settings/empresas...` | `EmpresaController` | **solo owner** (`ensureOwner` → 403) |
| GET | `/settings/categorias` | `CategoriaController@index` | miembro del team |
| POST/PUT/DELETE | `/settings/categorias...` | `CategoriaController` | **solo owner** |

## 5. Archivos tocados
1. `database/migrations/2026_06_26_000001_create_empresas_table.php`, `..._000002_create_categorias_table.php`
2. `app/Models/Empresa.php`, `app/Models/Categoria.php`
3. `database/factories/EmpresaFactory.php`, `CategoriaFactory.php`
4. `database/seeders/FinanzasCatalogoSeeder.php` (+ registro en `DatabaseSeeder.php`)
5. `app/Http/Controllers/EmpresaController.php`, `CategoriaController.php`
6. `routes/web.php` (2 resources)
7. `resources/js/Pages/Settings/{Empresas,Categorias}/{Index,Create}.vue`
8. `resources/js/Layouts/AuthenticatedLayout.vue` (2 links, gated owner)
9. `lang/es.json`, `lang/en.json`
10. `tests/Feature/{EmpresaTest,CategoriaTest,FinanzasCatalogoSeederTest}.php`

## 6. Reglas de negocio y casos borde
- **Tenancy:** `TeamOwned` filtra por `current_team_id`; defense-in-depth `where('team_id', ...)` en `index`. Registro de otro team → 404 (route-model binding scopeado).
- **Autorización:** mutaciones solo por owner del team (no hay sistema de roles; se replica `ToleranciaController`). No-owner → 403.
- **Unicidad:** `nombre` único por team en ambas tablas (validación + índice). `slug` de empresa derivado de `Str::slug(nombre)`.
- **`naturaleza` nullable:** ingresos quedan en `null`; solo egresos llevan fijo/variable.
- **Seeder idempotente:** `updateOrCreate` por `(team_id, slug)` / `(team_id, nombre)`; re-ejecutar no duplica (verificado: 3 empresas + 21 categorías estables).

## 7. Plan de pruebas
- **Pest feature:** `EmpresaTest`, `CategoriaTest` — CRUD owner feliz, validación required/unique, enums inválidos.
- **Tenancy:** acceso a registro de otro team → 404; mutación por miembro no-owner → 403.
- **Seeder:** `FinanzasCatalogoSeederTest` — 3 empresas + catálogo por team; re-run no duplica.
- Resultado: **8 passed (35 assertions)**. Suite completa: 65 passed / 13 failed (los 13 son baseline preexistente, 0 regresiones nuevas).

## 8. Impacto en lo existente
- ¿Tenancy/transacciones/colas/migraciones/contratos Inertia? Solo **migraciones nuevas** (aditivas) y **rutas/páginas nuevas**. No cambia props de páginas existentes.
- ¿Toca el motor de conciliación? **NO.** Cero cambios en `MatcherService`, `conciliacions`, jobs o parsers.

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Colisión de `slug` entre nombres distintos | Violación unique(team,slug) | `nombre` único por team ya previene; slug derivado del nombre |
| Seeder corre sin teams (DB vacía) | No siembra nada | Esperado; siembra al existir un team. Idempotente al re-correr |
| No-owner intenta mutar | Acceso indebido | `ensureOwner()` → 403 en todas las mutaciones; links ocultos en UI |

## 10. Definition of Done
DoD §8 del PRD: **A** pruebas ✓ (8 nuevas verdes, 0 regresiones) · **B** docs ✓ (`domain.md`, `endpoints.md`, este SDD, PRD marcado) · **C** commit atómico en `feature/finanzas-fase0` (pendiente) · **D** gate financiero N/A en Fase 0 (no mueve dinero; motor intacto).
