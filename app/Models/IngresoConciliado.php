<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IngresoConciliado extends Model
{
    use HasFactory;

    protected $table = 'ingresos_conciliados';

    protected $fillable = [
        'sucursal_origen', 
        'banco_receptor', 
        'fecha', 
        'cliente_id', 
        'referencia',
        'folio_sc',
        /* 'operacion_id',
        'operation_type', */
        'tipo_comprobante', 
        'monto_deposito', 
        'honorarios', 
        'impuestos', 
        'eci',
        'maniobras', 
        'flete', 
        'muestras', 
        'llc', 
        'estado_envio',
        'proveedor_maniobras', 
        'factura_maniobras',
        'proveedor_flete', 
        'factura_flete',
        'proveedor_muestras', 
        'factura_muestras',
        'proveedor_llc', 
        'factura_llc'
    ];

    public function operacion()
    {
        return $this->morphTo('operation', 'operation_type', 'operacion_id');
    }
    public function cliente()
    {
        return $this->belongsTo(Empresas::class, 'cliente_id');
    }
}