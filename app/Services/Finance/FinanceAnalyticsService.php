<?php

namespace App\Services\Finance;

use App\Models\Conciliacion;
use App\Models\Egreso;
use App\Models\Empresa;
use App\Models\IngresoManual;
use Carbon\Carbon;

/**
 * Analítica temporal del dashboard ejecutivo v2 (Dashboard v2, BLOQUE 1).
 *
 * POPO sin estado. Construye series mensuales y desgloses del periodo sobre las
 * mismas fuentes/definiciones del P&L, reusando `ProfitLossService`/`PeriodResolver`
 * para garantizar identidad con el motor (la suma de la serie == `forPeriod` del rango).
 *
 * Queue-safety: TODOS los métodos reciben `teamId` explícito y filtran
 * `where('<tabla>.team_id', $teamId)` directamente, sin depender del global scope
 * ambiente de `TeamOwned` (que se apaga sin `Auth::check()`, p.ej. en un job PDF).
 */
class FinanceAnalyticsService
{
    public function __construct(
        private ProfitLossService $profitLoss,
        private PeriodResolver $periods,
    ) {}

    /**
     * Serie de los últimos `$months` meses terminando en (año, mes) ancla, en orden
     * cronológico ascendente. Cada mes reusa `ProfitLossService::forPeriod` (identidad
     * con el motor garantizada).
     *
     * @return list<array{
     *     year: int, month: int, label: string,
     *     ingresos_total: float, ingresos_bancario: float, ingresos_manual: float,
     *     egresos_total: float, costo_venta: float, gasto_operativo: float,
     *     abajo_ebitda: float, sin_clasificar: float,
     *     utilidad_bruta: float, ebitda: float, utilidad_neta: float,
     *     margen_bruto: float, margen_ebitda: float, margen_neto: float
     * }>
     */
    public function monthlySeries(int $anchorYear, int $anchorMonth, int $months, ?int $empresaId, int $teamId): array
    {
        $anchor = Carbon::create($anchorYear, $anchorMonth, 1)->startOfMonth();
        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $cursor = $anchor->copy()->subMonthsNoOverflow($i);
            $y = $cursor->year;
            $m = $cursor->month;

            ['desde' => $desde, 'hasta' => $hasta] = $this->periods->resolve('mensual', $y, $m);
            $pl = $this->profitLoss->forPeriod($desde, $hasta, $empresaId, $teamId);

            $series[] = [
                'year' => $y,
                'month' => $m,
                'label' => $this->label($y, $m),
                'ingresos_total' => $pl['ingresos']['total'],
                'ingresos_bancario' => $pl['ingresos']['bancario_conciliado'],
                'ingresos_manual' => $pl['ingresos']['manual'],
                'egresos_total' => $pl['egresos_total'],
                'costo_venta' => $pl['costo_venta'],
                'gasto_operativo' => $pl['gasto_operativo'],
                'abajo_ebitda' => $pl['abajo_ebitda'],
                'sin_clasificar' => $pl['sin_clasificar'],
                'utilidad_bruta' => $pl['utilidad_bruta'],
                'ebitda' => $pl['ebitda'],
                'utilidad_neta' => $pl['utilidad_neta'],
                'margen_bruto' => $pl['margen_bruto'],
                'margen_ebitda' => $pl['margen_ebitda'],
                'margen_neto' => $pl['margen_neto'],
            ];
        }

        return $series;
    }

    /**
     * Ingreso mensual desglosado por empresa (para la serie apilada), con bucket
     * "sin asignar" para las filas con `empresa_id` null. NO itera `forPeriod` N×M:
     * usa 2 queries agrupadas que espejan la definición de ingreso del P&L
     * (bancario conciliado + manual) sobre toda la ventana.
     *
     * @return list<array{
     *     year: int, month: int, label: string,
     *     empresas: list<array{empresa_id: int, nombre: string|null, color: string|null, total: float}>,
     *     sin_asignar: float
     * }>
     */
    public function ingresoPorEmpresaMensual(int $anchorYear, int $anchorMonth, int $months, int $teamId): array
    {
        $anchor = Carbon::create($anchorYear, $anchorMonth, 1)->startOfMonth();
        $inicio = $anchor->copy()->subMonthsNoOverflow($months - 1)->startOfMonth()->toDateString();
        $fin = $anchor->copy()->endOfMonth()->toDateString();

        // Bancario conciliado, agrupado por año/mes(de movimientos.fecha) + empresa.
        $bancario = Conciliacion::query()
            ->join('movimientos', 'conciliacions.movimiento_id', '=', 'movimientos.id')
            ->where('conciliacions.team_id', $teamId)
            ->where('conciliacions.estatus', 'conciliado')
            ->where('movimientos.tipo', 'abono')
            ->whereBetween('movimientos.fecha', [$inicio, $fin])
            ->groupBy('y', 'm', 'conciliacions.empresa_id')
            ->selectRaw('YEAR(movimientos.fecha) as y, MONTH(movimientos.fecha) as m, conciliacions.empresa_id as empresa_id, SUM(conciliacions.monto_aplicado) as total')
            ->get();

        // Manual (efectivo), agrupado por año/mes(de fecha) + empresa.
        $manual = IngresoManual::query()
            ->where('ingresos_manuales.team_id', $teamId)
            ->whereBetween('fecha', [$inicio, $fin])
            ->groupBy('y', 'm', 'empresa_id')
            ->selectRaw('YEAR(fecha) as y, MONTH(fecha) as m, empresa_id as empresa_id, SUM(monto) as total')
            ->get();

        // Acumula por "año-mes" → empresa_id (null usa la clave "" de PHP) → total.
        $acc = [];
        foreach ([$bancario, $manual] as $collection) {
            foreach ($collection as $row) {
                $key = ((int) $row->y).'-'.((int) $row->m);
                $eid = $row->empresa_id; // int|null
                $acc[$key][$eid] = ($acc[$key][$eid] ?? 0.0) + (float) $row->total;
            }
        }

        // Catálogo de empresas del team (queue-safe: sin scope ambiente).
        $empresas = Empresa::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->keyBy('id');

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $cursor = $anchor->copy()->subMonthsNoOverflow($i);
            $y = $cursor->year;
            $m = $cursor->month;
            $porEmpresa = $acc["{$y}-{$m}"] ?? [];

            $lista = [];
            $sinAsignar = 0.0;
            foreach ($porEmpresa as $eid => $total) {
                // La clave null de PHP se coacciona a "" → bucket "sin asignar".
                if ($eid === null || $eid === '') {
                    $sinAsignar += (float) $total;

                    continue;
                }

                $empresa = $empresas->get((int) $eid);
                $lista[] = [
                    'empresa_id' => (int) $eid,
                    'nombre' => $empresa?->nombre,
                    'color' => $empresa?->color,
                    'total' => $this->money((float) $total),
                ];
            }

            usort($lista, fn ($a, $b) => $a['empresa_id'] <=> $b['empresa_id']);

            $result[] = [
                'year' => $y,
                'month' => $m,
                'label' => $this->label($y, $m),
                'empresas' => $lista,
                'sin_asignar' => $this->money($sinAsignar),
            ];
        }

        return $result;
    }

    /**
     * Egresos del periodo agrupados por categoría (nombre + grupo), desc por total.
     * Categoría null → nombre 'Sin categoría', grupo null.
     *
     * @return list<array{nombre: string, grupo: string|null, total: float}>
     */
    public function egresosPorCategoria(Carbon $desde, Carbon $hasta, ?int $empresaId, int $teamId): array
    {
        return Egreso::query()
            ->leftJoin('categorias', 'egresos.categoria_id', '=', 'categorias.id')
            ->where('egresos.team_id', $teamId)
            ->whereBetween('egresos.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when($empresaId !== null, fn ($q) => $q->where('egresos.empresa_id', $empresaId))
            ->groupBy('egresos.categoria_id', 'categorias.nombre', 'categorias.grupo')
            ->selectRaw('categorias.nombre as nombre, categorias.grupo as grupo, SUM(egresos.monto) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'nombre' => $row->nombre ?? 'Sin categoría',
                'grupo' => $row->grupo,
                'total' => $this->money((float) $row->total),
            ])
            ->all();
    }

    /**
     * Egresos del periodo por naturaleza. Naturaleza null → 'sin_clasificar'.
     *
     * @return array{fijo: float, variable: float, sin_clasificar: float}
     */
    public function egresosPorNaturaleza(Carbon $desde, Carbon $hasta, ?int $empresaId, int $teamId): array
    {
        $rows = Egreso::query()
            ->leftJoin('categorias', 'egresos.categoria_id', '=', 'categorias.id')
            ->where('egresos.team_id', $teamId)
            ->whereBetween('egresos.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when($empresaId !== null, fn ($q) => $q->where('egresos.empresa_id', $empresaId))
            ->groupBy('categorias.naturaleza')
            ->selectRaw('categorias.naturaleza as naturaleza, SUM(egresos.monto) as total')
            ->get();

        $fijo = 0.0;
        $variable = 0.0;
        $sinClasificar = 0.0;
        foreach ($rows as $row) {
            $total = (float) $row->total;
            if ($row->naturaleza === 'fijo') {
                $fijo += $total;
            } elseif ($row->naturaleza === 'variable') {
                $variable += $total;
            } else {
                $sinClasificar += $total;
            }
        }

        return [
            'fijo' => $this->money($fijo),
            'variable' => $this->money($variable),
            'sin_clasificar' => $this->money($sinClasificar),
        ];
    }

    /**
     * Top proveedores por gasto en el periodo, desc, limitado. Decisión: se EXCLUYEN
     * los egresos con proveedor null o vacío para no ensuciar el ranking.
     *
     * @return list<array{proveedor: string, total: float}>
     */
    public function topProveedores(Carbon $desde, Carbon $hasta, ?int $empresaId, int $teamId, int $limit = 10): array
    {
        return Egreso::query()
            ->where('egresos.team_id', $teamId)
            ->whereBetween('egresos.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when($empresaId !== null, fn ($q) => $q->where('egresos.empresa_id', $empresaId))
            ->whereNotNull('proveedor')
            ->where('proveedor', '!=', '')
            ->groupBy('proveedor')
            ->selectRaw('proveedor as proveedor, SUM(monto) as total')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'proveedor' => $row->proveedor,
                'total' => $this->money((float) $row->total),
            ])
            ->all();
    }

    /**
     * Rollup de nómina del periodo (egresos con `empleado_id`), por `concepto_nomina`.
     *
     * @return array{fiscal: float, complemento: float, total: float}
     */
    public function nominaRollup(Carbon $desde, Carbon $hasta, ?int $empresaId, int $teamId): array
    {
        $rows = Egreso::query()
            ->where('egresos.team_id', $teamId)
            ->whereBetween('egresos.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->when($empresaId !== null, fn ($q) => $q->where('egresos.empresa_id', $empresaId))
            ->whereNotNull('empleado_id')
            ->groupBy('concepto_nomina')
            ->selectRaw('concepto_nomina as concepto_nomina, SUM(monto) as total')
            ->get();

        $fiscal = 0.0;
        $complemento = 0.0;
        foreach ($rows as $row) {
            $total = (float) $row->total;
            if ($row->concepto_nomina === 'fiscal') {
                $fiscal += $total;
            } elseif ($row->concepto_nomina === 'complemento') {
                $complemento += $total;
            }
        }

        return [
            'fiscal' => $this->money($fiscal),
            'complemento' => $this->money($complemento),
            'total' => $this->money($fiscal + $complemento),
        ];
    }

    /**
     * Etiqueta de mes "YYYY-MM".
     */
    private function label(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * Redondea un monto al centavo y normaliza -0.0 → 0.0 (misma regla que ProfitLossService).
     */
    private function money(float $value): float
    {
        $r = round($value, 2);

        return $r == 0.0 ? 0.0 : $r;
    }
}
