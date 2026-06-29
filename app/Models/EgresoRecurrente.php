<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EgresoRecurrente extends Model
{
    use \App\Models\Traits\TeamOwned;
    use HasFactory;

    protected $table = 'egresos_recurrentes';

    protected $fillable = [
        'team_id',
        'empresa_id',
        'categoria_id',
        'descripcion',
        'proveedor',
        'monto',
        'frecuencia',
        'dia_del_mes',
        'ajuste_dia_habil',
        'fecha_inicio',
        'vigencia_tipo',
        'fecha_fin',
        'num_pagos',
        'pagos_generados',
        'activo',
        'proxima_generacion',
        'user_id',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'proxima_generacion' => 'date',
        'dia_del_mes' => 'integer',
        'num_pagos' => 'integer',
        'pagos_generados' => 'integer',
        'activo' => 'boolean',
    ];

    /** Plantillas que tocan generar hoy. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('activo', true)->whereDate('proxima_generacion', '<=', now()->toDateString());
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function egresos()
    {
        return $this->hasMany(Egreso::class);
    }
}
