<?php

namespace App\Console\Commands;

use App\Models\Egreso;
use App\Models\EgresoRecurrente;
use App\Services\Finance\RecurrenceCalculator;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // En un comando NO hay Auth, así que el global scope de TeamOwned no filtra; aun así
        // lo desactivamos explícitamente (CLAUDE.md §1.3) para que la cobertura de TODOS los
        // teams sea un invariante del código, no un efecto colateral de "no hay sesión".
        $plantillas = EgresoRecurrente::withoutGlobalScopes()->due()->get();

        foreach ($plantillas as $plantilla) {
            $iteraciones = 0;

            while (
                $plantilla->activo
                && $plantilla->proxima_generacion->copy()->startOfDay()->lte($today)
                && $iteraciones < self::MAX_CATCHUP
            ) {
                $iteraciones++;
                $nominal = $plantilla->proxima_generacion->copy()->startOfDay();
                $fechaPago = $calc->applyDiaHabil($nominal, $plantilla->ajuste_dia_habil);

                // Cortes de vigencia ANTES de generar el periodo (un solo punto de evaluación).
                // num_pagos: ya se cubrió el cupo de pagos.
                if ($plantilla->vigencia_tipo === 'num_pagos'
                    && $plantilla->num_pagos !== null
                    && $plantilla->pagos_generados >= $plantilla->num_pagos) {
                    $plantilla->activo = false;
                    if (! $dryRun) {
                        $plantilla->save();
                    }
                    break;
                }
                // hasta_fecha: se evalúa contra la FECHA DE PAGO real, no el día nominal: con
                // 'habil_siguiente' un nominal de fin de mes puede caer después de fecha_fin.
                if ($plantilla->vigencia_tipo === 'hasta_fecha'
                    && $plantilla->fecha_fin
                    && $fechaPago->gt($plantilla->fecha_fin->copy()->startOfDay())) {
                    $plantilla->activo = false;
                    if (! $dryRun) {
                        $plantilla->save();
                    }
                    break;
                }

                // Idempotencia: no recrear si ya existe el egreso de este periodo. Respaldada
                // por el índice único egresos_recurrente_periodo_unique (where directo sobre la
                // columna date para que el índice sea utilizable, no whereDate()).
                $yaExiste = Egreso::query()
                    ->where('team_id', $plantilla->team_id)
                    ->where('egreso_recurrente_id', $plantilla->id)
                    ->where('fecha', $fechaPago->toDateString())
                    ->exists();

                DB::transaction(function () use ($plantilla, $fechaPago, $nominal, $calc, $dryRun, $yaExiste, &$created) {
                    if (! $yaExiste && ! $dryRun) {
                        try {
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
                            $plantilla->pagos_generados++;
                            $created++;
                        } catch (QueryException $e) {
                            // Otra corrida insertó este periodo entre el exists() y el create:
                            // el índice único lo rechaza; lo tratamos como ya generado.
                            if (! $this->isDuplicate($e)) {
                                throw $e;
                            }
                        }
                    } elseif ($yaExiste) {
                        // El periodo ya estaba cubierto (corrida previa o alta manual): cuéntalo
                        // para que la vigencia num_pagos no genere de más.
                        $plantilla->pagos_generados++;
                    }

                    // Avanzar el periodo (idempotente: avanza aunque ya existiera).
                    $plantilla->proxima_generacion = $calc->nextDate($nominal, $plantilla->frecuencia, $plantilla->dia_del_mes);

                    if (! $dryRun) {
                        $plantilla->save();
                    }
                });
            }

            if ($iteraciones >= self::MAX_CATCHUP) {
                $msg = "Plantilla #{$plantilla->id} alcanzó el tope de catch-up ({$iteraciones}); revisa proxima_generacion.";
                $this->warn($msg);
                // El stdout del scheduler se descarta; deja rastro accionable en el log.
                Log::warning("[egresos:generar-recurrentes] {$msg}");
            }
            if ($iteraciones > 0) {
                $touched++;
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Egresos generados: {$created} (plantillas procesadas: {$touched}).");

        return self::SUCCESS;
    }

    /** Violación de UNIQUE (SQLSTATE 23000 / código MySQL 1062). */
    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
