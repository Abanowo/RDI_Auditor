<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditoriaSc extends Model
{
    use HasFactory;

    /**
     * El nombre de la tabla asociada con el modelo.
     * @var string
     */
    protected $table = 'auditorias_sc';

    /**
     * Los atributos que no están protegidos contra la asignación masiva.
     * @var array
     */
    protected $guarded = [];

    /**
     * Define la relación inversa: Una AuditoriaSc pertenece a una (belongsTo) Operacion.
     */
    public function operacion()
    {
        return $this->belongsTo(Operacion::class);
    }
}