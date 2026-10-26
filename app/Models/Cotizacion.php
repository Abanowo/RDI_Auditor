<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cotizacion extends Model
{
    use SoftDeletes;

    protected $table = 'cotizaciones';

    protected $fillable = [
        'empresa_id', 'cliente', 'contacto', 'fecha', 'valida_hasta', 'puerto', 'tipo_operacion',
        'producto', 'vendedor', 'tipo_cambio', 'aprobada',
    ];

    protected $casts = [
        'fecha'        => 'date',
        'valida_hasta' => 'date',
        'aprobada'     => 'boolean',
        'aprobada_at'  => 'datetime',
        'enviada_at'   => 'datetime',
        'tipo_cambio'  => 'float',
        'total_mxn'    => 'float',
    ];

    protected static function booted()
    {
        // Folio consecutivo: COT-2026-0001
        static::created(function (Cotizacion $cotizacion) {
            $cotizacion->folio = sprintf(
                'COT-%s-%04d',
                $cotizacion->fecha ? $cotizacion->fecha->format('Y') : date('Y'),
                $cotizacion->id
            );
            // Actualización directa para no disparar de nuevo los eventos del modelo
            static::withTrashed()->whereKey($cotizacion->id)->update(['folio' => $cotizacion->folio]);
            $cotizacion->syncOriginalAttribute('folio');
        });
    }

    public function conceptos()
    {
        return $this->hasMany(CotizacionConcepto::class, 'cotizacion_id')->orderBy('orden');
    }

    public function terminos()
    {
        return $this->hasMany(CotizacionTermino::class, 'cotizacion_id')->orderBy('orden');
    }

    public function usuario()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * "15 DE JUNIO DEL 2026"
     */
    public static function fechaLarga($fecha)
    {
        if (!$fecha) {
            return '';
        }

        $texto = Carbon::parse($fecha)->locale('es')->isoFormat('D [de] MMMM [del] YYYY');

        return mb_strtoupper($texto, 'UTF-8');
    }

    public function getTipoOperacionTextoAttribute()
    {
        return $this->tipo_operacion === 'IMPORTACION' ? 'IMPORTACIÓN' : 'EXPORTACIÓN';
    }

    public function nombreArchivo()
    {
        $base = $this->folio ?: 'cotizacion-borrador';

        return $base . '-' . \Illuminate\Support\Str::slug($this->cliente ?: 'cliente') . '.pdf';
    }
}
