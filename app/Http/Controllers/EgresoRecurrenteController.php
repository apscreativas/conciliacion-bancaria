<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnforcesTeamOwnership;
use App\Http\Controllers\Concerns\ResolvesExpenseOptions;
use App\Http\Requests\EgresoRecurrenteRequest;
use App\Models\EgresoRecurrente;
use App\Services\Finance\RecurrenceCalculator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EgresoRecurrenteController extends Controller
{
    use EnforcesTeamOwnership;
    use ResolvesExpenseOptions;

    public function index(): Response
    {
        $teamId = auth()->user()->current_team_id;

        $plantillas = EgresoRecurrente::where('team_id', $teamId)
            ->with(['empresa:id,nombre,color', 'categoria:id,nombre'])
            ->orderByDesc('activo')
            ->orderBy('proxima_generacion')
            ->paginate(25)
            ->withQueryString();

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

        $reactivando = ! $recurringExpense->activo && $data['activo'];

        if ($recurringExpense->pagos_generados === 0) {
            // Aún no genera nada: recalcular la próxima generación según el nuevo schedule.
            $data['proxima_generacion'] = $calc->firstOccurrence(
                Carbon::parse($data['fecha_inicio']),
                (int) $data['dia_del_mes'],
                $data['frecuencia'],
            )->toDateString();
        } elseif ($reactivando) {
            // Reactivar una plantilla con historial: reanudar desde HOY, no desde su
            // proxima_generacion vencida, para no disparar una avalancha de egresos retroactivos.
            $anchor = Carbon::parse($data['fecha_inicio'])->max(Carbon::today());
            $data['proxima_generacion'] = $calc->firstOccurrence(
                $anchor,
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
}
