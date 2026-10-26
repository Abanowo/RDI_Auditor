<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CotizacionConcepto extends Model
{
    protected $table = 'cotizacion_conceptos';

    protected $fillable = [
        'cotizacion_id', 'seccion_id', 'codigo', 'descripcion', 'cantidad',
        'precio_usd', 'precio_mxn', 'importe_mxn', 'descripcion_doc', 'orden',
    ];

    protected $casts = [
        'cantidad'    => 'float',
        'precio_usd'  => 'float',
        'precio_mxn'  => 'float',
        'importe_mxn' => 'float',
        'orden'       => 'integer',
    ];

    public function seccion()
    {
        return $this->belongsTo(Seccion::class, 'seccion_id');
    }

    public function esDolares()
    {
        return (float) $this->precio_usd > 0;
    }

    /**
     * Tarifa unitaria tal como se imprime en el PDF: "$50.00 Dlls" / "$53.45 mxn"
     */
    public function tarifaTexto()
    {
        if ($this->esDolares()) {
            return '$' . number_format($this->precio_usd, 2) . ' Dlls';
        }

        return '$' . number_format($this->precio_mxn, 2) . ' mxn';
    }
}
