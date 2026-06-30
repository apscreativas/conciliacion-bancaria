<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use \App\Models\Traits\TeamOwned;
    use HasFactory;

    protected $table = 'empleados';

    protected $fillable = [
        'team_id',
        'empresa_id',
        'nombre',
        'puesto',
        'fecha_entrada',
        'fecha_baja',
        'salario_fiscal',
        'salario_real',
        'clasificacion',
        'activo',
        'user_id',
    ];

    protected $casts = [
        'fecha_entrada' => 'date',
        'fecha_baja' => 'date',
        'salario_fiscal' => 'decimal:2',
        'salario_real' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
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
