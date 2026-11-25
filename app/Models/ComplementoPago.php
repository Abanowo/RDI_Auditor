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
        'fecha',
        'timbrado',
        'ruta_pdf',
        'ruta_xml',
        'ruta_txt',
    ];

    protected $casts = [
        'timbrado' => 'boolean',
        'fecha'    => 'datetime',
    ];
}