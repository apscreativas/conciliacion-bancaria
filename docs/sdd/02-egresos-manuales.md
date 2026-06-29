# SDD — Egresos manuales (Fase 2)

> SDD-lite (CRUD). Módulo: Finanzas / Egresos · Fase: 2 · Autor: Juan + Claude · Fecha: 2026-06-29 · Estado: implementado

## 1. Objetivo
Capturar gastos uno por uno, clasificados por **empresa** (opcional) + **categoría** (egreso), con un índice filtrado (mes/año/empresa/categoría) y totales. Base de datos de egresos que consumirán los recurrentes (Fase 3), la nómina (Fase 3B) y el Estado de Resultados (Fase 5). Liga al PRD `docs/prd/finanzas-egresos-multiempresa.md`, Fase 2. **Aislado del motor de conciliación.**

## 2. Alcance
- **Incluye:** tabla `egresos`, modelo `Egreso`, factory, `EgresoRequest`, `EgresoController` (CRUD `/expenses`), índice filtrado + totales, páginas Inertia (Index/Create + ExpenseFilters), link de sidebar, i18n, tests.
- **NO incluye:** `egreso_recurrente_id` y el generador (Fase 3); nómina (Fase 3B); subida de comprobante (`comprobante_path` queda nullable sin UI); ingresos manuales (Fase 4); P&L/dashboard (Fases 5–6).

## 3. Modelo de datos (delta)
```
egresos: id, team_id(FK cascade),
  empresa_id (FK empresas, nullable, nullOnDelete),
  categoria_id(FK categorias, nullable DB + requerida app, nullOnDelete),
  fecha(date), monto(decimal 15,2), descripcion, proveedor(nullable),
  metodo_pago enum(transferencia,efectivo,tarjeta,otro) nullable,
  comprobante_path(nullable), origen enum(manual,recurrente) default manual,
  user_id(FK creador), timestamps. index(team_id, fecha)
```
`Egreso`: `TeamOwned` + `HasFactory`; casts `fecha=>date`, `monto=>decimal:2`; relaciones `empresa()`, `categoria()`, `user()`.

## 4. Endpoints / rutas
Resource `expenses` `->except('show')`. Ver tabla en `docs/endpoints.md` → "Egresos (Finanzas Fase 2)". **Acceso: cualquier miembro del team** (sin owner-gate). `EgresoRequest::authorize()` = true; tenancy por `TeamOwned`.

## 5. Archivos tocados
1. `database/migrations/2026_06_29_000002_create_egresos_table.php`
2. `app/Models/Egreso.php`, `database/factories/EgresoFactory.php`
3. `app/Http/Requests/EgresoRequest.php`, `app/Http/Controllers/EgresoController.php`
4. `routes/web.php` (resource expenses)
5. `resources/js/Pages/Expenses/{Index,Create}.vue` + `Partials/ExpenseFilters.vue`
6. `resources/js/Layouts/AuthenticatedLayout.vue` (link "Egresos")
7. `lang/{es,en}.json`, `tests/Feature/EgresoTest.php`

## 6. Reglas de negocio y casos borde
- **Validación:** `categoria_id` requerida + `exists` scoped (team **y `tipo=egreso`** → una categoría de ingreso se rechaza); `monto` > 0 (`gt:0`); `empresa_id` opcional + `exists` scoped al team; `metodo_pago` opcional enum. `empresa_id`/`metodo_pago` vacíos se normalizan a null (`prepareForValidation`).
- **Tenancy:** cualquier miembro del team CRUD; registro/empresa/categoría de otro team → 404 (binding) / 422 (validación scoped).
- **Borrado de catálogo:** `nullOnDelete` en `empresa_id` y `categoria_id` → borrar una empresa/categoría NO borra el egreso (queda "sin asignar"/"sin categoría"), evitando 500 o cascada sobre datos financieros. `categoria_id` es requerida a nivel app pero nullable en DB por esta razón.
- **Totales:** `total` (suma del conjunto filtrado) y `totalsByCategoria` se calculan sobre el query filtrado (no solo la página).
- **No doble conteo (PRD §4.1):** los egresos viven en su propia tabla; el P&L (Fase 5) NO sumará los cargos bancarios.

## 7. Plan de pruebas
- **Pest (`EgresoTest`):** alta/edición/baja por un **miembro no-owner** (set `user_id`=creador, `origen=manual`); validación (`monto` 0 → error, `categoria` requerida, categoría `tipo=ingreso` rechazada, empresa/categoría de otro team → 422); **tenancy** (egreso de otro team → 404); **filtros** por empresa/categoría (Inertia assert sobre `egresos.data`) y **total** del periodo.
- Resultado: **6 passed (59 assertions)**. Suite completa: **77 passed / 13 failed** (13 baseline preexistente; 0 regresiones nuevas).

## 8. Impacto en lo existente
- Migración aditiva; rutas/páginas nuevas; un link de sidebar. **No toca** conciliación/matcher/`conciliacions`.

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Borrar categoría/empresa rompe egresos | Pérdida/500 de datos financieros | `nullOnDelete` (nunca cascada); categoría requerida solo a nivel app |
| Categoría de ingreso en un egreso | P&L mal clasificado | `exists` scoped a `tipo=egreso` |
| Egreso con monto 0/negativo | Datos sucios | `gt:0` |
| Acceso cross-team | Fuga | `TeamOwned` + binding 404 + `exists` scoped 422 |

## 10. Definition of Done
DoD §8 del PRD: **A** pruebas ✓ (6 nuevas, 0 regresiones) · **B** docs ✓ (`domain.md`, `endpoints.md`, este SDD, PRD Fase 2) · **C** commit atómico en `feature/finanzas-fase2` · **D** gate ✓ (no toca conciliación; sin doble conteo con cargos bancarios).
