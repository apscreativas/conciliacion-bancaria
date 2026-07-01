<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Egreso;
use App\Models\Empleado;
use App\Services\Finance\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerarNomina extends Command
{
    protected $signature = 'nomina:generar
        {--month= : Mes objetivo YYYY-MM (omite la ventana móvil; default: ventana de 40 días)}
        {--dry-run : Reporta sin persistir}';

    protected $description = 'Genera los egresos de nómina quincenal (fiscal + complemento) por empleado activo (idempotente).';

    /** Ventana móvil de catch-up en días (outage más largo → usar --month). */
    private const VENTANA_DIAS = 40;

    private const CAT_FISCAL = 'Nómina fiscal';

    private const CAT_TECNICA = 'Nómina técnica facturable';

    private const CAT_COMPLEMENTO = 'Nómina complemento / real';

    public function handle(PayrollCalculator $calc): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = now()->startOfDay();

        $mes = $this->option('month');
        if ($mes !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $this->error('--month inválido: usa el formato YYYY-MM (por ejemplo 2026-02).');

            return self::FAILURE;
        }

        [$desde, $quincenas] = $this->quincenasObjetivo($calc, $today, $mes);

        $creados = 0;
        $omitidosCategoria = 0;
        $omitidosComplemento = 0;
        $catCache = []; // team_id => [nombre => id|null]
        $avisados = []; // "team_id:categoria" ya advertidos en esta corrida (evita spam de log)

        // Sin Auth en un comando → desactivamos el global scope explícitamente (CLAUDE.md §1.3).
        $empleados = Empleado::withoutGlobalScopes()->where('activo', true)->get();

        foreach ($quincenas as $q) {
            $nominal = $q['nominal'];
            $pago = $q['pago'];
            $qLabel = $nominal->day === 15 ? 'Q1' : 'Q2';

            foreach ($empleados as $emp) {
                // Elegibilidad por fecha NOMINAL.
                if ($emp->fecha_entrada->copy()->startOfDay()->gt($nominal)) {
                    continue;
                }
                if ($emp->fecha_baja && $nominal->gt($emp->fecha_baja->copy()->startOfDay())) {
                    continue;
                }

                $cats = $catCache[$emp->team_id] ??= $this->resolverCategorias($emp->team_id);

                // Parte fiscal.
                $catFiscalNombre = $emp->clasificacion === 'tecnica' ? self::CAT_TECNICA : self::CAT_FISCAL;
                $montoFiscal = round(((float) $emp->salario_fiscal) / 2, 2);
                if ($cats[$catFiscalNombre] === null) {
                    $omitidosCategoria++;
                    $this->avisarCategoriaFaltante($avisados, $emp->team_id, $catFiscalNombre);
                } else {
                    $creados += $this->generar($emp, $pago, 'fiscal', $cats[$catFiscalNombre], $montoFiscal, "Nómina fiscal {$qLabel} — {$emp->nombre}", $dryRun);
                }

                // Parte complemento (solo si > 0).
                $complemento = (float) $emp->salario_real - (float) $emp->salario_fiscal;
                if ($complemento <= 0) {
                    $omitidosComplemento++;
                } elseif ($cats[self::CAT_COMPLEMENTO] === null) {
                    $omitidosCategoria++;
                    $this->avisarCategoriaFaltante($avisados, $emp->team_id, self::CAT_COMPLEMENTO);
                } else {
                    $montoComp = round($complemento / 2, 2);
                    $creados += $this->generar($emp, $pago, 'complemento', $cats[self::CAT_COMPLEMENTO], $montoComp, "Nómina complemento {$qLabel} — {$emp->nombre}", $dryRun);
                }
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Nómina desde {$desde->toDateString()}: {$creados} egresos creados; omitidos por categoría: {$omitidosCategoria}; complemento ≤ 0: {$omitidosComplemento}.");

        return self::SUCCESS;
    }

    /**
     * Devuelve [fechaDesde, quincenas]. Con --month apunta a ese mes; sin él, la ventana móvil.
     * Siempre filtra nominal <= hoy (no pre-genera futuro).
     *
     * @return array{0: Carbon, 1: array<int, array{nominal: Carbon, pago: Carbon}>}
     */
    private function quincenasObjetivo(PayrollCalculator $calc, Carbon $today, ?string $mes): array
    {
        if ($mes) {
            // '!Y-m' fija día=01 y hora=00 (sin '!' Carbon rellena el día con now(), lo que
            // en días 29-31 desbordaría a otro mes para meses cortos). $mes ya viene validado.
            $ref = Carbon::createFromFormat('!Y-m', $mes);
            $quincenas = array_filter(
                $calc->quincenas((int) $ref->year, (int) $ref->month),
                fn ($q) => $q['nominal']->lte($today),
            );

            return [$ref->copy(), array_values($quincenas)];
        }

        $desde = $today->copy()->subDays(self::VENTANA_DIAS);
        $quincenas = [];
        $cursor = $desde->copy()->startOfMonth();
        while ($cursor->lte($today)) {
            foreach ($calc->quincenas((int) $cursor->year, (int) $cursor->month) as $q) {
                if ($q['nominal']->betweenIncluded($desde, $today)) {
                    $quincenas[] = $q;
                }
            }
            $cursor->addMonthNoOverflow();
        }

        return [$desde, $quincenas];
    }

    /** Resuelve las 3 categorías de nómina del team (activas) por nombre exacto. */
    private function resolverCategorias(int $teamId): array
    {
        $base = Categoria::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('tipo', 'egreso')
            ->where('activo', true)
            ->whereIn('nombre', [self::CAT_FISCAL, self::CAT_TECNICA, self::CAT_COMPLEMENTO])
            ->pluck('id', 'nombre');

        return [
            self::CAT_FISCAL => $base[self::CAT_FISCAL] ?? null,
            self::CAT_TECNICA => $base[self::CAT_TECNICA] ?? null,
            self::CAT_COMPLEMENTO => $base[self::CAT_COMPLEMENTO] ?? null,
        ];
    }

    /** Crea un egreso de nómina idempotente. Devuelve 1 si lo creó, 0 si ya existía. */
    private function generar(Empleado $emp, Carbon $pago, string $concepto, int $categoriaId, float $monto, string $descripcion, bool $dryRun): int
    {
        $yaExiste = Egreso::query()
            ->where('empleado_id', $emp->id)
            ->where('fecha', $pago->toDateString())
            ->where('concepto_nomina', $concepto)
            ->exists();

        if ($yaExiste) {
            return 0;
        }

        // En dry-run contamos lo que SÍ se generaría (para un preview fiel), pero no persistimos.
        if ($dryRun) {
            return 1;
        }

        return DB::transaction(function () use ($emp, $pago, $concepto, $categoriaId, $monto, $descripcion) {
            try {
                Egreso::create([
                    'team_id' => $emp->team_id,
                    'empresa_id' => $emp->empresa_id,
                    'categoria_id' => $categoriaId,
                    'empleado_id' => $emp->id,
                    'concepto_nomina' => $concepto,
                    'fecha' => $pago->toDateString(),
                    'monto' => $monto,
                    'descripcion' => $descripcion,
                    'origen' => 'recurrente',
                    'user_id' => $emp->user_id,
                ]);

                return 1;
            } catch (QueryException $e) {
                // Carrera (manual vs cron): el índice único rechaza el duplicado.
                if ($this->isDuplicate($e)) {
                    return 0;
                }
                throw $e;
            }
        });
    }

    /**
     * Violación de UNIQUE específicamente (código MySQL 1062). NO usamos el SQLSTATE genérico
     * 23000: cubre también NOT NULL (1048) y FK (1452); tragarlos ocultaría una sub-generación
     * de nómina. Cualquier otra violación de integridad debe propagarse.
     */
    private function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** Advierte una sola vez por (team, categoría) y por corrida para no inundar el log. */
    private function avisarCategoriaFaltante(array &$avisados, int $teamId, string $categoria): void
    {
        $key = "{$teamId}:{$categoria}";
        if (isset($avisados[$key])) {
            return;
        }
        $avisados[$key] = true;
        Log::warning("[nomina:generar] Team #{$teamId} sin categoría '{$categoria}' (activa); se omiten esos egresos de nómina.");
    }
}
