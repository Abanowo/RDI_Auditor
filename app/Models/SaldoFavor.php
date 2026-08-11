<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaldoFavor extends Model
{
    use HasFactory;

    // Vinculamos el modelo a la tabla exacta que creamos en la migración
    protected $table = 'saldos_favor';

    // Permitimos que todos los campos se puedan guardar (protección asignada en el controlador)
    protected $guarded = [];

    public function cliente()
    {
        // Le indicamos que este saldo "pertenece a" una Empresa, usando la llave 'cliente_id'
        return $this->belongsTo(\App\Models\Empresas::class, 'cliente_id', 'id');
    }
}
