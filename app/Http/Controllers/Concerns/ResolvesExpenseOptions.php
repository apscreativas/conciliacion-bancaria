<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Categoria;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Collection;

/**
 * Opciones de captura compartidas por los módulos de egresos (manuales y recurrentes):
 * empresas activas del team y categorías de tipo egreso. Centraliza el scoping por team
 * para que los dropdowns de ambos CRUD no diverjan.
 */
trait ResolvesExpenseOptions
{
    protected function empresasActivas(int $teamId): Collection
    {
        return Empresa::where('team_id', $teamId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'color']);
    }

    protected function categoriasEgreso(int $teamId): Collection
    {
        return Categoria::where('team_id', $teamId)
            ->where('activo', true)
            ->where('tipo', 'egreso')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'grupo']);
    }
}
