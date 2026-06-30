<?php

namespace App\Services\Finance;

use App\Models\Conciliacion;
use App\Models\Egreso;
use App\Models\IngresoManual;
use Carbon\Carbon;

/**
 * Estado de Resultados (P&L) de un periodo/empresa (Finanzas Fase 5).
 *
 * POPO sin estado. Combina las 3 fuentes de dinero ya materializadas:
 *  - Ingreso bancario conciliado: SUM(conciliacions.monto_aplicado), fechado por
 *    `movimientos.fecha` (base flujo) vía join por `movimiento_id`.
 *  - Ingreso manual (efectivo): SUM(ingresos_manuales.monto) por `fecha`.
 *  - Egresos (manuales/recurrentes/nómina): SUM(egresos.monto) por `fecha`,
 *    agrupados por `categorias.grupo` (costo_venta / gasto_operativo / abajo_ebitda).
 *
 * Anti-doble-conteo: NUNCA suma `movimientos.monto`, `movimientos.tipo='cargo'`
 * ni `facturas.monto`. El ingreso bancario es exclusivamente `monto_aplicado`.
 *
 * Todas las fuentes usan `TeamOwned`: el scope por team aplica en contexto
 * request/actingAs. `empresaId = null` → consolidado (incluye filas sin empresa).
 *
 * Identidad garantizada: `utilidad_neta = ingresos.total − egresos_total`.
 */
class ProfitLossService
{
    /**
     * @return array{
     *     desde: string,
     *     hasta: string,
     *     empresa_id: int|null,
     *     ingresos: array{total: float, bancario_conciliado: float, manual: float},
     *     costo_venta: float,
     *     utilidad_bruta: float,
     *     margen_bruto: float,
     *     gasto_operativo: float,
     *     ebitda: float,
     *     margen_ebitda: float,
     *     abajo_ebitda: float,
     *     sin_clasificar: float,
     *     utilidad_neta: float,
     *     margen_neto: float,
     *     egresos_total: float
     * }
     */
    public function forPeriod(Carbon $desde, Carbon $hasta, ?int $empresaId = null): array
    {
        $d = $desde->toDateString();
        $h = $hasta->toDateString();

        // Ingreso bancario conciliado (fechado por movimientos.fecha).
        $bancario = (float) Conciliacion::query()
            ->join('movimientos', 'conciliacions.movimiento_id', '=', 'movimientos.id')
            ->whereBetween('movimientos.fecha', [$d, $h])
            ->when($empresaId !== null, fn ($q) => $q->where('conciliacions.empresa_id', $empresaId))
            ->sum('conciliacions.monto_aplicado');

        // Ingreso manual (efectivo).
        $manual = (float) IngresoManual::query()
            ->whereBetween('fecha', [$d, $h])
            ->when($empresaId !== null, fn ($q) => $q->where('empresa_id', $empresaId))
            ->sum('monto');

        $ingresosTotal = $bancario + $manual;

        // Egresos: total y desglose por grupo (un query cada uno).
        $egresosTotal = (float) Egreso::query()
            ->whereBetween('fecha', [$d, $h])
            ->when($empresaId !== null, fn ($q) => $q->where('empresa_id', $empresaId))
            ->sum('monto');

        $porGrupo = Egreso::query()
            ->leftJoin('categorias', 'egresos.categoria_id', '=', 'categorias.id')
            ->whereBetween('egresos.fecha', [$d, $h])
            ->when($empresaId !== null, fn ($q) => $q->where('egresos.empresa_id', $empresaId))
            ->groupBy('categorias.grupo')
            ->selectRaw('categorias.grupo as grupo, SUM(egresos.monto) as total')
            ->pluck('total', 'grupo');

        $costoVenta = (float) ($porGrupo['costo_venta'] ?? 0);
        $gastoOperativo = (float) ($porGrupo['gasto_operativo'] ?? 0);
        $abajoEbitda = (float) ($porGrupo['abajo_ebitda'] ?? 0);
        // Absorbe egresos sin categoría o con grupo inesperado → el P&L cuadra exacto.
        $sinClasificar = $egresosTotal - $costoVenta - $gastoOperativo - $abajoEbitda;

        $utilidadBruta = $ingresosTotal - $costoVenta;
        $ebitda = $utilidadBruta - $gastoOperativo;
        $utilidadNeta = $ebitda - $abajoEbitda - $sinClasificar;

        return [
            'desde' => $d,
            'hasta' => $h,
            'empresa_id' => $empresaId,
            'ingresos' => [
                'total' => $this->money($ingresosTotal),
                'bancario_conciliado' => $this->money($bancario),
                'manual' => $this->money($manual),
            ],
            'costo_venta' => $this->money($costoVenta),
            'utilidad_bruta' => $this->money($utilidadBruta),
            'margen_bruto' => $this->margin($utilidadBruta, $ingresosTotal),
            'gasto_operativo' => $this->money($gastoOperativo),
            'ebitda' => $this->money($ebitda),
            'margen_ebitda' => $this->margin($ebitda, $ingresosTotal),
            'abajo_ebitda' => $this->money($abajoEbitda),
            'sin_clasificar' => $this->money($sinClasificar),
            'utilidad_neta' => $this->money($utilidadNeta),
            'margen_neto' => $this->margin($utilidadNeta, $ingresosTotal),
            'egresos_total' => $this->money($egresosTotal),
        ];
    }

    /**
     * Redondea un monto al centavo en el borde de salida.
     */
    private function money(float $value): float
    {
        return (float) round($value, 2);
    }

    /**
     * Margen como ratio (round 4), con guardia de división por cero.
     */
    private function margin(float $renglon, float $ingresosTotal): float
    {
        if ($ingresosTotal == 0.0) {
            return 0.0;
        }

        return round($renglon / $ingresosTotal, 4);
    }
}
