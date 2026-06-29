<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    use \App\Models\Traits\TeamOwned;
    use HasFactory;

    protected $table = 'categorias';

    protected $fillable = [
        'team_id',
        'nombre',
        'tipo',
        'grupo',
        'naturaleza',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];
}
