<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Flete extends Model
{
    use HasFactory;

    /**
     * El nombre de la tabla asociada con el modelo.
     * @var string
     */
    protected $table = 'auditorias_fletes';

    /**
     * Los atributos que no están protegidos contra la asignación masiva.
     * Esto es necesario para que métodos como ::create() funcionen.
     * @var array
     */
    protected $guarded = [];

    /**
     * Define la relación inversa: Un Flete pertenece a una (belongsTo) Operacion.
     */
    public function operacion()
    {
        return $this->belongsTo(Operacion::class);
    }
}
