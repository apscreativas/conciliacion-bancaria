# SDD — Ingresos manuales (efectivo) (Fase 4)

> SDD-lite (CRUD). Módulo: Finanzas / Ingresos · Fase: 4 · Autor: Juan + Claude · Fecha: 2026-06-30 · Estado: implementado

## 1. Objetivo
Registrar los **ingresos reales que NO pasan por banco** (pagos en efectivo), clasificados por **empresa** (opcional) + **categoría** (tipo=ingreso), con un índice filtrado (mes/año/empresa/categoría) y totales. Es una de las dos fuentes de ingresos del Estado de Resultados (Fase 5), junto con los ingresos bancarios conciliados. Es el **espejo de la Fase 2 (egresos manuales)** con categorías `tipo=ingreso`. Liga al PRD `docs/prd/finanzas-egresos-multiempresa.md`, Fase 4 (§3.5). **Aislado del motor de conciliación.**

## 2. Alcance
- **Incluye:** tabla `ingresos_manuales`, modelo `IngresoManual`, factory, `IngresoManualRequest`, `IngresoManualController` (CRUD `/cash-income`), índice filtrado + totales, páginas Inertia (CashIncome Index/Create, reusando `ExpenseFilters`), helper `categoriasIngreso` en el trait `ResolvesExpenseOptions`, link de sidebar, i18n, tests.
- **NO incluye (no-goals):** integración al P&L (`ProfitLossService`, Fase 5); conciliación/cruce con banco (los ingresos bancarios ya viven en `conciliacions`); recurrencia de ingresos; subida de comprobante.

## 3. Modelo de datos (delta)
```
ingresos_manuales: id, team_id(FK cascade),
  empresa_id (FK empresas, nullable, nullOnDelete),
  categoria_id(FK categorias, nullable DB + requerida app tipo=ingreso, nullOnDelete),
  fecha(date), monto(decimal 15,2), descripcion,
  cliente(nullable),
  metodo enum(efectivo,otro) default efectivo,
  user_id(FK creador, nullable, nullOnDelete), timestamps. index(team_id, fecha)
```
`IngresoManual`: `TeamOwned` + `HasFactory`; casts `fecha=>date`, `monto=>decimal:2`; relaciones `empresa()`, `categoria()`, `user()`. Diferencias vs `egresos`: campo `cliente` en vez de `proveedor`; `metodo` enum de 2 (efectivo/otro) en vez de `metodo_pago` enum de 4; sin `comprobante_path`/`origen`/`egreso_recurrente_id`/`empleado_id`.

## 4. Endpoints / rutas
Resource `cash-income` `->except('show')`. Ver tabla en `docs/endpoints.md` → "Ingresos manuales (Finanzas Fase 4)". **Acceso: cualquier miembro del team** (sin owner-gate). `IngresoManualRequest::authorize()` = true; tenancy por `TeamOwned` + `ensureOwnTeam` en edit/update/destroy.

## 5. Archivos tocados
1. `database/migrations/2026_07_01_000001_create_ingresos_manuales_table.php`
2. `app/Models/IngresoManual.php`, `database/factories/IngresoManualFactory.php`
3. `app/Http/Requests/IngresoManualRequest.php`, `app/Http/Controllers/IngresoManualController.php`
4. `app/Http/Controllers/Concerns/ResolvesExpenseOptions.php` (nuevo `categoriasIngreso`)
5. `routes/web.php` (resource cash-income)
6. `resources/js/Pages/CashIncome/{Index,Create}.vue` (reusan `Expenses/Partials/ExpenseFilters.vue`)
7. `resources/js/Layouts/AuthenticatedLayout.vue` (link "Ingresos"), `lang/{es,en}.json`, `tests/Feature/IngresoManualTest.php`

## 6. Reglas de negocio y casos borde
- **Validación:** `categoria_id` requerida + `exists` scoped (team **y `tipo=ingreso`** → una categoría de egreso se rechaza); `monto` > 0 (`gt:0`); `empresa_id` opcional + `exists` scoped al team; `cliente` opcional; `metodo` ∈ efectivo/otro. `empresa_id`/`metodo` vacíos se normalizan (`prepareForValidation`; `metodo` cae a `efectivo`).
- **Tenancy:** cualquier miembro del team CRUD; registro/empresa/categoría de otro team → 404 (binding) / 422 (validación scoped).
- **Borrado de catálogo:** `nullOnDelete` en `empresa_id`/`categoria_id`/`user_id` → borrar empresa/categoría/creador NO borra el ingreso (registro financiero sobrevive; queda "sin asignar"/"Sin categoría").
- **Totales:** `total` y `totalsByCategoria` sobre el conjunto filtrado (no solo la página), con bucket "Sin categoría" para que la suma cuadre.
- **`per_page`:** whitelist (10/25/50/100/all); valor basura cae a 25 (evita `paginate(0)` → 500).
- **No doble conteo (PRD §4.1):** el efectivo manual no aparece en `movimientos`; entra al P&L (Fase 5) junto con el ingreso bancario conciliado, sin solaparse.

## 7. Plan de pruebas
- **Pest (`IngresoManualTest`):** alta/edición/baja por un **miembro no-owner** (verifica any-member, set `user_id`=creador, `metodo`); validación (`monto` 0 → error, `categoria` requerida, **categoría `tipo=egreso` rechazada** — inverso de Fase 2, empresa/categoría de otro team → 422); **tenancy** (ingreso de otro team → 404); **filtros** por empresa/categoría + **total** del periodo; `per_page` basura no 500ea; ingreso sin categoría entra en `total` y desglose ("Sin categoría").
- Resultado: **8 passed**. Suite completa: 13 fallos baseline preexistentes, **0 regresiones nuevas**.

## 8. Impacto en lo existente
- Migración aditiva; rutas/páginas nuevas; un link de sidebar; un helper nuevo en `ResolvesExpenseOptions`. **No toca** conciliación/matcher/`conciliacions` ni tenancy compartida.

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Categoría de egreso en un ingreso | P&L mal clasificado | `exists` scoped a `tipo=ingreso` |
| Borrar categoría/empresa/creador rompe ingresos | Pérdida/500 de datos financieros | `nullOnDelete` en las 3 FKs; categoría requerida solo a nivel app |
| Ingreso con monto 0/negativo | Datos sucios | `gt:0` |
| Acceso cross-team | Fuga | `TeamOwned` + binding 404 + `ensureOwnTeam` + `exists` scoped 422 |

## 10. Definition of Done
DoD §8 del PRD: **A** pruebas ✓ (8 nuevas, 0 regresiones) · **B** docs ✓ (`domain.md`, `endpoints.md`, `business-rules.md`, este SDD, PRD Fase 4) · **C** commit atómico en `feature/finanzas-fase4` · **D** gate ✓ (no toca conciliación; sin doble conteo con cargos/abonos bancarios).
