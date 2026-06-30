<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Egreso extends Model
{
    use \App\Models\Traits\TeamOwned;
    use HasFactory;

    protected $table = 'egresos';

    protected $fillable = [
        'team_id',
        'empresa_id',
        'categoria_id',
        'egreso_recurrente_id',
        'fecha',
        'monto',
        'descripcion',
        'proveedor',
        'metodo_pago',
        'comprobante_path',
        'origen',
        'user_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

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

    public function egresoRecurrente()
    {
        return $this->belongsTo(EgresoRecurrente::class);
    }
}
