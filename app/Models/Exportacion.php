<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Exportacion extends Model
{
    use HasFactory;

    protected $table = 'operaciones_exportacion';
    protected $primaryKey = 'id_exportacion';

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
     * asociada a ESTA exportación específica.
     */
    public function auditoriasRecientes()
    {
        $maxAuditsSubquery = DB::table('auditorias')
            ->select(
                'tipo_documento',
                DB::raw('MAX(updated_at) as max_updated_at')
            )
            ->where('operacion_id', $this->id_exportacion) // Filtra por el ID de esta exportación
            ->where('operation_type', self::class)
            ->groupBy('tipo_documento');

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
