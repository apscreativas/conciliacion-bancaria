<?php

namespace App\Services\Finance;

use Carbon\Carbon;

/**
 * Fechas de pago de la nómina quincenal (Finanzas Fase 3B): día 15 y último día del mes,
 * ajustadas al día hábil anterior si caen en fin de semana. Reusa el ajuste de día hábil
 * de RecurrenceCalculator (CLAUDE.md §3.5, no duplicar lógica). Sin festivos en v1.
 */
class PayrollCalculator
{
    public function __construct(private RecurrenceCalculator $habil) {}

    /**
     * Las dos quincenas del mes como pares ['nominal' => Carbon, 'pago' => Carbon].
     * 'nominal' define elegibilidad/periodo; 'pago' es la fecha del egreso.
     *
     * @return array<int, array{nominal: Carbon, pago: Carbon}>
     */
    public function quincenas(int $year, int $month): array
    {
        $q1 = Carbon::create($year, $month, 15)->startOfDay();
        $q2 = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();

        return [
            ['nominal' => $q1, 'pago' => $this->habil->applyDiaHabil($q1, 'habil_anterior')],
            ['nominal' => $q2, 'pago' => $this->habil->applyDiaHabil($q2, 'habil_anterior')],
        ];
    }
}
