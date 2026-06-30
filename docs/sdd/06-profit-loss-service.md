# SDD — Estado de Resultados / ProfitLossService (Fase 5)

> SDD **ampliado** (módulo sensible a dinero, PRD §8.1). Módulo: Finanzas / P&L · Fase: 5 · Autor: Juan + Claude · Fecha: 2026-06-30 · Estado: implementado

## 1. Objetivo
El **cerebro** que arma el Estado de Resultados (P&L) gerencial, base flujo, de cualquier periodo y empresa (o consolidado). Recibe un rango de fechas + `empresa_id|null` y devuelve ingresos, COGS, utilidad bruta, OPEX, EBITDA, abajo-EBITDA, utilidad neta y los 3 márgenes, **al centavo y sin doble conteo**. Liga al PRD `docs/prd/finanzas-egresos-multiempresa.md`, Fase 5. Lo crítico: la **lógica financiera** (qué entra, cómo se suma, que la identidad cuadre). Aislado del motor de conciliación.

## 2. Alcance
- **Incluye:** servicio `App\Services\Finance\ProfitLossService` (POPO sin estado, sin migración) con `forPeriod(Carbon $desde, Carbon $hasta, ?int $empresaId = null): array`; combina las 3 fuentes ya materializadas (Fases 1/2/3/3B/4); tests numéricos `tests/Feature/ProfitLossServiceTest.php` (7 casos al centavo).
- **NO incluye (no-goals):** UI, endpoint, comando artisan (Fase 6 consume este servicio; un comando sin Auth no tendría contexto de team). Desglose ingreso-por-categoría y por-empresa, KPIs, waterfall, comparativos periodo/YoY (Fase 6). `conciliacions.categoria_id` (se sigue difiriendo). Conciliación de egresos / cruce con cargos banco (Fase 7).

## 3. Modelo de datos (delta)
**Ninguno.** Fase 5 no crea ni modifica tablas: **no hay migración**. Lee las tablas existentes: `conciliacions` (`monto_aplicado`, `empresa_id`, join a `movimientos` por `movimiento_id`), `movimientos` (`fecha`), `ingresos_manuales` (`monto`, `fecha`, `empresa_id`), `egresos` (`monto`, `fecha`, `empresa_id`, `categoria_id`) y `categorias` (`grupo`). El scope por team lo aplica `TeamOwned` en contexto request/`actingAs`.

## 4. Las 3 fuentes y cómo se suman

| Fuente | Tabla | Monto | Fecha (filtro periodo) | empresa | Renglón P&L |
|---|---|---|---|---|---|
| Ingreso bancario conciliado | `conciliacions` | `SUM(monto_aplicado)` (sin dedup; ya particiona el dinero) | **`movimientos.fecha`** (join por `movimiento_id`) | `conciliacions.empresa_id` | `ingreso` (línea única "bancario conciliado", sin categoría) |
| Ingreso manual (efectivo) | `ingresos_manuales` | `SUM(monto)` | `fecha` | `empresa_id` | `ingreso` ("manual") |
| Egresos (manual/recurrente/nómina) | `egresos` | `SUM(monto)` | `fecha` | `empresa_id` | por `categorias.grupo`: `costo_venta`/`gasto_operativo`/`abajo_ebitda` (+ `sin_clasificar`) |

- **Rango inclusivo** en la columna `date` vía `whereBetween('fecha', [$d, $h])`; para conciliaciones `whereBetween('movimientos.fecha', [$d, $h])`. `$d`/`$h` se derivan con `->toDateString()`.
- `$empresaId === null` → **consolidado** (incluye filas con `empresa_id` NULL = "sin asignar"). `$empresaId` dado → solo esa empresa (`where empresa_id = $empresaId`), aplicado vía `->when($empresaId !== null, ...)`.
- Egresos por grupo en **un solo query**: `leftJoin('categorias')` + `groupBy('categorias.grupo')` + `selectRaw('categorias.grupo as grupo, SUM(egresos.monto) as total')` + `pluck('total','grupo')`. `costo_venta`/`gasto_operativo`/`abajo_ebitda` salen del map con default 0.

### 4.1 Regla anti-doble-conteo (clave)
El banco ya guarda los **cargos** (`movimientos.tipo='cargo'`). El P&L **NUNCA** suma `movimientos.tipo='cargo'`, `movimientos.monto` ni `facturas.monto`. Los egresos salen **solo** de la tabla `egresos`; el ingreso bancario es **exclusivamente** `conciliacions.monto_aplicado`. Por eso `monto`/`factura.monto` de las conciliaciones (sembrados absurdos en el test) no inflan nada.

## 5. Fórmula (identidad garantizada)
```
ingresos.bancario_conciliado = SUM(conciliacions.monto_aplicado) fechado por movimientos.fecha
ingresos.manual             = SUM(ingresos_manuales.monto)
ingresos.total              = bancario_conciliado + manual

egresos_total               = SUM(egresos.monto)
costo_venta / gasto_operativo / abajo_ebitda = SUM por categorias.grupo (default 0)
sin_clasificar              = egresos_total − costo_venta − gasto_operativo − abajo_ebitda

utilidad_bruta = ingresos.total − costo_venta
ebitda         = utilidad_bruta − gasto_operativo
utilidad_neta  = ebitda − abajo_ebitda − sin_clasificar
```
- **`sin_clasificar`** absorbe egresos con `categoria_id` NULL o grupo inesperado → garantiza que el P&L cuadra exacto y se reporta explícito para recategorizar.
- **Identidad maestra (siempre se cumple):** `utilidad_neta = ingresos.total − egresos_total` (porque `sin_clasificar` cierra el residuo de los grupos).
- **Montos** redondeados al centavo en el borde de salida: `(float) round($value, 2)`.
- **Márgenes** = ratio float `round(renglón / ingresos.total, 4)`, con **guardia de división por cero** (`ingresos.total == 0` → `0.0`): `margen_bruto`, `margen_ebitda`, `margen_neto`.

## 6. Decisiones (confirmadas con el usuario)
1. **Ingreso bancario fechado por `movimientos.fecha`** (base flujo, "cuándo entró el cash"; PRD §4.1), vía join `conciliacions → movimientos`, **no** por `fecha_conciliacion`.
2. **No se agrega `conciliacions.categoria_id`** (se sigue difiriendo): para el P&L todo el ingreso conciliado es grupo `ingreso` (línea única "bancario conciliado"). El desglose de ingresos por categoría es detalle de Fase 6.
3. **El consolidado incluye el dinero SIN empresa** (`empresa_id` NULL, bucket "sin asignar"): ingreso conciliado, ingreso manual y egresos sin empresa, simétrico entre ingresos y egresos.

## 7. Forma de retorno
La definida en el PHPDoc `@return array{...}` del servicio:
```
desde:string, hasta:string, empresa_id:int|null,
ingresos: { total:float, bancario_conciliado:float, manual:float },
costo_venta:float, utilidad_bruta:float, margen_bruto:float,
gasto_operativo:float, ebitda:float, margen_ebitda:float,
abajo_ebitda:float, sin_clasificar:float,
utilidad_neta:float, margen_neto:float, egresos_total:float
```

## 8. Plan de pruebas
`tests/Feature/ProfitLossServiceTest.php` (Pest, `RefreshDatabase` + `actingAs` para el scope `TeamOwned`; helpers `plConciliacion`/`plEgreso`/`plIngresoManual` siembran montos fijos y fechas conocidas en 2026-06). Como **no existe `ConciliacionFactory`**, las conciliaciones se crean con `Conciliacion::create([...])` enlazando `Movimiento::factory()` (fecha del periodo) + `Factura::factory()` con `monto`/`factura.monto` **absurdos** a propósito. 7 casos:
1. **Maestro consolidado (al centavo):** 2 empresas + grupos; bancario `SUM(monto_aplicado)` sin dedup + una fila fuera del periodo; manual dentro/fuera; egresos en cada grupo dentro/fuera. Verifica `ingresos.total/bancario/manual`, `costo_venta/gasto_operativo/abajo_ebitda/sin_clasificar/egresos_total`, `utilidad_bruta/ebitda/utilidad_neta`, los 3 márgenes (ratio) y la identidad maestra.
2. **Por empresa + consolidado = A + B + sin-asignar:** `forPeriod(d,h,empresaA)` solo suma A; consolidado = A + B + NULL.
3. **Anti-doble-conteo:** usa `monto_aplicado` (no `99999`/`88888`); un `movimientos.tipo='cargo'` suelto en el periodo NO afecta egresos/utilidad.
4. **Bordes de fecha:** `desde`/`hasta` inclusivos; un día antes/después no cuenta.
5. **`sin_clasificar`:** egreso con `categoria_id=null` cae ahí (no COGS/OPEX) y se mantiene la identidad.
6. **Periodo vacío:** todo 0 y márgenes 0 (sin división por cero).
7. **Tenancy:** datos de otro team NO entran (vía `actingAs` + `TeamOwned`).

## 9. Impacto en lo existente
- **Cero.** Servicio nuevo de solo-lectura; sin migración, sin rutas, sin colas, sin contratos Inertia. **No toca** el motor de conciliación (`MatcherService` intacto). No usa `withoutGlobalScopes`.

## 10. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Doble conteo (cargo banco + egreso manual) | Utilidad subestimada/inflada | El P&L NO suma cargos; egresos solo de `egresos`; ingreso solo `monto_aplicado` (test caso 3) |
| Abonos no-ingreso (transferencias internas) inflan ingresos | Utilidad sobreestimada | Solo ingreso conciliado y respaldado por factura entra; abonos sin conciliar no |
| Egreso sin categoría desaparece del P&L | P&L no cuadra | `sin_clasificar` absorbe el residuo; identidad `utilidad_neta = ingresos − egresos` se mantiene (test caso 5) |
| División por cero en márgenes (periodo vacío) | Error/NaN | Guardia `ingresos.total == 0 → 0.0` (test caso 6) |
| Fuga cross-team | Datos de otro team en el P&L | `TeamOwned` + `actingAs` (test caso 7) |
| Fechar ingreso por `fecha_conciliacion` | Cash en periodo equivocado | Join a `movimientos.fecha` (decisión 1, test caso 4) |

## 11. Definition of Done
DoD §8 del PRD: **A** pruebas ✓ (7 casos numéricos al centavo: consolidado, por-empresa, anti-doble-conteo, bordes, sin_clasificar, vacío, tenancy; 0 regresiones nuevas) · **B** docs ✓ (`business-rules.md` §13, `domain.md`, este SDD, PRD Fase 5) · **C** commit atómico en `feature/finanzas-fase5` · **D** gate financiero ✓ (consolidado = empresas + sin-asignar; `utilidad_neta = ingresos − egresos`; ningún cargo bancario sumado; motor de conciliación intacto).
