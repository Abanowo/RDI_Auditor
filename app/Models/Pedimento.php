<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
    public function auditoriasRecientes()
    {
        // 1. Subconsulta: Encontrar la fecha más reciente para cada grupo.
        // Agrupamos por pedimento_id Y tipo_documento para obtener la fecha máxima
        // de cada combinación única (ej. pedimento 71222-impuestos, 71222-llc, etc.).
        $maxAuditsSubquery = DB::table('auditorias')
            ->select(
                'pedimento_id',
                'tipo_documento',
                DB::raw('MAX(updated_at) as max_updated_at')
            )
            ->where('pedimento_id', $this->id_pedimiento) // Solo para el pedimento actual
            ->groupBy('pedimento_id', 'tipo_documento');

        // 2. Relación Principal: Unimos 'auditorias' con nuestra subconsulta.
        // Esto filtra y nos deja solo con las filas que coinciden exactamente
        // con el pedimento, tipo de documento Y la fecha más reciente.
        return $this->hasMany(Auditoria::class, 'pedimento_id', 'id_pedimiento')
            ->joinSub($maxAuditsSubquery, 'max_audits', function ($join) {
                $join->on('auditorias.pedimento_id', '=', 'max_audits.pedimento_id')
                     ->on('auditorias.tipo_documento', '=', 'max_audits.tipo_documento')
                     ->on('auditorias.updated_at', '=', 'max_audits.max_updated_at');
            });
    }
    public function auditoriasTotalSC()
    {
          // Le decimos: la llave foránea en 'auditorias_totales_sc' es 'operacion_id'
          // y se conecta con la llave local 'id' de esta tabla ('operaciones').
          // La llave foránea en 'auditorias_totales_sc' es 'pedimento_id'
        return $this->hasOne(AuditoriaTotalSC::class, 'pedimento_id', 'id_pedimiento');
    }
}
