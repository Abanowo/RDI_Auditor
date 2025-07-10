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
        'pedimentos_no_procesados' => 'array',
        'rutas_extras' => 'array',
    ];

    protected $fillable = [
        'banco',
        'sucursal',
        'nombre_archivo',
        'ruta_estado_de_cuenta',
        'rutas_extras',
        'pedimentos_no_procesados',
        'pedimentos_procesados',
        'status',
        'resultado',
    ];
}
