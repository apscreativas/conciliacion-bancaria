# SDD — Asignación de empresa a conciliación (Fase 1)

> SDD **ampliado** (módulo sensible a dinero, PRD §8.1). Módulo: Finanzas / Asignación · Fase: 1 · Autor: Juan + Claude · Fecha: 2026-06-29 · Estado: implementado

## 1. Objetivo
Etiquetar el **ingreso bancario ya conciliado** con su **unidad de negocio** (`empresa`), a nivel **grupo** (`group_id`), con 1 click desde el Historial. Habilita el Estado de Resultados por empresa (Fases 5–6). Liga al PRD `docs/prd/finanzas-egresos-multiempresa.md`, Fase 1. **Aditivo y reversible: no toca el motor de matching.**

## 2. Alcance
- **Incluye:** columna `conciliacions.empresa_id` (nullable); relación `Conciliacion::empresa()`; endpoint `PATCH /reconciliation/group/{groupId}/empresa` (asignar/reasignar/des-asignar a nivel grupo); selector + badge de color en el card del Historial; lista de empresas activas como prop.
- **NO incluye (no-goals):** `categoria_id` (Fase 5); asignar desde Status/Workbench; cambios en `MatcherService`/`store`/`batch`/`auto`; egresos/ingresos/P&L (Fases 2–6).

## 3. Modelo de datos (delta)
```
ALTER conciliacions ADD empresa_id BIGINT UNSIGNED NULL AFTER group_id,
  FK → empresas(id) ON DELETE SET NULL   // nullOnDelete
```
Sin backfill: conciliaciones existentes quedan `empresa_id = NULL` ("sin asignar"). `Conciliacion`: `empresa_id` en `$fillable` + `empresa()` belongsTo.

## 4. Endpoints / rutas
| Método | Ruta | Controller | Notas |
|---|---|---|---|
| PATCH | `/reconciliation/group/{groupId}/empresa` | `ReconciliationController@updateGroupEmpresa` | `empresa_id` nullable + `Rule::exists('empresas','id')->where(team_id)`; `update(['empresa_id'=>...])` sobre `where group_id + team_id`; `abort(404)` si 0 filas. **Espeja `destroyGroup`** en tenancy. |

Además, `history()` añade `empresa` al eager-load y al grupo transformado, y pasa la prop `empresas` (activas del team). Ver `docs/endpoints.md`.

## 5. Archivos tocados
1. `database/migrations/2026_06_29_000001_add_empresa_id_to_conciliacions_table.php`
2. `app/Models/Conciliacion.php` (fillable + `empresa()`)
3. `app/Http/Controllers/ReconciliationController.php` (`updateGroupEmpresa` + `history()` extendido) — **NO se tocó `store`/`batch`/`auto`/matcher**
4. `routes/web.php` (ruta PATCH)
5. `resources/js/Pages/Reconciliation/History.vue` (prop `empresas` → card)
6. `resources/js/Pages/Reconciliation/Partials/ReconciliationGroupCard.vue` (badge + `<select>` → `router.patch`)
7. `lang/es.json`, `lang/en.json` ("Sin asignar", "Asignar empresa")
8. `tests/Feature/ConciliacionEmpresaTest.php`

## 6. Reglas de negocio y casos borde
- **A nivel grupo:** la asignación actualiza **todas** las filas del `group_id` (un grupo N-M tiene varias filas `conciliacions`). Verificado con grupo multi-fila (1 factura, 2 movimientos).
- **Tenancy:** scope `team_id` en el UPDATE (como `destroyGroup`). Grupo de otro team → **404**. `empresa_id` de otro team → **422** (`exists` scoped por team). Cualquier miembro del team puede asignar (tarea operativa, no owner-gate — consistente con conciliar/desvincular).
- **Des-asignar:** `empresa_id = null` permitido (vuelve a "sin asignar").
- **Borrado de empresa:** `nullOnDelete` → la conciliación sobrevive con `empresa_id = null`; **nunca** se borra un registro financiero.
- **No-tocar-matcher:** `empresa_id` se asigna **post-conciliación**; `MatcherService::reconcile()` ni lo conoce. El UPDATE no altera `monto_aplicado`, `group_id`, ni el conteo de filas.

## 7. Plan de pruebas
- **Pest (`ConciliacionEmpresaTest`):** crea una conciliación real vía `POST reconciliation.store` (pasa por el matcher) para obtener un `group_id` real.
  - asigna empresa → **todas** las filas del grupo quedan con `empresa_id` (grupo multi-fila).
  - des-asigna (`empresa_id=null`).
  - **gate financiero:** `monto_aplicado` total y conteo de filas **idénticos** antes/después de asignar.
  - empresa de otro team → 422; group_id de otro team → 404.
- **No-regresión del motor:** `MatcherServiceTest`, `RegressionTest`, `ReconciliationTenancyTest` → 0 fallos nuevos vs baseline.
- Resultado: **5 passed (18 assertions)**. Suite completa: **76 passed / 13 failed** (los 13 son baseline preexistente; 0 regresiones nuevas).

## 8. Impacto en lo existente
- **Migración** aditiva nullable (no rompe filas existentes). **Contrato Inertia de History** extendido (props nuevas `empresa` por grupo y `empresas`) — aditivo, no hay test que verifique el shape previo.
- **¿Toca el motor de conciliación?** **NO.** Cero cambios en `MatcherService` ni en `store`/`batch`/`auto`. Confirmado por el gate financiero y la no-regresión.

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Borrar empresa borra conciliaciones | Pérdida de datos financieros | `nullOnDelete` (nunca cascada) |
| Asignación parcial del grupo | Reportes inconsistentes | UPDATE por `group_id` actualiza todas las filas; test multi-fila |
| empresa/grupo de otro team | Fuga cross-tenant | scope `team_id` (404) + `exists` scoped (422) |
| Cambio accidental de montos | Corrupción financiera | El endpoint solo escribe `empresa_id`; test verifica `monto_aplicado` y conteo intactos |

## 10. Definition of Done
DoD §8 del PRD: **A** pruebas ✓ (5 nuevas + no-regresión, 0 nuevas) · **B** docs ✓ (`domain.md`, `endpoints.md`, este SDD, PRD Fase 1) · **C** commit atómico en `feature/finanzas-fase1` · **D** gate financiero ✓ (motor intacto, `monto_aplicado` sin cambios).
