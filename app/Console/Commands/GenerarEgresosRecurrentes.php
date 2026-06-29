<?php

namespace App\Console\Commands;

use App\Models\Egreso;
use App\Models\EgresoRecurrente;
use App\Services\Finance\RecurrenceCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerarEgresosRecurrentes extends Command
{
    protected $signature = 'egresos:generar-recurrentes {--dry-run : Reporta sin persistir}';

    protected $description = 'Genera egresos a partir de plantillas recurrentes vencidas (idempotente, con catch-up).';

    /** Tope de seguridad de periodos generados por plantilla en una corrida. */
    private const MAX_CATCHUP = 24;

    public function handle(RecurrenceCalculator $calc): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = now()->startOfDay();
        $created = 0;
        $touched = 0;

        // Sin Auth en un comando → el global scope de TeamOwned no filtra: recorre todos los teams.
        $plantillas = EgresoRecurrente::query()->due()->get();

        foreach ($plantillas as $plantilla) {
            $iteraciones = 0;

            while (
                $plantilla->activo
                && $plantilla->proxima_generacion->copy()->startOfDay()->lte($today)
                && $iteraciones < self::MAX_CATCHUP
            ) {
                $iteraciones++;
                $nominal = $plantilla->proxima_generacion->copy()->startOfDay();

                // Cortar por vigencia ANTES de generar un periodo fuera de rango.
                if ($plantilla->vigencia_tipo === 'hasta_fecha'
                    && $plantilla->fecha_fin
                    && $nominal->gt($plantilla->fecha_fin->copy()->startOfDay())) {
                    $plantilla->activo = false;
                    if (! $dryRun) {
                        $plantilla->save();
                    }
                    break;
                }
                if ($plantilla->vigencia_tipo === 'num_pagos'
                    && $plantilla->num_pagos !== null
                    && $plantilla->pagos_generados >= $plantilla->num_pagos) {
                    $plantilla->activo = false;
                    if (! $dryRun) {
                        $plantilla->save();
                    }
                    break;
                }

                $fechaPago = $calc->applyDiaHabil($nominal, $plantilla->ajuste_dia_habil);

                // Idempotencia: no recrear si ya existe el egreso de este periodo.
                $yaExiste = Egreso::query()
                    ->where('team_id', $plantilla->team_id)
                    ->where('egreso_recurrente_id', $plantilla->id)
                    ->whereDate('fecha', $fechaPago->toDateString())
                    ->exists();

                DB::transaction(function () use ($plantilla, $fechaPago, $nominal, $calc, $dryRun, $yaExiste, &$created) {
                    if (! $yaExiste) {
                        if (! $dryRun) {
                            Egreso::create([
                                'team_id' => $plantilla->team_id,
                                'empresa_id' => $plantilla->empresa_id,
                                'categoria_id' => $plantilla->categoria_id,
                                'egreso_recurrente_id' => $plantilla->id,
                                'fecha' => $fechaPago->toDateString(),
                                'monto' => $plantilla->monto,
                                'descripcion' => $plantilla->descripcion,
                                'proveedor' => $plantilla->proveedor,
                                'origen' => 'recurrente',
                                'user_id' => $plantilla->user_id,
                            ]);
                        }
                        $plantilla->pagos_generados++;
                        $created++;
                    }

                    // Avanzar el periodo (idempotente: avanza aunque ya existiera).
                    $plantilla->proxima_generacion = $calc->nextDate($nominal, $plantilla->frecuencia, $plantilla->dia_del_mes);

                    // Cortar por vigencia tras avanzar.
                    if ($plantilla->vigencia_tipo === 'num_pagos'
                        && $plantilla->num_pagos !== null
                        && $plantilla->pagos_generados >= $plantilla->num_pagos) {
                        $plantilla->activo = false;
                    }
                    if ($plantilla->vigencia_tipo === 'hasta_fecha'
                        && $plantilla->fecha_fin
                        && $plantilla->proxima_generacion->copy()->startOfDay()->gt($plantilla->fecha_fin->copy()->startOfDay())) {
                        $plantilla->activo = false;
                    }

                    if (! $dryRun) {
                        $plantilla->save();
                    }
                });
            }

            if ($iteraciones >= self::MAX_CATCHUP) {
                $this->warn("Plantilla #{$plantilla->id} alcanzó el tope de catch-up ({$iteraciones}); revisa proxima_generacion.");
            }
            if ($iteraciones > 0) {
                $touched++;
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Egresos generados: {$created} (plantillas procesadas: {$touched}).");

        return self::SUCCESS;
    }
}
