<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmpresaRequest;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    public function index(): Response
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

    public function create(): Response
    {
        $this->authorize('create', Empresa::class);

        return Inertia::render('Settings/Companies/Create');
    }

    public function store(EmpresaRequest $request): RedirectResponse
    {
        Empresa::create($request->validated() + ['team_id' => auth()->user()->current_team_id]);

        return redirect()->route('settings.companies.index')->with('success', 'Empresa creada exitosamente.');
    }

    public function edit(Empresa $company): Response
    {
        $this->authorize('update', $company);

        return Inertia::render('Settings/Companies/Create', [
            'empresa' => $company,
        ]);
    }

    public function update(EmpresaRequest $request, Empresa $company): RedirectResponse
    {
        $company->update($request->validated());

        return redirect()->route('settings.companies.index')->with('success', 'Empresa actualizada exitosamente.');
    }

    public function destroy(Empresa $company): RedirectResponse
    {
        $this->authorize('delete', $company);

        $company->delete();

        return back()->with('success', 'Empresa eliminada.');
    }
}
