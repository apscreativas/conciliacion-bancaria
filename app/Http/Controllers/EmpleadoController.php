<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesExpenseOptions;
use App\Http\Requests\EmpleadoRequest;
use App\Models\Empleado;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EmpleadoController extends Controller
{
    use ResolvesExpenseOptions;

    public function index(): Response
    {
        $this->authorize('viewAny', Empleado::class);
        $teamId = auth()->user()->current_team_id;

        $empleados = Empleado::where('team_id', $teamId)
            ->with(['empresa:id,nombre,color'])
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Employees/Index', [
            'empleados' => $empleados,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Empleado::class);
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('Employees/Create', [
            'empresas' => $this->empresasActivas($teamId),
        ]);
    }

    public function store(EmpleadoRequest $request): RedirectResponse
    {
        $this->authorize('create', Empleado::class);

        Empleado::create($request->validated() + [
            'team_id' => auth()->user()->current_team_id,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('employees.index')->with('success', 'Empleado creado exitosamente.');
    }

    public function edit(Empleado $employee): Response
    {
        $this->authorize('update', $employee);
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('Employees/Create', [
            'empleado' => $employee->load(['empresa:id,nombre']),
            'empresas' => $this->empresasActivas($teamId),
        ]);
    }

    public function update(EmpleadoRequest $request, Empleado $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $employee->update($request->validated());

        return redirect()->route('employees.index')->with('success', 'Empleado actualizado exitosamente.');
    }

    public function destroy(Empleado $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return back()->with('success', 'Empleado eliminado.');
    }
}
