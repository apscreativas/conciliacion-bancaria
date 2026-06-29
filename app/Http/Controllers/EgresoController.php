<?php

namespace App\Http\Controllers;

use App\Http\Requests\EgresoRequest;
use App\Models\Categoria;
use App\Models\Egreso;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EgresoController extends Controller
{
    public function index(Request $request): Response
    {
        $teamId = auth()->user()->current_team_id;

        $month = $request->input('month');
        $year = $request->input('year');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = Egreso::where('team_id', $teamId)
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
        $perPage = $perPageParam === 'all' ? 10000 : (int) $perPageParam;

        $egresos = $query->with(['empresa:id,nombre,color', 'categoria:id,nombre'])
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $categorias = $this->categoriasEgreso($teamId);

        return Inertia::render('Expenses/Index', [
            'egresos' => $egresos,
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $categorias,
            'total' => (float) $total,
            'totalsByCategoria' => $categorias
                ->map(fn ($c) => ['nombre' => $c->nombre, 'total' => (float) ($totalsByCategoria[$c->id] ?? 0)])
                ->filter(fn ($row) => $row['total'] > 0)
                ->values(),
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

        return Inertia::render('Expenses/Create', [
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $this->categoriasEgreso($teamId),
        ]);
    }

    public function store(EgresoRequest $request): RedirectResponse
    {
        Egreso::create($request->validated() + [
            'team_id' => auth()->user()->current_team_id,
            'user_id' => auth()->id(),
            'origen' => 'manual',
        ]);

        return redirect()->route('expenses.index')->with('success', 'Egreso registrado exitosamente.');
    }

    public function edit(Egreso $expense): Response
    {
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('Expenses/Create', [
            'egreso' => $expense->load(['empresa:id,nombre', 'categoria:id,nombre']),
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $this->categoriasEgreso($teamId),
        ]);
    }

    public function update(EgresoRequest $request, Egreso $expense): RedirectResponse
    {
        $expense->update($request->validated());

        return redirect()->route('expenses.index')->with('success', 'Egreso actualizado exitosamente.');
    }

    public function destroy(Egreso $expense): RedirectResponse
    {
        $expense->delete();

        return back()->with('success', 'Egreso eliminado.');
    }

    private function empresasActivas(int $teamId)
    {
        return Empresa::where('team_id', $teamId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'color']);
    }

    private function categoriasEgreso(int $teamId)
    {
        return Categoria::where('team_id', $teamId)
            ->where('activo', true)
            ->where('tipo', 'egreso')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'grupo']);
    }
}
