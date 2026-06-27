<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmpresaRequest;
use App\Models\Empresa;
use Inertia\Inertia;

class EmpresaController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Empresa::class);

        $empresas = Empresa::where('team_id', auth()->user()->current_team_id)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Settings/Companies/Index', [
            'empresas' => $empresas,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Empresa::class);

        return Inertia::render('Settings/Companies/Create');
    }

    public function store(EmpresaRequest $request)
    {
        $this->authorize('create', Empresa::class);

        Empresa::create($request->validated() + ['team_id' => auth()->user()->current_team_id]);

        return redirect()->route('settings.companies.index')->with('success', 'Empresa creada exitosamente.');
    }

    public function edit(Empresa $company)
    {
        $this->authorize('update', $company);

        return Inertia::render('Settings/Companies/Create', [
            'empresa' => $company,
        ]);
    }

    public function update(EmpresaRequest $request, Empresa $company)
    {
        $this->authorize('update', $company);

        $company->update($request->validated());

        return redirect()->route('settings.companies.index')->with('success', 'Empresa actualizada exitosamente.');
    }

    public function destroy(Empresa $company)
    {
        $this->authorize('delete', $company);

        $company->delete();

        return back()->with('success', 'Empresa eliminada.');
    }
}
