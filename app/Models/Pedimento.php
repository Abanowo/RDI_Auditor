<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pedimento extends Model
{
    use HasFactory;
    protected $table = 'pedimiento';
    protected $primaryKey = 'id_pedimiento';

    public function importacion()
    {
        return $this->hasOne(Importacion::class, 'id_pedimiento', 'id_pedimiento');
    }

     public function auditorias()
    {
        return $this->hasMany(Auditoria::class, 'operacion_id');
    }

    public function auditoriasTotalSC()
    {
          // Le decimos: la llave foránea en 'auditorias_totales_sc' es 'operacion_id'
          // y se conecta con la llave local 'id' de esta tabla ('operaciones').
          return $this->hasOne(AuditoriaTotalSC::class, 'operacion_id', 'id');
    }
}
