<?php

namespace App\Services\Finance;

use Carbon\Carbon;

/**
 * Resuelve rangos `[desde, hasta]` (Carbon) para una granularidad y un ancla (Finanzas Fase 6).
 *
 * POPO sin estado. Centraliza la lógica de periodos del dashboard ejecutivo:
 *  - `resolve()`   → rango del periodo que contiene el ancla (año/mes).
 *  - `previous()`  → periodo inmediatamente anterior de la misma granularidad.
 *  - `yearOverYear()` → mismo rango un año antes.
 *
 * Granularidades soportadas: `mensual`, `trimestral`, `semestral`, `anual`.
 * Las fechas se devuelven a `startOfDay`/`endOfDay`; el consumidor (`ProfitLossService`)
 * usa `toDateString()`, así que solo importa el día correcto.
 */
class PeriodResolver
{
    /**
     * Rango del periodo que contiene el ancla (año/mes), según la granularidad.
     *
     * @return array{desde: \Carbon\Carbon, hasta: \Carbon\Carbon}
     */
    public function resolve(string $granularidad, int $year, int $month): array
    {
        $ancla = Carbon::create($year, $month, 1)->startOfMonth();

        return match ($granularidad) {
            'trimestral' => [
                'desde' => $ancla->copy()->startOfQuarter()->startOfDay(),
                'hasta' => $ancla->copy()->endOfQuarter()->endOfDay(),
            ],
            'semestral' => $this->semester($year, $month),
            'anual' => [
                'desde' => $ancla->copy()->startOfYear()->startOfDay(),
                'hasta' => $ancla->copy()->endOfYear()->endOfDay(),
            ],
            // 'mensual' y default.
            default => [
                'desde' => $ancla->copy()->startOfMonth()->startOfDay(),
                'hasta' => $ancla->copy()->endOfMonth()->endOfDay(),
            ],
        };
    }

    /**
     * Periodo inmediatamente anterior de la misma granularidad (maneja cruce de año).
     *
     * @return array{desde: \Carbon\Carbon, hasta: \Carbon\Carbon}
     */
    public function previous(string $granularidad, Carbon $desde): array
    {
        $ancla = $desde->copy();

        return match ($granularidad) {
            'trimestral' => $this->resolve('trimestral', ...$this->anchorOf($ancla->copy()->subMonthsNoOverflow(3))),
            'semestral' => $this->resolve('semestral', ...$this->anchorOf($ancla->copy()->subMonthsNoOverflow(6))),
            'anual' => $this->resolve('anual', ...$this->anchorOf($ancla->copy()->subYearNoOverflow())),
            // 'mensual' y default.
            default => $this->resolve('mensual', ...$this->anchorOf($ancla->copy()->subMonthNoOverflow())),
        };
    }

    /**
     * Mismo rango un año antes (comparativo year-over-year).
     *
     * @param  array{desde: \Carbon\Carbon, hasta: \Carbon\Carbon}  $rango
     * @return array{desde: \Carbon\Carbon, hasta: \Carbon\Carbon}
     */
    public function yearOverYear(array $rango): array
    {
        return [
            'desde' => $rango['desde']->copy()->subYearNoOverflow(),
            'hasta' => $rango['hasta']->copy()->subYearNoOverflow(),
        ];
    }

    /**
     * Semestre que contiene el mes: ene–jun (month <= 6) o jul–dic (month >= 7).
     *
     * @return array{desde: \Carbon\Carbon, hasta: \Carbon\Carbon}
     */
    private function semester(int $year, int $month): array
    {
        if ($month <= 6) {
            return [
                'desde' => Carbon::create($year, 1, 1)->startOfDay(),
                'hasta' => Carbon::create($year, 6, 30)->endOfDay(),
            ];
        }

        return [
            'desde' => Carbon::create($year, 7, 1)->startOfDay(),
            'hasta' => Carbon::create($year, 12, 31)->endOfDay(),
        ];
    }

    /**
     * Año y mes de una fecha, para realimentar `resolve()`.
     *
     * @return array{0: int, 1: int}
     */
    private function anchorOf(Carbon $fecha): array
    {
        return [$fecha->year, $fecha->month];
    }
}
