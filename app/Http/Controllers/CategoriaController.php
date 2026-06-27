<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use Inertia\Inertia;

class CategoriaController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Categoria::class);

        $categorias = Categoria::where('team_id', auth()->user()->current_team_id)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Settings/Categories/Index', [
            'categorias' => $categorias,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Categoria::class);

        return Inertia::render('Settings/Categories/Create');
    }

    public function store(CategoriaRequest $request)
    {
        $this->authorize('create', Categoria::class);

        Categoria::create($request->validated() + ['team_id' => auth()->user()->current_team_id]);

        return redirect()->route('settings.categories.index')->with('success', 'Categoría creada exitosamente.');
    }

    public function edit(Categoria $category)
    {
        $this->authorize('update', $category);

        return Inertia::render('Settings/Categories/Create', [
            'categoria' => $category,
        ]);
    }

    public function update(CategoriaRequest $request, Categoria $category)
    {
        $this->authorize('update', $category);

        $category->update($request->validated());

        return redirect()->route('settings.categories.index')->with('success', 'Categoría actualizada exitosamente.');
    }

    public function destroy(Categoria $category)
    {
        $this->authorize('delete', $category);

        $category->delete();

        return back()->with('success', 'Categoría eliminada.');
    }
}
