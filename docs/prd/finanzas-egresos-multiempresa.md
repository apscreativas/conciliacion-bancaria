# PRD — Finanzas 360: Egresos, Multi-empresa y Estado de Resultados ejecutivo

> Documento de planeación para extender la app de Conciliación hacia control financiero completo.
> Pensado para ejecutarse **fase por fase** en Claude Code sin romper el motor de conciliación existente.
> Autor: Juan · Fecha: 2026-06-26 · Estado: Propuesta para aprobación

---

## 1. Visión en una frase

Convertir la app de **conciliación bancaria** (ingresos) en una **plataforma de control financiero del grupo**: capturar egresos (manuales y recurrentes) e ingresos no bancarios (efectivo), clasificarlos por empresa y categoría, y producir un **Estado de Resultados ejecutivo** (mensual / trimestral / semestral / anual) por empresa y consolidado, presentable a consejo de administración.

El grupo opera **3 unidades de negocio** bajo el mismo Team y la misma cuenta bancaria:

| Empresa | Negocio | Característica financiera |
|---|---|---|
| **Aplicaciones Creativas** | Desarrollo de software (software factory) | Ingreso por proyecto / servicios |
| **Tu Checador** | Producto (más movimiento en conciliación, más depósitos) | Ingreso recurrente tipo SaaS |
| **Domoticap** | Instalación de cámaras y seguridad | Ingreso por instalación + hardware |

---

## 2. Decisiones de arquitectura (ya tomadas)

| # | Decisión | Resolución | Implicación |
|---|---|---|---|
| D1 | Modelado multi-empresa | **Dimensión `empresa_id` dentro del Team actual**. Todo el dinero cae a la misma cuenta bancaria. | Un solo motor de conciliación. La empresa es una *etiqueta* que se asigna **después** de conciliar. NO se crean Teams separados. |
| D2 | Rigor contable del P&L | **Gerencial pragmático, base flujo/efectivo**. | Rápido de implementar y presentable a consejo. Estructura lista para migrar a devengado/formal después sin rehacer. |
| D3 | Origen de egresos (v1) | **Carga manual + recurrentes**. (CFDI y SAT quedan para fases futuras). | Motor de captura manual + generador de gastos recurrentes mensuales. |
| D4 | Asignación de empresa | **Post-conciliación**, no en el motor. | `empresa_id` se setea sobre la conciliación ya hecha (o el grupo) y sobre cada egreso/ingreso manual. El `MatcherService` NO se toca. |

### 2.1 Restricción crítica de no-romper

El motor de conciliación (`MatcherService`, `FileUploadController`, jobs de import, `ProcessXmlUpload`, `ProcessBankStatement`) está 100% funcional y es financieramente sensible. **Ninguna fase de este PRD modifica el algoritmo de matching.** Todo lo nuevo es aditivo: tablas nuevas + una columna `empresa_id` nullable en `conciliacions` + controllers y vistas nuevas.

---

## 3. Modelo de datos propuesto

Todas las tablas nuevas de dominio usan el trait `App\Models\Traits\TeamOwned` (global scope automático por `team_id`, set automático en create) y `migración con team_id`. Se mantiene defense-in-depth en controllers (`where('team_id', auth()->user()->current_team_id)`).

### 3.1 `empresas` (nueva)

La dimensión de unidad de negocio.

```
id, team_id, nombre, slug, color (hex, para dashboard), activo (bool),
orden (int, para UI), timestamps
```

- Seed inicial: Aplicaciones Creativas, Tu Checador, Domoticap.
- "Grupo / Consolidado" NO es un registro: es la vista sin filtro de empresa (o `empresa_id IS NULL` se interpreta como "sin asignar", distinto de consolidado).

### 3.2 `categorias` (nueva) — catálogo del Estado de Resultados

El "mini catálogo de cuentas" gerencial. Clasifica tanto ingresos como egresos.

```
id, team_id, nombre, tipo ENUM('ingreso','egreso'),
grupo ENUM('ingreso','costo_venta','gasto_operativo','abajo_ebitda'),
naturaleza ENUM('fijo','variable')  // aplica sobre todo a egresos
activo (bool), orden (int), timestamps
```

- `grupo` es lo que arma el Estado de Resultados (ver §4).
- Seed con el catálogo sugerido en §4.2.

### 3.3 `egresos` (nueva)

Egreso individual capturado (manual o generado por recurrencia).

```
id, team_id, empresa_id (FK, nullable hasta asignar),
categoria_id (FK), egreso_recurrente_id (FK nullable, si vino de una plantilla),
fecha (date), monto (decimal 15,2), descripcion, proveedor (nullable),
metodo_pago ENUM('transferencia','efectivo','tarjeta','otro') nullable,
comprobante_path (nullable, para adjuntar PDF/XML futuro),
origen ENUM('manual','recurrente') default 'manual',
user_id (quién lo registró), timestamps
```

### 3.4 `egresos_recurrentes` (nueva) — plantillas

Define el gasto que se repite (servidores, suscripciones, nómina fija base).

```
id, team_id, empresa_id (FK nullable), categoria_id (FK),
descripcion, proveedor (nullable), monto (decimal 15,2),
frecuencia ENUM('quincenal','mensual','bimestral','trimestral','anual') default 'mensual',
dia_del_mes (int 1-31, cuándo se genera),  // en 'quincenal' se ignora: usa día 15 y último día
ajuste_dia_habil ENUM('ninguno','habil_anterior','habil_siguiente') default 'habil_anterior',
fecha_inicio (date),
// Vigencia — cómo termina la recurrencia (elegir un modo):
vigencia_tipo ENUM('indefinida','hasta_fecha','num_pagos') default 'indefinida',
fecha_fin (date nullable),          // usado si vigencia_tipo='hasta_fecha'
num_pagos (int nullable),           // usado si vigencia_tipo='num_pagos'
pagos_generados (int default 0),    // contador para cortar en num_pagos
activo (bool), proxima_generacion (date), user_id, timestamps
```

- **Vigencia configurable** (lo que pediste): al crear la plantilla eliges por cuánto tiempo aplica —`indefinida`, `hasta una fecha` (ej. "hasta diciembre 2026") o `por N pagos` (ej. 12 mensualidades). Ideal para gastos que sabes que corren solo hasta cierto mes con el mismo monto.
- Un job programado lee las plantillas **activas** con `proxima_generacion <= hoy`, crea el `egreso`, incrementa `pagos_generados`, y avanza `proxima_generacion`. **Se detiene** (marca `activo=false`) cuando `fecha_fin` ya pasó o `pagos_generados >= num_pagos`. Idempotente (no duplica si ya se generó el periodo).

### 3.5 `ingresos_manuales` (nueva) — ingresos no bancarios (efectivo)

Ingresos reales que NO pasan por banco (pagos en efectivo).

```
id, team_id, empresa_id (FK nullable), categoria_id (FK, tipo=ingreso),
fecha (date), monto (decimal 15,2), descripcion, cliente (nullable),
metodo ENUM('efectivo','otro') default 'efectivo',
user_id, timestamps
```

### 3.6 Cambio mínimo en `conciliacions` (existente)

```
ALTER: agregar empresa_id (FK nullable, after group_id)
       agregar categoria_id (FK nullable)  // opcional, default categoría de ingreso por empresa
```

- Nullable para no romper los registros existentes ni el flujo del matcher.
- Se asigna en una pantalla nueva de "Asignación por empresa" o con un botón en el workbench una vez conciliado, **a nivel grupo** (`group_id`) para que asignar un lote sea 1 click.

### 3.7 `empleados` (nueva) — registro de plantilla y fuente de nómina recurrente

Módulo simple. La nómina es un gasto que se mantiene mes a mes (salvo cambio de plantilla), así que el registro de empleados **alimenta el generador de nómina recurrente** en lugar de capturarse a mano cada mes.

```
id, team_id, empresa_id (FK, a qué empresa/centro de costo pertenece),
nombre, puesto (nullable),
fecha_entrada (date), fecha_baja (date nullable),
salario_fiscal (decimal 15,2),     // sueldo timbrado
salario_real (decimal 15,2),       // lo que realmente recibe (complemento = real - fiscal)
clasificacion ENUM('tecnica','administrativa') nullable,  // COGS facturable vs OPEX (opcional)
activo (bool, derivable de fecha_baja),
user_id, timestamps
```

- **Sencillo a propósito:** nombre, fecha de entrada y los dos salarios son lo mínimo; `puesto` y `clasificacion` son opcionales pero ayudan al margen bruto por empresa.
- **Pago quincenal (Lun–Vie):** la nómina se paga **dos veces al mes** (día 15 y último día del mes). Los salarios se capturan como **mensuales**; cada quincena = `salario / 2`.
- **Fecha real de pago (base flujo):** el egreso se fecha en el **día hábil** de pago. Si el día 15 o el fin de mes cae **sábado/domingo, el pago se recorre al viernes anterior** (`ajuste_dia_habil='habil_anterior'`). El gasto NO se carga completo a inicio de mes: cada quincena se registra en su propia fecha de pago, en el periodo correcto. *(Días festivos oficiales: fuera de v1, sólo se ajusta por fin de semana; se puede agregar un calendario de festivos después.)*
- **Generación de nómina:** un command/schedule recorre los empleados activos y, **por cada quincena**, crea los `egresos`: uno por la mitad del `salario_fiscal` (categoría *Nómina fiscal*) y otro por la mitad del **complemento** = `(salario_real - salario_fiscal)/2` (categoría *Nómina complemento/real*), con la fecha hábil de pago, `empresa_id` del empleado y `origen='recurrente'`. Idempotente por quincena (no duplica si ya se generó esa fecha).
- **IMSS / ISN:** en v1 se manejan como plantillas en `egresos_recurrentes` (montos que da tu contador) o como % estimado configurable sobre la masa salarial. No se calculan fiscalmente — esto es control gerencial.
- **Cambios de plantilla:** alta = nuevo empleado; baja = `fecha_baja` (deja de generar a partir de ese periodo); aumento = editar salario (aplica al siguiente periodo).

### 3.8 Diagrama de relaciones (texto)

```
Team (1) ──< Empresa
Team (1) ──< Categoria
Team (1) ──< Empleado
Empresa (1) ──< Conciliacion (empresa_id)      [ingreso bancario conciliado]
Empresa (1) ──< IngresoManual                  [ingreso efectivo]
Empresa (1) ──< Egreso                          [egreso]
Empresa (1) ──< Empleado                        [centro de costo]
EgresoRecurrente (1) ──< Egreso (egreso_recurrente_id)
Empleado (1) ──< Egreso (nómina generada cada periodo)
Categoria (1) ──< {Conciliacion, IngresoManual, Egreso}
```

---

## 4. Reglas de negocio del Estado de Resultados

### 4.1 Fuentes de verdad (base flujo)

El P&L de un periodo se compone de:

**Ingresos =**
1. **Ingresos bancarios conciliados** con `empresa_id` asignado, por fecha del movimiento (cuándo entró el cash). Solo cuenta ingreso respaldado por conciliación (factura ↔ abono). *Los abonos no conciliados NO son ingreso* hasta clasificarse (evita inflar con transferencias internas o préstamos).
2. **Ingresos manuales** (efectivo) del periodo.

**Egresos =**
1. **Egresos manuales** del periodo.
2. **Egresos recurrentes** generados (que ya son registros en `egresos`).

> ⚠️ **Regla anti-doble-conteo (clave).** El banco ya guarda los **cargos** (`movimientos.tipo = 'cargo'`). En v1 los egresos del P&L salen **solo** de la tabla `egresos` (manual + recurrente); **los cargos bancarios NO se suman al P&L**. Esto evita doble conteo, pero exige disciplina: *todo egreso real debe registrarse manualmente o vía recurrencia*. Los cargos del banco quedan como mecanismo de **cruce/control** (ver Fase 7: "conciliación de egresos", simétrica al motor de ingresos).

### 4.2 Estructura sugerida del Estado de Resultados (software factory)

Esta es la mejor práctica para una desarrolladora de software con líneas de producto e instalación. Sirve de seed para `categorias.grupo`:

```
INGRESOS
  + Servicios de desarrollo            (Aplicaciones Creativas)
  + Ingresos recurrentes / suscripción (Tu Checador)
  + Instalaciones y hardware           (Domoticap)
  + Otros ingresos (efectivo)
= INGRESOS TOTALES

COSTO DE VENTA (COGS — lo directamente atribuible a entregar)
  - Infraestructura / servidores / cloud      [recurrente, variable]
  - Licencias y software de terceros de proyecto
  - Subcontratación / freelancers de proyecto
  - Hardware y materiales (Domoticap: cámaras, cableado)
  - (opcional) Nómina técnica directamente facturable
= UTILIDAD BRUTA            → Margen bruto %

GASTOS DE OPERACIÓN (OPEX)
  COSTO LABORAL (México — rollup "Costo total de nómina"):
  - Nómina fiscal (sueldos timbrados)                         [fijo]
  - Nómina complemento / real (pago aparte de lo timbrado)    [fijo]
  - Cuotas IMSS / seguro social (parte patronal)              [fijo]
  - Impuesto sobre nómina (ISN estatal)                       [fijo]
  - Bonos y comisiones                                        [variable]
  OTROS OPEX:
  - Renta y servicios
  - Contabilidad, legal y administrativos
  - Marketing y ventas
  - Herramientas internas (software no atribuible a proyecto)
= EBITDA                    → Margen EBITDA %

  - Depreciación y amortización
  - Gastos financieros / intereses
  - Impuestos (estimado)
= UTILIDAD NETA             → Margen neto %
```

### 4.3 Recomendaciones específicas para software factory

- **Nómina (México) en categorías separadas con rollup.** Se hacen dos pagos distintos —**nómina fiscal** (timbrada) y **nómina complemento/real** (lo que se paga aparte)— más **cuotas IMSS/seguro social** (patronal) e **impuesto sobre nómina (ISN)**. Modelar cada una como categoría propia bajo un grupo "Costo laboral" y mostrar en dashboard el **rollup "Costo total de nómina"** + su composición. Esto da visibilidad del peso de la carga social y del split fiscal vs complemento. IMSS es mensual con componente RCV/INFONAVIT bimestral → la frecuencia `bimestral` de `egresos_recurrentes` lo cubre.
- **Bonos y comisiones como categoría aparte** (variable) — confirmaste que casi no hay nómina variable, pero bonos/comisiones sí. Verlos separados muestra su peso real sobre EBITDA.
- **Nota:** este es un control gerencial, no sustituye el tratamiento fiscal del contador. La distinción fiscal/real se mantiene como clasificación para visibilidad, no como criterio contable formal.
- **Distinguir nómina facturable (COGS) vs no facturable (OPEX)** aunque sea una estimación gruesa por porcentaje. Es lo que permite ver el **margen bruto real por proyecto/línea**, métrica #1 de una software factory.
- **Tu Checador como ingreso recurrente:** medir aparte como MRR/ingreso recurrente da una lectura de salud distinta a los ingresos por proyecto (más predecibles, mejor valuación). Vale una tarjeta propia en el dashboard.
- **Margen por unidad de negocio** (no solo del grupo): cada empresa debe poder verse con su propio margen bruto y EBITDA. Domoticap (hardware) tendrá márgenes muy distintos a Aplicaciones Creativas (servicios).
- **Costos fijos vs variables** (`categorias.naturaleza`): permite calcular punto de equilibrio y apalancamiento operativo — útil para consejo.

---

## 5. Roadmap por fases

Cada fase es entregable, probable y no rompe lo anterior. Orden = dependencias.

### Fase 0 — Fundamentos: dimensión empresa + catálogo de categorías  ✅ CERRADA (2026-06-26)
> Implementada en rama `feature/finanzas-fase0`. SDD: `docs/sdd/00-fundamentos-empresa-categorias.md`. Tablas `empresas` + `categorias`, CRUD en Settings (solo owner del team), seeder idempotente (3 empresas + 21 categorías), 8 tests verdes, 0 regresiones nuevas.
- **Objetivo:** crear la base sobre la que todo lo demás se apoya.
- **Alcance:** migraciones `empresas` y `categorias`; modelos con `TeamOwned`; seeder con las 3 empresas y el catálogo §4.2; CRUD básico de empresas y categorías en Settings (solo admin del team).
- **Criterios de aceptación:** se pueden listar/crear/editar empresas y categorías; aislamiento por team verificado con test de tenancy; seeder idempotente.
- **Riesgo/tenancy:** bajo. Tablas nuevas, sin tocar dominio existente.

### Fase 1 — Asignación de empresa a conciliaciones (post-conciliación)
- **Objetivo:** etiquetar el ingreso bancario ya conciliado con su empresa, sin tocar el matcher.
- **Alcance:** migración `add empresa_id (+ categoria_id) to conciliacions`; UI para asignar empresa a un **grupo conciliado** (1 click por `group_id`) desde el workbench/historial; endpoint `PATCH /reconciliation/group/{groupId}/empresa`; bulk-assign.
- **Criterios:** asignar/reasignar empresa a un grupo; conciliaciones existentes quedan en "sin asignar" sin romperse; ownership validado; test que confirma que el `MatcherService` no cambió (correr `MatcherServiceTest`, `RegressionTest`).
- **Riesgo/tenancy:** medio (toca tabla del dominio). `empresa_id` nullable, aditivo. No tocar lógica de matching.

### Fase 2 — Egresos manuales
- **Objetivo:** capturar gastos uno por uno, clasificados por empresa + categoría.
- **Alcance:** migración `egresos`; modelo + `TeamOwned`; CRUD `/expenses` (index con filtros mes/año/empresa/categoría, create, edit, delete); validación (monto > 0, categoría tipo=egreso, empresa del team); factory + tests.
- **Criterios:** alta/edición/baja de egresos; filtros funcionan; totales por categoría; tenancy test.
- **Riesgo:** bajo. Aislado del motor.

### Fase 3 — Egresos recurrentes (motor)
- **Objetivo:** que servidores/suscripciones/nómina base se generen solos cada mes.
- **Alcance:** migración `egresos_recurrentes`; CRUD de plantillas; **command artisan** `egresos:generar-recurrentes` (idempotente) + registrar el **schedule** (ver nota de infra abajo — hay que montarlo desde cero); marca `origen='recurrente'` y liga `egreso_recurrente_id`.
- **Diseño clave:** el generador es un **command artisan ejecutable a mano**; el scheduler solo lo automatiza. Así funciona aunque no haya cron (Herd local) corriéndolo solo.
- **Criterios:** crear plantilla; el command genera el egreso del periodo una sola vez; re-ejecutar no duplica; scheduler registrado y documentado (prod + local); documentar en `docs/operations.md`.
- **Riesgo:** bajo-medio (job programado). Idempotencia es el punto crítico a testear.

> **Infra de scheduler (catch de revisión — debe montarse en Fase 3).** Hoy `routes/console.php` solo tiene el comando `inspire` de demo y **no existe ningún schedule** en el repo (ni en `bootstrap/app.php`). Antes de automatizar cualquier generador hay que:
> - Registrar los schedules en `routes/console.php` (Laravel 12) — `Schedule::command('egresos:generar-recurrentes')->dailyAt('01:00')`, y la nómina quincenal en su cadencia.
> - **Producción:** agregar la entrada de cron `* * * * * cd /ruta && php artisan schedule:run >> /dev/null 2>&1` (documentar en `README.md`/`docs/operations.md`, junto a los workers de cola).
> - **Local Herd (sin cron):** igual que las colas (CLAUDE.md §3.2), correr `php artisan schedule:work` en una terminal aparte, **o** disparar el generador a mano con `php artisan egresos:generar-recurrentes`. Documentar ambas en `docs/operations.md`.
> Como los generadores son commands idempotentes, ejecutarlos manualmente es seguro y es el fallback local.

### Fase 3B — Módulo de Empleados + nómina recurrente
- **Objetivo:** registrar la plantilla y que la nómina (fiscal + complemento) se genere sola cada mes.
- **Alcance:** migración `empleados`; modelo + `TeamOwned`; CRUD `/employees` (nombre, puesto, fecha entrada/baja, salario fiscal mensual, salario real mensual, empresa, clasificación); **command** `nomina:generar` **quincenal** (día 15 y fin de mes, cada quincena = salario/2) con **ajuste a día hábil anterior** si cae sábado/domingo; **schedule** que crea los egresos de nómina (fiscal + complemento) por empleado activo en la fecha real de pago; IMSS/ISN vía plantillas de `egresos_recurrentes` o % configurable.
- **Criterios:** alta/baja/edición de empleados; el command genera las dos quincenas con fiscal + complemento por empleado, fechadas en el día hábil correcto (incluye caso fin de mes en domingo → viernes); baja deja de generar a partir de su quincena; re-ejecutar no duplica; tenancy test; documentar en `docs/operations.md`.
- **Riesgo:** medio (job + dinero). Idempotencia por quincena, ajuste de día hábil y altas/bajas a mitad de periodo son lo crítico a testear. Depende de Fase 2 (tabla `egresos`) y Fase 0. Reusa la infra de scheduler montada en Fase 3 (ver su nota); `nomina:generar` también es ejecutable a mano.

### Fase 4 — Ingresos manuales (efectivo)
- **Objetivo:** registrar ingresos reales que no pasan por banco.
- **Alcance:** migración `ingresos_manuales`; CRUD `/cash-income`; misma estructura de filtros que egresos; categoría tipo=ingreso; empresa.
- **Criterios:** alta/edición/baja; se integran a la fuente de ingresos del P&L; tenancy test.
- **Riesgo:** bajo.

### Fase 5 — Motor de Estado de Resultados (servicio de cálculo)
- **Objetivo:** el cerebro que arma el P&L de cualquier periodo/empresa.
- **Alcance:** `App\Services\Finance\ProfitLossService` que recibe (rango de fechas, empresa_id|null=consolidado) y devuelve la estructura §4.2 con: ingresos totales, COGS, utilidad bruta, OPEX, EBITDA, utilidad neta, y márgenes. Reusa las 3 fuentes (§4.1). Sin doble conteo de cargos. Cubierto por tests unitarios con datos conocidos.
- **Criterios:** dados datos sembrados, los totales y márgenes cuadran al centavo; consolidado = suma de empresas + sin-asignar; performance OK con índices por fecha/empresa.
- **Riesgo:** medio (lógica financiera). **Test-first** con casos numéricos fijos.

### Fase 6 — Dashboard ejecutivo + export consejo
- **Objetivo:** la vista nivel CEO/consejo.
- **Alcance:** página `/executive` o `/pnl`: selector de periodo (mensual/trimestral/semestral/anual) + selector empresa/consolidado; tarjetas KPI (Ingresos, Utilidad bruta y margen, EBITDA y margen, Utilidad neta); P&L en cascada/waterfall; comparativo periodo vs periodo anterior y YoY; ingreso recurrente (Tu Checador) destacado; margen por unidad de negocio. Export **PDF** (extender patrón de export async existente) presentable a consejo.
- **Criterios:** cambiar periodo/empresa recalcula vía `ProfitLossService`; números cuadran con Fase 5; PDF generado vía cola `exports`; responsive.
- **Riesgo:** medio (UI + export). Reusa `SetGlobalDateFilters`, no lo reemplaza.

### Fase 7 (futuro / opcional) — Conciliación de egresos (cruce con banco)
- **Objetivo:** garantizar completitud de egresos cruzando `egresos` contra `movimientos.tipo='cargo'` (como el motor de ingresos pero al revés). Detecta gastos pagados en banco no registrados, y registrados no pagados.
- Queda fuera de v1; se documenta como evolución natural.

### Fases futuras candidatas (no v1)
- Importar **CFDI de egresos** (XML recibidos) reusando `CfdiParserService`.
- Integración **SAT / Contpaqi / QuickBooks**.
- Migración a **base devengado** (catálogo formal, conciliable con contador).
- Presupuesto vs real (budget) por empresa y categoría.
- Flujo de efectivo proyectado (cash flow forecast).

---

## 6. Dependencias entre fases

```
Fase 0  →  Fase 1
Fase 0  →  Fase 2  →  Fase 3
                  →  Fase 3B (Empleados + nómina)
Fase 0  →  Fase 4
(Fases 1,2,3,3B,4)  →  Fase 5  →  Fase 6
```

Fases 2 y 4 son paralelizables después de la 0. La 1 puede ir en paralelo a 2/4. Fase 3B depende de la 2 (usa la tabla `egresos`).

---

## 7. Cómo ejecutarlo en Claude Code (una fase a la vez)

Sugerencia de prompt de arranque por fase (ejemplo Fase 0):

> "Vamos a implementar la **Fase 0** del PRD `docs/prd/finanzas-egresos-multiempresa.md`. Antes de tocar código sigue el flujo §2 de CLAUDE.md (AUDIT → UNDERSTAND → PLAN → VALIDATE). Crea las migraciones de `empresas` y `categorias` con `team_id`, los modelos con el trait `TeamOwned`, el seeder con las 3 empresas (Aplicaciones Creativas, Tu Checador, Domoticap) y el catálogo de categorías del §4.2 del PRD, y un CRUD básico en Settings. Añade tests de tenancy. Actualiza `docs/domain.md`. No toques el motor de conciliación."

Reglas de oro al pasarlo a Claude Code:
- **Ninguna fase se cierra sin cumplir el Definition of Done (§8) completo (A→D).**
- **Una fase por rama/PR.** No mezclar fases.
- Exigir que corra `MatcherServiceTest`, `RegressionTest` y los tests de tenancy antes de cerrar cualquier fase que toque `conciliacions` (Fase 1).
- Recordar las reglas de §1.4 y §6 de CLAUDE.md (no `withoutGlobalScopes` en controllers, no renombrar `conciliacions`, no romper `SetGlobalDateFilters`).
- Cada fase actualiza la doc correspondiente (`docs/domain.md`, `docs/endpoints.md`, `docs/operations.md`, `docs/business-rules.md`).

---

## 8. Definition of Done (DoD) — cierre de CADA fase

Ninguna fase se considera terminada hasta cumplir **todo** lo siguiente, **en este orden**. Aplica a las Fases 0–6 (las Fases 7+ son futuras). Porque es dinero, esto se hace religiosamente.

### A. Pruebas (antes de commitear)
1. `php artisan test` (en Herd) o `vendor/bin/sail artisan test --compact` — **toda la suite verde**, incluidos los tests nuevos del módulo.
2. Tests de **tenancy** del módulo nuevo. Si la fase toca dominio (Fase 1, `conciliacions`): correr además `MatcherServiceTest`, `RegressionTest` y `SecurityAuditTest` y confirmar cero regresiones.
3. Tests específicos del tipo de módulo:
   - **Lógica financiera (Fase 5):** casos numéricos fijos que cuadran al centavo (ingresos que entran/no entran, sin doble conteo, consolidado = suma de empresas).
   - **Generadores (Fase 3, 3B):** idempotencia por periodo/quincena, ajuste de día hábil, altas/bajas a mitad de periodo, corte por vigencia.
4. **E2E con Playwright** para flujos de UI cuando aplique (CRUD de Fases 0/2/4 y dashboard de Fase 6). La lógica de negocio se cubre con Pest, no con E2E.

### B. Documentación (regla del workspace, §4 de CLAUDE.md)
5. Actualizar los `.md` que correspondan según la tabla §4.2 de CLAUDE.md: `docs/domain.md`, `docs/endpoints.md`, `docs/business-rules.md`, `docs/operations.md`, `docs/flows/*`, `docs/architecture.md`, `docs/security.md`.
6. Actualizar **este PRD/plan** (marcar la fase como cerrada y anotar desviaciones) y el **SDD del módulo** (ver §8.1).
7. Actualizar **archivos de memoria** del agente (`CLAUDE.md` / memoria) con decisiones nuevas o reglas de negocio descubiertas (ej. nueva categoría, regla de día hábil).

### C. Commit y reviews (sobre el commit/PR, en orden)
8. **Commit atómico por módulo**, mensaje claro. Nunca `--no-verify`, `--force` ni saltar hooks (§6 CLAUDE.md).
9. **PR review con "superpowers"** → *"usa superpowers para el PR review"*.
10. **Gitnexus review.**
11. **Revisión de seguridad y escalabilidad** de los cambios, contra checklist: tenancy/global scope intacto, sin `withoutGlobalScopes` en controllers, N+1 y queries indexadas (índices por `fecha`/`empresa_id`), transacciones/`lockForUpdate` donde mueva saldos, validaciones de ownership y montos, rate limits en rutas pesadas, manejo de `failed()` en jobs nuevos.

### D. Gate financiero (go / no-go — porque es dinero)
12. **Cruce manual** con un caso real del periodo: los totales del módulo cuadran y **no hay doble conteo** (cargos banco vs egresos manuales).
13. **No-regresión del motor de conciliación** confirmada.

> Solo cuando A→D están en verde, la fase se marca cerrada en el roadmap (§5) y se arranca la siguiente.

### 8.1 SDD por módulo — recomendación

- **SDD-lite (1 página) para TODOS los módulos**, usando la plantilla en `docs/sdd/_TEMPLATE.md`. Puede salir del paso PLAN/VALIDATE de CLAUDE.md (no es trabajo extra, es capturar lo que ya piensas). Se guarda en `docs/sdd/NN-nombre-modulo.md`.
- **SDD ampliado (con casos borde + plan de pruebas numérico) solo para los 3 módulos sensibles a dinero:**
  - Fase 1 — cambio en `conciliacions` (`empresa_id`).
  - Fases 3 / 3B — generadores de egresos recurrentes y nómina.
  - Fase 5 — motor del Estado de Resultados (`ProfitLossService`).
- Los CRUD (Fases 0, 2, 4) y el dashboard (Fase 6) llevan **solo SDD-lite** — un SDD formal ahí sería burocracia que frena.

---

## 9. Riesgos y mitigaciones

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Doble conteo de egresos (cargo banco + manual) | Utilidad subestimada | En v1 el P&L NO suma cargos; solo `egresos`. Fase 7 cruza para completitud. |
| Abonos no-ingreso (transferencias internas, préstamos) inflan ingresos | Utilidad sobreestimada | Solo cuenta ingreso conciliado y respaldado por factura; abonos sin conciliar no entran. |
| Tocar `conciliacions` rompe el motor | Crítico | `empresa_id` nullable y aditivo; cero cambios al matching; correr suite de regresión. |
| Empresa no asignada → huecos en dashboard | Reportes incompletos | Categoría/empresa "Sin asignar" visible; alertas en dashboard de pendientes por asignar. |
| Recurrentes duplicados | Egresos inflados | Command idempotente con control por periodo + `proxima_generacion`. |
| No existe scheduler en el repo (solo `inspire` demo) y Herd local no corre cron | Recurrentes/nómina no se generan solos | Fase 3 monta el scheduler desde cero (`routes/console.php`); generadores son commands idempotentes ejecutables a mano; documentar cron prod + `schedule:work` local. |
| Clasificación nómina facturable vs no | Margen bruto impreciso | Empezar con % estimado configurable; refinar después. |

---

## 10. Métricas de éxito

- Tiempo de cierre mensual del Estado de Resultados < 1 día (vs proceso manual actual).
- 100% de ingresos bancarios conciliados con empresa asignada.
- P&L consolidado y por empresa generable en < 5 segundos.
- EBITDA y margen bruto por unidad de negocio visibles para consejo cada mes.
- Cero regresiones en el motor de conciliación.
