<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PagoDeDerecho extends Model
{
    use HasFactory;

    // Definimos la tabla explícitamente (buena práctica)
    protected $table = 'auditorias_pdd';

    // Campos que permitimos llenar masivamente
    protected $fillable = [
        'operacion_id',
        'llave_pago',
        'numero_operacion',
        'monto_total',
        'fecha_pago',
        'ruta_pdf',
        'tipo', // <-- ¡La columna que tú sugeriste!
        'estado_sader',
    ];

    /**
     * Un Pago de Derecho pertenece a una Operacion.
     */
    public function operacion()
    {
        return $this->belongsTo(Operacion::class);
    }
}
