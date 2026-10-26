<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seccion extends Model
{
    protected $table = 'cotizacion_secciones';

    protected $fillable = ['nombre', 'titulo_pdf', 'color', 'aplica_iva', 'orden', 'activo'];

    protected $casts = [
        'aplica_iva' => 'boolean',
        'activo'     => 'boolean',
        'orden'      => 'integer',
    ];

    public function catalogo()
    {
        return $this->hasMany(ConceptoCatalogo::class, 'seccion_id')
            ->where('activo', true)
            ->orderBy('codigo');
    }

    public function scopeActivas($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }
}
