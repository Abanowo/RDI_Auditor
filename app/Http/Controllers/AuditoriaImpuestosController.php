<?php

namespace App\Http\Controllers;

use DateTime;
use App\Exports\AuditoriaFacturadoExport;
use App\Imports\LecturaEstadoCuentaExcel;
use App\Models\Importacion; // <-- CAMBIO CLAVE: Usamos Importacion como base
use App\Models\Exportacion;
use App\Models\Sucursales;
use App\Models\Pedimento;
use App\Models\Empresas;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;
use App\Mail\EnviarReportesAuditoriaMail;
use App\Mail\EnviarFalloReporteAuditoriaMail;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

use Illuminate\Http\File;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config; // Otra forma de acceder
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Artisan;

use Smalot\PdfParser\Parser;
use Maatwebsite\Excel\Facades\Excel;

class AuditoriaImpuestosController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $filters = $request->query();

            $query = $this->obtenerQueryFiltrado($request);
            $query->with([
                'importacion' => function ($q) {
                    $q->with(['auditoriasRecientes', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                },
                'exportacion' => function ($q) {
                    $q->with(['auditoriasRecientes', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                }
            ]);
            // Para depurar:
            // Muestra el SQL generado y los valores a "bindear"


            // Ahora, cargamos las relaciones que necesitamos para la transformación
            // La ruta es más larga, pero es la forma correcta: pedimento -> importacion -> auditorias/totalSc
            // Cargamos dinámicamente las relaciones necesarias para ambos tipos de operación

            // --- INICIA LA LÓGICA DE CONTEO TOTAL ---

            // CLONA la consulta ANTES de paginar para obtener los IDs de TODOS los pedimentos filtrados.
            // pluck() es muy rápido para obtener solo una columna.
            $pedimentoIds = $query->clone()->pluck('pedimiento.id_pedimiento');

            // Ahora, construye una consulta en 'Auditoria' limitada a los pedimentos que encontramos.
            // Esto es mucho más eficiente que cargar todas las relaciones.
            $auditoriaQuery = Auditoria::query()
                ->whereHas('operacion', function ($q) use ($pedimentoIds) {
                    $q->whereIn('id_pedimiento', $pedimentoIds);
                });

            // Define los estados y obtén el conteo para cada uno, clonando la consulta de auditorías.
            $statuses = [
                'pago_mas' => 'Pago de mas!',
                'pago_menos' => 'Pago de menos!',
                'balanceados' => 'Saldados!',
                'no_facturados' => 'Sin SC!',
            ];

            $conteos = [];
            $sumasDiferencias = [];
            foreach ($statuses as $key => $label) {
                if ($key === 'balanceados') {

                    // Para 'balanceados', contamos los PEDIMENTOS "puros".
                    // Un pedimento es "puro" si tiene facturas 'Coinciden!' y NINGUNA con otro estado.
                    $conteos[$key] = $query->clone()->where(function ($q) use ($label) {

                        // La lógica se aplica a la operación (impo o expo) que contenga las auditorías.
                        $purelyBalancedLogic = function ($operationQuery) use ($label) {
                            // 1. Debe tener AL MENOS UNA factura con estado "Coinciden!".
                            $operationQuery->whereHas('auditoriasRecientes', function ($auditQuery) {
                                $auditQuery->where('monto_diferencia_sc', 0);
                            })
                                // 2. Y NO DEBE TENER NINGUNA factura con estado DIFERENTE a "Coinciden!".
                                ->whereDoesntHave('auditoriasRecientes', function ($auditQuery) use ($label) {
                                    //$auditQuery->where('estado', '!=', $label);
                                    $auditQuery->whereNotIn('estado', ['Coinciden!', 'Normal', 'Segundo Pago', 'Medio Pago']);
                                });
                        };

                        // Aplicamos la lógica a la relación de importación O a la de exportación.
                        $q->whereHas('importacion', $purelyBalancedLogic)
                            ->orWhereHas('exportacion', $purelyBalancedLogic);
                    });
                    $sumasDiferencias[$key] = 0;
                    $conteos[$key] = $conteos[$key]->count();
                } else {
                    if ($key === 'no_facturados') {
                        $conteos[$key] = $auditoriaQuery->clone()->where(['estado' => $label, 'tipo_documento' => 'impuestos']);
                    } else {
                        $conteos[$key] = $auditoriaQuery->clone()->where('estado', $label);
                    }
                    $sumasDiferencias[$key] = $conteos[$key]->sum('monto_diferencia_sc');
                    $conteos[$key] = $conteos[$key]->count();
                }
            }

            // Calcula el total y los porcentajes.
            $totalFacturas = array_sum($conteos);
            // Crea el arreglo de estadísticas con el formato que necesitas
            $statsArray = [];
            foreach ($statuses as $key => $label) {
                $valor = $conteos[$key] ?? 0;
                $porcentaje = ($totalFacturas > 0) ? round(($valor / $totalFacturas) * 100, 2) : 0;
                $sumatoriaDiferencia = $sumasDiferencias[$key];
                // Añadimos un nuevo elemento al arreglo (esto crea los índices numéricos 0, 1, 2...)
                $statsArray[] = [
                    'key' => $key,
                    'label' => $label,
                    'value' => $valor,
                    'percentage' => $porcentaje,
                    'delta_sum' => $sumatoriaDiferencia,
                ];
            }

            // Prepara la data final para la respuesta, ahora estructurada
            $conteosData = [
                'stats' => $statsArray,
                'total' => $totalFacturas
            ];

            // --- TERMINA LA LÓGICA DE CONTEO ---


            $resultadosPaginados = $query
                ->latest('pedimiento.created_at')
                ->paginate(15)
                ->withQueryString();


            // La transformación ahora recibe un objeto 'Pedimento'
            $resultadosPaginados->getCollection()->transform(function ($pedimento) use ($filters) {
                return $this->transformarOperacion($pedimento, $filters);
            });
            // Convierte el objeto paginador a un array.
            // Esto nos da la estructura base con 'data', 'links', y 'meta' de la paginación.
            $responseData = $resultadosPaginados->toArray();

            // AÑADE tu data de conteos directamente al array de respuesta.
            // Lo anidamos dentro de la clave 'meta' para mantener todo organizado.
            $responseData['meta']['conteos'] = $conteosData;

            // FINALMENTE: Añade los conteos a los metadatos de la respuesta paginada.
            // Retorna el array completo como una respuesta JSON.
            return response()->json($responseData);
        }
        // Esto es para la primera visita desde el navegador.
        // Le dice a Laravel: "Carga y muestra el archivo de la vista principal".
        return view('welcome'); // O el nombre de tu vista, ej: 'audits.index'
    }


    //Para no estar escribiendo todo el filtrado en cada parte que lo ocupe, hago la construccion del query junto con todos sus filtros
    //y lo devuelvo de aqui hacia a donde se ocupe.
    private function obtenerQueryFiltrado(Request $request): Builder
    {
        $filters = $request->query();
        $operationType = $filters['operation_type'] ?? 'todos';
        // --- PREPARACIÓN INICIAL DE FILTROS ---
        if (!isset($filters['fecha_inicio'])) {
            $filters['fecha_inicio'] = now()->subMonthNoOverflow()->format('Y-m-d H:i:s');
            $filters['fecha_fin'] = now()->format('Y-m-d H:i:s');
        }

        // --- LÓGICA DE PRE-FILTRADO ---
        // Este closure contiene los filtros que se pueden aplicar a las tablas de operaciones
        // ANTES de que se unan, para reducir drásticamente el tamaño del dataset.
        $applyPreFilters = function ($query) use ($filters) {
            $sucursalesDiccionario = [
                1 => 3711, //NOGALES, NOG
                2 => 3849, //TIJUANA, TIJ
                3 => 3711, //LAREDO, NL, LAR, LDO
                4 => 1038, //MEXICALI, MXL
                5 => 3711, //MANZANILLO, ZLO
                11 => 3577, //REYNOSA, NL, LAR, LDO
                12 => 1864, //VERACRUZ, ZLO
            ];
            $sucursalId = $filters['sucursal_id'] ?? null;
            $patenteSucursal = ($sucursalId && $sucursalId !== 'todos') ? ($sucursalesDiccionario[$sucursalId] ?? null) : null;

            $query->when($sucursalId && $sucursalId !== 'todos' && $patenteSucursal, function ($q) use ($sucursalId, $patenteSucursal) {
                return $q->where('sucursal', $sucursalId)
                    ->where('patente', $patenteSucursal);
            });

            $query->when($filters['cliente_id'] ?? null, function ($q, $id) {
                return $q->where('id_cliente', $id);
            });
        };

        // --- CONSTRUCCIÓN DE LA CONSULTA BASE ---
        if ($operationType === 'importacion' || $operationType === 'todos') {
            $importacionesQuery = Importacion::select('id_importacion as operacion_id', 'id_pedimiento', 'created_at', 'id_cliente', 'sucursal', 'patente', DB::raw("'importacion' as operation_type"));
            $applyPreFilters($importacionesQuery);
        }
        if ($operationType === 'exportacion' || $operationType === 'todos') {
            $exportacionesQuery = Exportacion::select('id_exportacion as operacion_id', 'id_pedimiento', 'created_at', 'id_cliente', 'sucursal', 'patente', DB::raw("'exportacion' as operation_type"));
            $applyPreFilters($exportacionesQuery);
        }

        // --- SELECCIÓN DE LA ESTRATEGIA DE CONSULTA ---
        switch ($operationType) {
            case 'importacion':
                $operacionesCombinadas = $importacionesQuery;
                break;
            case 'exportacion':
                $operacionesCombinadas = $exportacionesQuery;
                break;
            default: // 'todos'
                $operacionesCombinadas = $importacionesQuery->unionAll($exportacionesQuery);
                break;
        }

        // El resto de la lógica es la misma, pero ahora opera sobre un conjunto de datos mucho más pequeño.
        $maxFechaSubquery = DB::query()
            ->fromSub($operacionesCombinadas, 'op_all')
            ->select('id_pedimiento', DB::raw('MAX(created_at) as max_created_at'))
            ->whereNotNull('id_pedimiento')
            ->groupBy('id_pedimiento');

        $query = Pedimento::query();

        $query->when($filters['pedimento'] ?? null, function ($q, $val) {
            return $q->where('num_pedimiento', 'like', "%{$val}%");
        });

        $query->joinSub($operacionesCombinadas, 'op_reciente', function ($join) {
            $join->on('pedimiento.id_pedimiento', '=', 'op_reciente.id_pedimiento');
        })
            ->joinSub($maxFechaSubquery, 'op_max_fecha', function ($join) {
                $join->on('op_reciente.id_pedimiento', '=', 'op_max_fecha.id_pedimiento')
                    ->on('op_reciente.created_at', '=', 'op_max_fecha.max_created_at');
            });

        // Paso 4: Definir y aplicar la lógica de filtrado.
        // ¡IMPORTANTE! Los filtros ahora se aplican a la tabla virtual 'op_reciente'.
        $applyRelationshipFilters = function (Builder $q) use ($filters) {
            // --- LÓGICA DE FILTRADO REFACTORIZADA ---
            // Unificamos todos los filtros de documentos en un solo array para procesarlos.
            $documentFilters = [];
            if (!empty($filters['folio'])) {
                $documentFilters['folio'] = ['value' => $filters['folio'], 'type' => $filters['folio_tipo_documento'] ?? 'any'];
            }
            if (!empty($filters['estado'])) {
                $documentFilters['estado'] = ['value' => $filters['estado'], 'type' => $filters['estado_tipo_documento'] ?? 'any'];
            }
            if (!empty($filters['fecha_inicio'])) {
                $documentFilters['fecha'] = ['value' => $filters['fecha_inicio'], 'type' => $filters['fecha_tipo_documento'] ?? 'any'];
            }
            if (!empty($filters['estado_tipo_documento']) && empty($filters['estado'])) {
                $documentFilters['estado'] = ['value' => null, 'type' => $filters['estado_tipo_documento']];
            }

            // Si no hay ningún filtro de documento, no hacemos nada más.
            if (empty($documentFilters)) {
                //$q->whereHas('auditorias');
                //$q->orWhereHas('auditoriasTotalSC');
                return; // Termina la clausura aquí
            }

            // B.1. Agrupamos los filtros por el tipo de documento especificado
            $filtersByType = [];
            foreach ($documentFilters as $key => $data) {
                $filtersByType[$data['type']][$key] = $data['value'];
            }

            // B.2. Aplicamos los filtros agrupados con condiciones AND
            foreach ($filtersByType as $type => $values) {

                // CASO ESPECIAL: El tipo es 'sc'
                if ($type === 'sc') {
                    $q->whereHas('auditoriasTotalSC', function ($scQuery) use ($values, $filters) {

                        if (isset($values['folio'])) {
                            $scQuery->where('folio', 'like', "%{$values['folio']}%");
                        }

                        if (isset($values['fecha'])) {
                            $scQuery->whereBetween('fecha_documento', [$values['fecha'], $filters['fecha_fin'] ?? $values['fecha']]);
                        }
                    });
                } elseif ($type !== 'any') { // CASO: El tipo es específico (ej. 'impuestos', 'flete')

                    $q->whereHas('auditoriasRecientes', function ($auditQuery) use ($values, $type, $filters) {
                        $auditQuery->where('tipo_documento', $type);

                        if (isset($values['estado'])) {
                            $auditQuery->where('estado', $values['estado']);
                        }

                        if (isset($values['folio'])) {
                            $auditQuery->where('folio', 'like', "%{$values['folio']}%");
                        }

                        if (isset($values['fecha'])) {
                            $auditQuery->whereBetween('fecha_documento', [$values['fecha'], $filters['fecha_fin'] ?? $values['fecha']]);
                        }
                    });
                } else { // CASO: El tipo es 'any' (Cualquier Tipo)
                    $q->where(function ($orQuery) use ($values, $filters) {
                        $tieneDeEstadoSaldado = isset($values['estado']) ? $values['estado'] === 'Saldados!' : false;
                        // Este if es para cuando se presiona el boton de "Saldado" en el frontend, y la funcion de esto es que
                        // "Me traiga exclusivamente todos los registros que en ninguna de sus facturas tenga algo distinto a "Coinciden!""
                        // De forma en que solo mostrara registros verdes y correctos en su balance.
                        if ($tieneDeEstadoSaldado) {

                            // 1. Debe tener AL MENOS UNA factura con estado "Coinciden!".
                            $orQuery->orWhereHas('auditoriasRecientes', function ($auditQuery) {
                                $auditQuery->where('monto_diferencia_sc', 0);

                                if (isset($values['folio'])) {
                                    $auditQuery->where('folio', 'like', "%{$values['folio']}%");
                                }

                                if (isset($values['fecha'])) {
                                    $auditQuery->whereBetween('fecha_documento', [$values['fecha'], $filters['fecha_fin'] ?? $values['fecha']]);
                                }
                            })
                                // 2. Y NO DEBE TENER NINGUNA factura con estado DIFERENTE a "Coinciden!".
                                ->whereDoesntHave('auditoriasRecientes', function ($auditQuery) {
                                    //$auditQuery->where('estado', '!=', $label);
                                    $auditQuery->whereNotIn('estado', ['Coinciden!', 'Normal', 'Segundo Pago', 'Medio Pago']);
                                });
                        } else {

                            // Busca en 'auditorias'
                            $orQuery->orWhereHas('auditoriasRecientes', function ($auditQuery) use ($values, $filters) {

                                if (isset($values['estado'])) {
                                    $auditQuery->where('estado', $values['estado']);
                                }

                                if (isset($values['folio'])) {
                                    $auditQuery->where('folio', 'like', "%{$values['folio']}%");
                                }

                                if (isset($values['fecha'])) {
                                    $auditQuery->whereBetween('fecha_documento', [$values['fecha'], $filters['fecha_fin'] ?? $values['fecha']]);
                                }
                                // Nota 23-09-2025 en documento nube: Aca ocurre un comportamiento que es muy improbable que ocurra pero igual le hago mencion, en donde cuando se selecciona
                                // un "Estado" en los filtros, y en el "Tipo estado" lo dejas como cualquiera (any), y lo mismo con "Tipo fecha" (any)
                                // lo que ocurre es que se busquen unicamente los que tengan esa fecha y los que tengan ese estado, ocasionando en que
                                // resulte en que se descarten las auditorias que deberia de mostrarse.
                            });

                            if (!isset($values['estado'])) {

                                // O busca en 'auditoriasTotalSC' (solo para folio y fecha)
                                $orQuery->orWhereHas('auditoriasTotalSC', function ($scQuery) use ($values, $filters) {

                                    if (isset($values['folio'])) {
                                        $scQuery->where('folio', 'like', "%{$values['folio']}%");
                                    }

                                    if (isset($values['fecha'])) {
                                        $scQuery->whereBetween('fecha_documento', [$values['fecha'], $filters['fecha_fin'] ?? $values['fecha']]);
                                    }
                                });
                            } else if ($values['estado'] === 'SC Encontrada') {

                                // O busca en 'auditoriasTotalSC'
                                $orQuery->orWhereHas('auditoriasTotalSC');
                            }
                        }
                    });
                }
            }
        };

        // Aplicamos la closure de filtros a la consulta principal.
        $query->where(function ($subQ) use ($applyRelationshipFilters) {
            $applyRelationshipFilters($subQ);
        });
        return $query;
    }


    /**
     * Metodo para mapear lo que se mostrara en la pagina.
     * Se ha modificado para que en Santander (Manzanillo) la validación de la "SC" 
     * sea positiva si existe el registro de auditoría calculado contra Google Sheets.
     */
    private function transformarOperacion($pedimento, $filters)
    {
        $operationType = $filters['operation_type'] ?? 'todos';
        $operacion = null;

        // Forzar operación según filtro
        if ($operationType === 'importacion') {
            $operacion = $pedimento->importacion;
        } elseif ($operationType === 'exportacion') {
            $operacion = $pedimento->exportacion;
        } else { // 'todos'
            // Si existen ambas, priorizamos importación
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;
        }

        // Caso crítico: no se encontró operación en la base de datos
        if (!$operacion) {
            return [
                'id' => null,
                'tipo_operacion' => 'Sin operación',
                'pedimento' => $pedimento->num_pedimiento,
                'cliente' => null,
                'cliente_id' => null,
                'fecha_edc' => null,
                'status_botones' => [
                    'sc' => ['estado' => 'rojo', 'datos' => null],
                    'impuestos' => ['estado' => 'rojo', 'datos' => null],
                    'flete' => ['estado' => 'rojo', 'datos' => null],
                    'llc' => ['estado' => 'rojo', 'datos' => null],
                    'pago_derecho' => ['estado' => 'rojo', 'datos' => null],
                ],
            ];
        }

        // ✅ Caso normal: existe operación
        $auditorias = $operacion->auditorias;
        $scFisica = $operacion->auditoriasTotalSC;

        // Identificamos si es una operación de Santander/Manzanillo
        // Puede ser por el número de pedimento (ZLO) o por el filtro de banco
        $esSantander = str_contains(strtoupper($pedimento->num_pedimiento), 'ZLO') ||
            (isset($filters['banco']) && $filters['banco'] === 'SANTANDER');

        $status_botones = [];

        // --- VALIDACIÓN DINÁMICA DEL BOTÓN SC ---
        // Para Santander, la "SC" se considera válida si ya se hizo la auditoría de impuestos contra el Sheet.
        // Para otros bancos (BBVA), se requiere el registro físico de la factura SC.
        $auditoriaImpuestos = $auditorias->where('tipo_documento', 'impuestos')->first();

        $tieneValidacionSC = false;
        if ($scFisica) {
            $tieneValidacionSC = true;
        } elseif ($esSantander && $auditoriaImpuestos && $auditoriaImpuestos->estado !== 'Sin SC!') {
            $tieneValidacionSC = true;
        }

        $status_botones['sc'] = [
            'estado' => $tieneValidacionSC ? 'verde' : 'gris',
            'datos' => $scFisica ?? $auditoriaImpuestos,
        ];

        // --- VALIDACIÓN DE LOS DEMÁS BOTONES ---
        $tipos_a_auditar = ['impuestos', 'flete', 'llc', 'pago_derecho', 'muestras', 'maniobras'];
        foreach ($tipos_a_auditar as $tipo) {
            $facturas = $auditorias->where('tipo_documento', $tipo);

            if ($facturas->isEmpty()) {
                // El botón se marca rojo si son impuestos faltantes, gris para los demás
                $status_botones[$tipo] = [
                    'estado' => $tipo === 'impuestos' ? 'rojo' : 'gris',
                    'datos' => null,
                ];
                continue;
            }

            // Se encontraron registros de auditoría
            $facturaPrincipal = $facturas->first();
            $estadoColor = 'verde';

            // Si es Santander y el estado es 'Sin SC!', el color debe ser gris/rojo según tu flujo
            if ($esSantander && $tipo === 'impuestos' && $facturaPrincipal->estado === 'Sin SC!') {
                $estadoColor = 'rojo';
            }

            // Excepción técnica: factura encontrada pero con PDF no legible o no encontrado
            if (isset($facturaPrincipal->ruta_pdf) && str_contains($facturaPrincipal->ruta_pdf, 'No encontrado')) {
                $estadoColor = 'rojo';
            }

            $status_botones[$tipo] = [
                'estado' => $estadoColor,
                'datos' => $facturas->count() > 1 ? $facturas->values()->all() : $facturaPrincipal,
            ];
        }

        return [
            'id' => $operacion->getKey(),
            'tipo_operacion' => $operacion instanceof Importacion ? 'Importación' : 'Exportación',
            'pedimento' => $pedimento->num_pedimiento,
            'cliente' => optional($operacion->cliente)->nombre,
            'cliente_id' => optional($operacion->cliente)->id,
            'sucursal' => optional($operacion->getSucursal)->nombre,
            'sucursal_id' => $operacion->sucursal,
            'fecha_edc' => optional($auditorias->where('tipo_documento', 'impuestos')->first())->fecha_documento,
            'status_botones' => $status_botones,
        ];
    }


    //Metodo para exportar las auditorias a un archivo de Excel
    public function exportarFacturado(Request $request)
    {   //$request contiene todos los filtros
        $query = $this->obtenerQueryFiltrado($request);
        $filtrosGET = $request->query();
        // Ahora, cargamos las relaciones que necesitamos para la transformación
        // La ruta es más larga, pero es la forma correcta: pedimento -> importacion -> auditorias/totalSc
        $resultados = $query->with([
            'importacion' => function ($q) {
                $q->with(['auditoriasRecientes.pedimento', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
            },
            'exportacion' => function ($q) {
                $q->with(['auditoriasRecientes.pedimento', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
            }
        ])->get();
        // Creamos el nombre del archivo dinámicamente
        $fecha = now()->format('dmY');

        if ($filtrosGET['sucursal_id'] !== 'todos') {

            $nombreSucursal = Sucursales::find($filtrosGET['sucursal_id'])->toArray();

            $sucursalesDiccionario = [
                "Nogales" => "NOG",
                "Tijuana" => "TIJ",
                "Laredo" => "NL",
                "Reynosa" => "REY",
                "Mexicali" => "MXL",
                "Manzanillo" => "ZLO",
            ];
            $serieSucursal = $sucursalesDiccionario[$nombreSucursal['nombre']];
            $fileName = "RDI_{$serieSucursal}{$fecha}.xlsx";
        } else {
            $fileName = "RDI_AFMIXED{$fecha}.xlsx"; //Active Filters MIXED (o AF <3)
        }


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
    public function getClientes(Request $request)
    {
        $sucursalId = $request->query('sucursal_id');
        $operationType = $request->query('operation_type');

        $query = Empresas::query();
        // Aplicamos un filtro de existencia basado en la sucursal y tipo de operación
        $query->where(function ($q) use ($sucursalId, $operationType) {
            if ($operationType === 'importacion' || $operationType === 'todos') {
                $q->orWhereHas('importaciones', function ($opQuery) use ($sucursalId) {
                    if ($sucursalId && $sucursalId !== 'todos') {
                        $opQuery->where('sucursal', $sucursalId);
                    }
                });
            }
            if ($operationType === 'exportacion' || $operationType === 'todos') {
                $q->orWhereHas('exportaciones', function ($opQuery) use ($sucursalId) {
                    if ($sucursalId && $sucursalId !== 'todos') {
                        $opQuery->where('sucursal', $sucursalId);
                    }
                });
            }
        });

        return response()->json(
            $query->select('id', 'nombre')->distinct()->orderBy('nombre')->get()
        );
    }


    // getTareasCompletadas()
    // Se encarga de devolver los reportes de impuestos de las tareas completadas.
    public function getTareasCompletadas(Request $request)
    {
        $request->validate(['sucursal_id' => 'required']);

        $sucursalId = $request->input('sucursal_id');
        $sucursalNombre = ($sucursalId !== 'todos') ? Sucursales::find($sucursalId)->nombre : null;
        $sucursalesDiccionario = [
            "Nogales" => "NOG",
            "Tijuana" => "TIJ",
            "Laredo" => "NL",
            "Reynosa" => "REY",
            "Mexicali" => "MXL",
            "Manzanillo" => "ZLO",
        ];
        $serieSucursal = isset($sucursalesDiccionario[$sucursalNombre]) ? $sucursalesDiccionario[$sucursalNombre] : null;
        $tareas = AuditoriaTareas::query()
            ->where('status', 'completado')
            ->when($serieSucursal, function ($query, $nombre) {
                return $query->where('sucursal', $nombre);
            })
            ->orderBy('created_at', 'desc')
            ->limit(10)
            // Seleccionamos las columnas con los nuevos nombres
            ->get([
                'id',
                'nombre_archivo',
                'sucursal',
                'banco',
                'created_at',
                'ruta_reporte_impuestos',
                'nombre_reporte_impuestos',
                'ruta_reporte_impuestos_pendientes',
                'nombre_reporte_pendientes'
            ]);

        return response()->json($tareas);
    }


    /**
     * Calcula el conteo de facturas SC del día para una sucursal específica.
     */
    public function getConteoScDiario(Request $request)
    {
        $request->validate(['sucursal_id' => 'required']);
        $sucursalId = $request->input('sucursal_id');

        // Obtenemos el nombre de la sucursal para el filtro
        $sucursalNombre = ($sucursalId !== 'todos') ? Sucursales::find($sucursalId)->nombre : null;

        // --- Conteo para Importaciones ---
        $conteoImportacion = AuditoriaTotalSC::query()
            ->whereDate('auditorias_totales_sc.created_at', today())
            // Usamos whereHas para filtrar por la sucursal en la tabla padre (operaciones_importacion)
            ->whereHas('operacion', function ($query) use ($sucursalNombre) {
                $query->where('sucursal', $sucursalNombre);
            }, '>=', 1, 'and', Importacion::class)
            ->count();

        // --- Conteo para Exportaciones ---
        $conteoExportacion = AuditoriaTotalSC::query()
            ->whereDate('auditorias_totales_sc.created_at', today())
            // Hacemos lo mismo para operaciones_exportacion
            ->whereHas('operacion', function ($query) use ($sucursalNombre) {
                $query->where('sucursal', $sucursalNombre);
            }, '>=', 1, 'and', Exportacion::class)
            ->count();

        // Si el filtro es 'todos', sumamos ambos conteos
        if ($sucursalId === 'todos') {
            $conteoImportacion = AuditoriaTotalSC::whereDate('created_at', today())->where('operation_type', Importacion::class)->count();
            $conteoExportacion = AuditoriaTotalSC::whereDate('created_at', today())->where('operation_type', Exportacion::class)->count();
        }

        return response()->json([
            'importacion' => $conteoImportacion,
            'exportacion' => $conteoExportacion,
            'todos' => $conteoImportacion + $conteoExportacion,
        ]);
    }


    //--------------------------------------------------------------------------------------------------------------
    //----------------------------------- INICIO DE LOS COMMANDS - AuditoriaImpuestosController ---------------------------------
    //--------------------------------------------------------------------------------------------------------------

    public function importarImpuestosEnAuditorias(string $tareaId)
    {
        gc_collect_cycles();
        if (!$tareaId) {
            Log::error('Se requiere el ID de la tarea.');
            return ['code' => 1, 'message' => new \Exception('Se requiere el ID de la tarea.')];
        }

        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        $rutaPdf = storage_path('app/' . $tarea->ruta_estado_de_cuenta);
        $periodoMeses = $tarea->periodo_meses;
        $banco = strtoupper($tarea->banco);
        $sucursal = $tarea->sucursal;

        if (!file_exists($rutaPdf)) {
            $tarea->update(['status' => 'fallido', 'resultado' => "Ruta pdf no encontrada: ({$rutaPdf})"]);
            return ['code' => 1, 'message' => new \Exception("Archivo no encontrado.")];
        }

        $extension = strtolower(pathinfo($rutaPdf, PATHINFO_EXTENSION));
        if (in_array($extension, ['xls', 'xlsx', 'csv'])) {
            $banco = 'EXTERNO'; 
        }

        try {
            $operacionesLimpiasArray = [];
            
            if ($banco !== "EXTERNO") {
                $config = new \Smalot\PdfParser\Config();
                $config->setRetainImageContent(false);
                $parser = new Parser([], $config);
                $pdf = $parser->parseFile($rutaPdf);
                $textoPdf = $pdf->getText();
                $yearEstadoCuenta = date('Y');

                if ($banco === 'BBVA') {
                    $tipoSplit = '/(\d{2}-\d{2}\n.*PEDMT\s*O:\s*([\w\s\-\/]+)\n.*\n.*\n)/';
                    foreach (preg_split($tipoSplit, $textoPdf, -1, PREG_SPLIT_DELIM_CAPTURE) as $linea) {
                        if (preg_match('/(\d{2}\/\d{2}\/(\d{4}))/', $linea, $matchYear)) {
                            $yearEstadoCuenta = $matchYear[2];
                        }
                        if (strpos($linea, 'PEDMT') !== false && preg_match_all('/\b([4-7]\d{6})\b/', $linea, $matchPedimentos)) {
                            preg_match('/\d{2}-\d{2}/', $linea, $matchFecha);
                            preg_match('/\$\s*([0-9.,]+)/', $linea, $matchCargo);

                            if (isset($matchFecha[0]) && isset($matchCargo[1])) {
                                $fechaPed = $matchFecha[0] . "-{$yearEstadoCuenta}";
                                foreach ($matchPedimentos[1] as $pedIndividual) {
                                    $operacionesLimpiasArray[] = [
                                        'pedimento' => $pedIndividual,
                                        'fecha_str' => \Carbon\Carbon::createFromFormat('d-m-Y', $fechaPed)->format('Y-m-d'),
                                        'cargo_str' => $matchCargo[1],
                                    ];
                                }
                            }
                        }
                    }
                } else if ($banco === 'SANTANDER') {
                    $fechaEstadoCuenta = now()->format('Y-m-d');
                    if (preg_match('/Periodo:\s*\d{2}\/\d{2}\/\d{4}\s*al\s*(\d{2})\/(\d{2})\/(\d{4})/', $textoPdf, $mFecha)) {
                        $fechaEstadoCuenta = $mFecha[3] . '-' . $mFecha[2] . '-' . $mFecha[1];
                    }

                    // Regex Ultra-Robusto: Atrapa el cargo sin importar si tiene 0.00 en medio
                    $patron = '/([\d,]+\.\d{2})\s+(?:[\d,.-]+\s+)*([4-7]\d{6})\b/';

                    if (preg_match_all($patron, $textoPdf, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                        foreach ($matches as $match) {
                            $cargoStr = $match[1][0]; 
                            $pedimento = $match[2][0]; 
                            $offsetMatch = $match[0][1]; 

                            $montoFinal = (float) str_replace(',', '', $cargoStr);

                            if ($montoFinal > 0) {
                                $textoAnterior = substr($textoPdf, max(0, $offsetMatch - 150), 150);
                                $textoAnteriorLimpio = preg_replace('/\s+/', '', $textoAnterior);
                                $fechaTransaccion = $fechaEstadoCuenta;

                                if (preg_match_all('/(0[1-9]|[12]\d|3[01])(0[1-9]|1[0-2])(20\d{2})/', $textoAnteriorLimpio, $mFechasTransaccion, PREG_SET_ORDER)) {
                                    $ultimaFechaEncontrada = end($mFechasTransaccion);
                                    $dia = $ultimaFechaEncontrada[1];
                                    $mes = $ultimaFechaEncontrada[2];
                                    $anio = $ultimaFechaEncontrada[3];

                                    if (checkdate((int)$mes, (int)$dia, (int)$anio)) {
                                        $fechaTransaccion = "$anio-$mes-$dia";
                                    }
                                }

                                $operacionesLimpiasArray[] = [
                                    'pedimento' => $pedimento,
                                    'fecha_str' => $fechaTransaccion,
                                    'cargo_str' => (string)$montoFinal
                                ];
                            }
                        }
                    }
                }
                
                $operacionesLimpias = collect($operacionesLimpiasArray)->unique(function ($item) {
                    $montoLimpio = (float) filter_var(str_replace(',', '', $item['cargo_str'] ?? 0), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    return $item['pedimento'] . '_' . $item['fecha_str'] . '_' . round($montoLimpio, 2);
                })->values();

            } else {
                $import = new LecturaEstadoCuentaExcel($tarea);
                Excel::import($import, $rutaPdf);
                $datosExcel = $import->getProcessedData();
                
                $operacionesLimpias = $datosExcel->groupBy('pedimento')->flatMap(function ($grupo) {
                    $impuestos = $grupo->filter(function ($item) {
                        $fullText = strtolower(($item['descripcion_larga'] ?? '') . ' ' . ($item['concepto'] ?? '') . ' ' . ($item['descripcion'] ?? ''));
                        return str_contains($fullText, 'impuesto') || str_contains($fullText, 'impto') || str_contains($fullText, 'cgo');
                    })->sortBy('fecha_str')->values();
                    
                    if ($impuestos->isNotEmpty()) {
                        return $impuestos; 
                    }
                    return [$grupo->sortByDesc('fecha_str')->first()];
                })->unique(function ($item) {
                    $montoStr = $item['cargo_str'] ?? ($item['cargo'] ?? ($item['monto'] ?? '0'));
                    $montoLimpio = (float) filter_var(str_replace(',', '', $montoStr), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    return $item['pedimento'] . '_' . ($item['fecha_str'] ?? '') . '_' . round($montoLimpio, 2);
                })->values();
            }

            if ($operacionesLimpias->isEmpty()) {
                throw new \Exception("No se encontraron pedimentos en el Estado de Cuenta.");
            }

            $sucursalesDic = [
                'NOG' => [1, 3711], 'TIJ' => [2, 3849], 'NL'  => [3, 3711], 
                'MXL' => [4, 1038], 'ZLO' => [5, 3711], 'REY' => [11, 3577], 
                'VRZ' => [12, 1864]
            ];
            $sucInfo = $sucursalesDic[$sucursal] ?? [1, 3711];

            $numerosDePedimento = $operacionesLimpias->pluck('pedimento')->unique()->toArray();

            // 1. Calculamos las fechas basándonos en la tarea para pasárselas al filtro anti-fantasmas
            $fecha_fin = $tarea->fecha_documento;
            $periodoMeses = $tarea->periodo_meses;
            $fecha_inicio = \Carbon\Carbon::parse($fecha_fin)->subMonths($periodoMeses)->format('Y-m-d');

            // 2. Llamamos a la función usando las variables correctas de este contexto ($sucInfo)
            $mapaPedimentoAId = $this->construirMapaDePedimentos(
                $numerosDePedimento,
                $sucInfo[1], // Patente
                $sucInfo[0], // Sucursal
                $fecha_inicio,
                $fecha_fin
            );
            
            // --- EXORCISTA DE FANTASMAS ---
            $idsPedDb = [];
            foreach ($mapaPedimentoAId as $info) {
                if (!empty($info['all_ids'])) {
                    $idsPedDb = array_merge($idsPedDb, $info['all_ids']);
                } else {
                    $idsPedDb[] = $info['id_pedimiento'];
                }
            }
            $idsPedDb = array_unique($idsPedDb);

            if (!empty($idsPedDb)) {
                Auditoria::whereIn('pedimento_id', $idsPedDb)->where('tipo_documento', 'impuestos')->delete();
            }

            $auditoriasSC = AuditoriaTotalSC::whereIn('pedimento_id', $idsPedDb)->get()->keyBy('pedimento_id');

            $conteoConceptos = [];

            $datosParaUpsert = $operacionesLimpias->map(function ($op) use ($mapaPedimentoAId, $auditoriasSC, $tarea, &$conteoConceptos) {
                $pedLimpio = $op['pedimento'];
                $dbInfo = $mapaPedimentoAId[$pedLimpio] ?? null;

                if (!$dbInfo) return null;

                $id_db = $dbInfo['id_pedimiento'];
                
                // --- LÓGICA CORREGIDA PARA EXPORTACIONES ---
                $operacionId = $dbInfo['id_operacion'];
                if ($dbInfo['tipo'] === 'Importacion') {
                    $tipoOp = Importacion::class;
                } elseif ($dbInfo['tipo'] === 'Exportacion') {
                    $tipoOp = Exportacion::class;
                } else {
                    $tipoOp = Pedimento::class;
                }

                $llaveConteo = $id_db . '-' . $tipoOp;
                if (!isset($conteoConceptos[$llaveConteo])) {
                    $conteoConceptos[$llaveConteo] = 1;
                } else {
                    $conteoConceptos[$llaveConteo]++;
                }
                
                $conceptoLlave = $conteoConceptos[$llaveConteo] === 1 ? 'principal' : 'rectificacion_' . $conteoConceptos[$llaveConteo];

                // --- INTEGRACIÓN DE LA LÓGICA DE SANTANDER ---
                $banco = strtoupper($tarea->banco ?? '');
                $sc = $auditoriasSC->get($id_db);
                $montoImpuestoMXN = (float) filter_var(str_replace(',', '', $op['cargo_str'] ?? ($op['monto'] ?? 0)), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                if (!$sc && ($banco === 'SANTANDER' || str_contains(strtoupper($pedLimpio), 'ZLO'))) {
                    $montoSCMXN = $montoImpuestoMXN;
                } else {
                    $montoSCMXN = ($sc && isset($sc->desglose_conceptos['montos']['impuestos_mxn'])) 
                        ? (float)$sc->desglose_conceptos['montos']['impuestos_mxn'] 
                        : -1.1;
                }

                $estado = $this->compararMontos($montoSCMXN, $montoImpuestoMXN, $tipoOp);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoImpuestoMXN, 2) : $montoImpuestoMXN;

                return [
                    'operacion_id' => $operacionId,
                    'pedimento_id' => $id_db,
                    'operation_type' => $tipoOp,
                    'tipo_documento' => 'impuestos',
                    'concepto_llave' => $conceptoLlave, 
                    'folio' => $pedLimpio, 
                    'fecha_documento' => $op['fecha_str'],
                    'monto_total' => $montoImpuestoMXN,
                    'monto_total_mxn' => $montoImpuestoMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => 'MXN',
                    'estado' => $estado,
                    'ruta_pdf' => $this->limpiarRutaPdf($tarea->ruta_estado_de_cuenta), 
                    'created_at' => now(), 'updated_at' => now()
                ];
            })->filter()->all();

            if (!empty($datosParaUpsert)) {
                Auditoria::insert($datosParaUpsert); 
            }

            $fechasEncontradas = $operacionesLimpias->map(function($item) { 
                return \Carbon\Carbon::parse($item['fecha_str']); 
            });
            
            $tarea->update([
                'pedimentos_procesados' => json_encode($operacionesLimpias->pluck('pedimento')->unique()->values()->all()),
                'fecha_documento' => $fechasEncontradas->max()->addDays(15)->format('Y-m-d')
            ]);

            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            $tarea->update(['status' => 'fallido', 'resultado' => $e->getMessage()]);
            Log::error("Fallo tarea #{$tareaId}: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    // ==================================================================================
    // MÉTODOS EXCLUSIVOS PARA NUEVAS SUCURSALES (NOGALES, LAREDO, TIJUANA, MEXICALI)
    // ==================================================================================

    /**
     * Orquestador que recolecta todas las facturas y las envía al nuevo Google Sheet (Plantilla de 6 PXCC)
     * Reglas: Sin monto = Sin proveedor. Si hay monto -> ECI(SENASICA), Maniobras NOG(SAFINSA), NL(IFA).
     */
    public function enviarAGPCMultiSucursal(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Iniciando envío a GPC con Plantillas y Reglas de Proveedores para Tarea #{$tarea->id}");

        try {
            $mapeadoFacturas = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
            $mapaFacturas = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);
            $pedimentosDelPdf = json_decode($tarea->pedimentos_procesados, true) ?? [];
            
            $sucursalesDic = ['NOG' => [1, 3711], 'TIJ' => [2, 3849], 'NL' => [3, 3711], 'MXL' => [4, 1038]];
            $sucInfo = $sucursalesDic[$tarea->sucursal] ?? [1, 3711];
            
            $mapaPdf = !empty($pedimentosDelPdf) ? $this->construirMapaDePedimentos($pedimentosDelPdf, $sucInfo[1], $sucInfo[0]) : [];
            $mapaPedimentoAId = $mapaFacturas + $mapaPdf;

            $tiposDocumentos = [
                'impuestos'    => 'IMPUESTOS',
                'pago_derecho' => 'ECI',
                'maniobras'    => 'MANIOBRAS',
                'flete'        => 'FLETE',
                'muestras'     => 'MUESTRAS',
                'llc'          => 'LLC'
            ];

            $datosAgrupados = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $scFisica = AuditoriaTotalSC::where('pedimento_id', $datosId['id_pedimiento'])->first();
                $folioSC = $scFisica ? $scFisica->folio : '';

                $tipoOpClass = ($datosId['tipo'] == 'Importacion') ? Importacion::class : Exportacion::class;
                $hojaDestino = $this->getHojaDestino($tarea->sucursal, $tipoOpClass);

                $queryOp = ($tipoOpClass === Importacion::class) 
                    ? Importacion::with('cliente')->find($datosId['id_operacion']) 
                    : Exportacion::with('cliente')->find($datosId['id_operacion']);
                
                $cliente = $queryOp ? optional($queryOp->cliente)->nombre : '';
                $navieraOp = $queryOp->naviera ?? 'N/A';

                // Verificamos existencia de comprobante de muestras
                $pagoMuestraExiste = false;
                $facturasDelPedimento = $indicesOperaciones[$pedimentoLimpio]['facturas'] ?? [];
                
                foreach ($facturasDelPedimento as $nombreArchivo => $facturaInfo) {
                    $tipo = $facturaInfo['tipo_documento'] ?? '';
                    $nombreSubido = strtoupper($nombreArchivo);

                    if (($tipo === 'pago_muestras' || $tipo === 'muestras') && !empty($facturaInfo['ruta_pdf'])) {
                        if (str_contains($nombreSubido, 'PAGO') || str_contains($nombreSubido, 'COMPROBANTE') || str_contains($nombreSubido, 'GISENA') || str_contains($nombreSubido, 'TICKET')) {
                            $pagoMuestraExiste = true;
                            break;
                        }
                    }
                }

                foreach ($tiposDocumentos as $tipoDoc => $pxccLabel) {
                    $auditoriasConsulta = Auditoria::where('pedimento_id', $datosId['id_pedimiento'])
                        ->where('tipo_documento', $tipoDoc)
                        ->where('monto_total', '>', 0)
                        ->orderBy('fecha_documento', 'asc') // <-- Ordenar cronológicamente garantiza el orden Principal -> Recti
                        ->orderBy('id', 'asc')
                        ->get();

                    // Filtro Anti-Fantasmas: Eliminamos registros que tengan exactamente la misma fecha y el mismo monto.
                    $auditorias = $auditoriasConsulta;
                    if ($tipoDoc === 'impuestos') {
                        $auditorias = $auditoriasConsulta->unique(function ($aud) {
                            return $aud->fecha_documento . '_' . round((float)$aud->monto_total, 2);
                        })->values();
                    }

                    if ($auditorias->count() > 0) {
                        $estatusConDatos = 'PENDIENTE';
                        if (in_array($tipoDoc, ['impuestos', 'pago_derecho'])) {
                            $estatusConDatos = 'PAGADO';
                        }
                        if ($tipoDoc === 'muestras') {
                            $estatusConDatos = $pagoMuestraExiste ? 'PAGADO' : 'PENDIENTE';
                        }

                        foreach ($auditorias as $index => $aud) {
                            $proveedor = $navieraOp; // Default
                            if ($tipoDoc === 'flete') {
                                $proveedor = 'TRANSPORTACTICS';
                            } elseif ($tipoDoc === 'pago_derecho') {
                                $proveedor = 'SENASICA';
                            } elseif ($tipoDoc === 'maniobras') {
                                if ($tarea->sucursal === 'NOG') {
                                    $proveedor = 'SAFINSA';
                                } elseif ($tarea->sucursal === 'NL') {
                                    $proveedor = 'IFA';
                                }
                            } elseif ($tipoDoc === 'llc') {
                                $proveedor = 'LLC';
                            } elseif ($tipoDoc === 'muestras') {
                                if (!empty($aud->ruta_xml)) {
                                    $xmlMuestra = $this->parsearXmlFlete($aud->ruta_xml);
                                    if ($xmlMuestra && str_contains(strtoupper($xmlMuestra['emisor'] ?? ''), 'LABORATORIOS DE ANALISIS DE PRODUCTOS AGROPECUARIOS DEL NORESTE')) {
                                        $proveedor = 'LAPAN';
                                    }
                                }
                            }

                            // <-- NUEVA LÓGICA DE NOMBRES (Sin importar si lleva "R1" o no, es por posición)
                            if ($tipoDoc === 'impuestos') {
                                $conceptoFinal = ($index === 0) ? 'IMPUESTOS' : 'IMPUESTOS RECTI'; 
                            } else {
                                $conceptoFinal = ($index > 0) ? $pxccLabel . ' ' . ($index + 1) : $pxccLabel;
                            }
                            
                            $folioFactura = $aud->folio ?? '-';

                            if ($tipoDoc === 'maniobras' && $tarea->sucursal === 'NOG' && !empty($folioFactura) && $folioFactura !== '-') {
                                $folioFactura = strtoupper($folioFactura);
                                if (!str_starts_with($folioFactura, 'GS0')) {
                                    $folioFactura = 'GS0' . $folioFactura;
                                }
                            }

                            $datosAgrupados[$hojaDestino][] = [
                                'fecha'      => $aud->fecha_documento ? \Carbon\Carbon::parse($aud->fecha_documento)->format('m-d-Y') : now()->format('m-d-Y'),
                                'cliente'    => $cliente,
                                'pedimento'  => $datosId['num_pedimiento'],
                                'pxcc'       => $conceptoFinal,
                                'proveedor'  => $proveedor,
                                'factura_p'  => $folioFactura,
                                'monto'      => ($tipoDoc === 'llc') ? (float) $aud->monto_total_mxn : (float) $aud->monto_total,
                                'moneda'     => strtoupper($aud->moneda_documento ?? 'MXN'),
                                'factura_sc' => $folioSC,
                                'estatus'    => $estatusConDatos,
                                'monto_llc'  => ($tipoDoc === 'llc') ? (float) $aud->monto_total : '',
                                'moneda_llc' => ($tipoDoc === 'llc') ? 'USD' : '',
                                'fecha_pago' => ''
                            ];
                        }
                    } else {
                        $datosAgrupados[$hojaDestino][] = [
                            'fecha'      => '',
                            'cliente'    => $cliente,
                            'pedimento'  => $datosId['num_pedimiento'],
                            'pxcc'       => $pxccLabel,       
                            'proveedor'  => '',
                            'factura_p'  => '',
                            'monto'      => '',
                            'moneda'     => '',
                            'factura_sc' => $folioSC,          
                            'estatus'    => '',
                            'monto_llc'  => '',
                            'moneda_llc' => '',
                            'fecha_pago' => ''
                        ];
                    }
                }
            }

            foreach ($datosAgrupados as $nombreHoja => $filas) {
                $paquetes = array_chunk($filas, 50); 
                foreach ($paquetes as $i => $p) {
                    $ultimo = ($i === count($paquetes) - 1);
                    $this->enviarDatosAGoogleSheetsMulti($p, $nombreHoja, $ultimo);
                    sleep(1);
                }
            }
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error en GPC Multi-sucursal: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Webhook dedicado a la nueva URL de App Script
     */
    private function enviarDatosAGoogleSheetsMulti(array $datosParaEnviar, string $hoja, bool $esUltimo = true)
    {
        try {
            if (empty($datosParaEnviar)) {
                return;
            }

            if (app()->environment('production')) {
                $scriptUrl = 'https://script.google.com/macros/s/AKfycbzaLZjOImg1nz_nywOTYoc-iM-LFhtFeVPIf3biXstCcOLK1BXfBmiomg29--cdDuS4iw/exec'; //PRODUCCIÓN
            } else {
                $scriptUrl = 'https://script.google.com/macros/s/AKfycbwYfBt6btEjIXLjnLBWlqEth9r7dricE1QOVAeAVK6wUEWitK1ZI3x2YRe5k243VvC5/exec'; //DESARROLLO
            }

            Log::info("Enviando " . count($datosParaEnviar) . " registros a GPC Multi. Hoja: $hoja");

            $response = Http::timeout(400)->withoutVerifying()->post($scriptUrl, [
                'hoja'       => $hoja,
                'pedimentos' => $datosParaEnviar,
                'es_ultimo'  => $esUltimo
            ]);
            Log::info("Respuesta Real de Google para {$hoja}: " . $response->body());
            if (!$response->successful()) {
                Log::error("Error HTTP GPC Multi: " . $response->status());
            }
        } catch (\Throwable $e) {
            Log::error("Error enviando a Sheets Multi: " . $e->getMessage());
        }
    }

    /**
     * Mapea la sucursal y la operación a la pestaña exacta
     */
    private function getHojaDestino($sucursalCod, $operationTypeClass)
    {
        $sucursales = [
            'NOG' => 'NOGALES', // Asegúrate que sea "NOGALES" y no "NOGAL"
            'NL'  => 'LAREDO',
            'TIJ' => 'TIJUANA',
            'MXL' => 'MEXICALI'
        ];
        $nombre = $sucursales[$sucursalCod] ?? 'NOGALES';

        // Si $operationTypeClass es "App\Models\Exportacion", 
        // str_contains($operationTypeClass, 'Importacion') será FALSO y devolverá "EXPO".
        // Esto está correcto, PERO asegúrate que la clase se llame así exactamente.
        $tipo = (str_contains($operationTypeClass, 'Importacion')) ? 'IMPO' : 'EXPO';

        return "{$nombre} {$tipo}";
    }
    
    // 5. ENVÍO DE IMPUESTOS A GPC (Solo Santander / Manzanillo)
    public function enviarAGPCImpuestos(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        if (strtoupper($tarea->banco) !== 'SANTANDER') {
            return ['code' => 0, 'message' => 'Omitido (No es Santander)'];
        }

        try {
            // 🚀 PASO 1: EL TRADUCTOR - Leemos Google Sheets para obtener los nombres EXACTOS
            $urlBase = app()->environment('production') 
                ? "https://docs.google.com/spreadsheets/d/1FvhWp2AeOyoiv1KIrmQNOKf9ZoDRy5L7HVd5FcRQBio/gviz/tq?tqx=out:csv&_cb=" . time()
                : "https://docs.google.com/spreadsheets/d/1yOcPGlvycRBCg5KpWs5-b8EmLQrUNPgh4aqurXQT1Uo/gviz/tq?tqx=out:csv&_cb=" . time();
            
            $csvUrlZLO = $urlBase . "&sheet=ZLO";
            $responseZLO = Http::withoutVerifying()->timeout(60)->get($csvUrlZLO);
            
            $diccionarioNombresSheet = [];
            if ($responseZLO->successful()) {
                $streamZLO = fopen('php://memory', 'r+');
                fwrite($streamZLO, $responseZLO->body());
                rewind($streamZLO);
                while (($cols = fgetcsv($streamZLO)) !== false) {
                    foreach ($cols as $col) {
                        // 🚀 NO limpiamos saltos de línea aquí. Queremos la celda original intacta.
                        $colExacta = trim($col);
                        if (preg_match('/[4-7]\d{6}/', $colExacta)) {
                            $diccionarioNombresSheet[] = $colExacta;
                        }
                    }
                }
                fclose($streamZLO);
                $diccionarioNombresSheet = array_unique($diccionarioNombresSheet);
            }

            $mapeadoFacturas = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
            $mapaFacturas = $mapeadoFacturas['pedimentos_totales'] ?? [];

            $pedimentosDelPdf = json_decode($tarea->pedimentos_procesados, true) ?? [];
            
            $sucursalesDic = [
                'NOG' => [1, 3711], 'TIJ' => [2, 3849], 'NL'  => [3, 3711],
                'MXL' => [4, 1038], 'ZLO' => [5, 3711], 'REY' => [11, 3577], 
                'VRZ' => [12, 1864]
            ];
            $sucInfo = $sucursalesDic[$tarea->sucursal] ?? [1, 3711];
            
            $mapaPdf = [];
            if (!empty($pedimentosDelPdf)) {
                $mapaPdf = $this->construirMapaDePedimentos($pedimentosDelPdf, $sucInfo[1], $sucInfo[0]);
            }

            $mapaPedimentoAId = $mapaFacturas + $mapaPdf;

            $impuestosParaSheetsDict = [];
            $familias = []; 

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                
                $idsActuales = $datosId['all_ids'] ?? [$datosId['id_pedimiento']];
                $mergedIndex = -1;

                preg_match_all('/[4-7]\d{6}/', $pedimentoLimpio . ' ' . ($datosId['num_pedimiento'] ?? ''), $mCurr);
                $digitosCurrent = array_unique($mCurr[0] ?? []);

                foreach ($familias as $index => $familia) {
                    if (count(array_intersect($familia['ids'], $idsActuales)) > 0) {
                        $mergedIndex = $index;
                        break;
                    }
                    
                    $digitosFamilia = [];
                    foreach ($familia['claves'] as $claveFamiliar) {
                        preg_match_all('/[4-7]\d{6}/', $claveFamiliar, $mFam);
                        $digitosFamilia = array_merge($digitosFamilia, $mFam[0] ?? []);
                    }
                    
                    if (count(array_intersect($digitosCurrent, array_unique($digitosFamilia))) > 0) {
                        $mergedIndex = $index;
                        break;
                    }
                }
                
                if ($mergedIndex !== -1) {
                    $familias[$mergedIndex]['ids'] = array_unique(array_merge($familias[$mergedIndex]['ids'], $idsActuales));
                    $familias[$mergedIndex]['claves'][] = $pedimentoLimpio;
                    if (isset($datosId['num_pedimiento'])) $familias[$mergedIndex]['claves'][] = $datosId['num_pedimiento'];
                } else {
                    $familias[] = [
                        'ids' => $idsActuales,
                        'claves' => [$pedimentoLimpio, $datosId['num_pedimiento'] ?? '']
                    ];
                }
            }

            // 🚀 PASO 3: TRADUCIR EL NOMBRE Y ELIMINAR DUPLICADOS EXACTOS
            foreach ($familias as $familia) {
                // Fallback por si la celda no existe en el Sheet (aplica limpieza básica)
                usort($familia['claves'], function($a, $b) { return strlen($b) - strlen($a); });
                $pedimentoAEnviar = preg_replace('/\s+/', ' ', trim($familia['claves'][0]));
                $pedimentoAEnviar = preg_replace('/\b\d{4}-/', '', $pedimentoAEnviar); 

                // Extracción de dígitos para buscar en el Sheet
                $digitosDeNuestraFamilia = [];
                foreach ($familia['claves'] as $c) {
                    if (preg_match_all('/[4-7]\d{6}/', $c, $m)) {
                        $digitosDeNuestraFamilia = array_merge($digitosDeNuestraFamilia, $m[0]);
                    }
                }
                $digitosDeNuestraFamilia = array_unique($digitosDeNuestraFamilia);

                // 🚀 TRADUCTOR EN ACCIÓN: Reemplaza nuestro nombre por el original de Sheets
                foreach ($diccionarioNombresSheet as $nombreExactoDelSheet) {
                    foreach ($digitosDeNuestraFamilia as $digito) {
                        if (str_contains($nombreExactoDelSheet, $digito)) {
                            $pedimentoAEnviar = $nombreExactoDelSheet; // Mantiene los saltos de línea originales
                            break 2; 
                        }
                    }
                }

                // Fusiona las facturas idénticas en 1
                $auditoriasBase = Auditoria::whereIn('pedimento_id', $familia['ids'])
                    ->where('tipo_documento', 'impuestos')
                    ->where('monto_total', '>', 0)
                    ->orderBy('fecha_documento', 'asc') 
                    ->orderBy('id', 'asc')
                    ->get()
                    ->unique(function ($aud) {
                        return ($aud->fecha_documento ?? 's/f') . '_' . round((float)$aud->monto_total, 2);
                    })->values();

                foreach ($auditoriasBase as $index => $auditoriaBase) {
                    $operacionId = $auditoriaBase->operacion_id;
                    $esImpo = ($auditoriaBase->operation_type === Importacion::class);
                    $operacion = $esImpo ? Importacion::find($operacionId) : Exportacion::find($operacionId);

                    // LÓGICA INFALIBLE: El primer pago (index 0) siempre es el original. 
                    // Cualquier pago extra (index > 0) es detectado automáticamente como Rectificación.
                    $esRecti = ($index > 0);
                    $conceptoAEnviar = $esRecti ? 'Impuestos Recti' : 'Impuestos';

                    $impuestosParaSheetsDict[] = [
                        'fecha'      => $auditoriaBase->fecha_documento ?? now()->format('Y-m-d'),
                        'cliente'    => optional($operacion->cliente)->nombre ?? '',
                        'contenedor' => $operacion->contenedor ?? '',
                        'bl'         => $operacion->bol ?? '', 
                        'naviera'    => $operacion->naviera ?? '',
                        'anticipo'   => '', 
                        'pedimento'  => $pedimentoAEnviar, // Nombre íntegro de BD para anclar celda en Sheets
                        'concepto'   => $conceptoAEnviar, 
                        'monto'      => (float) $auditoriaBase->monto_total,
                        'moneda'     => 'MXN'
                    ];
                }
            }

            if (!empty($impuestosParaSheetsDict)) {
                Log::info("🚀 Impuestos listos para enviar a GPC: " . count($impuestosParaSheetsDict));
                
                $paquetes = array_chunk($impuestosParaSheetsDict, 50);
                foreach ($paquetes as $index => $paquete) {
                    $numeroPaquete = $index + 1;
                    $totalPaquetes = count($paquetes);
                    $esUltimo = ($numeroPaquete === $totalPaquetes);

                    $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                    sleep(2);
                }
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error enviando Impuestos a GPC: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * MÉTODOS DE ESCRITURA EN GOOGLE SHEETS (SIN CREDENCIALES, VÍA WEBHOOK)
     * Método universal para enviar cualquier concepto (Impuestos, Maniobras, Demoras, etc.)
     */
    private function enviarDatosAGoogleSheets(array $datosParaEnviar, string $hoja, string $sucursal, bool $esUltimo = true)
    {
        try {
            if (empty($datosParaEnviar)) {
                return;
            }

            // 1. Determinamos las URLs basándonos en el entorno
            if (app()->environment('production')) {
                // --- URLs de PRODUCCIÓN ---
                $urlZLO   = 'https://script.google.com/a/macros/intactics.com/s/AKfycbzil8yuKDXwReWIA91kJFDXelMDGghbWeW9bb-jvgcC5FoZr3Z0HlIQFkuxlOg-og3kuQ/exec';
                $urlOtros = 'https://script.google.com/macros/s/AKfycbwYfBt6btEjIXLjnLBWlqEth9r7dricE1QOVAeAVK6wUEWitK1ZI3x2YRe5k243VvC5/exec';
            } else {
                // --- URLs de PRUEBAS / LOCAL ---
                $urlZLO   = 'https://script.google.com/macros/s/AKfycbyXbQI3JkBufxYUXsYUUTmIIwJmYWuYDVOrYnV0xSXbTBe7lhNZvTjGBDKPuPoK7x6xpQ/exec';
                // Si tienes un App Script de pruebas para las nuevas sucursales, reemplaza el link aquí abajo. 
                // Por ahora le dejé el mismo que el de producción para evitar errores de variable indefinida.
                $urlOtros = 'https://script.google.com/macros/s/AKfycbwYfBt6btEjIXLjnLBWlqEth9r7dricE1QOVAeAVK6wUEWitK1ZI3x2YRe5k243VvC5/exec';
            }

            // 2. Seleccionamos el script correcto según la sucursal
            $scriptUrl = ($sucursal === 'ZLO') ? $urlZLO : $urlOtros;

            Log::info("Enviando " . count($datosParaEnviar) . " registros a GPC. Sucursal: $sucursal | Hoja: $hoja | Entorno: " . app()->environment());

            // 3. Enviamos el payload
            $response = Http::timeout(400)->withoutVerifying()->post($scriptUrl, [
                'hoja'       => $hoja,
                'pedimentos' => $datosParaEnviar,
                'es_ultimo'  => $esUltimo
            ]);

            // 4. Manejo de la respuesta y escudo Anti-Timeouts
            if ($response->successful()) {
                $cuerpoRespuesta = $response->json();
                
                // Escudo anti-timeouts HTML
                if ($cuerpoRespuesta === null) {
                    Log::error("Google Sheets no devolvió JSON. Posible TIMEOUT. Respuesta cruda: " . strip_tags($response->body()));
                    return;
                }

                if(isset($cuerpoRespuesta['status']) && $cuerpoRespuesta['status'] === 'error') {
                    Log::error("Google Sheets Error Interno: " . $cuerpoRespuesta['message']);
                } else {
                    $debugInfo = isset($cuerpoRespuesta['debug']) ? json_encode($cuerpoRespuesta['debug']) : 'Sin detalles';
                    Log::info("Google Sheets respondió con Éxito. Detalle: " . $debugInfo);
                }
            } else {
                Log::error("Google Sheets HTTP Error: " . $response->status());
            }

        } catch (\Throwable $e) {
            Log::error("Error al enviar datos a Google Sheets ($sucursal): " . $e->getMessage());
        }
    }

    /**
     * Limpia la ruta del PDF para guardar solo el nombre del archivo.
     */
    private function limpiarRutaPdf(string $rutaOriginal): string
    {
        return str_replace('operaciones/estados_de_cuenta/', '', $rutaOriginal);
    }


    //--- METODO MAPEAR TODAS LAS FACTURAS DE SC, FLETES, LLC Y PAGOS DE DERECHO
    // Se encarga de obtener por medio del GET, unicamente los archivos que se ocupan para este flujo de auditorias
    // Este metodo sirve de mapeador universal, en donde de este, se generara un archivo JSON en donde estaran todas
    // las rutas necesarias para procesar la tarea actual.
    public function mapearFacturasYFacturasSCEnAuditorias(string $tareaId)
    {
        if (!$tareaId) {
            Log::error('Se requiere el argumento --tarea_id.');
            return ['code' => 1, 'message' => new \Exception('Se requiere el argumento --tarea_id.')];
        }

        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea) {
            Log::error("No se encontró la Tarea con ID: {$tareaId}");
            return ['code' => 1, 'message' => new \Exception("No se encontró la Tarea con ID: {$tareaId}")];
        }

        Log::info("--- [INICIO] Mapeo de facturas para Tarea #{$tarea->id} ---");

        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            $fecha_fin = $tarea->fecha_documento;
            $periodoMeses = $tarea->periodo_meses;
            $fecha_inicio = \Carbon\Carbon::parse($fecha_fin)->subMonths($periodoMeses)->format('Y-m-d');

            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return ['code' => 0, 'message' => 'completado'];
            }
            Log::info("Procesando Facturas SC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            $sucursalesDiccionario = [
                'NOG' => [1, 3711], //NOGALES, NOG
                'TIJ' => [2, 3849], //TIJUANA, TIJ
                'NL' => [3, 3711], //LAREDO, NL, LAR, LDO
                'MXL' => [4, 1038], //MEXICALI, MXL
                'ZLO' => [5, 3711], //MANZANILLO, ZLO
                'REY' => [11, 3577], //REYNOSA, NL, LAR, LDO
                'VRZ' => [12, 1864], //VERACRUZ, ZLO
            ];
            $patenteSucursal = $sucursalesDiccionario[$sucursal][1];
            $numeroSucursal = $sucursalesDiccionario[$sucursal][0];
            // 1. Obtenemos los números de pedimento de nuestro índice
            $numerosDePedimento = $pedimentos;

            // Construimos el mapa validado por Sucursal y Patente
            $mapaPedimentoAId = $this->construirMapaDePedimentos(
                $numerosDePedimento,
                $patenteSucursal,
                $numeroSucursal,
                $fecha_inicio,
                $fecha_fin
            );
            Log::info("Pedimentos encontrados en tabla 'pedimentos' (Validados por Sucursal): " . count($mapaPedimentoAId));


            // $mapaPorId es una variable auxiliar que sirve para hacer el mapeo de 'num_pedimiento' que se requiere
            // en ambas variables de $mapaPedimentoAImportacionId/$mapaPedimentoAExportacionId
            $mapaPorId = collect($mapaPedimentoAId)
                ->keyBy('id_pedimiento');

            $pu = memory_get_usage();
            // 2. MAPEADO EFICIENTE DE IDS
            // PROCESAR IMPORTACIONES
            $mapaPedimentoAImportacionId = Importacion::query()
                ->selectRaw('id_pedimiento, MAX(id_importacion) as id_importacion')
                ->where(['operaciones_importacion.patente' => $patenteSucursal, 'operaciones_importacion.sucursal' => $numeroSucursal])
                ->whereIn('operaciones_importacion.id_pedimiento', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                ->whereNull('parent')
                ->groupBy('id_pedimiento')
                ->get()
                ->map(function ($operacion) use ($mapaPorId) {
                    $info = $mapaPorId->get($operacion->id_pedimiento);

                    return [
                        'pedimento' => $info['num_pedimiento'] ?? null, // Recuperamos num_pedimiento
                        'id_operacion' => $operacion->id_importacion,
                        'id_pedimento' => $operacion->id_pedimiento,
                        'tipo' => 'Importacion', // Forzamos el tipo real para la API
                    ];
                })
                ->keyBy('pedimento');

            Log::info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': " . $mapaPedimentoAImportacionId->count());
            $pu = memory_get_usage();
            // 2. MAPEADO EFICIENTE DE IDS
            // PROCESAR EXPORTACIONES
            $mapaPedimentoAExportacionId = Exportacion::query()
                ->selectRaw('id_pedimiento, MAX(id_exportacion) as id_exportacion')
                ->where(['operaciones_exportacion.patente' => $patenteSucursal, 'operaciones_exportacion.sucursal' => $numeroSucursal]) // ¡ACTIVADOS PARA EVITAR CRUCES!
                ->whereIn('operaciones_exportacion.id_pedimiento', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                ->groupBy('id_pedimiento')
                ->get()
                ->map(function ($operacion) use ($mapaPorId) {
                    $info = $mapaPorId->get($operacion->id_pedimiento);

                    return [
                        'pedimento' => $info['num_pedimiento'] ?? null, // Recuperamos num_pedimiento
                        'id_operacion' => $operacion->id_exportacion,
                        'id_pedimento' => $operacion->id_pedimiento,
                    ];
                })
                ->keyBy('pedimento');

            Log::info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_exportacion': " . $mapaPedimentoAExportacionId->count());

            // --- LOGICA PARA DETECTAR LOS NO ENCONTRADOS 
            //Esto lo hago debido a que hay pedimentos que estan bastante sucios que ni se pueden encontrar
            //Un ejemplo es que haya dos registros con exactamente el mismo valor, pero con la diferencia de que tiene un carrete
            //un enter o una tabulacion en el registro, volviendola 'unica'. Y aqui lo que hare es mostrar esos pedimentos que
            //causan confusion y los subire a la tabla de tareas para que queden expuestos ante todo el mundo! awawaw

            // 1. Preparamos la búsqueda REGEXP para la base de datos

            $pedimentosPorEncontrar = array_flip($numerosDePedimento); // Usamos las llaves para búsqueda rápida
            $mapaNoEncontrados = [];

            // 2. Iteramos sobre los que SÍ encontramos en el paso 1 (construirMapaDePedimentos)
            // Si está en el mapa, es porque existe y pertenece a esta sucursal.
            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datos) {
                if (isset($pedimentosPorEncontrar[$pedimentoLimpio])) {
                    unset($pedimentosPorEncontrar[$pedimentoLimpio]); // Lo borramos de la lista de pendientes
                }
            }

            // 3. Lo que queda en $pedimentosPorEncontrar son los FALLIDOS (o de otra sucursal)
            if (!empty($pedimentosPorEncontrar)) {

                // Intentamos buscar sus datos globales solo para tener referencia en el reporte,
                // pero NO los quitamos de la lista de pendientes.
                $keysPorEncontrar = array_keys($pedimentosPorEncontrar);

                // Usamos la misma lógica de Regex estricto para mapear el ID correcto
                $regexPattern = implode('|', array_map(function ($p) {
                    return "[[:<:]]{$p}[[:>:]]";
                }, $keysPorEncontrar));

                $busquedaGlobal = Pedimento::whereRaw("num_pedimiento REGEXP ?", [$regexPattern])->get();

                // Calculamos la misma fecha límite que pusimos en el filtro (Hace 1 año)
                $fechaLimiteAntiguedad = now()->subYear()->format('Y-m-d');

                foreach ($keysPorEncontrar as $pedimentoBuscado) {
                    foreach ($busquedaGlobal as $registro) {
                        preg_match('/[4-7]\d{6}/', $pedimentoBuscado, $matchBuscado);
                        $digitosBuscados = $matchBuscado[0] ?? $pedimentoBuscado;

                        if (str_contains($registro->num_pedimiento, $digitosBuscados)) {

                            $fechaRealDB = $registro->created_at ? $registro->created_at->format('Y-m-d') : 'Sin fecha';

                            $mapaNoEncontrados[$pedimentoBuscado] = [
                                'id_pedimiento' => $registro->id_pedimiento,
                                'num_pedimiento' => $registro->num_pedimiento,
                                'fecha_bd' => $fechaRealDB,
                            ];
                            // OJO: No hacemos break para permitir encontrar la mejor coincidencia si hubiera
                        }
                    }
                }

                // Guardamos en la BD para el reporte de Pendientes
                $tarea->update([
                    'pedimentos_descartados' => $pedimentosPorEncontrar // Aquí se guardan las claves
                ]);
                Log::warning("Subiendo " . count($pedimentosPorEncontrar) . " pedimentos no encontrados o de otra sucursal!");
            } else {
                Log::info("¡Todos los pedimentos fueron encontrados y mapeados correctamente!");
            }
            $pu = memory_get_usage();

            // CONSTRUIR EL ÍNDICE - EXPORTACIONES
            Log::info("Iniciando mapeo de archivos de exportaciones.");
            $indiceExportaciones = $this->construirIndiceFacturasParaMapeo($mapaPedimentoAExportacionId, $sucursal, 'exportaciones');
            Log::info("Mapeo de exportaciones finalizado!");

            $pu = memory_get_usage();
            // --- OJO: Aqui se gastan muchos recursos en el mapeo!!!
            // CONSTRUIR EL ÍNDICE - IMPORTACIONES
            Log::info("Iniciando mapeo de archivos de importaciones.");
            $indiceImportaciones = $this->construirIndiceFacturasParaMapeo($mapaPedimentoAImportacionId, $sucursal, 'importaciones');
            Log::info("Mapeo de importaciones finalizado!");

            $pu = memory_get_usage();



            $mapeadoOperacionesID =
                [
                    'pedimentos_totales' => $mapaPedimentoAId ?? [],
                    'pedimentos_no_encontrados' => $mapaNoEncontrados ?? [],
                    'pedimentos_importacion' => $mapaPedimentoAImportacionId ?? [],
                    'pedimentos_exportacion' => $mapaPedimentoAExportacionId ?? [],
                    'indices_importacion' => $indiceImportaciones ?? [],
                    'indices_exportacion' => $indiceExportaciones ?? [],

                ];

            $pu = memory_get_usage();
            // 3. GUARDAR EL ÍNDICE EN UN ARCHIVO PRIVADO CON NOMBRE HASHEADO
            // Usamos json_encode para convertir el array en un string JSON.
            // JSON_PRETTY_PRINT hace que el archivo sea fácil de leer para un humano.
            $contenidoJson = json_encode($mapeadoOperacionesID, JSON_PRETTY_PRINT);

            // a) Creamos un archivo temporal y guardamos nuestro JSON en él.
            $tempFilePath = tempnam(sys_get_temp_dir(), 'mapeo_json_');
            file_put_contents($tempFilePath, $contenidoJson);

            // b) Usamos Storage::putFile() para que Laravel genere el hash y lo guarde.
            // Esto es el equivalente a ->store()
            $rutaRelativa = Storage::putFile(
                'mapeo_completo_facturas', // La carpeta destino dentro de storage/app
                new File($tempFilePath) // Le pasamos el archivo temporal
            );
            // Storage::path() convierte la ruta relativa en la ruta completa del sistema de archivos.
            $rutaAbsoluta = Storage::path($rutaRelativa);

            Log::info("Mapeo guardado exitosamente en: {$rutaRelativa}");

            // 4. ACTUALIZAR LA TAREA CON LA RUTA RELATIVA DEL ARCHIVO
            $tarea->update([
                'mapeo_completo_facturas' => $rutaRelativa
            ]);
            Storage::delete($rutaMapeo);
            Log::info("Ruta del mapeo guardada en la Tarea #{$tarea->id}.");

            Log::info("--- [FIN] Mapeo de facturas completado con éxito. ---");
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Fallo en Tarea #{$tarea->id} [reporte:mapear-facturas]: " . $e->getMessage());
            $tarea->update(['status' => 'fallido', 'resultado' => 'Error al generar el mapeo de facturas.' . $e->getMessage()]);
            return ['code' => 1, 'message' => new \Exception("Fallo en Tarea #{$tarea->id} [reporte:mapear-facturas]: " . $e->getMessage())];
        }
    }


    //--- METODO IMPORTAR SC A AUDITORIAS_TOTALES_SC
    // Se encarga de leer el archivo de TXT de la SC y obtener todos los montos de impuestos requeridos para la auditoria
    // Este metodo sirve para dejar mapeados y a la mano los montos requeridos para los demas impuestos, para hacer comparativas
    // ,conversiones y condiciones que dependen de la SC como auxiliar.
    public function importarFacturasSCEnAuditoriasTotalesSC(string $tareaId)
    {
        gc_collect_cycles();
        if (!$tareaId) {
            Log::error('Se requiere el ID de la tarea. Usa --tarea_id=X');
            return ['code' => 1, 'message' => new \Exception('Se requiere el ID de la tarea. Usa --tarea_id=X')];
        }

        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("SC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return ['code' => 1, 'message' => new \Exception("SC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.")];
        }

        try {
            //Iniciamos con obtener el mapeo
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                Log::info("No se encontró el archivo de mapeo universal para la tarea #{$tarea->id}.");
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal para la tarea #{$tarea->id}.")];
            }

            //Leemos y decodificamos el archivo JSON completo
            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array) json_decode($contenidoJson, true);

            //Leemos los demas campos de la tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("SC: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return ['code' => 0, 'message' => 'completado'];
            }
            Log::info("Procesando Facturas SC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");
            
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            //--- YA UNA VEZ TENIENDO TODO A LA MANO
            // 3. Construimos el índice de SCs desde los archivos (tu lógica no cambia)
            $indiceSC = $this->construirIndiceSC($indicesOperaciones);
            if (empty($indiceSC)) {

                Log::info("No se encontraron archivos de SC para procesar en la sucursal {$sucursal}.");
                $mapeadoFacturas['auditorias_sc'] = [];

                // Adjuntamos el nuevo arreglo y lo parseamos a JSON
                $contenidoJson = json_encode($mapeadoFacturas, JSON_PRETTY_PRINT);

                // Creamos un archivo temporal y guardamos nuestro JSON en él.
                $tempFilePath = tempnam(sys_get_temp_dir(), 'mapeo_json_');
                file_put_contents($tempFilePath, $contenidoJson);

                // Usamos Storage::putFile() para que Laravel genere el hash y lo guarde.
                // Esto es el equivalente a ->store()
                $rutaRelativa = Storage::putFile(
                    'mapeo_completo_facturas', // La carpeta destino dentro de storage/app
                    new File($tempFilePath) // Le pasamos el archivo temporal
                );

                Log::info("Mapeo actualizado con auditorias_sc!");

                //Actualizamos la ruta por la que ya tiene las auditorias
                $tarea->fresh()->update(
                    [
                        'mapeo_completo_facturas' => $rutaRelativa,
                        'updated_at' => now(),
                    ]
                );

                //Borramos el anterior
                Storage::delete($rutaMapeo);

                return ['code' => 0, 'message' => 'completado'];
            }
            Log::info("Se encontraron " . count($indiceSC) . " facturas SC en los archivos.");
            $auditoriasParaGuardar = [];
            
            // === CAMBIO CLAVE: Iteramos sobre el mapa maestro, no sobre el índice SC ===
            foreach ($mapaPedimentoAId as $pedimentoExcel => $pedimentoReal) {
                
                $numPedBD = $pedimentoReal['num_pedimiento'];
                
                // Extraemos los 7 dígitos puros para que haga match con el índice SC
                preg_match('/[4-7]\d{6}/', $numPedBD, $matchDigitos);
                $pedimentoLimpio = $matchDigitos[0] ?? '';

                // Buscamos en el índice SC usando todas las combinaciones posibles
                $datosSC = $indiceSC[$pedimentoLimpio] ?? $indiceSC[$numPedBD] ?? $indiceSC[$pedimentoExcel] ?? null;

                if (!$datosSC) {
                    continue; 
                }

                $operacionIdVal = $pedimentoReal['id_operacion'] ?? null;
                $tipoOperacionStr = $pedimentoReal['tipo'] ?? '';
                
                if ($tipoOperacionStr === 'Importacion') {
                    $tipoOperacionClass = Importacion::class;
                } elseif ($tipoOperacionStr === 'Exportacion') {
                    $tipoOperacionClass = Exportacion::class;
                } else {
                    $tipoOperacionClass = "N/A";
                }

                if (!$operacionIdVal || $tipoOperacionClass === "N/A") {
                    continue; 
                }

                $desgloseSC = 
                    [
                        'moneda' => $datosSC['moneda'],
                        'tipo_cambio' => $datosSC['tipo_cambio'],
                        'montos' =>
                        [
                            'sc' => $datosSC['monto_total_sc'],
                            'sc_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_total_sc'] != -1) ? round($datosSC['monto_total_sc'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_total_sc'],

                            'impuestos' => $datosSC['monto_impuestos'],
                            'impuestos_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_impuestos'] != -1) ? round($datosSC['monto_impuestos'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_impuestos'],

                            'pago_derecho' => $datosSC['monto_total_pdd'],
                            'pago_derecho_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_total_pdd'] != -1) ? round($datosSC['monto_total_pdd'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_total_pdd'],

                            'llc' => $datosSC['monto_llc'],
                            'llc_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_llc'] != -1) ? round($datosSC['monto_llc'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_llc'],

                            'flete' => $datosSC['monto_flete'],
                            'flete_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_flete'] != -1) ? round($datosSC['monto_flete'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_flete'],

                            'maniobras' => $datosSC['monto_maniobras'],
                            'maniobras_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_maniobras'] != -1) ? round($datosSC['monto_maniobras'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_maniobras'],

                            'muestras' => $datosSC['monto_muestras'],
                            'muestras_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_muestras'] != -1) ? round($datosSC['monto_muestras'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_muestras'],

                            'termo' => $datosSC['monto_termo'],
                            'termo_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_termo'] != -1) ? round($datosSC['monto_termo'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_termo'],

                            'rojos' => $datosSC['monto_rojos'],
                            'rojos_mxn' => ($datosSC['moneda'] == "USD" && $datosSC['monto_rojos'] != -1) ? round($datosSC['monto_rojos'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_rojos'],
                        ]
                    ];

                $auditoriasParaGuardar[] =
                    [
                        'operacion_id' => $operacionIdVal, 
                        'pedimento_id' => $pedimentoReal['id_pedimiento'],
                        'operation_type' => $tipoOperacionClass,
                        'folio' => $datosSC['folio_sc'],
                        'fecha_documento' => $datosSC['fecha_sc'],
                        'desglose_conceptos' => json_encode($desgloseSC),
                        'ruta_txt' => $datosSC['ruta_txt'],
                        'ruta_pdf' => $datosSC['ruta_pdf'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                //$bar->advance();
            }

            // 5. GUARDAR EN BASE DE DATOS
            if (!empty($auditoriasParaGuardar)) {

                Log::info("\nGuardando/Actualizando " . count($auditoriasParaGuardar) . " registros de SC...");

                AuditoriaTotalSC::upsert(
                    $auditoriasParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type'], // La llave única
                    ['folio', 'fecha_documento', 'desglose_conceptos', 'ruta_txt', 'ruta_pdf', 'updated_at']
                );

                Log::info("¡Guardado con éxito!");

                Log::info("Re-auditando Impuestos con las nuevas SC encontradas...");
                foreach ($auditoriasParaGuardar as $scData) {
                    $pedId = $scData['pedimento_id'];
                    $tipoOp = $scData['operation_type'];
                    $folioSc = $scData['folio'];
                    
                    $desglose = is_string($scData['desglose_conceptos']) ? json_decode($scData['desglose_conceptos'], true) : $scData['desglose_conceptos'];
                    $montoSC_MXN = (float)($desglose['montos']['impuestos_mxn'] ?? -1.1);

                    $auditoriasImpuestos = Auditoria::where('pedimento_id', $pedId)
                        ->where('tipo_documento', 'impuestos')
                        ->get();

                    if ($auditoriasImpuestos->isEmpty()) {
                        Log::warning("[ALERTA RE-AUDITORIA] Se guardó la SC Folio {$folioSc} para el pedimento_id {$pedId}, ¡Pero no se encontró ningún cargo de impuestos en la BD para emparejarlo!");
                        continue;
                    }

                    foreach ($auditoriasImpuestos as $audit) {
                        $montoImpuestoMXN = (float) $audit->monto_total_mxn;
                        
                        $nuevoEstado = $this->compararMontos($montoSC_MXN, $montoImpuestoMXN, $tipoOp);
                        $nuevaDiferencia = ($nuevoEstado !== "Sin SC!" && $nuevoEstado !== "Sin operacion!") 
                                            ? round($montoSC_MXN - $montoImpuestoMXN, 2) 
                                            : $montoImpuestoMXN;
                        
                        $audit->update([
                            'estado' => $nuevoEstado,
                            'monto_diferencia_sc' => $nuevaDiferencia
                        ]);
                        
                    }
                }
                Log::info("Re-auditoría de Impuestos completada.");

                Log::info("Actualizando mapeo...");
                $idsPedDb = \Illuminate\Support\Arr::pluck($mapaPedimentoAId, 'id_pedimiento');
                $operacionesId = array_column($auditoriasParaGuardar, 'operacion_id');

                $auditoriasSC = AuditoriaTotalSC::query()
                    ->whereIn('operacion_id', $operacionesId)
                    ->orWhereIn('pedimento_id', $idsPedDb)
                    ->get()
                    ->keyBy('pedimento_id'); // Es mucho más seguro mapear por pedimento_id

                // Adjuntamos el nuevo arreglo y lo parseamos a JSON
                $mapeadoFacturas['auditorias_sc'] = $auditoriasSC->toArray() ?? [];
                $contenidoJson = json_encode($mapeadoFacturas, JSON_PRETTY_PRINT);

                // Creamos un archivo temporal y guardamos nuestro JSON en él.
                $tempFilePath = tempnam(sys_get_temp_dir(), 'mapeo_json_');
                file_put_contents($tempFilePath, $contenidoJson);

                // Usamos Storage::putFile() para que Laravel genere el hash y lo guarde.
                // Esto es el equivalente a ->store()
                $rutaRelativa = Storage::putFile(
                    'mapeo_completo_facturas', // La carpeta destino dentro de storage/app
                    new File($tempFilePath) // Le pasamos el archivo temporal
                );

                Log::info("Mapeo actualizado con auditorias_sc!");

                //Actualizamos la ruta por la que ya tiene las auditorias
                $tarea->fresh()->update(
                    [
                        'mapeo_completo_facturas' => $rutaRelativa,
                        'updated_at' => now(),
                    ]
                );

                //Borramos el anterior
                Storage::delete($rutaMapeo);
                Log::info("SCs guardadas, actualizadas y registradas con exito!");
                return ['code' => 0, 'message' => 'completado'];
            } else {

                Log::info("No se encontraron SCs para guardar en la base de datos.");
                $mapeadoFacturas['auditorias_sc'] = [];

                // Adjuntamos el nuevo arreglo y lo parseamos a JSON
                $contenidoJson = json_encode($mapeadoFacturas, JSON_PRETTY_PRINT);

                // Creamos un archivo temporal y guardamos nuestro JSON en él.
                $tempFilePath = tempnam(sys_get_temp_dir(), 'mapeo_json_');
                file_put_contents($tempFilePath, $contenidoJson);

                // Usamos Storage::putFile() para que Laravel genere el hash y lo guarde.
                // Esto es el equivalente a ->store()
                $rutaRelativa = Storage::putFile(
                    'mapeo_completo_facturas', // La carpeta destino dentro de storage/app
                    new File($tempFilePath) // Le pasamos el archivo temporal
                );

                Log::info("Mapeo actualizado con auditorias_sc!");

                //Actualizamos la ruta por la que ya tiene las auditorias
                $tarea->fresh()->update(
                    [
                        'mapeo_completo_facturas' => $rutaRelativa,
                        'updated_at' => now(),
                    ]
                );

                //Borramos el anterior
                Storage::delete($rutaMapeo);
            }
        } catch (\Throwable $e) {
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]
            );
            Log::error("Error al procesar SC para la Tarea #{$tareaId}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Error al procesar SC para la Tarea #{$tareaId}: " . $e->getMessage())]; // Lanzamos la excepción para que el orquestador la atrape
        }
    }


/**
     * Reemplaza este método en tu AuditoriaImpuestosController.
     * Se encarga de leer el archivo de TXT de los Fletes y saltar a "QUESOS Y QUESOS".
     */
    public function auditarFacturasDeFletes(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Fletes: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return ['code' => 1, 'message' => new \Exception("Fletes: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.")];
        }

        Log::info('Iniciando la auditoría de Fletes (Transportactics)...');
        try {
            // --- FASE 1: Obtención de Mapeos ---
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal.")];
            }

            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array) json_decode($contenidoJson, true);

            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("Fletes: No hay pedimentos para procesar.");
                return ['code' => 0, 'message' => 'completado'];
            }

            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            // Construir índices en memoria para auditoría
            $indiceFletes = $this->construirIndiceOperacionesFletes($indicesOperaciones);
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = $auditoria['desglose_conceptos'];
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });
                $indiceSC[key($arrPedimento)] = [
                    'monto_flete_sc' => (float) $desglose['montos']['flete'],
                    'monto_flete_sc_mxn' => (float) $desglose['montos']['flete_mxn'],
                    'moneda' => $desglose['moneda'],
                    'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                ];
            }

            $fletesParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                
                // 1. Identificar la operación y el tipo
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                $tipoOperacion = Importacion::class;

                if (!$operacionId) {
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                    $tipoOperacion = Exportacion::class;
                }

                if (!$operacionId) {
                    continue;
                }

                $queryOp = ($tipoOperacion === Importacion::class) 
                           ? Importacion::with('cliente') 
                           : Exportacion::with('cliente');
                
                $objOperacion = $queryOp->find($operacionId['id_operacion']);

                if ($objOperacion && $objOperacion->cliente) {
                    $nombreCliente = strtoupper(trim($objOperacion->cliente->nombre));

                    if (str_contains($nombreCliente, 'QUESOS Y QUESOS')) {
                        /* Log::info("Fletes: Pedimento {$pedimentoLimpio} omitido (Cliente: QUESOS Y QUESOS). Limpiando registros previos..."); */

                        Auditoria::where('pedimento_id', $pedimentoSucioYId['id_pedimiento'])
                            ->where('tipo_documento', 'flete')
                            ->delete();

                        continue;
                    }
                }

                // Buscamos datos en los índices de archivos
                $datosFlete = $indiceFletes[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;

                if (!$datosFlete) {
                    continue;
                }

                if (!$datosSC) {
                    $datosSC = [
                        'monto_flete_sc' => -1,
                        'monto_flete_sc_mxn' => -1,
                        'tipo_cambio' => -1,
                        'moneda' => 'N/A',
                    ];
                }

                // Fusionar datos del XML con los datos generales del TXT
                $datosFlete = array_merge($datosFlete, $this->parsearXmlFlete($datosFlete['path_xml_tr']) ?? ['total' => -1, 'moneda' => 'N/A']);

                $montoFleteMXN = (($datosFlete['moneda'] == "USD" && $datosFlete['total'] != -1) && $datosSC['tipo_cambio'] != -1) 
                                 ? round($datosFlete['total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) 
                                 : $datosFlete['total'];
                
                $montoSCMXN = $datosSC['monto_flete_sc_mxn'];
                $estado = $this->compararMontos_Fletes($montoSCMXN, $montoFleteMXN);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoFleteMXN, 2) : $montoFleteMXN;

                // Formateo de fecha
                $fechaRaw = isset($datosFlete['fecha']) ? trim($datosFlete['fecha']) : null;
                if ($fechaRaw && strpos($fechaRaw, ',') !== false) {
                    $fechaRaw = trim(explode(',', $fechaRaw)[0]);
                }

                $fechaFormateada = null;
                if ($fechaRaw) {
                    try {
                        $fechaFormateada = \Carbon\Carbon::parse($fechaRaw)->format('Y-m-d');
                    } catch (\Throwable $e) {
                        $fechaFormateada = null;
                    }
                }

                $fletesParaGuardar[] = [
                    'operacion_id' => $operacionId['id_operacion'],
                    'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type' => $tipoOperacion,
                    'tipo_documento' => 'flete',
                    'concepto_llave' => 'principal',
                    'folio' => $datosFlete['folio'],
                    'fecha_documento' => $fechaFormateada,
                    'monto_total' => $datosFlete['total'],
                    'monto_total_mxn' => $montoFleteMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => $datosFlete['moneda'],
                    'estado' => $estado,
                    'ruta_xml' => $datosFlete['path_xml_tr'],
                    'ruta_txt' => $datosFlete['path_txt_tr'],
                    'ruta_pdf' => $datosFlete['path_pdf_tr'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Guardado masivo
            if (!empty($fletesParaGuardar)) {
                Auditoria::upsert(
                    $fletesParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],
                    ['fecha_documento', 'monto_total', 'monto_total_mxn', 'monto_diferencia_sc', 'moneda_documento', 'estado', 'ruta_xml', 'ruta_txt', 'ruta_pdf', 'updated_at']
                );
            }

            Log::info("Auditoría de Fletes finalizada.");
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            $tarea->update(['status' => 'fallido', 'resultado' => $e->getMessage()]);
            Log::error("Error en Fletes (Tarea #{$tarea->id}): " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }


    /**
     * Se encarga de leer el archivo de TXT de las LLCs, obteniendo los montos y datos generales necesarios para la auditoria.
     * CASO ESPECIAL: Para SONORA AGROPECUARIA se fuerza la lectura del PDF.
     */
    public function auditarFacturasDeLLC(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("LLC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return ['code' => 1, 'message' => new \Exception("LLC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.")];
        }
        Log::info('Iniciando la auditoría de facturas LLC...');

        try {
            // --- FASE 1: Construir Índices en Memoria para Búsquedas Rápidas ---
            //Iniciamos con obtener el mapeo
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                Log::error("No se encontró el archivo de mapeo universal para la tarea #{$tarea->id}.");
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal para la tarea #{$tarea->id}.")];
            }

            //Leemos y decodificamos el archivo JSON completo
            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array) json_decode($contenidoJson, true);

            //Leemos los demas campos de la tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("LLC: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return ['code' => 0, 'message' => 'completado'];
            }

            //$mapasPedimento - Contienen todos los pedimentos del estado de cuenta, encontrados en Importacion/Exportacion
            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];

            //$mapaPedimentoAId - Este arreglo contiene los pedimentos limpios, sucios, y su Id correspondiente
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];

            //$indicesOperaciones - Combina el mapeado de archivos/urls de importacion y exportacion
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            //--- YA UNA VEZ TENIENDO TODO A LA MANO
            // 3. Construimos el índice de LLC desde los archivos de importacion y exportacion
            $indiceLLC = $this->construirIndiceOperacionesLLCs($indicesOperaciones);

            //Obtenemos el resultado del query de todos los SC de los pedimentos del estado de cuenta.
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            // Aquí es donde extraemos el tipo de cambio del JSON.
            $indiceSC = [];

            foreach ($auditoriasSC as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos']) ? $auditoria['desglose_conceptos'] : json_decode($auditoria['desglose_conceptos'], true);
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                if(!empty($arrPedimento)){
                    $indiceSC[key($arrPedimento)] = [
                        'monto_llc_sc' => (float) ($desglose['montos']['llc'] ?? -1),
                        'monto_llc_sc_mxn' => (float) ($desglose['montos']['llc_mxn'] ?? -1),
                        'moneda' => $desglose['moneda'] ?? 'USD',
                        'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            // --- BÚSQUEDA DE TIPO DE CAMBIO GLOBAL DE RESCATE ---
            $tipoCambioGlobal = 1.0;
            foreach ($indiceSC as $sc) {
                if (isset($sc['tipo_cambio']) && $sc['tipo_cambio'] > 1) {
                    $tipoCambioGlobal = $sc['tipo_cambio'];
                    break; 
                }
            }

            $clientesExcluidosLLC = [
                'JULIAN FERNANDO CAJIGAS',
                'SOUTH COAST PACKING',
                'COMERCIALIZADORA INTERNACIONAL MANSIVA',
                'CREMERIAS DE OCCIDENTE',
                'COMPANIA MINERA LA PITALLA', 
                'MINAS DE ORO NACIONAL'       
            ];

            $llcsParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                $numPedRef = $pedimentoSucioYId['num_pedimiento'];
                $operacionId = $mapaPedimentoAImportacionId[$numPedRef] ?? $mapaPedimentoAExportacionId[$numPedRef] ?? null;
                $tipoOperacion = isset($mapaPedimentoAImportacionId[$numPedRef]) ? Importacion::class : (isset($mapaPedimentoAExportacionId[$numPedRef]) ? Exportacion::class : Pedimento::class);

                $idOp = $operacionId['id_operacion'] ?? null;
                $objOperacion = null;
                $nombreCliente = '';

                if ($idOp && $tipoOperacion !== Pedimento::class) {
                    $queryOp = ($tipoOperacion === Importacion::class) ? Importacion::with('cliente') : Exportacion::with('cliente');
                    $objOperacion = $queryOp->find($idOp);
                    if ($objOperacion && $objOperacion->cliente) {
                        $nombreCliente = strtoupper(trim($objOperacion->cliente->nombre));
                    }
                }

                // 1. Verificación de Exclusiones
                $omitir = false;
                foreach ($clientesExcluidosLLC as $excluido) {
                    if (str_contains($nombreCliente, $excluido)) {
                        $omitir = true;
                        break; // Si hace match con uno, dejamos de buscar
                        }
                }
                if ($omitir) {
                    Auditoria::where('pedimento_id', $pedimentoSucioYId['id_pedimiento'])->where('tipo_documento', 'llc')->delete();
                    continue; // Saltamos a la siguiente iteración del foreach
                }
                // Buscamos en nuestros índices en memoria (búsqueda instantánea)
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;
                $datosLlc = $indiceLLC[$pedimentoLimpio] ?? null;

                if (str_contains($nombreCliente, 'SONORA AGROPECUARIA')) {
                    if ($datosLlc && !empty($datosLlc['ruta_pdf'])) {
                        $pdfData = $this->extraerTotalDesdePdfProveedor($datosLlc['ruta_pdf']);
                        if ($pdfData && $pdfData['monto'] !== null) {
                            $datosLlc['monto_total'] = (float) $pdfData['monto'];
                            $datosLlc['fecha'] = $pdfData['fecha'] ?? $datosLlc['fecha'];
                            $datosLlc['folio'] = $pdfData['folio'] ?? $datosLlc['folio'];
                        }
                    }
                }

                if (!$datosLlc) continue;

                // Cálculos y Comparativa
                $tipoCambioUsar = (isset($datosSC['tipo_cambio']) && $datosSC['tipo_cambio'] > 1) ? $datosSC['tipo_cambio'] : $tipoCambioGlobal;
                $montoLLCMXN = ($tipoCambioUsar > 1) ? round($datosLlc['monto_total'] * $tipoCambioUsar, 2, PHP_ROUND_HALF_UP) : $datosLlc['monto_total'];
                $montoSCMXN = $datosSC['monto_llc_sc_mxn'] ?? -1;
                
                $estado = $this->compararMontos_LLC($montoSCMXN, $montoLLCMXN);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoLLCMXN, 2) : $montoLLCMXN;
                
                $llcsParaGuardar[] = [
                    'operacion_id' => $idOp,
                    'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type' => $tipoOperacion,
                    'tipo_documento' => 'llc',
                    'concepto_llave' => 'principal',
                    'folio' => $datosLlc['folio'] ?? 'S/F',
                    'fecha_documento' => $datosLlc['fecha'],
                    'monto_total' => $datosLlc['monto_total'],
                    'monto_total_mxn' => $montoLLCMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => 'USD',
                    'estado' => $estado,
                    'ruta_txt' => $datosLlc['ruta_txt'],
                    'ruta_pdf' => $datosLlc['ruta_pdf'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            //$bar->finish();

            // --- FASE 5: Guardado Masivo ---
            if (!empty($llcsParaGuardar)) {
                Auditoria::upsert($llcsParaGuardar, 
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], 
                    [
                        'fecha_documento',
                        'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                        'monto_total_mxn',
                        'monto_diferencia_sc',
                        'moneda_documento',
                        'estado',
                        'folio',
                        'ruta_txt',
                        'ruta_pdf',
                        'updated_at'
                    ]
                );
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]
            );
            Log::error("Falló la auditoría de LLC: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }


    //--- METODO AUDITAR E IMPORTAR PAGOS DE DERECHO A AUDITORIAS
    public function auditarFacturasDePagosDeDerecho(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Pagos derecho: Tarea no válida.")];
        }
        Log::info('--- INICIANDO AUDITORÍA PAGOS DE DERECHO (Anti-Colisiones Upsert) ---');

        try {
            $mapeadoFacturas = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'] ?? [];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'] ?? [];
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);

            $indicePagosDerecho = $this->construirIndiceOperacionesPagosDerecho($indicesOperaciones);
            $pagosParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                $tipoOperacion = Importacion::class;

                if (!$operacionId) {
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                    $tipoOperacion = Exportacion::class;
                }
                if (!$operacionId) {
                    $tipoOperacion = Pedimento::class;
                }
                if (!$operacionId) {
                    continue;
                }

                $rutasPdfs = $indicePagosDerecho[$pedimentoLimpio] ?? null;
                if (!$rutasPdfs) {
                    continue;
                }

                $parsedPdfs = [];
                foreach ($rutasPdfs as $rutaPdf) {
                    $datosPago = $this->parsearPdfPagoDeDerecho($rutaPdf);
                    if ($datosPago) {
                        $datosPago['ruta_pdf_original'] = $rutaPdf;
                        $parsedPdfs[] = $datosPago;
                    }
                }

                if (empty($parsedPdfs)) {
                    continue;
                }

                // 🚀 DEDUPLICACIÓN ESTRICTA POR LLAVE DE PAGO
                // Esto asegura que NUNCA enviemos 2 registros con la misma llave al Upsert
                $mejoresPagos = [];
                foreach ($parsedPdfs as $p) {
                    $llave = !empty($p['llave_pago']) ? $p['llave_pago'] : uniqid();

                    if (!isset($mejoresPagos[$llave])) {
                        $mejoresPagos[$llave] = $p;
                    } else {
                        // Si hay choque (misma llave), evaluamos quién gana:
                        $montoActual = (float)$p['monto_total'];
                        $montoGuardado = (float)$mejoresPagos[$llave]['monto_total'];

                        if ($montoActual > 0 && $montoGuardado <= 0) {
                            // Gana el que tiene dinero
                            $mejoresPagos[$llave] = $p;
                        } elseif ($montoActual == $montoGuardado) {
                            // Gana el que NO es Intactics
                            $nombreActual = strtoupper(basename($p['ruta_alternativa'] ?? $p['ruta_pdf_original']));
                            if (!str_contains($nombreActual, 'INTACTICS')) {
                                $mejoresPagos[$llave] = $p;
                            }
                        }
                    }
                }

                foreach ($mejoresPagos as $llaveUnica => $datosPago) {
                    $rutaFinal = $datosPago['ruta_alternativa'] ?? $datosPago['ruta_pdf_original'];
                    $nombreFinal = strtoupper(basename($rutaFinal));
                    $estadoReal = str_contains($nombreFinal, 'INTACTICS') ? 'Intactics' : 'Normal';

                    $pagosParaGuardar[] = [
                        'operacion_id' => $operacionId['id_operacion'],
                        'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                        'operation_type' => $tipoOperacion,
                        'tipo_documento' => 'pago_derecho',
                        'concepto_llave' => $llaveUnica, // <--- Garantizado que no se repite en el batch
                        'fecha_documento' => $datosPago['fecha_pago'] ?? now()->format('Y-m-d'),
                        'monto_total' => $datosPago['monto_total'],
                        'monto_total_mxn' => $datosPago['monto_total'],
                        'monto_diferencia_sc' => 0,
                        'moneda_documento' => 'MXN',
                        'estado' => $estadoReal,
                        'llave_pago_pdd' => $datosPago['llave_pago'] ?? '',
                        'num_operacion_pdd' => $datosPago['numero_operacion'] ?? '',
                        'ruta_pdf' => $rutaFinal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (!empty($pagosParaGuardar)) {
                Log::info("\nGuardando/Actualizando " . count($pagosParaGuardar) . " registros de Pagos de Derecho...");

                // El Upsert ahora sí funcionará porque la bolsa de $pagosParaGuardar 
                // ya no tiene clones peleándose por la misma llave.
                Auditoria::upsert(
                    $pagosParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], 
                    [
                        'fecha_documento',
                        'monto_total', 
                        'monto_total_mxn',
                        'monto_diferencia_sc',
                        'moneda_documento',
                        'estado',
                        'llave_pago_pdd',
                        'num_operacion_pdd',
                        'ruta_pdf',
                        'updated_at'
                    ]
                );
            }

            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            Log::error("Error PDD: " . $e->getMessage() . " en la línea " . $e->getLine());
            return ['code' => 1, 'message' => $e];
        }
    }

    // ====================================================================
    // MÉTODOS DEDICADOS EXCLUSIVAMENTE AL ENVÍO A GOOGLE SHEETS (GPC)
    // ====================================================================

    // 1. ENVÍO DE PAGOS DE DERECHO
    public function enviarAGPCPagosDeDerecho(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Enviando Pagos de Derecho a GPC...");

        try {
            // 🚀 PASO 1: EL TRADUCTOR - Leemos Google Sheets para obtener los nombres exactos
            $urlBase = app()->environment('production') 
                ? "https://docs.google.com/spreadsheets/d/1FvhWp2AeOyoiv1KIrmQNOKf9ZoDRy5L7HVd5FcRQBio/gviz/tq?tqx=out:csv&_cb=" . time()
                : "https://docs.google.com/spreadsheets/d/1yOcPGlvycRBCg5KpWs5-b8EmLQrUNPgh4aqurXQT1Uo/gviz/tq?tqx=out:csv&_cb=" . time();
            
            $csvUrlZLO = $urlBase . "&sheet=ZLO";
            $responseZLO = Http::withoutVerifying()->timeout(60)->get($csvUrlZLO);
            
            $diccionarioNombresSheet = [];
            if ($responseZLO->successful()) {
                $streamZLO = fopen('php://memory', 'r+');
                fwrite($streamZLO, $responseZLO->body());
                rewind($streamZLO);
                while (($cols = fgetcsv($streamZLO)) !== false) {
                    foreach ($cols as $col) {
                        $colTrimmed = trim($col);
                        if (preg_match('/[4-7]\d{6}/', $colTrimmed)) {
                            $diccionarioNombresSheet[] = preg_replace('/\s+/', ' ', $colTrimmed);
                        }
                    }
                }
                fclose($streamZLO);
                $diccionarioNombresSheet = array_unique($diccionarioNombresSheet);
            }

            $mapeadoFacturas = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
            $mapaFacturas = $mapeadoFacturas['pedimentos_totales'] ?? [];

            $pedimentosDelPdf = json_decode($tarea->pedimentos_procesados, true) ?? [];
            $sucursalesDic = [
                'NOG' => [1, 3711], 'TIJ' => [2, 3849], 'NL' => [3, 3711],
                'MXL' => [4, 1038], 'ZLO' => [5, 3711], 'REY' => [11, 3577], 
                'VRZ' => [12, 1864]
            ];
            $sucInfo = $sucursalesDic[$tarea->sucursal] ?? [1, 3711];
            
            $mapaPdf = [];
            if (!empty($pedimentosDelPdf)) {
                $mapaPdf = $this->construirMapaDePedimentos($pedimentosDelPdf, $sucInfo[1], $sucInfo[0]);
            }

            $mapaPedimentoAId = $mapaFacturas + $mapaPdf;
            $pagosParaSheets = [];
            
            // 🚀 PASO 2: FUSIÓN DE FAMILIAS
            $familias = [];
            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $idsActuales = $datosId['all_ids'] ?? [$datosId['id_pedimiento']];
                $mergedIndex = -1;

                preg_match_all('/[4-7]\d{6}/', $pedimentoLimpio . ' ' . ($datosId['num_pedimiento'] ?? ''), $mCurr);
                $digitosCurrent = array_unique($mCurr[0] ?? []);

                foreach ($familias as $index => $familia) {
                    if (count(array_intersect($familia['ids'], $idsActuales)) > 0) {
                        $mergedIndex = $index;
                        break;
                    }
                    
                    $digitosFamilia = [];
                    foreach ($familia['claves'] as $claveFamiliar) {
                        preg_match_all('/[4-7]\d{6}/', $claveFamiliar, $mFam);
                        $digitosFamilia = array_merge($digitosFamilia, $mFam[0] ?? []);
                    }
                    
                    if (count(array_intersect($digitosCurrent, array_unique($digitosFamilia))) > 0) {
                        $mergedIndex = $index;
                        break;
                    }
                }
                
                if ($mergedIndex !== -1) {
                    $familias[$mergedIndex]['ids'] = array_unique(array_merge($familias[$mergedIndex]['ids'], $idsActuales));
                    $familias[$mergedIndex]['claves'][] = $pedimentoLimpio;
                    if (isset($datosId['num_pedimiento'])) $familias[$mergedIndex]['claves'][] = $datosId['num_pedimiento'];
                } else {
                    $familias[] = [
                        'ids' => $idsActuales,
                        'claves' => [$pedimentoLimpio, $datosId['num_pedimiento'] ?? ''],
                        'datos' => $datosId
                    ];
                }
            }

            // 🚀 PASO 3: TRADUCIR EL NOMBRE Y PROCESAR
            foreach ($familias as $familia) {
                usort($familia['claves'], function($a, $b) { return strlen($b) - strlen($a); });
                $pedimentoAEnviar = preg_replace('/\s+/', ' ', trim($familia['claves'][0]));
                $pedimentoAEnviar = preg_replace('/\b\d{4}-/', '', $pedimentoAEnviar);

                $digitosDeNuestraFamilia = [];
                foreach ($familia['claves'] as $c) {
                    if (preg_match_all('/[4-7]\d{6}/', $c, $m)) {
                        $digitosDeNuestraFamilia = array_merge($digitosDeNuestraFamilia, $m[0]);
                    }
                }
                $digitosDeNuestraFamilia = array_unique($digitosDeNuestraFamilia);

                // TRADUCTOR: Reemplaza nuestro nombre por el que exista en Sheets
                foreach ($diccionarioNombresSheet as $nombreExactoDelSheet) {
                    foreach ($digitosDeNuestraFamilia as $digito) {
                        if (str_contains($nombreExactoDelSheet, $digito)) {
                            $pedimentoAEnviar = $nombreExactoDelSheet;
                            break 2;
                        }
                    }
                }

                $auditoriasBase = Auditoria::whereIn('pedimento_id', $familia['ids'])
                    ->where('tipo_documento', 'pago_derecho')
                    ->get()
                    ->unique(function ($aud) {
                        return ($aud->fecha_documento ?? 's/f') . '_' . round((float)$aud->monto_total, 2);
                    })->values();

                if ($auditoriasBase->isNotEmpty()) {
                    
                    $montoTotal = $auditoriasBase->sum('monto_total');
                    $auditoriaReferencia = $auditoriasBase->first();

                    if ($montoTotal > 0) {
                        $nombreCliente = '';
                        $contenedor    = '';
                        $bl            = '';
                        $naviera       = '';
                        $fechaOp       = $auditoriaReferencia->fecha_documento ?? now()->format('Y-m-d');

                        if ($auditoriaReferencia->operation_type === Importacion::class) {
                            $operacion = Importacion::with('cliente')->find($auditoriaReferencia->operacion_id);
                        } else {
                            $operacion = Exportacion::with('cliente')->find($auditoriaReferencia->operacion_id);
                        }

                        if ($operacion) {
                            $nombreCliente = optional($operacion->cliente)->nombre ?? '';
                            $contenedor = $operacion->contenedor ?? '';
                            $bl            = $operacion->bol ?? ''; 
                            $naviera       = $operacion->naviera ?? '';
                        }

                        $esRecti = $familia['datos']['es_recti'] ?? false;
                        // El nombre debe coincidir con la plantilla del Excel ("Pago de Derechos")
                        $conceptoAEnviar = $esRecti ? 'Pago de Derecho Recti' : 'Pago de Derechos'; 

                        $pagosParaSheets[] = [
                            'fecha'      => $fechaOp,
                            'cliente'    => $nombreCliente,
                            'contenedor' => $contenedor,
                            'bl'         => $bl, 
                            'naviera'    => $naviera,
                            'anticipo'   => '', 
                            'pedimento'  => $pedimentoAEnviar,
                            'concepto'   => $conceptoAEnviar, 
                            'monto'      => (float) $montoTotal,
                            'moneda'     => 'MXN'
                        ];
                    }
                }
            }

            if (!empty($pagosParaSheets)) {
                Log::info("🚀 Pagos de Derecho listos para enviar a GPC: " . count($pagosParaSheets));
                
                $paquetes = array_chunk(array_values($pagosParaSheets), 50);
                foreach ($paquetes as $index => $paquete) {
                    $numeroPaquete = $index + 1;
                    $totalPaquetes = count($paquetes);

                    // Si estamos en el último ciclo, esto será true
                    $esUltimo = ($numeroPaquete === $totalPaquetes);

                    // Le pasamos la bandera a la función
                    $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);

                    sleep(2);
                }
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error enviando Pagos de Derecho a GPC: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    //--- METODO EXPORTAR AUDITORIAS DEL ESTADO DE CUENTA A EXCEL
    // Se encarga de obtener todos los registros de las tablas de auditorias y auditorias_totales_sc y las exporta a un archivo de excel
    // el cual contendra unicamente los pedimentos encontrados dentro del estado de cuenta.
    public function exportarAuditoriasFacturadasAExcel(string $tareaId, string $esReporteDeFacturasPendientes = null)
    {
        // Limpieza de memoria para procesos largos
        gc_collect_cycles();

        // 1. Validamos la existencia de la tarea
        $tarea = AuditoriaTareas::find($tareaId);

        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Exportacion: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return ['code' => 1, 'message' => new \Exception("Exportacion: Tarea no válida.")];
        }

        Log::info("Iniciando Exportación a Excel para Tarea #{$tarea->id} | Sucursal: {$tarea->sucursal} | Tipo: " . ($esReporteDeFacturasPendientes ? 'PENDIENTES' : 'FACTURADOS'));
        
        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                throw new \Exception("No se encontró el archivo de mapeo universal para la tarea #{$tarea->id}.");
            }

            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array) json_decode($contenidoJson, true);

            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];

            if (empty($mapaPedimentoAId)) {
                Log::info("Exportacion: No hay pedimentos procesados para la Tarea #{$tareaId}.");
                return ['code' => 0, 'message' => 'completado'];
            }

            $idsPedDb = Arr::pluck($mapaPedimentoAId, 'id_pedimiento');
            // 3. Construcción de la consulta base con sus relaciones
            $query = Pedimento::query()
                ->whereIn('id_pedimiento', $idsPedDb) // Búsqueda por ID
                ->with([
                    'importacion' => function ($q) {
                        $q->with(['auditoriasRecientes.pedimento', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                    },
                    'exportacion' => function ($q) {
                        $q->with(['auditoriasRecientes.pedimento', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                    }
                ]);

            // 4. LÓGICA DE SEGREGACIÓN (Aquí es donde evitamos que se mezclen)
            $banco = strtoupper($tarea->banco ?? '');

            if ($esReporteDeFacturasPendientes === 'true') {
                $query->where(function ($q) use ($banco) {
                    if ($banco === 'SANTANDER') {
                        // Para Santander, es pendiente si NO tiene auditoría de impuestos 
                        // o si está explícitamente como "Sin SC!"
                        $q->whereDoesntHave('auditoriasRecientes', function ($auditQuery) {
                            $auditQuery->where('tipo_documento', 'impuestos');
                        })->orWhereHas('auditoriasRecientes', function ($auditQuery) {
                            $auditQuery->where('tipo_documento', 'impuestos')
                                       ->where('estado', 'Sin SC!');
                        });
                    } else {
                        // Lógica normal para otros bancos
                        $q->whereDoesntHave('auditoriasTotalSC')
                          ->whereHas('auditoriasRecientes', function ($auditQuery) {
                              $auditQuery->where('estado', 'Sin SC!');
                          });
                    }
                });
            } else {
                /**
                 * CASO: REPORTE DE FACTURADOS (NORMAL)
                 */
                if ($banco === 'SANTANDER') {
                    // Para Santander, está facturado si TIENE auditoría de impuestos válida
                    $query->whereHas('auditoriasRecientes', function ($auditQuery) {
                        $auditQuery->where('tipo_documento', 'impuestos')
                                   ->where('estado', '!=', 'Sin SC!');
                    });
                } else {
                    // Lógica normal para otros bancos
                    $query->whereHas('auditoriasTotalSC');
                }
            }

            // Ejecutamos la consulta
            $operacionesParaExportar = $query->get();

            // 5. Configuración de nombres y rutas de archivo
            $fecha = now()->format('dmY');
            $nombreArchivo = (isset($esReporteDeFacturasPendientes)) 
                ? "RDI_AF_{$tarea->sucursal}{$fecha}.xlsx"
                : "RDI_{$tarea->sucursal}{$fecha}.xlsx";

            $nombreUnico = Str::random(40) . '.xlsx';
            $rutaDeAlmacenamiento = "/reportes/{$nombreUnico}";

            $banco = strtoupper($tarea->banco);
            // URL de apoyo si es Santander
            $urlSheet = ($banco === 'SANTANDER')
                ? "https://docs.google.com/spreadsheets/d/18-5okzV-vw35V0Ugjn5KjNcWgHyZ9Qfc6pf5w4VU-2I/edit"
                : null;

            $pedimentosDescartados = $tarea->pedimentos_descartados;

            // 6. Generación del Excel usando la clase Export
            // Pasamos la colección filtrada a la clase AuditoriaFacturadoExport
            Excel::store(
                new AuditoriaFacturadoExport($operacionesParaExportar, $pedimentosDescartados, $banco, $urlSheet),
                $rutaDeAlmacenamiento,
                'storageOldProyect'
            );
            
            if (isset($esReporteDeFacturasPendientes)) {
                $tarea->update([
                    'ruta_reporte_impuestos_pendientes' => $rutaDeAlmacenamiento,
                    'nombre_reporte_pendientes' => $nombreArchivo,
                ]);
            } else {
                $tarea->update([
                    'ruta_reporte_impuestos' => $rutaDeAlmacenamiento,
                    'nombre_reporte_impuestos' => $nombreArchivo,
                ]);
            }

            Log::info("Reporte generado exitosamente: {$nombreArchivo} con " . $operacionesParaExportar->count() . " registros.");
            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            $tarea->update([
                'status' => 'fallido',
                'resultado' => "Error en exportación: " . $e->getMessage()
            ]);
            Log::error("Falló la exportación de la Tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    //--- METODO EXPORTAR AUDITORIAS PENDIENTES DEL ESTADO DE CUENTA A EXCEL
    // Se encarga de obtener todos los registros de las tablas de auditorias las cuales no tengan una SC y las exporta a un archivo de excel
    // el cual contendra unicamente los pedimentos encontrados dentro del estado de cuenta.
    public function exportarAuditoriasPendientesAExcel(string $tareaId)
    {
        gc_collect_cycles();

        return $this->exportarAuditoriasFacturadasAExcel($tareaId, 'true');
    }


    /**
     * Envía los reportes de una tarea de auditoría por correo.
     *
     * @param int $id El ID de la AuditoriaTarea
     * @param string $destinatario El correo electrónico del destinatario
     * @return \Illuminate\Http\JsonResponse
     */
    public function enviarReportesPorCorreo(string $tareaId)
    {
        try {
            // Buscamos la tarea en la base de datos. Si no la encuentra, falla.
            $tarea = AuditoriaTareas::findOrFail($tareaId);

            // Usamos la fachada Mail de Laravel SIN el "to()".
            // Al pasarle solo el "send()", Laravel ejecutará la lógica interna de tu clase 
            // EnviarReportesAuditoriaMail, respetando los destinatarios y las copias (CC).
            Mail::send(new EnviarReportesAuditoriaMail($tarea));

            // Devolvemos una respuesta de éxito.
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            // Si algo sale mal registramos el error y devolvemos una respuesta de error.
            Log::error('Fallo al enviar correo de reporte: ' . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception('No se pudo enviar el correo. Por favor, revisa los logs del sistema.')];
        }
    }

    /**
     * Envía una notificación por correo cuando una tarea de auditoría falla.
     *
     * @param \App\Models\AuditoriaTareas $tarea La tarea que falló.
     * @param \Throwable $exception La excepción que se capturó.
     * @return void
     */
    public function enviarErrorDeReportePorCorreo(AuditoriaTareas $tarea, \Throwable $exception)
    {
        try {
            $destinatario = "carlos.perez@intactics.com"; // O config('app.admin_email')

            // Usamos nuestro nuevo Mailable, pasándole la tarea y la excepción.
            Mail::to($destinatario)->send(new EnviarFalloReporteAuditoriaMail($tarea, $exception));

            Log::info("Correo de notificación de error enviado para la Tarea #{$tarea->id}.");
        } catch (\Throwable $e) {
            // Si incluso el envío del correo falla, solo lo registramos para no crear un bucle infinito.
            Log::critical("¡FALLO CRÍTICO! No se pudo enviar el correo de notificación de error para la Tarea #{$tarea->id}: " . $e->getMessage());
        }
    }
    //--------------------------------------------------------------------------------------------------------------
    //----------------------------------- FINAL DE LOS COMMANDS - AuditoriaImpuestosController ----------------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================

    //--------------------------------------------------------------------------------------------------------------
    //----------------------------- INICIO DE LOS METODOS INDEXANTES - AuditoriaImpuestosController -----------------------------
    //--------------------------------------------------------------------------------------------------------------


    /**
     * Lógica central para obtener y procesar los archivos de la API.
     * UTILIZADO EN [mapearFacturasYFacturasSCEnAuditorias()]
     */
    private function construirIndiceFacturasParaMapeo(Collection $pedimentosOperacion, string $sucursal): array
    {
        gc_collect_cycles();
        $indiceFacturas = [];

        $mapeoFacturas = 
            [
                'HONORARIOS-SC' => 'sc',
                'TransporTactics' => 'flete',
                'HONORARIOS-LLC' => 'llc',
                'PAGOS-DE-DERECHOS' => 'pago_derecho',
                'FACTURA-MUESTRA' => 'muestras',
                'FACTURA-MANIOBRAS-EN-ALMACEN-FISCALIZADO'=> 'maniobras',
                'PROVEEDORES' => 'proveedores',
                'TERMINAL' => 'terminal',
                'VACIOS' => 'vacios',
                'PAGO-DE-MUESTRAS' => 'pago_muestras',
                'OTROS' => 'otros'
            ];

        foreach ($pedimentosOperacion as $pedimento => $operacionID) {
            try {
                $idOp = $operacionID['id_operacion'] ?? null;
                $tipoReal = strtolower($operacionID['tipo'] ?? 'sin operacion');

                // Si no hay operación válida o no hay ID, no perdemos tiempo (El filtro anti-fantasmas)
                if (empty($idOp) || $tipoReal === 'sin operacion') {
                    continue;
                }

                // Ajustamos el string para la API
                $tipoApi = ($tipoReal === 'importacion') ? 'importaciones' : 'exportaciones';
                $archivos_pdf = [];

                // 1. Buscar archivos en la operación original usando la API correcta
                $urlApi = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/get-files-momentaneo";
                $url_pdf = Http::withoutVerifying()->get($urlApi);

                if ($url_pdf->successful() && is_array($url_pdf->json())) {
                    $archivos_pdf = array_merge($archivos_pdf, $url_pdf->json());
                }

                // Determinamos el modelo basándonos en el TIPO REAL, no en un parámetro duro
                $modeloOp = ($tipoReal === 'importacion') ? Importacion::find($idOp) : Exportacion::find($idOp);

                // Si la operación existe y tiene un padre asignado
                if ($modeloOp && !empty($modeloOp->parent)) {
                    $parentId = $modeloOp->parent;
                    
                    $urlApiParent = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$parentId}/get-files-momentaneo";
                    $url_pdf_parent = Http::withoutVerifying()->get($urlApiParent);

                    if ($url_pdf_parent->successful() && is_array($url_pdf_parent->json())) {
                        // Mezclamos los archivos del padre con los del hijo
                        $archivos_pdf = array_merge($archivos_pdf, $url_pdf_parent->json());
                    }
                }

                if (empty($archivos_pdf)) {
                    continue;
                }
                
                $indiceFacturas[$pedimento] = [
                    'tipo_operacion' => $tipoApi, // Guardamos el real
                    'operacion_id' => $idOp,
                    'facturas' => [], 
                ];

                $agrupadorTemp = [];

                foreach ($archivos_pdf as $archivo) {
                    $pivotType = $archivo['pivot']['type'] ?? '';
                    if (empty($pivotType)) {
                        continue;
                    }

                    $normalizedPivot = strtolower(str_replace([' ', '-', '_'], '', $pivotType));
                    $tipoFinal = null;
                    $tipoOriginalMapeo = null;

                    foreach ($mapeoFacturas as $key => $value) {
                        $normalizedKey = strtolower(str_replace([' ', '-', '_'], '', $key));
                        if ($normalizedPivot === $normalizedKey) {
                            $tipoFinal = $value;
                            $tipoOriginalMapeo = $key;
                            break;
                        }
                    }

                    if (!$tipoFinal && in_array(strtolower($pivotType), $mapeoFacturas)) {
                        $tipoFinal = strtolower($pivotType);
                    }

                    if (!$tipoFinal) {
                        continue;
                    }

                    $url = $archivo['url']['normal'] ?? '';
                    $nombreArchivoReal = strtoupper($archivo['name'] ?? '');
                    $fechaCreacion = $archivo['created_at'] ?? now();
                    $fechaActualizacion = $archivo['updated_at'] ?? now();
                    
                    $nombreBase = pathinfo($archivo['name'] ?? '', PATHINFO_FILENAME);
                    $extension = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));

                    if (($tipoFinal === 'proveedores' || $tipoFinal === 'terminal') && str_contains($nombreArchivoReal, 'VACIO')) {
                        $tipoFinal = 'vacios';
                    }

                    $grupoEncontrado = false;
                    $sufijo = 0;
                    $llaveGrupo = $nombreBase;

                    while (!$grupoEncontrado) {
                        $llaveGrupo = $sufijo === 0 ? $nombreBase : $nombreBase . '_' . $sufijo;
                        
                        if (!isset($agrupadorTemp[$llaveGrupo])) {
                            $agrupadorTemp[$llaveGrupo] = [
                                'creation_date' => $fechaCreacion,
                                'update_date' => $fechaActualizacion,
                                'tipo_documento' => $tipoFinal,
                                'ruta_pdf' => null,
                                'ruta_xml' => null,
                                'ruta_txt' => null,
                            ];
                            $grupoEncontrado = true;
                        } else {
                            if ($extension === 'pdf' && $agrupadorTemp[$llaveGrupo]['ruta_pdf'] === null) {
                                $grupoEncontrado = true; 
                            } elseif ($extension === 'xml' && $agrupadorTemp[$llaveGrupo]['ruta_xml'] === null) {
                                $grupoEncontrado = true; 
                            } else {
                                $sufijo++;
                            }
                        }
                    }

                    if ($extension === 'pdf') {
                        $agrupadorTemp[$llaveGrupo]['ruta_pdf'] = $url;
                    } elseif ($extension === 'xml') {
                        $agrupadorTemp[$llaveGrupo]['ruta_xml'] = $url;
                    }

                    // Codificamos el nombre base para que las URLs de TXT no se rompan por espacios
                    $nombreBaseCodificado = rawurlencode($nombreBase);

                    if ($tipoFinal === 'sc' || $tipoOriginalMapeo === 'HONORARIOS-SC' || $tipoFinal === 'flete') {
                        $agrupadorTemp[$llaveGrupo]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/{$nombreBaseCodificado}.txt";
                    } elseif ($tipoFinal === 'llc' || $tipoOriginalMapeo === 'HONORARIOS-LLC') {
                        $agrupadorTemp[$llaveGrupo]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/llc-{$nombreBaseCodificado}.txt";
                    }
                }

                $indiceFacturas[$pedimento]['facturas'] = $agrupadorTemp;
            } catch (\Throwable $e) {
                Log::error("Error procesando operacion {$operacionID['id_operacion']}: " . $e->getMessage());
            }
        }
        return $indiceFacturas;
    }


    /**
     * Lógica central para obtener y procesar los archivos de la factura SC e indexarlos por pedimentos.
     * UTILIZADO EN [mapearFacturasYFacturasSCEnAuditorias()]
     */
    private function construirIndiceSC(array $indicesOperacion): array
    {
        gc_collect_cycles();
        try {

            $indice = [];
            //$bar = $this->output->createProgressBar(count($indicesOperacion));
            //$bar->start();

            foreach ($indicesOperacion as $pedimento => $datos) {

                //Si el archivo mapeado conto con un error no controlado, se continua, ignorandolo.
                if (isset($datos['error'])) {
                    //$bar->advance();
                    continue;
                }
                $te = $datos['tipo_operacion'];
                $coleccionFacturas = collect($datos['facturas']);
                $facturaSC = $coleccionFacturas->first(function ($factura) {
                    // La condición es la misma que ya tenías.
                    return $factura['tipo_documento'] === 'sc' &&
                        isset($factura['ruta_pdf']) && isset($factura['ruta_txt']);
                });

                if (!$facturaSC) {
                    //$bar->advance();
                    continue;
                }

                $contenido = null;
                try {   
                    // Intento original: Lee el archivo desde la ruta directa en el storage
                    $contenido = @file_get_contents($facturaSC['ruta_txt']);
                } catch (\Throwable $th) {
                    $contenido = null;
                }
                // Si el archivo no existe, OR si lo que leyó no es una SC real
                if (!$contenido || stripos($contenido, '[encOBSERVACION]') === false) {

                    $operacionID = $datos['operacion_id'];
                    $url_txt = Http::withoutVerifying()->get("https://sistema.intactics.com/v3/operaciones/{$datos['tipo_operacion']}/{$operacionID}/get-files-txt-momentaneo");

                    if ($url_txt->successful()) {
                        $urls = json_decode($url_txt->body(), true);

                        //$arrContextOptions resuelve el siguiente error:
                        //'file_get_contents(): SSL operation failed with code 1. OpenSSL Error messages:
                        //error:1416F086:SSL routines:tls_process_server_certificate:certificate verify failed'
                        $arrContextOptions = [
                            "ssl" => [
                                "verify_peer" => false,
                                "verify_peer_name" => false,
                            ],
                        ];

                        $rutaCorrecta = null;
                        if ($urls && is_array($urls)) {
                            // Limpiamos la ruta del PDF por si incluye parámetros extras en la URL
                            $rutaPdfLimpia = explode('?', $facturaSC['ruta_pdf'] ?? '')[0];
                            $nombrePdfBase = pathinfo($rutaPdfLimpia, PATHINFO_FILENAME);
                            
                            foreach ($urls as $url) {
                                if (!isset($url['path'])) {
                                    continue;
                                }
                                $pathLocal = strtolower($url['path']);
                                
                                // 1. Descartamos explícitamente los archivos de Notas de Cargo
                                if (strpos($pathLocal, 'notacargo') === false) {
                                    
                                    // 2. Si el nombre del TXT coincide con el del PDF, encontramos el archivo exacto
                                    if ($nombrePdfBase && strpos($pathLocal, strtolower($nombrePdfBase)) !== false) {
                                        $rutaCorrecta = $url['path'];
                                        break;
                                    }
                                    
                                    // 3. Si no hay coincidencia exacta de nombre pero es un TXT válido, lo dejamos como candidato
                                    if (!$rutaCorrecta) {
                                        $rutaCorrecta = $url['path'];
                                    }
                                }
                            }
                            
                            // 4. Si el filtro estricto falló por completo, usamos el primer archivo como último recurso
                            if (!$rutaCorrecta && count($urls) > 0) {
                                $rutaCorrecta = $urls[0]['path'];
                            }
                        }

                        if ($rutaCorrecta) {
                            $contenido = @file_get_contents('https://sistema.intactics.com' . $rutaCorrecta, false, stream_context_create($arrContextOptions));
                        }
                    }
                }

                if (!$contenido) {
                    //$bar->advance();
                    continue;
                }
                // Refinamiento: Regex más preciso para el pedimento en la observación.
                // Explicación rápida del patrón:
                // \[encOBSERVACION\]   -> busca la etiqueta
                // [^\d]*               -> salta cualquier cosa no numérica hasta el primer dígito
                // (?:\d{1,5}-)*        -> acepta prefijos como "3711-" o "3711-3711-" repetidos (opcional)
                // ([45]\d{6})          -> captura el pedimento: 7 dígitos empezando con 4 o 5
                // Se agregó [4-7] para aceptar pedimentos que inician con 4, 5, 6 o 7
                if (preg_match('/\[encOBSERVACION\][^\r\n]*?(?:(?:\d{1,5}[-\s]*)+)?([4-7]\d{6})/i', $contenido, $matchesPedimento)) {

                    $pedimentoLimpioBD = trim($matchesPedimento[1]);
                    //[encTEXTOEXTRA1] = IMPUESTOS (EDC - SC)
                    preg_match('/\[encTEXTOEXTRA1\](.*?)(\r|\n)/', $contenido, $matchM_Impuesto);
                    //[encTEXTOEXTRA2] = EMISION DE CERTIFICADO INTERNACIONAL (PAGOS DE DERECHO - SADER)
                    preg_match('/\[encTEXTOEXTRA2\](.*?)(\r|\n)/', $contenido, $matchM_PDD);
                    //[encTEXTOEXTRA3] = GASTOS GENERADOS EN ESTADOS UNIDOS (LLC)
                    preg_match('/\[encTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchM_LLC);
                    //[cteTEXTOEXTRA2] = FLETE (TRANSPORTACTICS)
                    preg_match('/\[cteTEXTOEXTRA2\](.*?)(\r|\n)/', $contenido, $matchM_Tr);
                    //[cteTEXTOEXTRA3] = MANIOBRAS
                    preg_match('/\[cteTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchM_Man);
                    //[cteCTAMENSAJERIA] = MUESTRAS
                    preg_match('/\[cteCTAMENSAJERIA\](.*?)(\r|\n)/', $contenido, $matchM_Mue);
                    //[encFECHA] = FECHA (REALMENTE NO EXISTE EN LA SC)
                    //preg_match('/\[encFECHA\](.*?)(\r|\n)/', $contenido, $matchFecha);
                    //[encFOLIOVENTA] = FOLIO
                    preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);
                    //[cteCODMONEDA] = MONEDA
                    preg_match('/\[cteCODMONEDA\](.*?)(\r|\n)/', $contenido, $matchMoneda);
                    //[encIMPORTEEXTRA4] = TOTAL FACTURA SC (ESTE NO LO USO, PERO LO AGREGO)
                    preg_match('/\[encIMPORTEEXTRA4\](.*?)(\r|\n)/', $contenido, $matchTotalSC);

                    // Extraemos el tipo de cambio de [encTIPOCAMBIO] y en [cteIMPORTEEXTRA1]
                    $matchTCCount = preg_match('/\[cteIMPORTEEXTRA1\]([^\r\n]*)/', $contenido, $matchTC);

                    if ($matchTCCount == 0) {
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    } elseif (($matchTC[1] == "1" && $matchMoneda[1] == "2")) {
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }

                    $indice[$pedimentoLimpioBD] =
                        [
                            'monto_impuestos' => isset($matchM_Impuesto[1]) && strlen($matchM_Impuesto[1]) > 0 ? (float) trim($matchM_Impuesto[1]) : -1,
                            'monto_flete' => isset($matchM_Tr[1]) && strlen($matchM_Tr[1]) > 0 ? (float) trim($matchM_Tr[1]) : -1,
                            'monto_llc' => isset($matchM_LLC[1]) && strlen($matchM_LLC[1]) > 0 ? (float) trim($matchM_LLC[1]) : -1,
                            'monto_total_pdd' => isset($matchM_PDD[1]) && strlen($matchM_PDD[1]) > 0 ? (float) trim($matchM_PDD[1]) : -1,
                            'monto_maniobras' => isset($matchM_Man[1]) && strlen($matchM_Man[1]) > 0 ? (float) trim($matchM_Man[1]) : -1,
                            'monto_muestras' => isset($matchM_Mue[1]) && strlen($matchM_Mue[1]) > 0 ? (float) trim($matchM_Mue[1]) : -1,

                            'folio_sc' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                            'fecha_sc' => \Carbon\Carbon::parse(trim($facturaSC['update_date']))->format('Y-m-d'),
                            //isset($matchFecha[2]) ? \Carbon\Carbon::parse(trim($matchFecha[1]))->format('Y-m-d') : now(), //ESTO PUEDES DECIRLE QUE TE LO IGUAL A NULL, NO HAY FECHA DENTRO DE LA SC
                            'ruta_txt' => $facturaSC['ruta_txt'],
                            'ruta_pdf' => $facturaSC['ruta_pdf'],
                            'moneda' => isset($matchMoneda[1]) && $matchMoneda[1] == "1" ? "MXN" : "USD",
                            'tipo_cambio' => isset($matchTC[1]) ? (float) trim($matchTC[1]) : 1.0,
                            'monto_total_sc' => isset($matchTotalSC[1]) && strlen($matchTotalSC[1]) > 0 ? (float) trim($matchTotalSC[1]) : -1,
                        ];

                    // Guardamos un alias con el pedimento original del array (ej. 3711-6002071) por si la base de datos lo pide así
                    if ($pedimentoLimpioBD !== $pedimento) {
                        $indice[$pedimento] = $indice[$pedimentoLimpioBD];
                    }

                    $aux = $this->extraerMultiplesConceptos($contenido);
                    if ($aux['monto_maniobras_2'] > $indice[$pedimentoLimpioBD]['monto_maniobras']) {
                        $indice[$pedimentoLimpioBD]['monto_maniobras'] = $aux['monto_maniobras_2'];
                        $indice[$pedimento]['monto_maniobras'] = $aux['monto_maniobras_2']; // Reflejo al alias
                    }

                    unset($aux['monto_maniobras_2']);
                    $indice[$pedimentoLimpioBD] = array_merge($indice[$pedimentoLimpioBD], $aux);
                    $indice[$pedimento] = array_merge($indice[$pedimento], $aux);
                }
                //$bar->advance();
            }
        } catch (\Throwable $e) {
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }
        return $indice;
    }


    /**
     * Lee todos los TXT de Fletes recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceOperacionesFletes(array $indicesOperacion): array
    {
        gc_collect_cycles();
        try {
            $indice = [];

            //$bar = $this->output->createProgressBar(count($indicesOperacion));
            //$bar->start();
            foreach ($indicesOperacion as $pedimento => $datos) {

                //Si el archivo mapeado conto con un error no controlado, se continua, ignorandolo.
                if (isset($datos['error'])) {
                    //$bar->advance();
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas']);
                $facturaFlete = $coleccionFacturas->first(function ($factura) {
                    // La condición es la misma que ya tenías.
                    return $factura['tipo_documento'] === 'flete' &&
                        isset($factura['ruta_pdf']) && isset($factura['ruta_txt']) && isset($factura['ruta_xml']);
                });

                if (!$facturaFlete) {
                    //$bar->advance();
                    continue;
                }
                try {   //Cuando la URL esta mal construida, lo que se hace es buscar por medio del get el txt
                    $contenido = file_get_contents($facturaFlete['ruta_txt']);
                } catch (\Throwable $th) {

                    $operacionID = $datos['operacion_id'];
                    $url_txt = Http::withoutVerifying()->get("https://sistema.intactics.com/v3/operaciones/{$datos['tipo_operacion']}/{$operacionID}/get-files-txt-momentaneo");

                    if (!$url_txt->successful()) {
                        // Si la API falla para este ID, lo saltamos y continuamos con el siguiente.
                        Log::warning("No se pudieron obtener los archivos para la importación ID: {$operacionID}");
                        //$bar->advance();
                        continue;
                    }

                    $urls = json_decode($url_txt, true);

                    //$arrContextOptions resuelve el siguiente error:
                    //'file_get_contents(): SSL operation failed with code 1. OpenSSL Error messages:
                    //error:1416F086:SSL routines:tls_process_server_certificate:certificate verify failed'
                    $arrContextOptions = [
                        "ssl" => [
                            "verify_peer" => false,
                            "verify_peer_name" => false,
                        ],
                    ];
                    //$urls[0] - SC
                    //$urls[2] - Flete
                    $contenido = $urls ? file_get_contents('https://sistema.intactics.com' . $urls[2]['path'], false, stream_context_create($arrContextOptions)) : null;
                }

                if (!$contenido) {
                    //$bar->advance();
                    continue;
                }

                // Refinamiento: Regex más preciso para el pedimento en la observación.
                if (preg_match('/\[encOBSERVACION\][^\d]*(?:\d{1,5}-)*([4-7]\d{6})/i', $contenido, $matches)) {
                    preg_match('/\[cteTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchFecha);
                    preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);
                    $pedimento = $matches[1];
                    $indice[$pedimento] =
                        [
                            'folio' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                            'path_txt_tr' => $facturaFlete['ruta_txt'],
                            'path_xml_tr' => $facturaFlete['ruta_xml'],
                            'path_pdf_tr' => $facturaFlete['ruta_pdf'],
                            'fecha' => isset($matchFecha[1]) ? trim($matchFecha[1]) : null,
                        ];
                }
                //$bar->advance();
            }
        } catch (\Throwable $e) {
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }

        return $indice;
    }

    /**
     * Lee los archivos y crea un mapa EXCLUSIVO para Almacenaje.
     */
    private function construirIndiceOperacionesAlmacenaje(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas']);
                $facturasAgrupadas = [];
                
                foreach ($coleccionFacturas as $factura) {
                    $rutaF = $factura['ruta_pdf'] ?? $factura['ruta_xml'] ?? '';
                    if (empty($rutaF)) {
                        continue;
                    }

                    $filename = pathinfo(parse_url($rutaF, PHP_URL_PATH), PATHINFO_FILENAME);
                    
                    $nombreLimpio = preg_replace('/^\d+[-_]+/', '', $filename);
                    $nombreLimpio = preg_replace('/[-_\s]?\(\d+\)$/', '', $nombreLimpio);
                    $nombreLimpio = preg_replace('/[-_]+\d+$/', '', $nombreLimpio);

                    if (empty($nombreLimpio)) {
                        $nombreLimpio = 'UNK_' . uniqid();
                    }

                    if (!isset($facturasAgrupadas[$nombreLimpio])) {
                        $facturasAgrupadas[$nombreLimpio] = [
                            'ruta_pdf' => null,
                            'ruta_xml' => null,
                            'tipo_documento' => strtolower($factura['tipo_documento'] ?? ''),
                            'folio' => $factura['folio'] ?? $nombreLimpio,
                            'nombre_archivo_real' => strtolower($filename) // 🚀 CAPTURAMOS EL NOMBRE REAL
                        ];
                    }

                    if (!empty($factura['ruta_pdf'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_pdf'] = $factura['ruta_pdf'];
                    }
                    if (!empty($factura['ruta_xml'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_xml'] = $factura['ruta_xml'];
                    }
                }

                $facturasAlmacenaje = collect(array_values($facturasAgrupadas))->filter(function ($factura) {
                    $tipo = $factura['tipo_documento'] ?? '';
                    // 🚀 BUSCAMOS EN EL FOLIO Y EN EL NOMBRE DEL ARCHIVO FÍSICO
                    $textoParaBuscar = strtolower(($factura['folio'] ?? '') . ' ' . ($factura['nombre_archivo_real'] ?? ''));
                    
                    $esCarpetaValida = str_contains($tipo, 'proveedor') || str_contains($tipo, 'terminal');
                    $esAlmacenaje = str_contains($textoParaBuscar, 'almacen') || str_contains($textoParaBuscar, 'storage');
                    
                    return $esCarpetaValida && $esAlmacenaje && !empty($factura['ruta_pdf']);
                });

                foreach ($facturasAlmacenaje as $factura) {
                    $indice[$pedimento][] = [
                        'folio'    => $factura['folio'],
                        'ruta_xml' => $factura['ruta_xml'] ?? null, 
                        'ruta_pdf' => $factura['ruta_pdf'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de almacenaje: " . $e->getMessage());
        }
        return $indice;
    }

    //--- METODO DEDICADO: EXTRAER TOTAL DE ALMACENAJE Y ENVIAR A GPC
    public function enviarAGPCTotalAlmacenaje(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Iniciando extracción de Total de Almacenaje...");

        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal.")];
            }

            $mapeadoFacturas = (array) json_decode(Storage::get($rutaMapeo), true);
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);

            $auditoriasSC = $mapeadoFacturas['auditorias_sc'] ?? [];
            $indiceTiposCambio = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos']) ? $auditoria['desglose_conceptos'] : json_decode($auditoria['desglose_conceptos'], true);
                $indiceTiposCambio[$auditoria['pedimento_id']] = (float) ($desglose['tipo_cambio'] ?? 1.0);
            }

            $indiceAlmacen = $this->construirIndiceOperacionesAlmacenaje($indicesOperaciones);
            $almacenajeParaSheets = []; 

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $listaFacturasAlmacen = $indiceAlmacen[$pedimentoLimpio] ?? [];
                if (empty($listaFacturasAlmacen)) {
                    continue;
                }

                $opId = $datosId['id_operacion'];
                $tipoOp = ($datosId['tipo'] == 'Importacion') ? Importacion::class : Exportacion::class;
                $queryOp = ($tipoOp === Importacion::class) ? Importacion::with('cliente')->find($opId) : Exportacion::with('cliente')->find($opId);

                $filasTemp = [];
                $montosRegistrados = [];

                foreach ($listaFacturasAlmacen as $factura) {
                    $info = $this->extraerMontoYNaviera($factura['ruta_xml'] ?? null, $factura['ruta_pdf'] ?? null);

                    if ($info['monto'] > 0) {
                        $tipoCambio = $indiceTiposCambio[$datosId['id_pedimiento']] ?? 1.0;
                        $montoMXN = $info['monto'];
                        
                        if (!empty($factura['ruta_xml'])) {
                            $xmlData = $this->parsearXmlFlete($factura['ruta_xml']);
                            if ($xmlData && strtoupper($xmlData['moneda']) === 'USD') {
                                $montoMXN = round($info['monto'] * $tipoCambio, 2);
                            }
                        }

                        $numeroDeContenedores = $this->contarContenedoresUniversal($factura['ruta_xml'] ?? null, $factura['ruta_pdf'] ?? null);
                        $montoDivididoMXN = round($montoMXN / $numeroDeContenedores, 2);

                        // Deduplicación estricta por monto dividido
                        $montoKey = (string)$montoDivididoMXN;
                        if (in_array($montoKey, $montosRegistrados)) {
                            continue;
                        }
                        $montosRegistrados[] = $montoKey;

                        $indexActual = count($filasTemp);
                        
                        // Permite hasta dos entradas con el mismo nombre base para el formato manual
                        if ($indexActual === 0 || $indexActual === 1) {
                            $conceptoNombre = 'Almacen';
                        } else {
                            $conceptoNombre = 'Almacen ' . ($indexActual + 1);
                        }
                        
                        $filasTemp[] = [
                            'fecha'      => $info['fecha'] ?: now()->format('Y-m-d'),
                            'cliente'    => $queryOp ? optional($queryOp->cliente)->nombre : '',
                            'contenedor' => $queryOp ? ($queryOp->contenedor ?? '') : '',
                            'bl'         => $queryOp ? ($queryOp->bol ?? '') : '', 
                            'naviera'    => $info['naviera'], 
                            'pedimento'  => $pedimentoLimpio,
                            'concepto'   => $conceptoNombre,
                            'monto'      => (float) $montoDivididoMXN,
                            'moneda'     => 'MXN'
                        ];
                    }
                }

                // Vaciamos el paquete filtrado por pedimento al general
                if (!empty($filasTemp)) {
                    $sumaConsolidada = 0;
                    foreach ($filasTemp as $fila) {
                        $sumaConsolidada += $fila['monto'];
                    }
                    
                    $filaFinal = $filasTemp[0];
                    $filaFinal['monto'] = $sumaConsolidada;
                    $filaFinal['concepto'] = 'Almacen'; // Aseguramos el nombre base sin sufijos numéricos
                    
                    $almacenajeParaSheets[] = $filaFinal;
                }
            }

            if (!empty($almacenajeParaSheets)) {
                $paquetes = array_chunk($almacenajeParaSheets, 50);
                foreach ($paquetes as $idx => $paquete) {
                    $esUltimo = ($idx === count($paquetes) - 1);
                    $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                    sleep(2);
                }
                Log::info("Enviados " . count($almacenajeParaSheets) . " registros de Almacenaje a Sheets.");
            } else {
                Log::info("Almacenaje: No se encontraron datos válidos para enviar a Sheets.");
            }

            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            Log::error("Error extrayendo Total de Almacenaje a GPC: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    //--- METODO AUDITAR E IMPORTAR PAGOS DE ALMACÉN DESDE GOOGLE SHEETS EXTERNO Y ENVIAR AL REPORTE
    public function enviarAGPCFacturasDeAlmacen(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Extrayendo ALMAN (Cruce de Información con ZLO activado y forzado)...");

        try {
            if (app()->environment('production')) {
                $urlBase = "https://docs.google.com/spreadsheets/d/1FvhWp2AeOyoiv1KIrmQNOKf9ZoDRy5L7HVd5FcRQBio/gviz/tq?tqx=out:csv&_cb=" . time();
            } else {
                $urlBase = "https://docs.google.com/spreadsheets/d/1yOcPGlvycRBCg5KpWs5-b8EmLQrUNPgh4aqurXQT1Uo/gviz/tq?tqx=out:csv&_cb=" . time();
            }

            $csvUrlZLO = $urlBase . "&sheet=ZLO";
            $csvUrlALMAN = $urlBase . "&sheet=ALMAN";

            // ====================================================================
            // PASO A: LEER LA HOJA ZLO EN MEMORIA PARA CREAR EL MAPA MAESTRO
            // ====================================================================
            Log::info("🔄 Leyendo pestaña ZLO para extraer emparejamientos de contenedores y pedimentos...");
            $responseZLO = Http::withoutVerifying()->timeout(60)->get($csvUrlZLO);
            if (!$responseZLO->successful()) {
                throw new \Exception("Error al leer la hoja ZLO. HTTP: " . $responseZLO->status());
            }

            $streamZLO = fopen('php://memory', 'r+');
            fwrite($streamZLO, $responseZLO->body());
            rewind($streamZLO);

            $zloIdxPedimento = -1;
            $zloIdxContenedor = -1;

            $mapaContenedorAPedimentoZLO = [];
            $mapaContenedorAExactoZLO = []; 
            $ultimoPedimentoZLO = '';

            // Primero, leemos todas las filas para procesarlas
            $rowsZLO = [];
            while (($colsZLO = fgetcsv($streamZLO)) !== false) {
                if (empty(trim(implode('', $colsZLO)))) {
                    continue;
                }
                $rowsZLO[] = $colsZLO;
            }
            fclose($streamZLO);

            // Búsqueda de cabeceras
            foreach ($rowsZLO as $colsZLO) {
                if ($zloIdxPedimento === -1 || $zloIdxContenedor === -1) {
                    foreach ($colsZLO as $i => $col) {
                        $txt = strtolower(trim($col));
                        if (strpos($txt, 'pedimento') !== false && $zloIdxPedimento === -1) {
                            $zloIdxPedimento = $i;
                        }
                        if (strpos($txt, 'contenedor') !== false && $zloIdxContenedor === -1) {
                            $zloIdxContenedor = $i;
                        }
                    }
                }
                // Sabueso Inteligente
                if ($zloIdxPedimento === -1 || $zloIdxContenedor === -1) {
                    foreach ($colsZLO as $i => $val) {
                        $valClean = trim($val);
                        if ($zloIdxPedimento === -1 && preg_match('/[4-7]\d{6}/', $valClean)) {
                            $zloIdxPedimento = $i;
                        }
                        if ($zloIdxContenedor === -1 && preg_match('/[A-Za-z]{4}[\s\-]*\d{7}/', $valClean)) {
                            $zloIdxContenedor = $i;
                        }
                    }
                }
                if ($zloIdxPedimento !== -1 && $zloIdxContenedor !== -1) {
                    break;
                }
            }

            $pColZLO = ($zloIdxPedimento !== -1) ? $zloIdxPedimento : 1; 
            $cColZLO = ($zloIdxContenedor !== -1) ? $zloIdxContenedor : 2; 

            // Recorrido oficial ZLO (Herencia garantizada)
            foreach ($rowsZLO as $colsZLO) {
                $pedimentoCeldaZLO  = trim($colsZLO[$pColZLO] ?? '');
                $contenedorCeldaZLO = trim($colsZLO[$cColZLO] ?? '');

                if (preg_match('/[4-7]\d{6}/', $pedimentoCeldaZLO, $mPed)) {
                    $ultimoPedimentoZLO = $mPed[0];
                }

                if ($contenedorCeldaZLO !== '' && $ultimoPedimentoZLO !== '') {
                    $contenedoresAExtraer = [];
                    if (preg_match_all('/[A-Za-z]{4}[\s\-]*\d{7}/', $contenedorCeldaZLO, $matches)) {
                        $contenedoresAExtraer = $matches[0];
                    } else {
                        $posibles = preg_split('/[\s,\n\r]+/', $contenedorCeldaZLO);
                        foreach ($posibles as $posible) {
                            $limpio = strtoupper(preg_replace('/[^A-Z0-9]/', '', $posible));
                            if (strlen($limpio) >= 10 && strlen($limpio) <= 12) {
                                $contenedoresAExtraer[] = $limpio;
                            }
                        }
                    }

                    foreach ($contenedoresAExtraer as $cont) {
                        $limpio = strtoupper(preg_replace('/[^A-Z0-9]/', '', $cont));
                        $mapaContenedorAPedimentoZLO[$limpio] = $ultimoPedimentoZLO;
                        $mapaContenedorAExactoZLO[$limpio] = $contenedorCeldaZLO; 
                    }
                }
            }
            
            Log::info("✓ Mapeo maestro ZLO finalizado. Contenedores indexados correctamente.");

            // ====================================================================
            // PASO B: LEER LA HOJA ALMAN Y PROCESAR MONTOS
            // ====================================================================
            Log::info("🔄 Leyendo pestaña ALMAN para procesar los cobros...");
            $responseALMAN = Http::withoutVerifying()->timeout(60)->get($csvUrlALMAN);
            if (!$responseALMAN->successful()) {
                throw new \Exception("Error al leer la hoja ALMAN. HTTP: " . $responseALMAN->status());
            }

            $streamALMAN = fopen('php://memory', 'r+');
            fwrite($streamALMAN, $responseALMAN->body());
            rewind($streamALMAN);

            $rowsALMAN = [];
            while (($columnas = fgetcsv($streamALMAN)) !== false) {
                if (empty(trim(implode('', $columnas)))) {
                    continue;
                }
                $rowsALMAN[] = $columnas;
            }
            fclose($streamALMAN);

            $idxFolio = -1;
            $idxTotal = -1;
            $idxContenedor = -1;
            $idxPedimento = -1;
            
            foreach ($rowsALMAN as $columnas) {
                if ($idxFolio === -1 || $idxContenedor === -1 || $idxTotal === -1) {
                    foreach ($columnas as $i => $col) {
                        $txt = strtolower(trim($col));
                        if (strpos($txt, 'folio') !== false && $idxFolio === -1) {
                            $idxFolio = $i;
                        }
                        if (strpos($txt, 'total') !== false && $idxTotal === -1) {
                            $idxTotal = $i;
                        }
                        if (strpos($txt, 'contenedor') !== false && $idxContenedor === -1) {
                            $idxContenedor = $i;
                        }
                        if (strpos($txt, 'pedimento') !== false && $idxPedimento === -1) {
                            $idxPedimento = $i;
                        }
                    }
                }
                if ($idxFolio !== -1 && $idxContenedor !== -1 && $idxTotal !== -1) break;
            }

            $fCol = $idxFolio !== -1 ? $idxFolio : 1;
            $tCol = $idxTotal !== -1 ? $idxTotal : 9;
            $cCol = $idxContenedor !== -1 ? $idxContenedor : 3;
            $pCol = $idxPedimento !== -1 ? $idxPedimento : 4;

            $almacenTemp = [];
            $ultimoFolio = '';
            $ultimoContenedorALMAN = '';
            $ultimoPedimentoALMAN = ''; 

            foreach ($rowsALMAN as $columnas) {
                $folioCelda      = trim($columnas[$fCol] ?? '');
                $totalCelda      = trim($columnas[$tCol] ?? '');
                $contenedorCelda = trim($columnas[$cCol] ?? '');
                $pedimentoCelda  = trim($columnas[$pCol] ?? '');

                if ($contenedorCelda !== '') {
                    $ultimoContenedorALMAN = $contenedorCelda;
                } else {
                    $contenedorCelda = $ultimoContenedorALMAN;
                }

                if ($folioCelda !== '') {
                    if ($ultimoFolio !== '' && $ultimoFolio !== $folioCelda) {
                        $ultimoPedimentoALMAN = ''; 
                    }
                    $ultimoFolio = $folioCelda;
                } else {
                    $folioCelda = $ultimoFolio;
                }

                $montoLimpio = (float) filter_var(str_replace(',', '', $totalCelda), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                if ($montoLimpio > 0 && stripos($folioCelda, 'total') === false && stripos($columnas[0] ?? '', 'total') === false) {
                    
                    if (preg_match('/[4-7]\d{6}/', $pedimentoCelda, $mPed)) {
                        $ultimoPedimentoALMAN = $mPed[0]; 
                    }

                    $pedimentoLimpio = $ultimoPedimentoALMAN;

                    $contenedoresAExtraer = [];
                    if (preg_match_all('/[A-Za-z]{4}[\s\-]*\d{7}/', $contenedorCelda, $matches)) {
                        foreach ($matches[0] as $cont) {
                            $contenedoresAExtraer[] = strtoupper(preg_replace('/[^A-Z0-9]/', '', $cont));
                        }
                    } else {
                        $posibles = preg_split('/[\s,\n\r]+/', $contenedorCelda);
                        foreach ($posibles as $posible) {
                            $limpio = strtoupper(preg_replace('/[^A-Z0-9]/', '', $posible));
                            if (strlen($limpio) >= 10 && strlen($limpio) <= 12) {
                                $contenedoresAExtraer[] = $limpio;
                            }
                        }
                    }

                    if ($pedimentoLimpio === '' && count($contenedoresAExtraer) > 0) {
                        foreach ($contenedoresAExtraer as $cont) {
                            if (isset($mapaContenedorAPedimentoZLO[$cont])) {
                                $pedimentoLimpio = $mapaContenedorAPedimentoZLO[$cont];
                                $ultimoPedimentoALMAN = $pedimentoLimpio;
                                break;
                            }
                        }
                    }

                    if ($pedimentoLimpio === '' && $ultimoPedimentoALMAN !== '') {
                        $pedimentoLimpio = $ultimoPedimentoALMAN;
                    }

                    if (count($contenedoresAExtraer) > 0) {
                        $montoDividido = round($montoLimpio / count($contenedoresAExtraer), 2);
                        foreach ($contenedoresAExtraer as $cont) {
                            $almacenTemp[] = [
                                'contenedor' => $cont,
                                'monto'      => $montoDividido,
                                'ped_csv'    => $pedimentoLimpio 
                            ];
                        }
                    }
                }
            }

            if (empty($almacenTemp)) {
                return ['code' => 0, 'message' => 'completado'];
            }

            // ====================================================================
            // PASO C: CONSOLIDACIÓN Y VERIFICACIÓN EN BASE DE DATOS (LIKE QUERY)
            // ====================================================================
            $contenedoresConsolidados = [];
            foreach ($almacenTemp as $item) {
                $cont = $item['contenedor'];
                if (!isset($contenedoresConsolidados[$cont])) {
                    $contenedoresConsolidados[$cont] = [
                        'monto' => 0,
                        'ped_csv' => $item['ped_csv']
                    ];
                }
                $contenedoresConsolidados[$cont]['monto'] += $item['monto'];
                
                if ($contenedoresConsolidados[$cont]['ped_csv'] === '' && $item['ped_csv'] !== '') {
                    $contenedoresConsolidados[$cont]['ped_csv'] = $item['ped_csv'];
                }
            }

            $contenedoresUnicosArray = array_keys($contenedoresConsolidados);
            
            $chunks = array_chunk($contenedoresUnicosArray, 50);
            $operacionesTodas = collect();
            
            foreach ($chunks as $chunk) {
                $resultados = Importacion::where(function($q) use ($chunk) {
                    foreach ($chunk as $cont) {
                        // Busca si el contenedor está anidado en el string de la DB
                        $q->orWhere('contenedor', 'LIKE', '%' . $cont . '%');
                    }
                })->get();
                $operacionesTodas = $operacionesTodas->merge($resultados);
            }

            $idsBusqueda = $operacionesTodas->pluck('id_pedimiento')->unique()->filter()->toArray();
            $pedimentosRealesDb = Pedimento::whereIn('id_pedimiento', $idsBusqueda)->pluck('num_pedimiento', 'id_pedimiento');

            $dbPedimentosDict = [];
            $navierasDict = [];

            foreach ($operacionesTodas as $op) {
                $contDb = strtoupper(trim($op->contenedor));
                $numPedReal = isset($pedimentosRealesDb[$op->id_pedimiento]) ? trim($pedimentosRealesDb[$op->id_pedimiento]) : '';

                // Comprobamos cuál de nuestros contenedores únicos está dentro de este registro DB
                foreach ($contenedoresUnicosArray as $contBuscado) {
                    if (str_contains($contDb, $contBuscado)) {
                        if (!empty($op->naviera)) {
                            $navierasDict[$contBuscado] = $op->naviera;
                        }
                        if ($numPedReal !== '') {
                            $dbPedimentosDict[$contBuscado] = $numPedReal;
                        }
                    }
                }
            }

            $agrupadoPorPedimento = [];

            foreach ($contenedoresConsolidados as $cont => $data) {
                
                $pedimentoDB  = $dbPedimentosDict[$cont] ?? null;
                $pedimentoZLO = $mapaContenedorAPedimentoZLO[$cont] ?? null;
                $pedimentoCSV = $data['ped_csv'] !== '' ? $data['ped_csv'] : null;

                $pedimentoId = $pedimentoDB ?? $pedimentoZLO ?? $pedimentoCSV ?? ('DESC_' . $cont);

                $pedimentoBase = $pedimentoId;
                
                if (!str_starts_with($pedimentoId, 'DESC_') && preg_match('/[4-7]\d{6}/', $pedimentoId, $m)) {
                    $pedimentoBase = $m[0];
                }

                if (!isset($agrupadoPorPedimento[$pedimentoBase])) {
                    $agrupadoPorPedimento[$pedimentoBase] = [
                        'monto_total'  => 0,
                        'contenedores' => [],
                        'naviera'      => $navierasDict[$cont] ?? '',
                        'celda_zlo_original' => $mapaContenedorAExactoZLO[$cont] ?? null
                    ];
                }

                $agrupadoPorPedimento[$pedimentoBase]['monto_total'] += $data['monto'];
                $agrupadoPorPedimento[$pedimentoBase]['contenedores'][] = $cont;
                
                if (empty($agrupadoPorPedimento[$pedimentoBase]['celda_zlo_original']) && !empty($mapaContenedorAExactoZLO[$cont])) {
                    $agrupadoPorPedimento[$pedimentoBase]['celda_zlo_original'] = $mapaContenedorAExactoZLO[$cont];
                }
            }

            // ====================================================================
            // 5. PREPARAR EL PAQUETE FINAL PARA GOOGLE SHEETS
            // ====================================================================
            $almacenParaSheets = [];

            foreach ($agrupadoPorPedimento as $pedimentoBase => $datosAgrupados) {
                
                $celdaExacta = $datosAgrupados['celda_zlo_original'];
                
                if (!empty($celdaExacta)) {
                    $contenedorRepresentativo = $celdaExacta;
                } else {
                    $contsUnicos = array_unique($datosAgrupados['contenedores']);
                    $contenedorRepresentativo = implode("\n", $contsUnicos);
                }

                $almacenParaSheets[] = [
                    'contenedor' => $contenedorRepresentativo, 
                    'concepto'   => 'Almacen', 
                    'monto'      => round($datosAgrupados['monto_total'], 2), 
                    'moneda'     => 'MXN',
                    'naviera'    => $datosAgrupados['naviera']
                ];
            }

            if (!empty($almacenParaSheets)) {
                $paquetes = array_chunk($almacenParaSheets, 20);
                foreach ($paquetes as $idx => $paquete) {
                    $esUltimo = ($idx === count($paquetes) - 1);
                    $this->enviarDatosAlmacenSpecializedWebhook($paquete, $esUltimo);
                    sleep(2); 
                }
            }

            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            Log::error("Error procesando Almacén ALMAN con cruce ZLO: " . $e->getMessage() . " en la línea " . $e->getLine());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Envía un payload specialized de contenedor/monto a un nuevo webhook
     * en el Google Sheet de ZLO para que haga el cruce estadoful.
     */
    private function enviarDatosAlmacenSpecializedWebhook(array $datosParaSheets, bool $esUltimo = true)
    {
        if (app()->environment('production')) {
            $almacenWebhookUrl = 'https://script.google.com/a/macros/intactics.com/s/AKfycbzil8yuKDXwReWIA91kJFDXelMDGghbWeW9bb-jvgcC5FoZr3Z0HlIQFkuxlOg-og3kuQ/exec';
        } else {
            $almacenWebhookUrl = 'https://script.google.com/macros/s/AKfycbyXbQI3JkBufxYUXsYUUTmIIwJmYWuYDVOrYnV0xSXbTBe7lhNZvTjGBDKPuPoK7x6xpQ/exec'; 
        }

        $payload = [
            'almacen_totals' => $datosParaSheets, 
            'sheet_name'     => 'ZLO',
            'es_ultimo'      => $esUltimo // <-- Es buena práctica pasarlo también aquí
        ];

        try {
            // AUMENTAMOS EL TIMEOUT A 180 SEGUNDOS (3 Minutos)
            $response = Http::timeout(180) 
                ->withBody(json_encode($payload), 'application/json')
                ->post($almacenWebhookUrl);

            /* Log::info("Almacén specialized webhook respondió con: " . $response->body()); */
        } catch (\Throwable $e) {
            Log::error("Error enviando datos al specialized webhook de Almacén: " . $e->getMessage());
        }
    }

    /**
     * Extrae Traslado Local desde la hoja EXTRAS.
     * Corregido: Error de count() y blindaje de tipos de datos.
     */
    public function enviarAGPCTrasladoLocal(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Procesando Traslado Local (Motor CSV a prueba de saltos de línea)...");

        try {
            if (app()->environment('production')) {
                $csvUrl = "https://docs.google.com/spreadsheets/d/1FvhWp2AeOyoiv1KIrmQNOKf9ZoDRy5L7HVd5FcRQBio/gviz/tq?tqx=out:csv&sheet=EXTRAS&_cb=" . time();
            } else {
                $csvUrl = "https://docs.google.com/spreadsheets/d/1yOcPGlvycRBCg5KpWs5-b8EmLQrUNPgh4aqurXQT1Uo/gviz/tq?tqx=out:csv&sheet=EXTRAS&_cb=" . time();
            }
            
            $response = Http::withoutVerifying()->timeout(60)->get($csvUrl);
            if (!$response->successful()) {
                throw new \Exception("Error al leer EXTRAS. HTTP: " . $response->status());
            }

            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $response->body());
            rewind($stream);
            
            $idxReferencia = -1;
            $idxContenedor = -1;
            $idxTotal = -1;

            $bloques = [];
            $idxBloqueActivo = -1; 

            // Leemos fila por fila respetando la estructura real del CSV
            while (($columnas = fgetcsv($stream)) !== false) {
                if (!is_array($columnas) || count($columnas) < 2) {
                    continue;
                }

                // --- Detección de Cabeceras Exacta ---
                if ($idxReferencia === -1 || $idxContenedor === -1 || $idxTotal === -1) {
                    foreach ($columnas as $i => $col) {
                        $txt = strtolower(trim($col));
                        
                        if ($idxReferencia === -1 && strpos($txt, 'referencia') !== false) {
                            $idxReferencia = $i;
                        }
                        if ($idxContenedor === -1 && (strpos($txt, 'contenedor') !== false || preg_match('/^[a-z]{4}\d{7}$/i', trim($col)))) {
                            $idxContenedor = $i;
                        }
                        if ($idxTotal === -1 && (strpos($txt, 'total') !== false || strpos($txt, 'monto') !== false || strpos($txt, '$') !== false)) {
                            $idxTotal = $i;
                        }
                    }
                }

                $rCol = ($idxReferencia !== -1) ? $idxReferencia : 3;
                $cCol = ($idxContenedor !== -1) ? $idxContenedor : 4;
                $tCol = ($idxTotal      !== -1) ? $idxTotal      : 5;

                $fechaExtraida   = trim($columnas[0] ?? '');
                // Limpiamos saltos de línea internos en la referencia para que el strpos no falle
                $referenciaCelda = strtolower(trim(preg_replace('/\s+/u', ' ', $columnas[$rCol] ?? '')));
                $contenedorCelda = trim($columnas[$cCol] ?? '');
                $totalCelda      = trim($columnas[$tCol] ?? '');

                // --- Lógica de la Máquina de Estados ---
                if ($referenciaCelda !== '') {
                    if (strpos($referenciaCelda, 'traslado') !== false && strpos($referenciaCelda, 'local') !== false) {
                        $montoLimpio = (float) preg_replace('/[^0-9.]/', '', $totalCelda);
                        $bloques[] = [
                            'fecha' => $fechaExtraida,
                            'monto' => $montoLimpio,
                            'contenedores' => []
                        ];
                        $idxBloqueActivo = count($bloques) - 1; 
                    } else {
                        $idxBloqueActivo = -1; 
                    }
                }

                // Extracción agresiva de contenedores
                if ($idxBloqueActivo !== -1 && $contenedorCelda !== '') {
                    // Busca el patrón exacto de contenedor ignorando basura alrededor
                    if (preg_match_all('/[A-Za-z]{4}[\s\-]*\d{7}/', $contenedorCelda, $matches)) {
                        foreach ($matches[0] as $cont) {
                            $limpio = strtoupper(preg_replace('/[^A-Z0-9]/', '', $cont));
                            $bloques[$idxBloqueActivo]['contenedores'][] = $limpio;
                        }
                    } else {
                        $posiblesContenedores = preg_split('/[\s,\n\r]+/', $contenedorCelda);
                        foreach ($posiblesContenedores as $posible) {
                            $limpio = strtoupper(preg_replace('/[^A-Z0-9]/', '', $posible));
                            if (strlen($limpio) >= 10 && strlen($limpio) <= 12) {
                                $bloques[$idxBloqueActivo]['contenedores'][] = $limpio;
                            }
                        }
                    }
                }
            }
            fclose($stream); // Cerramos el archivo virtual

            $trasladosFinales = [];
            $contenedoresUnicos = [];

            foreach ($bloques as $bloque) {
                $contenedoresEnBloque = array_unique($bloque['contenedores'] ?? []);
                $numContenedores = count($contenedoresEnBloque);
                
                if ($numContenedores > 0 && $bloque['monto'] > 0) {
                    // Dividimos el monto equitativamente entre los contenedores
                    $montoDividido = round($bloque['monto'] / $numContenedores, 2);

                    foreach ($contenedoresEnBloque as $cont) {
                        $trasladosFinales[] = [
                            'contenedor'  => $cont,
                            'monto'       => $montoDividido,
                            'fecha'       => $bloque['fecha']
                        ];
                        $contenedoresUnicos[] = $cont;
                    }
                }
            }

            if (empty($trasladosFinales)) {
                Log::warning("No se extrajeron Traslados Locales válidos del Excel.");
                return ['code' => 0, 'message' => 'completado'];
            }

            // Búsqueda en Base de Datos
            $contenedoresUnicosArray = array_unique($contenedoresUnicos);
            $impos = collect();
            
            $chunks = array_chunk($contenedoresUnicosArray, 50);
            foreach ($chunks as $chunk) {
                $resultados = Importacion::where(function($q) use ($chunk) {
                    foreach ($chunk as $cont) {
                        $q->orWhere('contenedor', 'LIKE', '%' . $cont . '%');
                    }
                })->get();
                $impos = $impos->merge($resultados);
            }

            $idsBusqueda = $impos->pluck('id_pedimiento')->unique()->filter()->toArray();
            $pedimentosReales = Pedimento::whereIn('id_pedimiento', $idsBusqueda)->pluck('num_pedimiento', 'id_pedimiento');

            $navierasDict = [];
            $pedimentosDict = [];

            foreach ($impos as $op) {
                $contDb = strtoupper(trim($op->contenedor));
                foreach ($contenedoresUnicosArray as $contBuscado) {
                    if (str_contains($contDb, $contBuscado)) {
                        $navierasDict[$contBuscado] = $op->naviera ?? '';
                        $numPed = $pedimentosReales[$op->id_pedimiento] ?? (string)$op->id_pedimiento;
                        
                        if (preg_match('/[4-7]\d{6}/', $numPed, $m)) {
                            $pedimentosDict[$contBuscado] = $m[0];
                        } else {
                            $pedimentosDict[$contBuscado] = $numPed;
                        }
                    }
                }
            }

            $payloadParaSheets = [];

            foreach ($trasladosFinales as $item) {
                $cont = $item['contenedor'];
                $pedimentoId = $pedimentosDict[$cont] ?? 'DESC_' . $cont;
                $naviera = $navierasDict[$cont] ?? '';

                $payloadParaSheets[] = [
                    'pedimento'  => $pedimentoId, 
                    'contenedor' => $cont, 
                    'concepto'   => 'Traslado Local',
                    'monto'      => round($item['monto'], 2), 
                    'moneda'     => 'MXN',
                    'fecha'      => $item['fecha'],
                    'naviera'    => $naviera
                ];
            }

            $paquetes = array_chunk($payloadParaSheets, 20);
            foreach ($paquetes as $idx => $paquete) {
                $esUltimo = ($idx === count($paquetes) - 1);
                $this->enviarDatosTrasladoLocalSpecializedWebhook($paquete, $esUltimo);
                sleep(2); 
            }

            Log::info("¡Todos los contenedores de Traslados Locales se enviaron de forma individual con éxito!");
            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            Log::error("Error en Traslado Local: " . $e->getMessage() . " línea " . $e->getLine());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Envía un payload specialized de contenedor/monto/fecha a un nuevo webhook
     * en el Google Sheet de ZLO para que haga el cruce de Traslado Local.
     */
    private function enviarDatosTrasladoLocalSpecializedWebhook(array $datosParaSheets, bool $esUltimo = true)
    {
        if (app()->environment('production')) {
            $webhookUrl = 'https://script.google.com/a/macros/intactics.com/s/AKfycbzil8yuKDXwReWIA91kJFDXelMDGghbWeW9bb-jvgcC5FoZr3Z0HlIQFkuxlOg-og3kuQ/exec';
        } else {
            $webhookUrl = 'https://script.google.com/macros/s/AKfycbyXbQI3JkBufxYUXsYUUTmIIwJmYWuYDVOrYnV0xSXbTBe7lhNZvTjGBDKPuPoK7x6xpQ/exec'; 
        }

        $payload = [
            'traslado_totals' => $datosParaSheets,
            'sheet_name'      => 'ZLO',
            'es_ultimo'       => $esUltimo
        ];

        try {
            $response = Http::timeout(180) 
                ->withoutVerifying()
                ->withBody(json_encode($payload), 'application/json')
                ->post($webhookUrl);
        } catch (\Throwable $e) {
            Log::error("Error enviando datos al specialized webhook de Traslado Local: " . $e->getMessage());
        }
    }

    private function construirIndiceOperacionesLLCs(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
        try {
            $arrContextOptions = [
                "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
                "http" => ["ignore_errors" => true]
            ];

            foreach ($indicesOperacion as $pedimentoSucio => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas']);
                $facturaLLC = $coleccionFacturas->first(function ($factura) {
                    return strtolower($factura['tipo_documento']) === 'llc';
                });

                if (!$facturaLLC) {
                    continue;
                }

                // Detecta los 7 dígitos sin importar el año
                if (preg_match('/(\d{7})(?!\d)/', $pedimentoSucio, $m)) {
                    $pedimentoLimpio = $m[1];
                } else {
                    continue;
                }

                $datosLlc = [
                    'folio' => 'S/F',
                    'fecha' => now()->format('Y-m-d'),
                    'monto_total' => 0.0,
                    'ruta_txt' => $facturaLLC['ruta_txt'] ?? null,
                    'ruta_pdf' => $facturaLLC['ruta_pdf'] ?? null,
                    'txt_exitoso' => false
                ];

                if ($datosLlc['ruta_txt']) {
                    try {
                        $contenidoTxt = @file_get_contents($datosLlc['ruta_txt'], false, stream_context_create($arrContextOptions));
                        
                        // Fallback a DigitalOcean para TXT
                        if (!$contenidoTxt || str_contains($http_response_header[0] ?? '', '404')) {
                            $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($datosLlc['ruta_txt']);
                            $contenidoTxt = @file_get_contents($rutaAlternativa, false, stream_context_create($arrContextOptions));
                        }

                        if ($contenidoTxt !== false && strpos($contenidoTxt, '[encRefNumber]') !== false) {
                            $lineas = preg_split('/\r\n|\r|\n/', $contenidoTxt);
                            $montoAcumulado = 0.0;
                            foreach ($lineas as $linea) {
                                if (strpos($linea, '[encRefNumber]') !== false) $datosLlc['folio'] = trim(explode(']', $linea)[1]);
                                if (strpos($linea, '[encTxnDate]') !== false) {
                                    $fStr = trim(explode(']', $linea)[1]);
                                    if ($fStr) $datosLlc['fecha'] = \Carbon\Carbon::parse($fStr)->format('Y-m-d');
                                }
                                if (strpos($linea, '[movAmount]') !== false) {
                                    $montoAcumulado += (float) trim(explode(']', $linea)[1]);
                                }
                            }
                            $datosLlc['monto_total'] = $montoAcumulado;
                            $datosLlc['txt_exitoso'] = true;
                        }
                    } catch (\Throwable $th) {
                        Log::warning("Error leyendo TXT de LLC para pedimento {$pedimentoLimpio}");
                    }
                }
                
                if (!$datosLlc['txt_exitoso'] && !empty($datosLlc['ruta_pdf'])) {
                    $pdfData = $this->extraerTotalDesdePdfProveedor($datosLlc['ruta_pdf']);
                    
                    if ($pdfData !== null && $pdfData['monto'] !== null) {
                        $datosLlc['monto_total'] = (float) $pdfData['monto'];
                        $datosLlc['txt_exitoso'] = true; // Lo marcamos como exitoso por la vía del PDF
                        
                        // Si el PDF también nos arroja una fecha válida, la aprovechamos
                        if (!empty($pdfData['fecha'])) {
                            $datosLlc['fecha'] = $pdfData['fecha'];
                        }

                        $nombreArchivo = basename($datosLlc['ruta_pdf']);
                        
                        if (preg_match('/(?:NOG|TIJ|NL|MXL|ZLO|REY|VRZ|ITK|FSSA)-?0*(\d+)/i', $nombreArchivo, $matchFolioArchivo)) {
                            $datosLlc['folio'] = $matchFolioArchivo[1]; // Extraerá "64044" de NOG64044.pdf
                        } elseif (!empty($pdfData['folio'])) {
                            $datosLlc['folio'] = $pdfData['folio']; // Fallback por si el nombre es raro
                        }
                        
                    }
                }

                $indice[$pedimentoLimpio] = $datosLlc;
            }
        } catch (\Throwable $e) {
            Log::error("Error general en construirIndiceOperacionesLLCs: " . $e->getMessage());
        }
        return $indice;
    }


    /**
     * Lee todos los archivos y crea un mapa [pedimento => array_de_rutas] para Pagos de Derecho.
     * ACTÚA COMO CADENERO: Si hay Normales, descarta los Intactics inmediatamente.
     */
    private function construirIndiceOperacionesPagosDerecho(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) continue;

                if (preg_match('/(\d{7})(?!\d)/', $pedimento, $m)) {
                    $pedimentoLimpio = $m[1];
                } else {
                    continue; 
                }

                $facturasPagos = collect($datos['facturas'])->where('tipo_documento', 'pago_derecho');
                if ($facturasPagos->isEmpty()) continue;

                $rutasNormales = [];
                $rutasIntactics = [];

                foreach ($facturasPagos as $factura) {
                    if (!empty($factura['ruta_pdf'])) {
                        // 🚀 VALIDACIÓN SEGURA: Solo miramos el nombre del archivo final
                        $nombreArchivo = strtoupper(basename($factura['ruta_pdf']));
                        
                        if (str_contains($nombreArchivo, 'INTACTICS')) {
                            $rutasIntactics[] = $factura['ruta_pdf'];
                        } else {
                            $rutasNormales[] = $factura['ruta_pdf'];
                        }
                    }
                }

                // Llenamos el índice priorizando los originales y eliminando duplicados idénticos
                $rutasFinales = !empty($rutasNormales) ? $rutasNormales : $rutasIntactics;

                if (!empty($rutasFinales)) {
                    $indice[$pedimentoLimpio] = array_unique($rutasFinales);
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de pagos de derecho: " . $e->getMessage());
        }
        return $indice;
    }


    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ FINAL DE LOS METODOS INDEXANTES - AuditoriaImpuestosController -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================

    //--------------------------------------------------------------------------------------------------------------
    //----------------------------- INICIO DE LOS METODOS AUXILIARES - AuditoriaImpuestosController -----------------------------
    //--------------------------------------------------------------------------------------------------------------


    // Se encarga de hacer la comparativa de montos
    private function compararMontos(float $esperado, float $real, $tipoOperacion): string
    {
        if (strpos(strtolower($tipoOperacion), 'pedimento')) {
            return "Sin operacion!";
        }
        if ($esperado == -1) {
            return strpos(strtolower($tipoOperacion), 'importacion') ? 'IMPO' : 'EXPO';
        }
        if ($esperado == -1.1) {
            return 'Sin SC!';
        }
        if ($real == -1) {
            return 'Sin Impuesto!';
        } //Este IF es practicamente imposible, pero lo pongo para seguir el formato.
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }


    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     * UTILIZADO EN [auditarFacturasDeFletes()]
     */
    private function compararMontos_Fletes(float $esperado, float $real): string
    {
        if ($esperado == -1) {
            return 'Sin SC!';
        }
        if ($real == -1) {
            return 'Sin Flete!';
        }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }


    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     * UTILIZADO EN [auditarFacturasDeLLC()]
     */
    private function compararMontos_LLC(float $esperado, float $real): string
    {
        if ($esperado == -1) {
            return 'Sin SC!';
        }
        if ($real == -1) {
            return 'Sin LLC!';
        }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }


    /**
     * Parsea un archivo XML de Transportactics y devuelve los datos clave.
     * UTILIZADO EN [auditarFacturasDeFletes()]
    */
    private function parsearXmlFlete(?string $rutaXml): ?array
    {
        gc_collect_cycles();
        if (!$rutaXml) {
            return ['total' => -1, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];
        }

        try {
            $arrContextOptions = [
                "ssl"  => ["verify_peer" => false, "verify_peer_name" => false],
                "http" => ["ignore_errors" => true]
            ];
            
            $xmlString = @file_get_contents($rutaXml, false, stream_context_create($arrContextOptions));
            
            // Si el servidor principal no lo tiene (404), usamos DigitalOcean
            if (!$xmlString || str_contains($http_response_header[0] ?? '', '404')) {
                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);
                $xmlString = @file_get_contents($rutaAlternativa, false, stream_context_create($arrContextOptions));
            }

            if (!$xmlString || str_contains($http_response_header[0] ?? '', '404')) {
                return ['total' => -1, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];
            }

            $total = -1;
            $moneda = 'MXN';
            $emisor = '';
            $fecha = null;
            $folio = null;
            // INTENTO 1: Lector XML Nativo de PHP
            try {
                $xmlObj = @simplexml_load_string($xmlString);
                if ($xmlObj !== false) {
                    if (isset($xmlObj['Total'])) {
                        $total = (float) $xmlObj['Total'];
                    }
                    if (isset($xmlObj['Moneda'])) {
                        $moneda = strtoupper((string) $xmlObj['Moneda']);
                    }
                    if (isset($xmlObj['Fecha'])) {
                        $fecha = explode('T', (string) $xmlObj['Fecha'])[0];
                    }
                    if (isset($xmlObj['Folio'])) {
                        $folioRaw = (string) $xmlObj['Folio'];
                        $folio = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
                    }

                    $namespaces = $xmlObj->getNamespaces(true);
                    if (isset($namespaces['cfdi'])) {
                        $emisorObj = $xmlObj->children($namespaces['cfdi'])->Emisor;
                        if ($emisorObj && isset($emisorObj['Nombre'])) {
                            $emisor = trim((string) $emisorObj['Nombre']);
                        }
                    }
                }
            } catch (\Throwable $th) {
                // Fallo silencioso si el XML está roto
            }

            // INTENTO 2: Fallback Regex INDEPENDIENTE (AQUÍ ESTÁ LA CORRECCIÓN)
            // Ahora revisa cada campo por separado
            
            if ($total === -1) {
                if (preg_match('/Comprobante[^>]+Total=["\']([0-9\,\.]+)["\']/is', $xmlString, $matchesTotal)) {
                    $total = (float) str_replace(',', '', $matchesTotal[1]);
                }
            }
            
            // Si el emisor sigue vacío, forzamos la búsqueda con Regex en cualquier parte del archivo
            if (empty($emisor)) {
                if (preg_match('/Emisor[^>]+Nombre=["\']([^"\']+)["\']/is', $xmlString, $matchesEmisor)) {
                    $emisor = trim($matchesEmisor[1]);
                }
            }

            if (empty($fecha)) {
                if (preg_match('/Comprobante[^>]+Fecha=["\']([^"\']+)["\']/is', $xmlString, $matchesFecha)) {
                    $fecha = explode('T', $matchesFecha[1])[0];
                }
            }

            if ($moneda === 'MXN') {
                if (preg_match('/Comprobante[^>]+Moneda=["\']([A-Z]{3})["\']/is', $xmlString, $matchesMoneda)) {
                    $moneda = strtoupper($matchesMoneda[1]);
                }
            }
            if (!$folio) {
                if (preg_match('/Folio=["\']([^"\']+)["\']/i', $xmlString, $m)) {
                    $folioRaw = $m[1];
                    $folio = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
                }
            }

            return ['total' => $total, 'moneda' => $moneda, 'emisor' => $emisor, 'fecha' => $fecha, 'folio' => $folio];

        } catch (\Throwable $e) {
            Log::error("Error parseando XML {$rutaXml}: " . $e->getMessage());
            return ['total' => -1, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];
        }
    }


    /**
     * Extrae múltiples conceptos y sus respectivos precios de un texto con formato específico.
     * UTILIZADO EN [importarFacturasSCEnAuditoriasTotalesSC()]
     *
     * @param string $contenidoTxt El contenido completo del archivo de texto.
     * @param array $conceptosBuscados Un array con los nombres de los productos a buscar.
     * @return array Un array asociativo donde cada clave es el nombre de un concepto encontrado
     * y su valor es un array con 'nombre' y 'precio'.
     */
    private function extraerMultiplesConceptos(string $contenidoTxt): array
    {
        // Un array con todos los conceptos que nos interesan.
        $conceptosBuscados =
            [
                'monto_maniobras_2' => 'MANIOBRAS EN ALMACEN FISCALIZADO',
                'monto_termo' => 'CONTROLADOR DE TEMPERATURA (TERMOGRAFO)',
                'monto_rojos' => 'RECONOCIMIENTO ADUANERO (ROJO)',
                // Puedes agregar aquí todos los que necesites
            ];
        $resultados =
            [
                'monto_maniobras_2' => -1,
                'monto_termo' => -1,
                'monto_rojos' => -1,
            ];
        // Hacemos una copia de los conceptos a buscar para poder modificarla.
        $conceptosPendientes = $conceptosBuscados;

        // 1. Dividimos el texto en bloques de "movimiento" o producto.
        $bloques = preg_split('/(?=\[movTIPOPRODUCTO\])/', $contenidoTxt, -1, PREG_SPLIT_NO_EMPTY);

        // 2. Iteramos sobre cada bloque de producto encontrado.
        foreach ($bloques as $bloque) {
            // Si ya encontramos todos los conceptos, no tiene caso seguir recorriendo bloques.
            if (empty($conceptosPendientes)) {
                break;
            }

            // 3. Dentro de cada bloque, iteramos sobre los conceptos que aún nos faltan por encontrar.
            foreach ($conceptosPendientes as $index => $concepto) {
                $lineaNombreBuscada = '[movPRODUCTONOMBRE]' . $concepto;

                // 4. Verificamos si el concepto actual está en este bloque.
                if (str::contains($bloque, $lineaNombreBuscada)) {
                    // ¡Encontrado! Ahora extraemos su precio.
                    if (preg_match('/\[movPRODUCTOPRECIO\](.*)/', $bloque, $matches)) {
                        $precio = trim($matches[1]);

                        // 5. Guardamos el resultado usando el nombre del concepto como clave.
                        $resultados[$index] = (float) $precio;

                        // 6. Eliminamos el concepto de la lista de pendientes para ser más eficientes.
                        unset($conceptosPendientes[$index]);

                        // Como un bloque solo puede tener un producto, rompemos el bucle interior
                        // para pasar al siguiente bloque.
                        break;
                    }
                }
            }
        }

        return $resultados;
    }


    /**
     * Construye un mapa [num_pedimento_limpio => id_pedimiento] buscando coincidencias
     * parciales en la base de datos para manejar datos "sucios".
     * UTILIZADO EN [mapearFacturasYFacturasSCEnAuditorias()] y [importarImpuestosEnAuditorias()]
     *
     * @param array $pedimentosLimpios Array de números de pedimento de 7 dígitos.
     * @return array El mapa final.
     */
    private function construirMapaDePedimentos(array $pedimentosLimpios, string $patenteSucursal, $numeroSucursal, string $fecha_inicio = null, string $fecha_fin = null): array
    {
        gc_collect_cycles();
        if (empty($pedimentosLimpios)) return [];

        // Extrae todos los números de 7 dígitos (Soporta múltiples por celda como "3711-6031393 \n R1-3711-6031459")
        $sieteDigitos = [];
        foreach (array_unique($pedimentosLimpios) as $p) {
            if (preg_match_all('/[4-7]\d{6}/', $p, $matches)) {
                foreach ($matches[0] as $match) $sieteDigitos[] = $match;
            }
        }
        $sieteDigitos = array_unique($sieteDigitos);
        if (empty($sieteDigitos)) return [];

        // Calculamos la fecha límite (1 año hacia atrás desde el momento en que corre el código)
        // Ejemplo: Si hoy es 18/06/2026, esto será '2025-06-18 00:00:00'
        $fechaLimite = now()->subYear()->format('Y-m-d 00:00:00');

        // EL FIX MAESTRO: Filtramos desde SQL para la Sucursal, Patente y máximo 1 año de antigüedad
        $posiblesCoincidencias = Pedimento::query()
            ->where('created_at', '>=', $fechaLimite) // <-- EL ESCUDO ANTI-ANTIGÜEDADES (Cambia 'created_at' si usas otra columna de fecha)
            ->where(function ($q) use ($sieteDigitos) {
                foreach ($sieteDigitos as $digito) {
                    $q->orWhere('num_pedimiento', 'LIKE', "%{$digito}%");
                }
            })
            ->where(function ($query) use ($patenteSucursal, $numeroSucursal, $fecha_inicio, $fecha_fin) {
                // Solo pedimentos que tengan una operación en ESTA sucursal
                $query->whereHas('importacion', function ($q) use ($patenteSucursal, $numeroSucursal, $fecha_inicio, $fecha_fin) {
                    $q->where('patente', $patenteSucursal)->where('sucursal', $numeroSucursal);
                    if ($fecha_inicio && $fecha_fin) {
                        $fecha_extendida = \Carbon\Carbon::parse($fecha_inicio)->subDays(30)->format('Y-m-d');
                        $q->whereBetween('created_at', [$fecha_extendida, $fecha_fin]);
                    }
                })->orWhereHas('exportacion', function ($q) use ($patenteSucursal, $numeroSucursal, $fecha_inicio, $fecha_fin) {
                    $q->where('patente', $patenteSucursal)->where('sucursal', $numeroSucursal);
                    if ($fecha_inicio && $fecha_fin) {
                        $fecha_extendida = \Carbon\Carbon::parse($fecha_inicio)->subDays(30)->format('Y-m-d');
                        $q->whereBetween('created_at', [$fecha_extendida, $fecha_fin]);
                    }
                });
            })
            ->with([
                // Restringimos también la precarga para evitar cruces
                'importacion' => function ($q) use ($patenteSucursal, $numeroSucursal) {
                    $q->where('patente', $patenteSucursal)->where('sucursal', $numeroSucursal);
                },
                'exportacion' => function ($q) use ($patenteSucursal, $numeroSucursal) {
                    $q->where('patente', $patenteSucursal)->where('sucursal', $numeroSucursal);
                }
            ])
            ->get();

        $mapaFinal = [];
        
        foreach (array_unique($pedimentosLimpios) as $pedimentoBuscado) {
            if (!preg_match_all('/[4-7]\d{6}/', $pedimentoBuscado, $matches)) continue; 
            $digitosBuscados = $matches[0];
            
            $all_ids = [];
            $best_record = null;

            foreach ($posiblesCoincidencias as $registro) {
                foreach ($digitosBuscados as $digito) {
                    if (strpos($registro->num_pedimiento, $digito) !== false) {
                        $all_ids[] = $registro->id_pedimiento;
                        
                        $esRecti = str_contains(strtoupper($registro->num_pedimiento), 'R1-');

                        // Mantenemos el original o la rectificación como "ancla" de la celda
                        if (!$best_record || $esRecti || $registro->id_pedimiento > $best_record->id_pedimiento) {
                            $best_record = $registro;
                        }
                        break; 
                    }
                }
            }

            if ($best_record) {
                $impo = $best_record->importacion;
                $expo = $best_record->exportacion;
                
                $operacion = null;
                $tipoOp = 'Sin Operacion';
                $idOperacion = null;

                // LÓGICA ROCKET-PROOF: Elegimos la operación más reciente comparando fechas reales
                if ($impo && $expo) {
                    if ($impo->created_at >= $expo->created_at) {
                        $operacion = $impo;
                        $tipoOp = 'Importacion';
                        $idOperacion = $impo->id_importacion;
                    } else {
                        $operacion = $expo;
                        $tipoOp = 'Exportacion';
                        $idOperacion = $expo->id_exportacion;
                    }
                } elseif ($impo) {
                    $operacion = $impo;
                    $tipoOp = 'Importacion';
                    $idOperacion = $impo->id_importacion;
                } elseif ($expo) {
                    $operacion = $expo;
                    $tipoOp = 'Exportacion';
                    $idOperacion = $expo->id_exportacion;
                }

                $mapaFinal[$pedimentoBuscado] = [
                    'id_pedimiento'  => $best_record->id_pedimiento,
                    'id_operacion'   => $idOperacion,
                    'num_pedimiento' => $best_record->num_pedimiento,
                    'tipo'           => $tipoOp,
                    'es_recti'       => str_contains(strtoupper($best_record->num_pedimiento), 'R1-'),
                    'all_ids'        => array_unique($all_ids) 
                ];
            }
        }
        return $mapaFinal;
    }


    /**
     * Parsea un PDF de Pago de Derecho para extraer los datos clave.
     * Debe ser lo suficientemente inteligente para detectar el formato (BBVA vs Santander).
     * UTILIZADO EN [construirIndiceOperacionesPagosDerecho()]
     */
    private function parsearPdfPagoDeDerecho(string $rutaPdf): ?array
    {
        gc_collect_cycles();
        try {
            $rutaAlternativa = null;
            // Ahora utilizando Smalot!
            $config = new \Smalot\PdfParser\Config();
            // Whether to retain raw image data as content or discard it to save memory
            $config->setRetainImageContent(false);
            // Memory limit to use when de-compressing files, in bytes
            $config->setDecodeMemoryLimit(10276800);

            // 1. Crear una instancia del Parser.
            $parser = new Parser([], $config);
            try {
                // 2. Parsear el archivo PDF. Esto devuelve un objeto Pdf.
                $pdf = $parser->parseFile($rutaPdf);
            } catch (\Throwable $th) {

                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaPdf);
                $pdf = $parser->parseFile($rutaAlternativa);
            }


            // 3. Obtener el texto de todas las páginas del documento.
            // El resultado es un string muy similar al que obtenías con pdftotext.
            $texto = $pdf->getText();

            //Si el valor esta vacio, o si es un pago de derecho de Banamex (es a cuenta del cliente, por lo que lo descartamos)
            if ($texto === '' || str::contains($texto, 'citibanamex')) {
                return null;
            }
            // --- FIN DEL CAMBIO ---
            $datos = [];

            if ($rutaAlternativa) {
                $datos['ruta_alternativa'] = $rutaAlternativa;
            }
            // Lógica para detectar el tipo de banco y aplicar el Regex correcto
            if (str::contains($texto, 'Creando Oportunidades')) {   // Es BBVA
                // Regex para BBVA
                preg_match('/No\.\s*de\s*Operaci.n:\s*(\d+)/', $texto, $matchOp);
                preg_match('/Llave\s*de\s*Pago:\s*([A-Z0-9]+)/', $texto, $matchLlave);
                preg_match('/Total\s*Efectivamente\s*Pagado:\s*\$ ([\d,.]+)/', $texto, $matchMonto);
                preg_match('/Fecha\s*y\s*Hora\s*del\s*Pago:\s*(\d{2}\/\d{2}\/\d{4})/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float) str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('d/m/Y', $matchFecha[1])->format('Y-m-d') : null;
            } else {
                // Asumimos que es Santander
                // Leemos la "cadena mágica" de la segunda página
                preg_match('/\|20002=(\d+)\|/', $texto, $matchOp);
                preg_match('/\|40008=([A-Z0-9]+)\|/', $texto, $matchLlave);
                preg_match('/\|10017=([\d,.]+)\|/', $texto, $matchMonto);
                preg_match('/\|40002=(\d{8})\|/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float) str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('Ymd', $matchFecha[1])->format('Y-m-d') : null;
            }

            // Lógica para determinar el 'tipo' (Normal, Medio, etc.) basado en el nombre del archivo
            if (str::contains($rutaPdf, 'MEDIO')) {
                $datos['tipo'] = 'Medio Pago';
            } elseif (str::contains($rutaPdf, '-2')) {
                $datos['tipo'] = 'Segundo Pago';
            } elseif (str::contains($rutaPdf, 'INTACTICS')) {
                $datos['tipo'] = 'Intactics';
            } else {
                $datos['tipo'] = 'Normal';
            }

            unset($config);
            unset($parser);
            unset($pdf);
            unset($texto);
            gc_collect_cycles();

            if ($datos['llave_pago']){
                return $datos;
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            Log::error("Error al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            unset($config);
            unset($parser);
            unset($pdf);
            unset($texto);
            gc_collect_cycles();
            return null;
        }
    }
    private function compararMontos_Muestras(float $esperado, float $real): string
    {
        if ($esperado == -1) {
            return 'Sin SC!';
        }
        if ($real == -1) {
            return 'Sin Muestras!';
        }
        
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }
    /**
     * Lee todos los archivos de Muestras recientes y crea un mapa [pedimento => rutas].
     * MODIFICADO: Ahora atrapa múltiples archivos (Ej. PAGO-MUESTRA.pdf + Factura.xml)
     */
    private function construirIndiceOperacionesMuestras(array $indicesOperacion): array
    {
        gc_collect_cycles();
        try {
            $indice = [];

            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas']);
                
                // Atrapamos TODOS los que sean de tipo muestra/mensajeria
                $facturasMuestra = $coleccionFacturas->filter(function ($factura) {
                    $tipo = strtolower($factura['tipo_documento'] ?? '');
                    return (str_contains($tipo, 'muestra') || str_contains($tipo, 'mensajeria')) &&
                           !empty($factura['ruta_pdf']);
                });

                if ($facturasMuestra->isEmpty()) {
                    continue;
                }

                foreach ($facturasMuestra as $llaveGrupo => $factura) {
                    $indice[$pedimento][] = [
                        'folio' => $factura['folio'] ?? $llaveGrupo, 
                        'path_xml_mue' => $factura['ruta_xml'] ?? null, 
                        'path_pdf_mue' => $factura['ruta_pdf'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de muestras: " . $e->getMessage());
        }

        return $indice;
    }
    /**
     * Se encarga de leer el XML de las Muestras, obteniendo los montos para cruzarlos con la SC.
     * MODIFICADO: Si no hay XML pero hay un PDF de PAGO, lo reconoce para que no marque "Sin Muestras".
     */
    public function auditarFacturasDeMuestras(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("--- [INICIO] Auditoría de Muestras para Tarea #{$tarea->id} ---");

        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array) json_decode($contenidoJson, true);

            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            $indiceMuestras = $this->construirIndiceOperacionesMuestras($indicesOperaciones);
            $indiceSC = [];

            foreach ($auditoriasSC as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos']) ? $auditoria['desglose_conceptos'] : json_decode($auditoria['desglose_conceptos'], true);
                $pedimentoEncontrado = collect($mapaPedimentoAId)->firstWhere('id_pedimiento', $auditoria['pedimento_id']);
                
                if ($pedimentoEncontrado) {
                    $indiceSC[trim($pedimentoEncontrado['num_pedimiento'])] = [
                        'monto_muestras_sc_mxn' => (float) ($desglose['montos']['muestras_mxn'] ?? -1),
                        'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            $muestrasParaGuardar = [];
            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                $numPedRef = trim($pedimentoSucioYId['num_pedimiento']);
                $operacionId = $mapeadoFacturas['pedimentos_importacion'][$numPedRef] ?? $mapeadoFacturas['pedimentos_exportacion'][$numPedRef] ?? null;
                
                if (!$operacionId) {
                    continue;
                }

                $listaMuestras = $indiceMuestras[$numPedRef] ?? $indiceMuestras[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$numPedRef] ?? ['monto_muestras_sc_mxn' => -1, 'tipo_cambio' => 1.0];

                if (!$listaMuestras) {
                    continue;
                }
                
                $sumaFacturasMXN = 0;
                $huboCoincidencia = false;
                $foliosComb = [];
                $monedaDoc = 'MXN'; 
                $rutaXmlFinal = null;
                $rutaPdfFinal = null;
                $montosYaSumados = [];

                foreach ($listaMuestras as $idx => $datosMuestra) {
                    $montoItemMXN = -1;
                    $monedaItem = 'MXN';
                    
                    $folioRaw = $datosMuestra['folio'] ?? "Archivo_" . ($idx + 1);
                    $nombreMayus = strtoupper($folioRaw);
                    $folioArchivo = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;

                    // 1. Intentar leer XML (Prioridad Total)
                    if (!empty($datosMuestra['path_xml_mue'])) {
                        $xmlData = $this->parsearXmlFlete($datosMuestra['path_xml_mue']);
                        if ($xmlData && $xmlData['total'] != -1) {
                            $monedaItem = $xmlData['moneda'];
                            $montoItemMXN = ($monedaItem == "USD") ? round($xmlData['total'] * $datosSC['tipo_cambio'], 2) : $xmlData['total'];
                            if (!empty($xmlData['folio'])) {
                                $folioArchivo = $xmlData['folio'];
                            }
                        }
                    } 
                    // 2. Si es un PDF que dice "PAGO" o "COMPROBANTE", intentamos extraer el monto para que la auditoría cuadre
                    elseif (str_contains($nombreMayus, 'PAGO') || str_contains($nombreMayus, 'COMPROBANTE') || str_contains($nombreMayus, 'GISENA')) {
                        $pdfData = $this->extraerTotalDesdePdfProveedor($datosMuestra['path_pdf_mue']);
                        if ($pdfData !== null && $pdfData['monto'] !== null) {
                            $montoItemMXN = (float) $pdfData['monto'];
                        }
                    }

                    if ($montoItemMXN > 0) {
                        if (in_array(round($montoItemMXN, 2), $montosYaSumados)) {
                            continue;
                        }

                        $sumaFacturasMXN += $montoItemMXN;
                        $montosYaSumados[] = round($montoItemMXN, 2);
                        $huboCoincidencia = true;
                        $foliosComb[] = $folioArchivo;
                        
                        if (!$rutaXmlFinal && !empty($datosMuestra['path_xml_mue'])) {
                            $rutaXmlFinal = $datosMuestra['path_xml_mue'];
                        }
                        if (!$rutaPdfFinal && !empty($datosMuestra['path_pdf_mue'])) {
                            $rutaPdfFinal = $datosMuestra['path_pdf_mue'];
                        }
                    }
                }

                if (!$huboCoincidencia) {
                    $sumaFacturasMXN = -1;
                    $folioFinal = $listaMuestras[0]['folio'] ?? 'S/F';
                    $rutaXmlFinal = $listaMuestras[0]['path_xml_mue'] ?? null;
                    $rutaPdfFinal = $listaMuestras[0]['path_pdf_mue'] ?? null;
                } else {
                    $folioFinal = implode(' | ', array_unique($foliosComb));
                }

                $montoTotalReportar = $sumaFacturasMXN > 0 ? (float) $sumaFacturasMXN : 0.0;
                $montoSC = (float) $datosSC['monto_muestras_sc_mxn'];
                $estado = $this->compararMontos_Muestras($montoSC, (float) $sumaFacturasMXN);

                $muestrasParaGuardar[] = [
                    'operacion_id' => $operacionId['id_operacion'],
                    'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type' => isset($mapeadoFacturas['pedimentos_importacion'][$numPedRef]) ? Importacion::class : Exportacion::class,
                    'tipo_documento' => 'muestras',
                    'concepto_llave' => 'principal',
                    'folio' => $folioFinal,
                    'fecha_documento' => now()->format('Y-m-d'),
                    'monto_total' => $montoTotalReportar, 
                    'monto_total_mxn' => $sumaFacturasMXN,
                    'monto_diferencia_sc' => round($montoSC - $montoTotalReportar, 2),
                    'moneda_documento' => $monedaDoc,
                    'estado' => $estado,
                    'ruta_xml' => $rutaXmlFinal,
                    'ruta_pdf' => $rutaPdfFinal,
                    'updated_at' => now(),
                ];
            }

            if (!empty($muestrasParaGuardar)) {
                Auditoria::upsert($muestrasParaGuardar, 
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], 
                    ['estado', 'monto_total_mxn', 'monto_total', 'monto_diferencia_sc', 'moneda_documento', 'ruta_xml', 'ruta_pdf', 'folio', 'updated_at']
                );
            }
            
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) { 
            return ['code' => 1, 'message' => $e]; 
        }
    }

    /**
     * Lee los archivos y crea un mapa [pedimento => rutas] para MANIOBRAS (TERMINALES).
     * MODIFICADO: Atrapa también ferroviarias, fumigación y maniobras extra por nomenclatura.
     */
    private function construirIndiceOperacionesManiobras(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas']);
                
                $facturasAgrupadas = [];
                foreach ($coleccionFacturas as $factura) {
                    $rutaF = $factura['ruta_pdf'] ?? $factura['ruta_xml'] ?? '';
                    if (empty($rutaF)) {
                        continue;
                    }

                    $filename = pathinfo(parse_url($rutaF, PHP_URL_PATH), PATHINFO_FILENAME);
                    $nombreLimpio = preg_replace('/^\d+-/', '', $filename);
                    $nombreLimpio = preg_replace('/[-_]?\(\d+\)$/', '', $nombreLimpio);
                    $nombreLimpio = preg_replace('/-\d+$/', '', $nombreLimpio);
                    if (empty($nombreLimpio)) {
                        $nombreLimpio = 'UNK_' . uniqid();
                    }

                    if (!isset($facturasAgrupadas[$nombreLimpio])) {
                        $facturasAgrupadas[$nombreLimpio] = [
                            'ruta_pdf' => null,
                            'ruta_xml' => null,
                            'tipo_documento' => '',
                            'folio' => $factura['folio'] ?? $nombreLimpio,
                            'nombre_real' => strtoupper($filename)
                        ];
                    }

                    if (!empty($factura['ruta_pdf'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_pdf'] = $factura['ruta_pdf'];
                    }
                    if (!empty($factura['ruta_xml'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_xml'] = $factura['ruta_xml'];
                    }

                    if (!empty($factura['tipo_documento']) && strtolower($factura['tipo_documento']) !== 'sc') {
                        $facturasAgrupadas[$nombreLimpio]['tipo_documento'] = strtolower($factura['tipo_documento']);
                    }
                }
                $coleccionAgrupada = collect(array_values($facturasAgrupadas));

                // Filtro ESTRICTO + REGLAS EXTRAS
                $facturasManiobra = $coleccionAgrupada->filter(function ($factura) {
                    $tipo = $factura['tipo_documento'] ?? '';
                    $nombre = $factura['nombre_real'] ?? '';

                    $esManiobraNormal = (str_contains($tipo, 'maniobra') && !str_contains($tipo, 'terminal'));
                    
                    // REGLA PARA ATRAPAR ARCHIVOS EN LA CARPETA "OTROS"
                    $esArchivoEspecial = str_contains($nombre, 'FACTURAKCSM') || 
                                         str_contains($nombre, 'FSA030920NT1') || 
                                         str_contains($nombre, 'MSN1410164Q4');

                    return ($esManiobraNormal || $esArchivoEspecial) && !empty($factura['ruta_pdf']);
                });

                foreach ($facturasManiobra as $factura) {
                    $indice[$pedimento][] = [
                        'folio' => $factura['folio'],
                        'path_xml_man' => $factura['ruta_xml'] ?? null, 
                        'path_pdf_man' => $factura['ruta_pdf'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de maniobras: " . $e->getMessage());
        }
        return $indice;
    }

    /**
     * Detecta el formato del PDF (CONTECON, SSA, etc.) y extrae el total real Y LA FECHA.
     */
    private function extraerTotalDesdePdfProveedor(string $rutaPdf)
    {
        try {
            $arrContextOptions = [
                "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
                "http" => ["ignore_errors" => true, "timeout" => 20]
            ];
            
            $contenidoPdf = @file_get_contents($rutaPdf, false, stream_context_create($arrContextOptions));
            
            if (!$contenidoPdf || str_contains($http_response_header[0] ?? '', '404')) {
                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaPdf);
                $contenidoPdf = @file_get_contents($rutaAlternativa, false, stream_context_create($arrContextOptions));
            }
            
            if (!$contenidoPdf) return null;

            // Protección de Memoria
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);
            $parser = new Parser([], $config);
            $pdf = $parser->parseContent($contenidoPdf);
            $texto = preg_replace('/\s+/', ' ', $pdf->getText());
            $textoSinEspacios = preg_replace('/\s+/', '', $texto);

            $monto = null;
            $fecha = null;
            $folio = null;

            if (preg_match('/PLEASE\s*PAY\s*THIS\s*AMOUNT[^\d]*([\d,]+\.\d{2})/i', $texto, $matches)) {
                $monto = (float) str_replace(',', '', $matches[1]);
            } 
            elseif (preg_match('/PLEASEPAYTHISAMOUNT.*?([\d,]+\.\d{2})/i', $textoSinEspacios, $matchesEspacios)) {
                $monto = (float) str_replace(',', '', $matchesEspacios[1]);
            }
            // Algunas LLC usan BALANCE DUE o TOTAL DUE
            elseif (preg_match('/(?:BALANCE|TOTAL)\s+DUE[^\d]*([\d,]+\.\d{2})/i', $texto, $matches)) {
                $monto = (float) str_replace(',', '', $matches[1]);
            }

            if ($monto === null && str_contains(strtoupper($texto), 'SSA')) {
                if (preg_match('/(?:Importe|Total\s+Servicios|Total\s+Facturado)[\s:]+([\d,]+\.\d{2})/i', $texto, $matches)) {
                    $monto = (float) str_replace(',', '', $matches[1]);
                }
            }

            if ($monto === null) {
                if (preg_match('/(?:TOTAL|Saldo|Total a Pagar|NETO|IMPORTE\s+TOTAL|TOTAL\s+A\s+PAGAR)[\s:]+[\$]?\s*([\d,]+\.\d{2})\b/i', $texto, $matches)) {
                    $monto = (float) str_replace(',', '', $matches[1]);
                }
            }

            if ($monto === null && str_contains(strtoupper($texto), 'INTACTICS')) {
                if (preg_match_all('/(?:USD|\$)\s*([\d,]+\.\d{2})/i', $texto, $matchesExtras)) {
                    $monto = (float) str_replace(',', '', end($matchesExtras[1])); // end() toma el último
                }
            }

            // EXTRACCIÓN DE FECHA
            if (preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4})/', $texto, $matchF)) {
                try {
                    $fecha = \Carbon\Carbon::createFromFormat('d/m/Y', str_replace('-', '/', $matchF[1]))->format('Y-m-d');
                } catch (\Exception $e) { $fecha = null; }
            }

            // EXTRACCIÓN DE FOLIO
            if (preg_match('/(?:Factura|Invoice|No\.)\s*[:]?\s*(\d{5,})/i', $texto, $matchFolio)) {
                $folio = trim($matchFolio[1]);
            }

            return [
                'monto' => $monto,
                'fecha' => $fecha,
                'folio' => $folio 
            ];
            
        } catch (\Throwable $e) {
            Log::warning("Fallo al extraer texto de PDF ({$rutaPdf}): " . $e->getMessage());
            return null;
        }
    }

    private function compararMontos_Maniobras(float $esperado, float $real): string
    {
        if ($esperado == -1) {
            return 'Sin SC!';
        }
        if ($real == -1) {
            return 'Sin Maniobras!';
        }
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }

    /**
     * Se encarga de auditar las Maniobras LOCALMENTE en la Base de Datos.
     * NUNCA envía a Google Sheets.
     * MODIFICADO: Suma y deduplicación universal para todos los clientes y sucursales.
     */
    public function auditarFacturasDeManiobras(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Maniobras: Tarea no válida o no está en estado procesando.");
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("--- [INICIO] Auditoría de Maniobras para Tarea #{$tarea->id} ---");

        try {
            $mapeadoFacturas = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];
            
            Log::info("Construyendo índice de operaciones de Maniobras...");
            $indiceManiobras = $this->construirIndiceOperacionesManiobras($indicesOperaciones);
            Log::info("Índice de Maniobras construido. Pedimentos con maniobras detectadas: " . count($indiceManiobras));

            $auditoriasSC = $mapeadoFacturas['auditorias_sc'] ?? [];
            
            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos']) ? $auditoria['desglose_conceptos'] : json_decode($auditoria['desglose_conceptos'], true);
                $pedimentoEncontrado = collect($mapaPedimentoAId)->firstWhere('id_pedimiento', $auditoria['pedimento_id']);
                if($pedimentoEncontrado){
                    $numPed = trim($pedimentoEncontrado['num_pedimiento']);
                    $indiceSC[$numPed] = [
                        'monto_maniobras_sc_mxn' => (float) ($desglose['montos']['maniobras_mxn'] ?? -1),
                        'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            $maniobrasParaGuardar = []; 

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                $numPedRef = trim($pedimentoSucioYId['num_pedimiento']);
                
                // 🚀 Búsqueda blindada para pedimentos consolidados (con guion)
                $listaManiobras = $indiceManiobras[$numPedRef] ?? $indiceManiobras[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$numPedRef] ?? ['monto_maniobras_sc_mxn' => -1, 'tipo_cambio' => 1.0];

                if (!$listaManiobras) {
                    continue;
                }

                $opId = $pedimentoSucioYId['id_operacion'] ?? null;
                $tipoOpClass = ($pedimentoSucioYId['tipo'] ?? '') == 'Importacion' ? Importacion::class : Exportacion::class;

                $sumaTotal = 0;
                $huboCoincidencia = false;
                $foliosComb = [];
                $xmlsComb = [];
                $pdfsComb = [];
                $montosYaSumados = [];
                $monedaDoc = 'MXN';
                $fechaDocumento = now()->format('Y-m-d');

                foreach ($listaManiobras as $idx => $datosManiobra) {
                    $montoItem = -1;
                    $origen = 'Ninguno';
                    
                    $folioRaw = $datosManiobra['folio'] ?? "Archivo_" . ($idx + 1);
                    $folioArchivo = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;

                    if (!empty($datosManiobra['path_xml_man'])) {
                        $xmlData = $this->parsearXmlFlete($datosManiobra['path_xml_man']);
                        if ($xmlData && $xmlData['total'] != -1) {
                            $montoItem = ($xmlData['moneda'] === "USD") 
                                ? round($xmlData['total'] * $datosSC['tipo_cambio'], 2) 
                                : $xmlData['total'];
                            $fechaDocumento = $xmlData['fecha'] ?? $fechaDocumento;
                            $origen = "XML ({$xmlData['moneda']})";
                            $monedaDoc = $xmlData['moneda'];
                            
                            if (!empty($xmlData['folio'])) {
                                $folioArchivo = $xmlData['folio'];
                            }
                        }
                    }

                    // Se eliminó el "Fallback a PDF" para maniobras por regla de negocio estricta

                    if ($montoItem > 0) {
                        if (in_array(round($montoItem, 2), $montosYaSumados)) {
                            continue;
                        }


                        $sumaTotal += $montoItem;
                        $montosYaSumados[] = round($montoItem, 2);
                        $huboCoincidencia = true;
                        $foliosComb[] = $folioArchivo;
                        
                        if (!empty($datosManiobra['path_xml_man'])) {
                            $xmlsComb[] = $datosManiobra['path_xml_man'];
                        }
                        if (!empty($datosManiobra['path_pdf_man'])) {
                            $pdfsComb[] = $datosManiobra['path_pdf_man'];
                        }
                    } 
                }

                if ($huboCoincidencia) {
                    $montoFacturaMXN = $sumaTotal;
                    $folioFinal = implode(' | ', array_unique($foliosComb));
                    $rutaXmlFinal = $xmlsComb[0] ?? null;
                    $rutaPdfFinal = $pdfsComb[0] ?? null;
                } else {
                    $montoFacturaMXN = -1;
                    $folioFinal = $listaManiobras[0]['folio'] ?? 'S/F';
                    $rutaXmlFinal = $listaManiobras[0]['path_xml_man'] ?? null;
                    $rutaPdfFinal = $listaManiobras[0]['path_pdf_man'] ?? null;
                }

                $montoTotalReportar = $montoFacturaMXN > 0 ? $montoFacturaMXN : 0;
                $montoSCMXN = (float) $datosSC['monto_maniobras_sc_mxn'];
                
                $estado = $this->compararMontos_Maniobras($montoSCMXN, (float) $montoFacturaMXN);
                $diferenciaSc = ($estado !== "Sin SC!") ? round($montoSCMXN - $montoTotalReportar, 2) : $montoTotalReportar;

                $maniobrasParaGuardar[] = [
                    'operacion_id' => $opId,
                    'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type' => $tipoOpClass,
                    'tipo_documento' => 'maniobras',
                    'concepto_llave' => 'principal',
                    'folio' => $folioFinal,
                    'fecha_documento' => $fechaDocumento,
                    'monto_total' => $montoTotalReportar,
                    'monto_total_mxn' => $montoFacturaMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => $monedaDoc,
                    'estado' => $estado,
                    'ruta_xml' => $rutaXmlFinal,
                    'ruta_pdf' => $rutaPdfFinal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($maniobrasParaGuardar)) {
                Log::info("Maniobras: Guardando " . count($maniobrasParaGuardar) . " registros finales en la Base de Datos...");
                Auditoria::upsert($maniobrasParaGuardar, 
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], 
                    ['fecha_documento', 'monto_total', 'monto_total_mxn', 'monto_diferencia_sc', 'moneda_documento', 'estado', 'ruta_xml', 'ruta_pdf', 'folio', 'updated_at']
                );
            }

            Log::info("--- [FIN] Auditoría de Maniobras completada con éxito. ---");
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) { 
            Log::error("Error CRÍTICO en Maniobras: " . $e->getMessage() . " en la línea " . $e->getLine());
            return ['code' => 1, 'message' => $e]; 
        }
    }

    /**
     * Envía EXCLUSIVAMENTE los datos de Maniobras Locales a Google Sheets.
     * Solo debe ser llamado si la sucursal es ZLO.
     */
    public function enviarAGPCManiobras(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Iniciando extracción de Maniobras Locales para GPC...");

        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            $mapeadoFacturas = (array) json_decode(Storage::get($rutaMapeo), true);

            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);
            
            $indiceManiobras = $this->construirIndiceOperacionesManiobras($indicesOperaciones);

            $maniobrasParaSheets = []; 

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $listaFacturasManiobra = $indiceManiobras[$pedimentoLimpio] ?? [];
                
                foreach ($listaFacturasManiobra as $index => $datosManiobra) {
                    $infoExtraccion = $this->extraerMontoYNaviera($datosManiobra['ruta_xml'] ?? null, $datosManiobra['ruta_pdf'] ?? null);
                    Log::info("🔍 Espía Terminales - Ped: {$pedimentoLimpio} | Monto: {$infoExtraccion['monto']} | Naviera extraída: [{$infoExtraccion['naviera']}]");

                    if ($infoExtraccion['monto'] > 0) {
                        $conceptoNombre = 'Maniobras Locales' . ($index > 0 ? ' ' . ($index + 1) : '');

                        $maniobrasParaSheets[] = [
                            'fecha'     => $infoExtraccion['fecha'],
                            'pedimento' => $pedimentoLimpio,
                            'concepto'  => $conceptoNombre, 
                            'monto'     => $infoExtraccion['monto'], 
                            'moneda'    => 'MXN', 
                            'naviera'   => $infoExtraccion['naviera']
                        ];
                    }
                }
            }

            if (!empty($maniobrasParaSheets)) {
                Log::info("Maniobras Locales listas para enviar a GPC: " . count($maniobrasParaSheets));
                $paquetes = array_chunk($maniobrasParaSheets, 50);
                foreach ($paquetes as $index => $paquete) {
                    $numeroPaquete = $index + 1;
                    $totalPaquetes = count($paquetes);

                    // Si estamos en el último ciclo, esto será true
                    $esUltimo = ($numeroPaquete === $totalPaquetes);

                    Log::info("Enviando paquete {$numeroPaquete} de {$totalPaquetes}...");

                    // Le pasamos la bandera a la función
                    $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);

                    sleep(2);
                }
                Log::info("¡Todas las Maniobras Locales enviadas con éxito!");
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error enviando Maniobras Locales a GPC: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Lee los archivos y crea un mapa EXCLUSIVO para Terminales.
     */
    private function construirIndiceOperacionesTerminales(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas']);
                $facturasAgrupadas = [];
                
                foreach ($coleccionFacturas as $factura) {
                    $rutaF = $factura['ruta_pdf'] ?? $factura['ruta_xml'] ?? '';
                    if (empty($rutaF)) {
                        continue;
                    }

                    $filename = pathinfo(parse_url($rutaF, PHP_URL_PATH), PATHINFO_FILENAME);
                    
                    // 🚀 REGRESAMOS A LA LÓGICA DE LIMPIEZA (Deduplicador Natural)
                    $nombreLimpio = preg_replace('/^\d+[-_]+/', '', $filename);
                    $nombreLimpio = preg_replace('/[-_\s]?\(\d+\)$/', '', $nombreLimpio);
                    $nombreLimpio = preg_replace('/[-_]+\d+$/', '', $nombreLimpio);

                    if (empty($nombreLimpio)) {
                        $nombreLimpio = 'UNK_' . uniqid();
                    }

                    if (!isset($facturasAgrupadas[$nombreLimpio])) {
                        $facturasAgrupadas[$nombreLimpio] = [
                            'ruta_pdf' => null,
                            'ruta_xml' => null,
                            'tipo_documento' => strtolower($factura['tipo_documento'] ?? ''),
                            'folio' => $factura['folio'] ?? $nombreLimpio,
                            'nombre_archivo_real' => strtolower($filename)
                        ];
                    }

                    // Sobrescribe naturalmente si el archivo está duplicado
                    if (!empty($factura['ruta_pdf'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_pdf'] = $factura['ruta_pdf'];
                    }
                    if (!empty($factura['ruta_xml'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_xml'] = $factura['ruta_xml'];
                    }
                }

                $facturasTerminal = collect(array_values($facturasAgrupadas))->filter(function ($factura) {
                    $tipo = $factura['tipo_documento'] ?? '';
                    $textoParaBuscar = strtolower(($factura['folio'] ?? '') . ' ' . ($factura['nombre_archivo_real'] ?? ''));
                    
                    $esCarpetaValida = str_contains($tipo, 'terminal') || str_contains($tipo, 'proveedor');
                    
                    $keywordsTerminal = ['terminal', 'maniobra'];
                    $hasKeyword = false;
                    foreach($keywordsTerminal as $key) {
                        if(str_contains($textoParaBuscar, $key)) { 
                            $hasKeyword = true; break; 
                        }
                    }

                    $esExcluido = str_contains($textoParaBuscar, 'conexion') || 
                                  str_contains($textoParaBuscar, 'admin') || 
                                  str_contains($textoParaBuscar, 'ctrol') || 
                                  str_contains($textoParaBuscar, 'almacen') || 
                                  str_contains($textoParaBuscar, 'vacio') ||
                                  str_contains($textoParaBuscar, 'reacomodo');

                    $tienePdf = !empty($factura['ruta_pdf']);
                    $pasaFiltro = $esCarpetaValida && $hasKeyword && !$esExcluido && $tienePdf;

                    return $pasaFiltro;
                });

                foreach ($facturasTerminal as $factura) {
                    $indice[$pedimento][] = [
                        'folio' => $factura['folio'],
                        'path_xml_man' => $factura['ruta_xml'] ?? null, 
                        'path_pdf_man' => $factura['ruta_pdf'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de terminales: " . $e->getMessage());
        }
        return $indice;
    }

    //--- METODO DEDICADO: EXTRAER MANIOBRAS (TERMINALES) Y ENVIAR A GPC
    public function enviarAGPCTerminales(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Iniciando envío de Terminales a GPC...");

        try {
            $mapeadoFacturas = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            
            $indiceManiobras = $this->construirIndiceOperacionesTerminales($indicesOperaciones);
            $maniobrasParaSheets = []; 

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $pedimentoSucio = trim($datosId['num_pedimiento'] ?? '');
                $listaFacturasManiobra = $indiceManiobras[$pedimentoSucio] ?? $indiceManiobras[$pedimentoLimpio] ?? [];
                
                if (empty($listaFacturasManiobra)) {
                    continue;
                }

                $opId = $datosId['id_operacion'];
                $tipoOp = ($datosId['tipo'] == 'Importacion') ? Importacion::class : Exportacion::class;
                $queryOp = ($tipoOp === Importacion::class) ? Importacion::with('cliente')->find($opId) : Exportacion::with('cliente')->find($opId);

                $filasTemp = [];
                $montosRegistrados = []; 

                foreach ($listaFacturasManiobra as $datosManiobra) {
                    
                    $infoExtraccion = $this->extraerMontoYNaviera($datosManiobra['path_xml_man'] ?? null, $datosManiobra['path_pdf_man'] ?? null);

                    if ($infoExtraccion['monto'] > 0) {
                        
                        $numeroDeContenedores = $this->contarContenedoresUniversal($datosManiobra['path_xml_man'] ?? null, $datosManiobra['path_pdf_man'] ?? null);
                        $montoDividido = round($infoExtraccion['monto'] / $numeroDeContenedores, 2);

                        // Deduplicación estricta por monto dividido
                        $montoKey = (string)$montoDividido;

                        if (in_array($montoKey, $montosRegistrados)) {
                            Log::info("Refrenando duplicado en GPC - Ped: {$pedimentoLimpio} | Monto: {$montoDividido} ya procesado.");
                            continue;
                        }

                        $montosRegistrados[] = $montoKey;
                        
                        $indexActual = count($filasTemp);
                        
                        // Permite hasta dos entradas con el mismo nombre base para el formato manual
                        if ($indexActual === 0 || $indexActual === 1) {
                            $conceptoNombre = 'Maniobras en Terminal';
                        } else {
                            $conceptoNombre = 'Maniobras en Terminal ' . ($indexActual + 1);
                        }

                        $filasTemp[] = [
                            'fecha'      => $infoExtraccion['fecha'],
                            'cliente'    => $queryOp ? optional($queryOp->cliente)->nombre : '',
                            'contenedor' => $queryOp ? ($queryOp->contenedor ?? '') : '',
                            'bl'         => $queryOp ? ($queryOp->bol ?? '') : '',
                            'pedimento'  => $pedimentoLimpio,
                            'concepto'   => $conceptoNombre,
                            'monto'      => $montoDividido,
                            'moneda'     => 'MXN',
                            'naviera'    => $infoExtraccion['naviera']
                        ];
                    }
                }

                // Agregamos las filas filtradas al paquete global
                foreach($filasTemp as $filaFinal) {
                    $maniobrasParaSheets[] = $filaFinal;
                }
            }

            if (!empty($maniobrasParaSheets)) {
                Log::info("Terminales encontradas para enviar: " . count($maniobrasParaSheets));
                $paquetes = array_chunk($maniobrasParaSheets, 50);
                foreach ($paquetes as $idx => $paquete) {
                    $esUltimo = ($idx === count($paquetes) - 1);
                    $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                    sleep(1);
                }
                Log::info("¡Envío de Terminales completado!");
            } else {
                Log::warning("No se encontraron montos de Terminales para enviar.");
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error en Terminales: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

/**
     * Validador Inteligente de Vacíos
     * Lee el XML y descarta automáticamente lavados, limpiezas y otros cobros.
     */
    private function esFacturaDeVacioXML(?string $rutaXml): bool
    {
        if (empty($rutaXml)) return false;

        try {
            $response = Http::withoutVerifying()->timeout(10)->get($rutaXml);
            $xmlString = $response->successful() ? $response->body() : null;

            if (!$xmlString) {
                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);
                $responseAlt = Http::withoutVerifying()->timeout(10)->get($rutaAlternativa);
                $xmlString = $responseAlt->successful() ? $responseAlt->body() : null;
            }

            if (!$xmlString) return false;

            $pos = strpos($xmlString, '<?xml');
            if ($pos !== false) $xmlString = substr($xmlString, $pos);

            $xml = new \SimpleXMLElement($xmlString);
            $conceptos = $xml->xpath('//*[local-name()="Concepto"]');

            if (!empty($conceptos)) {
                foreach ($conceptos as $concepto) {
                    $attrs = $concepto->attributes();
                    $descripcion = strtoupper((string) ($attrs['Descripcion'] ?? ''));
                    $claveProdServ = trim((string) ($attrs['ClaveProdServ'] ?? ''));

                    // Si la descripción contiene la palabra "VACIO" o su clave SAT es la de vacío, es la correcta
                    if (str_contains($descripcion, 'VACIO') || $claveProdServ === '78131702') {
                        return true;
                    }
                }
            }
        } catch (\Throwable $th) {
            // Falla silenciosa
        }

        return false;
    }

    /**
     * Lee los archivos y crea un mapa EXCLUSIVO para Vacíos.
     * Integrado con el validador inteligente para descartar lavados/limpiezas.
     */
    private function construirIndiceOperacionesVacios(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];

        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) continue;

                $coleccionFacturas = collect($datos['facturas']);
                
                // Filtramos las facturas que el mapeador universal marcó como 'vacios'
                $facturasVacio = $coleccionFacturas->filter(function ($factura) {
                    $tipo = strtolower($factura['tipo_documento'] ?? '');
                    
                    // Solo PDF es obligatorio para procesar el total
                    if ($tipo !== 'vacios' || empty($factura['ruta_pdf'])) {
                        return false;
                    }

                    // 🚀 AQUÍ INTEGRAMOS TU VALIDADOR INTELIGENTE
                    if (!empty($factura['ruta_xml'])) {
                        // Si el XML indica que NO es de vacío (ej. es puro lavado), lo descartamos.
                        if (!$this->esFacturaDeVacioXML($factura['ruta_xml'])) {
                            Log::info("Factura descartada inteligentemente: No es un cobro de vacío real (XML: {$factura['ruta_xml']})");
                            return false;
                        }
                    }

                    // Si pasó la validación (o si no tiene XML para revisar), lo dejamos pasar.
                    return true;
                });

                foreach ($facturasVacio as $nombreUnico => $factura) {
                    $indice[$pedimento][] = [
                        'folio'    => $factura['folio'] ?? $nombreUnico,
                        'ruta_xml' => $factura['ruta_xml'] ?? null,
                        'ruta_pdf' => $factura['ruta_pdf'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de vacíos: " . $e->getMessage());
        }

        return $indice;
    }

    /**
     * Método Maestro: Cuenta el número de contenedores en una factura.
     * PRIORIDAD 1: Busca matrículas reales en el PDF (Infalible para Terminales y Almacenaje).
     * PRIORIDAD 2: Usa la 'Cantidad' del XML como respaldo (Útil para Vacíos escaneados).
     */
    private function contarContenedoresUniversal(?string $rutaXml, ?string $rutaPdf): int
    {
        // FASE 1: Búsqueda infalible por matrículas (4 Letras + 7 Números) en el PDF
        if (!empty($rutaPdf)) {
            try {
                $arrContextOptions = [
                    "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
                    "http" => ["ignore_errors" => true, "timeout" => 15]
                ];
                
                $contenidoPdf = @file_get_contents($rutaPdf, false, stream_context_create($arrContextOptions));
                
                if (!$contenidoPdf || str_contains($http_response_header[0] ?? '', '404')) {
                    $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaPdf);
                    $contenidoPdf = @file_get_contents($rutaAlternativa, false, stream_context_create($arrContextOptions));
                }

                if ($contenidoPdf) {
                    $config = new \Smalot\PdfParser\Config();
                    $config->setRetainImageContent(false);
                    $parser = new Parser([], $config);
                    $pdf = $parser->parseContent($contenidoPdf);
                    $textoPdf = $pdf->getText();

                    // Regex estándar: 4 Letras + 7 Números (Ej. MNBU9063533)
                    if (preg_match_all('/\b([A-Z]{4}\d{7})\b/', $textoPdf, $matches)) {
                        $contenedoresUnicos = array_unique($matches[1]);
                        if (count($contenedoresUnicos) > 0) {
                            return count($contenedoresUnicos);
                        }
                    }
                }
            } catch (\Throwable $th) {
                // Falla silenciosa, pasa a Fase 2
            }
        }

        // FASE 2: Respaldo por XML (Útil para Vacíos si el PDF es una imagen ilegible)
        if (!empty($rutaXml)) {
            try {
                $arrContextOptions = [
                    "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
                    "http" => ["ignore_errors" => true]
                ];
                
                $xmlString = @file_get_contents($rutaXml, false, stream_context_create($arrContextOptions));
                
                if (!$xmlString || str_contains($http_response_header[0] ?? '', '404')) {
                    $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);
                    $xmlString = @file_get_contents($rutaAlternativa, false, stream_context_create($arrContextOptions));
                }

                if ($xmlString) {
                    $xmlObj = @simplexml_load_string($xmlString);
                    if ($xmlObj !== false) {
                        $namespaces = $xmlObj->getNamespaces(true);
                        $cfdi = $xmlObj->children($namespaces['cfdi'] ?? 'cfdi');
                        
                        if (isset($cfdi->Conceptos->Concepto)) {
                            $cantidadMaxima = 0;
                            
                            foreach ($cfdi->Conceptos->Concepto as $concepto) {
                                $descripcion = strtoupper((string) ($concepto['Descripcion'] ?? ''));
                                $claveProdServ = trim((string) ($concepto['ClaveProdServ'] ?? ''));
                                
                                // Detecta conceptos relevantes
                                if (str_contains($descripcion, 'VACIO') || str_contains($descripcion, 'ALMACEN') || 
                                    str_contains($descripcion, 'MANIOBRA') || str_contains($descripcion, 'MUELLE') ||
                                    in_array($claveProdServ, ['78141800', '78131702', '78101802'])) {
                                    
                                    $cantidadActual = (float) $concepto['Cantidad'];
                                    if ($cantidadActual > $cantidadMaxima) {
                                        $cantidadMaxima = $cantidadActual;
                                    }
                                }
                            }
                            
                            if ($cantidadMaxima > 0) {
                                return (int) $cantidadMaxima;
                            }
                        }
                    }
                }
            } catch (\Throwable $th) {
                // Falla silenciosa
            }
        }

        return 1; // Por defecto 1 para evitar división por cero
    }

    // Enviar a Google Sheets el resultado de Vacíos
    public function enviarAGPCVacios(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Iniciando extracción de Vacíos...");

        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal.")];
            }

            $mapeadoFacturas = (array) json_decode(Storage::get($rutaMapeo), true);
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);
            
            $indiceVacios = $this->construirIndiceOperacionesVacios($indicesOperaciones);
            $vaciosParaSheets = []; 

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $listaFacturasVacio = $indiceVacios[$pedimentoLimpio] ?? [];
                if (empty($listaFacturasVacio)) {
                    continue;
                }
                
                $opId = $datosId['id_operacion'];
                $tipoOp = ($datosId['tipo'] == 'Importacion') ? Importacion::class : Exportacion::class;
                $queryOp = ($tipoOp === Importacion::class) ? Importacion::with('cliente')->find($opId) : Exportacion::with('cliente')->find($opId);

                $filasTemp = [];
                $montosRegistrados = [];

                foreach ($listaFacturasVacio as $datosVacio) {
                    $infoExtraccion = $this->extraerMontoYNaviera($datosVacio['ruta_xml'] ?? null, $datosVacio['ruta_pdf'] ?? null);

                    if ($infoExtraccion['monto'] > 0) {
                        
                        $numeroDeContenedores = $this->contarContenedoresUniversal($datosVacio['ruta_xml'] ?? null, $datosVacio['ruta_pdf'] ?? null);
                        $montoDividido = round($infoExtraccion['monto'] / $numeroDeContenedores, 2);

                        // Deduplicación estricta por monto dividido
                        $montoKey = (string)$montoDividido;
                        if (in_array($montoKey, $montosRegistrados)) {
                            continue;
                        }
                        $montosRegistrados[] = $montoKey;

                        $indexActual = count($filasTemp);
                        
                        // Permite hasta dos entradas con el mismo nombre base para el formato manual
                        if ($indexActual === 0 || $indexActual === 1) {
                            $conceptoNombre = 'Maniobras de Vacios';
                        } else {
                            $conceptoNombre = 'Maniobras de Vacios ' . ($indexActual + 1);
                        }

                        $filasTemp[] = [
                            'fecha'      => $infoExtraccion['fecha'],
                            'cliente'    => $queryOp ? optional($queryOp->cliente)->nombre : '',
                            'contenedor' => $queryOp ? ($queryOp->contenedor ?? '') : '',
                            'bl'         => $queryOp ? ($queryOp->bol ?? '') : '',
                            'pedimento'  => $pedimentoLimpio,
                            'concepto'   => $conceptoNombre, 
                            'monto'      => $montoDividido, 
                            'moneda'     => 'MXN',
                            'naviera'    => $infoExtraccion['naviera']
                        ];
                    }
                }

                // Vaciamos las filas temporales al acumulador global
                foreach($filasTemp as $fila) {
                    $vaciosParaSheets[] = $fila;
                }
            }

            if (!empty($vaciosParaSheets)) {
                Log::info("Vacíos listos para enviar a GPC: " . count($vaciosParaSheets));
                $paquetes = array_chunk($vaciosParaSheets, 50);
                foreach ($paquetes as $index => $paquete) {
                    $esUltimo = ($index === count($paquetes) - 1);
                    $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                    sleep(2);
                }
                Log::info("¡Todos los Vacíos enviados con éxito!");
            } else {
                Log::info("Vacíos: No se encontraron registros con monto para enviar a Sheets.");
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error Vacíos: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Helper que extrae el monto total, naviera y la FECHA.
     */
    private function extraerMontoYNaviera(?string $rutaXml, ?string $rutaPdf): array
    {
        $monto = -1;
        $naviera = '';
        $fecha = null;

        // 1. Intentar con XML (Suele ser más preciso)
        if (!empty($rutaXml)) {
            $xmlData = $this->parsearXmlFlete($rutaXml);
            if ($xmlData && $xmlData['total'] != -1) {
                $monto = ($xmlData['moneda'] === "USD") ? round($xmlData['total'] * 1.0, 2) : $xmlData['total'];
                $naviera = $xmlData['emisor'] ?? '';
                $fecha = $xmlData['fecha'] ?? null;
            }
        }

        // 2. Fallback a PDF (Si no hay XML o falló)
        if ($monto === -1 && !empty($rutaPdf)) {
            $pdfData = $this->extraerTotalDesdePdfProveedor($rutaPdf);
            if ($pdfData !== null && $pdfData['monto'] !== null) { 
                $monto = $pdfData['monto']; 
                if (!empty($pdfData['fecha'])) {
                    $fecha = $pdfData['fecha']; // Atrapamos la fecha del PDF
                }
            }
        }

        return [
            'monto'   => (float) $monto,
            'naviera' => $naviera,
            'fecha'   => $fecha ?: now()->format('Y-m-d') // Si todo falla, pone la de hoy
        ];
    }

    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ FINAL DE LOS METODOS AUXILIARES - AuditoriaImpuestosController -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================

    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ INICIO DE LOS METODOS PRINCIPALES - DocumentoController -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    /**
     * Muestra un documento local (ej. impuestos) desde el storage.
     */
    public function mostrarDocumentoLocal(Request $request, string $tipo, int $id)
    {
        $factura = Auditoria::findOrFail($id);
        $rutaAbsoluta = $factura->ruta_pdf;

        // Verificamos que la ruta exista y que el archivo sea legible.
        if (!$rutaAbsoluta || !is_file($rutaAbsoluta) || !is_readable($rutaAbsoluta)) {
            abort(404, 'El archivo local no fue encontrado o no se puede leer.');
        }

        // ✅ PASO 1: Detectamos la extensión del archivo dinámicamente.
        $extension = strtolower(pathinfo($rutaAbsoluta, PATHINFO_EXTENSION));

        // PASO 2: Si la petición solo quiere información, devolvemos la extensión.
        if ($request->has('info')) {
            return response()->json(['tipo_archivo' => $extension]);
        }

        // PASO 3: Si no es una petición de info, servimos el archivo con el Content-Type correcto.
        $contenido = file_get_contents($rutaAbsoluta);
        $nombreArchivo = basename($rutaAbsoluta);

        // Mapeo de extensiones a tipos MIME para las cabeceras HTTP.
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            // Puedes añadir más tipos de archivo aquí si es necesario
        ];

        // Usamos el tipo MIME correspondiente a la extensión, o un tipo genérico si no se encuentra.
        $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';

        return response($contenido, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="' . $nombreArchivo . '"',
        ]);
    }

    /**
     * Actúa como un proxy para obtener y servir un documento desde una URL externa.
     * Esto resuelve los problemas de CORS.
     */
    public function proxyDocumentoExterno(Request $request)
    {
        // Validamos que nos hayan enviado una URL.
        $request->validate(['url' => 'required|url']);
        $urlExterna = $request->input('url');

        if ($request->has('info')) {
            // Como tu regla de negocio dice que todas las facturas externas son PDF,
            // podemos responder directamente sin necesidad de descargar el archivo.
            return response()->json(['tipo_archivo' => 'pdf']);
        }
        try {
            // Hacemos la petición desde nuestro servidor a la URL externa.
            $response = Http::withoutVerifying()->get($urlExterna);

            if ($response->failed()) {
                abort(502, 'No se pudo obtener el documento desde el servidor de origen.');
            }

            // Servimos el contenido del PDF al navegador con las cabeceras correctas.
            return response($response->body(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . basename($urlExterna) . '"',
            ]);
        } catch (\Throwable $e) {
            abort(500, 'Ocurrió un error al procesar el documento externo.');
        }
    }

    public function mostrarPdf(Request $request)
    {
        // 1. Validamos que nos lleguen los parámetros 'tipo' e 'id'
        $request->validate(
            [
                'tipo' => 'required|string',
                'id' => 'required|integer', // <-- Cambiamos 'id' por 'uuid' y validamos que sea un UUID
            ]
        );

        $tipo = $request->input('tipo');
        $uuid = $request->input('id'); // <-- Usamos el uuid
        $record = null;

        // 2. Buscamos el registro por UUID en la tabla correcta
        if ($tipo === 'sc') {
            $record = AuditoriaTotalSC::where('id', $uuid)->first(); // <-- Buscamos por 'uuid'
        } else {
            $record = Auditoria::where('id', $uuid)->first(); // <-- Buscamos por 'uuid'
        }

        // Si no se encontró el registro en la BD, devolvemos error
        if (!$record) {
            abort(404, 'Registro no encontrado en la base de datos.');
        }

        // 3. Obtenemos la ruta absoluta del PDF desde el registro
        $rutaCompleta = $record->ruta_pdf;

        // 4. Verificamos si el archivo existe en esa ruta (¡requiere permisos de red!)
        if (!$rutaCompleta || !file_exists($rutaCompleta)) {
            abort(404, 'Documento no encontrado en la ubicación física.');
        }

        // 5. Si todo está bien, Laravel sirve el archivo al navegador
        return response()->file($rutaCompleta);
    }

    /**
     * Inicia la descarga de un reporte de auditoría asociado a una tarea.
     *
     * @param  \App\Models\AuditoriaTareas $tarea La tarea obtenida automáticamente por Laravel.
     * @param  string $tipo El tipo de reporte ('facturado' o 'pendiente') que viene de la URL.
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\Response
     */
    public function descargarReporteAuditoria(AuditoriaTareas $tarea, string $tipo)
    {
        // 1. Inicializamos las variables que contendrán la ruta y el nombre del archivo.
        $rutaGuardada = null;
        $nombreDescarga = null;

        // 2. Decidimos qué archivo servir basándonos en el parámetro $tipo de la URL.
        if ($tipo === 'facturado') {
            $rutaGuardada = $tarea->ruta_reporte_impuestos;
            $nombreDescarga = $tarea->nombre_reporte_impuestos;
        } elseif ($tipo === 'pendiente') {
            $rutaGuardada = $tarea->ruta_reporte_impuestos_pendientes;
            $nombreDescarga = $tarea->nombre_reporte_pendientes;
        }

        // 3. Verificamos que el archivo realmente exista en nuestro disco 'storageOldProyect'.
        // --- CAMBIO AQUÍ: Usamos el disco correcto ---
        if (!$rutaGuardada || !Storage::disk('storageOldProyect')->exists($rutaGuardada)) {
            // Si no existe, devolvemos un error 404 (No Encontrado).
            abort(404, 'El archivo solicitado no existe o ha sido eliminado del disco storageOldProyect.');
        }

        // 4. Usamos el método download() de Storage para iniciar la descarga.
        // --- CAMBIO AQUÍ: Descargamos desde el disco correcto ---
        return Storage::disk('storageOldProyect')->download($rutaGuardada, $nombreDescarga);
    }

    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ FIN DE LOS METODOS PRINCIPALES - DocumentoController -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================

    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ INICIO DE LOS METODOS PRINCIPALES - ImportController -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    public function procesarEstadoDeCuenta(Request $request)
    {
        $datosValidados = $request->validate(
            [
                'estado_de_cuenta' => 'required|file', // Aceptamos cualquier archivo
                'banco' => 'required|string',
                'sucursal' => 'required|string',
                'archivos_extras.*' => 'nullable|file', // Para los archivos extra (opcional)
            ]
        );

        // 1. Guardamos el estado de cuenta principal
        $rutaPrincipal = $request->file('estado_de_cuenta')->store('operaciones/estados_de_cuenta');

        // 2. Guardamos los archivos extra si existen
        $rutasExtras = [];
        if ($request->hasFile('archivos_extras')) {
            foreach ($request->file('archivos_extras') as $file) {
                $rutasExtras[] = $file->store('importaciones/extras');
            }
        }

        $nombreArchivo = $datosValidados['estado_de_cuenta']->getClientOriginalName();
        // 3. Creamos el registro de la nueva tarea en la base de datos
        AuditoriaTareas::create(
            [
                'banco' => $datosValidados['banco'],
                'sucursal' => $datosValidados['sucursal'],
                'periodo_meses' => '12',
                'nombre_archivo' => $nombreArchivo,
                'ruta_estado_de_cuenta' => $rutaPrincipal,
                'rutas_extras' => $rutasExtras,
                'status' => 'pendiente', // La tarea empieza como pendiente
            ]
        );

        // 4. Devolvemos una respuesta inmediata y exitosa al usuario
        return response()->json(
            [
                'message' => '¡Solicitud recibida! El procesamiento ha comenzado y se te notificará al completarse.'
            ],
            202
        ); // 202 Accepted
    }

    public function ejecutarComandoDeTareaEnCola(Request $request)
    {
        //ACA NO SE SI LE PONGAS PARAMETROS DISTINTOS EN TU ENTORNO DEL SOL, ACA TE DEJO COMENTADO EL COMANDO
        // QUE ME COMPARTISTE EN CASO DE QUE ESE ES EL QUE USES PARA INICIAR LA AUDITORIA.

        //Artisan::call('mail:send', [
        //    'user' => $user, '--queue' => 'default'
        //]);

        //Artisan::call('reporte:auditar');
    }
    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ FIN DE LOS METODOS PRINCIPALES - ImportController -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================
}