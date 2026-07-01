# SDD — Dashboard ejecutivo + export PDF (Fase 6)

> SDD **ampliado** (consume el módulo sensible a dinero de Fase 5 y expone export en cola). Módulo: Finanzas / Dashboard ejecutivo · Fase: 6 (última del v1) · Autor: Juan + Claude · Fecha: 2026-06-30 · Estado: implementado

## 1. Objetivo
La **cara visible nivel CEO/consejo** del Estado de Resultados: un dashboard ejecutivo (`/executive`) con KPIs, P&L en cascada (waterfall), comparativos (periodo anterior + YoY), margen por unidad de negocio y tarjeta de ingreso recurrente ("Tu Checador"), más **export PDF asíncrono** presentable a consejo. No agrega lógica financiera nueva: **consume** `ProfitLossService` (Fase 5) y un nuevo `PeriodResolver` para resolver periodos. Liga al PRD `docs/prd/finanzas-egresos-multiempresa.md`, Fase 6. Es la **última fase del v1** (Fases 0–6).

## 2. Alcance
- **Incluye:**
  - `App\Services\Finance\PeriodResolver` (POPO sin estado): `resolve`/`previous`/`yearOverYear` para mensual/trimestral/semestral/anual.
  - `App\Http\Controllers\ExecutiveController` (`index` + trío `export`/`checkExportStatus`/`downloadExport`), **solo owner**.
  - `App\Jobs\GenerateProfitLossPdfJob` (cola `exports`, clon del PDF de conciliación) + vista `resources/views/exports/profit_loss/pdf_report.blade.php`.
  - `resources/js/Pages/Executive/Index.vue` (KPIs, waterfall, margen por empresa, Tu Checador, comparativos, botón export con polling) + `SidebarLink` condicionado a owner + claves i18n `lang/es.json`/`lang/en.json`.
  - Extensión de `ProfitLossService::forPeriod` con `?int $teamId` (queue-safety).
  - Tests: `tests/Unit/PeriodResolverTest.php`, `tests/Feature/ExecutiveControllerTest.php` (8), extensión de `tests/Feature/ProfitLossServiceTest.php`.
- **NO incluye (no-goals):**
  - Desglose del P&L por categoría (líneas dentro de COGS/OPEX): el waterfall usa **grupos** (`ProfitLossService` da totales por grupo).
  - MRR de Tu Checador por categoría de ingreso (no hay ingreso por categoría): se muestra el ingreso de la **empresa** Tu Checador, no un MRR categoría-nivel.
  - Librería de gráficas: el waterfall, las barras de margen y los comparativos se hacen con **CSS/divs + Tailwind** y `empresa.color` inline (sin dependencia nueva; respeta el caveat Tailwind v3/v4 de `docs/decisions/0005`).
  - Conciliación de egresos (Fase 7, fuera de v1).

## 3. Modelo de datos (delta)
**Ninguno.** Fase 6 **no tiene migración**: no crea ni modifica tablas. Reusa `export_requests` con un nuevo valor de `type`: **`pl_pdf`** (junto a los `xlsx`/`pdf` de conciliación). Los rangos de periodo se calculan en memoria (`PeriodResolver`) y el P&L se lee de las tablas existentes vía `ProfitLossService` (Fase 5).

## 4. Endpoints / rutas
Grupo `auth`, `routes/web.php`. **Todos solo-owner** (`abort_unless(ownsCurrentTeam(...), 403)`).

| Método | Ruta | Controller | Notas |
|---|---|---|---|
| GET | `/executive` | `ExecutiveController@index` | Inertia `Executive/Index`. Solo owner (403) |
| GET | `/executive/export` | `ExecutiveController@export` | **`throttle:10,1`**. Crea `ExportRequest(type='pl_pdf')` + dispatcha job. JSON `{id,status:'queued'}` si `wantsJson()`. Solo owner (403) |
| GET | `/executive/export/{id}/status` | `ExecutiveController@checkExportStatus` | JSON `{status,error_message,is_offline}`. Scope team + `user_id === auth::id()` (403 ajeno). Solo owner |
| GET | `/executive/export/{id}/download` | `ExecutiveController@downloadExport` | `Storage::download`. Requiere `status='completed'` + archivo en disco (si no, 404). Scope team + ownership. Solo owner |

Props de `index`: `pnl`, `pnlPrev`, `pnlYoY`, `porEmpresa`, `tuChecador`, `empresas`, `filters` (`granularidad`, `empresa_id`, `month`, `year`). Detalle en `docs/endpoints.md`.

## 5. Archivos a tocar (orden)
1. `app/Services/Finance/ProfitLossService.php` — añadir `?int $teamId = null` (queue-safety).
2. `app/Services/Finance/PeriodResolver.php` (nuevo).
3. `app/Http/Controllers/ExecutiveController.php` (nuevo).
4. `app/Jobs/GenerateProfitLossPdfJob.php` (nuevo).
5. `resources/views/exports/profit_loss/pdf_report.blade.php` (nuevo).
6. `resources/js/Pages/Executive/Index.vue` (nuevo) + `resources/js/Layouts/AuthenticatedLayout.vue` (SidebarLink owner) + `lang/{es,en}.json`.
7. `routes/web.php` — 4 rutas.
8. Tests + docs.

## 6. Reglas de negocio y casos borde
- **Autorización solo-owner (una sola fuente de verdad):** `ChecksTeamOwnership::ownsCurrentTeam`. Cada método del controller abre con `abort_unless(..., 403)`. El `SidebarLink` se condiciona a owner (`current_team.user_id === auth.user.id`) → un no-owner ni lo ve. Justificación: la vista expone el P&L consolidado del grupo, márgenes y costo laboral. Ver `docs/security.md` §11.
- **Queue-safety (punto crítico de correctness):** el job de PDF corre en cola **sin auth**, así que el global scope de `TeamOwned` está apagado. Sin un filtro de team explícito, `ProfitLossService` sumaría **todos los teams** en el PDF. Mitigación: `forPeriod(..., $teamId)` añade `->where('<tabla>.team_id', $teamId)` a cada query; el `team_id` viaja en `ExportRequest.filters['team_id']` y el job lo pasa explícito. El controller (en request, con scope activo) también lo pasa explícito (defense-in-depth, consistente con `ReconciliationPdfExport`).
- **Resolución de periodos (`PeriodResolver`):**
  - `resolve`: `mensual` = mes del ancla; `trimestral` = `startOfQuarter`/`endOfQuarter`; `semestral` = ene–jun si `month <= 6`, jul–dic si `>= 7` (cálculo manual); `anual` = año completo. Granularidad no reconocida → `mensual`.
  - `previous`: periodo inmediatamente anterior de la misma granularidad; **cruce de año** vía `subMonthsNoOverflow`/`subYearNoOverflow` (ej. enero mensual → diciembre del año anterior; Q1 → Q4 previo; S1 → S2 previo).
  - `yearOverYear`: mismo rango un año antes (`subYearNoOverflow`).
  - Devuelve `startOfDay`/`endOfDay`; el consumidor usa `toDateString()`, así que solo importa el día.
- **Ancla del periodo:** `month`/`year` vienen de `SetGlobalDateFilters` (selector global del sidebar); el dashboard reusa, no reemplaza, ese mecanismo.
- **Consolidado vs empresa:** `empresa_id` null = consolidado (incluye filas "sin asignar"); `empresa_id` dado = solo esa empresa. `normalizeEmpresaId` → int positivo o null.
- **Tu Checador (degrada con gracia):** se localiza la empresa por `slug='tu-checador'`; si no existe en el team, `tuChecador = null` y la tarjeta no se renderiza (no truena).
- **Margen por empresa:** un `forPeriod` por cada empresa activa del team (`ResolvesExpenseOptions::empresasActivas` en request; `Empresa::where(team)->where(activo)` ordenado en el job).
- **Export async (espeja conciliación):** `ExportRequest` status `queued → processing → completed|failed`; polling cada 2s al endpoint de status; `is_offline=true` si `queued > 2min`; `downloadExport` exige `completed` + archivo en disco.
- **Gráficas CSS/divs:** waterfall, barras de margen y deltas se calculan en el front desde `pnl`/`pnlPrev`/`pnlYoY`/`porEmpresa` con divs + Tailwind + `empresa.color` inline (sin librería).

## 7. Plan de pruebas
- **Unit `PeriodResolverTest`** (puro, sin DB): rangos correctos para mensual/trimestral/semestral/anual desde un ancla; `previous` con cruce de año (enero → diciembre previo); `yearOverYear`; bordes (Q4, S2, diciembre).
- **Feature `ExecutiveControllerTest`** (8 casos, `actingAs` + `assertInertia` + `Queue::fake`):
  - **Owner** ve `Inertia::render('Executive/Index')` con `pnl`, `pnlPrev`, `pnlYoY`, `porEmpresa`, `tuChecador`, `empresas`, `filters`; montos clave cuadran (datos sembrados).
  - **No-owner** (miembro del team) → **403** en `index` y `export`.
  - **Tenancy:** datos de otro team no entran (servicio con `team_id` explícito aísla).
  - `export` crea `ExportRequest(type='pl_pdf')` y `assertPushed(GenerateProfitLossPdfJob)`.
  - `checkExportStatus`/`downloadExport` respetan ownership (403 ajeno) y status.
- **Feature `ProfitLossServiceTest`** (extensión): caso con **`team_id` explícito SIN `actingAs`** (queue-safety) que aísla por team y cuadra al centavo.
- Mantener **0 regresiones** vs baseline (13 fallos preexistentes).

### Casos numéricos fijos (gate financiero)
- El P&L del dashboard y del PDF cuadran entre sí y con `ProfitLossService` (consolidado = suma de empresas + sin-asignar; identidad `utilidad_neta = ingresos − egresos`).
- Con `team_id` explícito, un segundo team con datos NO afecta los totales del primero (test queue-safety).

## 8. Impacto en lo existente
- **Tenancy:** refuerza (filtro `team_id` explícito en `ProfitLossService`); no debilita el scope. `forPeriod` con `teamId = null` mantiene el comportamiento original → tests de Fase 5 intactos.
- **Colas:** un job nuevo en `exports` (sigue el patrón `tries=3`/`backoff`/`failed()`).
- **Migraciones:** ninguna.
- **Contratos Inertia:** una page nueva (`Executive/Index`) + un `SidebarLink`. No cambia props compartidas.
- **Motor de conciliación:** **no se toca** (`MatcherService` intacto). Sin `withoutGlobalScopes` en controllers.

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Job en cola sin auth suma todos los teams en el PDF | Fuga cross-team en un documento de consejo | `forPeriod(..., $teamId)` con `team_id` explícito desde `ExportRequest.filters`; test queue-safety sin `actingAs` |
| No-owner accede al P&L del grupo | Exposición de finanzas sensibles | `abort_unless(ownsCurrentTeam, 403)` en los 4 métodos + `SidebarLink` condicionado a owner; tests 403 |
| Descarga de export ajeno | Fuga entre usuarios del mismo team | `user_id === auth::id()` en status/download (no basta ser owner) |
| `previous`/`yoy` mal en cruce de año | Comparativos equivocados | `subMonthsNoOverflow`/`subYearNoOverflow` + `PeriodResolverTest` (enero, Q4, S2, diciembre) |
| Empresa "Tu Checador" no existe | Error al render | Degrada a `null`; la tarjeta no se muestra |
| Doble conteo / números que no cuadran | P&L incorrecto a consejo | Toda la lógica financiera vive en `ProfitLossService` (Fase 5, ya testeado al centavo); Fase 6 solo lo consume |

## 10. Definition of Done
Aplica el DoD §8 del PRD (A pruebas → B docs → C commit/reviews → D gate financiero):
- **A** ✓ `PeriodResolverTest`, `ExecutiveControllerTest` (8), extensión de `ProfitLossServiceTest` verdes; suite completa = baseline 13 fallos preexistentes, **0 regresiones nuevas**.
- **B** ✓ `docs/endpoints.md` (`/executive` + trío export), `docs/domain.md` (`PeriodResolver` + `forPeriod` con `team_id`), `docs/operations.md` (`GenerateProfitLossPdfJob`), `docs/security.md` (solo owner), este SDD, PRD Fase 6 cerrada + v1 completo.
- **C** ✓ commit atómico en `feature/finanzas-fase6`; `/code-review`; merge `--no-ff`.
- **D** ✓ gate financiero: dashboard y PDF cuadran con `ProfitLossService`; sin doble conteo; sin mezcla de teams; motor de conciliación intacto.

**Estado: implementado** (`feature/finanzas-fase6`).

---

# v2 — Analítica temporal

> Ampliación del dashboard ejecutivo · Rama: `feature/dashboard-analytics` · Autor: Juan + Claude · Fecha: 2026-07-01 · **Estado: implementado**

## v2.1 Objetivo
El dashboard v1 es una **foto de un solo periodo** (KPIs + waterfall + margen por empresa): al elegir un rango >1 mes solo **suma**, sin mostrar el **comportamiento en el tiempo** ni desgloses finos, pese a que la data lo permite (egresos por categoría/grupo/naturaleza/proveedor, nómina, ingresos bancario/efectivo y por empresa, todo mensual). v2 lo convierte en un **dashboard analítico** con tendencias, composición y desgloses, **manteniendo** la foto (waterfall) actual. **No** cambia el cálculo/identidad del P&L: solo lo consume mes a mes.

## v2.2 Alcance
- **Backend:** `App\Services\Finance\FinanceAnalyticsService` (POPO sin estado, **sin migración**), inyecta `ProfitLossService` + `PeriodResolver`. Métodos con `teamId` **explícito** (queue-safe): `monthlySeries` (reusa `forPeriod` por mes → identidad garantizada), `ingresoPorEmpresaMensual` (2 queries agrupadas, bucket "sin asignar"), `egresosPorCategoria`, `egresosPorNaturaleza`, `topProveedores`, `nominaRollup`. Ver `docs/domain.md`.
- **`ExecutiveController@index`:** nuevo param `months` (validado `in:6,12`, default 12); pasa `series`, `ingresoEmpresaSeries` (siempre consolidado multi-empresa), `egresosPorCategoria`, `egresosPorNaturaleza`, `topProveedores`, `nominaRollup`; añade `months` a `filters`. `export` propaga `months` al `ExportRequest`.
- **Frontend:** rediseño de `Executive/Index.vue` con widgets **A–I** extraídos a `Executive/Partials/` usando **ApexCharts** (`vue3-apexcharts`, import local por Partial):
  - **A. KpiCard** — número + margen % + sparkline + Δ vs anterior/YoY.
  - **B. TrendChart** — Ingresos vs Egresos vs Utilidad neta por mes.
  - **C. MarginTrendChart** — márgenes bruto/EBITDA/neto por mes.
  - **D. EgresosComposition** — barras apiladas por mes (COGS/OPEX/abajo/sin_clasificar) + dona por categoría del periodo.
  - **E. IngresoEmpresaChart** — apilado por empresa (color de empresa) + Tu Checador resaltado.
  - **F. FijoVariableChart** — fijo vs variable (dona/barras).
  - **G. TopProveedores** — ranking + top categorías.
  - **H. NominaRollup** — costo de nómina fiscal/complemento/total.
  - **I. PnlWaterfall** — el waterfall v1 extraído como Partial (se mantiene).
  - **Selector 6/12 meses** en el header (junto a granularidad + empresa), `router.get` con `preserveState`.
- **PDF:** dompdf **no** renderiza charts JS → el `GenerateProfitLossPdfJob` calcula serie mensual + desgloses con `FinanceAnalyticsService` (`months` del `ExportRequest`, `team_id` explícito) y la Blade los añade en **tablas** tras la tabla P&L (A4, estilo actual).

## v2.3 Decisiones
- **Librería de charts (ApexCharts):** v1 evitó dependencias (CSS/divs). v2 necesita líneas/áreas/apiladas/donas/sparklines multi-serie temporales → hacerlas a mano sería frágil y caro. Se adopta **ApexCharts** (SVG, no choca con el caveat Tailwind v3/v4 de `docs/decisions/0005`). Costo: bundle grande (`apexcharts`/`vue3-apexcharts` ~500–600 kB min c/u). **Mitigación futura (fuera de alcance):** code-split / lazy-load del bundle de charts (dynamic `import()`), hoy import local por Partial.
- **Ventana 6/12 (no rango libre):** simplicidad de UI y de perf; termina en el ancla mes/año del sidebar.
- **`ingresoEmpresaSeries` ignora el filtro de empresa** por diseño (siempre muestra el split consolidado).
- **Perf:** `monthlySeries` cuesta ~4 queries × meses (≤48 con 12 meses). Para la escala v1 (<pocas empresas) está bien; si crece → caché/batch (fuera de alcance).

## v2.4 Impacto / no-goals
- **Sin migración**, sin cambio de esquema. **No** toca `ProfitLossService::forPeriod` (solo lo consume). Motor de conciliación intacto. Tenancy reforzada (`team_id` explícito en todos los métodos del servicio). Charts **solo** en UI; el PDF usa tablas.

## v2.5 Pruebas / DoD
- `FinanceAnalyticsServiceTest` (al centavo: series/márgenes, split por empresa+mes con "sin asignar", desgloses, tenancy, e invariante suma(serie)==`forPeriod` del rango).
- `ExecutiveControllerTest` extendido (props analíticas presentes, validación `months`, solo-owner 403).
- `GenerateProfitLossPdfJobTest` (dispatchSync → `completed` + PDF, con y sin datos; la Blade con tablas no truena por variable indefinida).
- Suite completa = baseline **13 fallos** preexistentes, **0 regresiones nuevas**; `npm run build` verde con ApexCharts; smoke server-side del PDF OK.

**Estado: implementado** (`feature/dashboard-analytics`).
