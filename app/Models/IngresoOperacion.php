<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IngresoOperacion extends Model
{
    use HasFactory;

    protected $table = 'ingreso_operacion';

    protected $fillable = [
        'ingreso_id',
        'operacion_id',
        'operacion_type',
        'monto_cfdi',
        'monto_gpc',
        'referencia',
        'anticipo'
    ];
}
