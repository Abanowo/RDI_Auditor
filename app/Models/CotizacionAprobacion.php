<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aprobación interna de un área (Dirección, Desarrollo de negocios, Contabilidad).
 */
class CotizacionAprobacion extends Model
{
    protected $table = 'cotizacion_aprobaciones';

    protected $fillable = ['cotizacion_id', 'area', 'estado', 'usuario_id', 'usuario_nombre', 'comentario', 'respondida_at'];

    protected $casts = ['respondida_at' => 'datetime'];

    public function cotizacion()
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }

    public function nombreArea()
    {
        return (string) config('cotizador.aprobaciones_internas.' . $this->area . '.nombre', $this->area);
    }
}
