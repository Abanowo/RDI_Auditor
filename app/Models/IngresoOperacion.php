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
        'anticipo',
        'impuestos',
        'eci',
        'maniobras',
        'flete',
        'muestras',
        'llc',
        'garantias',
        'desglose_naviera',
        'pago_proveedor',
        'ganancia',
        'proveedor_maniobras',
        'factura_maniobras',
        'proveedor_flete',
        'factura_flete',
        'proveedor_muestras',
        'factura_muestras',
        'proveedor_llc',
        'factura_llc'
    ];

    public function ingreso()
    {
        return $this->belongsTo(IngresoConciliado::class, 'ingreso_id');
    }

    public function operacionable()
    {
        return $this->morphTo('operacionable', 'operacion_type', 'operacion_id');
    }
}