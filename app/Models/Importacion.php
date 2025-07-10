<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Importacion extends Model
{
    use HasFactory;

    protected $table = 'operaciones_importacion';
    protected $primaryKey = 'id_importacion';

    public function pedimento()
    {
        return $this->belongsTo(Pedimento::class, 'id_pedimiento');
    }

    public function cliente()
    {
        return $this->belongsTo(Empresas::class, 'id_cliente');
    }

    public function auditorias()
    {
        return $this->hasMany(Auditoria::class, 'operacion_id');
    }

    public function auditoriasTotalSC()
    {
          // Le decimos: la llave foránea en 'auditorias_totales_sc' es 'operacion_id'
          // y se conecta con la llave local 'id' de esta tabla ('operaciones').
          return $this->hasOne(AuditoriaTotalSC::class, 'operacion_id', 'id_importacion');
    }

    public function getSucursal()
    {
        return $this->belongsTo(Sucursales::class, "sucursal");
    }

}
