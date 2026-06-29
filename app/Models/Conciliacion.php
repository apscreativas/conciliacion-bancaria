<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conciliacion extends Model
{
    use \App\Models\Traits\TeamOwned;

    protected $fillable = [
        'team_id',
        'group_id',
        'empresa_id',
        'user_id',
        'factura_id',
        'movimiento_id',
        'monto_aplicado',
        'estatus',
        'tipo',
        'fecha_conciliacion',
    ];

    protected $casts = [
        'monto_aplicado' => 'decimal:2',
        'fecha_conciliacion' => 'datetime',
    ];

    public function factura()
    {
        return $this->belongsTo(Factura::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function movimiento()
    {
        return $this->belongsTo(Movimiento::class);
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}
