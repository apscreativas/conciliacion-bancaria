<?php

namespace App\Jobs;

use App\Models\Empresa;
use App\Models\ExportRequest;
use App\Services\Finance\PeriodResolver;
use App\Services\Finance\ProfitLossService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Genera el PDF del Estado de Resultados (Finanzas Fase 6), clon del PDF job de conciliación.
 *
 * Corre en cola `exports` SIN auth → pasa `team_id` EXPLÍCITO a `ProfitLossService`
 * (queue-safety: el global scope de `TeamOwned` está apagado en cola).
 */
class GenerateProfitLossPdfJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600;

    public $tries = 3;

    public $backoff = [30, 120, 300];

    public function __construct(public ExportRequest $exportRequest)
    {
        $this->onQueue('exports');
    }

    public function handle(PeriodResolver $resolver, ProfitLossService $pl): void
    {
        $this->exportRequest->update(['status' => 'processing']);

        try {
            $filters = $this->exportRequest->filters ?? [];

            $teamId = $filters['team_id'] ?? $this->exportRequest->team_id;
            $granularidad = $filters['granularidad'] ?? 'mensual';
            $empresaId = $filters['empresa_id'] ?? null;
            $month = (int) ($filters['month'] ?? now()->month);
            $year = (int) ($filters['year'] ?? now()->year);

            $rango = $resolver->resolve($granularidad, $year, $month);
            $prev = $resolver->previous($granularidad, $rango['desde']);
            $yoy = $resolver->yearOverYear($rango);

            $pnl = $pl->forPeriod($rango['desde'], $rango['hasta'], $empresaId, $teamId);
            $pnlPrev = $pl->forPeriod($prev['desde'], $prev['hasta'], $empresaId, $teamId);
            $pnlYoY = $pl->forPeriod($yoy['desde'], $yoy['hasta'], $empresaId, $teamId);

            $empresas = Empresa::where('team_id', $teamId)
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'color']);

            $porEmpresa = $empresas->map(fn ($empresa) => [
                'id' => $empresa->id,
                'nombre' => $empresa->nombre,
                'color' => $empresa->color,
                'pnl' => $pl->forPeriod($rango['desde'], $rango['hasta'], $empresa->id, $teamId),
            ])->values();

            // Nombre de la empresa filtrada (o "Consolidado").
            $empresaNombre = 'Consolidado';
            if ($empresaId !== null) {
                $empresa = Empresa::where('team_id', $teamId)->find($empresaId);
                $empresaNombre = $empresa?->nombre ?? 'Empresa';
            }

            $data = [
                'pnl' => $pnl,
                'pnlPrev' => $pnlPrev,
                'pnlYoY' => $pnlYoY,
                'porEmpresa' => $porEmpresa,
                'granularidad' => $granularidad,
                'empresaNombre' => $empresaNombre,
                'desde' => $rango['desde']->toDateString(),
                'hasta' => $rango['hasta']->toDateString(),
                'generadoAt' => now(),
            ];

            $uuid = Str::uuid();
            $path = "exports/{$teamId}/{$this->exportRequest->user_id}/{$uuid}.pdf";

            $pdf = Pdf::loadView('exports.profit_loss.pdf_report', $data);
            $pdf->setPaper('a4', 'portrait');

            Storage::put($path, $pdf->output());

            $this->exportRequest->update([
                'status' => 'completed',
                'file_path' => $path,
                'file_name' => 'estado_resultados_'.$year.'_'.$month.'.pdf',
            ]);
        } catch (\Throwable $e) {
            Log::error('P&L PDF Export Failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            $this->exportRequest->update([
                'status' => 'failed',
                'error_message' => 'Error generating pdf: '.$e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->exportRequest->update([
            'status' => 'failed',
            'error_message' => 'Error permanente: '.$exception->getMessage(),
        ]);
    }
}
