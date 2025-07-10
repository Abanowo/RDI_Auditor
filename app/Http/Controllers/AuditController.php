<?php

namespace App\Http\Controllers;

use App\Models\Importacion; // <-- CAMBIO CLAVE: Usamos Importacion como base
use App\Models\Pedimento;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Exports\AuditoriaFacturadoExport;
use Maatwebsite\Excel\Facades\Excel;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $query = $this->obtenerQueryFiltrado($request);

            // Ahora, cargamos las relaciones que necesitamos para la transformación
            // La ruta es más larga, pero es la forma correcta: pedimento -> importacion -> auditorias/totalSc
            $resultados = $query->with(
            [
                'importacion.auditorias',
                'importacion.auditoriasTotalSc',
                'importacion.cliente', // Asumiendo que tienes una relación 'cliente' en el modelo Importacion
                'importacion.getSucursal'     // Asumiendo que tienes una relación 'sucursal' en el modelo Importacion
            ])
                ->latest() // Ordena por el 'created_at' de operaciones_importacion
                ->paginate(15)
                ->withQueryString();

            // La transformación ahora recibe un objeto 'Pedimento'
            $resultados->getCollection()->transform(function ($pedimento) {
                return $this->transformarOperacion($pedimento);
            });

            return response()->json($resultados);
        }

    }
    //Para no estar escribiendo todo el filtrado en cada parte que lo ocupe, hago la construccion del query junto con todos sus filtros
    //y lo devuelvo de aqui hacia a donde se ocupe.
    private function obtenerQueryFiltrado(Request $request) : Builder
    {
        $filters = $request->query();
        // Asumimos que este código está dentro de un método que recibe los filtros,
        // como el index() de tu AuditController o el query() de tu clase de Export.

        // --- LÓGICA DE FILTROS RECONSTRUIDA ---

        // 1. La consulta ahora empieza desde el modelo Pedimento.
        $query = Pedimento::query();

        // 2. ¡FILTRO CLAVE! Nos aseguramos de que el pedimento esté vinculado a una operación de importación
        //    y que esa operación tenga al menos una auditoría o una SC.
        //    Esto resuelve el problema de traer pedimentos "vacíos".
        $query->whereHas('importacion', function ($q) {
            $q->where(function ($subQ) {
                $subQ->whereHas('auditorias')->orWhereHas('auditoriasTotalSC');
            });
        });

        // --- APLICAMOS LOS FILTROS DEL USUARIO ---

        // SECCIÓN 1: Identificadores Universales
        // Filtro por Número de Pedimento (ahora es un 'where' directo)
        $query->when($filters['pedimento'] ?? null, function ($q, $val) {
            return $q->where('num_pedimiento', 'like', "%{$val}%");
        });


        // SECCIÓN 2 y 3: Folio y Estado (ahora se anidan dentro de la relación 'importacion')
        $query->whereHas('importacion', function ($q) use ($filters) {

            // Filtro por Folio
            $q->when($filters['folio'] ?? null, function ($q, $folio) use ($filters) {
                return $q->where(function ($query) use ($folio, $filters) {
                    $query->whereHas('auditorias', function ($subQuery) use ($folio, $filters) {
                        $subQuery->where('folio', 'like', "%{$folio}%");
                        $subQuery->when($filters['folio_tipo_documento'] ?? null, function ($q_inner, $tipo) {
                            return $q_inner->where('tipo_documento', $tipo);
                        });
                    })
                    ->orWhereHas('auditoriasTotalSC', function($subQuery) { $subQuery->where('folio_documento', 'like', "%{$folio}%"); });
                });
            });

            // Filtro por Estado
            $q->when($filters['estado'] ?? null, function ($q, $estado) use ($filters) {
                $tipo_documento = $filters['estado_tipo_documento'] ?? null;
                if ($estado === 'SC Encontrada') {
                    $q->whereHas('auditoriasTotalSC');
                    if ($tipo_documento) {
                        $q->whereHas('auditorias', function ($subQ){ $subQ->where('tipo_documento', $tipo_documento); });
                    }
                    return $q;
                }
                return $q->whereHas('auditorias', function ($subQuery) use ($estado, $tipo_documento) {
                    $subQuery->where('estado', $estado);
                    if ($tipo_documento) {
                        $subQuery->where('tipo_documento', $tipo_documento);
                    }
                });
            });
        });

        // SECCIÓN 4: Periodo de Fecha (también se anida)
        $query->when($filters['fecha_inicio'] ?? null, function ($q) use ($filters) {
            $hasActiveFilters = !empty(array_filter($filters));
            if (!$hasActiveFilters) {
                $filters['fecha_inicio'] = now()->addMonths(-1)->toDateString();
                $filters['fecha_fin'] = now()->toDateString();
            }

            $fecha_inicio = $filters['fecha_inicio'];
            $fecha_fin = $filters['fecha_fin'] ?? $fecha_inicio;

            $tipo_documento = $filters['fecha_tipo_documento'] ?? null;

            return $q->whereHas('importacion.auditorias', function ($subQuery) use ($fecha_inicio, $fecha_fin, $tipo_documento) {
                $subQuery->whereBetween('fecha_documento', [$fecha_inicio, $fecha_fin]);
                if ($tipo_documento) {
                    $subQuery->where('tipo_documento', $tipo_documento);
                }
            });
        });

        return $query;

    }
    //Metodo para mapear lo que se mostrara en la pagina
    private function transformarOperacion($pedimento)
    {
        // Los datos ahora vienen de las relaciones del modelo Importacion
        $auditorias = $pedimento->importacion->auditorias;
        $sc = $pedimento->importacion->auditoriasTotalSC;

        $status_botones = [];

        // Lógica para el botón de la SC (sin cambios)
        $status_botones['sc']['estado'] = $sc ? 'verde' : 'gris';
        $status_botones['sc']['datos'] = $sc;

        // Lógica para las demás facturas (sin cambios)
        $tipos_a_auditar = ['impuestos', 'flete', 'llc', 'pago_derecho'];
        foreach ($tipos_a_auditar as $tipo) {
            $facturas = $auditorias->where('tipo_documento', $tipo);

            if ($facturas->isEmpty()) {
                $status_botones[$tipo]['estado'] = 'gris';
                $status_botones[$tipo]['datos'] = null;

            } else {
                $facturaPrincipal = $facturas->first();
                $status_botones[$tipo]['estado'] = 'verde';
                $status_botones[$tipo]['datos'] = $facturas->count() > 1 ? $facturas->values()->all() : $facturaPrincipal;

                if (str_contains(optional($facturaPrincipal)->ruta_pdf, 'No encontrado')) {
                    $status_botones[$tipo]['estado'] = 'rojo';

                }
            }
        }

        // Devolvemos el JSON en el formato que el frontend espera
        return
        [
            'id'             => $pedimento->importacion->id_importacion, // Usamos el ID de importación
            'pedimento'      => $pedimento->num_pedimiento, // Obtenemos el número de la relación
            'cliente'        => $pedimento->importacion->cliente->nombre,
            'cliente_id'     => $pedimento->importacion->cliente->id,
            'fecha_edc'      => optional($auditorias->where('tipo_documento', 'impuestos')->first())->fecha_documento,
            'status_botones' => $status_botones,
        ];
    }

    //Metodo para exportar las auditorias a un archivo de Excel
    public function exportarFacturado(Request $request)
    {   //$request contiene todos los filtros
        $query = $this->obtenerQueryFiltrado($request);

        // Ahora, cargamos las relaciones que necesitamos para la transformación
        // La ruta es más larga, pero es la forma correcta: pedimento -> importacion -> auditorias/totalSc
        $resultados = $query->with(
        [
            'importacion.auditorias',
            'importacion.auditoriasTotalSc',
            'importacion.cliente', // Asumiendo que tienes una relación 'cliente' en el modelo Importacion
            'importacion.getSucursal'     // Asumiendo que tienes una relación 'sucursal' en el modelo Importacion
        ])->get();

        // Creamos el nombre del archivo dinámicamente
        $fecha = now()->format('dmY');
        $fileName = "RDI_NOG{$fecha}.xlsx";

        // Le pasamos la responsabilidad a nuestra clase de exportación
        return Excel::download(new AuditoriaFacturadoExport($resultados), $fileName);
    }

}
