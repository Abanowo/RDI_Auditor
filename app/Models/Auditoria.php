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
     * El método "booted" del modelo.
     * Se ejecuta automáticamente cuando el modelo es inicializado.
     *
     * @return void
     */
    protected static function booted()
    {
        // Define un evento que se dispara JUSTO ANTES de crear un nuevo registro.
        static::creating(function ($model) {
            // Si el modelo aún no tiene un UUID, se lo asignamos.
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
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
        'tipo_documento',
        'folio',
        'fecha_documento',
        'monto_total_documento',
        'moneda_documento',
        'monto_total_mxn',
        'desglose_conceptos',
        'llave_pago_pdd',
        'ruta_pdf',
        'ruta_txt',
        'ruta_xml',
    ];

    /**
     * Define la relación inversa de uno a muchos.
     * Un registro de Auditoria pertenece a una Operacion.
     */
    public function operacion()
    {
        return $this->belongsTo(Operacion::class, 'operacion_id');
    }
}
