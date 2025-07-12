<?php

namespace App\Http\Controllers;

use App\Models\Importacion; // <-- CAMBIO CLAVE: Usamos Importacion como base
use App\Models\Exportacion;
use App\Models\Sucursales;
use App\Models\Empresas;
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
            // Cargamos dinámicamente las relaciones necesarias para ambos tipos de operación
            $resultados = $query->with([
                'importacion' => function ($q) {
                    $q->with(['auditorias', 'auditoriasTotalSc', 'cliente', 'getSucursal']);
                },
                'exportacion' => function ($q) {
                    $q->with(['auditorias', 'auditoriasTotalSc', 'cliente', 'getSucursal']);
                }
            ])
                ->latest('pedimiento.created_at') // Ordena por el 'created_at' de operaciones_importacion
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

        // 2. Filtro por Número de Pedimento (directo sobre la tabla pedimiento)
        $query->when($filters['pedimento'] ?? null, function ($q, $val) {
            return $q->where('num_pedimiento', 'like', "%{$val}%");
        });

        // 3. Contenedor principal de filtros que dependen de la operación (Impo/Expo)
        $query->where(function ($q) use ($filters) {

            // Esta función interna aplica los filtros de factura (folio, estado, fecha)
            // Se define una vez y se reutiliza para Impo y Expo. ¡DRY!
            $filtrosAplicados = function (Builder $subQ) use ($filters) {

                // Filtro por Folio
                $subQ->when($filters['folio'] ?? null, function ($q, $folio) use ($filters) {

                    $tipo = $filters['folio_tipo_documento'] ?? null;

                    $q->where(function ($innerQ) use ($folio, $tipo) {
                        $innerQ->whereHas('auditorias', function ($auditQ) use ($folio, $tipo) {

                            $auditQ->where('folio', 'like', "%{$folio}%");
                            if ($tipo) $auditQ->where('tipo_documento', $tipo);

                        });

                        // Solo busca en SC si no se especificó un tipo de documento o si es 'sc'
                        if (!$tipo || $tipo === 'sc') {
                           $innerQ->orWhereHas('auditoriasTotalSC', function ($scQ) use ($folio) {

                               $scQ->where('folio_documento', 'like', "%{$folio}%");

                           });

                        }
                    });

                });

                // Filtro por Estado
                $subQ->when($filters['estado'] ?? null, function ($q, $estado) use ($filters) {

                    $tipo = $filters['estado_tipo_documento'] ?? null;

                    if ($estado === 'SC Encontrada') {
                        return $q->whereHas('auditoriasTotalSC');
                    }

                    return $q->whereHas('auditorias', function ($auditQ) use ($estado, $tipo) {

                        $auditQ->where('estado', $estado);
                        if ($tipo) $auditQ->where('tipo_documento', $tipo);

                    });
                });

                // Filtro por Periodo de Fecha
                $subQ->when($filters['fecha_inicio'] ?? null, function ($q) use ($filters) {

                    $inicio = $filters['fecha_inicio'];
                    $fin = $filters['fecha_fin'] ?? $inicio;
                    $tipo = $filters['fecha_tipo_documento'] ?? null;

                    return $q->where(function ($innerQ) use ($inicio, $fin, $tipo) {
                        $innerQ->whereHas('auditorias', function ($auditQ) use ($inicio, $fin, $tipo) {

                            $auditQ->whereBetween('fecha_documento', [$inicio, $fin]);
                            if ($tipo) $auditQ->where('tipo_documento', $tipo);

                        })->orWhereHas('auditoriasTotalSC', function ($scQ) use ($inicio, $fin) {

                            $scQ->whereBetween('fecha_documento', [$inicio, $fin]);

                        });
                    });
                });

                // Filtro por Cliente
                $subQ->when($filters['cliente_id'] ?? null, function ($q, $clienteId) {
                    return $q->where('id_cliente', $clienteId);
                });

                // Filtro por Sucursal
                $subQ->when($filters['sucursal_id'] ?? null, function ($q, $sucursalId) {
                     // Solo aplicamos el 'where' si el ID de la sucursal existe
                    // Y NO es la palabra 'todos'.
                    if ($sucursalId && $sucursalId !== 'todos') {
                        return $q->where('sucursal', $sucursalId);
                    }
                    // Si es nulo o es 'todos', no hacemos nada, devolviendo todas las sucursales.
                    return $q;
                });
            };

            // 4. Aplicar filtros según el TIPO DE OPERACIÓN seleccionado
            $operationType = $filters['operation_type'] ?? 'todos';

            if ($operationType === 'importacion') {
                $q->whereHas('importacion', $filtrosAplicados);
            } elseif ($operationType === 'exportacion') {
                $q->whereHas('exportacion', $filtrosAplicados);
            } else { // 'todos' o no especificado
                $q->whereHas('importacion', $filtrosAplicados)
                  ->orWhereHas('exportacion', $filtrosAplicados);
            }
        });

        // 5. Asegurarnos de que el pedimento tenga alguna auditoría asociada para no traer registros vacíos
        $query->has('importacion.auditorias')
              ->orHas('importacion.auditoriasTotalSc')
              ->orHas('exportacion.auditorias')
              ->orHas('exportacion.auditoriasTotalSc');

        return $query;

    }
    //Metodo para mapear lo que se mostrara en la pagina
    private function transformarOperacion($pedimento)
    {
        // Los datos ahora vienen de las relaciones del modelo Importacion
        // Determinamos si el pedimento tiene una operación de importación o exportación cargada
        $operacion = $pedimento->importacion ?? $pedimento->exportacion;

        // Si por alguna razón no hay operación, no devolvemos nada.
        if (!$operacion) {
            return null;
        }

        $auditorias = $operacion->auditorias;
        $sc = $operacion->auditoriasTotalSC->first(); // .first() porque es hasOne

        // El resto de la lógica de transformación permanece igual...
        $status_botones = [];
        $status_botones['sc']['estado'] = $sc ? 'verde' : 'gris';
        $status_botones['sc']['datos'] = $sc;

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

        return [
            'id' => $operacion->getKey(), // ID de importacion o exportacion
            'tipo_operacion' => $operacion instanceof \App\Models\Importacion ? 'Importación' : 'Exportación',
            'pedimento' => $pedimento->num_pedimiento,
            'cliente' => optional($operacion->cliente)->nombre,
            'cliente_id' => optional($operacion->cliente)->id,
            'fecha_edc' => optional($auditorias->where('tipo_documento', 'impuestos')->first())->fecha_documento,
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

    // --- NUEVOS MÉTODOS PARA POBLAR FILTROS ---

    /**
     * Devuelve una lista de todas las sucursales.
     */
    public function getSucursales()
    {
        return response()->json(Sucursales::select('id', 'nombre')->whereIn('id', [1, 2, 3, 4, 5, 11, 12])->get());
    }

     /**
     * Devuelve una lista de todos los clientes (empresas).
     */
    public function getClientes()
    {
        // distinct() y orderBy() para una lista limpia y ordenada
        return response()->json(Empresas::select('id', 'nombre')->distinct()->orderBy('nombre')->get());
    }
}
