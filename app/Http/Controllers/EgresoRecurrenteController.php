<?php

namespace App\Http\Controllers;

use App\Http\Requests\EgresoRecurrenteRequest;
use App\Models\Categoria;
use App\Models\EgresoRecurrente;
use App\Models\Empresa;
use App\Services\Finance\RecurrenceCalculator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EgresoRecurrenteController extends Controller
{
    public function index(): Response
    {
        $teamId = auth()->user()->current_team_id;

        $plantillas = EgresoRecurrente::where('team_id', $teamId)
            ->with(['empresa:id,nombre,color', 'categoria:id,nombre'])
            ->orderByDesc('activo')
            ->orderBy('proxima_generacion')
            ->get();

        return Inertia::render('RecurringExpenses/Index', [
            'plantillas' => $plantillas,
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $this->categoriasEgreso($teamId),
        ]);
    }

    public function create(): Response
    {
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('RecurringExpenses/Create', [
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $this->categoriasEgreso($teamId),
        ]);
    }

    public function store(EgresoRecurrenteRequest $request, RecurrenceCalculator $calc): RedirectResponse
    {
        $data = $request->validated();

        EgresoRecurrente::create($data + [
            'team_id' => auth()->user()->current_team_id,
            'user_id' => auth()->id(),
            'pagos_generados' => 0,
            'activo' => $request->boolean('activo'),
            'proxima_generacion' => $calc->firstOccurrence(
                Carbon::parse($data['fecha_inicio']),
                (int) $data['dia_del_mes'],
                $data['frecuencia'],
            )->toDateString(),
        ]);

        return redirect()->route('recurring-expenses.index')->with('success', 'Plantilla recurrente creada exitosamente.');
    }

    public function edit(EgresoRecurrente $recurringExpense): Response
    {
        $this->ensureOwnTeam($recurringExpense);
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('RecurringExpenses/Create', [
            'plantilla' => $recurringExpense->load(['empresa:id,nombre', 'categoria:id,nombre']),
            'empresas' => $this->empresasActivas($teamId),
            'categorias' => $this->categoriasEgreso($teamId),
        ]);
    }

    public function update(EgresoRecurrenteRequest $request, EgresoRecurrente $recurringExpense, RecurrenceCalculator $calc): RedirectResponse
    {
        $this->ensureOwnTeam($recurringExpense);

        $data = $request->validated();
        $data['activo'] = $request->boolean('activo');

        // Si aún no ha generado ningún egreso, recalcular la próxima generación según el nuevo schedule.
        if ($recurringExpense->pagos_generados === 0) {
            $data['proxima_generacion'] = $calc->firstOccurrence(
                Carbon::parse($data['fecha_inicio']),
                (int) $data['dia_del_mes'],
                $data['frecuencia'],
            )->toDateString();
        }

        $recurringExpense->update($data);

        return redirect()->route('recurring-expenses.index')->with('success', 'Plantilla recurrente actualizada exitosamente.');
    }

    public function destroy(EgresoRecurrente $recurringExpense): RedirectResponse
    {
        $this->ensureOwnTeam($recurringExpense);

        $recurringExpense->delete();

        return back()->with('success', 'Plantilla recurrente eliminada.');
    }

    private function ensureOwnTeam(EgresoRecurrente $plantilla): void
    {
        if ($plantilla->team_id !== auth()->user()->current_team_id) {
            abort(403);
        }
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
            ->get(['id', 'nombre']);
    }
}
