<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplementoPago extends Model
{
    protected $table = 'complemento_pago';

    protected $fillable = [
        'ingreso_conciliado_id',
        'request_id',
        'serie',
        'folio_factura',
        'folio',
        'fecha'
    ];
}