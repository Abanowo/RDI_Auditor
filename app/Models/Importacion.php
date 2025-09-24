<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        return $this->morphMany(Auditoria::class, 'operacion', 'operation_type', 'operacion_id');
    }

    /**
     * ¡NUEVA RELACIÓN INTELIGENTE!
     * Obtiene solo la auditoría más reciente para cada tipo de documento
     * asociada a ESTA importación específica.
     */
    public function auditoriasRecientes()
    {
        // Subconsulta para encontrar la fecha máxima de cada tipo de documento
        // para esta operación específica.
        $maxAuditsSubquery = DB::table('auditorias')
            ->select(
                'tipo_documento',
                DB::raw('MAX(updated_at) as max_updated_at')
            )
            ->where('operacion_id', $this->id_importacion) // Filtra por el ID de esta importación
            ->where('operation_type', self::class) // Y por su tipo de modelo
            ->groupBy('tipo_documento');

        // Unimos la relación base con la subconsulta para obtener solo las filas más recientes.
        return $this->morphMany(Auditoria::class, 'operacion', 'operation_type', 'operacion_id')
            ->joinSub($maxAuditsSubquery, 'max_audits', function ($join) {
                $join->on('auditorias.tipo_documento', '=', 'max_audits.tipo_documento')
                     ->on('auditorias.updated_at', '=', 'max_audits.max_updated_at');
            });
    }

    public function auditoriasTotalSC()
    {
        // Le decimos: la llave foránea en 'auditorias_totales_sc' es 'operacion_id'
        // y se conecta con la llave local 'id' de esta tabla ('operaciones').
        return $this->morphOne(AuditoriaTotalSC::class, 'operacion', 'operation_type', 'operacion_id');
    }

    public function getSucursal()
    {
        return $this->belongsTo(Sucursales::class, "sucursal");
    }

}
