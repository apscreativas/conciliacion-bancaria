<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    use \App\Models\Traits\TeamOwned;
    use HasFactory;

    protected $table = 'empresas';

    protected $fillable = [
        'team_id',
        'nombre',
        'slug',
        'color',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];
}
