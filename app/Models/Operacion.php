<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operacion extends Model
{
    use HasFactory;

    /**
     * El nombre de la tabla asociada con el modelo.
     * Esto le dice a Eloquent que busque 'operaciones' en lugar de 'operacions'.
     *
     * @var string
     */
    protected $table = 'operaciones'; // <--- ¡AÑADE ESTA LÍNEA!

    /**
     * Los atributos que no están protegidos contra la asignación masiva.
     * Poner un array vacío significa que confiamos en todos nuestros campos.
     * Es necesario para que updateOrCreate() funcione.
     *
     * @var array
     */
    protected $guarded = []; // <--- ¡AÑADE TAMBIÉN ESTA LÍNEA!


    // --- Aquí van las relaciones que ya definimos ---
    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * Define la relación: Una Operacion tiene una (hasOne) factura de Flete.
     */
    public function flete()
    {
        return $this->hasOne(Flete::class);
    }

    /**
     * Define la relación: Una Operacion tiene una (hasOne) auditoría de SC.
     */
    public function auditoriaSc()
    {
        return $this->hasOne(AuditoriaSc::class);
    }

     /**
     * Define la relación: Una Operacion tiene una (hasOne) factura LLC.
     */
    public function llc()
    {
        return $this->hasOne(Llc::class);
    }

    public function pagosDeDerecho()
    {
        return $this->hasMany(PagoDeDerecho::class, 'operacion_id');
    }
}
