<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesExpenseOptions;
use App\Jobs\GenerateProfitLossPdfJob;
use App\Models\Empresa;
use App\Models\ExportRequest;
use App\Policies\Concerns\ChecksTeamOwnership;
use App\Services\Finance\FinanceAnalyticsService;
use App\Services\Finance\PeriodResolver;
use App\Services\Finance\ProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard ejecutivo del Estado de Resultados (Finanzas Fase 6).
 *
 * Vista de liderazgo: la ven el dueño del team y los miembros con rol 'admin'
 * (`managesCurrentTeam`). Consume
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

    /** Ventanas de tendencia soportadas (meses); default 12. */
    private const MESES_VENTANA = [6, 12];

    public function index(Request $request, PeriodResolver $resolver, ProfitLossService $pl, FinanceAnalyticsService $analytics): Response
    {
        abort_unless($this->managesCurrentTeam($request->user()), 403);

        $teamId = $request->user()->current_team_id;

        $granularidad = $this->normalizeGranularidad($request->input('granularidad'));
        $empresaId = $this->normalizeEmpresaId($request->input('empresa_id'));
        $months = $this->normalizeMonths($request->input('months'));

        // Ancla mes/año: SetGlobalDateFilters la resuelve, pero clampamos de nuevo
        // (defense-in-depth) para que un ?month=13 o ?year fuera de rango no desborde el ancla.
        $month = $this->normalizeMonth($request->input('month'));
        $year = $this->normalizeYear($request->input('year'));

        $rango = $resolver->resolve($granularidad, $year, $month);
        $prev = $resolver->previous($granularidad, $rango['desde']);
        $yoy = $resolver->yearOverYear($rango);

        $pnl = $pl->forPeriod($rango['desde'], $rango['hasta'], $empresaId, $teamId);
        $pnlPrev = $pl->forPeriod($prev['desde'], $prev['hasta'], $empresaId, $teamId);
        $pnlYoY = $pl->forPeriod($yoy['desde'], $yoy['hasta'], $empresaId, $teamId);

        // Analítica temporal (Dashboard v2, BLOQUE 2): series mensuales + desgloses del rango.
        $series = $analytics->monthlySeries($year, $month, $months, $empresaId, $teamId);
        // Ingreso por empresa: SIEMPRE consolidado multi-empresa (ignora el filtro de empresa por diseño).
        $ingresoEmpresaSeries = $analytics->ingresoPorEmpresaMensual($year, $month, $months, $teamId);
        $egresosPorCategoria = $analytics->egresosPorCategoria($rango['desde'], $rango['hasta'], $empresaId, $teamId);
        $egresosPorNaturaleza = $analytics->egresosPorNaturaleza($rango['desde'], $rango['hasta'], $empresaId, $teamId);
        $topProveedores = $analytics->topProveedores($rango['desde'], $rango['hasta'], $empresaId, $teamId);
        $nominaRollup = $analytics->nominaRollup($rango['desde'], $rango['hasta'], $empresaId, $teamId);

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
            'series' => $series,
            'ingresoEmpresaSeries' => $ingresoEmpresaSeries,
            'egresosPorCategoria' => $egresosPorCategoria,
            'egresosPorNaturaleza' => $egresosPorNaturaleza,
            'topProveedores' => $topProveedores,
            'nominaRollup' => $nominaRollup,
            'filters' => [
                'granularidad' => $granularidad,
                'empresa_id' => $empresaId,
                'month' => $month,
                'year' => $year,
                'months' => $months,
            ],
        ]);
    }

    public function export(Request $request)
    {
        abort_unless($this->managesCurrentTeam($request->user()), 403);

        $request->validate([
            'granularidad' => 'nullable|in:mensual,trimestral,semestral,anual',
            'empresa_id' => 'nullable|integer',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'months' => 'nullable|integer|in:6,12',
        ]);

        $teamId = $request->user()->current_team_id;
        $userId = $request->user()->id;

        $granularidad = $this->normalizeGranularidad($request->input('granularidad'));
        $empresaId = $this->normalizeEmpresaId($request->input('empresa_id'));
        $months = $this->normalizeMonths($request->input('months'));
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
                'months' => $months,
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
        abort_unless($this->managesCurrentTeam($request->user()), 403);

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
        abort_unless($this->managesCurrentTeam($request->user()), 403);

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

    /** Normaliza la ventana de tendencia a 6 o 12 meses (default 12). */
    private function normalizeMonths($value): int
    {
        return in_array((int) $value, self::MESES_VENTANA, true) ? (int) $value : 12;
    }

    /** Clampa el mes ancla a 1..12; fallback al mes actual si es inválido. */
    private function normalizeMonth($value): int
    {
        $month = is_numeric($value) ? (int) $value : now()->month;

        return ($month >= 1 && $month <= 12) ? $month : now()->month;
    }

    /** Clampa el año ancla a 2000..2100; fallback al año actual si es inválido. */
    private function normalizeYear($value): int
    {
        $year = is_numeric($value) ? (int) $value : now()->year;

        return ($year >= 2000 && $year <= 2100) ? $year : now()->year;
    }
}
