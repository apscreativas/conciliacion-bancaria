# SDD — Empleados + nómina quincenal / Fase 3B

> SDD ampliado (módulo de dinero). Sale del paso PLAN/VALIDATE de CLAUDE.md.
> Módulo: Finanzas · Fase: 3B · Autor: Juan + Claude · Fecha: 2026-06-30 · Estado: aprobado (diseño)

## 1. Objetivo
Registrar la plantilla de personal y que la nómina (parte fiscal + complemento) se genere sola cada quincena como `egresos`, sin captura manual mes a mes. Liga al PRD (`docs/prd/finanzas-egresos-multiempresa.md`, §3.7 y Fase 3B). Aquí va la lógica quincenal (día 15 + último día hábil) que se difirió en Fase 3.

## 2. Alcance
- **Incluye:** tabla `empleados` (+ `TeamOwned`); CRUD `/employees` (solo owner del team); comando `nomina:generar` quincenal idempotente con catch-up de ventana móvil + schedule diario; generación de egresos de nómina (fiscal + complemento) por empleado activo en su fecha real de pago.
- **NO incluye (no-goals):** IMSS/ISN (se manejan como plantillas en `egresos_recurrentes`, ya soportado por Fase 3); días festivos oficiales (solo se ajusta por fin de semana en v1); cálculo fiscal real (esto es control gerencial); timbrado/dispersión.

## 3. Modelo de datos (delta)

**`empleados`** (nueva, dominio → `TeamOwned`):
```
id
team_id        FK teams        cascadeOnDelete
empresa_id     FK empresas     nullable, nullOnDelete   // centro de costo (requerida a nivel app)
nombre         string
puesto         string          nullable
fecha_entrada  date
fecha_baja     date            nullable                 // baja: deja de generar a partir de su quincena
salario_fiscal decimal(15,2)                            // mensual, sueldo timbrado
salario_real   decimal(15,2)                            // mensual, lo que realmente recibe
clasificacion  enum('tecnica','administrativa') nullable // COGS facturable vs OPEX
activo         boolean         default true
user_id        FK users        nullable, nullOnDelete   // quién lo registró
timestamps
index (team_id, activo)
```

**`egresos`** (existente, Fase 2 — 3 cambios; ver ⚠️):
```
+ empleado_id  FK empleados    nullable, nullOnDelete   // liga el egreso de nómina a su empleado
+ unique (empleado_id, fecha, categoria_id) → egresos_empleado_periodo_unique  // idempotencia en DB
~ user_id  cambia de  constrained()->cascadeOnDelete()  a  nullable()->nullOnDelete()  ⚠️
```
- **NULLs múltiples permitidos** en el índice único (MySQL) → egresos manuales (`empleado_id` null) y recurrentes no colisionan.
- ⚠️ **`egresos.user_id` → nullable + nullOnDelete:** hoy es `cascadeOnDelete` no-nullable. Necesario porque `empleado.user_id` (y `egreso_recurrente.user_id` desde Fase 3) son `nullOnDelete`: si se borra al usuario creador, el generador insertaría `user_id=null` y fallaría. Además cierra correctamente el hallazgo #9 de Fase 3 (un registro financiero debe sobrevivir al borrado de su usuario). **Toca una tabla ya en `develop`** (Fase 2) — validado con el usuario.

## 4. Endpoints / rutas
| Método | Ruta | Controller | Notas (auth, ownership) |
|---|---|---|---|
| GET | `/employees` | `EmpleadoController@index` | auth; **solo owner** (Policy `viewAny`); TeamOwned + `where team_id` |
| GET | `/employees/create` | `EmpleadoController@create` | solo owner |
| POST | `/employees` | `EmpleadoController@store` | solo owner; `EmpleadoRequest` |
| GET | `/employees/{employee}/edit` | `EmpleadoController@edit` | solo owner; ownership 404 (binding) + 403 (Policy) |
| PUT | `/employees/{employee}` | `EmpleadoController@update` | solo owner; `EmpleadoRequest` |
| DELETE | `/employees/{employee}` | `EmpleadoController@destroy` | solo owner |

`Route::resource('employees', EmpleadoController::class)->except('show')` (igual patrón que `settings/companies`).

## 5. Archivos a tocar (orden)
1. `database/migrations/..._create_empleados_table.php` (nueva)
2. `database/migrations/..._add_empleado_id_and_relax_user_id_on_egresos.php` (nueva: `empleado_id` + índice único + `user_id` nullable)
3. `app/Models/Empleado.php` (+ `TeamOwned`); `app/Models/Egreso.php` (`empleado_id` fillable + `empleado()` belongsTo)
4. `app/Policies/EmpleadoPolicy.php` (espeja `EmpresaPolicy`)
5. `app/Services/Finance/NominaCalculator.php` (fechas de pago de quincena, reusa `RecurrenceCalculator::applyDiaHabil`)
6. `app/Console/Commands/GenerarNomina.php` (`nomina:generar`)
7. `routes/console.php` (schedule)
8. `app/Http/Controllers/EmpleadoController.php` + `app/Http/Requests/EmpleadoRequest.php`
9. `resources/js/Pages/Employees/{Index,Create}.vue`; `lang/{es,en}.json`
10. `database/factories/EmpleadoFactory.php`; tests; docs

(>3 archivos → confirmado con el usuario en VALIDATE.)

## 6. Reglas de negocio y casos borde
**Fechas de pago (por mes):** Q1 = día **15**; Q2 = **último día del mes**. Cada una ajustada con `habil_anterior` (si cae sábado/domingo → viernes anterior). Sin festivos en v1.

**Catch-up (ventana móvil):** cada corrida evalúa todas las fechas de pago Q1/Q2 cuyo día caiga en `[hoy-40d, hoy]`, idempotente. `--month=YYYY-MM` fuerza un mes concreto. Recorre **todos los teams** con `withoutGlobalScopes()`.

**Elegibilidad de un empleado en fecha de pago `d`:** `activo == true` && `fecha_entrada <= d` && (`fecha_baja` es null || `d <= fecha_baja`). Baja a mitad de periodo: no genera la quincena cuyo `d` es posterior a `fecha_baja`.

**Montos por quincena (salarios son mensuales):**
- **Fiscal:** `monto = round(salario_fiscal / 2, 2)`. Categoría según clasificación: `tecnica` → **"Nómina técnica facturable"** (COGS); `administrativa` o `null` → **"Nómina fiscal"** (OPEX).
- **Complemento:** `c = salario_real - salario_fiscal`. Si `c > 0`: `monto = round(c / 2, 2)`, categoría **"Nómina complemento / real"**. Si `c <= 0` → **no se genera** egreso de complemento.
- Egreso resultante: `empresa_id` del empleado, `empleado_id`, `fecha = d`, `origen = 'recurrente'`, `user_id` del empleado, `descripcion` tipo `"Nómina fiscal Q1 — {nombre}"`.

**Resolución de categorías:** lookup por `(team_id, nombre)` exacto, cacheado por team. Si una categoría requerida no existe o está inactiva (el owner la borró/renombró) → `Log::warning` y se **omite** ese egreso (no se crea un egreso sin `categoria_id`).

**Idempotencia:** `exists(empleado_id, fecha, categoria_id)` (ruta normal) + `try/catch` de violación de UNIQUE contra `egresos_empleado_periodo_unique` (carrera manual vs cron / multi-servidor). La parte fiscal y la de complemento no colisionan entre sí (distinta `categoria_id`); Q1 y Q2 tampoco (distinta `fecha`).

**Casos borde:**
- Fin de mes en **domingo** → viernes anterior; día **15 en sábado** → viernes 14.
- Empleado dado de alta a mitad de mes: solo genera las quincenas con `d >= fecha_entrada`.
- `salario_real == salario_fiscal` → solo egreso fiscal (complemento 0 omitido).
- Re-ejecución / ventana solapada → no duplica.
- `--dry-run` no persiste.

## 7. Plan de pruebas
- **Unit `NominaCalculatorTest`:** Q1/Q2 por mes; **fin de mes en domingo → viernes**; día 15 en sábado → viernes 14; mes con 28/30/31 días.
- **Feature `GenerarNominaTest`** (`Carbon::setTestNow`):
  - **Caso numérico fijo:** empleado `salario_fiscal=20000`, `salario_real=24000`, administrativo → por quincena: fiscal `10000.00` (cat "Nómina fiscal") + complemento `2000.00` (cat "Nómina complemento / real"); dos quincenas en el mes → 4 egresos, suma `24000.00`.
  - Empleado **técnico** → parte fiscal en categoría "Nómina técnica facturable".
  - **Complemento ≤ 0** (real == fiscal) → solo egreso fiscal.
  - **Idempotencia** en re-corrida; ventana de catch-up cruzando fin de mes; índice único rechaza duplicado.
  - **Baja** a mitad de periodo: deja de generar desde su quincena.
  - Multi-team: `team_id` y `empleado_id` correctos.
  - `--dry-run` no persiste.
- **Feature `EmpleadoTest`:** CRUD solo-owner (miembro no-owner → **403**); validación (salarios `gt:0`, categoría/empresa scoped al team, `fecha_baja >= fecha_entrada`); tenancy 404 cross-team.
- **Tenancy:** no negociable; mantener **0 regresiones** vs baseline 13.

## 8. Impacto en lo existente
- **Migraciones:** nueva tabla + alteración de `egresos` (índice único, `empleado_id`, `user_id` nullable). ⚠️ toca tabla de Fase 2 (develop) — validado.
- **Colas:** ninguna (comando síncrono, como `egresos:generar-recurrentes`).
- **Contratos Inertia:** nuevas páginas; no cambia las existentes.
- **Motor de conciliación:** **NO** se toca.
- Reusa la infra de scheduler de Fase 3 y los patrones de hardening (índice único, `try/catch` dup, `withoutGlobalScopes`, `onOneServer`, `Log::warning`, traits, `where` sargable).

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Doble generación (cron 2×, manual vs cron, multi-servidor) | Nómina duplicada | Índice único `(empleado_id, fecha, categoria_id)` + `exists()` + `withoutOverlapping`/`onOneServer` |
| Server caído cruzando fin de mes | Quincena perdida | Catch-up ventana móvil ~40 días (idempotente) |
| Borrar empleado/creador borra egresos | Pérdida de histórico | `empleado_id` y `egresos.user_id` con `nullOnDelete` |
| Categoría de nómina borrada/renombrada | Egreso sin categoría / crash | Lookup tolerante: `Log::warning` + omite (no crea sin `categoria_id`) |
| Redondeo de mitades | Centavos | `round(x/2, 2)` por quincena; deriva ≤ centavos/año, inmaterial para control gerencial (documentado) |
| Exposición de salarios | Privacidad RRHH | Autorización **solo owner** (Policy) |

## 10. Definition of Done
Aplica el DoD §8 del PRD (A pruebas → B docs → C commit/reviews → D gate financiero). Pendiente: implementación, `/code-review develop`, merge `--no-ff` a develop, marcar Fase 3B ✅ en el PRD.
