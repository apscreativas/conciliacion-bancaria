<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClienteEmpresa extends Model
{
    use \App\Models\Traits\TeamOwned;
    use HasFactory;

    protected $table = 'cliente_empresas';

    protected $fillable = [
        'team_id',
        'rfc',
        'nombre',
        'empresa_id',
        'excluido',
        'veces',
        'ultima_asignacion_at',
        'user_id',
    ];

    protected $casts = [
        'excluido' => 'boolean',
        'veces' => 'integer',
        'ultima_asignacion_at' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
