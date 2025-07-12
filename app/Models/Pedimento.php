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

    public function exportacion()
    {
        return $this->hasOne(Exportacion::class, 'id_pedimiento', 'id_pedimiento');
    }

     public function auditorias()
    {
         // La llave foránea en 'auditorias' es 'pedimento_id'
    return $this->hasMany(Auditoria::class, 'pedimento_id', 'id_pedimiento');
    }

    public function auditoriasTotalSC()
    {
          // Le decimos: la llave foránea en 'auditorias_totales_sc' es 'operacion_id'
          // y se conecta con la llave local 'id' de esta tabla ('operaciones').
          // La llave foránea en 'auditorias_totales_sc' es 'pedimento_id'
        return $this->hasOne(AuditoriaTotalSC::class, 'pedimento_id', 'id_pedimiento');
    }
}
