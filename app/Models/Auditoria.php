<?php

// app/Models/Auditoria.php

namespace App\Models;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    use HasFactory;

     /**
     * El nombre de la tabla asociada con el modelo.
     * @var string
     */
    protected $table = 'auditorias';

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'desglose_conceptos' => 'array', // ¡Esta es la línea mágica!
    ];

    // También es buena idea definir qué campos se pueden llenar masivamente
    protected $fillable = [
        'operacion_id',
        'pedimento_id',
        'operation_type',
        'tipo_documento',
        'concepto_llave',
        'folio',
        'fecha_documento',
        'monto_total',
        'monto_total_mxn',
        'monto_diferencia_sc',
        'moneda_documento',
        'estado',
        'desglose_conceptos',
        'llave_pago_pdd',
        'num_operacion_pdd',
        'ruta_pdf',
        'ruta_txt',
        'ruta_xml',
    ];

    public function operacion()
    {
        // El nombre 'operacion' debe coincidir con el segundo argumento de morphMany
        // y será el prefijo de las columnas 'operacion_id' y 'operacion_type'.
        return $this->morphTo('operacion', 'operation_type', 'operacion_id');
    }
}
