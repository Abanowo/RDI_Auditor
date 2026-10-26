<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConceptoCatalogo extends Model
{
    protected $table = 'conceptos_catalogo';

    protected $fillable = [
        'seccion_id', 'codigo', 'descripcion', 'precio_usd', 'precio_mxn', 'descripcion_doc', 'activo',
    ];

    protected $casts = [
        'precio_usd' => 'float',
        'precio_mxn' => 'float',
        'activo'     => 'boolean',
    ];

    public function seccion()
    {
        return $this->belongsTo(Seccion::class, 'seccion_id');
    }
}
