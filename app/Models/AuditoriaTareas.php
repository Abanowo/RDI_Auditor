<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditoriaTareas extends Model
{
    use HasFactory;
    protected $table = 'auditorias_tareas';

    protected $casts = [
        'pedimentos_procesados' => 'array',
        'pedimentos_descartados' => 'array',
        'rutas_extras' => 'array',
    ];

    protected $fillable = [
        'banco',
        'sucursal',
        'nombre_archivo',
        'ruta_estado_de_cuenta',
        'rutas_extras',
        'mapeo_completo_facturas',
        'pedimentos_procesados',
        'pedimentos_descartados',
        'ruta_reporte_impuestos',
        'nombre_reporte_impuestos',
        'ruta_reporte_impuestos_pendientes',
        'nombre_reporte_pendientes',
        'status',
        'resultado',
        'fecha_documento',
        'periodo_meses'
    ];
}
