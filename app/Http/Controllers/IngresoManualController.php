<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesTeamOwnership;
use App\Http\Controllers\Concerns\ResolvesExpenseOptions;
use App\Http\Requests\IngresoManualRequest;
use App\Models\IngresoManual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IngresoManualController extends Controller
{
    use EnforcesTeamOwnership;
    use ResolvesExpenseOptions;

    public function index(Request $request): Response
    {
        $teamId = auth()->user()->current_team_id;

        $month = $request->input('month');
        $year = $request->input('year');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = IngresoManual::where('team_id', $teamId)
            ->when($request->filled('empresa_id'), fn ($q) => $q->where('empresa_id', $request->input('empresa_id')))
            ->when($request->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $request->input('categoria_id')))
            ->when($request->filled('amount_min'), fn ($q) => $q->where('monto', '>=', $request->input('amount_min')))
            ->when($request->filled('amount_max'), fn ($q) => $q->where('monto', '<=', $request->input('amount_max')));

        // Rango de fechas; si no hay, cae al mes/año global (SetGlobalDateFilters).
        if ($dateFrom || $dateTo) {
            $query->when($dateFrom, fn ($q) => $q->whereDate('fecha', '>=', $dateFrom))
                ->when($dateTo, fn ($q) => $q->whereDate('fecha', '<=', $dateTo));
        } elseif ($month && $year) {
            $query->whereMonth('fecha', $month)->whereYear('fecha', $year);
        }

        // Totales sobre el conjunto FILTRADO (no solo la página).
        $total = (clone $query)->sum('monto');
        $totalsByCategoria = (clone $query)
            ->selectRaw('categoria_id, SUM(monto) as total')
            ->groupBy('categoria_id')
            ->pluck('total', 'categoria_id');

        $perPageParam = $request->input('per_page', 25);
        // Whitelist: un per_page basura (no numérico) caería a 0 → paginate(0) → DivisionByZeroError (500).
        $perPage = $perPageParam === 'all' ? 10000 : (in_array((int) $perPageParam, [10, 25, 50, 100], true) ? (int) $perPageParam : 25);

        $ingresos = $query->with(['empresa:id,nombre,color', 'categoria:id,nombre'])
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $categorias = $this->categoriasIngreso($teamId);

        // Desglose por categoría + bucket "Sin categoría" para que la suma cuadre con $total.
        $breakdown = $categorias
            ->map(fn ($c) => ['nombre' => $c->nombre, 'total' => (float) ($totalsByCategoria[$c->id] ?? 0)])
            ->filter(fn ($row) => $row['total'] > 0)
            ->values();
        $sinCategoria = (float) ($totalsByCategoria->get(null) ?? $totalsByCategoria->get('') ?? 0);
        if ($sinCategoria > 0) {
            $breakdown->push(['nombre' => 'Sin categoría', 'total' => $sinCategoria]);
        }

        return Inertia::render('CashIncome/Index', [
            'ingresos' => $ingresos,
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $categorias,
            'total' => (float) $total,
            'totalsByCategoria' => $breakdown,
            'filters' => [
                'month' => $month,
                'year' => $year,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_min' => $request->input('amount_min'),
                'amount_max' => $request->input('amount_max'),
                'empresa_id' => $request->input('empresa_id'),
                'categoria_id' => $request->input('categoria_id'),
                'per_page' => $perPageParam,
            ],
        ]);
    }

    public function create(): Response
    {
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('CashIncome/Create', [
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $this->categoriasIngreso($teamId),
        ]);
    }

    public function store(IngresoManualRequest $request): RedirectResponse
    {
        IngresoManual::create($request->validated() + [
            'team_id' => auth()->user()->current_team_id,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('cash-income.index')->with('success', 'Ingreso registrado exitosamente.');
    }

    public function edit(IngresoManual $cash_income): Response
    {
        $this->ensureOwnTeam($cash_income);
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('CashIncome/Create', [
            'ingreso' => $cash_income->load(['empresa:id,nombre', 'categoria:id,nombre']),
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $this->categoriasIngreso($teamId),
        ]);
    }

    public function update(IngresoManualRequest $request, IngresoManual $cash_income): RedirectResponse
    {
        $this->ensureOwnTeam($cash_income);

        $cash_income->update($request->validated());

        return redirect()->route('cash-income.index')->with('success', 'Ingreso actualizado exitosamente.');
    }

    public function destroy(IngresoManual $cash_income): RedirectResponse
    {
        $this->ensureOwnTeam($cash_income);

        $cash_income->delete();

        return back()->with('success', 'Ingreso eliminado.');
    }
}
