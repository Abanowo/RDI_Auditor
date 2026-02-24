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

    //--- METODO IMPORTAR IMPUESTOS A AUDITORIAS
    // Se encarga de leer el estado de cuenta y obtener los Pedimentos, Cargos y fechas de este.
    // Este metodo sirve de base para iniciar todas las auditorias, debido que de aqui derivan los pedimentos
    // que se mostraran unicamente para el panel de auditorias, y son exclusivamente de aqui.
    public function importarImpuestosEnAuditorias(string $tareaId)
    {
        gc_collect_cycles();
        if (!$tareaId) {
            Log::error('Se requiere el ID de la tarea.');
            return ['code' => 1, 'message' => new \Exception('Se requiere el ID de la tarea.')];
        }

        // 1. Buscar la tarea
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Tarea #{$tareaId} no encontrada o no está en procesando.");
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

        try {
            $tablaDeDatos = [];
            
            if ($banco !== "EXTERNO") {
                $config = new \Smalot\PdfParser\Config();
                $config->setRetainImageContent(false);
                $parser = new \Smalot\PdfParser\Parser([], $config);
                $pdf = $parser->parseFile($rutaPdf);
                $textoPdf = $pdf->getText();
                $yearEstadoCuenta = date('Y');

                // --- LÓGICA BBVA (INTACTA) ---
                if ($banco === 'BBVA') {
                    $tipoSplit = '/(\d{2}-\d{2}\n.*PEDMT\s*O:\s*([\w-]+)\n.*\n.*\n)/';
                    foreach (preg_split($tipoSplit, $textoPdf, -1, PREG_SPLIT_DELIM_CAPTURE) as $linea) {
                        $patron = "/PEDMT\s*O:\s*([\w-]+)/";
                        if (preg_match('/(\d{2}\/\d{2}\/(\d{4}))/', $linea, $matchYear)) {
                            $yearEstadoCuenta = $matchYear[2];
                        }
                        if (preg_match($patron, $linea, $matchPedimento)) {
                            preg_match('/\d{2}-\d{2}/', $linea, $matchFecha);
                            preg_match('/\$\s*([0-9.,]+)/', $linea, $matchCargo);
                            $fechaPedimentoEstadoCuenta = $matchFecha[0] . "-{$yearEstadoCuenta}";
                            $tablaDeDatos[] = [
                                'pedimento' => $matchPedimento[1],
                                'fecha_str' => \Carbon\Carbon::createFromFormat('d-m-Y', $fechaPedimentoEstadoCuenta)->format('Y-m-d'),
                                'cargo_str' => isset($matchCargo[1]) ? $matchCargo[1] : '0',
                            ];
                        }
                    }
                } 
                // --- LÓGICA SANTANDER ---
                else if ($banco === 'SANTANDER') {
                    // Dividimos el documento usando la estructura base de cada fila: Cuenta (11 digitos) + Espacio + Fecha (8 digitos)
                    $bloques = preg_split('/(?=\b\d{10,12}\s+\d{8}\b)/', $textoPdf);

                    foreach ($bloques as $bloque) {
                        // Juntamos los saltos de línea para leer la fila horizontalmente
                        $fila = preg_replace('/\s+/', ' ', trim($bloque));
                        if (empty($fila)) continue;

                        $montoFinal = null;
                        $pedimentoFinal = null;
                        $fechaFinal = null;

                        // 1. Extraer Fecha (8 dígitos)
                        if (preg_match('/\b(\d{8})\b/', $fila, $mf)) {
                            $fechaRaw = $mf[1];
                            try {
                                $fechaFinal = \Carbon\Carbon::createFromFormat('dmY', $fechaRaw)->format('Y-m-d');
                            } catch (\Exception $e) { continue; } // Ignora si la fecha es inválida
                        } else {
                            continue;
                        }

                        // 2. Extraer Montos (Cargo vs Abono vs Saldo)
                        if (preg_match_all('/(\d{1,3}(?:,?\d{3})*(?:\.\d{2}))/', $fila, $mms)) {
                            $montosParseados = [];
                            foreach ($mms[1] as $mRaw) {
                                $montosParseados[] = (float) str_replace(',', '', $mRaw);
                            }

                            // El parser extrae en orden: [Abono] [Cargo] [Saldo]
                            if (count($montosParseados) >= 3) {
                                if ($montosParseados[0] === 0.0) {
                                    // El Abono es 0.00 (posición 0), el Cargo es el siguiente (posición 1)
                                    $montoFinal = $montosParseados[1];
                                } else if ($montosParseados[1] === 0.0) {
                                    // Fallback: Por si lee en el orden visual correcto [Cargo, Abono, Saldo]
                                    $montoFinal = $montosParseados[0];
                                }
                            }
                        }

                        // 3. Extraer Pedimento (Referencia verde)
                        // Buscamos números de 7 a 11 dígitos
                        if (preg_match_all('/\b(\d{7,11})\b/', $fila, $mRefs)) {
                            foreach ($mRefs[1] as $ref) {
                                // Ignoramos si es la fecha o si es la cuenta bancaria inicial
                                if ($ref !== $fechaRaw && strpos($fila, $ref) !== 0) {
                                    // Cortamos a 7 dígitos exactos para borrar la hora pegada (ej. 503205410:38 -> 5032054)
                                    $pedimentoFinal = substr($ref, 0, 7);
                                    break;
                                }
                            }
                        }

                        // Solo agregamos la fila si es un cargo por impuestos válido
                        if ($pedimentoFinal && $montoFinal > 0) {
                            $tablaDeDatos[] = [
                                'pedimento' => $pedimentoFinal,
                                'fecha_str' => $fechaFinal,
                                'cargo_str' => (string)$montoFinal
                            ];
                        }
                    }
                }
                $operacionesLimpias = collect($tablaDeDatos);
            } else {
                // --- LÓGICA EXTERNO ---
                $import = new LecturaEstadoCuentaExcel($tarea);
                Excel::import($import, $rutaPdf);
                $operacionesLimpias = $import->getProcessedData();
            }

            // VALIDACIÓN
            if ($operacionesLimpias->isEmpty()) {
                $tarea->update(['status' => 'fallido', 'resultado' => 'No se encontraron pedimentos en el PDF.']);
                return ['code' => 1, 'message' => new \Exception("No se encontraron pedimentos.")];
            }

            // --- CÁLCULOS, BÚSQUEDA EN BD Y GUARDADO ---
            $fechasEncontradas = $operacionesLimpias->map(function($item) {
                return \Carbon\Carbon::parse($item['fecha_str']);
            });
            $fecha_fin_query = $fechasEncontradas->max()->addDays(15)->format('Y-m-d');
            
            $sucursalesDiccionario = [
                'NOG' => [1, 3711], 'TIJ' => [2, 3849], 'NL' => [3, 3711], 
                'MXL' => [4, 1038], 'ZLO' => [5, 3711], 'REY' => [11, 3577], 'VRZ' => [12, 1864]
            ];
            
            $sucInfo = isset($sucursalesDiccionario[$sucursal]) ? $sucursalesDiccionario[$sucursal] : [1, 3711];
            $numeroSucursal = $sucInfo[0];
            $patenteSucursal = $sucInfo[1];

            $numerosDePedimento = $operacionesLimpias->pluck('pedimento')->unique()->toArray();
            $mapaPedimentoAId = $this->construirMapaDePedimentos($numerosDePedimento, $patenteSucursal, $numeroSucursal);
            $idsPedDb = Arr::pluck($mapaPedimentoAId, 'id_pedimiento');

            $mapaImpo = Importacion::whereIn('id_pedimiento', $idsPedDb)->whereNull('parent')->get()->keyBy('id_pedimiento');
            $mapaExpo = Exportacion::whereIn('id_pedimiento', $idsPedDb)->whereNull('parent')->get()->keyBy('id_pedimiento');

            $idsImpoRaw = $mapaImpo->pluck('id_importacion')->toArray();
            $idsExpoRaw = $mapaExpo->pluck('id_exportacion')->toArray();

            $auditoriasSC = AuditoriaTotalSC::query()
                ->where(function ($q) use ($idsImpoRaw, $idsExpoRaw, $idsPedDb) {
                    $q->whereIn('operacion_id', array_filter($idsImpoRaw))
                    ->orWhereIn('operacion_id', array_filter($idsExpoRaw))
                    ->orWhereIn('pedimento_id', $idsPedDb);
                })->get();

            $mapaSCFinal = [];
            foreach ($auditoriasSC as $scItem) {
                if ($scItem->pedimento_id) $mapaSCFinal['p_' . $scItem->pedimento_id] = $scItem;
                if ($scItem->operacion_id) $mapaSCFinal['o_' . $scItem->operacion_id] = $scItem;
            }

            $datosParaAuditorias = $operacionesLimpias->map(function ($op) use ($mapaPedimentoAId, $mapaImpo, $mapaExpo, $mapaSCFinal, $tarea) {
                $pedLimpio = $op['pedimento'];
                $pedInfo = isset($mapaPedimentoAId[$pedLimpio]) ? $mapaPedimentoAId[$pedLimpio] : null;
                if (!$pedInfo) return null;

                $id_ped_db = $pedInfo['id_pedimiento'];
                $impo = $mapaImpo->get($id_ped_db);
                $expo = $mapaExpo->get($id_ped_db);

                $operacionId = $impo ? $impo->id_importacion : ($expo ? $expo->id_exportacion : null);
                $tipoOp = $impo ? Importacion::class : ($expo ? Exportacion::class : Pedimento::class);

                $sc = isset($mapaSCFinal['p_' . $id_ped_db]) ? $mapaSCFinal['p_' . $id_ped_db] : (isset($mapaSCFinal['o_' . $operacionId]) ? $mapaSCFinal['o_' . $operacionId] : null);
                
                $montoSCMXN = ($sc && isset($sc->desglose_conceptos['montos']['impuestos_mxn'])) ? $sc->desglose_conceptos['montos']['impuestos_mxn'] : -1.1;
                
                $montoImpuestoMXN = (float) filter_var(str_replace(',', '', $op['cargo_str']), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                
                $estado = $this->compararMontos($montoSCMXN, $montoImpuestoMXN, $tipoOp);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoImpuestoMXN, 2) : $montoImpuestoMXN;

                return [
                    'operacion_id' => $operacionId,
                    'pedimento_id' => $id_ped_db,
                    'operation_type' => $tipoOp,
                    'tipo_documento' => 'impuestos',
                    'concepto_llave' => 'principal',
                    'fecha_documento' => $op['fecha_str'],
                    'monto_total' => $montoImpuestoMXN,
                    'monto_total_mxn' => $montoImpuestoMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => 'MXN',
                    'estado' => $estado,
                    'ruta_pdf' => $tarea->ruta_estado_de_cuenta,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            })->filter()->all();

            // Guardar en tabla Auditoria
            if (!empty($datosParaAuditorias)) {
                Auditoria::upsert($datosParaAuditorias, 
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], 
                    ['fecha_documento', 'monto_total', 'monto_total_mxn', 'monto_diferencia_sc', 'estado', 'updated_at']
                );
            }

            // --- PASO CRUCIAL PARA EL SIGUIENTE COMANDO DE MAPEO ---
            $pedimentosProcesados = $operacionesLimpias->pluck('pedimento')->unique()->values()->all();
            
            if (count($pedimentosProcesados) > 0) {
                $tarea->update([
                    'pedimentos_procesados' => json_encode($pedimentosProcesados),
                    'fecha_documento' => $fecha_fin_query
                ]);
                Log::info("Se registraron " . count($pedimentosProcesados) . " pedimentos en la Tarea #{$tareaId}.");
            } else {
                throw new \Exception("La lista de pedimentos únicos resultó vacía.");
            }

            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            $tarea->update(['status' => 'fallido', 'resultado' => $e->getMessage()]);
            Log::error("Fallo tarea #{$tareaId}: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
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
            $mapaPedimentoAId = $this->construirMapaDePedimentos($numerosDePedimento, $patenteSucursal, $numeroSucursal);
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
                ->whereBetween('operaciones_importacion.created_at', [$fecha_inicio, $fecha_fin])
                ->where(['operaciones_importacion.patente' => $patenteSucursal, 'operaciones_importacion.sucursal' => $numeroSucursal])
                ->whereIn('operaciones_importacion.id_pedimiento', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                ->whereNull('parent')
                ->orderBy('operaciones_importacion.created_at', 'desc')
                ->groupBy('id_pedimiento')
                ->get()
                ->map(function ($operacion) use ($mapaPorId) {
                    $info = $mapaPorId->get($operacion->id_pedimiento);

                    return [
                        'pedimento' => $info['num_pedimiento'] ?? null, // Recuperamos num_pedimiento
                        'id_operacion' => $operacion->id_importacion,
                        'id_pedimento' => $operacion->id_pedimiento,
                    ];
                })
                ->keyBy('pedimento');

            Log::info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': " . $mapaPedimentoAImportacionId->count());
            $pu = memory_get_usage();
            // 2. MAPEADO EFICIENTE DE IDS
            // PROCESAR EXPORTACIONES
            $mapaPedimentoAExportacionId = Exportacion::query()
                ->selectRaw('id_pedimiento, MAX(id_exportacion) as id_exportacion')
                ->whereBetween('operaciones_exportacion.created_at', [$fecha_inicio, $fecha_fin])
                ->where(['operaciones_exportacion.patente' => $patenteSucursal, 'operaciones_exportacion.sucursal' => $numeroSucursal])
                ->whereIn('operaciones_exportacion.id_pedimiento', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                ->whereNull('parent')
                ->orderBy('operaciones_exportacion.created_at', 'desc')
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

            $pu = memory_get_usage();
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

                foreach ($keysPorEncontrar as $pedimentoBuscado) {
                    foreach ($busquedaGlobal as $registro) {
                        if (preg_match("/\b" . preg_quote($pedimentoBuscado, '/') . "\b/", $registro->num_pedimiento)) {
                            // Guardamos la info para el reporte, indicando que existe pero no calificó
                            $mapaNoEncontrados[$pedimentoBuscado] = [
                                'id_pedimiento' => $registro->id_pedimiento,
                                'num_pedimiento' => $registro->num_pedimiento,
                                'status_extra' => 'Encontrado en otra sucursal o sin operación'
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
            //$mapasPedimento - Contienen todos los pedimentos del estado de cuenta, encontrados en Importacion/Exportacion
            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];

            //$operacionesId - Contienen todas las operaciones_id de los pedimentos del estado de cuenta, encontrados en Importacion/Exportacion
            $operacionesId = array_merge(array_column($mapaPedimentoAImportacionId, 'id_operacion'), array_column($mapaPedimentoAExportacionId, 'id_operacion'));

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
            // 4. PREPARAR DATOS PARA GUARDAR EN 'auditorias_totales_sc'

            Log::info("Iniciando mapeo para Upsert.");

            $auditoriasParaGuardar = [];
            //$bar = $this->output->createProgressBar(count($indiceSC));
            //$bar->start();
            foreach ($indiceSC as $pedimento => $datosSC) {

                // Buscamos el id_importacion en nuestro mapa
                $pedimentoReal = $mapaPedimentoAId[$pedimento] ?? null;
                if (!$pedimentoReal) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    continue; // Si no hay operación, no podemos guardar la SC
                }
                $tipoOperacion = Importacion::class;

                //Se verifica si la operacion ID esta en Importacion
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoReal['num_pedimiento']] ?? null;

                if (!$operacionId) { //Si no, entonces busca en Exportacion
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoReal['num_pedimiento']] ?? null;
                    $tipoOperacion = Exportacion::class;
                }

                if (!$operacionId) { //Si no esta ni en Importacion o en Exportacion, que lo guarde por pedimento_id
                    $tipoOperacion = "N/A";
                }

                if (!$operacionId) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    //$bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                // Preparamos el desglose para guardarlo como JSON
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
                        'operacion_id' => isset($operacionId['id_operacion']) ? $operacionId['id_operacion'] : null, // ¡La vinculación auxiliar correcta!
                        'pedimento_id' => isset($operacionId['id_pedimento']) ? $operacionId['id_pedimento'] : $pedimentoReal['id_pedimiento'], // ¡La vinculación correcta! REVISAR AQUI EN CASO DE QUE LAS SCs NO SE REFLEJEN BIEN
                        'operation_type' => $tipoOperacion,
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

                Log::info("Actualizando mapeo...");

                // Aca lo que hacemos es, crear otro json en donde vendran todos los SC encontrados de los pedimentos del estado de cuenta
                $auditoriasSC = AuditoriaTotalSC::query()
                    ->whereIn('operacion_id', $operacionesId)
                    ->orWhereIn('pedimento_id', array_keys($mapaPedimentoAId))
                    ->get()
                    ->keyBy('operacion_id');

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


    //--- METODO AUDITAR E IMPORTAR FLETES A AUDITORIAS
    // Se encarga de leer el archivo de TXT de los Fletes, obteniendo los montos y datos generales necesarios para la auditoria
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
                Log::info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return ['code' => 0, 'message' => 'completado'];
            }
            Log::info("Procesando Facturas de Fletes para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            //$mapasPedimento - Contienen todos los pedimentos del estado de cuenta, encontrados en Importacion/Exportacion
            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];

            //$mapaPedimentoAId - Este arreglo contiene los pedimentos limpios, sucios, y su Id correspondiente
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];

            //$indicesOperaciones - Combina el mapeado de archivos/urls de importacion y exportacion
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            //--- YA UNA VEZ TENIENDO TODO A LA MANO
            // 3. Construimos el índice de Fletes desde los archivos de importacion y exportacion
            $indiceFletes = $this->construirIndiceOperacionesFletes($indicesOperaciones);

            //Obtenemos el resultado del query de todos los SC de los pedimentos del estado de cuenta.
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            // Aquí es donde extraemos el tipo de cambio del JSON.
            $indiceSC = [];

            foreach ($auditoriasSC as $auditoria) {

                // Laravel ya ha convertido el `desglose_conceptos` en un array gracias a la propiedad `$casts`.
                $desglose = $auditoria['desglose_conceptos'];
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                // Creamos la entrada en nuestro mapa.
                $indiceSC[key($arrPedimento)] =
                    [
                        'monto_flete_sc' => (float) $desglose['montos']['flete'],
                        'monto_flete_sc_mxn' => (float) $desglose['montos']['flete_mxn'],
                        'moneda' => $desglose['moneda'],
                        // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                        // para asignar un valor por defecto (ej. 1) si no se encuentra.
                        'tipo_cambio' => (float) $desglose['tipo_cambio'] ?? 1.0,
                    ];
            }

            Log::info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");

            Log::info("Iniciando mapeo para Upsert.");

            //$bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            //$bar->start();
            $fletesParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                // Obtemenos la operacionId por medio del pedimento sucio
                //Se verifica si la operacion ID esta en Importacion
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                $tipoOperacion = Importacion::class;

                if (!$operacionId) { //Si no, entonces busca en Exportacion
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                    $tipoOperacion = Exportacion::class;
                }

                if (!$operacionId) { //Si no esta ni en Importacion o en Exportacion, que lo guarde por pedimento_id
                    $tipoOperacion = Pedimento::class;
                }

                if (!$operacionId) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimentoLimpio}' porque no se encontró una operación de importación asociada.");
                    //$bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                // Buscamos en nuestros índices en memoria (búsqueda instantánea)
                $datosFlete = $indiceFletes[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;

                if (!$datosFlete/* || $datosSC['monto_esperado_flete'] == 0 */) {
                    //$bar->advance();
                    continue; // Si no tenemos todos los datos, saltamos a la siguiente operación.
                }
                if (!$datosSC) {
                    //Aqui es cuando hay LLC pero no existe SC para esta factura.\\
                    $datosSC =
                        [
                            'monto_flete_sc' => -1,
                            'monto_flete_sc_mxn' => -1,
                            'tipo_cambio' => -1,
                            'moneda' => 'N/A',
                        ];
                }
                // --- FASE 3: Procesar los archivos encontrados ---

                //En un futuro donde ya tengas implementadas las sucursals y series, cambia la linea de abajo, tanto de este comando
                //como el de los demas que sigan esta logica, por esta nueva:
                // $rutaXmlFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . $operacion->sucursal->serie . $datosFleteTxt['folio'] . '.xml';

                //Aqui combino el resultado del txt (datos generales) y el resultado del xml (Total de la factura)
                $datosFlete = array_merge($datosFlete, $this->parsearXmlFlete($datosFlete['path_xml_tr']) ?? [-1, 'N/A']);



                $montoFleteMXN = (($datosFlete['moneda'] == "USD" && $datosFlete['total'] != -1) && $datosSC['tipo_cambio'] != -1) ? round($datosFlete['total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosFlete['total'];
                $montoSCMXN = $datosSC['monto_flete_sc_mxn'];
                $estado = $this->compararMontos_Fletes($montoSCMXN, $montoFleteMXN);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoFleteMXN, 2) : $montoFleteMXN;

                // 1. Limpieza inicial: Quitamos espacios y aseguramos que no sea null
                $fechaRaw = isset($datosFlete['fecha']) ? trim($datosFlete['fecha']) : null;
                $fechaFormateada = null;

                // Si la fecha viene como "03/11/2025,03/11/2025", la cortamos.
                if ($fechaRaw && strpos($fechaRaw, ',') !== false) {
                    $partes = explode(',', $fechaRaw);
                    $fechaRaw = trim($partes[0]); // Nos quedamos solo con la primera: "03/11/2025"
                }

                if ($fechaRaw) {
                    try {
                        // 2. Carbon intenta adivinar la fecha de entrada
                        // 3. Y forzamos la SALIDA al formato: Año-Mes-Día (Y-m-d) para la base de datos
                        $fechaFormateada = \Carbon\Carbon::parse($fechaRaw)->format('Y-m-d');
                    } catch (\Throwable $e) {
                        // Si falla la inteligencia de Carbon, intentamos un último rescate manual
                        try {
                            // Convertimos guiones o puntos en diagonales
                            $fechaLimpia = str_replace(['-', '.'], '/', $fechaRaw);
                            $fechaFormateada = \Carbon\Carbon::createFromFormat('d/m/Y', $fechaLimpia)->format('Y-m-d');
                        } catch (\Throwable $e2) {
                            Log::error("Fletes: Fecha ilegible '{$fechaRaw}' en pedimento {$pedimentoLimpio}.");
                            $fechaFormateada = null;
                        }
                    }
                }
                // Añadimos el resultado al array para el upsert masivo
                $fletesParaGuardar[] =
                    [
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

                //$bar->advance();
            }
            //$bar->finish();

            // --- FASE 5: Guardado Masivo en Base de Datos ---
            if (!empty($fletesParaGuardar)) {
                Log::info("\nPaso 3/3: Guardando/Actualizando " . count($fletesParaGuardar) . " registros de fletes...");
                Auditoria::upsert(
                    $fletesParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], // La llave única correcta
                    [
                        'fecha_documento',
                        'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                        'monto_total_mxn',
                        'monto_diferencia_sc',
                        'moneda_documento',
                        'estado',
                        'ruta_xml',
                        'ruta_txt',
                        'ruta_pdf',
                        'updated_at'
                    ]
                );

                Log::info("¡Guardado con éxito!");
            }

            Log::info("\nAuditoría de Fletes finalizada.");
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]
            );
            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Falló la tarea #{$tarea->id}: " . $e->getMessage())];
        }
    }


    //--- METODO AUDITAR E IMPORTAR LLCS A AUDITORIAS
    // Se encarga de leer el archivo de TXT de las LLCs, obteniendo los montos y datos generales necesarios para la auditoria
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
            Log::info("Procesando Facturas de LLC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

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

                // Laravel ya ha convertido el `desglose_conceptos` en un array gracias a la propiedad `$casts`.
                $desglose = $auditoria['desglose_conceptos'];
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                // Creamos la entrada en nuestro mapa.
                $indiceSC[key($arrPedimento)] =
                    [
                        'monto_llc_sc' => (float) $desglose['montos']['llc'],
                        'monto_llc_sc_mxn' => (float) $desglose['montos']['llc_mxn'],
                        'moneda' => $desglose['moneda'],
                        // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                        // para asignar un valor por defecto (ej. 1) si no se encuentra.
                        'tipo_cambio' => (float) $desglose['tipo_cambio'] ?? 1.0,
                    ];
            }

            Log::info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");

            Log::info("Iniciando mapeo para Upsert.");
            //--------------------------------------------------------------------------------------------------------
            //========================================================================================================
            //--------------------------------------------------------------------------------------------------------

            //$bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            //$bar->start();
            $llcsParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                // Obtemenos la operacionId por medio del pedimento sucio
                // Se verifica si la operacion ID esta en Importacion
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                $tipoOperacion = Importacion::class;

                if (!$operacionId) { // Si no, entonces busca en Exportacion
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                    $tipoOperacion = Exportacion::class;
                }

                if (!$operacionId) { // Si no esta ni en Importacion o en Exportacion, que lo guarde por pedimento_id
                    $tipoOperacion = Pedimento::class;
                }

                if (!$operacionId) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimentoLimpio}' porque no se encontró una operación de importación asociada.");
                    //$bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                // Buscamos en nuestros índices en memoria (búsqueda instantánea)
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;
                $datosLlc = $indiceLLC[$pedimentoLimpio] ?? null;

                if (!$datosLlc) {
                    //$bar->advance();
                    continue;
                } elseif (!$datosSC && $datosLlc) {
                    // Aqui es cuando hay LLC pero no existe SC para esta factura.\\
                    $datosSC =
                        [
                            'monto_llc_sc' => -1,
                            'monto_llc_sc_mxn' => -1,
                            'tipo_cambio' => -1,
                            'moneda' => 'N/A',
                        ];
                }

                $montoLLCMXN = $datosSC['monto_llc_sc'] != -1 ? round($datosLlc['monto_total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : -1;
                $montoSCMXN = $datosSC['monto_llc_sc_mxn'];
                // --- FASE 4: Comparar y Preparar Datos ---
                $estado = $this->compararMontos_LLC($montoSCMXN, $montoLLCMXN);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoLLCMXN, 2) : $montoLLCMXN;
                $llcsParaGuardar[] =
                    [
                        'operacion_id' => $operacionId['id_operacion'],
                        'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                        'operation_type' => $tipoOperacion,
                        'tipo_documento' => 'llc',
                        'concepto_llave' => 'principal',
                        'folio' => $datosLlc['folio'],
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

                //$bar->advance();
            }
            //$bar->finish();

            // --- FASE 5: Guardado Masivo ---
            if (!empty($llcsParaGuardar)) {
                Log::info("\nGuardando/Actualizando " . count($llcsParaGuardar) . " registros de LLC...");
                Auditoria::upsert(
                    $llcsParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],  // Columna única para identificar si debe actualizar o insertar
                    [
                        'fecha_documento',
                        'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                        'monto_total_mxn',
                        'monto_diferencia_sc',
                        'moneda_documento',
                        'estado',
                        'ruta_txt',
                        'ruta_pdf',
                        'updated_at'
                    ]
                );
                Log::info("¡Guardado con éxito!");
            }

            Log::info("\nAuditoría de LLC finalizada.");
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]
            );
            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Falló la tarea #{$tarea->id}: " . $e->getMessage())];
        }
    }


    //--- METODO AUDITAR E IMPORTAR PAGOS DE DERECHO A AUDITORIAS
    // Se encarga de leer el archivo de TXT de los Pagos de derecho, obteniendo los montos y datos generales necesarios para la auditoria
    public function auditarFacturasDePagosDeDerecho(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Pagos derecho: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return ['code' => 1, 'message' => new \Exception("Pagos derecho: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.")];
        }
        Log::info('Iniciando la auditoría de Pagos de Derecho...');
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
                Log::info("Pagos de derecho: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return ['code' => 0, 'message' => 'completado'];
            }
            Log::info("Procesando Facturas de Pagos de derecho para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            //$mapasPedimento - Contienen todos los pedimentos del estado de cuenta, encontrados en Importacion/Exportacion
            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];

            //$mapaPedimentoAId - Este arreglo contiene los pedimentos limpios, sucios, y su Id correspondiente
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];

            //$indicesOperaciones - Combina el mapeado de archivos/urls de importacion y exportacion
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            //--- YA UNA VEZ TENIENDO TODO A LA MANO
            // 3. Construimos el índice de Pagos de derecho desde los archivos de importacion y exportacion
            $indicePagosDerecho = $this->construirIndiceOperacionesPagosDerecho($indicesOperaciones);

            Log::info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");

            Log::info("Iniciando mapeo para Upsert.");
            //----------------------------------------------------
            //$bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            //$bar->start();
            $pagosParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                // Obtemenos la operacionId por medio del pedimento sucio
                // Se verifica si la operacion ID esta en Importacion
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                $tipoOperacion = Importacion::class;

                if (!$operacionId) { // Si no, entonces busca en Exportacion
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                    $tipoOperacion = Exportacion::class;
                }

                if (!$operacionId) { // Si no esta ni en Importacion o en Exportacion, que lo guarde por pedimento_id
                    $tipoOperacion = Pedimento::class;
                }

                if (!$operacionId) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimentoLimpio}' porque no se encontró una operación de importación asociada.");
                    //$bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                $rutasPdfs = $indicePagosDerecho[$pedimentoSucioYId['num_pedimiento']] ?? null;
                if (!$rutasPdfs) {
                    //$bar->advance();
                    continue;
                }
                foreach ($rutasPdfs as $rutaPdf) {

                    //Parseamos cada PDF encontrado.
                    $datosPago = $this->parsearPdfPagoDeDerecho($rutaPdf) ?? null;

                    if ($datosPago) {
                        if (isset($datosPago['ruta_alternativa'])) {
                            $rutaPdf = $datosPago['ruta_alternativa'];
                        }

                        $diferenciaSc = 0; //Sujeto a cambios
                        // 4. Si obtuvimos datos, los acumulamos para el guardado masivo.
                        $pagosParaGuardar[] =
                            [
                                'operacion_id' => $operacionId['id_operacion'],
                                'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                                'operation_type' => $tipoOperacion,
                                'tipo_documento' => 'pago_derecho',
                                'concepto_llave' => $datosPago['llave_pago'],
                                'fecha_documento' => $datosPago['fecha_pago'],
                                'monto_total' => $datosPago['monto_total'],
                                'monto_total_mxn' => $datosPago['monto_total'],
                                'monto_diferencia_sc' => $diferenciaSc,
                                'moneda_documento' => 'MXN',
                                'estado' => $datosPago['tipo'],
                                'llave_pago_pdd' => $datosPago['llave_pago'],
                                'num_operacion_pdd' => $datosPago['numero_operacion'],
                                'ruta_pdf' => $rutaPdf,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                    }
                }
                //$bar->advance();
            }

            //$bar->finish();

            // 5. Guardado Masivo con upsert
            if (!empty($pagosParaGuardar)) {
                Log::info("\nGuardando/Actualizando " . count($pagosParaGuardar) . " registros de Pagos de Derecho...");
                // Usamos la llave de pago como identificador único para el upsert.

                Auditoria::upsert(
                    $pagosParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], // Columna única para identificar si debe actualizar o insertar
                    [
                        'fecha_documento',
                        'monto_total', // Asegúrate que estos nombres coincidan con tu migración
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
                Log::info("¡Guardado con éxito!");
            }

            Log::info("\nAuditoría de Pagos de Derecho finalizada.");
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]
            );
            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Falló la tarea #{$tarea->id}: " . $e->getMessage())];
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
            Log::warning("Exportación: No se encontró la tarea #{$tareaId} o no está en estado procesando.");
            return ['code' => 1, 'message' => new \Exception("Tarea no válida o no encontrada.")];
        }

        $banco = strtoupper($tarea->banco);
        $sucursal = $tarea->sucursal;

        Log::info("Iniciando Exportación a Excel para Tarea #{$tarea->id} | Sucursal: {$sucursal} | Tipo: " . ($esReporteDeFacturasPendientes ? 'PENDIENTES' : 'FACTURADOS'));

        try {
            // 2. Obtenemos los pedimentos que el sistema logró identificar del Estado de Cuenta
            $pedimentosProcesadosJson = $tarea->pedimentos_procesados;
            $listaPedimentosExactos = $pedimentosProcesadosJson ? json_decode($pedimentosProcesadosJson, true) : [];

            if (empty($listaPedimentosExactos)) {
                Log::info("Exportación: No hay pedimentos procesados para la tarea #{$tareaId}.");
                return ['code' => 0, 'message' => 'completado'];
            }

            // 3. Construcción de la consulta base con sus relaciones
            $query = Pedimento::query()
                ->whereIn('num_pedimiento', $listaPedimentosExactos)
                ->with([
                    'importacion' => function ($q) {
                        $q->with(['auditoriasRecientes.pedimento', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                    },
                    'exportacion' => function ($q) {
                        $q->with(['auditoriasRecientes.pedimento', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                    }
                ]);

            // 4. LÓGICA DE SEGREGACIÓN (Aquí es donde evitamos que se mezclen)
            if ($esReporteDeFacturasPendientes === 'true') {
                /**
                 * CASO: REPORTE DE PENDIENTES
                 * Filtramos para traer SOLO los que NO tienen SC registrada.
                 */
                $query->where(function ($q) {
                    // No debe tener registro en la tabla de SC Totales
                    $q->whereDoesntHave('auditoriasTotalSC')
                        // Y su estado en la auditoría individual debe ser "Sin SC!"
                        ->whereHas('auditoriasRecientes', function ($auditQuery) {
                            $auditQuery->where('estado', 'Sin SC!');
                        });
                });
            } else {
                /**
                 * CASO: REPORTE DE FACTURADOS (NORMAL)
                 * Filtramos para traer SOLO los que SÍ tienen SC registrada.
                 */
                $query->whereHas('auditoriasTotalSC');
            }

            // Ejecutamos la consulta
            $operacionesParaExportar = $query->get();

            // 5. Configuración de nombres y rutas de archivo
            $fecha = now()->format('dmY');
            $nombreArchivo = ($esReporteDeFacturasPendientes === 'true')
                ? "RDI_{$sucursal}_{$fecha}.xlsx"
                : "RDI_{$sucursal}_{$fecha}.xlsx";

            $nombreUnico = Str::random(40) . '.xlsx';
            $rutaDeAlmacenamiento = "/reportes/{$nombreUnico}";

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

            // 7. Actualizamos la tarea con la ruta del reporte correspondiente
            if ($esReporteDeFacturasPendientes === 'true') {
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
            // En caso de error, registramos el fallo en la tarea y el log
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
            $destinatario = "carlos.perez@intactics.com";
            // Buscamos la tarea en la base de datos. Si no la encuentra, falla.
            $tarea = AuditoriaTareas::findOrFail($tareaId);

            // Usamos la fachada Mail de Laravel para enviar el correo.
            // Pasamos la instancia de la tarea a nuestro Mailable.
            Mail::to($destinatario)->send(new EnviarReportesAuditoriaMail($tarea));

            // Devolvemos una respuesta de éxito.
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            // Si algo sale mal (ej. el correo no es válido, el archivo no existe, etc.)
            // registramos el error y devolvemos una respuesta de error.
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
    private function construirIndiceFacturasParaMapeo(Collection $pedimentosOperacion, string $sucursal, string $tipoOperacion): array
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
            ];
        //$bar = $this->output->createProgressBar($pedimentosOperacion->count());
        //$bar->start();

        foreach ($pedimentosOperacion as $pedimento => $operacionID) {
            try {

                // --- 1. OBTENCIÓN DE DATOS ---
                // Usamos el cliente HTTP de Laravel que es más seguro y maneja errores.
                //Urls
                // ARREGLO TEMPORAL PARA SSL: Usamos withoutVerifying() para saltar la verificación del certificado SSL.
                // ¡¡¡IMPORTANTE!!! Esto es solo para desarrollo local. Eliminar en producción.
                //$url_txt = Http::withoutVerifying()->get("https://sistema.intactics.com/v3/operaciones/exportaciones/{$operacionID}/get-files-txt-momentaneo");
                $url_pdf = Http::withoutVerifying()->get("https://sistema.intactics.com/v3/operaciones/{$tipoOperacion}/{$operacionID['id_operacion']}/get-files-momentaneo");

                if (!$url_pdf->successful()) {
                    // Si la API falla para este ID, lo saltamos y continuamos con el siguiente.

                    Log::warning("No se pudieron obtener los archivos para la importación ID: {$operacionID['id_operacion']}");
                    //$bar->advance();
                    continue;
                }

                //Resultados JSON del get
                //$archivos_txt = json_decode($url_txt);
                $archivos_pdf = collect($url_pdf->json());
                unset($url_pdf);
                gc_collect_cycles();
                // Inicializamos la entrada para el pedimento actual
                $indiceFacturas[$pedimento] = [
                    'tipo_operacion' => $tipoOperacion,
                    'operacion_id' => $operacionID['id_operacion'],
                    'facturas' => [], // Aquí guardaremos las facturas encontradas
                ];

                // --- 3. FILTRADO Y PROCESAMIENTO DE FACTURAS (Nueva Lógica) ---
                $agrupadorTemp = [];

                $archivosFacturas = $archivos_pdf->whereIn('pivot.type', array_keys($mapeoFacturas));

                foreach ($archivosFacturas as $archivo) {
                    $tipoJson = $archivo['pivot']['type'];
                    $url = $archivo['url']['normal'];
                    $fechaCreacion = $archivo['created_at'];
                    $fechaActualizacion = $archivo['updated_at'];
                    // Usamos el nombre del archivo sin extensión para agrupar PDF y XML
                    $nombreBase = pathinfo($archivo['name'], PATHINFO_FILENAME);
                    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

                    // Si aún no existe una entrada para este nombre base, la creamos.
                    if (!isset($agrupadorTemp[$nombreBase])) {
                        $agrupadorTemp[$nombreBase] = [
                            'creation_date' => $fechaCreacion,
                            'update_date' => $fechaActualizacion,
                            'tipo_documento' => $mapeoFacturas[$tipoJson], // Usamos el nombre amigable
                            'ruta_pdf' => null,
                            'ruta_xml' => null,
                            'ruta_txt' => null,
                        ];
                    }

                    // Asignamos la URL a la clave correcta según su extensión.
                    if ($extension === 'pdf') {
                        $agrupadorTemp[$nombreBase]['ruta_pdf'] = $url;
                    } elseif ($extension === 'xml') {
                        $agrupadorTemp[$nombreBase]['ruta_xml'] = $url;
                    }

                    // Obtenemos las rutas txt y su contenido
                    if ($tipoJson != 'PAGOS-DE-DERECHOS') {
                        switch ($tipoJson) {
                            case 'HONORARIOS-SC':
                            case 'TransporTactics':
                                $agrupadorTemp[$nombreBase]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/{$nombreBase}.txt";
                                break;

                            case 'HONORARIOS-LLC':
                                $agrupadorTemp[$nombreBase]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/llc-{$nombreBase}.txt";
                                break;
                        }
                    }
                }

                // Asignamos las facturas agrupadas y limpias al resultado final.
                // array_values() reinicia los índices del array para que sea una lista limpia.
                //NUEVA FORMA
                $indiceFacturas[$pedimento]['facturas'] = $agrupadorTemp;
                //VIEJA FORMA
                //$indiceFacturas[$pedimento]['facturas'] = array_values($agrupadorTemp);
                //$bar->advance();

                // --- BORRAR ESTO EN CASO DE QUE TE INTERESE OBTENER TODAS LAS SC
                // Este foreach de abajo tiene el proposito de arreglar el Bug que se tiene en ZLO en donde existen 2 SCs
                // y aveces termina agarrando la SC incorrecta. Y lo que se hace aca es buscar la que tenga el numero de folio menor
                // ya que por experiencia, esas suelen ser las SC que le corresponden al cliente

                // Suponiendo que tu arreglo se llama $datos
                // Usamos un bucle foreach con una referencia (&) para poder modificar el arreglo original directamente.
                foreach ($indiceFacturas as &$operacion) {
                    // 1. Convertimos el sub-arreglo de 'facturas' a una colección para trabajar más fácil.
                    $facturas = collect($operacion['facturas']);

                    // 2. Separamos las facturas: las que son 'sc' y las que no.
                    $scInvoices = $facturas->filter(function ($factura) {
                        return $factura['tipo_documento'] === 'sc';
                    });

                    // 3. Si hay más de una factura 'sc', procedemos a encontrar la menor.
                    //    Si hay solo una o ninguna, no hacemos nada.
                    if ($scInvoices->count() > 1) {
                        // Encontramos la factura 'sc' con el número de serie más bajo en su clave.
                        // `sortBy` ordena la colección. Usamos una función para extraer el número
                        // de la clave (la llave del arreglo, ej. 'ZLO3205') y lo convierte a entero
                        // para una comparación numérica correcta. `first()` nos da el primer elemento
                        // después de ordenar, que será el más bajo.
                        $lowestScInvoice = $scInvoices->sortBy(function ($factura, $key) {
                            // Extraemos todos los dígitos de la clave y tomamos el último número encontrado.
                            preg_match_all('/\d+/', $key, $matches);
                            return (int) end($matches[0]);
                        })->first();

                        // Obtenemos la llave de la factura que queremos conservar.
                        $keyToKeep = $scInvoices->search($lowestScInvoice);

                        // 4. Filtramos las facturas que NO son 'sc'
                        $otherInvoices = $facturas->filter(function ($factura) {
                            return $factura['tipo_documento'] !== 'sc';
                        });

                        // 5. Reconstruimos el arreglo de facturas:
                        //    Juntamos las que no eran 'sc' con la única 'sc' que decidimos conservar.
                        $operacion['facturas'] = $otherInvoices
                            ->put($keyToKeep, $lowestScInvoice) // Añadimos la factura 'sc' correcta.
                            ->all(); // Convertimos la colección de vuelta a un array.
                    }
                }

                // Es una buena práctica eliminar la referencia al final del bucle.
                unset($operacion);
                // --- HASTA ACA TERMINA EL FOREACH
            } catch (\Throwable $e) {
                Log::error("Ocurrió un error procesando la operacion ID ({$tipoOperacion}) {$operacionID['id_operacion']}: " . $e->getMessage());
                // Aseguramos que haya una entrada para este pedimento aunque falle, para evitar errores posteriores.
                if (!isset($indiceFacturas[$pedimento])) {
                    $indiceFacturas[$pedimento] = ['error' => $e->getMessage()];
                }
            }
        }
        unset($archivosFacturas);
        unset($archivo);
        unset($pedimentosOperacion);
        unset($pedimento);
        unset($operacionID);
        gc_collect_cycles();
        //$bar->finish();
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
                try {   //Cuando la URL esta mal construida, lo que se hace es buscar por medio del get el txt
                    $contenido = file_get_contents($facturaSC['ruta_txt']);
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

                    $contenido = $urls ? file_get_contents('https://sistema.intactics.com' . $urls[0]['path'], false, stream_context_create($arrContextOptions)) : null;
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
                if (preg_match('/\[encOBSERVACION\][^\d]*(?:\d{1,5}-)*([4-7]\d{6})/i', $contenido, $matchesPedimento)) {

                    $pedimento = trim($matchesPedimento[1]);
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

                    $indice[$pedimento] =
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

                    $aux = $this->extraerMultiplesConceptos($contenido);
                    if ($aux['monto_maniobras_2'] > $indice[$pedimento]['monto_maniobras']) {
                        $indice[$pedimento]['monto_maniobras'] = $aux['monto_maniobras_2'];
                    }

                    unset($aux['monto_maniobras_2']);
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
     * Lee todos los TXT de LLCs recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceOperacionesLLCs(array $indicesOperacion): array
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
                $facturaLLC = $coleccionFacturas->first(function ($factura) {
                    // La condición es la misma que ya tenías.
                    return $factura['tipo_documento'] === 'llc' &&
                        isset($factura['ruta_pdf']) && isset($factura['ruta_txt']);
                });

                if (!$facturaLLC) {
                    //$bar->advance();
                    continue;
                }
                try {
                    // Cuando la URL esta mal construida, lo que se hace es buscar por medio del get el txt
                    //$arrContextOptions resuelve el siguiente error:
                    //'file_get_contents(): SSL operation failed with code 1. OpenSSL Error messages:
                    //error:1416F086:SSL routines:tls_process_server_certificate:certificate verify failed'
                    $arrContextOptions = [
                        "ssl" => [
                            "verify_peer" => false,
                            "verify_peer_name" => false,
                        ],
                    ];
                    $contenido = file_get_contents($facturaLLC['ruta_txt'], false, stream_context_create($arrContextOptions));
                } catch (\Throwable $th) {
                    // En las LLC no se verificara si existe el archivo dentro del GET del files-txt-momentaneo
                    // debido a que no existe o no se presenta dentro del JSON. Por lo tanto, si no existe el url construido
                    // con anterioridad, simplemente continuara con el siguiente archivo.

                    $contenido = null;
                }

                if (!$contenido) {
                    //$bar->advance();
                    continue;
                }

                // Refinamiento: Regex más preciso para el pedimento en la observación.'/\[encOBSERVACION\][^\d]*(?:\d{1,5}-)*([45]\d{6})/i'
                if (preg_match('/\[encPdfRemarksNote\d+\][^\d]*(?:Patente:\s*\d+\s*,\s*Pedimento:\s*)?(?:\d{1,5}-)*([4-7]\d{6})/i', $contenido, $matchPedimento)) {
                    $pedimento = trim($matchPedimento[1]);

                    // Leemos todas las líneas del archivo en un array
                    $lineas = file($facturaLLC['ruta_txt'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES, stream_context_create($arrContextOptions));

                    $datos =
                        [
                            'folio' => null,
                            'fecha' => null,
                            'monto_total' => 0.0,
                            'ruta_txt' => $facturaLLC['ruta_txt'],
                            'ruta_pdf' => $facturaLLC['ruta_pdf'],

                        ];

                    $montoTotal = 0.0;

                    // Iteramos sobre cada línea del archivo
                    foreach ($lineas as $linea) {
                        // Buscamos el folio
                        if (strpos($linea, '[encRefNumber]') !== false) {
                            $datos['folio'] = trim(explode(']', $linea)[1]);
                        }
                        // Buscamos la fecha
                        if (strpos($linea, '[encTxnDate]') !== false) {
                            // Usamos Carbon para parsear y formatear la fecha de forma segura
                            $datos['fecha'] = \Carbon\Carbon::parse(trim(explode(']', $linea)[1]))->format('Y-m-d');
                        }
                        // Buscamos y SUMAMOS cada monto
                        if (strpos($linea, '[movAmount]') !== false) {
                            $monto = (float) trim(explode(']', $linea)[1]);
                            $montoTotal += $monto;
                        }
                    }

                    $datos['monto_total'] = $montoTotal;
                    // Solo devolvemos los datos si encontramos un folio y un monto.

                    $indice[$pedimento] = $datos;
                }
                //$bar->advance();
            }
        } catch (\Throwable $e) {
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }

        //$bar->finish();
        return $indice;
    }


    /**
     * Escanea el directorio de Pagos de Derecho una vez y crea un mapa
     * de [pedimento => [lista_de_rutas_pdf]].
     */
    private function construirIndiceOperacionesPagosDerecho(array $indicesOperacion): array
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
                $facturaPDD = $coleccionFacturas->filter(function ($factura) {
                    // La condición es la misma que ya tenías.
                    return $factura['tipo_documento'] === 'pago_derecho' &&
                        isset($factura['ruta_pdf']);
                })->toArray();

                if (!$facturaPDD) {
                    //$bar->advance();
                    continue;
                }

                // Extraemos el pedimento del nombre del archivo.
                // Este Regex busca 7 dígitos seguidos de un posible guion.
                foreach ($facturaPDD as $factura => $datos) {
                    $indice[$pedimento][] = $datos['ruta_pdf'];
                }

                //$bar->advance();
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo el índice de Pagos de Derecho: " . $e->getMessage());
        }
        //$bar->finish();
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
    private function parsearXmlFlete(string $rutaXml): ?array
    {
        gc_collect_cycles();

        try {
            //$arrContextOptions resuelve el siguiente error:
            //'file_get_contents(): SSL operation failed with code 1. OpenSSL Error messages:
            //error:1416F086:SSL routines:tls_process_server_certificate:certificate verify failed'
            $arrContextOptions =
                [
                    "ssl" =>
                    [
                        "verify_peer" => false,
                        "verify_peer_name" => false,
                    ],
                ];
            // Usamos SimpleXMLElement, que es nativo de PHP.
            $xml = new \SimpleXMLElement(file_get_contents($rutaXml, false, stream_context_create($arrContextOptions)));

            // Devolvemos un array con los datos que nos interesan.
            return
                [
                    'total' => (float) $xml['Total'],
                    'moneda' => (string) $xml['Moneda'],
                ];
        } catch (\Throwable $e) {
            Log::error("Error al parsear el XML {$rutaXml}: " . $e->getMessage());
            return
                [
                    'total' => -1,
                    'moneda' => 'N/A',
                ];
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
    private function construirMapaDePedimentos(array $pedimentosLimpios, string $patenteSucursal, $numeroSucursal): array
    {
        gc_collect_cycles();
        if (empty($pedimentosLimpios)) return [];

        $regexPattern = implode('|', array_map(function ($p) {
            return "[[:<:]]{$p}[[:>:]]";
        }, array_unique($pedimentosLimpios)));

        $posiblesCoincidencias = Pedimento::query()
            ->whereRaw("num_pedimiento REGEXP ?", [$regexPattern])
            ->where(function ($q) use ($patenteSucursal, $numeroSucursal) {
                $q->whereHas('importacion', function ($im) use ($patenteSucursal, $numeroSucursal) {
                    $im->where('patente', $patenteSucursal)->where('sucursal', $numeroSucursal);
                })
                    ->orWhereHas('exportacion', function ($ex) use ($patenteSucursal, $numeroSucursal) {
                        $ex->where('patente', $patenteSucursal)->where('sucursal', $numeroSucursal);
                    });
            })
            ->with(['importacion', 'exportacion'])
            ->get();

        $mapaFinal = [];
        foreach ($pedimentosLimpios as $pedimentoBuscado) {
            foreach ($posiblesCoincidencias as $registro) {
                if (preg_match("/\b" . preg_quote($pedimentoBuscado, '/') . "\b/", $registro->num_pedimiento)) {

                    $esImpo = !is_null($registro->importacion);
                    $operacion = $esImpo ? $registro->importacion : $registro->exportacion;
                    $esRecti = ($operacion && !is_null($operacion->parent));

                    if (
                        !isset($mapaFinal[$pedimentoBuscado]) ||
                        (!$mapaFinal[$pedimentoBuscado]['es_recti'] && $esRecti) ||
                        ($registro->id_pedimiento > $mapaFinal[$pedimentoBuscado]['id_pedimiento'])
                    ) {

                        $mapaFinal[$pedimentoBuscado] = [
                            'id_pedimiento' => $registro->id_pedimiento,
                            'id_operacion'  => $esImpo ? $operacion->id_importacion : $operacion->id_exportacion,
                            'num_pedimiento' => $registro->num_pedimiento,
                            'tipo'           => $esImpo ? 'Importacion' : 'Exportacion', // <--- SOLUCIÓN AL ERROR 'tipo'
                            'es_recti'       => $esRecti
                        ];
                    }
                }
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

            if ($datos['llave_pago'])
                return $datos;
            else
                return null;
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
     */
    private function construirIndiceOperacionesMuestras(array $indicesOperacion): array
    {
        gc_collect_cycles();
        try {
            $indice = [];

            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) continue;

                $coleccionFacturas = collect($datos['facturas']);
                $facturaMuestra = $coleccionFacturas->first(function ($factura) {
                    return $factura['tipo_documento'] === 'muestras' &&
                           isset($factura['ruta_pdf']) && isset($factura['ruta_xml']);
                });

                if (!$facturaMuestra) {
                    continue;
                }
                // Para extraer UUID o Folio rápido (Misma lógica que usas para fletes, o simplemente guardamos las rutas)
                $indice[$pedimento] = [
                    'folio' => $facturaMuestra['nombre_base'] ?? 'S/F', // O extraer de XML
                    'path_xml_mue' => $facturaMuestra['ruta_xml'],
                    'path_pdf_mue' => $facturaMuestra['ruta_pdf'],
                ];
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de muestras: " . $e->getMessage());
        }

        return $indice;
    }
    /**
     * Se encarga de leer el XML de las Muestras, obteniendo los montos para cruzarlos con la SC.
     */
    public function auditarFacturasDeMuestras(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Muestras: No se encontró la tarea #{$tareaId} o no está procesando.");
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info('Iniciando la auditoría de Muestras...');
        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal.")];
            }

            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array) json_decode($contenidoJson, true);

            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            // 1. Construir índice de Muestras
            $indiceMuestras = $this->construirIndiceOperacionesMuestras($indicesOperaciones);
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            // 2. Extraer datos de la SC para Muestras
            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = $auditoria['desglose_conceptos'];
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datos) use ($auditoria) {
                    return $datos['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                if(!empty($arrPedimento)) {
                    $indiceSC[key($arrPedimento)] = [
                        'monto_muestras_sc' => (float) ($desglose['montos']['muestras'] ?? -1),
                        'monto_muestras_sc_mxn' => (float) ($desglose['montos']['muestras_mxn'] ?? -1),
                        'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            $muestrasParaGuardar = [];

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

                $datosMuestra = $indiceMuestras[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;

                if (!$datosMuestra) {
                    continue;
                }

                if (!$datosSC) {
                    $datosSC = [
                        'monto_muestras_sc' => -1,
                        'monto_muestras_sc_mxn' => -1,
                        'tipo_cambio' => -1,
                    ];
                }

                // Parseamos el XML reutilizando la lógica de fletes (CFDI estándar)
                $datosFacturaXml = $this->parsearXmlFlete($datosMuestra['path_xml_mue']) ?? ['total' => -1, 'moneda' => 'N/A'];
                
                $montoFacturaMXN = (($datosFacturaXml['moneda'] == "USD" && $datosFacturaXml['total'] != -1) && $datosSC['tipo_cambio'] != -1) 
                    ? round($datosFacturaXml['total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) 
                    : $datosFacturaXml['total'];
                
                $montoSCMXN = $datosSC['monto_muestras_sc_mxn'];
                
                $estado = $this->compararMontos_Muestras($montoSCMXN, $montoFacturaMXN);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoFacturaMXN, 2) : $montoFacturaMXN;

                $muestrasParaGuardar[] = [
                    'operacion_id' => $operacionId['id_operacion'],
                    'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type' => $tipoOperacion,
                    'tipo_documento' => 'muestras',
                    'concepto_llave' => 'principal',
                    'folio' => $datosMuestra['folio'] ?? null,
                    'fecha_documento' => now()->format('Y-m-d'), // O extraer fecha del XML si es requerido
                    'monto_total' => $datosFacturaXml['total'],
                    'monto_total_mxn' => $montoFacturaMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => $datosFacturaXml['moneda'],
                    'estado' => $estado,
                    'ruta_xml' => $datosMuestra['path_xml_mue'],
                    'ruta_pdf' => $datosMuestra['path_pdf_mue'],
                    'ruta_txt' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($muestrasParaGuardar)) {
                Log::info("Guardando " . count($muestrasParaGuardar) . " registros de Muestras...");
                Auditoria::upsert(
                    $muestrasParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],
                    ['fecha_documento', 'monto_total', 'monto_total_mxn', 'monto_diferencia_sc', 'moneda_documento', 'estado', 'ruta_xml', 'ruta_pdf', 'updated_at']
                );
            }

            Log::info("Auditoría de Muestras finalizada.");
            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            $tarea->update(['status' => 'fallido', 'resultado' => "Error Muestras: " . $e->getMessage()]);
            Log::error("Falló Muestras en tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Compara dos montos y devuelve el estado de la auditoría para Maniobras.
     */
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
     * Lee todos los archivos de Maniobras recientes y crea un mapa [pedimento => rutas].
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
                $facturaManiobra = $coleccionFacturas->first(function ($factura) {
                    return $factura['tipo_documento'] === 'maniobras' &&
                           isset($factura['ruta_pdf']) && isset($factura['ruta_xml']);
                });

                if (!$facturaManiobra) {
                    continue;
                }

                $indice[$pedimento] = [
                    'folio' => $facturaManiobra['nombre_base'] ?? 'S/F',
                    'path_xml_man' => $facturaManiobra['ruta_xml'],
                    'path_pdf_man' => $facturaManiobra['ruta_pdf'],
                ];
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de maniobras: " . $e->getMessage());
        }
        return $indice;
    }

    /**
     * Se encarga de leer el XML de las Maniobras, obteniendo los montos para cruzarlos con la SC.
     */
    public function auditarFacturasDeManiobras(string $tareaId)
    {
        gc_collect_cycles();
        $tarea = AuditoriaTareas::find($tareaId);
        
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Maniobras: No se encontró la tarea #{$tareaId} o no está procesando.");
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info('Iniciando la auditoría de Maniobras...');
        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array) json_decode($contenidoJson, true);

            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            // 1. Construir índice de Maniobras
            $indiceManiobras = $this->construirIndiceOperacionesManiobras($indicesOperaciones);
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            // 2. Extraer datos de la SC para Maniobras
            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = $auditoria['desglose_conceptos'];
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datos) use ($auditoria) {
                    return $datos['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                if(!empty($arrPedimento)) {
                    $indiceSC[key($arrPedimento)] = [
                        'monto_maniobras_sc' => (float) ($desglose['montos']['maniobras'] ?? -1),
                        'monto_maniobras_sc_mxn' => (float) ($desglose['montos']['maniobras_mxn'] ?? -1),
                        'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            $maniobrasParaGuardar = [];

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

                $datosManiobra = $indiceManiobras[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;

                if (!$datosManiobra) {
                    continue;
                }

                if (!$datosSC) {
                    $datosSC = [
                        'monto_maniobras_sc' => -1,
                        'monto_maniobras_sc_mxn' => -1,
                        'tipo_cambio' => -1,
                    ];
                }

                // Reutilizamos tu parseador de XML (CFDI genérico)
                $datosFacturaXml = $this->parsearXmlFlete($datosManiobra['path_xml_man']) ?? ['total' => -1, 'moneda' => 'N/A'];
                
                $montoFacturaMXN = (($datosFacturaXml['moneda'] == "USD" && $datosFacturaXml['total'] != -1) && $datosSC['tipo_cambio'] != -1) 
                    ? round($datosFacturaXml['total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) 
                    : $datosFacturaXml['total'];
                
                $montoSCMXN = $datosSC['monto_maniobras_sc_mxn'];
                
                $estado = $this->compararMontos_Maniobras($montoSCMXN, $montoFacturaMXN);
                $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoFacturaMXN, 2) : $montoFacturaMXN;

                $maniobrasParaGuardar[] = [
                    'operacion_id' => $operacionId['id_operacion'],
                    'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type' => $tipoOperacion,
                    'tipo_documento' => 'maniobras',
                    'concepto_llave' => 'principal',
                    'folio' => $datosManiobra['folio'] ?? null,
                    'fecha_documento' => now()->format('Y-m-d'),
                    'monto_total' => $datosFacturaXml['total'],
                    'monto_total_mxn' => $montoFacturaMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => $datosFacturaXml['moneda'],
                    'estado' => $estado,
                    'ruta_xml' => $datosManiobra['path_xml_man'],
                    'ruta_pdf' => $datosManiobra['path_pdf_man'],
                    'ruta_txt' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($maniobrasParaGuardar)) {
                Log::info("Guardando " . count($maniobrasParaGuardar) . " registros de Maniobras...");
                Auditoria::upsert(
                    $maniobrasParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],
                    ['fecha_documento', 'monto_total', 'monto_total_mxn', 'monto_diferencia_sc', 'moneda_documento', 'estado', 'ruta_xml', 'ruta_pdf', 'updated_at']
                );
            }

            Log::info("Auditoría de Maniobras finalizada.");
            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            $tarea->update(['status' => 'fallido', 'resultado' => "Error Maniobras: " . $e->getMessage()]);
            Log::error("Falló Maniobras en tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
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
                'periodo_meses' => '4',
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
