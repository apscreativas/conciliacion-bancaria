<?php

namespace App\Services\Finance;

use Carbon\Carbon;

/**
 * Cálculo de fechas para egresos recurrentes (Finanzas Fase 3).
 * Frecuencias mensuales (1 fecha por periodo vía día del mes). 'quincenal' (2 fechas/mes)
 * es de nómina y se maneja en Fase 3B.
 */
class RecurrenceCalculator
{
    private const MONTHS = [
        'mensual' => 1,
        'bimestral' => 2,
        'trimestral' => 3,
        'anual' => 12,
    ];

    /**
     * Primera fecha programada: día `dia` del mes de `fecha_inicio` (clamp a fin de mes).
     * Puede ser ANTERIOR a `fecha_inicio` — decisión de negocio: el mes de inicio siempre
     * genera su egreso, aunque la plantilla se capture después del día de pago.
     */
    public function firstOccurrence(Carbon $inicio, int $dia): Carbon
    {
        return $this->withDay($inicio, $dia);
    }

    /**
     * Primera fecha programada (en `dia` del mes) que cae en o después de `anchor`.
     * Usada al reactivar una plantilla con historial: reanuda sin egresos retroactivos.
     */
    public function onOrAfter(Carbon $anchor, int $dia, string $frecuencia): Carbon
    {
        $candidate = $this->withDay($anchor, $dia);

        if ($candidate->lt($anchor->copy()->startOfDay())) {
            $candidate = $this->nextDate($candidate, $frecuencia, $dia);
        }

        return $candidate;
    }

    /**
     * Avanza una unidad de la frecuencia y re-fija el día nominal (clamp al último día del mes).
     * Re-aplicar `dia` recupera el día nominal tras un mes corto (ej. feb 28 → mar 31).
     */
    public function nextDate(Carbon $actual, string $frecuencia, int $dia): Carbon
    {
        $months = self::MONTHS[$frecuencia] ?? 1;
        $advanced = $actual->copy()->addMonthsNoOverflow($months);

        return $this->withDay($advanced, $dia);
    }

    /**
     * Ajusta fines de semana a día hábil (sin festivos oficiales en v1).
     */
    public function applyDiaHabil(Carbon $fecha, string $ajuste): Carbon
    {
        $d = $fecha->copy();

        if ($ajuste === 'habil_anterior') {
            while ($d->isWeekend()) {
                $d->subDay();
            }
        } elseif ($ajuste === 'habil_siguiente') {
            while ($d->isWeekend()) {
                $d->addDay();
            }
        }

        return $d;
    }

    /**
     * Fija el día del mes recortando al último día si el mes es más corto (31 → feb 28/29).
     */
    private function withDay(Carbon $date, int $dia): Carbon
    {
        $d = $date->copy()->startOfDay();

        return $d->day(min($dia, $d->daysInMonth));
    }
}
