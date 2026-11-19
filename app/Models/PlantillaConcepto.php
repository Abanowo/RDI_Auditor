<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlantillaConcepto extends Model
{
    protected $table = 'plantilla_conceptos';

    protected $fillable = [
        'plantilla_id', 'seccion_id', 'codigo', 'clave_sat', 'descripcion', 'cantidad',
        'precio_usd', 'precio_mxn', 'al_costo', 'descripcion_doc', 'nota', 'orden',
    ];

    protected $casts = [
        'cantidad'   => 'float',
        'precio_usd' => 'float',
        'precio_mxn' => 'float',
        'al_costo'   => 'boolean',
        'orden'      => 'integer',
    ];

    public function seccion()
    {
        return $this->belongsTo(Seccion::class, 'seccion_id');
    }
}
