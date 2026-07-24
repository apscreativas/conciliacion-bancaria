# SDD — Egresos recurrentes (Fase 3)

> SDD **ampliado** (módulo sensible a dinero, PRD §8.1). Módulo: Finanzas / Recurrentes · Fase: 3 · Autor: Juan + Claude · Fecha: 2026-06-29 · Estado: implementado

## 1. Objetivo
Que gastos fijos (servidores, suscripciones) **se registren solos** cada periodo a partir de **plantillas**, vía un **comando idempotente** + **scheduler**. Liga al PRD `docs/prd/finanzas-egresos-multiempresa.md`, Fase 3. Lo crítico: **idempotencia** y el **cálculo de fechas**. Aislado del motor de conciliación.

## 2. Alcance
- **Incluye:** tabla `egresos_recurrentes` + `egresos.egreso_recurrente_id`; modelo `EgresoRecurrente`; servicio `RecurrenceCalculator`; comando `egresos:generar-recurrentes` + schedule diario; CRUD de plantillas; tests (incl. unit de fechas).
- **NO incluye:** `quincenal` (queda en el enum, su lógica de 2 fechas/mes es de **Fase 3B nómina**, comando `nomina:generar` separado); festivos en el ajuste de día hábil (solo fin de semana en v1); ingresos manuales (Fase 4); P&L/dashboard (Fases 5–6).

## 3. Modelo de datos (delta)
- `egresos_recurrentes` (PRD §3.4): empresa_id/categoria_id (nullable, nullOnDelete), descripcion, proveedor, monto, `frecuencia` enum, `dia_del_mes`, `ajuste_dia_habil` enum, `fecha_inicio`, `vigencia_tipo` enum + `fecha_fin`/`num_pagos`/`pagos_generados`, `activo`, `proxima_generacion`, `user_id`. Índice `(team_id, activo, proxima_generacion)`.
- `egresos.egreso_recurrente_id` (FK nullable, **nullOnDelete** — borrar plantilla no borra egresos generados).

## 4. Lógica del generador (lo crítico)
`RecurrenceCalculator`: `firstOccurrence` (día `dia_del_mes` del **mes de `fecha_inicio`**, clamp al mes — **puede ser anterior a `fecha_inicio`**, ver §6), `onOrAfter` (primera fecha programada ≥ ancla; solo para reactivación), `nextDate` (avanza +1/2/3/12 meses con `addMonthsNoOverflow` y re-fija el día nominal, clamp al mes), `applyDiaHabil` (fin de semana → hábil anterior/siguiente).

`GenerarEgresosRecurrentes` (`egresos:generar-recurrentes {--dry-run}`): por cada plantilla `due()` (todos los teams, sin Auth), **loop catch-up** (tope 24) mientras `proxima_generacion <= hoy` y vigencia lo permita:
1. fecha de pago = `applyDiaHabil(proxima_generacion, ajuste)`.
2. **idempotencia:** si ya existe egreso `(egreso_recurrente_id, fecha)` → no recrear.
3. dentro de **`DB::transaction`**: crear `Egreso` (`origen='recurrente'`, `user_id` de la plantilla), `pagos_generados++`, avanzar `proxima_generacion = nextDate(...)`, aplicar vigencia (`num_pagos`/`hasta_fecha` → `activo=false`), guardar plantilla — todo atómico.

Schedule: `routes/console.php` → `Schedule::command(...)->dailyAt('01:00')->withoutOverlapping()`. Ver `docs/operations.md` (Scheduler) para cron prod / `schedule:work` local.

## 5. Archivos tocados
- Migraciones `2026_06_29_000003_create_egresos_recurrentes_table`, `..._000004_add_egreso_recurrente_id_to_egresos_table`.
- `app/Models/EgresoRecurrente.php`, `app/Models/Egreso.php` (+`egreso_recurrente_id`), `EgresoRecurrenteFactory`.
- `app/Services/Finance/RecurrenceCalculator.php`, `app/Console/Commands/GenerarEgresosRecurrentes.php`, `routes/console.php`.
- `app/Http/Requests/EgresoRecurrenteRequest.php`, `app/Http/Controllers/EgresoRecurrenteController.php`, `routes/web.php`.
- `resources/js/Pages/RecurringExpenses/{Index,Create}.vue`, botón en `Expenses/Index.vue`, `lang/{es,en}.json`.

## 6. Reglas de negocio y casos borde
- **Idempotencia:** verificada por existencia `(egreso_recurrente_id, fecha)` + avance de `proxima_generacion` en transacción. Re-correr el mismo día no duplica.
- **Catch-up:** genera todos los periodos faltantes hasta hoy (tope 24 + warning).
- **Clamp de día:** `dia_del_mes=31` en febrero → 28/29; el día nominal se recupera el mes siguiente.
- **Día hábil:** fin de semana → hábil anterior/siguiente (sin festivos).
- **Vigencia:** `num_pagos` corta al llegar a N; `hasta_fecha` corta cuando la próxima excede `fecha_fin`; ambas marcan `activo=false`.
- **Tenancy:** el comando setea `team_id` explícito (sin Auth, el global scope no aplica); el CRUD usa `TeamOwned` + `ensureOwnTeam` (404/422 cross-team).
- **Primera ocurrencia retroactiva (2026-07-23):** el primer egreso es el del día `dia_del_mes` del **mes de `fecha_inicio`**, aunque ese día ya haya pasado al capturar la plantilla (ej. renta día 10 capturada el 15-jul → genera el 10-jul en la siguiente corrida vía catch-up). La fecha del primer egreso **puede ser anterior a `fecha_inicio`** — es intencional, no lo "corrijas": decisión de negocio de Juan (un gasto del mes en curso es real aunque se capture tarde). Cuenta para `num_pagos` como cualquier periodo.
- **`update`** recomputa `proxima_generacion` si `pagos_generados==0` (re-agenda con `firstOccurrence` desde `fecha_inicio`, retroactivo permitido: una plantilla sin pagos debe su calendario completo — esta rama tiene precedencia aunque además se esté reactivando) **o** al **reactivar** una plantilla con historial (`activo` false→true): en ese caso usa `onOrAfter(max(fecha_inicio, hoy))` para **reanudar desde hoy** y no disparar una avalancha de egresos retroactivos.

## 7. Plan de pruebas
- **Unit `RecurrenceCalculatorTest`:** `nextDate` por frecuencia, clamp y recuperación de día, `applyDiaHabil` (fin de semana → hábil); `firstOccurrence` retroactiva dentro del mes de inicio + clamp en mes corto; `onOrAfter` conserva la semántica sin retroactivos (mensual y bimestral).
- **Feature `GenerarEgresosRecurrentesTest`** (con `Carbon::setTestNow`): genera una vez + **idempotencia** en re-corrida; **catch-up** (2 periodos atrasados → 3 egresos); vigencia `num_pagos`/`hasta_fecha` (corta + `activo=false`); **día hábil** (sábado → viernes); multi-team (team_id correcto); `--dry-run` no persiste ni avanza. Hardening: **índice único** `(egreso_recurrente_id, fecha)` rechaza duplicados; periodo preexistente **cuenta** para `num_pagos` sin duplicar; `habil_siguiente` que cruza el mes **no** genera después de `fecha_fin`.
- **Feature `EgresoRecurrenteTest`:** CRUD (miembro del team) + validación (monto>0, categoría tipo=egreso, quincenal rechazada, reglas condicionales de vigencia) + tenancy 404 + **reactivación** con historial reancla `proxima_generacion` en hoy + **plantilla capturada después del día de pago** genera el egreso del mes en curso end-to-end + reactivación con `pagos_generados=0` recalcula desde `fecha_inicio` (retroactivo).

## 8. Impacto en lo existente
- Migraciones aditivas; comando + schedule nuevos; rutas/páginas nuevas; botón en Egresos. **No toca** conciliación/matcher.

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Doble generación (cron corre 2×, manual vs cron, multi-servidor) | Egresos duplicados | Índice único `(egreso_recurrente_id, fecha)` + `exists()` + `withoutOverlapping`/`onOneServer`; el `INSERT` perdedor se rechaza y se trata como ya generado |
| Cron caído varios periodos | Huecos en contabilidad | Catch-up de periodos faltantes (tope 24 + `warn` + `Log::warning`) |
| Borrar plantilla borra egresos | Pérdida de histórico | `nullOnDelete` en `egreso_recurrente_id` |
| Borrar al creador borra la plantilla | El team deja de generar el fijo | `user_id` con `nullOnDelete` |
| Pago ajustado cae fuera de vigencia | Egreso después de `fecha_fin` | `hasta_fecha` se evalúa contra la fecha de pago ajustada, no el día nominal |
| Reactivar plantilla vencida | Avalancha de egresos retroactivos | `update` re-ancla `proxima_generacion` en hoy al reactivar |
| Día 31 en mes corto / fin de semana | Fecha inválida o no hábil | `nextDate` clamp + `applyDiaHabil`, cubiertos por unit tests |
| Recurrencia infinita (bug de fechas) | Runaway | Tope de 24 iteraciones/plantilla/corrida |

## 10. Definition of Done
DoD §8 del PRD: **A** pruebas ✓ (20 nuevas, idempotencia/catch-up/vigencia/día hábil; 0 regresiones) · **B** docs ✓ (`domain.md`, `endpoints.md`, `operations.md` Scheduler, este SDD, PRD Fase 3) · **C** commit atómico en `feature/finanzas-fase3` · **D** gate ✓ (idempotencia verificada; no toca conciliación).
