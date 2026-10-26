<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CotizacionTermino extends Model
{
    protected $table = 'cotizacion_terminos';

    protected $fillable = ['cotizacion_id', 'texto', 'orden'];

    public function cotizacion()
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }
}
