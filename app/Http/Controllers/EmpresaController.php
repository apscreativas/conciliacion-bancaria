<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmpresaController extends Controller
{
    public function index()
    {
        $empresas = Empresa::where('team_id', auth()->user()->current_team_id)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Settings/Empresas/Index', [
            'empresas' => $empresas,
        ]);
    }

    public function create()
    {
        $this->ensureOwner();

        return Inertia::render('Settings/Empresas/Create');
    }

    public function store(Request $request)
    {
        $this->ensureOwner();

        $data = $this->validateData($request);

        Empresa::create([
            'team_id' => auth()->user()->current_team_id,
            'nombre' => $data['nombre'],
            'slug' => Str::slug($data['nombre']),
            'color' => $data['color'] ?? null,
            'activo' => $data['activo'] ?? true,
            'orden' => $data['orden'] ?? 0,
        ]);

        return redirect()->route('settings.empresas.index')->with('success', 'Empresa creada exitosamente.');
    }

    public function edit(Empresa $empresa)
    {
        $this->ensureOwner();

        return Inertia::render('Settings/Empresas/Create', [
            'empresa' => $empresa,
        ]);
    }

    public function update(Request $request, Empresa $empresa)
    {
        $this->ensureOwner();

        $data = $this->validateData($request, $empresa->id);

        $empresa->update([
            'nombre' => $data['nombre'],
            'slug' => Str::slug($data['nombre']),
            'color' => $data['color'] ?? null,
            'activo' => $data['activo'] ?? true,
            'orden' => $data['orden'] ?? 0,
        ]);

        return redirect()->route('settings.empresas.index')->with('success', 'Empresa actualizada exitosamente.');
    }

    public function destroy(Empresa $empresa)
    {
        $this->ensureOwner();

        $empresa->delete();

        return back()->with('success', 'Empresa eliminada.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $teamId = auth()->user()->current_team_id;

        return $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('empresas')->ignore($ignoreId)->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'color' => ['nullable', 'string', 'max:20'],
            'activo' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * Solo el dueño del team puede administrar el catálogo (no hay sistema de roles).
     */
    private function ensureOwner(): void
    {
        $user = auth()->user();

        if ($user->id !== $user->currentTeam->user_id) {
            abort(403, 'Solo el propietario del equipo puede administrar las empresas.');
        }
    }
}
