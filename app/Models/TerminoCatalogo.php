<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Términos y condiciones que se cargan por defecto en cada cotización nueva.
 */
class TerminoCatalogo extends Model
{
    protected $table = 'terminos_catalogo';

    protected $fillable = ['texto', 'orden', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function scopeActivos($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }
}
