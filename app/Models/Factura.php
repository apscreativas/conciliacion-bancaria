<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Factura extends Model
{
    use \App\Models\Traits\HasCreator;
    use \App\Models\Traits\TeamOwned;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'user_id',
        'team_id',
        'file_id_xml',
        'uuid',
        'tipo_comprobante',
        'metodo_pago',
        'monto',
        'fecha_emision',
        'rfc',
        'nombre',
        'verificado',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'monto' => 'decimal:2',
        'verificado' => 'boolean',
    ];

    public function archivoXml()
    {
        return $this->belongsTo(Archivo::class, 'file_id_xml');
    }

    public function conciliaciones()
    {
        return $this->hasMany(Conciliacion::class);
    }
}
