<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenPago extends Model
{
    // Forzamos el nombre exacto de la tabla por buenas prácticas
    protected $table = 'ordenes_pago_control_proveedores';

    protected $fillable = [
        'op', 
        'proveedor', 
        'facturas', 
        'subtotal', 
        'iva', 
        'retencion', 
        'total', 
        'fecha', 
        'status'
    ];

    // Casteamos los decimales a float para que Vue no se confunda y el .toFixed() o .toLocaleString() funcionen perfecto
    protected $casts = [
        'subtotal' => 'float',
        'iva' => 'float',
        'retencion' => 'float',
        'total' => 'float',
    ];
}