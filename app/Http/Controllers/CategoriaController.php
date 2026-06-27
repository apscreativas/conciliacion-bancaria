<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CategoriaController extends Controller
{
    public function index()
    {
        $categorias = Categoria::where('team_id', auth()->user()->current_team_id)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Settings/Categorias/Index', [
            'categorias' => $categorias,
        ]);
    }

    public function create()
    {
        $this->ensureOwner();

        return Inertia::render('Settings/Categorias/Create');
    }

    public function store(Request $request)
    {
        $this->ensureOwner();

        $data = $this->validateData($request);

        Categoria::create([
            'team_id' => auth()->user()->current_team_id,
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'grupo' => $data['grupo'],
            'naturaleza' => $data['naturaleza'] ?? null,
            'activo' => $data['activo'] ?? true,
            'orden' => $data['orden'] ?? 0,
        ]);

        return redirect()->route('settings.categorias.index')->with('success', 'Categoría creada exitosamente.');
    }

    public function edit(Categoria $categoria)
    {
        $this->ensureOwner();

        return Inertia::render('Settings/Categorias/Create', [
            'categoria' => $categoria,
        ]);
    }

    public function update(Request $request, Categoria $categoria)
    {
        $this->ensureOwner();

        $data = $this->validateData($request, $categoria->id);

        $categoria->update([
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'grupo' => $data['grupo'],
            'naturaleza' => $data['naturaleza'] ?? null,
            'activo' => $data['activo'] ?? true,
            'orden' => $data['orden'] ?? 0,
        ]);

        return redirect()->route('settings.categorias.index')->with('success', 'Categoría actualizada exitosamente.');
    }

    public function destroy(Categoria $categoria)
    {
        $this->ensureOwner();

        $categoria->delete();

        return back()->with('success', 'Categoría eliminada.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $teamId = auth()->user()->current_team_id;

        return $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categorias')->ignore($ignoreId)->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'grupo' => ['required', Rule::in(['ingreso', 'costo_venta', 'gasto_operativo', 'abajo_ebitda'])],
            'naturaleza' => ['nullable', Rule::in(['fijo', 'variable'])],
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
            abort(403, 'Solo el propietario del equipo puede administrar las categorías.');
        }
    }
}
