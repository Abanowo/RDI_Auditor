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
        'token_expira_at'        => 'datetime',
        'cambios_solicitados_at' => 'datetime',
        'tipo_cambio'  => 'float',
        'total_mxn'    => 'float',
    ];

    protected static function booted()
    {
        // Folio: COT + sucursal + fecha (DDMMAAAA) + número de cotización del cliente + cliente
        // Ejemplo: COTN26102026001-asimex-global
        // Se recalcula en cada guardado mientras la cotización no se haya enviado al cliente.
        static::saved(function (Cotizacion $cotizacion) {
            if ($cotizacion->enviada_at && $cotizacion->folio) {
                return;
            }
            $folio = $cotizacion->generarFolio();
            if ($folio !== $cotizacion->folio) {
                $cotizacion->folio = $folio;
                // Actualización directa para no disparar de nuevo los eventos del modelo
                static::withTrashed()->whereKey($cotizacion->id)->update(['folio' => $folio]);
                $cotizacion->syncOriginalAttribute('folio');
            }
        });
    }

    /**
     * COT + código de sucursal + DDMMAAAA + consecutivo del cliente (001, 002…) + "-" + cliente.
     * Códigos de sucursal en config('cotizador.folio_sucursal'): N, T, L, MXL, MZ, REX, VER.
     */
    public function generarFolio()
    {
        $clave  = $this->puerto ? app(\App\Services\CatalogoCotizador::class)->claveSucursal($this->puerto) : null;
        $codigo = $clave ? (string) config('cotizador.folio_sucursal.' . $clave, '') : '';
        $fecha  = ($this->fecha ?: now())->format('dmY');

        // Número de cotización del cliente: cuántas cotizaciones tiene hasta esta (incluida)
        $consulta = static::withTrashed()->where('id', '<=', $this->id);
        if ($this->empresa_id) {
            $consulta->where('empresa_id', $this->empresa_id);
        } else {
            $consulta->whereNull('empresa_id')->where('cliente', $this->cliente);
        }
        $numero = str_pad((string) max(1, $consulta->count()), 3, '0', STR_PAD_LEFT);

        $cliente = substr(\Illuminate\Support\Str::slug($this->cliente ?: 'cliente'), 0, 120);
        $base    = 'COT' . $codigo . $fecha . $numero . '-' . $cliente;

        // Si otra cotización ya tiene ese folio (clientes con el mismo nombre), se agrega -2, -3…
        $folio = $base;
        $n     = 2;
        while (static::withTrashed()->where('folio', $folio)->where('id', '<>', $this->id)->exists()) {
            $folio = $base . '-' . $n++;
        }

        return $folio;
    }

    /* ---------- Aprobación por el cliente (enlace del correo) ---------- */

    /**
     * Genera un token nuevo para el enlace de aprobación. Vence al terminar el día
     * de "Válida hasta" (o en 30 días si no tiene vigencia).
     */
    public function generarTokenAprobacion()
    {
        $this->token_aprobacion = \Illuminate\Support\Str::random(48);
        $this->token_expira_at  = $this->valida_hasta
            ? $this->valida_hasta->copy()->endOfDay()
            : now()->addDays(30);

        return $this->token_aprobacion;
    }

    public function urlAprobacion()
    {
        if (!$this->token_aprobacion) {
            return null;
        }
        // Dirección pública con la que el CLIENTE abre el enlace (no 127.0.0.1 ni localhost)
        $base = rtrim((string) config('cotizador.url_publica'), '/') ?: url('/');

        return $base . '/cotizacion/aprobar/' . $this->token_aprobacion;
    }

    public function tokenVencido()
    {
        return $this->token_expira_at && $this->token_expira_at->isPast();
    }

    /** aprobada | cambios | pendiente */
    public function estadoRespuesta()
    {
        if ($this->aprobada) {
            return 'aprobada';
        }

        return $this->cambios_solicitados_at ? 'cambios' : 'pendiente';
    }

    /* ---------- Aprobaciones internas: Dirección, Desarrollo de negocios, Contabilidad ---------- */

    public function aprobacionesInternas()
    {
        return $this->hasMany(CotizacionAprobacion::class, 'cotizacion_id');
    }

    /**
     * Crea las aprobaciones pendientes de cada área (una vez que el cliente aprobó).
     * Devuelve las que se crearon en esta llamada (para avisar solo a esas áreas).
     */
    public function iniciarAprobacionesInternas()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('cotizacion_aprobaciones')) {
            return collect();
        }

        $nuevas = collect();
        foreach (array_keys((array) config('cotizador.aprobaciones_internas')) as $area) {
            $aprobacion = CotizacionAprobacion::firstOrCreate(
                ['cotizacion_id' => $this->id, 'area' => $area],
                ['estado' => 'pendiente']
            );
            if ($aprobacion->wasRecentlyCreated) {
                $nuevas->push($aprobacion);
            }
        }

        return $nuevas;
    }

    /** sin_iniciar | pendiente | autorizada | rechazada */
    public function estadoInterno()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('cotizacion_aprobaciones')) {
            return 'sin_iniciar';
        }
        $estados = $this->aprobacionesInternas()->pluck('estado');
        if ($estados->isEmpty()) {
            return 'sin_iniciar';
        }
        if ($estados->contains('rechazada')) {
            return 'rechazada';
        }

        return $estados->every(function ($e) { return $e === 'aprobada'; }) ? 'autorizada' : 'pendiente';
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
        // El folio ya incluye el cliente: COTN26102026001-asimex-global.pdf
        if ($this->folio) {
            return $this->folio . '.pdf';
        }

        return 'cotizacion-borrador-' . \Illuminate\Support\Str::slug($this->cliente ?: 'cliente') . '.pdf';
    }
}
