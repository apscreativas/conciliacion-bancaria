# SDD — Catálogo cliente→empresa + auto-asignación de ingresos

> SDD-lite. Módulo: Finanzas / Catálogo cliente→empresa · Fase: adicional (post Fase 6) · Autor: Juan Carlos Portales · Fecha: 2026-07-01 · Estado: implementado

## 1. Objetivo

Asignar a mano la empresa de cada ingreso conciliado (`/reconciliation/history`) es repetitivo: estadísticamente cada **cliente (RFC de la factura)** pertenece casi siempre a la misma empresa. Este módulo mantiene un **catálogo `RFC → empresa` auto-aprendido** de las asignaciones y **pre-asigna sola** la empresa al conciliar (editable), más una vista de catálogo y **detección de clientes mensuales que dejaron de facturar**. **Solo Ingresos.** **Cero cambios de comportamiento del motor de conciliación** (todo aditivo).

## 2. Alcance

- **Incluye:** modelo/tabla `cliente_empresas`; `ClienteEmpresaService` (recordar/sugerir/aplicar/rfcsDeGrupo); auto-aprendizaje al asignar empresa a un grupo; auto-asignación al conciliar (store/batch); página `/clients` (catálogo editable + reporte de recurrentes + aplicar catálogo); `reconcile()` expone su `group_id`.
- **NO incluye (no-goals):** egresos (capturan su empresa en su propio flujo); cambios al cálculo del P&L; cambios al algoritmo del matcher (solo se expone el `group_id`); periodicidad distinta de mensual en la detección (heurística simple).

## 3. Modelo de datos (delta)

Nueva tabla `cliente_empresas` (modelo `ClienteEmpresa`, `TeamOwned`):

```
id, team_id (FK cascade)
rfc (string)                      # identidad estable del cliente
nombre (string)                   # último visto, solo display
empresa_id (FK empresas, nullable, nullOnDelete)
veces (unsignedInteger default 0) # confianza del aprendizaje
ultima_asignacion_at (timestamp nullable)
user_id (FK users, nullable, nullOnDelete)  # quién asignó por última vez
timestamps
unique (team_id, rfc)
```

Evidencia: `2026_07_01_000001_create_cliente_empresas_table.php`.

## 4. Endpoints / rutas

| Método | Ruta | Controller | Notas (auth, ownership) |
|---|---|---|---|
| GET | `/clients` | `ClienteEmpresaController@index` | Cualquier miembro; Inertia `Clients/Index` (`catalogo`, `empresas`, `recurrentes`) |
| PATCH | `/clients/{client}` | `ClienteEmpresaController@update` | Override manual `empresa_id` (`present`+`nullable`+`exists` scoped). Otro team → 404 |
| POST | `/clients/aplicar-sugerencias` | `ClienteEmpresaController@aplicarSugerencias` | Aplica el catálogo al histórico sin empresa; redirect con conteo |

Además (aditivo, no rutas nuevas): `store`/`batch`/`updateGroupEmpresa` de `ReconciliationController` invocan `ClienteEmpresaService`.

## 5. Archivos tocados

1. `database/migrations/2026_07_01_000001_create_cliente_empresas_table.php` (nuevo)
2. `app/Models/ClienteEmpresa.php` + `database/factories/ClienteEmpresaFactory.php` (nuevo)
3. `app/Services/Finance/ClienteEmpresaService.php` (nuevo)
4. `app/Services/Reconciliation/MatcherService.php` (`reconcile` `void` → `string`)
5. `app/Http/Controllers/ReconciliationController.php` (store/batch/updateGroupEmpresa, aditivo)
6. `app/Http/Controllers/ClienteEmpresaController.php` (nuevo) + `routes/web.php`
7. `resources/js/Pages/Clients/Index.vue` + `SidebarLink` en `AuthenticatedLayout.vue` + i18n `lang/{es,en}.json`

## 6. Reglas de negocio y casos borde

- **Identidad = `facturas.rfc`** (estable); `nombre` solo display. Un mapeo por `(team_id, rfc)`.
- **`recordar`** (auto-aprendizaje): al asignar empresa **no-null** a un grupo (`updateGroupEmpresa`), `updateOrCreate` por rfc (**last-wins**) e incrementa `veces`. Des-asignar (null) no aprende. Deduplica RFC dentro del lote (último nombre gana). Acepta arrays `[{rfc,nombre}]` o modelos `Factura`.
- **`sugerirEmpresa`**: devuelve empresa si TODOS los RFC conocidos mapean a la MISMA empresa y hay ≥1 mapeado; RFC sin mapeo se ignoran; ambiguo (multi-RFC distinto) o ninguno → `null`.
- **Auto-asignación al conciliar** (`store`/`batch`): tras `reconcile` (que devuelve `group_id`), si `sugerirEmpresa` da empresa, `update` del grupo. RFC desconocido / ambiguo → grupo sin empresa (como hoy). Fuera de la transacción del motor.
- **`aplicarASinEmpresa`**: recorre grupos con `empresa_id` null; asigna los que tengan sugerencia unívoca; deja ambiguos/sin-mapeo. Devuelve conteo.
- **Detección recurrente / "dejó de facturar"**: ventana 4 meses (actual + 3 previos). `recurrente` = facturó en ≥3 de los 4; `sin_factura_mes_actual` = recurrente sin factura en el mes en curso. Devuelve solo recurrentes, "sin factura este mes" primero.
- **Casos borde:** borrar empresa → mapeo sobrevive (`nullOnDelete`); reasignar la misma empresa → no rompe (idempotente); des-asignar no borra el aprendizaje previo; `reconcile` retornando `group_id` es compatible con callers que lo ignoran.

## 7. Plan de pruebas

- **`ClienteEmpresaServiceTest`** (Feature): `recordar` (upsert por rfc, last-wins, incrementa veces, dedup de lote, acepta modelos); `sugerirEmpresa` (mono-rfc, multi-rfc misma, multi-rfc distinta→null, sin mapeo→null, ignora rfc sin mapeo); `aplicarASinEmpresa` (asigna unívocos, deja ambiguos); `rfcsDeGrupo`; tenancy (otro team no entra).
- **`ClienteEmpresaControllerTest`** (Feature): index render (catálogo + empresas + recurrentes); cualquier-miembro no-owner; update override; empresa de otro team → 422; aplicarSugerencias; no fuga cross-team; update de otro team → 404; detección flags/clears mes actual.
- **`ReconciliationTest`** (extendido): `updateGroupEmpresa` aprende el mapeo; `store` auto-asigna con RFC conocido; `store` deja sin empresa con RFC desconocido.
- **Sin regresiones** vs baseline (13) en `MatcherServiceTest`/`RegressionTest`/`ReconciliationTest`.

## 8. Impacto en lo existente

- **Tenancy:** nueva tabla `TeamOwned`; servicio team-explícito (queue-safe). Controller valida `current_team_id` (defense in depth).
- **Motor de conciliación:** único toque = `reconcile` retorna `string` (el `group_id` local) en vez de `void`. NO cambia matching/saldos/`lockForUpdate`. Auto-asignación ocurre **fuera** de la transacción.
- **Contratos Inertia:** nueva page `Clients/Index`; `/reconciliation/history` sin cambios (ya traía `empresa`).

## 9. Riesgos y mitigaciones

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Auto-asignar empresa incorrecta | Ingreso mal clasificado en P&L | Solo asigna con mapeo unívoco; editable en history y en `/clients`; ambiguo → sin empresa |
| Tocar `reconcile` rompe el motor | Corrupción financiera | Solo se cambia el tipo de retorno; misma lógica; cubierto por Matcher/Regression/Reconciliation tests |
| Fuga cross-team | Datos de otro team | Servicio team-explícito + validación `current_team_id` + tests de tenancy |

## 10. Definition of Done

- [x] A. Pruebas: tests nuevos verdes; 0 regresiones nuevas vs baseline.
- [x] B. Docs: `domain.md`, `endpoints.md`, `business-rules.md` §14, `flows/reconciliation.md`, este SDD.
- [x] C. Commit/reviews: rama `feature/catalogo-cliente-empresa`.
- [x] D. Gate financiero: motor de conciliación intacto (solo se expone `group_id`); P&L sin cambios.
