<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesExpenseOptions;
use App\Jobs\GenerateProfitLossPdfJob;
use App\Models\Empresa;
use App\Models\ExportRequest;
use App\Policies\Concerns\ChecksTeamOwnership;
use App\Services\Finance\PeriodResolver;
use App\Services\Finance\ProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard ejecutivo del Estado de Resultados (Finanzas Fase 6).
 *
 * Vista de liderazgo: SOLO el dueño del team la ve (`ownsCurrentTeam`). Consume
 * `ProfitLossService` (con `team_id` explícito, defense-in-depth) y `PeriodResolver`
 * para armar KPIs, comparativos (periodo anterior + YoY) y margen por empresa.
 * El export PDF asíncrono espeja el patrón de `ReconciliationController`.
 */
class ExecutiveController extends Controller
{
    use ChecksTeamOwnership;
    use ResolvesExpenseOptions;

    /** Granularidades soportadas por el resolver de periodos. */
    private const GRANULARIDADES = ['mensual', 'trimestral', 'semestral', 'anual'];

    public function index(Request $request, PeriodResolver $resolver, ProfitLossService $pl): Response
    {
        abort_unless($this->ownsCurrentTeam($request->user()), 403);

        $teamId = $request->user()->current_team_id;

        $granularidad = $this->normalizeGranularidad($request->input('granularidad'));
        $empresaId = $this->normalizeEmpresaId($request->input('empresa_id'));

        // Ancla mes/año ya resuelta por SetGlobalDateFilters.
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $rango = $resolver->resolve($granularidad, $year, $month);
        $prev = $resolver->previous($granularidad, $rango['desde']);
        $yoy = $resolver->yearOverYear($rango);

        $pnl = $pl->forPeriod($rango['desde'], $rango['hasta'], $empresaId, $teamId);
        $pnlPrev = $pl->forPeriod($prev['desde'], $prev['hasta'], $empresaId, $teamId);
        $pnlYoY = $pl->forPeriod($yoy['desde'], $yoy['hasta'], $empresaId, $teamId);

        $empresas = $this->empresasActivas($teamId);

        // Margen por empresa: un P&L por cada empresa activa del team.
        $porEmpresa = $empresas->map(fn ($empresa) => [
            'id' => $empresa->id,
            'nombre' => $empresa->nombre,
            'color' => $empresa->color,
            'pnl' => $pl->forPeriod($rango['desde'], $rango['hasta'], $empresa->id, $teamId),
        ])->values();

        // Tarjeta MRR: empresa "Tu Checador" si existe (degrada con gracia a null).
        $tuChecadorEmpresa = Empresa::where('team_id', $teamId)
            ->where('slug', 'tu-checador')
            ->first();

        $tuChecador = $tuChecadorEmpresa ? [
            'id' => $tuChecadorEmpresa->id,
            'nombre' => $tuChecadorEmpresa->nombre,
            'color' => $tuChecadorEmpresa->color,
            'pnl' => $pl->forPeriod($rango['desde'], $rango['hasta'], $tuChecadorEmpresa->id, $teamId),
        ] : null;

        return Inertia::render('Executive/Index', [
            'pnl' => $pnl,
            'pnlPrev' => $pnlPrev,
            'pnlYoY' => $pnlYoY,
            'porEmpresa' => $porEmpresa,
            'tuChecador' => $tuChecador,
            'empresas' => $empresas,
            'filters' => [
                'granularidad' => $granularidad,
                'empresa_id' => $empresaId,
                'month' => $month,
                'year' => $year,
            ],
        ]);
    }

    public function export(Request $request)
    {
        abort_unless($this->ownsCurrentTeam($request->user()), 403);

        $request->validate([
            'granularidad' => 'nullable|in:mensual,trimestral,semestral,anual',
            'empresa_id' => 'nullable|integer',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $teamId = $request->user()->current_team_id;
        $userId = $request->user()->id;

        $granularidad = $this->normalizeGranularidad($request->input('granularidad'));
        $empresaId = $this->normalizeEmpresaId($request->input('empresa_id'));
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $exportRequest = ExportRequest::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'type' => 'pl_pdf',
            'status' => 'queued',
            'filters' => [
                'granularidad' => $granularidad,
                'empresa_id' => $empresaId,
                'month' => $month,
                'year' => $year,
                'team_id' => $teamId,
            ],
        ]);

        GenerateProfitLossPdfJob::dispatch($exportRequest);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $exportRequest->id,
                'status' => 'queued',
                'message' => 'Export starting.',
            ]);
        }

        return back()->with('success', 'Exportación iniciada. Verifique el historial en unos momentos.');
    }

    public function checkExportStatus(Request $request, $id)
    {
        abort_unless($this->ownsCurrentTeam($request->user()), 403);

        $exportRequest = ExportRequest::where('team_id', $request->user()->current_team_id)
            ->findOrFail($id);

        if ($exportRequest->user_id !== $request->user()->id) {
            abort(403);
        }

        // Offline Safeguard: si lleva > 2 minutos en cola, avisa al usuario.
        $isOffline = false;
        if ($exportRequest->status === 'queued' && $exportRequest->created_at->diffInMinutes(now()) > 2) {
            $isOffline = true;
        }

        return response()->json([
            'status' => $exportRequest->status,
            'error_message' => $exportRequest->error_message,
            'is_offline' => $isOffline,
        ]);
    }

    public function downloadExport(Request $request, $id)
    {
        abort_unless($this->ownsCurrentTeam($request->user()), 403);

        $exportRequest = ExportRequest::where('team_id', $request->user()->current_team_id)
            ->findOrFail($id);

        if ($exportRequest->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($exportRequest->status !== 'completed' || ! $exportRequest->file_path) {
            abort(404, 'File not ready or failed.');
        }

        if (! Storage::exists($exportRequest->file_path)) {
            abort(404, 'File not found on disk.');
        }

        return Storage::download($exportRequest->file_path, $exportRequest->file_name);
    }

    /** Devuelve una granularidad válida (default `mensual`). */
    private function normalizeGranularidad($value): string
    {
        return in_array($value, self::GRANULARIDADES, true) ? $value : 'mensual';
    }

    /** Normaliza `empresa_id`: int positivo o null (consolidado). */
    private function normalizeEmpresaId($value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
