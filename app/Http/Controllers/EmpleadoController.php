<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmpleadoRequest;
use App\Models\Empleado;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EmpleadoController extends Controller
{
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
            'empresas' => $this->empresasActivas($teamId),
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
        $employee->update($request->validated());

        return redirect()->route('employees.index')->with('success', 'Empleado actualizado exitosamente.');
    }

    public function destroy(Empleado $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return back()->with('success', 'Empleado eliminado.');
    }

    private function empresasActivas(int $teamId)
    {
        return Empresa::where('team_id', $teamId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'color']);
    }
}
