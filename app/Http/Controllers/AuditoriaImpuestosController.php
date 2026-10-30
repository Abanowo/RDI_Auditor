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
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

use Smalot\PdfParser\Parser;
use Maatwebsite\Excel\Facades\Excel;

class AuditoriaImpuestosController extends Controller
{
    /**
     * Únicos sitios de donde el proxy puede traer un documento.
     */
    const HOSTS_DE_DOCUMENTOS = [
        'sistema.intactics.com',
        'intactics.nyc3.cdn.digitaloceanspaces.com',
    ];

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

        $esExcelSimple = false;

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
                    // 1. Encontramos Año y Mes base del documento (Fecha de Consulta / Periodo)
                    $monthEstadoCuenta = date('m');
                    if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $textoPdf, $mFecha)) {
                        $monthEstadoCuenta = $mFecha[2];
                        $yearEstadoCuenta = $mFecha[3];
                    }

                    // 2. ESTRATEGIA DE BLOQUES: Cortamos el texto por cada pago de impuestos
                    // Esto evita que una mala lectura junte dos operaciones.
                    $bloques = preg_split('/PAGO\s+IMPUESTOS\s+ADUANALES/i', $textoPdf);
                    
                    foreach ($bloques as $index => $bloque) {
                        if ($index === 0) {
                            continue; // El índice 0 es el encabezado del estado de cuenta
                        }
                        // Buscamos el pedimento dentro de este bloque
                        if (preg_match('/(?:PEDMTO|PEDMT\s*O|PEDIMENTO)[\s:]*([4-7]\d{6})/i', $bloque, $mPed)) {
                            $pedimento = $mPed[1];
                            
                            // Buscamos la fecha mirando los últimos 50 caracteres del bloque ANTERIOR
                            $fechaCruda = '01'; 
                            $textoAnterior = substr($bloques[$index - 1], -50); 
                            if (preg_match('/(\d{2}[-\/]\d{2}|\d{2})\s*$/', $textoAnterior, $mFechaOp)) {
                                $fechaCruda = $mFechaOp[1];
                            }
                            
                            // Buscamos el Monto (Acepta formato "$ 383.00", "-20,616.00" e incluso diferentes tipos de guiones de PDF)
                            $montoStr = '0';
                            if (preg_match('/(?:\$\s*([\d,]+\.\d{2})|([-\x{2012}\x{2013}\x{2014}\x{2212}]\s*[\d,]+\.\d{2}))/u', $bloque, $mMonto)) {
                                $montoStr = !empty($mMonto[1]) ? $mMonto[1] : $mMonto[2];
                            } elseif (preg_match('/([\d,]+\.\d{2})/', $bloque, $mMontoFallback)) {
                                $montoStr = $mMontoFallback[1]; // Fallback al primer decimal que encuentre
                            }
                            
                            // Limpiamos y forzamos a positivo (por si era un cargo en negativo como en Net Cash)
                            $montoLimpio = abs((float) filter_var(str_replace(',', '', $montoStr), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION));

                            // Formateo de Fecha
                            $dia = '01';
                            $mes = $monthEstadoCuenta;

                            if (str_contains($fechaCruda, '-') || str_contains($fechaCruda, '/')) {
                                $partes = preg_split('/[\-\/]/', $fechaCruda);
                                $dia = str_pad($partes[0], 2, '0', STR_PAD_LEFT);
                                $mes = str_pad($partes[1], 2, '0', STR_PAD_LEFT);
                            } else {
                                $dia = str_pad($fechaCruda, 2, '0', STR_PAD_LEFT);
                            }
                            
                            $fechaPed = "{$yearEstadoCuenta}-{$mes}-{$dia}";
                            try {
                                $fechaParseada = \Carbon\Carbon::parse($fechaPed)->format('Y-m-d');
                            } catch (\Exception $e) {
                                $fechaParseada = now()->format('Y-m-d');
                            }

                            if ($montoLimpio > 0) {
                                $operacionesLimpiasArray[] = [
                                    'pedimento' => $pedimento,
                                    'fecha_str' => $fechaParseada,
                                    'cargo_str' => (string)$montoLimpio,
                                ];
                            }
                        }
                    }

                    // 3. PLAN B DE RESCATE MÁXIMO (Por si el PDF viene corrupto o con otro texto)
                    if (empty($operacionesLimpiasArray)) {
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
                                        try {
                                            $f = \Carbon\Carbon::createFromFormat('d-m-Y', $fechaPed)->format('Y-m-d');
                                        } catch (\Exception $e) { $f = now()->format('Y-m-d'); }

                                        $operacionesLimpiasArray[] = [
                                            'pedimento' => $pedIndividual,
                                            'fecha_str' => $f,
                                            'cargo_str' => $matchCargo[1],
                                        ];
                                    }
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
                    $patron = '/([\d,]+\.\d{2})\s+(?:(?![4-7]\d{6}\b)[\d,.\-]+\s+)*?([4-7]\d{6})\b/';

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

                $esExcelSimple = true;
                foreach ($datosExcel as $row) {
                    $montoStr = $row['cargo_str'] ?? ($row['cargo'] ?? ($row['monto'] ?? '0'));
                    $montoLimpio = (float) filter_var(str_replace(',', '', $montoStr), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    if ($montoLimpio > 0) {
                        $esExcelSimple = false;
                        break;
                    }
                }
                
                $operacionesLimpias = $datosExcel->groupBy('pedimento')->flatMap(function ($grupo) use ($tarea, $esExcelSimple) {
                    $impuestos = $grupo->filter(function ($item) {
                        $fullText = strtolower(($item['descripcion_larga'] ?? '') . ' ' . ($item['concepto'] ?? '') . ' ' . ($item['descripcion'] ?? ''));
                        return str_contains($fullText, 'impuesto') || str_contains($fullText, 'impto') || str_contains($fullText, 'cgo');
                    })->sortBy('fecha_str')->values();
                    
                    if ($impuestos->isNotEmpty()) {
                        return $impuestos; 
                    }
                    
                    $primerItem = $grupo->sortByDesc('fecha_str')->first();

                    if ($esExcelSimple) {
                        $primerItem['cargo_str'] = '0.00';
                        $primerItem['fecha_str'] = $primerItem['fecha_str'] ?? ($tarea->fecha_documento ?? now()->format('Y-m-d'));
                    }

                    return [$primerItem];
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

            $datosParaUpsert = $operacionesLimpias->map(function ($op) use ($mapaPedimentoAId, $auditoriasSC, $tarea, &$conteoConceptos, $esExcelSimple) {
                $pedLimpio = $op['pedimento'];
                $dbInfo = $mapaPedimentoAId[$pedLimpio] ?? null;

                if (!$dbInfo) {
                    return null;
                }

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

                $montoImpuestoMXN = (float) filter_var(str_replace(',', '', $op['cargo_str'] ?? ($op['monto'] ?? 0)), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                if ($esExcelSimple) {
                    $estado = "Coinciden!";
                    $diferenciaSc = 0.0;
                    $montoImpuestoMXN = 0.0;
                } else {
                    $banco = strtoupper($tarea->banco ?? '');
                    $sc = $auditoriasSC->get($id_db);

                    if (!$sc && ($banco === 'SANTANDER' || str_contains(strtoupper($pedLimpio), 'ZLO'))) {
                        $montoSCMXN = $montoImpuestoMXN;
                    } else {
                        $montoSCMXN = ($sc && isset($sc->desglose_conceptos['montos']['impuestos_mxn'])) 
                            ? (float)$sc->desglose_conceptos['montos']['impuestos_mxn'] 
                            : -1.1;
                    }

                    $estado = $this->compararMontos($montoSCMXN, $montoImpuestoMXN, $tipoOp);
                    $diferenciaSc = ($estado !== "Sin SC!" && $estado !== "Sin operacion!") ? round($montoSCMXN - $montoImpuestoMXN, 2) : $montoImpuestoMXN;
                }

                $fechaCruda = $op['fecha_str'] ?? now()->format('Y-m-d');
                $fechaFormateada = $fechaCruda;

                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fechaCruda, $m)) {
                    // Si viene en formato D/M/YYYY o DD/MM/YYYY
                    $fechaFormateada = $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
                } else {
                    try {
                        $fechaFormateada = \Carbon\Carbon::parse($fechaCruda)->format('Y-m-d');
                    } catch (\Exception $e) {
                        $fechaFormateada = now()->format('Y-m-d');
                    }
                }

                return [
                    'operacion_id' => $operacionId,
                    'pedimento_id' => $id_db,
                    'operation_type' => $tipoOp,
                    'tipo_documento' => 'impuestos',
                    'concepto_llave' => $conceptoLlave, 
                    'folio' => $pedLimpio, 
                    'fecha_documento' => $fechaFormateada,
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
                try {
                    // Re-aplicamos parseo seguro por si alguna fecha en el $operacionesLimpias venía cruda
                    $fecha = $item['fecha_str'] ?? now()->format('Y-m-d');
                    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fecha, $m)) {
                        $fecha = $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
                    }
                    return \Carbon\Carbon::parse($fecha); 
                } catch (\Exception $e) {
                    return \Carbon\Carbon::now();
                }
            });
            
            $tarea->update([
                'pedimentos_procesados' => json_encode($operacionesLimpias->pluck('pedimento')->unique()->values()->all()),
                'fecha_documento' => $fechasEncontradas->max()->addDays(15)->format('Y-m-d')
            ]);

            return ['code' => 0, 'message' => 'completado'];

        } catch (\Throwable $e) {
            $tarea->update(['status' => 'fallido', 'resultado' => $e->getMessage()]);
            Log::error("Fallo tarea #{$tareaId}: " . $e->getMessage() . " Linea: " . $e->getLine());
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
            if (!$this->getHojaDestino($tarea->sucursal, Importacion::class)) {
                Log::warning("GPC Multi: la sucursal {$tarea->sucursal} no tiene GPC configurado. Se omite el envío.");
                return ['code' => 0, 'message' => 'Omitido (sucursal sin GPC)'];
            }
 
            $mapeadoFacturas = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
            $mapaFacturas = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);
            $pedimentosDelPdf = json_decode($tarea->pedimentos_procesados, true) ?? [];
 
            $sucursalesDic = [
                'NOG' => [1, 3711], 'TIJ' => [2, 3849], 'NL'  => [3, 3711],
                'MXL' => [4, 1038], 'ZLO' => [5, 3711], 'REY' => [11, 3577],
                'VRZ' => [12, 1864]
            ];
            $sucInfo = $sucursalesDic[$tarea->sucursal] ?? [1, 3711];
 
            $mapaPdf = !empty($pedimentosDelPdf) ? $this->construirMapaDePedimentos($pedimentosDelPdf, $sucInfo[1], $sucInfo[0]) : [];
            $mapaPedimentoAId = $mapaFacturas + $mapaPdf;
 
            $indiceManiobras = [];
            if ($tarea->sucursal === 'NL') {
                $indiceManiobras = $this->construirIndiceOperacionesManiobras($indicesOperaciones);
            }
 
            $datosAgrupados = [];
 
            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $numPedRef = trim($datosId['num_pedimiento'] ?? '');
                $indiceOperacion = $indicesOperaciones[$numPedRef] ?? $indicesOperaciones[$pedimentoLimpio] ?? ['facturas' => []];
 
                $resultado = $this->construirFilasGPCMulti($tarea->sucursal, (string) $pedimentoLimpio, $datosId, $indiceOperacion, $indiceManiobras, false);
 
                if (!$resultado['hoja']) {
                    continue;
                }
 
                foreach ($resultado['filas'] as $fila) {
                    $datosAgrupados[$resultado['hoja']][] = $fila;
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
            Log::error("Error en GPC Multi-sucursal: " . $e->getMessage() . " en la línea " . $e->getLine());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Webhook dedicado a la nueva URL de App Script
     */
    private function enviarDatosAGoogleSheetsMulti(array $datosParaEnviar, string $hoja, bool $esUltimo = true): bool
    {
        try {
            if (empty($datosParaEnviar)) {
                return false;
            }

            if ($this->modoVistaPrevia) {
                $this->registrarEnvioVistaPrevia('multi', $hoja, $datosParaEnviar, $esUltimo);
                return true;
            }

            if (app()->environment('production')) {
                $scriptUrl = 'https://script.google.com/macros/s/AKfycbzaLZjOImg1nz_nywOTYoc-iM-LFhtFeVPIf3biXstCcOLK1BXfBmiomg29--cdDuS4iw/exec'; // PRODUCCIÓN
            } else {
                $scriptUrl = 'https://script.google.com/macros/s/AKfycbwYfBt6btEjIXLjnLBWlqEth9r7dricE1QOVAeAVK6wUEWitK1ZI3x2YRe5k243VvC5/exec'; // DESARROLLO
            }

            Log::info("Enviando " . count($datosParaEnviar) . " registros a GPC Multi. Hoja: $hoja");

            $resultado = $this->postAppsScript($scriptUrl, [
                'hoja'       => $hoja,
                'pedimentos' => $datosParaEnviar,
                'es_ultimo'  => $esUltimo
            ]);

            if (!$resultado['ok']) {
                Log::warning("GPC Multi ($hoja) no devolvió su respuesta JSON; revisa la hoja. " . $resultado['detalle']);
                return false;
            }

            $cuerpo = $resultado['json'];
            Log::info("Respuesta Real de Google para {$hoja}: " . json_encode($cuerpo));

            if (isset($cuerpo['status']) && $cuerpo['status'] === 'error') {
                Log::error("GPC Multi Error Interno: " . ($cuerpo['message'] ?? 'sin mensaje'));
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Error enviando a Sheets Multi: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mapea la sucursal y la operación a la pestaña exacta
     */
    private function getHojaDestino($sucursalCod, $operationTypeClass): ?string
    {
        $sucursales = [
            'NOG' => 'NOGALES',
            'NL'  => 'LAREDO',
            'TIJ' => 'TIJUANA',
            'MXL' => 'MEXICALI'
        ];
 
        $nombre = $sucursales[strtoupper((string) $sucursalCod)] ?? null;
        if (!$nombre) {
            return null;
        }
 
        $tipo = (str_contains($operationTypeClass, 'Importacion')) ? 'IMPO' : 'EXPO';
 
        return "{$nombre} {$tipo}";
    }
    
    // 5. ENVÍO DE IMPUESTOS A GPC (Solo Santander / Manzanillo)
    public function enviarAGPCImpuestos($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);
        
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

    private function postAppsScript(string $url, array $payload, int $timeout = 400): array
    {
        $maxIntentos = 4;

        for ($intento = 1; $intento <= $maxIntentos; $intento++) {
            $respuesta = Http::timeout($timeout)
                ->withoutVerifying()
                ->post($url, $payload);

            $status = $respuesta->status();
            $json = $respuesta->json();

            if (is_array($json)) {
                $mensaje = (string) ($json['message'] ?? '');
                $estaOcupado = (($json['status'] ?? '') === 'error') && stripos($mensaje, 'ocupado') !== false;

                if ($estaOcupado && $intento < $maxIntentos) {
                    Log::warning("Apps Script ocupado (otra ejecución sigue corriendo). Reintento {$intento} de " . ($maxIntentos - 1) . " en 20 segundos...");
                    sleep(20);
                    continue;
                }

                if ($estaOcupado) {
                    Log::error("Apps Script siguió ocupado después de {$maxIntentos} intentos: estos registros NO se escribieron en la hoja.");
                }

                return ['ok' => true, 'json' => $json, 'status' => $status, 'detalle' => ''];
            }

            // Llegó HTML: sacamos el título de la página para saber qué fue
            $cuerpo = (string) $respuesta->body();
            $titulo = preg_match('/<title[^>]*>(.*?)<\/title>/is', $cuerpo, $m)
                ? trim(html_entity_decode(strip_tags($m[1])))
                : '';
            $extracto = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($cuerpo))), 0, 200);

            return [
                'ok' => false,
                'json' => null,
                'status' => $status,
                'detalle' => "HTTP {$status}" . ($titulo !== '' ? " | Página: {$titulo}" : '') . " | Inicio: {$extracto}"
            ];
        }

        return ['ok' => false, 'json' => null, 'status' => 0, 'detalle' => 'Sin respuesta.'];
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

            // Vista previa: se guarda lo que se enviaría y NO se manda a Google Sheets
            if ($this->modoVistaPrevia) {
                $this->registrarEnvioVistaPrevia('pedimentos', $hoja, $datosParaEnviar, $esUltimo, $sucursal);
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
                $urlOtros = 'https://script.google.com/macros/s/AKfycbwYfBt6btEjIXLjnLBWlqEth9r7dricE1QOVAeAVK6wUEWitK1ZI3x2YRe5k243VvC5/exec';
            }

            // 2. Seleccionamos el script correcto según la sucursal
            $scriptUrl = ($sucursal === 'ZLO') ? $urlZLO : $urlOtros;

            Log::info("Enviando " . count($datosParaEnviar) . " registros a GPC. Sucursal: $sucursal | Hoja: $hoja | Último: " . ($esUltimo ? 'sí' : 'no') . " | Entorno: " . app()->environment());

            // 3. Enviamos el payload siguiendo la redirección de Apps Script
            $resultado = $this->postAppsScript($scriptUrl, [
                'hoja'       => $hoja,
                'pedimentos' => $datosParaEnviar,
                'es_ultimo'  => $esUltimo
            ]);

            // 4. Manejo de la respuesta
            if (!$resultado['ok']) {
                // doPost sí se ejecutó (los datos suelen quedar escritos), pero no llegó su respuesta
                Log::warning("Google Sheets ($sucursal/$hoja) no devolvió su respuesta JSON; revisa la hoja. " . $resultado['detalle']);
                return;
            }

            $cuerpoRespuesta = $resultado['json'];

            if (isset($cuerpoRespuesta['status']) && $cuerpoRespuesta['status'] === 'error') {
                Log::error("Google Sheets Error Interno: " . ($cuerpoRespuesta['message'] ?? 'sin mensaje'));
            } else {
                $debugInfo = isset($cuerpoRespuesta['debug']) ? json_encode($cuerpoRespuesta['debug']) : 'Sin detalles';
                Log::info("Google Sheets respondió con Éxito. Detalle: " . $debugInfo);
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
                        'tipo' => 'Exportacion',
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
                    return '\\b' . preg_quote($p) . '\\b';
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
            if (!empty($rutaMapeo)) {
                Storage::delete($rutaMapeo);
            }
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

        Log::info("--- [INICIO] Auditoría de Fletes (Transportactics) para Tarea #{$tarea->id} ---");

        try {
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

            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'] ?? [];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'] ?? [];
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);

            $indiceFletes = $this->construirIndiceOperacionesFletes($indicesOperaciones);
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'] ?? [];

            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos']) 
                    ? $auditoria['desglose_conceptos'] 
                    : json_decode($auditoria['desglose_conceptos'], true);

                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                if (!empty($arrPedimento)) {
                    $indiceSC[key($arrPedimento)] = [
                        'monto_flete_sc'     => (float) ($desglose['montos']['flete'] ?? -1),
                        'monto_flete_sc_mxn' => (float) ($desglose['montos']['flete_mxn'] ?? -1),
                        'moneda'             => $desglose['moneda'] ?? 'MXN',
                        'tipo_cambio'        => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            $fletesParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                $numPedBD = trim($pedimentoSucioYId['num_pedimiento'] ?? '');
                
                $operacionId = $mapaPedimentoAImportacionId[$numPedBD] 
                    ?? $mapaPedimentoAImportacionId[$pedimentoLimpio] 
                    ?? null;
                $tipoOperacion = Importacion::class;

                if (!$operacionId) {
                    $operacionId = $mapaPedimentoAExportacionId[$numPedBD] 
                        ?? $mapaPedimentoAExportacionId[$pedimentoLimpio] 
                        ?? null;
                    $tipoOperacion = Exportacion::class;
                }

                if (!$operacionId) {
                    continue;
                }

                $queryOp = ($tipoOperacion === Importacion::class) 
                    ? Importacion::with('cliente')->find($operacionId['id_operacion']) 
                    : Exportacion::with('cliente')->find($operacionId['id_operacion']);

                if ($queryOp && $queryOp->cliente) {
                    $nombreCliente = strtoupper(trim($queryOp->cliente->nombre));
                    if (str_contains($nombreCliente, 'QUESOS Y QUESOS')) {
                        Auditoria::where('pedimento_id', $pedimentoSucioYId['id_pedimiento'])
                            ->where('tipo_documento', 'flete')
                            ->delete();
                        continue;
                    }
                }

                $datosFlete = $indiceFletes[$numPedBD] ?? $indiceFletes[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$numPedBD] ?? $indiceSC[$pedimentoLimpio] ?? [
                    'monto_flete_sc'     => -1,
                    'monto_flete_sc_mxn' => -1,
                    'tipo_cambio'        => 1.0,
                    'moneda'             => 'N/A',
                ];

                if (!$datosFlete) {
                    continue;
                }

                $totalFlete = -1;
                $monedaFlete = 'MXN';
                $fechaFlete = null;
                $folioFlete = $datosFlete['folio'] ?? null;

                // 1. Extraer del XML
                if (!empty($datosFlete['path_xml_tr'])) {
                    $xmlData = $this->parsearXmlFlete($datosFlete['path_xml_tr']);
                    if ($xmlData && ($xmlData['total'] ?? -1) > 0) {
                        $totalFlete = (float) $xmlData['total'];
                        $monedaFlete = strtoupper($xmlData['moneda'] ?? 'MXN');
                        $fechaFlete = $xmlData['fecha'] ?? null;
                        if (!empty($xmlData['folio'])) {
                            $folioFlete = $xmlData['folio'];
                        }
                    }
                }

                // 2. Respaldo en PDF
                if ($totalFlete <= 0 && !empty($datosFlete['path_pdf_tr'])) {
                    $pdfData = $this->extraerTotalDesdePdfProveedor($datosFlete['path_pdf_tr']);
                    if ($pdfData && ($pdfData['monto'] ?? 0) > 0) {
                        $totalFlete = (float) $pdfData['monto'];
                        $fechaFlete = $fechaFlete ?: $pdfData['fecha'];
                        if (empty($folioFlete) && !empty($pdfData['folio'])) {
                            $folioFlete = $pdfData['folio'];
                        }
                    }
                }

                if ($totalFlete <= 0) {
                    continue;
                }

                $montoFleteMXN = ($monedaFlete === 'USD' && ($datosSC['tipo_cambio'] ?? 0) > 1)
                    ? round($totalFlete * $datosSC['tipo_cambio'], 2)
                    : $totalFlete;

                $montoSCMXN = (float) ($datosSC['monto_flete_sc_mxn'] ?? -1);
                $estado = $this->compararMontos_Fletes($montoSCMXN, $montoFleteMXN);
                $diferenciaSc = ($estado !== 'Sin SC!' && $estado !== 'Sin operacion!') 
                    ? round($montoSCMXN - $montoFleteMXN, 2) 
                    : $montoFleteMXN;

                $fletesParaGuardar[] = [
                    'operacion_id'        => $operacionId['id_operacion'],
                    'pedimento_id'        => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type'      => $tipoOperacion,
                    'tipo_documento'      => 'flete',
                    'concepto_llave'      => 'principal',
                    'folio'               => $folioFlete ?: '-',
                    'fecha_documento'     => $fechaFlete ?: now()->format('Y-m-d'),
                    'monto_total'         => $totalFlete,
                    'monto_total_mxn'     => $montoFleteMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento'    => $monedaFlete,
                    'estado'              => $estado,
                    'ruta_xml'            => $datosFlete['path_xml_tr'] ?? null,
                    'ruta_txt'            => null,
                    'ruta_pdf'            => $datosFlete['path_pdf_tr'] ?? null,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ];
            }

            if (!empty($fletesParaGuardar)) {
                Auditoria::upsert(
                    $fletesParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],
                    ['fecha_documento', 'monto_total', 'monto_total_mxn', 'monto_diferencia_sc', 'moneda_documento', 'estado', 'ruta_xml', 'ruta_txt', 'ruta_pdf', 'folio', 'updated_at']
                );
                Log::info("🚛 [AUDITORIA FLETE] Guardados exitosamente: " . count($fletesParaGuardar) . " registros.");
            }

            Log::info("--- [FIN] Auditoría de Fletes finalizada. ---");
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            $tarea->update(['status' => 'fallido', 'resultado' => $e->getMessage()]);
            Log::error("Error en Fletes (Tarea #{$tarea->id}): " . $e->getMessage() . " Línea: " . $e->getLine());
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
            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'] ?? [];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'] ?? [];

            //$mapaPedimentoAId - Este arreglo contiene los pedimentos limpios, sucios, y su Id correspondiente
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);


            //--- YA UNA VEZ TENIENDO TODO A LA MANO
            // 3. Construimos el índice de LLC desde los archivos de importacion y exportacion
            $indiceLLC = $this->construirIndiceOperacionesLLCs($indicesOperaciones);
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'] ?? [];

            // Aquí es donde extraemos el tipo de cambio del JSON.
            $indiceSC = [];

            foreach ($auditoriasSC as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos']) ? $auditoria['desglose_conceptos'] : json_decode($auditoria['desglose_conceptos'], true);
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                if(!empty($arrPedimento)){
                    $indiceSC[key($arrPedimento)] = [
                        'desglose' => $desglose,
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
                $numPedRef = $pedimentoSucioYId['num_pedimiento'] ?? '';

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

                $montoRealExtraido = -1.0; 

                if ($datosLlc !== null) {
                    // LECTURA DINÁMICA DE PDF SIN RESTRICCIONES (Aplica para todo)
                    if (empty($datosLlc['txt_exitoso']) || $datosLlc['monto_total'] <= 0) {
                        if (!empty($datosLlc['ruta_pdf'])) {
                            // Enviamos el pedimentoLimpio para que el log lo intercepte
                            $pdfData = $this->extraerTotalDesdePdfProveedor($datosLlc['ruta_pdf'], $pedimentoLimpio);
                            
                            if ($pdfData !== null && $pdfData['monto'] !== null) {
                                $datosLlc['monto_total'] = (float) $pdfData['monto'];
                                if (!empty($pdfData['fecha'])) $datosLlc['fecha'] = $pdfData['fecha'];
                                
                                $nombreArchivo = basename($datosLlc['ruta_pdf']);
                                if (preg_match('/(?:NOG|TIJ|NL|MXL|ZLO|REY|VRZ|ITK|FSSA)-?0*(\d+)/i', $nombreArchivo, $matchFolioArchivo)) {
                                    $datosLlc['folio'] = $matchFolioArchivo[1]; 
                                } elseif (!empty($pdfData['folio'])) {
                                    $datosLlc['folio'] = $pdfData['folio']; 
                                }
                            }
                        }
                    }
                    $montoRealExtraido = (float) ($datosLlc['monto_total'] ?? 0);
                }

                // Cálculos y Comparativa
                $tipoCambioUsar = (isset($datosSC['tipo_cambio']) && $datosSC['tipo_cambio'] > 1) ? $datosSC['tipo_cambio'] : $tipoCambioGlobal;
                $montoSCMXN = -1.0;

                if ($datosSC) {
                    $desgloseCompleto = $datosSC['desglose'] ?? [];
                    
                    $valoresEncontrados = [];
                    if (is_array($desgloseCompleto) || is_object($desgloseCompleto)) {
                        array_walk_recursive($desgloseCompleto, function($value, $key) use (&$valoresEncontrados) {
                            $valoresEncontrados[] = [
                                'key' => strtolower((string)$key), 
                                'value' => $value
                            ];
                        });
                    }

                    $llavesPosibles = ['llc', 'tactics', 'logistica', 'usa', 'extranjer', 'comercializadora', 'intactics'];

                    foreach ($valoresEncontrados as $item) {
                        $kLower = $item['key'];
                        $vLower = is_string($item['value']) ? strtolower($item['value']) : '';
                        $vFloat = is_numeric($item['value']) ? round((float)$item['value'], 2) : 0;

                        if ($vFloat > 0) {
                            $esLLC = false;
                            foreach ($llavesPosibles as $palabra) {
                                if (str_contains($kLower, $palabra) || str_contains($vLower, $palabra)) {
                                    $esLLC = true; 
                                    break;
                                }
                            }

                            if ($esLLC) {
                                if ($montoRealExtraido > 0 && $vFloat >= ($montoRealExtraido * 15) && $vFloat <= ($montoRealExtraido * 25)) {
                                    $tipoCambioUsar = round($vFloat / $montoRealExtraido, 4);
                                }

                                if (str_ends_with($kLower, '_mxn') || str_contains($kLower, 'mxn') || ($montoRealExtraido > 0 && $vFloat >= ($montoRealExtraido * 10))) {
                                    $montoSCMXN = $vFloat; 
                                } else {
                                    $montoSCMXN = ($tipoCambioUsar > 1) ? round($vFloat * $tipoCambioUsar, 2) : $vFloat; 
                                }
                                break; 
                            }
                        }
                    }

                    // RADAR MATEMÁTICO
                    if ($montoSCMXN === -1.0 && $montoRealExtraido > 0) {
                        $montoBuscadoUSD = round($montoRealExtraido, 2);
                        $tcTemp = ($tipoCambioUsar <= 1.0) ? 18.0 : $tipoCambioUsar;
                        $montoBuscadoMXN = round($montoBuscadoUSD * $tcTemp, 2);

                        foreach ($valoresEncontrados as $item) {
                            if (!is_numeric($item['value'])) continue;
                            $vFloat = round((float)$item['value'], 2);
                            if ($vFloat <= 0) continue;

                            $posibleTC = round($vFloat / $montoRealExtraido, 4);
                            if ($posibleTC >= 15.0 && $posibleTC <= 25.0) {
                                $tipoCambioUsar = $posibleTC;
                                $montoSCMXN = $vFloat;
                                break;
                            }

                            if (abs($vFloat - $montoBuscadoMXN) <= 5.0) {
                                $montoSCMXN = $vFloat;
                                break;
                            }
                            if (abs($vFloat - $montoRealExtraido) <= 2.0) {
                                if ($tipoCambioUsar <= 1.0) $tipoCambioUsar = 18.0; 
                                $montoSCMXN = round($vFloat * $tipoCambioUsar, 2);
                                break;
                            }
                        }
                    }
                }

                if ($montoRealExtraido === -1.0) {
                    $montoLLCMXN = -1.0;
                } else {
                    if ($tipoCambioUsar <= 1.0) $tipoCambioUsar = 18.0; 
                    $montoLLCMXN = round($montoRealExtraido * $tipoCambioUsar, 2);
                }
                $estado = $this->compararMontos_LLC($montoSCMXN, $montoLLCMXN);
                // Respetamos estrictamente los estados 'Sin SC!' y 'Sin LLC!' para que tu Front-End / DB los reconozca
                if ($estado === 'Sin SC!' || $estado === 'Sin LLC!') {
                    $diferenciaSc = 0.0; // Se mantiene en 0 para evitar ilusiones ópticas en la tabla
                } else if ($estado === 'Coinciden!') {
                    $diferenciaSc = 0.0;
                } else {
                    $diffMxn = round($montoSCMXN - $montoLLCMXN, 2);
                    $diferenciaSc = ($tipoCambioUsar > 1) ? round($diffMxn / $tipoCambioUsar, 2) : $diffMxn;
                }

                if ($diferenciaSc > 10000000 || $diferenciaSc < -10000000) {
                    $diferenciaSc = 0.0;
                }

                $llcsParaGuardar[] = [
                    'operacion_id' => $idOp,
                    'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type' => $tipoOperacion,
                    'tipo_documento' => 'llc',
                    'concepto_llave' => 'principal',
                    'folio' => $datosLlc['folio'] ?? 'S/F',
                    'fecha_documento' => $datosLlc['fecha'] ?? now()->format('Y-m-d'),
                    'monto_total' => $montoRealExtraido === -1.0 ? 0 : $montoRealExtraido,
                    'monto_total_mxn' => $montoLLCMXN === -1.0 ? 0 : $montoLLCMXN,
                    'monto_diferencia_sc' => $diferenciaSc,
                    'moneda_documento' => 'USD',
                    'estado' => $estado,
                    'ruta_txt' => $datosLlc['ruta_txt'] ?? null,
                    'ruta_pdf' => $datosLlc['ruta_pdf'] ?? null,
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
    public function auditarFacturasDePagosDeDerecho($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);

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

                $auditoriasSC = $mapeadoFacturas['auditorias_sc'] ?? [];

                foreach ($mejoresPagos as $llaveUnica => $datosPago) {
                    $rutaFinal = $datosPago['ruta_alternativa'] ?? $datosPago['ruta_pdf_original'];
                    $nombreFinal = strtoupper(basename($rutaFinal));
                    $estadoReal = str_contains($nombreFinal, 'INTACTICS') ? 'Intactics' : 'Normal';

                    $idPedimentoBD = $pedimentoSucioYId['id_pedimiento'];
                    $scDelPedimento = $auditoriasSC[$idPedimentoBD] ?? null;
                    
                    $montoScPDD = -1.1;
                    if ($scDelPedimento) {
                        $desglose = is_string($scDelPedimento['desglose_conceptos']) 
                                    ? json_decode($scDelPedimento['desglose_conceptos'], true) 
                                    : $scDelPedimento['desglose_conceptos'];
                        
                        $montoScPDD = (float)($desglose['montos']['pago_derecho_mxn'] ?? -1.1);
                    }

                    $montoPagoPDF = (float)$datosPago['monto_total'];

                    // Comparamos los montos para sacar el estado ("Pagado", "Diferencia", "Sin SC!")
                    $estadoValidacion = $this->compararMontos($montoScPDD, $montoPagoPDF, $tipoOperacion);
                    
                    $diferenciaSc = ($estadoValidacion !== "Sin SC!" && $estadoValidacion !== "Sin operacion!") 
                        ? round($montoScPDD - $montoPagoPDF, 2) 
                        : $montoPagoPDF;

                    $estadoFinal = $estadoReal . ' - ' . $estadoValidacion;

                    $pagosParaGuardar[] = [
                        'operacion_id' => $operacionId['id_operacion'],
                        'pedimento_id' => $pedimentoSucioYId['id_pedimiento'],
                        'operation_type' => $tipoOperacion,
                        'tipo_documento' => 'pago_derecho',
                        'concepto_llave' => $llaveUnica,
                        'fecha_documento' => $datosPago['fecha_pago'] ?? now()->format('Y-m-d'),
                        'monto_total' => $montoPagoPDF,
                        'monto_total_mxn' => $montoPagoPDF,
                        'monto_diferencia_sc' => $diferenciaSc,
                        'moneda_documento' => 'MXN',
                        'estado' => $estadoFinal,
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
    public function enviarAGPCPagosDeDerecho($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);

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
                    
                    // Extraemos los datos base de la operación usando la primera factura de referencia
                    $auditoriaReferencia = $auditoriasBase->first();
                    $nombreCliente = '';
                    $contenedor    = '';
                    $bl            = '';
                    $naviera       = '';

                    if ($auditoriaReferencia->operation_type === Importacion::class) {
                        $operacion = Importacion::with('cliente')->find($auditoriaReferencia->operacion_id);
                    } else {
                        $operacion = Exportacion::with('cliente')->find($auditoriaReferencia->operacion_id);
                    }

                    if ($operacion) {
                        $nombreCliente = optional($operacion->cliente)->nombre ?? '';
                        $contenedor    = $operacion->contenedor ?? '';
                        $bl            = $operacion->bol ?? ''; 
                        $naviera       = $operacion->naviera ?? '';
                    }

                    // AHORA ITERAMOS EN VEZ DE SUMAR: Para separar los pagos de derecho en hojas de cálculo
                    foreach ($auditoriasBase as $idxAud => $auditoriaItem) {
                        $fechaOp = $auditoriaItem->fecha_documento ?? now()->format('Y-m-d');
                        $esRecti = $familia['datos']['es_recti'] ?? false;
                        
                        // Si hay más de un pago de derecho, le agregamos un número para separarlo (Ej: Pago de Derechos 2)
                        $conceptoBase = $esRecti ? 'Pago de Derecho Recti' : 'Pago de Derechos'; 
                        $conceptoAEnviar = ($idxAud === 0) ? $conceptoBase : $conceptoBase . ' ' . ($idxAud + 1);

                        $pagosParaSheets[] = [
                            'fecha'      => $fechaOp,
                            'cliente'    => $nombreCliente,
                            'contenedor' => $contenedor,
                            'bl'         => $bl, 
                            'naviera'    => $naviera,
                            'anticipo'   => '', 
                            'pedimento'  => $pedimentoAEnviar,
                            'concepto'   => $conceptoAEnviar, 
                            'monto'      => (float) $auditoriaItem->monto_total,
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
            // En uploads/ van archivos sueltos, sin subcarpetas: el nombre ya es
            // un Str::random(40), así que no hay riesgo de colisión.
            $rutaDeAlmacenamiento = $nombreUnico;

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
            $destinatarios = ['carlos.perez@intactics.com', 'anthonyanroab@gmail.com'];

            // Usamos nuestro nuevo Mailable, pasándole la tarea y la excepción.
            Mail::to($destinatarios)->send(new EnviarFalloReporteAuditoriaMail($tarea, $exception));

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
     * OPTIMIZADO: Anti-Crash XAMPP (Pre-carga de base de datos)
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
                'FACTURA-MANIOBRAS-EN-ALMACEN-FISCALIZADO' => 'maniobras',
                'PROVEEDORES' => 'proveedores',
                'TERMINAL' => 'terminal',
                'VACIOS' => 'vacios',
                'PAGO-DE-MUESTRAS' => 'pago_muestras',
                'OTROS' => 'otros'
            ];
 
        // PRE-CARGAR LOS PARENTS FUERA DEL BUCLE
        $idsImportacion = [];
        $idsExportacion = [];
 
        foreach ($pedimentosOperacion as $op) {
            $tipo = strtolower($op['tipo'] ?? '');
            if ($tipo === 'importacion' && isset($op['id_operacion'])) {
                $idsImportacion[] = $op['id_operacion'];
            } elseif ($tipo === 'exportacion' && isset($op['id_operacion'])) {
                $idsExportacion[] = $op['id_operacion'];
            }
        }
 
        $parentsImportacion = [];
        if (!empty($idsImportacion)) {
            $parentsImportacion = DB::table('operaciones_importacion')
                ->whereIn('id_importacion', $idsImportacion)
                ->whereNotNull('parent')
                ->pluck('parent', 'id_importacion')
                ->toArray();
        }
 
        $parentsExportacion = [];
        if (!empty($idsExportacion)) {
            $parentsExportacion = DB::table('operaciones_exportacion')
                ->whereIn('id_exportacion', $idsExportacion)
                ->whereNotNull('parent')
                ->pluck('parent', 'id_exportacion')
                ->toArray();
        }
 
        foreach ($pedimentosOperacion as $pedimento => $operacionID) {
            try {
                $idOp = $operacionID['id_operacion'] ?? null;
                $tipoReal = strtolower($operacionID['tipo'] ?? 'sin operacion');
 
                if (empty($idOp) || $tipoReal === 'sin operacion') {
                    continue;
                }
 
                $tipoApi = ($tipoReal === 'importacion') ? 'importaciones' : 'exportaciones';
                $archivos_pdf = [];
 
                // 1. Archivos de la operación original
                $urlApi = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/get-files-momentaneo";
                $url_pdf = Http::withoutVerifying()->timeout(15)->get($urlApi);
 
                if ($url_pdf->successful() && is_array($url_pdf->json())) {
                    $archivos_pdf = array_merge($archivos_pdf, $url_pdf->json());
                }
 
                // 2. Archivos del padre (diccionario en memoria)
                $parentId = ($tipoReal === 'importacion')
                    ? ($parentsImportacion[$idOp] ?? null)
                    : ($parentsExportacion[$idOp] ?? null);
 
                if (!empty($parentId)) {
                    $urlApiParent = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$parentId}/get-files-momentaneo";
                    $url_pdf_parent = Http::withoutVerifying()->timeout(15)->get($urlApiParent);
 
                    if ($url_pdf_parent->successful() && is_array($url_pdf_parent->json())) {
                        $archivos_pdf = array_merge($archivos_pdf, $url_pdf_parent->json());
                    }
                }
 
                if (empty($archivos_pdf)) {
                    continue;
                }
 
                $indiceFacturas[$pedimento] = [
                    'tipo_operacion' => $tipoApi,
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
 
                    // NUEVO: Carpeta de ROJO / Reconocimiento Aduanero
                    if (!$tipoFinal && (str_contains($normalizedPivot, 'rojo') || str_contains($normalizedPivot, 'reconocimiento'))) {
                        $tipoFinal = 'rojo';
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
 
                    // NUEVO: Guardamos la carpeta real ANTES de reclasificar a 'vacios'
                    $carpetaOriginal = $tipoFinal;
 
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
                                'carpeta_original' => $carpetaOriginal,
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
 
                    $nombreBaseCodificado = rawurlencode($nombreBase);
 
                    if ($tipoFinal === 'sc' || $tipoOriginalMapeo === 'HONORARIOS-SC' || $tipoFinal === 'flete') {
                        $agrupadorTemp[$llaveGrupo]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/{$nombreBaseCodificado}.txt";
                    } elseif ($tipoFinal === 'llc' || $tipoOriginalMapeo === 'HONORARIOS-LLC') {
                        $agrupadorTemp[$llaveGrupo]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/llc-{$nombreBaseCodificado}.txt";
                    }
                }
 
                $indiceFacturas[$pedimento]['facturas'] = $agrupadorTemp;
 
                // Micro-pausa para no agotar puertos salientes en XAMPP
                usleep(20000);
 
            } catch (\Throwable $e) {
                Log::error("Error procesando operacion {$operacionID['id_operacion']}: " . $e->getMessage());
            }
        }
        return $indiceFacturas;
    }

    private function obtenerTextoClasificacionXml(?string $rutaXml): string
    {
        static $cache = [];
 
        if (empty($rutaXml)) {
            return '';
        }
 
        if (isset($cache[$rutaXml])) {
            return $cache[$rutaXml];
        }
 
        $texto = '';
 
        try {
            $contexto = stream_context_create([
                "ssl"  => ["verify_peer" => false, "verify_peer_name" => false],
                "http" => ["ignore_errors" => true, "timeout" => 10]
            ]);
 
            $xmlString = @file_get_contents($rutaXml, false, $contexto);
 
            if (!$xmlString || str_contains($http_response_header[0] ?? '', '404')) {
                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);
                $xmlString = @file_get_contents($rutaAlternativa, false, $contexto);
            }
 
            if ($xmlString) {
                $partes = [];
 
                if (preg_match_all('/Descripcion=["\']([^"\']*)["\']/i', $xmlString, $mDescripciones)) {
                    $partes = array_merge($partes, $mDescripciones[1]);
                }
 
                if (preg_match('/Emisor[^>]+Nombre=["\']([^"\']+)["\']/is', $xmlString, $mEmisor)) {
                    $partes[] = $mEmisor[1];
                }
 
                $texto = Str::upper(Str::ascii(html_entity_decode(implode(' ', $partes))));
            }
        } catch (\Throwable $e) {
            Log::warning("Conceptos ZLO: no se pudo leer el XML {$rutaXml}: " . $e->getMessage());
        }
 
        return $cache[$rutaXml] = $texto;
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
     * Lee los archivos de Fletes (Transportactics) y crea el índice por pedimento.
     */
    private function construirIndiceOperacionesFletes(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];

        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas'] ?? []);

                $facturasFlete = $coleccionFacturas->filter(function ($factura, $llaveGrupo) {
                    $tipo = strtolower($factura['tipo_documento'] ?? '');
                    $nombreMayus = strtoupper($llaveGrupo . ' ' . ($factura['ruta_pdf'] ?? '') . ' ' . ($factura['ruta_xml'] ?? ''));

                    $esFlete = ($tipo === 'flete')
                        || str_contains($tipo, 'flete')
                        || str_contains($nombreMayus, 'TRANSPORTACTICS')
                        || str_contains($nombreMayus, 'FLETE');

                    $tieneArchivo = !empty($factura['ruta_xml']) || !empty($factura['ruta_pdf']);

                    return $esFlete && $tieneArchivo;
                });

                if ($facturasFlete->isEmpty()) {
                    continue;
                }

                $facturaFlete = $facturasFlete->first();

                preg_match('/[4-7]\d{6}/', (string) $pedimento, $mPed);
                $pedLimpio = $mPed[0] ?? (string) $pedimento;

                $datosFlete = [
                    'folio'       => $facturaFlete['folio'] ?? null,
                    'path_xml_tr' => $facturaFlete['ruta_xml'] ?? null,
                    'path_pdf_tr' => $facturaFlete['ruta_pdf'] ?? null,
                    'fecha'       => null,
                ];

                // Guardar con la llave original y con los 7 dígitos limpios
                $indice[$pedimento] = $datosFlete;
                $indice[$pedLimpio] = $datosFlete;

            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de fletes: " . $e->getMessage());
        }

        return $indice;
    }

    private function clasificarConceptoEspecialZLO(string $carpeta, string $texto): ?string
    {
        $carpeta = strtolower($carpeta);
        $t = Str::upper(Str::ascii($texto));
 
        $enProveedores = str_contains($carpeta, 'proveedor');
        $enTerminal    = str_contains($carpeta, 'terminal');
        $enVacios      = str_contains($carpeta, 'vacio');
        $enManiobras   = str_contains($carpeta, 'maniobra');
        $enRojo        = str_contains($carpeta, 'rojo');
 
        // Logipuerto tiene su propio concepto
        if (str_contains($t, 'LOGIPUERTO') || str_contains($t, 'LOGI PUERTO')) {
            return null;
        }
 
        // RECONOCIMIENTO: todo lo que esté en la carpeta de ROJO
        if ($enRojo) {
            return 'Reconocimiento';
        }
 
        // ACCESA: MANIOBRAS o PROVEEDORES
        if (($enManiobras || $enProveedores) && (str_contains($t, 'ACCESA') || str_contains($t, 'ACCESSA'))) {
            return 'Accesa';
        }
 
        // RECONOCIMIENTO: MANIOBRAS o PROVEEDORES (con palabra clave)
        if (($enManiobras || $enProveedores) && (str_contains($t, 'RECONOCIMIENTO') || str_contains($t, 'PREVIO'))) {
            return 'Reconocimiento';
        }
 
        // REACOMODO: PROVEEDORES, TERMINAL o MANIOBRAS
        if (($enProveedores || $enTerminal || $enManiobras) && str_contains($t, 'REACOMODO')) {
            return 'Reacomodo';
        }
 
        // CONEXIONES: PROVEEDORES o TERMINAL
        if (str_contains($t, 'CONEXION')) {
            return ($enProveedores || $enTerminal) ? 'Conexiones' : null;
        }
 
        // LIMPIEZA: PROVEEDORES o VACÍOS
        if (str_contains($t, 'LIMPIEZA') || str_contains($t, 'LAVADO')) {
            return ($enProveedores || $enVacios) ? 'Limpieza' : null;
        }
 
        return null;
    }
 
    private function extraerDatosFacturaProveedorZLO(?string $rutaXml, ?string $rutaPdf, float $tipoCambio = 1.0): array
    {
        $montoOriginal = -1;
        $monedaXml = 'MXN';
        $proveedor = '';
        $fecha = null;
 
        if (!empty($rutaXml)) {
            try {
                $xmlData = $this->parsearXmlFlete($rutaXml);
                if ($xmlData) {
                    $proveedor = trim($xmlData['emisor'] ?? '');
                    $fecha = $xmlData['fecha'] ?? null;
                    if (($xmlData['total'] ?? -1) != -1) {
                        $montoOriginal = (float) $xmlData['total'];
                        $monedaXml = strtoupper($xmlData['moneda'] ?? 'MXN');
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Conceptos ZLO: error leyendo XML {$rutaXml}: " . $e->getMessage());
            }
        }
 
        if ($montoOriginal <= 0 && !empty($rutaPdf)) {
            try {
                $pdfData = $this->extraerTotalDesdePdfProveedor($rutaPdf);
                if ($pdfData !== null && $pdfData['monto'] !== null) {
                    $montoOriginal = (float) $pdfData['monto'];
                    if (!empty($pdfData['fecha'])) {
                        $fecha = $pdfData['fecha'];
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Conceptos ZLO: error leyendo PDF {$rutaPdf}: " . $e->getMessage());
            }
        }
 
        $monto = $montoOriginal;
        $moneda = 'MXN';
 
        if ($montoOriginal > 0 && $monedaXml === 'USD') {
            if ($tipoCambio > 1) {
                $monto = round($montoOriginal * $tipoCambio, 2);
            } else {
                $moneda = 'USD';
            }
        }
 
        return [
            'monto'     => (float) $monto,
            'moneda'    => $moneda,
            'fecha'     => $fecha ?: now()->format('Y-m-d'),
            'proveedor' => $proveedor,
        ];
    }

    /**
     * Determina si una factura pertenece a Logipuerto.
     * REGLA DE NEGOCIO: Logipuerto SIEMPRE viene en la carpeta de PROVEEDORES.
     * 1. Si no es de proveedores, se descarta de inmediato.
     * 2. Revisa el nombre del archivo (barato, sin descargar nada).
     * 3. Si el nombre no lo dice, lee el emisor del XML.
     */
    private function palabrasClaveLogipuerto(): array
    {
        return [
            'LOGIPUERTO',
            'LOGI PUERTO',
            'LOGI-PUERTO',
        ];
    }
 
    /**
     * Determina si una factura pertenece a Logipuerto.
     * REGLA DE NEGOCIO: Logipuerto SIEMPRE viene en la carpeta de PROVEEDORES.
     * 1. Si no es de proveedores, se descarta de inmediato.
     * 2. Revisa el nombre del archivo (barato, sin descargar nada).
     * 3. Si el nombre no lo dice, lee el emisor del XML.
     */
    private function esFacturaLogipuerto(string $llaveGrupo, array $factura): bool
    {
        $tipo = strtolower($factura['tipo_documento'] ?? '');
        if ($tipo !== 'proveedores') {
            return false;
        }
 
        $palabras = $this->palabrasClaveLogipuerto();
 
        $nombres = strtoupper(
            $llaveGrupo . ' ' .
            basename(parse_url($factura['ruta_pdf'] ?? '', PHP_URL_PATH) ?? '') . ' ' .
            basename(parse_url($factura['ruta_xml'] ?? '', PHP_URL_PATH) ?? '')
        );
 
        foreach ($palabras as $palabra) {
            if (str_contains($nombres, $palabra)) {
                return true;
            }
        }
 
        if (!empty($factura['ruta_xml'])) {
            try {
                $xmlData = $this->parsearXmlFlete($factura['ruta_xml']);
                $emisor = strtoupper($xmlData['emisor'] ?? '');
 
                foreach ($palabras as $palabra) {
                    if ($emisor !== '' && str_contains($emisor, $palabra)) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Logipuerto: no se pudo leer el emisor del XML {$factura['ruta_xml']}: " . $e->getMessage());
            }
        }
 
        return false;
    }
 
    /**
     * Crea un mapa [pedimento => lista de facturas] EXCLUSIVO para Logipuerto.
     * Solo revisa la carpeta de PROVEEDORES (regla de negocio).
     */
    private function construirIndiceOperacionesLogipuerto(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
 
        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }
 
                $facturas = $datos['facturas'] ?? [];
 
                foreach ($facturas as $llaveGrupo => $factura) {
                    $tipo = strtolower($factura['tipo_documento'] ?? '');
 
                    // Logipuerto SIEMPRE está en proveedores
                    if ($tipo !== 'proveedores') {
                        continue;
                    }
 
                    if (empty($factura['ruta_pdf']) && empty($factura['ruta_xml'])) {
                        continue;
                    }
 
                    if (!$this->esFacturaLogipuerto((string) $llaveGrupo, $factura)) {
                        continue;
                    }
 
                    $indice[$pedimento][] = [
                        'folio'    => $factura['folio'] ?? $llaveGrupo,
                        'ruta_xml' => $factura['ruta_xml'] ?? null,
                        'ruta_pdf' => $factura['ruta_pdf'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de Logipuerto: " . $e->getMessage());
        }
 
        return $indice;
    }
 
    /**
     * Envía a Google Sheets (hoja ZLO) las facturas de Logipuerto por pedimento.
     * Concepto en el Sheet: "Logipuerto" (y "Logipuerto 2", "Logipuerto 3"... si hay varias facturas distintas).
     */
    public function enviarAGPCLogipuerto($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);
 
        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }
 
        if (strtoupper($tarea->banco ?? '') !== 'SANTANDER' && strtoupper($tarea->sucursal ?? '') !== 'ZLO') {
            return ['code' => 0, 'message' => 'Omitido (Logipuerto solo aplica a ZLO)'];
        }
 
        Log::info("Tarea #{$tarea->id}: Iniciando envío de Logipuerto a GPC...");
 
        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal.")];
            }
 
            $mapeadoFacturas = (array) json_decode(Storage::get($rutaMapeo), true);
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);
 
            // Tipos de cambio por pedimento (desde la SC si existe) para convertir facturas en USD
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'] ?? [];
            $indiceTiposCambio = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos'])
                    ? $auditoria['desglose_conceptos']
                    : json_decode($auditoria['desglose_conceptos'], true);
                $indiceTiposCambio[$auditoria['pedimento_id']] = (float) ($desglose['tipo_cambio'] ?? 1.0);
            }
 
            $indiceLogipuerto = $this->construirIndiceOperacionesLogipuerto($indicesOperaciones);
            Log::info("Logipuerto: pedimentos con facturas detectadas: " . count($indiceLogipuerto));
 
            $logipuertoParaSheets = [];
 
            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $pedimentoSucio = trim($datosId['num_pedimiento'] ?? '');
                $listaFacturas = $indiceLogipuerto[$pedimentoSucio] ?? $indiceLogipuerto[$pedimentoLimpio] ?? [];
 
                if (empty($listaFacturas)) {
                    continue;
                }
 
                $opId = $datosId['id_operacion'] ?? null;
                $tipoOp = (($datosId['tipo'] ?? '') === 'Importacion') ? Importacion::class : Exportacion::class;
 
                $queryOp = null;
                try {
                    if ($opId) {
                        $queryOp = ($tipoOp === Importacion::class)
                            ? Importacion::with('cliente')->find($opId)
                            : Exportacion::with('cliente')->find($opId);
                    }
                } catch (\Throwable $e) {
                    Log::error("Error consultando BD (Logipuerto) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                    $queryOp = null;
                }
 
                $filasTemp = [];
                $montosRegistrados = [];
 
                foreach ($listaFacturas as $factura) {
                    $montoOriginal = -1;
                    $monedaXml = 'MXN';
                    $proveedor = '';
                    $fechaFactura = null;
 
                    // 1. XML (prioridad): monto, moneda, fecha y PROVEEDOR (Emisor Nombre) en una sola lectura
                    if (!empty($factura['ruta_xml'])) {
                        try {
                            $xmlData = $this->parsearXmlFlete($factura['ruta_xml']);
                            if ($xmlData) {
                                $proveedor = trim($xmlData['emisor'] ?? '');
                                $fechaFactura = $xmlData['fecha'] ?? null;
                                if (($xmlData['total'] ?? -1) != -1) {
                                    $montoOriginal = (float) $xmlData['total'];
                                    $monedaXml = strtoupper($xmlData['moneda'] ?? 'MXN');
                                }
                            }
                        } catch (\Throwable $e) {
                            Log::error("Error leyendo XML (Logipuerto) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                        }
                    }
 
                    // 2. Respaldo: si el XML no trajo monto, intentamos con el PDF
                    if ($montoOriginal <= 0 && !empty($factura['ruta_pdf'])) {
                        try {
                            $pdfData = $this->extraerTotalDesdePdfProveedor($factura['ruta_pdf']);
                            if ($pdfData !== null && $pdfData['monto'] !== null) {
                                $montoOriginal = (float) $pdfData['monto'];
                                if (!empty($pdfData['fecha'])) {
                                    $fechaFactura = $pdfData['fecha'];
                                }
                            }
                        } catch (\Throwable $e) {
                            Log::error("Error leyendo PDF (Logipuerto) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                        }
                    }
 
                    if ($montoOriginal <= 0) {
                        Log::warning("Logipuerto: factura sin monto legible en pedimento {$pedimentoLimpio} | PDF: " . ($factura['ruta_pdf'] ?? 'N/A') . " | XML: " . ($factura['ruta_xml'] ?? 'N/A'));
                        continue;
                    }
 
                    // Si el XML no trae emisor (o solo hay PDF), dejamos LOGIPUERTO como respaldo
                    if ($proveedor === '') {
                        $proveedor = 'LOGIPUERTO';
                    }
 
                    $montoMXN = $montoOriginal;
                    $moneda = 'MXN';
 
                    // Conversión a MXN si el XML viene en dólares
                    if ($monedaXml === 'USD') {
                        $tipoCambio = $indiceTiposCambio[$datosId['id_pedimiento']] ?? 1.0;
                        if ($tipoCambio > 1) {
                            $montoMXN = round($montoOriginal * $tipoCambio, 2);
                        } else {
                            // Sin tipo de cambio confiable: se envía en USD tal cual
                            $moneda = 'USD';
                        }
                    }
 
                    // Anti-duplicados: el mismo monto en el mismo pedimento se envía una sola vez
                    $montoKey = (string) round($montoMXN, 2);
                    if (in_array($montoKey, $montosRegistrados)) {
                        continue;
                    }
                    $montosRegistrados[] = $montoKey;
 
                    $indexActual = count($filasTemp);
                    $conceptoNombre = ($indexActual === 0) ? 'Logipuerto' : 'Logipuerto ' . ($indexActual + 1);
 
                    $filasTemp[] = [
                        'fecha'      => $fechaFactura ?: now()->format('Y-m-d'),
                        'cliente'    => $queryOp ? (optional($queryOp->cliente)->nombre ?? '') : '',
                        'contenedor' => $queryOp ? ($queryOp->contenedor ?? '') : '',
                        'bl'         => $queryOp ? ($queryOp->bol ?? '') : '',
                        'pedimento'  => $pedimentoLimpio,
                        'concepto'   => $conceptoNombre,
                        'monto'      => (float) round($montoMXN, 2),
                        'moneda'     => $moneda,
                        'naviera'    => $proveedor // Emisor del CFDI (columna G del Sheet)
                    ];
                }
 
                foreach ($filasTemp as $fila) {
                    $logipuertoParaSheets[] = $fila;
                }
            }
 
            if (!empty($logipuertoParaSheets)) {
                Log::info("Logipuerto: registros listos para enviar a GPC: " . count($logipuertoParaSheets));
                $paquetes = array_chunk($logipuertoParaSheets, 50);
 
                foreach ($paquetes as $idx => $paquete) {
                    $esUltimo = ($idx === count($paquetes) - 1);
                    try {
                        $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                        sleep(2);
                    } catch (\Throwable $e) {
                        Log::error("Error enviando Logipuerto a Sheets (Paquete " . ($idx + 1) . "): " . $e->getMessage());
                    }
                }
                Log::info("¡Envío de Logipuerto completado!");
            } else {
                Log::info("Logipuerto: no se encontraron facturas con monto para enviar.");
            }
 
            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error General en Logipuerto: " . $e->getMessage() . " en la línea " . $e->getLine());
            return ['code' => 1, 'message' => $e];
        }
    }

    /**
     * Lee los archivos y crea un mapa EXCLUSIVO para Almacenaje.
     */
    private function construirIndiceOperacionesAlmacenaje(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
        $palabrasLogipuerto = array_map('strtolower', $this->palabrasClaveLogipuerto());
 
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
                            'nombre_archivo_real' => strtolower($filename)
                        ];
                    }
 
                    if (!empty($factura['ruta_pdf'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_pdf'] = $factura['ruta_pdf'];
                    }
                    if (!empty($factura['ruta_xml'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_xml'] = $factura['ruta_xml'];
                    }
                }
 
                $facturasAlmacenaje = collect(array_values($facturasAgrupadas))->filter(function ($factura) use ($palabrasLogipuerto) {
                    $tipo = $factura['tipo_documento'] ?? '';
                    $textoParaBuscar = strtolower(($factura['folio'] ?? '') . ' ' . ($factura['nombre_archivo_real'] ?? ''));
 
                    $esCarpetaValida = str_contains($tipo, 'proveedor') || str_contains($tipo, 'terminal');
                    $esAlmacenaje = str_contains($textoParaBuscar, 'almacen') || str_contains($textoParaBuscar, 'storage');
 
                    $esExcluido = str_contains($textoParaBuscar, 'accesa') ||
                                  str_contains($textoParaBuscar, 'accessa') ||
                                  str_contains($textoParaBuscar, 'reacomodo') ||
                                  str_contains($textoParaBuscar, 'limpieza') ||
                                  str_contains($textoParaBuscar, 'lavado') ||
                                  str_contains($textoParaBuscar, 'conexion') ||
                                  str_contains($textoParaBuscar, 'reconocimiento');
 
                    foreach ($palabrasLogipuerto as $palabra) {
                        if (str_contains($textoParaBuscar, $palabra)) {
                            $esExcluido = true;
                            break;
                        }
                    }
 
                    return $esCarpetaValida && $esAlmacenaje && !$esExcluido && !empty($factura['ruta_pdf']);
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

    public function enviarAGPCTotalAlmacenaje($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);
        
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
                
                try {
                    $queryOp = ($tipoOp === Importacion::class) ? Importacion::with('cliente')->find($opId) : Exportacion::with('cliente')->find($opId);
                } catch (\Throwable $e) {
                    Log::error("Error consultando BD (Almacenaje) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                    $queryOp = null;
                }

                $filasTemp = [];
                $montosRegistrados = [];

                foreach ($listaFacturasAlmacen as $factura) {
                    try {
                        $info = $this->extraerMontoYNaviera($factura['ruta_xml'] ?? null, $factura['ruta_pdf'] ?? null);
                    } catch (\Throwable $e) {
                        Log::error("Error extracción (Almacenaje) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                        continue;
                    }

                    if ($info['monto'] > 0) {
                        $tipoCambio = $indiceTiposCambio[$datosId['id_pedimiento']] ?? 1.0;
                        $montoMXN = $info['monto'];
                        
                        try {
                            if (!empty($factura['ruta_xml'])) {
                                $xmlData = $this->parsearXmlFlete($factura['ruta_xml']);
                                if ($xmlData && strtoupper($xmlData['moneda']) === 'USD') {
                                    $montoMXN = round($info['monto'] * $tipoCambio, 2);
                                }
                            }
                        } catch (\Throwable $e) {
                            Log::error("Error parseando moneda en XML (Almacenaje) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                        }

                        try {
                            $numeroDeContenedores = $this->contarContenedoresUniversal($factura['ruta_xml'] ?? null, $factura['ruta_pdf'] ?? null);
                            $montoDivididoMXN = round($montoMXN / $numeroDeContenedores, 2);
                        } catch (\Throwable $e) {
                            Log::error("Error contando contenedores (Almacenaje) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                            $montoDivididoMXN = $montoMXN; 
                        }

                        $montoKey = (string)$montoDivididoMXN;
                        if (in_array($montoKey, $montosRegistrados)) {
                            continue;
                        }
                        $montosRegistrados[] = $montoKey;

                        $indexActual = count($filasTemp);
                        
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

                if (!empty($filasTemp)) {
                    $sumaConsolidada = 0;
                    foreach ($filasTemp as $fila) {
                        $sumaConsolidada += $fila['monto'];
                    }
                    
                    $filaFinal = $filasTemp[0];
                    $filaFinal['monto'] = $sumaConsolidada;
                    $filaFinal['concepto'] = 'Almacen'; 
                    
                    $almacenajeParaSheets[] = $filaFinal;
                }
            }

            if (!empty($almacenajeParaSheets)) {
                $paquetes = array_chunk($almacenajeParaSheets, 50);
                foreach ($paquetes as $idx => $paquete) {
                    $esUltimo = ($idx === count($paquetes) - 1);
                    try {
                        $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                        sleep(2);
                    } catch (\Throwable $e) {
                        Log::error("Error envío Almacenaje a Sheets (Paquete " . ($idx + 1) . "): " . $e->getMessage());
                    }
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
    public function enviarAGPCFacturasDeAlmacen($tareaId, ?array $soloContenedores = null)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);

        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Extrayendo ALMAN (Cruce de Información con ZLO activado y forzado)...");

        try {
            // ====================================================================
            // 0. OBTENER ÚNICAMENTE LOS PEDIMENTOS Y CONTENEDORES DE ESTA TAREA
            // ====================================================================
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentosDeLaTarea = $pedimentosJson ? json_decode($pedimentosJson, true) : [];
            $pedimentosAuditoria = [];

            foreach ($pedimentosDeLaTarea as $p) {
                if (preg_match('/[4-7]\d{6}/', (string) $p, $m)) {
                    $pedimentosAuditoria[] = $m[0];
                }
            }

            if (empty($pedimentosAuditoria) && empty($soloContenedores)) {
                Log::info("Tarea #{$tarea->id}: No hay pedimentos para auditar en esta tarea. Se omite ALMAN.");
                return ['code' => 0, 'message' => 'completado'];
            }

            if (empty($soloContenedores)) {
                $soloContenedores = $this->contenedoresDePedimentos($pedimentosAuditoria);
            }

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
                if ($idxFolio !== -1 && $idxContenedor !== -1 && $idxTotal !== -1) {
                    break;
                }
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

                    // --- FILTRO: PROCESAR ÚNICAMENTE SI PERTENECE A LA TAREA ACTUAL ---
                    $esDeLaTarea = false;

                    if (!empty($pedimentoLimpio) && in_array($pedimentoLimpio, $pedimentosAuditoria, true)) {
                        $esDeLaTarea = true;
                    }

                    if (!$esDeLaTarea && !empty($soloContenedores)) {
                        foreach ($contenedoresAExtraer as $cont) {
                            if (in_array($cont, $soloContenedores, true)) {
                                $esDeLaTarea = true;
                                break;
                            }
                        }
                    }

                    if (!$esDeLaTarea) {
                        continue;
                    }

                    if (count($contenedoresAExtraer) > 0) {
                        $montoDividido = round($montoLimpio / count($contenedoresAExtraer), 2);
                        foreach ($contenedoresAExtraer as $cont) {
                            // Botón "Llenar GPC": solo los contenedores de la operación
                            if (!empty($soloContenedores) && !in_array($cont, $soloContenedores, true)) {
                                continue;
                            }
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
        if ($this->modoVistaPrevia) {
            $this->registrarEnvioVistaPrevia('alman', 'ZLO', $datosParaSheets, $esUltimo);
            return;
        }

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
            $resultado = $this->postAppsScript($almacenWebhookUrl, $payload, 180);
            if (!$resultado['ok']) {
                Log::warning("ALMAN: Google Sheets no devolvió su respuesta JSON; revisa la hoja. " . $resultado['detalle']);
            } elseif (($resultado['json']['status'] ?? '') === 'error') {
                Log::error("ALMAN: Google Sheets Error Interno: " . ($resultado['json']['message'] ?? 'sin mensaje'));
            }
        } catch (\Throwable $e) {
            Log::error("Error enviando datos al specialized webhook de Almacén: " . $e->getMessage());
        }
    }

    /**
     * Extrae Traslado Local desde la hoja EXTRAS.
     * Corregido: Error de count() y blindaje de tipos de datos.
     */
    public function enviarAGPCTrasladoLocal($tareaId, ?array $soloContenedores = null)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);

        if (!$tarea || $tarea->status !== 'procesando') {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        Log::info("Tarea #{$tarea->id}: Procesando Traslado Local (Motor CSV a prueba de saltos de línea)...");

        try {
            // ====================================================================
            // 0. OBTENER CONTENEDORES EXCLUSIVOS DE ESTA TAREA SI NO VIENEN DADOS
            // ====================================================================
            if (empty($soloContenedores)) {
                $pedimentosJson = $tarea->pedimentos_procesados;
                $pedimentosDeLaTarea = $pedimentosJson ? json_decode($pedimentosJson, true) : [];
                $pedimentosAuditoria = [];

                foreach ($pedimentosDeLaTarea as $p) {
                    if (preg_match('/[4-7]\d{6}/', (string) $p, $m)) {
                        $pedimentosAuditoria[] = $m[0];
                    }
                }

                if (empty($pedimentosAuditoria)) {
                    Log::info("Tarea #{$tarea->id}: No hay pedimentos para Traslado Local en esta tarea. Se omite.");
                    return ['code' => 0, 'message' => 'completado'];
                }

                // Solo consulta las tablas que sí tienen columna de contenedor
                $soloContenedores = $this->contenedoresDePedimentos($pedimentosAuditoria);

                if (empty($soloContenedores)) {
                    Log::info("Tarea #{$tarea->id}: Las operaciones de esta tarea no tienen contenedores registrados. Se omite Traslado Local.");
                    return ['code' => 0, 'message' => 'completado'];
                }
            }

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
                            $limpio = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cont));
                            $bloques[$idxBloqueActivo]['contenedores'][] = $limpio;
                        }
                    } else {
                        $posiblesContenedores = preg_split('/[\s,\n\r]+/', $contenedorCelda);
                        foreach ($posiblesContenedores as $posible) {
                            $limpio = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $posible));
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
                        if (!empty($soloContenedores) && !in_array($cont, $soloContenedores, true)) {
                            continue;
                        }
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
            $contenedoresUnicosArray = array_values(array_unique($contenedoresUnicos));
            $impos = collect();

            $chunks = array_chunk($contenedoresUnicosArray, 50);
            foreach ($chunks as $chunk) {
                $resultados = Importacion::where(function ($q) use ($chunk) {
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
                        $numPed = $pedimentosReales[$op->id_pedimiento] ?? (string) $op->id_pedimiento;

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

            $paquetes = array_chunk($payloadParaSheets, 10);
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
        if ($this->modoVistaPrevia) {
            $this->registrarEnvioVistaPrevia('traslado', 'ZLO', $datosParaSheets, $esUltimo);
            return;
        }

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
            $resultado = $this->postAppsScript($webhookUrl, $payload, 180);
            if (!$resultado['ok']) {
                Log::warning("Traslado Local: Google Sheets no devolvió su respuesta JSON; revisa la hoja. " . $resultado['detalle']);
            } elseif (($resultado['json']['status'] ?? '') === 'error') {
                Log::error("Traslado Local: Google Sheets Error Interno: " . ($resultado['json']['message'] ?? 'sin mensaje'));
            }
        } catch (\Throwable $e) {
            Log::error("Error enviando datos al specialized webhook de Traslado Local: " . $e->getMessage());
        }
    }

    public function llenarGpcDirecto(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'pedimento' => 'required'
        ]);
 
        try {
            $opId = $request->input('id');
            $tipoOpRaw = strtolower($request->input('tipo_operacion', ''));
            $tipoApi = str_contains($tipoOpRaw, 'import') ? 'importaciones' : 'exportaciones';
 
            $operacion = ($tipoApi === 'importaciones')
                ? Importacion::with('cliente')->find($opId)
                : Exportacion::with('cliente')->find($opId);
 
            if (!$operacion) {
                return response()->json(['code' => 1, 'message' => 'Operación no encontrada en la BD.']);
            }
 
            $sucursalCod = $this->codigoSucursalPorId($operacion->sucursal);
 
            // MANZANILLO: formato ZLO (tu método actual, renombrado)
            if ($sucursalCod === 'ZLO') {
                return $this->llenarGpcDirectoZLO($request);
            }
 
            // DEMÁS SUCURSALES: formato multi-sucursal
            if (!$sucursalCod || !$this->getHojaDestino($sucursalCod, Importacion::class)) {
                return response()->json([
                    'code' => 1,
                    'message' => "La sucursal de esta operación (" . ($sucursalCod ?? $operacion->sucursal) . ") no tiene un GPC configurado."
                ]);
            }
 
            return $this->llenarGpcDirectoMultiSucursal($operacion, $tipoApi, $sucursalCod, (string) $request->input('pedimento'));
 
        } catch (\Throwable $e) {
            Log::error("Error en llenarGpcDirecto: " . $e->getMessage() . " Línea: " . $e->getLine());
            return response()->json([
                'code' => 1,
                'message' => "Ocurrió un error en el servidor al intentar llenar el GPC: " . $e->getMessage()
            ], 500);
        }
    }

    private function llenarGpcDirectoZLO(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'pedimento' => 'required'
        ]);
 
        $rutaMapeo = null;
 
        try {
            $opId = $request->input('id');
            $pedimentoRecibido = (string) $request->input('pedimento');
            $tipoOpRaw = strtolower($request->input('tipo_operacion', ''));
            $tipoApi = str_contains($tipoOpRaw, 'import') ? 'importaciones' : 'exportaciones';
            $tipoOpStr = ($tipoApi === 'importaciones') ? 'Importacion' : 'Exportacion';
 
            $operacion = ($tipoApi === 'importaciones')
                ? Importacion::with('cliente')->find($opId)
                : Exportacion::with('cliente')->find($opId);
 
            if (!$operacion) {
                return response()->json(['code' => 1, 'message' => 'Operación no encontrada en la BD.']);
            }

            $patenteZLO  = '3711';
            $sucursalZLO = 5;

            // =========================================================
            // 1. PEDIMENTO CON LAS MISMAS REGLAS QUE EL COMANDO
            //    construirMapaDePedimentos() junta TODOS los registros del pedimento
            //    (all_ids) y elige el mismo registro "ancla" que usa el comando.
            // =========================================================
            $pedimentoBD = Pedimento::where('id_pedimiento', $operacion->id_pedimiento)->first();
            $numPedimentoOp = trim($pedimentoBD->num_pedimiento ?? $pedimentoRecibido);

            preg_match('/[4-7]\d{6}/', $numPedimentoOp . ' ' . $pedimentoRecibido, $mPed);
            $pedimentoLimpio = $mPed[0] ?? trim($pedimentoRecibido);

            $mapaComando = $this->construirMapaDePedimentos([$pedimentoLimpio], $patenteZLO, $sucursalZLO);
            $datosId = $mapaComando[$pedimentoLimpio] ?? null;

            // Respaldo: si el comando no lo encontraría (ej. pedimento de hace más de un año), usamos la operación del clic
            if (!$datosId || empty($datosId['id_pedimiento'])) {
                Log::warning("GPC Directo ZLO: construirMapaDePedimentos no encontró {$pedimentoLimpio}; se usa la operación seleccionada.");
                $datosId = [
                    'id_pedimiento'  => $operacion->id_pedimiento,
                    'id_operacion'   => $operacion->getKey(),
                    'num_pedimiento' => $numPedimentoOp,
                    'tipo'           => $tipoOpStr,
                    'es_recti'       => str_contains(strtoupper($numPedimentoOp), 'R1-'),
                    'all_ids'        => [$operacion->id_pedimiento],
                ];
            }

            $tipoMapa = in_array($datosId['tipo'] ?? '', ['Importacion', 'Exportacion'], true) ? $datosId['tipo'] : $tipoOpStr;
            $datosId['tipo'] = $tipoMapa;
            $numPedimento = trim($datosId['num_pedimiento'] ?? $numPedimentoOp);
            $allIds = !empty($datosId['all_ids']) ? array_values(array_unique($datosId['all_ids'])) : [$datosId['id_pedimiento']];
            $datosId['all_ids'] = $allIds;

            // =========================================================
            // 2. OPERACIÓN DE LA QUE SE LEEN LOS ARCHIVOS (misma regla que el comando)
            //    Importación: la más reciente SIN parent. Exportación: la más reciente.
            // =========================================================
            if ($tipoMapa === 'Importacion') {
                $idOperacionMapa = Importacion::where('patente', $patenteZLO)
                    ->where('sucursal', $sucursalZLO)
                    ->where('id_pedimiento', $datosId['id_pedimiento'])
                    ->whereNull('parent')
                    ->max('id_importacion');
            } else {
                $idOperacionMapa = Exportacion::where('patente', $patenteZLO)
                    ->where('sucursal', $sucursalZLO)
                    ->where('id_pedimiento', $datosId['id_pedimiento'])
                    ->max('id_exportacion');
            }
            $idOperacionMapa = $idOperacionMapa ?: ($datosId['id_operacion'] ?? null) ?: $operacion->getKey();

            Log::info("GPC Directo ZLO: pedimento {$pedimentoLimpio} | ancla id_pedimiento {$datosId['id_pedimiento']} ({$numPedimento}) | all_ids [" . implode(', ', $allIds) . "] | operación de archivos {$tipoMapa} #{$idOperacionMapa} | clic en #{$operacion->getKey()}");

            // =========================================================
            // 3. MAPEO (misma estructura que el del comando)
            // =========================================================
            $indices = $this->construirIndiceFacturasParaMapeo(
                collect([$numPedimento => ['id_operacion' => $idOperacionMapa, 'tipo' => $tipoMapa]]),
                'ZLO'
            );

            // Alias por los 7 dígitos: algunos índices (Vacíos, Almacenaje) buscan solo por esa llave
            if ($numPedimento !== $pedimentoLimpio && isset($indices[$numPedimento])) {
                $indices[$pedimentoLimpio] = $indices[$numPedimento];
            }

            $registroOperacion = [
                $numPedimento => [
                    'pedimento'    => $numPedimento,
                    'id_operacion' => $idOperacionMapa,
                    'id_pedimento' => $datosId['id_pedimiento'],
                    'tipo'         => $tipoMapa,
                ]
            ];

            $esImpo = ($tipoMapa === 'Importacion');

            $mapeo = [
                'pedimentos_totales'        => [$pedimentoLimpio => $datosId],
                'pedimentos_no_encontrados' => [],
                'pedimentos_importacion'    => $esImpo ? $registroOperacion : [],
                'pedimentos_exportacion'    => $esImpo ? [] : $registroOperacion,
                'indices_importacion'       => $esImpo ? $indices : [],
                'indices_exportacion'       => $esImpo ? [] : $indices,
                'auditorias_sc'             => AuditoriaTotalSC::whereIn('pedimento_id', $allIds)
                                                ->get()
                                                ->keyBy('pedimento_id')
                                                ->toArray(),
            ];
 
            $rutaMapeo = 'mapeo_completo_facturas/gpc_directo_' . Str::random(20) . '.json';
            Storage::put($rutaMapeo, json_encode($mapeo));
 
            // =========================================================
            // 3. TAREA EN MEMORIA (NO se guarda en la BD)
            //    Los métodos del comando solo leen estos campos.
            // =========================================================
            $tareaDirecta = new AuditoriaTareas();
            $tareaDirecta->forceFill([
                'status'                  => 'procesando',
                'sucursal'                => 'ZLO',
                'banco'                   => 'SANTANDER',
                'periodo_meses'           => 12,
                'fecha_documento'         => now()->format('Y-m-d'),
                'mapeo_completo_facturas' => $rutaMapeo,
                'pedimentos_procesados'   => json_encode([$pedimentoLimpio]),
            ]);

            // Contenedores (operación del clic + operación de archivos) para filtrar ALMAN y Traslado Local
            $textoContenedores = (string) ($operacion->contenedor ?? '');
            if ((int) $idOperacionMapa !== (int) $operacion->getKey()) {
                $opMapa = $esImpo ? Importacion::find($idOperacionMapa) : Exportacion::find($idOperacionMapa);
                $textoContenedores .= ' ' . ($opMapa->contenedor ?? '');
            }
            preg_match_all('/[A-Za-z]{4}[\s\-]*\d{7}/', $textoContenedores, $mConts);
            $contenedores = array_values(array_unique(array_map(function ($c) {
                return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $c));
            }, $mConts[0] ?? [])));
 
            // =========================================================
            // 4. MISMOS MÉTODOS Y MISMO ORDEN QUE EL COMANDO
            // =========================================================
            $pasos = [
                'Impuestos'                  => function () use ($tareaDirecta) { return $this->enviarAGPCImpuestos($tareaDirecta); },
                'Pagos de Derecho (lectura)' => function () use ($tareaDirecta) { return $this->auditarFacturasDePagosDeDerecho($tareaDirecta); },
                'Pagos de Derecho'           => function () use ($tareaDirecta) { return $this->enviarAGPCPagosDeDerecho($tareaDirecta); },
                'Maniobras en Terminal'      => function () use ($tareaDirecta) { return $this->enviarAGPCTerminales($tareaDirecta); },
                'Maniobras de Vacios'        => function () use ($tareaDirecta) { return $this->enviarAGPCVacios($tareaDirecta); },
                'Almacenaje'                 => function () use ($tareaDirecta) { return $this->enviarAGPCTotalAlmacenaje($tareaDirecta); },
                'Logipuerto'                 => function () use ($tareaDirecta) { return $this->enviarAGPCLogipuerto($tareaDirecta); },
                'Conceptos especiales'       => function () use ($tareaDirecta) { return $this->enviarAGPCConceptosExtraZLO($tareaDirecta); },
            ];
 
            // Sin contenedores NO se llaman: sin filtro procesarían todas las operaciones
            if (!empty($contenedores)) {
                $pasos['Almacen (ALMAN)'] = function () use ($tareaDirecta, $contenedores) { return $this->enviarAGPCFacturasDeAlmacen($tareaDirecta, $contenedores); };
                $pasos['Traslado Local']  = function () use ($tareaDirecta, $contenedores) { return $this->enviarAGPCTrasladoLocal($tareaDirecta, $contenedores); };
            }
 
            $fallas = [];
 
            foreach ($pasos as $etiqueta => $paso) {
                try {
                    $resultado = $paso();
                    if (($resultado['code'] ?? 1) > 0) {
                        $detalle = $resultado['message'] instanceof \Throwable ? $resultado['message']->getMessage() : (string) ($resultado['message'] ?? '');
                        $fallas[] = $etiqueta;
                        Log::error("GPC Directo ZLO ({$etiqueta}) pedimento {$pedimentoLimpio}: {$detalle}");
                    }
                } catch (\Throwable $e) {
                    $fallas[] = $etiqueta;
                    Log::error("GPC Directo ZLO ({$etiqueta}) pedimento {$pedimentoLimpio}: " . $e->getMessage() . " línea " . $e->getLine());
                }
            }
 
            $mensaje = "Pedimento {$pedimentoLimpio} enviado al GPC de Manzanillo.";
            if (empty($contenedores)) {
                $mensaje .= " La operación no tiene contenedor registrado, se omitieron ALMAN y Traslado Local.";
            }
            if (!empty($fallas)) {
                $mensaje .= " No se pudieron completar: " . implode(', ', $fallas) . " (revisa el log).";
            }
 
            return response()->json([
                'code'    => (count($fallas) === count($pasos)) ? 1 : 0,
                'message' => $mensaje
            ]);
 
        } catch (\Throwable $e) {
            Log::error("Error en llenarGpcDirectoZLO: " . $e->getMessage() . " Línea: " . $e->getLine());
            return response()->json([
                'code' => 1,
                'message' => "Ocurrió un error en el servidor al intentar llenar el GPC: " . $e->getMessage()
            ], 500);
        } finally {
            // El mapeo temporal se borra siempre
            if ($rutaMapeo && Storage::exists($rutaMapeo)) {
                Storage::delete($rutaMapeo);
            }
        }
    }

    private $modoVistaPrevia = false;
    private $enviosVistaPrevia = [];

    public function vistaPreviaGpcDirecto(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'pedimento' => 'required'
        ]);
 
        $this->modoVistaPrevia = true;
        $this->enviosVistaPrevia = [];
 
        try {
            $respuesta = $this->llenarGpcDirecto($request);
        } finally {
            $this->modoVistaPrevia = false;
        }
 
        $resultado = $respuesta->getData(true);
 
        // Avisos del proceso (pasos que fallaron, operación sin contenedor...)
        $avisos = [];
        foreach (preg_split('/(?<=\.)\s+/', (string) ($resultado['message'] ?? '')) as $frase) {
            if (preg_match('/No se pudieron completar|no tiene contenedor|se omitieron|no tiene un GPC/i', $frase)) {
                $avisos[] = trim($frase);
            }
        }
 
        if (empty($this->enviosVistaPrevia)) {
            $mensaje = (($resultado['code'] ?? 1) > 0)
                ? ($resultado['message'] ?? 'No se pudo armar la información del GPC.')
                : 'No se encontraron conceptos con monto para este pedimento. Revisa que el expediente tenga sus facturas.';
 
            return response()->json([
                'code'    => 1,
                'message' => $mensaje,
                'avisos'  => $avisos,
                'filas'   => [],
            ]);
        }
 
        // Filas en un formato común para la tabla del front-end
        $filas = [];
        foreach ($this->enviosVistaPrevia as $envio) {
            foreach ($envio['datos'] as $fila) {
                $filas[] = $this->normalizarFilaVistaPrevia($envio['canal'], $envio['hoja'], $fila);
            }
        }
 
        // Totales por moneda (solo renglones con monto)
        $totales = [];
        $conMonto = 0;
        foreach ($filas as $fila) {
            if ($fila['monto'] !== null && $fila['monto'] > 0) {
                $conMonto++;
                $moneda = $fila['moneda'] ?: 'MXN';
                $totales[$moneda] = round(($totales[$moneda] ?? 0) + $fila['monto'], 2);
            }
        }
 
        // Lo que se va a enviar se guarda tal cual: lo que el usuario revisa es lo que se envía
        $token = Str::random(40);
        Cache::put('gpc_vista_previa_' . $token, [
            'envios'    => $this->enviosVistaPrevia,
            'pedimento' => (string) $request->input('pedimento'),
            'usuario'   => auth()->id(),
            'creado'    => now()->toDateTimeString(),
        ], now()->addMinutes(30));
 
        return response()->json([
            'code'    => 0,
            'token'   => $token,
            'expira'  => now()->addMinutes(30)->toDateTimeString(),
            'avisos'  => $avisos,
            'filas'   => $filas,
            'resumen' => [
                'registros' => count($filas),
                'con_monto' => $conMonto,
                'totales'   => $totales,
            ],
        ]);
    }
 
    /**
     * POST /auditoria/llenar-gpc-directo/confirmar
     * Envía a Google Sheets los registros de una vista previa ya revisada (por su token).
     * El token se usa una sola vez: así no se puede enviar dos veces la misma vista previa.
     */
    public function confirmarGpcDirecto(Request $request)
    {
        $request->validate(['token' => 'required|string']);
 
        $paquete = Cache::pull('gpc_vista_previa_' . $request->input('token'));
 
        if (!$paquete) {
            return response()->json([
                'code'    => 1,
                'message' => 'La vista previa ya no está disponible (expiró o ya se envió). Vuelve a presionar "Llenar GPC".'
            ], 410);
        }
 
        if (!empty($paquete['usuario']) && $paquete['usuario'] !== auth()->id()) {
            return response()->json(['code' => 1, 'message' => 'Esta vista previa la generó otro usuario.'], 403);
        }
 
        $registros = 0;
        $fallasMulti = 0;
 
        try {
            foreach ($paquete['envios'] as $envio) {
                $registros += count($envio['datos']);
 
                switch ($envio['canal']) {
                    case 'pedimentos':
                        $this->enviarDatosAGoogleSheets($envio['datos'], $envio['hoja'], $envio['sucursal'] ?? 'ZLO', $envio['es_ultimo']);
                        break;
                    case 'alman':
                        $this->enviarDatosAlmacenSpecializedWebhook($envio['datos'], $envio['es_ultimo']);
                        break;
                    case 'traslado':
                        $this->enviarDatosTrasladoLocalSpecializedWebhook($envio['datos'], $envio['es_ultimo']);
                        break;
                    case 'multi':
                        if (!$this->enviarDatosAGoogleSheetsMulti($envio['datos'], $envio['hoja'], $envio['es_ultimo'])) {
                            $fallasMulti++;
                        }
                        break;
                }
            }
        } catch (\Throwable $e) {
            Log::error("Confirmar GPC ped {$paquete['pedimento']}: " . $e->getMessage() . " línea " . $e->getLine());
            return response()->json([
                'code'    => 1,
                'message' => 'El envío se interrumpió: ' . $e->getMessage() . '. Revisa el GPC antes de volver a intentarlo.'
            ], 500);
        }
 
        if ($fallasMulti > 0) {
            return response()->json([
                'code'    => 1,
                'message' => "Google Sheets no confirmó {$fallasMulti} de los envíos del pedimento {$paquete['pedimento']}. Revisa el log."
            ]);
        }
 
        Log::info("Confirmar GPC: pedimento {$paquete['pedimento']} enviado ({$registros} registros) por el usuario " . (auth()->id() ?? 'N/A'));
 
        return response()->json([
            'code'    => 0,
            'message' => "Se enviaron {$registros} registros del pedimento {$paquete['pedimento']} al GPC."
        ]);
    }
 
    /**
     * Guarda en memoria lo que un método de envío habría mandado a Google Sheets.
     */
    private function registrarEnvioVistaPrevia(string $canal, string $hoja, array $datos, bool $esUltimo, string $sucursal = 'ZLO'): void
    {
        $this->enviosVistaPrevia[] = [
            'canal'     => $canal,
            'hoja'      => $hoja,
            'sucursal'  => $sucursal,
            'datos'     => array_values($datos),
            'es_ultimo' => $esUltimo,
        ];
    }
 
    /**
     * Convierte cualquier registro (ZLO, ALMAN, Traslado o multi-sucursal) al mismo formato
     * para la tabla de la vista previa.
     */
    private function normalizarFilaVistaPrevia(string $canal, string $hoja, array $fila): array
    {
        $origenes = [
            'pedimentos' => 'Conceptos del pedimento',
            'alman'      => 'Almacén (ALMAN)',
            'traslado'   => 'Traslado Local',
            'multi'      => 'Pestaña ' . $hoja,
        ];
 
        $montoCrudo = $fila['monto'] ?? null;
        $monto = ($montoCrudo === '' || $montoCrudo === null) ? null : round((float) $montoCrudo, 2);
 
        $montoLlcCrudo = $fila['monto_llc'] ?? null;
        $montoLlc = ($montoLlcCrudo === '' || $montoLlcCrudo === null) ? null : round((float) $montoLlcCrudo, 2);
 
        $concepto = $fila['concepto'] ?? $fila['pxcc'] ?? '';
        $proveedor = $fila['proveedor'] ?? $fila['naviera'] ?? '';
 
        return [
            'canal'       => $canal,
            'origen'      => $origenes[$canal] ?? $canal,
            'destino'     => ($canal === 'multi') ? $hoja : 'ZLO',
            'pedimento'   => (string) ($fila['pedimento'] ?? ''),
            'contenedor'  => (string) ($fila['contenedor'] ?? ''),
            'fecha'       => (string) ($fila['fecha'] ?? ''),
            'concepto'    => (string) $concepto,
            'proveedor'   => (string) $proveedor,
            'factura'     => (string) ($fila['factura_p'] ?? ''),
            'monto'       => $monto,
            'moneda'      => strtoupper((string) ($fila['moneda'] ?? '')),
            'monto_llc'   => $montoLlc,
            'factura_sc'  => (string) ($fila['factura_sc'] ?? ''),
            'estatus'     => (string) ($fila['estatus'] ?? ''),
        ];
    }

    private function codigoSucursalPorId($sucursalId): ?string
    {
        $diccionario = [
            1  => 'NOG', // NOGALES
            2  => 'TIJ', // TIJUANA
            3  => 'NL',  // LAREDO
            4  => 'MXL', // MEXICALI
            5  => 'ZLO', // MANZANILLO
            11 => 'REY', // REYNOSA
            12 => 'VRZ', // VERACRUZ
        ];
 
        return $diccionario[(int) $sucursalId] ?? null;
    }

    private function llenarGpcDirectoMultiSucursal($operacion, string $tipoApi, string $sucursalCod, string $pedimentoRecibido)
    {
        $tipoOpStr = ($tipoApi === 'importaciones') ? 'Importacion' : 'Exportacion';
        $opId = $operacion->getKey();
 
        $pedimentoBD = Pedimento::where('id_pedimiento', $operacion->id_pedimiento)->first();
        $numPedimento = trim($pedimentoBD->num_pedimiento ?? $pedimentoRecibido);
 
        preg_match('/[4-7]\d{6}/', $numPedimento . ' ' . $pedimentoRecibido, $mPed);
        $pedimentoLimpio = $mPed[0] ?? trim($pedimentoRecibido);
 
        $datosId = [
            'id_pedimiento'  => $operacion->id_pedimiento,
            'id_operacion'   => $opId,
            'num_pedimiento' => $numPedimento,
            'tipo'           => $tipoOpStr,
        ];
 
        // Archivos de la operación (y de su padre) con la misma estructura que el mapeo automático
        $indice = $this->construirIndiceFacturasParaMapeo(
            collect([$numPedimento => ['id_operacion' => $opId, 'tipo' => $tipoOpStr]]),
            $sucursalCod
        );
        $indiceOperacion = $indice[$numPedimento] ?? [
            'tipo_operacion' => $tipoApi,
            'operacion_id'   => $opId,
            'facturas'       => [],
        ];
 
        $indiceManiobras = ($sucursalCod === 'NL')
            ? $this->construirIndiceOperacionesManiobras([$numPedimento => $indiceOperacion])
            : [];
 
        $resultado = $this->construirFilasGPCMulti($sucursalCod, $pedimentoLimpio, $datosId, $indiceOperacion, $indiceManiobras, true);
 
        if (!$resultado['hoja'] || empty($resultado['filas'])) {
            return response()->json([
                'code' => 1,
                'message' => 'No se pudo construir la información del GPC para este pedimento.'
            ]);
        }
 
        $conceptosConMonto = array_values(array_unique(array_map(
            function ($fila) { return $fila['pxcc']; },
            array_filter($resultado['filas'], function ($fila) { return $fila['monto'] !== ''; })
        )));
 
        $exito = $this->enviarDatosAGoogleSheetsMulti($resultado['filas'], $resultado['hoja'], true);
 
        if (!$exito) {
            return response()->json([
                'code' => 1,
                'message' => "Google Sheets no confirmó el registro en la pestaña {$resultado['hoja']}. Revisa los logs."
            ]);
        }
 
        return response()->json([
            'code' => 0,
            'message' => "¡Éxito! Pedimento {$pedimentoLimpio} enviado a la pestaña {$resultado['hoja']}. Conceptos con monto: "
                . (empty($conceptosConMonto) ? 'ninguno' : implode(', ', $conceptosConMonto)) . '.'
        ]);
    }

    private function construirFilasGPCMulti(string $sucursalCod, string $pedimentoLimpio, array $datosId, array $indiceOperacion, array $indiceManiobras = [], bool $extraerDeArchivos = false): array
    {
        $tipoOpClass = (($datosId['tipo'] ?? '') === 'Importacion') ? Importacion::class : Exportacion::class;
        $hojaDestino = $this->getHojaDestino($sucursalCod, $tipoOpClass);

        if (!$hojaDestino) {
            return ['hoja' => null, 'filas' => []];
        }

        $filas = [];

        // --- Factura SC (BD primero, archivo como respaldo en modo manual) ---
        $scFisica = AuditoriaTotalSC::where('pedimento_id', $datosId['id_pedimiento'])
            ->where('operation_type', $tipoOpClass)
            ->first();
        $folioSC = $scFisica ? $scFisica->folio : '';

        $datosSCArchivo = null;
        if ($extraerDeArchivos) {
            $datosSCArchivo = $this->leerSCDesdeArchivos($pedimentoLimpio, $indiceOperacion);
            if ($folioSC === '' && !empty($datosSCArchivo['folio_sc'])) {
                $folioSC = $datosSCArchivo['folio_sc'];
            }
        }

        $queryOp = null;
        if (!empty($datosId['id_operacion'])) {
            $queryOp = ($tipoOpClass === Importacion::class)
                ? Importacion::with('cliente')->find($datosId['id_operacion'])
                : Exportacion::with('cliente')->find($datosId['id_operacion']);
        }

        $cliente = $queryOp ? (optional($queryOp->cliente)->nombre ?? '') : '';
        $navieraOp = $queryOp ? ($queryOp->naviera ?? 'N/A') : 'N/A';

        // Verificamos existencia de comprobante de muestras
        $pagoMuestraExiste = false;
        $facturasDelPedimento = $indiceOperacion['facturas'] ?? [];

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

        $tiposDocumentos = [
            'impuestos'    => 'IMPUESTOS',
            'pago_derecho' => 'ECI',
            'maniobras'    => 'MANIOBRAS',
            'flete'        => 'FLETE',
            'muestras'     => 'MUESTRAS',
            'llc'          => 'LLC'
        ];

        // Desglose de maniobras para NL a clientes específicos
        $clientesDesgloseNL = [
            'CENTRO ABARROTERO DEL BAJIO',
            'ALMACENADORA Y MAQUILAS',
            'ALMACENADORAS Y MAQUILA',
            'ALMACENADORAS Y MAQUILAS',
            'SURTIDORA DEL BAJIO'
        ];

        // Validador estricto para descartar nombres de archivo como folio
        $esFolioInvalido = function ($f) {
            $u = strtoupper(trim((string) $f));
            return empty($u)
                || in_array($u, ['-', 'S/F', 'PAGO-MUESTREO', 'PAGO-MUESTRA', 'PAGO MUESTRA', 'COMPROBANTE', 'TICKET'])
                || str_starts_with($u, 'UNK_')
                || str_starts_with($u, 'ARCHIVO_')
                || str_contains($u, 'PAGO-MUESTRA')
                || str_contains($u, 'PAGO_MUESTRA')
                || str_contains($u, '---')
                || strlen($u) > 25;
        };

        foreach ($tiposDocumentos as $tipoDoc => $pxccLabel) {
            $auditorias = Auditoria::where('pedimento_id', $datosId['id_pedimiento'])
                ->where('tipo_documento', $tipoDoc)
                ->where('monto_total', '>', 0)
                ->orderBy('fecha_documento', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            // Filtro Anti-Fantasmas: misma fecha y monto exactos
            if ($tipoDoc === 'impuestos') {
                $auditorias = $auditorias->unique(function ($aud) {
                    return $aud->fecha_documento . '_' . round((float) $aud->monto_total, 2);
                })->values();
            }

            // Modo manual: si la BD no tiene la auditoría, se lee de los archivos
            if ($auditorias->isEmpty()) {
                $auditorias = $this->extraerAuditoriasMultiDesdeArchivos($tipoDoc, $pedimentoLimpio, $indiceOperacion, $datosSCArchivo);
            }

            if ($auditorias->count() === 0) {
                // Sin monto = fila de plantilla vacía
                $filas[] = [
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
                continue;
            }

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
                    if ($sucursalCod === 'NOG') {
                        $proveedor = 'SAFINSA';
                    } elseif ($sucursalCod === 'NL') {
                        $proveedor = 'IFA';
                    }
                } elseif ($tipoDoc === 'llc') {
                    $proveedor = 'LLC';
                } elseif ($tipoDoc === 'muestras') {
                    $rutaXmlMuestra = $aud->ruta_xml ?? null;
                    $rutaPdfMuestra = $aud->ruta_pdf ?? null;

                    

                    // Búsqueda profunda del XML en todos los archivos del pedimento si no está enlazado
                    if (empty($rutaXmlMuestra)) {
                        foreach ($facturasDelPedimento as $fNombre => $fInfo) {
                            if (empty($fInfo['ruta_xml'])) {
                                continue;
                            }
                            $tDoc = strtolower($fInfo['tipo_documento'] ?? '');
                            $fNombreUpper = strtoupper($fNombre);

                            $esDeMuestras = in_array($tDoc, ['muestras', 'pago_muestras'])
                                || str_contains($tDoc, 'muestra')
                                || str_contains($fNombreUpper, 'MUESTRA')
                                || str_contains($fNombreUpper, 'GISENA')
                                || str_contains($fNombreUpper, 'LAPAN')
                                || str_contains($fNombreUpper, 'FITOSANITARIO');

                            if ($esDeMuestras) {
                                $rutaXmlMuestra = $fInfo['ruta_xml'];
                                break;
                            }
                        }
                    }

                    if (!empty($rutaXmlMuestra)) {
                        $xmlMuestra = $this->parsearXmlFlete($rutaXmlMuestra);
                        if ($xmlMuestra) {
                            $emisorMuestra = strtoupper($xmlMuestra['emisor'] ?? '');

                            if (str_contains($emisorMuestra, 'FITOSANITARIOS') || str_contains($emisorMuestra, 'GISENA') || str_contains($emisorMuestra, 'GRUPO INTEGRAL DE SERVICIOS FITOSANITARIOS ENA')) {
                                $proveedor = 'GISENA';
                            } elseif (str_contains($emisorMuestra, 'LABORATORIOS DE ANALISIS DE PRODUCTOS AGROPECUARIOS') || str_contains($emisorMuestra, 'LAPAN')) {
                                $proveedor = 'LAPAN';
                            } elseif (str_contains($emisorMuestra, 'COLEGIO DE POSTGRADUADOS') || str_contains($emisorMuestra, 'COLEGIO')) {
                                $proveedor = 'COLEGIO';
                            } elseif (!empty($emisorMuestra)) {
                                $proveedor = $emisorMuestra;
                            }

                            // Tomar únicamente el número del folio
                            if (!empty($xmlMuestra['folio'])) {
                                $aud->folio = preg_replace('/^\D+/', '', trim($xmlMuestra['folio']));
                            }
                        }
                    }

                    // Fallback a PDF solo si no se obtuvo emisor o folio válido
                    if (empty($proveedor) || $proveedor === $navieraOp || $proveedor === 'N/A' || $esFolioInvalido($aud->folio)) {
                        $rutaPdfScan = $rutaPdfMuestra;

                        if (empty($rutaPdfScan)) {
                            foreach ($facturasDelPedimento as $fNombre => $fInfo) {
                                $tDoc = strtolower($fInfo['tipo_documento'] ?? '');
                                if (in_array($tDoc, ['muestras', 'pago_muestras']) && !empty($fInfo['ruta_pdf'])) {
                                    $rutaPdfScan = $fInfo['ruta_pdf'];
                                    break;
                                }
                            }
                        }

                        $textoCheck = strtoupper(($aud->folio ?? '') . ' ' . ($rutaPdfScan ?? ''));
                        if (str_contains($textoCheck, 'GISENA') || str_contains($textoCheck, 'FITOSANITARIO')) {
                            $proveedor = 'GISENA';
                        } elseif (str_contains($textoCheck, 'LAPAN')) {
                            $proveedor = 'LAPAN';
                        }

                        if (!empty($rutaPdfScan)) {
                            $pdfData = $this->extraerTotalDesdePdfProveedor($rutaPdfScan);
                            if ($pdfData && !empty($pdfData['folio'])) {
                                $aud->folio = preg_replace('/^\D+/', '', trim($pdfData['folio']));
                            }

                            if (empty($proveedor) || $proveedor === $navieraOp || $proveedor === 'N/A') {
                                try {
                                    $arrContextOptions = [
                                        "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
                                        "http" => ["ignore_errors" => true, "timeout" => 15]
                                    ];
                                    $contenidoPdf = @file_get_contents($rutaPdfScan, false, stream_context_create($arrContextOptions));

                                    if (!$contenidoPdf || str_contains($http_response_header[0] ?? '', '404')) {
                                        $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaPdfScan);
                                        $contenidoPdf = @file_get_contents($rutaAlternativa, false, stream_context_create($arrContextOptions));
                                    }

                                    if ($contenidoPdf) {
                                        $config = new \Smalot\PdfParser\Config();
                                        $config->setRetainImageContent(false);
                                        $parser = new Parser([], $config);
                                        $pdfObj = $parser->parseContent($contenidoPdf);
                                        $textoPdf = strtoupper(preg_replace('/\s+/', ' ', $pdfObj->getText()));

                                        if (str_contains($textoPdf, 'FITOSANITARIO') || str_contains($textoPdf, 'GISENA') || str_contains($textoPdf, 'GRUPO INTEGRAL DE SERVICIOS FITOSANITARIOS')) {
                                            $proveedor = 'GISENA';
                                        } elseif (str_contains($textoPdf, 'LABORATORIOS DE ANALISIS DE PRODUCTOS AGROPECUARIOS') || str_contains($textoPdf, 'LAPAN')) {
                                            $proveedor = 'LAPAN';
                                        } elseif (str_contains($textoPdf, 'COLEGIO DE POSTGRADUADOS') || str_contains($textoPdf, 'COLEGIO')) {
                                            $proveedor = 'COLEGIO';
                                        }

                                        if ($esFolioInvalido($aud->folio)) {
                                            if (preg_match('/\b(?:NOG)?[-\s]?(\d{4,6})\b/i', $textoPdf, $mNog)) {
                                                $aud->folio = trim($mNog[1]);
                                            }
                                        }
                                    }
                                } catch (\Throwable $th) {
                                    Log::warning("No se pudo extraer texto del PDF para muestras en pedimento {$pedimentoLimpio}: " . $th->getMessage());
                                }
                            }
                        }
                    }
                }

                // Lógica de nombres
                if ($tipoDoc === 'impuestos') {
                    if ($index === 0) {
                        $conceptoFinal = 'IMPUESTOS';
                    } elseif ($index === 1) {
                        $conceptoFinal = 'IMPUESTOS RECTI';
                    } else {
                        $conceptoFinal = 'IMPUESTOS RECTI ' . $index;
                    }
                } else {
                    $conceptoFinal = ($index > 0) ? $pxccLabel . ' ' . ($index + 1) : $pxccLabel;
                }

                $folioFactura = $aud->folio ?? '-';

                if ($esFolioInvalido($folioFactura)) {
                    $folioFactura = '-';
                }

                // ============================================================
                // REGLA MAESTRA: SÓLO EL NÚMERO DE FOLIO (SIN SERIE)
                // Remueve prefijos de letras como NOG, TR, GS, etc.
                // ============================================================
                if (!empty($folioFactura) && $folioFactura !== '-') {
                    $soloNumero = preg_replace('/^[A-Za-z\s\-_]+0*/', '', trim($folioFactura));
                    $folioFactura = !empty($soloNumero) ? $soloNumero : trim($folioFactura);
                }

                // Desglose de maniobras NL
                if ($tipoDoc === 'maniobras' && $sucursalCod === 'NL' && Str::contains(strtoupper($cliente), $clientesDesgloseNL)) {
                    $numPedRef = $datosId['num_pedimiento'];
                    $listaFacturasManiobra = $indiceManiobras[$numPedRef] ?? $indiceManiobras[$pedimentoLimpio] ?? [];

                    if (!empty($listaFacturasManiobra)) {
                        $contadoresConcepto = [];

                        foreach ($listaFacturasManiobra as $datosManiobra) {
                            $infoExtraccion = $this->extraerMontoYNaviera($datosManiobra['path_xml_man'] ?? null, $datosManiobra['path_pdf_man'] ?? null);

                            if ($infoExtraccion['monto'] > 0) {
                                $nombreArchivo = strtoupper(basename($datosManiobra['path_pdf_man'] ?? $datosManiobra['path_xml_man'] ?? ''));
                                $naviera = strtoupper($infoExtraccion['naviera']);

                                $conceptoDesglose = 'MANIOBRAS';
                                $proveedorDesglose = 'MANIOBRAS NL';

                                if (str_contains($nombreArchivo, 'KCSM') || str_contains($naviera, 'KANSAS') || str_contains($naviera, 'KCSM')) {
                                    $conceptoDesglose = 'FERROVIARIAS';
                                    $proveedorDesglose = 'KANSAS';
                                } elseif (str_contains($nombreArchivo, 'FSA030920NT1') || str_contains($naviera, 'SANMOL') || str_contains($naviera, 'FUMIGA')) {
                                    $conceptoDesglose = 'FUMIGACION';
                                    $proveedorDesglose = 'SANMOL';
                                } elseif (str_contains($nombreArchivo, 'MSN1410164Q4') || str_contains($naviera, 'MANIOBRA')) {
                                    $conceptoDesglose = 'MANIOBRAS';
                                    $proveedorDesglose = 'MANIOBRAS NL';
                                }

                                if (!isset($contadoresConcepto[$conceptoDesglose])) {
                                    $contadoresConcepto[$conceptoDesglose] = 1;
                                    $conceptoFinalAEnviar = $conceptoDesglose;
                                } else {
                                    $contadoresConcepto[$conceptoDesglose]++;
                                    $conceptoFinalAEnviar = $conceptoDesglose . ' ' . $contadoresConcepto[$conceptoDesglose];
                                }

                                $folioItem = $datosManiobra['folio'] ?? '-';
                                $monedaItem = 'MXN';
                                if (!empty($datosManiobra['path_xml_man'])) {
                                    $xmlData = $this->parsearXmlFlete($datosManiobra['path_xml_man']);
                                    if ($xmlData) {
                                        if (!empty($xmlData['folio'])) {
                                            $folioItem = $xmlData['folio'];
                                        }
                                        if (!empty($xmlData['moneda'])) {
                                            $monedaItem = $xmlData['moneda'];
                                        }
                                    }
                                }

                                if ($esFolioInvalido($folioItem)) {
                                    $folioItem = '-';
                                } else {
                                    $folioItem = preg_replace('/^[A-Za-z\s\-_]+0*/', '', trim($folioItem)) ?: $folioItem;
                                }

                                $fechaItem = $infoExtraccion['fecha'] ? \Carbon\Carbon::parse($infoExtraccion['fecha'])->format('m-d-Y') : now()->format('m-d-Y');

                                $filas[] = [
                                    'fecha'      => $fechaItem,
                                    'cliente'    => $cliente,
                                    'pedimento'  => $datosId['num_pedimiento'],
                                    'pxcc'       => $conceptoFinalAEnviar,
                                    'proveedor'  => $proveedorDesglose,
                                    'factura_p'  => $folioItem,
                                    'monto'      => $infoExtraccion['monto'],
                                    'moneda'     => $monedaItem,
                                    'factura_sc' => $folioSC,
                                    'estatus'    => $estatusConDatos,
                                    'monto_llc'  => '',
                                    'moneda_llc' => '',
                                    'fecha_pago' => ''
                                ];
                            }
                        }
                        continue;
                    }
                }

                $filas[] = [
                    'fecha'      => !empty($aud->fecha_documento) ? \Carbon\Carbon::parse($aud->fecha_documento)->format('m-d-Y') : now()->format('m-d-Y'),
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
        }

        return ['hoja' => $hojaDestino, 'filas' => $filas];
    }

    private function leerSCDesdeArchivos(string $pedimentoLimpio, array $indiceOperacion): ?array
    {
        if (empty($indiceOperacion['facturas'])) {
            return null;
        }
 
        try {
            $indiceSC = $this->construirIndiceSC([$pedimentoLimpio => $indiceOperacion]);
            return empty($indiceSC) ? null : reset($indiceSC);
        } catch (\Throwable $e) {
            Log::warning("GPC Multi: no se pudo leer la SC del pedimento {$pedimentoLimpio}: " . $e->getMessage());
            return null;
        }
    }

    private function extraerAuditoriasMultiDesdeArchivos(string $tipoDoc, string $pedimentoLimpio, array $indiceOperacion, ?array $datosSC): Collection
    {
        $items = collect();
        $facturas = $indiceOperacion['facturas'] ?? [];
 
        if (empty($facturas)) {
            return $items;
        }
 
        $indiceUnico = [$pedimentoLimpio => $indiceOperacion];
        $tipoCambio = ($datosSC && (float) ($datosSC['tipo_cambio'] ?? 0) > 1) ? (float) $datosSC['tipo_cambio'] : 0.0;
        $hoy = now()->format('Y-m-d');
 
        $crear = function (array $datos) {
            return (object) array_merge([
                'fecha_documento'  => null,
                'folio'            => null,
                'monto_total'      => 0,
                'monto_total_mxn'  => 0,
                'moneda_documento' => 'MXN',
                'ruta_xml'         => null,
                'ruta_pdf'         => null,
            ], $datos);
        };
 
        try {
            switch ($tipoDoc) {
 
                // IMPUESTOS: monto de impuestos de la SC ([encTEXTOEXTRA1])
                case 'impuestos':
                    if ($datosSC && (float) ($datosSC['monto_impuestos'] ?? -1) > 0) {
                        $monto = (float) $datosSC['monto_impuestos'];
                        if (($datosSC['moneda'] ?? 'MXN') === 'USD' && $tipoCambio > 1) {
                            $monto = round($monto * $tipoCambio, 2);
                        }
                        preg_match('/[4-7]\d{6}/', $pedimentoLimpio, $mPed);
 
                        $items->push($crear([
                            'fecha_documento' => $hoy,
                            'folio'           => $mPed[0] ?? $pedimentoLimpio,
                            'monto_total'     => $monto,
                            'monto_total_mxn' => $monto,
                            'ruta_txt'        => $datosSC['ruta_txt'] ?? null,
                        ]));
                    }
                    break;
 
                // ECI / PAGO DE DERECHO: PDF del pago (prioriza los que no son de INTACTICS)
                case 'pago_derecho':
                    $indicePDD = $this->construirIndiceOperacionesPagosDerecho($indiceUnico);
                    $rutas = empty($indicePDD) ? [] : reset($indicePDD);
                    $llavesVistas = [];
 
                    foreach ($rutas as $rutaPdf) {
                        $datosPago = $this->parsearPdfPagoDeDerecho($rutaPdf);
                        if (!$datosPago || (float) $datosPago['monto_total'] <= 0) {
                            continue;
                        }
 
                        $llave = $datosPago['llave_pago'] ?? uniqid();
                        if (isset($llavesVistas[$llave])) {
                            continue;
                        }
                        $llavesVistas[$llave] = true;
 
                        $items->push($crear([
                            'fecha_documento' => $datosPago['fecha_pago'] ?? $hoy,
                            'monto_total'     => (float) $datosPago['monto_total'],
                            'monto_total_mxn' => (float) $datosPago['monto_total'],
                            'ruta_pdf'        => $datosPago['ruta_alternativa'] ?? $rutaPdf,
                        ]));
                    }
                    break;
 
                // FLETE: XML de TransporTactics
                case 'flete':
                    foreach ($facturas as $fNombre => $factura) {
                        $tDoc = strtolower($factura['tipo_documento'] ?? '');
                        $fMayus = strtoupper($fNombre . ' ' . ($factura['ruta_pdf'] ?? '') . ' ' . ($factura['ruta_xml'] ?? ''));

                        $esFlete = ($tDoc === 'flete')
                            || str_contains($tDoc, 'flete')
                            || str_contains($fMayus, 'TRANSPORTACTICS')
                            || str_contains($fMayus, 'FLETE');

                        if (!$esFlete) {
                            continue;
                        }

                        $total = -1;
                        $moneda = 'MXN';
                        $fecha = $hoy;
                        $folio = $factura['folio'] ?? null;

                        // 1. Intentar con XML
                        if (!empty($factura['ruta_xml'])) {
                            $xmlData = $this->parsearXmlFlete($factura['ruta_xml']);
                            if ($xmlData && ($xmlData['total'] ?? -1) > 0) {
                                $total = (float) $xmlData['total'];
                                $moneda = strtoupper($xmlData['moneda'] ?? 'MXN');
                                $fecha = $xmlData['fecha'] ?? $hoy;
                                if (!empty($xmlData['folio'])) {
                                    $folio = $xmlData['folio'];
                                }
                            }
                        }

                        // 2. Respaldo en PDF
                        if ($total <= 0 && !empty($factura['ruta_pdf'])) {
                            $pdfData = $this->extraerTotalDesdePdfProveedor($factura['ruta_pdf']);
                            if ($pdfData && ($pdfData['monto'] ?? 0) > 0) {
                                $total = (float) $pdfData['monto'];
                                $fecha = $pdfData['fecha'] ?? $fecha;
                                if (empty($folio) && !empty($pdfData['folio'])) {
                                    $folio = $pdfData['folio'];
                                }
                            }
                        }

                        Log::info("🚛 [RESCATE FLETE | Ped: {$pedimentoLimpio}] Archivo: {$fNombre} -> Total: {$total} {$moneda} | Folio: {$folio}");

                        if ($total <= 0) {
                            continue;
                        }

                        $items->push($crear([
                            'fecha_documento'  => $fecha,
                            'folio'            => $folio ?: '-',
                            'monto_total'      => $total,
                            'monto_total_mxn'  => ($moneda === 'USD' && $tipoCambio > 1) ? round($total * $tipoCambio, 2) : $total,
                            'moneda_documento' => $moneda,
                            'ruta_xml'         => $factura['ruta_xml'] ?? null,
                            'ruta_pdf'         => $factura['ruta_pdf'] ?? null,
                        ]));
                    }
                    break;
 
                // MANIOBRAS: suma de los XML (igual que la auditoría local, sin respaldo de PDF)
                case 'maniobras':
                    $indiceMan = $this->construirIndiceOperacionesManiobras($indiceUnico);
                    $lista = empty($indiceMan) ? [] : reset($indiceMan);
 
                    $suma = 0;
                    $folios = [];
                    $montosVistos = [];
                    $moneda = 'MXN';
                    $fecha = $hoy;
                    $rutaXml = null;
                    $rutaPdf = null;
 
                    foreach ($lista as $datosManiobra) {
                        if (empty($datosManiobra['path_xml_man'])) {
                            continue;
                        }
 
                        $xmlData = $this->parsearXmlFlete($datosManiobra['path_xml_man']);
                        if (!$xmlData || (float) $xmlData['total'] <= 0) {
                            continue;
                        }
 
                        $monto = round((float) $xmlData['total'], 2);
                        if (in_array($monto, $montosVistos)) {
                            continue;
                        }
                        $montosVistos[] = $monto;
 
                        $suma += $monto;
                        $moneda = strtoupper($xmlData['moneda'] ?? 'MXN');
                        $fecha = $xmlData['fecha'] ?? $fecha;
                        $folios[] = $xmlData['folio'] ?? ($datosManiobra['folio'] ?? '-');
                        $rutaXml = $rutaXml ?? $datosManiobra['path_xml_man'];
                        $rutaPdf = $rutaPdf ?? ($datosManiobra['path_pdf_man'] ?? null);
                    }
 
                    if ($suma > 0) {
                        $items->push($crear([
                            'fecha_documento'  => $fecha,
                            'folio'            => implode(' | ', array_unique($folios)),
                            'monto_total'      => $suma,
                            'monto_total_mxn'  => ($moneda === 'USD' && $tipoCambio > 1) ? round($suma * $tipoCambio, 2) : $suma,
                            'moneda_documento' => $moneda,
                            'ruta_xml'         => $rutaXml,
                            'ruta_pdf'         => $rutaPdf,
                        ]));
                    }
                    break;
 
                // MUESTRAS: XML (o PDF de PAGO / COMPROBANTE / GISENA)
                case 'muestras':
                    $indiceMue = $this->construirIndiceOperacionesMuestras($indiceUnico);
                    $lista = empty($indiceMue) ? [] : reset($indiceMue);
 
                    $suma = 0;
                    $folios = [];
                    $montosVistos = [];
                    $rutaXml = null;
                    $rutaPdf = null;
 
                    foreach ($lista as $idx => $datosMuestra) {
                        $monto = -1;
                        $folioRaw = $datosMuestra['folio'] ?? "Archivo_" . ($idx + 1);
                        $nombreMayus = strtoupper($folioRaw);
                        $folioArchivo = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
 
                        if (!empty($datosMuestra['path_xml_mue'])) {
                            $xmlData = $this->parsearXmlFlete($datosMuestra['path_xml_mue']);
                            if ($xmlData && $xmlData['total'] != -1) {
                                $monto = (strtoupper($xmlData['moneda'] ?? '') === 'USD' && $tipoCambio > 1)
                                    ? round($xmlData['total'] * $tipoCambio, 2)
                                    : (float) $xmlData['total'];
                                if (!empty($xmlData['folio'])) {
                                    $folioArchivo = $xmlData['folio'];
                                }
                            }
                        } elseif (str_contains($nombreMayus, 'PAGO') || str_contains($nombreMayus, 'COMPROBANTE') || str_contains($nombreMayus, 'GISENA')) {
                            $pdfData = $this->extraerTotalDesdePdfProveedor($datosMuestra['path_pdf_mue']);
                            if ($pdfData !== null && $pdfData['monto'] !== null) {
                                $monto = (float) $pdfData['monto'];
                            }
                        }
 
                        if ($monto <= 0 || in_array(round($monto, 2), $montosVistos)) {
                            continue;
                        }
                        $montosVistos[] = round($monto, 2);
 
                        $suma += $monto;
                        $folios[] = $folioArchivo;
                        $rutaXml = $rutaXml ?? ($datosMuestra['path_xml_mue'] ?? null);
                        $rutaPdf = $rutaPdf ?? ($datosMuestra['path_pdf_mue'] ?? null);
                    }
 
                    if ($suma > 0) {
                        $items->push($crear([
                            'fecha_documento' => $hoy,
                            'folio'           => implode(' | ', array_unique($folios)),
                            'monto_total'     => $suma,
                            'monto_total_mxn' => $suma,
                            'ruta_xml'        => $rutaXml,
                            'ruta_pdf'        => $rutaPdf,
                        ]));
                    }
                    break;
 
                // LLC: TXT de la LLC (o PDF de respaldo). Monto en USD + equivalente en MXN
                case 'llc':
                    $indiceLLC = $this->construirIndiceOperacionesLLCs($indiceUnico);
                    $datosLlc = empty($indiceLLC) ? null : reset($indiceLLC);
 
                    if (!$datosLlc) {
                        break;
                    }
 
                    if ((empty($datosLlc['txt_exitoso']) || (float) $datosLlc['monto_total'] <= 0) && !empty($datosLlc['ruta_pdf'])) {
                        $pdfData = $this->extraerTotalDesdePdfProveedor($datosLlc['ruta_pdf']);
                        if ($pdfData !== null && $pdfData['monto'] !== null) {
                            $datosLlc['monto_total'] = (float) $pdfData['monto'];
                            if (!empty($pdfData['fecha'])) {
                                $datosLlc['fecha'] = $pdfData['fecha'];
                            }
                            if (!empty($pdfData['folio'])) {
                                $datosLlc['folio'] = $pdfData['folio'];
                            }
                        }
                    }
 
                    $montoUSD = (float) ($datosLlc['monto_total'] ?? 0);
                    if ($montoUSD > 0) {
                        $tcLLC = $tipoCambio > 1 ? $tipoCambio : 18.0; // Mismo rescate que la auditoría de LLC
                        $items->push($crear([
                            'fecha_documento'  => $datosLlc['fecha'] ?? $hoy,
                            'folio'            => $datosLlc['folio'] ?? 'S/F',
                            'monto_total'      => $montoUSD,
                            'monto_total_mxn'  => round($montoUSD * $tcLLC, 2),
                            'moneda_documento' => 'USD',
                            'ruta_pdf'         => $datosLlc['ruta_pdf'] ?? null,
                        ]));
                    }
                    break;
            }
        } catch (\Throwable $e) {
            Log::warning("GPC Multi: no se pudo extraer '{$tipoDoc}' de los archivos del pedimento {$pedimentoLimpio}: " . $e->getMessage());
        }
 
        return $items;
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
            $xmlString = null;

            // 1. Si es un archivo local del servidor
            if (is_file($rutaXml) && is_readable($rutaXml)) {
                $xmlString = @file_get_contents($rutaXml);
            }

            // 2. Stream context nativo (soporta URLs con espacios, XAMPP y no truena con excepciones URI)
            if (!$xmlString) {
                $arrContextOptions = [
                    "ssl"  => ["verify_peer" => false, "verify_peer_name" => false],
                    "http" => ["ignore_errors" => true, "timeout" => 20]
                ];
                $contexto = stream_context_create($arrContextOptions);

                $xmlString = @file_get_contents($rutaXml, false, $contexto);

                if (!$xmlString || str_contains($http_response_header[0] ?? '', '404')) {
                    $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);
                    $xmlString = @file_get_contents($rutaAlternativa, false, $contexto);
                }
            }

            // 3. Respaldo adicional con Http de Laravel
            if (!$xmlString) {
                try {
                    $response = Http::withoutVerifying()->timeout(20)->get($rutaXml);
                    if ($response->successful()) {
                        $xmlString = $response->body();
                    }
                } catch (\Throwable $th) {
                    $xmlString = null;
                }
            }

            if (!$xmlString) {
                return ['total' => -1, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];
            }

            // Limpieza de caracteres BOM y espacios previos
            $xmlString = ltrim($xmlString, "\xEF\xBB\xBF \t\n\r");
            $pos = stripos($xmlString, '<?xml');
            if ($pos !== false && $pos > 0) {
                $xmlString = substr($xmlString, $pos);
            }

            $total = -1;
            $moneda = 'MXN';
            $emisor = '';
            $fecha = null;
            $folioNum = null;

            try {
                $xmlObj = @simplexml_load_string($xmlString);
                if ($xmlObj !== false) {
                    if (isset($xmlObj['Total'])) {
                        $total = (float) $xmlObj['Total'];
                    } elseif (isset($xmlObj['total'])) {
                        $total = (float) $xmlObj['total'];
                    }

                    if (isset($xmlObj['Moneda'])) {
                        $moneda = strtoupper((string) $xmlObj['Moneda']);
                    }

                    if (isset($xmlObj['Fecha'])) {
                        $fecha = explode('T', (string) $xmlObj['Fecha'])[0];
                    }

                    if (isset($xmlObj['Folio'])) {
                        $folioRaw = (string) $xmlObj['Folio'];
                        $folioNum = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
                    }

                    $emisores = $xmlObj->xpath('//*[local-name()="Emisor"]');
                    if (!empty($emisores)) {
                        $emisor = trim((string) ($emisores[0]['Nombre'] ?? $emisores[0]['nombre'] ?? ''));
                    }
                }
            } catch (\Throwable $th) {
                // Fallback a regex
            }

            if ($total === -1 && preg_match('/(?:Comprobante|cfdi:Comprobante)[^>]+Total=["\']([0-9\,\.]+)["\']/is', $xmlString, $mTotal)) {
                $total = (float) str_replace(',', '', $mTotal[1]);
            }

            if (empty($emisor) && preg_match('/(?:Emisor|cfdi:Emisor)[^>]+Nombre=["\']([^"\']+)["\']/is', $xmlString, $mEmisor)) {
                $emisor = trim($mEmisor[1]);
            }

            if (empty($fecha) && preg_match('/(?:Fecha|fecha)=["\']([^"\']+)["\']/i', $xmlString, $mFecha)) {
                $fecha = explode('T', $mFecha[1])[0];
            }

            if ($moneda === 'MXN' && preg_match('/(?:Moneda|moneda)=["\']([A-Z]{3})["\']/i', $xmlString, $mMoneda)) {
                $moneda = strtoupper($mMoneda[1]);
            }

            if (!$folioNum && preg_match('/(?:Folio|folio)=["\']([^"\']+)["\']/i', $xmlString, $mFolio)) {
                $folioRaw = $mFolio[1];
                $folioNum = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
            }

            // Extraer EXCLUSIVAMENTE los dígitos numéricos
            $folioLimpio = null;
            if ($folioNum) {
                $folioLimpio = preg_replace('/^\D+/', '', trim($folioNum)) ?: trim($folioNum);
            }

            return [
                'total'  => $total,
                'moneda' => $moneda,
                'emisor' => $emisor,
                'fecha'  => $fecha,
                'folio'  => $folioLimpio
            ];

        } catch (\Throwable $e) {
            Log::error("Error parseando XML ({$rutaXml}): " . $e->getMessage());
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
        $nombreArchivo = basename($rutaPdf);

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
            $extracto = mb_substr(preg_replace('/\s+/', ' ', (string) $texto), 0, 500);

            // 1. PDF sin texto (imagen escaneada)
            if (trim((string) $texto) === '') {
                Log::warning("PDD: '{$nombreArchivo}' no tiene texto legible (probablemente es una imagen escaneada).");
                return null;
            }
            $datos = [];
            if ($rutaAlternativa) {
                $datos['ruta_alternativa'] = $rutaAlternativa;
            }

            $formato = 'SANTANDER';

            if (Str::contains($texto, 'Creando Oportunidades')) {   // BBVA
                $formato = 'BBVA';
                preg_match('/No\.\s*de\s*Operaci.n:\s*(\d+)/', $texto, $matchOp);
                preg_match('/Llave\s*de\s*Pago:\s*([A-Z0-9]+)/', $texto, $matchLlave);
                preg_match('/Total\s*Efectivamente\s*Pagado:\s*\$ ([\d,.]+)/', $texto, $matchMonto);
                preg_match('/Fecha\s*y\s*Hora\s*del\s*Pago:\s*(\d{2}\/\d{2}\/\d{4})/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float) str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('d/m/Y', $matchFecha[1])->format('Y-m-d') : null;
            } else {
                // Santander: "cadena mágica" de la segunda página
                preg_match('/\|20002=(\d+)\|/', $texto, $matchOp);
                preg_match('/\|40008=([A-Z0-9]+)\|/', $texto, $matchLlave);
                preg_match('/\|10017=([\d,.]+)\|/', $texto, $matchMonto);
                preg_match('/\|40002=(\d{8})\|/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float) str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('Ymd', $matchFecha[1])->format('Y-m-d') : null;
            }

            if (empty($datos['llave_pago'])) {
                $textoPlano = preg_replace('/\s+/u', ' ', $texto);

                if (preg_match('/Llave\s*de\s*Pago:?\s*([A-Z0-9]{6,})/iu', $textoPlano, $mLlave)) {
                    $datos['llave_pago'] = strtoupper($mLlave[1]);

                    if (empty($datos['numero_operacion']) && preg_match('/N.mero\s*de\s*Operaci.n:?\s*(\d+)/iu', $textoPlano, $mOp)) {
                        $datos['numero_operacion'] = $mOp[1];
                    }

                    if (empty($datos['fecha_pago']) && preg_match('/Fecha\s*y\s*Hora\s*del\s*Pago:?\s*(\d{2}\/\d{2}\/\d{4})/iu', $textoPlano, $mFecha)) {
                        $datos['fecha_pago'] = \Carbon\Carbon::createFromFormat('d/m/Y', $mFecha[1])->format('Y-m-d');
                    }

                    if (empty($datos['monto_total'])) {
                        if (preg_match('/Total\s*Efectivamente(?:\s*Pagado)?:?\s*\$\s*([\d,]+(?:\.\d{1,2})?)/iu', $textoPlano, $mMonto)
                            || preg_match('/Cantidad\s*Pagada:?\s*\$?\s*([\d,]+(?:\.\d{1,2})?)/iu', $textoPlano, $mMonto)) {
                            $datos['monto_total'] = (float) str_replace(',', '', $mMonto[1]);
                        }
                    }

                    $formato .= ' (texto del recibo)';
                }
            }

            if (Str::contains($rutaPdf, 'MEDIO')) {
                $datos['tipo'] = 'Medio Pago';
            } elseif (Str::contains($rutaPdf, '-2')) {
                $datos['tipo'] = 'Segundo Pago';
            } elseif (Str::contains($rutaPdf, 'INTACTICS')) {
                $datos['tipo'] = 'Intactics';
            } else {
                $datos['tipo'] = 'Normal';
            }

            unset($config, $parser, $pdf, $texto);
            gc_collect_cycles();

            // 3. Tiene texto pero no coincide con el formato esperado
            if (empty($datos['llave_pago'])) {
                Log::warning("PDD: '{$nombreArchivo}' no tiene llave de pago con el formato {$formato}. Inicio del texto: {$extracto}");
                return null;
            }

            return $datos;
        } catch (\Throwable $e) {
            Log::error("Error al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            unset($config, $parser, $pdf, $texto);
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

            foreach ($indicesOperacion as $pedimento =>$datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas'] ?? []);
                
                // Atrapamos archivos de tipo muestra/mensajeria que tengan PDF O XML
                $facturasMuestra =$coleccionFacturas->filter(function ($factura,$llaveGrupo) {
                    $tipo = strtolower($factura['tipo_documento'] ?? '');
                    $nombreMayus = strtoupper($llaveGrupo . ' ' . ($factura['ruta_pdf'] ?? '') . ' ' . ($factura['ruta_xml'] ?? ''));

                    $esTipoMuestra = str_contains($tipo, 'muestra') 
                        || str_contains($tipo, 'mensajeria')
                        || str_contains($nombreMayus, 'GISENA')
                        || str_contains($nombreMayus, 'LAPAN')
                        || str_contains($nombreMayus, 'FITOSANITARIO');

                    $tieneArchivo = !empty($factura['ruta_pdf']) || !empty($factura['ruta_xml']);

                    return $esTipoMuestra && $tieneArchivo;
                });

                if ($facturasMuestra->isEmpty()) {
                    continue;
                }

                foreach ($facturasMuestra as $llaveGrupo =>$factura) {
                    $indice[$pedimento][] = [
                        'folio' => $factura['folio'] ?? $llaveGrupo, 
                        'path_xml_mue' => $factura['ruta_xml'] ?? null, 
                        'path_pdf_mue' => $factura['ruta_pdf'] ?? null,
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

                // PRIORIDAD TOTAL: XMLs primero para registrar el folio y emisor oficial
                usort($listaMuestras, function ($a, $b) {
                    $aXml = !empty($a['path_xml_mue']) ? 1 : 0;
                    $bXml = !empty($b['path_xml_mue']) ? 1 : 0;
                    return $bXml - $aXml;
                });
                
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

                    // 1. Intentar leer XML
                    if (!empty($datosMuestra['path_xml_mue'])) {
                        $xmlData = $this->parsearXmlFlete($datosMuestra['path_xml_mue']);
                        if ($xmlData && $xmlData['total'] != -1) {
                            $monedaItem = $xmlData['moneda'];
                            $montoItemMXN = ($monedaItem == "USD") ? round($xmlData['total'] * $datosSC['tipo_cambio'], 2) : $xmlData['total'];
                            if (!empty($xmlData['folio'])) {
                                $folioArchivo = $xmlData['folio'];
                            }
                            $rutaXmlFinal = $datosMuestra['path_xml_mue'];
                        }
                    } 
                    // 2. Fallback a comprobante PDF
                    elseif (!empty($datosMuestra['path_pdf_mue']) && (str_contains($nombreMayus, 'PAGO') || str_contains($nombreMayus, 'COMPROBANTE') || str_contains($nombreMayus, 'GISENA'))) {
                        $pdfData = $this->extraerTotalDesdePdfProveedor($datosMuestra['path_pdf_mue']);
                        if ($pdfData !== null && $pdfData['monto'] !== null) {
                            $montoItemMXN = (float) $pdfData['monto'];
                            if (!empty($pdfData['folio'])) {
                                $folioArchivo = $pdfData['folio'];
                            }
                        }
                    }

                    if ($montoItemMXN > 0) {
                        // Si el monto ya fue sumado por el XML, solo enlazamos el PDF
                        if (in_array(round($montoItemMXN, 2), $montosYaSumados)) {
                            if (!$rutaPdfFinal && !empty($datosMuestra['path_pdf_mue'])) {
                                $rutaPdfFinal = $datosMuestra['path_pdf_mue'];
                            }
                            continue;
                        }

                        $sumaFacturasMXN += $montoItemMXN;
                        $montosYaSumados[] = round($montoItemMXN, 2);
                        $huboCoincidencia = true;
                        
                        // Si el folio es el nombre largo del archivo, descartarlo
                        if (str_contains(strtoupper($folioArchivo), 'PAGO-MUESTRA') || str_contains($folioArchivo, '---') || strlen($folioArchivo) > 25) {
                            $folioArchivo = !empty($rutaXmlFinal) ? $folioArchivo : '-';
                        }

                        if ($folioArchivo !== '-') {
                            $foliosComb[] = $folioArchivo;
                        }
                        
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
                    $folioFinal = '-';
                    $rutaXmlFinal = $listaMuestras[0]['path_xml_mue'] ?? null;
                    $rutaPdfFinal = $listaMuestras[0]['path_pdf_mue'] ?? null;
                } else {
                    $folioFinal = !empty($foliosComb) ? implode(' | ', array_unique($foliosComb)) : '-';
                }

                $montoTotalReportar = $sumaFacturasMXN > 0 ? (float) $sumaFacturasMXN : 0.0;
                $montoSC = (float) $datosSC['monto_muestras_sc_mxn'];
                $estado = $this->compararMontos_Muestras($montoSC, (float) $sumaFacturasMXN);

                $muestrasParaGuardar[] = [
                    'operacion_id'        => $operacionId['id_operacion'],
                    'pedimento_id'        => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type'      => isset($mapeadoFacturas['pedimentos_importacion'][$numPedRef]) ? Importacion::class : Exportacion::class,
                    'tipo_documento'      => 'muestras',
                    'concepto_llave'      => 'principal',
                    'folio'               => $folioFinal,
                    'fecha_documento'     => now()->format('Y-m-d'),
                    'monto_total'         => $montoTotalReportar, 
                    'monto_total_mxn'     => $sumaFacturasMXN,
                    'monto_diferencia_sc' => round($montoSC - $montoTotalReportar, 2),
                    'moneda_documento'    => $monedaDoc,
                    'estado'              => $estado,
                    'ruta_xml'            => $rutaXmlFinal,
                    'ruta_pdf'            => $rutaPdfFinal,
                    'updated_at'          => now(),
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
 
                $facturasManiobra = $coleccionAgrupada->filter(function ($factura) {
                    $tipo = $factura['tipo_documento'] ?? '';
                    $nombre = $factura['nombre_real'] ?? '';
                    $nombreMin = strtolower($nombre);
 
                    $esManiobraNormal = (str_contains($tipo, 'maniobra') && !str_contains($tipo, 'terminal'));
 
                    // Archivos especiales en la carpeta "OTROS"
                    $esArchivoEspecial = str_contains($nombre, 'FACTURAKCSM') ||
                                         str_contains($nombre, 'FSA030920NT1') ||
                                         str_contains($nombre, 'MSN1410164Q4');
 
                    // Conceptos especiales ZLO: no se cobran como maniobras genéricas
                    $esExcluido = str_contains($nombreMin, 'accesa') ||
                                  str_contains($nombreMin, 'accessa') ||
                                  str_contains($nombreMin, 'reconocimiento') ||
                                  str_contains($nombreMin, 'previo') ||
                                  str_contains($nombreMin, 'reacomodo');
 
                    return ($esManiobraNormal || $esArchivoEspecial) && !empty($factura['ruta_pdf']) && !$esExcluido;
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

    private function columnaContenedor(string $tabla): ?string
    {
        static $cache = [];

        if (array_key_exists($tabla, $cache)) {
            return $cache[$tabla];
        }

        foreach (['contenedor', 'contenedores', 'num_contenedor', 'numero_contenedor'] as $columna) {
            if (Schema::hasColumn($tabla, $columna)) {
                return $cache[$tabla] = $columna;
            }
        }

        return $cache[$tabla] = null;
    }

    /**
     * Contenedores (limpios, sin espacios ni guiones) de las operaciones de los pedimentos dados.
     * Solo consulta las tablas que sí tienen columna de contenedor.
     */
    private function contenedoresDePedimentos(array $pedimentosAuditoria): array
    {
        if (empty($pedimentosAuditoria)) {
            return [];
        }

        $idsPed = Pedimento::where(function ($q) use ($pedimentosAuditoria) {
            foreach ($pedimentosAuditoria as $ped) {
                $q->orWhere('num_pedimiento', 'LIKE', "%{$ped}%");
            }
        })->pluck('id_pedimiento');

        if ($idsPed->isEmpty()) {
            return [];
        }

        $textos = collect();

        $colImpo = $this->columnaContenedor('operaciones_importacion');
        if ($colImpo) {
            $textos = $textos->merge(
                Importacion::whereIn('id_pedimiento', $idsPed)->whereNotNull($colImpo)->pluck($colImpo)
            );
        }

        $colExpo = $this->columnaContenedor('operaciones_exportacion');
        if ($colExpo) {
            $textos = $textos->merge(
                Exportacion::whereIn('id_pedimiento', $idsPed)->whereNotNull($colExpo)->pluck($colExpo)
            );
        }

        $contenedores = [];
        foreach ($textos as $texto) {
            if (preg_match_all('/[A-Za-z]{4}[\s\-]*\d{7}/', (string) $texto, $matches)) {
                foreach ($matches[0] as $c) {
                    $contenedores[] = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $c));
                }
            }
        }

        return array_values(array_unique($contenedores));
    }

    /**
     * Construye un índice para los conceptos específicos de ZLO: Limpieza, Conexiones, Accesa y Reconocimiento
     */
    private function construirIndiceConceptosEspecialesZLO(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];
 
        foreach ($indicesOperacion as $pedimento => $datos) {
            if (isset($datos['error'])) {
                continue;
            }
 
            foreach (($datos['facturas'] ?? []) as $llaveGrupo => $factura) {
                $carpeta = strtolower($factura['carpeta_original'] ?? $factura['tipo_documento'] ?? '');
                $rutaPdf = $factura['ruta_pdf'] ?? '';
                $rutaXml = $factura['ruta_xml'] ?? '';
 
                if (empty($rutaPdf) && empty($rutaXml)) {
                    continue;
                }
 
                $esCarpetaCandidata = str_contains($carpeta, 'proveedor')
                    || str_contains($carpeta, 'terminal')
                    || str_contains($carpeta, 'vacio')
                    || str_contains($carpeta, 'maniobra')
                    || str_contains($carpeta, 'rojo');
 
                if (!$esCarpetaCandidata) {
                    continue;
                }
 
                $nombreArchivo = Str::upper(Str::ascii(
                    $llaveGrupo . ' ' .
                    basename(parse_url($rutaPdf, PHP_URL_PATH) ?? '') . ' ' .
                    basename(parse_url($rutaXml, PHP_URL_PATH) ?? '')
                ));
 
                // Logipuerto va por su propio concepto
                if (str_contains($nombreArchivo, 'LOGIPUERTO') || str_contains($nombreArchivo, 'LOGI PUERTO')) {
                    continue;
                }
 
                $indice[$pedimento][] = [
                    'folio'          => $factura['folio'] ?? $llaveGrupo,
                    'ruta_xml'       => $rutaXml ?: null,
                    'ruta_pdf'       => $rutaPdf ?: null,
                    'carpeta'        => $carpeta,
                    'nombre_archivo' => $nombreArchivo,
                ];
            }
        }
 
        return $indice;
    }

    /**
     * Extrae y envía a Google Sheets los 4 conceptos especiales (Manzanillo)
     */
    public function enviarAGPCConceptosExtraZLO($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);

        if (!$tarea || ($tarea->exists && $tarea->status !== 'procesando')) {
            return ['code' => 1, 'message' => new \Exception("Tarea no válida.")];
        }

        if (strtoupper($tarea->sucursal ?? '') !== 'ZLO') {
            return ['code' => 0, 'message' => 'Omitido (Conceptos Extra solo aplica a ZLO)'];
        }

        Log::info("Tarea #{$tarea->id}: Iniciando extracción de Conceptos Especiales ZLO para GPC...");

        try {
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                return ['code' => 1, 'message' => new \Exception("No se encontró el archivo de mapeo universal.")];
            }

            $mapeadoFacturas = (array) json_decode(Storage::get($rutaMapeo), true);
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
            $indicesOperaciones = ($mapeadoFacturas['indices_importacion'] ?? []) + ($mapeadoFacturas['indices_exportacion'] ?? []);

            // Tipos de cambio por pedimento (desde la SC) para facturas en USD
            $indiceTiposCambio = [];
            foreach (($mapeadoFacturas['auditorias_sc'] ?? []) as $auditoria) {
                $desglose = is_array($auditoria['desglose_conceptos'])
                    ? $auditoria['desglose_conceptos']
                    : json_decode($auditoria['desglose_conceptos'], true);
                $indiceTiposCambio[$auditoria['pedimento_id']] = (float) ($desglose['tipo_cambio'] ?? 1.0);
            }

            // ==========================================================
            // ANTI-DUPLICADOS: facturas que ya tomaron los otros conceptos
            // ==========================================================
            $rutasReclamadas = [];
            $registrarRutas = function (array $indice, array $camposRuta) use (&$rutasReclamadas) {
                foreach ($indice as $listaFacturas) {
                    foreach ($listaFacturas as $factura) {
                        foreach ($camposRuta as $campo) {
                            if (!empty($factura[$campo])) {
                                $rutasReclamadas[$factura[$campo]] = true;
                            }
                        }
                    }
                }
            };

            $registrarRutas($this->construirIndiceOperacionesTerminales($indicesOperaciones), ['path_pdf_man', 'path_xml_man']);
            $registrarRutas($this->construirIndiceOperacionesVacios($indicesOperaciones), ['ruta_pdf', 'ruta_xml']);
            $registrarRutas($this->construirIndiceOperacionesAlmacenaje($indicesOperaciones), ['ruta_pdf', 'ruta_xml']);
            $registrarRutas($this->construirIndiceOperacionesLogipuerto($indicesOperaciones), ['ruta_pdf', 'ruta_xml']);

            $indiceCandidatos = $this->construirIndiceConceptosEspecialesZLO($indicesOperaciones);

            $especialesParaSheets = [];
            $resumen = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $datosId) {
                $pedimentoSucio = trim($datosId['num_pedimiento'] ?? '');
                $candidatos = $indiceCandidatos[$pedimentoSucio] ?? $indiceCandidatos[$pedimentoLimpio] ?? [];

                if (empty($candidatos)) {
                    continue;
                }

                $opId = $datosId['id_operacion'] ?? null;
                $tipoOp = (($datosId['tipo'] ?? '') === 'Importacion') ? Importacion::class : Exportacion::class;

                $queryOp = null;
                try {
                    if ($opId) {
                        $queryOp = ($tipoOp === Importacion::class)
                            ? Importacion::with('cliente')->find($opId)
                            : Exportacion::with('cliente')->find($opId);
                    }
                } catch (\Throwable $e) {
                    Log::error("Conceptos ZLO: error consultando BD para pedimento {$pedimentoLimpio}: " . $e->getMessage());
                    $queryOp = null;
                }

                $nombreCliente = $queryOp ? (optional($queryOp->cliente)->nombre ?? '') : '';
                $tipoCambio = $indiceTiposCambio[$datosId['id_pedimiento']] ?? 1.0;

                $conceptosCount = [];
                $montosRegistrados = [];

                foreach ($candidatos as $candidato) {
                    $yaReclamada = (!empty($candidato['ruta_pdf']) && isset($rutasReclamadas[$candidato['ruta_pdf']]))
                                || (!empty($candidato['ruta_xml']) && isset($rutasReclamadas[$candidato['ruta_xml']]));

                    // 1. Clasificación por nombre de archivo
                    $concepto = $this->clasificarConceptoEspecialZLO($candidato['carpeta'], $candidato['nombre_archivo']);

                    // 2. Por descripciones del XML SOLO si ningún otro concepto tomó ya esta factura
                    if (!$concepto && !$yaReclamada && !empty($candidato['ruta_xml'])) {
                        $textoXml = $this->obtenerTextoClasificacionXml($candidato['ruta_xml']);
                        $concepto = $this->clasificarConceptoEspecialZLO($candidato['carpeta'], $candidato['nombre_archivo'] . ' ' . $textoXml);
                    }

                    if (!$concepto) {
                        if (preg_match('/CONEXION|LIMPIEZA|LAVADO/', $candidato['nombre_archivo'])) {
                            Log::warning("Conceptos ZLO: Ped {$pedimentoLimpio} | Archivo en carpeta '{$candidato['carpeta']}' fuera de las carpetas permitidas. Se omite: " . ($candidato['ruta_pdf'] ?? $candidato['ruta_xml']));
                        }
                        continue;
                    }

                    $datosFactura = $this->extraerDatosFacturaProveedorZLO($candidato['ruta_xml'], $candidato['ruta_pdf'], $tipoCambio);

                    if ($datosFactura['monto'] <= 0) {
                        Log::warning("Conceptos ZLO: {$concepto} sin monto legible en pedimento {$pedimentoLimpio} | " . ($candidato['ruta_pdf'] ?? $candidato['ruta_xml']));
                        continue;
                    }

                    $baseConcepto = $concepto;
                    $proveedor = $datosFactura['proveedor'];

                    if ($concepto === 'Accesa') {
                        $baseConcepto = 'Almacen ACCESSA';
                        $proveedor = 'ACCESSA';
                    }

                    // Anti-duplicados: mismo concepto + mismo monto en el mismo pedimento
                    $llaveMonto = $baseConcepto . '_' . round($datosFactura['monto'], 2);
                    if (isset($montosRegistrados[$llaveMonto])) {
                        continue;
                    }
                    $montosRegistrados[$llaveMonto] = true;

                    $conceptosCount[$baseConcepto] = ($conceptosCount[$baseConcepto] ?? 0) + 1;
                    $conceptoNombre = $baseConcepto . ($conceptosCount[$baseConcepto] > 1 ? ' ' . $conceptosCount[$baseConcepto] : '');

                    $resumen[$baseConcepto] = ($resumen[$baseConcepto] ?? 0) + 1;

                    $especialesParaSheets[] = [
                        'fecha'      => $datosFactura['fecha'],
                        'cliente'    => $nombreCliente,
                        'contenedor' => $queryOp ? ($queryOp->contenedor ?? '') : '',
                        'bl'         => $queryOp ? ($queryOp->bol ?? '') : '',
                        'pedimento'  => $pedimentoLimpio,
                        'concepto'   => $conceptoNombre,
                        'monto'      => (float) round($datosFactura['monto'], 2),
                        'moneda'     => $datosFactura['moneda'],
                        'naviera'    => $proveedor ?: ''
                    ];
                }
            }

            Log::info("Conceptos Especiales ZLO detectados: " . json_encode($resumen));

            if (!empty($especialesParaSheets)) {
                Log::info("Conceptos Especiales ZLO listos para enviar a GPC: " . count($especialesParaSheets));
                $paquetes = array_chunk($especialesParaSheets, 50);

                foreach ($paquetes as $index => $paquete) {
                    $esUltimo = ($index === count($paquetes) - 1);
                    try {
                        $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                        sleep(2);
                    } catch (\Throwable $e) {
                        Log::error("Error enviando Conceptos Especiales ZLO (Paquete " . ($index + 1) . "): " . $e->getMessage());
                    }
                }
                Log::info("¡Todos los Conceptos Especiales ZLO enviados con éxito!");
            } else {
                Log::info("Conceptos Especiales ZLO: no se encontraron facturas con monto para enviar.");
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error enviando Conceptos Especiales ZLO a GPC: " . $e->getMessage() . " en la línea " . $e->getLine());
            return ['code' => 1, 'message' => $e];
        }
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
            $textoCrudo = $pdf->getText();
            $texto = preg_replace('/\s+/', ' ', $textoCrudo);

            $monto = null;
            $fecha = null;
            $folio = null;
            $regexMatched = 'Ninguno';

            $esInTactics = str_contains(strtoupper($texto), 'INTACTICS') 
                        || str_contains(strtoupper($texto), 'PLEASE PAY THIS AMOUNT') 
                        || preg_match('/ITK[0]*\d+/i', $texto);

            if ($esInTactics) {
                // 🚀 LÓGICA ANCLADA: Busca un número decimal EXACTAMENTE antes de la palabra "ENTRY:"
                if (preg_match('/([\d,]+\.\d{2})\s*ENTRY:/i', $texto, $matches)) {
                    $monto = (float) str_replace(',', '', $matches[1]);
                    $regexMatched = 'INTACTICS: ANCLA ENTRY:';
                } 
                // Fallback goloso si falla la primera regla
                elseif (preg_match('/PLEASE\s*PAY\s*THIS\s*AMOUNT.*?([\d,]+\.\d{2})\s*ENTRY/is', $texto, $matches)) {
                    $monto = (float) str_replace(',', '', $matches[1]);
                    $regexMatched = 'INTACTICS: PLEASE PAY -> ENTRY';
                }

                // Rescatamos el Folio exacto de InTactics (Ej. ITK00317118 -> 317118)
                if (preg_match('/ITK[0]*(\d+)/i', $texto, $matchFolioITK)) {
                    $folio = $matchFolioITK[1];
                }
            }

            // Lógica general para todos los demás proveedores (Solo se ejecuta si NO es Intactics o si falló la extracción)
            if ($monto === null) {
                if (preg_match('/PLEASE\s*PAY\s*THIS\s*AMOUNT[^\d]*([\d,]+\.\d{2})/i', $texto, $matches)) {
                    $monto = (float) str_replace(',', '', $matches[1]);
                    $regexMatched = 'PLEASE PAY THIS AMOUNT';
                } 
                elseif (preg_match('/(?:BALANCE|TOTAL)\s+DUE[^\d]*([\d,]+\.\d{2})/i', $texto, $matches)) {
                    $monto = (float) str_replace(',', '', $matches[1]);
                    $regexMatched = 'BALANCE/TOTAL DUE';
                }
            }

            if ($monto === null && str_contains(strtoupper($texto), 'SSA')) {
                if (preg_match('/(?:Importe|Total\s+Servicios|Total\s+Facturado)[\s:]+([\d,]+\.\d{2})/i', $texto, $matches)) {
                    $monto = (float) str_replace(',', '', $matches[1]);
                    $regexMatched = 'SSA';
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
            if ($folio === null) {
                // 1. Serie y Folio explícitos (ej. Serie: NOG Folio: 23132)
                if (preg_match('/(?:Serie|SERIE)\s*[:]?\s*([A-Za-z]+)\s*(?:Folio|FOLIO|No\.?)\s*[:]?\s*(\d+)/i', $texto, $matchSF)) {
                    $folio = strtoupper(trim($matchSF[1])) . trim($matchSF[2]);
                }
                // 2. Factura / Folio con serie y dígitos (ej: Factura: NOG23132, Folio: NOG-23132, Factura No. NOG23132)
                elseif (preg_match('/(?:Factura|Invoice|Folio|Recibo|Comprobante|No\.)\s*[:#]?\s*([A-Za-z]{2,5}[-\s]?\d{3,8})\b/i', $texto, $matchFolioAlfa)) {
                    $folio = strtoupper(str_replace(['-', ' '], '', trim($matchFolioAlfa[1])));
                }
                // 3. Patrón directo NOG (Nogales) + número (ej. NOG23132, NOG 23132, NOG-23132)
                elseif (preg_match('/\b(NOG)[-\s]?(\d{4,6})\b/i', $texto, $matchNog)) {
                    $folio = strtoupper(trim($matchNog[1])) . trim($matchNog[2]);
                }
                // 4. Fallback tradicional numérico
                elseif (preg_match('/(?:Factura|Invoice|No\.|Folio)\s*[:]?\s*(\d{5,})/i', $texto, $matchFolio)) {
                    $folio = trim($matchFolio[1]);
                }
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
        $palabrasLogipuerto = array_map('strtolower', $this->palabrasClaveLogipuerto());
 
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
 
                    // Deduplicador natural
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
 
                    if (!empty($factura['ruta_pdf'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_pdf'] = $factura['ruta_pdf'];
                    }
                    if (!empty($factura['ruta_xml'])) {
                        $facturasAgrupadas[$nombreLimpio]['ruta_xml'] = $factura['ruta_xml'];
                    }
                }

                foreach ($facturasAgrupadas as $factura) {
                    $tipo = $factura['tipo_documento'] ?? '';
                    $textoParaBuscar = strtolower(($factura['folio'] ?? '') . ' ' . ($factura['nombre_archivo_real'] ?? ''));

                    $enTerminal    = str_contains($tipo, 'terminal');
                    $enProveedores = str_contains($tipo, 'proveedor');

                    if (!$enTerminal && !$enProveedores) {
                        continue;
                    }

                    $tieneArchivo = !empty($factura['ruta_pdf']) || !empty($factura['ruta_xml']);
                    if (!$tieneArchivo) {
                        continue;
                    }

                    // En PROVEEDORES se exige la palabra clave; en TERMINAL la carpeta basta
                    $hasKeyword = str_contains($textoParaBuscar, 'terminal') || str_contains($textoParaBuscar, 'maniobra');
                    if ($enProveedores && !$enTerminal && !$hasKeyword) {
                        continue;
                    }

                    $exclusiones = ['conexion', 'limpieza', 'lavado', 'accesa', 'accessa', 'reconocimiento',
                                    'previo', 'admin', 'ctrol', 'almacen', 'vacio', 'reacomodo'];
                    $motivoExclusion = null;

                    foreach (array_merge($exclusiones, $palabrasLogipuerto) as $palabra) {
                        if (str_contains($textoParaBuscar, $palabra)) {
                            $motivoExclusion = $palabra;
                            break;
                        }
                    }

                    if ($motivoExclusion !== null) {
                        if ($enTerminal) {
                            Log::info("Terminales: Ped {$pedimento} | '{$factura['nombre_archivo_real']}' (carpeta TERMINAL) se excluye por '{$motivoExclusion}'.");
                        }
                        continue;
                    }

                    $indice[$pedimento][] = [
                        'folio' => $factura['folio'],
                        'path_xml_man' => $factura['ruta_xml'] ?? null,
                        'path_pdf_man' => $factura['ruta_pdf'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Error construyendo índice de terminales/proveedores: " . $e->getMessage());
        }
        return $indice;
    }

    public function enviarAGPCTerminales($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);

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
                
                try {
                    $queryOp = ($tipoOp === Importacion::class) ? Importacion::with('cliente')->find($opId) : Exportacion::with('cliente')->find($opId);
                } catch (\Throwable $e) {
                    Log::error("Error consultando BD (Terminales) para pedimento {$pedimentoLimpio}: " . $e->getMessage());
                    $queryOp = null;
                }

                $filasTemp = [];
                $montosRegistrados = []; 

                foreach ($listaFacturasManiobra as $datosManiobra) {
                    try {
                        $infoExtraccion = $this->extraerMontoYNaviera($datosManiobra['path_xml_man'] ?? null, $datosManiobra['path_pdf_man'] ?? null);
                    } catch (\Throwable $e) {
                        Log::error("Error extracción (Terminales) en pedimento {$pedimentoLimpio}: " . $e->getMessage(), [
                            'archivo' => $datosManiobra['path_pdf_man'] ?? 'Desconocido'
                        ]);
                        continue; 
                    }

                    if ($infoExtraccion['monto'] > 0) {
                        try {
                            $numeroDeContenedores = $this->contarContenedoresUniversal($datosManiobra['path_xml_man'] ?? null, $datosManiobra['path_pdf_man'] ?? null);
                            $montoDividido = round($infoExtraccion['monto'] / $numeroDeContenedores, 2);
                        } catch (\Throwable $e) {
                            Log::error("Error contando contenedores (Terminales) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                            $montoDividido = $infoExtraccion['monto'];
                        }

                        $montoKey = (string)$montoDividido;
                        if (in_array($montoKey, $montosRegistrados)) {
                            Log::info("Refrenando duplicado en GPC - Ped: {$pedimentoLimpio} | Monto: {$montoDividido} ya procesado.");
                            continue;
                        }

                        $montosRegistrados[] = $montoKey;
                        $indexActual = count($filasTemp);
                        
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

                foreach($filasTemp as $filaFinal) {
                    $maniobrasParaSheets[] = $filaFinal;
                }
            }

            if (!empty($maniobrasParaSheets)) {
                Log::info("Terminales encontradas para enviar: " . count($maniobrasParaSheets));
                $paquetes = array_chunk($maniobrasParaSheets, 50);
                
                foreach ($paquetes as $idx => $paquete) {
                    $esUltimo = ($idx === count($paquetes) - 1);
                    try {
                        $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                        sleep(1);
                    } catch (\Throwable $e) {
                        Log::error("Error en envío de Terminales a Sheets (Paquete " . ($idx + 1) . "): " . $e->getMessage());
                    }
                }
                Log::info("¡Envío de Terminales completado!");
            } else {
                Log::warning("No se encontraron montos de Terminales para enviar.");
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error General en Terminales: " . $e->getMessage());
            return ['code' => 1, 'message' => $e];
        }
    }

/**
     * Validador Inteligente de Vacíos
     * Lee el XML y descarta automáticamente lavados, limpiezas y otros cobros.
     */
    private function esFacturaDeVacioXML(?string $rutaXml): bool
    {
        if (empty($rutaXml)) {
            return false;
        }
 
        try {
            $response = Http::withoutVerifying()->timeout(10)->get($rutaXml);
            $xmlString = $response->successful() ? $response->body() : null;
 
            if (!$xmlString) {
                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);
                $responseAlt = Http::withoutVerifying()->timeout(10)->get($rutaAlternativa);
                $xmlString = $responseAlt->successful() ? $responseAlt->body() : null;
            }
 
            if (!$xmlString) {
                return false;
            }
 
            $pos = strpos($xmlString, '<?xml');
            if ($pos !== false) {
                $xmlString = substr($xmlString, $pos);
            }
 
            $xml = new \SimpleXMLElement($xmlString);
            $conceptos = $xml->xpath('//*[local-name()="Concepto"]');
 
            if (!empty($conceptos)) {
                foreach ($conceptos as $concepto) {
                    $attrs = $concepto->attributes();
                    $descripcion = Str::upper(Str::ascii((string) ($attrs['Descripcion'] ?? '')));
                    $claveProdServ = trim((string) ($attrs['ClaveProdServ'] ?? ''));
 
                    // Estos conceptos van por su propio renglón (Conexiones / Limpieza)
                    if (str_contains($descripcion, 'CONEXION') || str_contains($descripcion, 'LIMPIEZA') || str_contains($descripcion, 'LAVADO')) {
                        continue;
                    }
 
                    if (str_contains($descripcion, 'VACIO') || $claveProdServ === '78131702') {
                        return true;
                    }
                }
            }
        } catch (\Throwable $th) {
            Log::error("Error leyendo XML de Vacíos: " . $th->getMessage());
        }
 
        return false;
    }
    private function construirIndiceOperacionesVacios(array $indicesOperacion): array
    {
        gc_collect_cycles();
        $indice = [];

        try {
            foreach ($indicesOperacion as $pedimento => $datos) {
                if (isset($datos['error'])) {
                    continue;
                }

                $coleccionFacturas = collect($datos['facturas']);
                
                $facturasVacio = $coleccionFacturas->filter(function ($factura) {
                    $tipo = strtolower($factura['tipo_documento'] ?? '');
                    
                    if ($tipo !== 'vacios' || empty($factura['ruta_pdf'])) {
                        return false;
                    }

                    $textoParaBuscar = strtolower(($factura['folio'] ?? '') . ' ' . ($factura['ruta_pdf'] ?? ''));
                    if (str_contains($textoParaBuscar, 'conexion') || str_contains($textoParaBuscar, 'limpieza') || str_contains($textoParaBuscar, 'lavado')) {
                        return false;
                    }

                    if (!empty($factura['ruta_xml'])) {
                        if (!$this->esFacturaDeVacioXML($factura['ruta_xml'])) {
                            Log::info("Factura descartada inteligentemente: No es un cobro de vacío real (XML: {$factura['ruta_xml']})");
                            return false;
                        }
                    }

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

                    if (preg_match_all('/\b([A-Z]{4}\d{7})\b/', $textoPdf, $matches)) {
                        $contenedoresUnicos = array_unique($matches[1]);
                        if (count($contenedoresUnicos) > 0) {
                            return count($contenedoresUnicos);
                        }
                    }
                }
            } catch (\Throwable $th) {
                Log::warning("No se pudo leer el PDF para contenedores, saltando a XML. Archivo: {$rutaPdf} | Error: " . $th->getMessage());
            }
        }

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
                // Falla silenciosa permitida en último recurso
            }
        }

        return 1; 
    }

    // Enviar a Google Sheets el resultado de Vacíos
    public function enviarAGPCVacios($tareaId)
    {
        gc_collect_cycles();
        $tarea = ($tareaId instanceof AuditoriaTareas) ? $tareaId : AuditoriaTareas::find($tareaId);

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
                
                try {
                    $queryOp = ($tipoOp === Importacion::class) ? Importacion::with('cliente')->find($opId) : Exportacion::with('cliente')->find($opId);
                } catch (\Throwable $e) {
                    Log::error("Error consultando BD (Vacíos) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                    $queryOp = null;
                }

                $filasTemp = [];
                $montosRegistrados = [];

                foreach ($listaFacturasVacio as $datosVacio) {
                    try {
                        $infoExtraccion = $this->extraerMontoYNaviera($datosVacio['ruta_xml'] ?? null, $datosVacio['ruta_pdf'] ?? null);
                    } catch (\Throwable $e) {
                        Log::error("Error extracción de proveedor/monto (Vacíos) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                        continue;
                    }

                    if ($infoExtraccion['monto'] > 0) {
                        try {
                            $numeroDeContenedores = $this->contarContenedoresUniversal($datosVacio['ruta_xml'] ?? null, $datosVacio['ruta_pdf'] ?? null);
                            $montoDividido = round($infoExtraccion['monto'] / $numeroDeContenedores, 2);
                        } catch (\Throwable $e) {
                            Log::error("Error dividiendo monto (Vacíos) pedimento {$pedimentoLimpio}: " . $e->getMessage());
                            $montoDividido = $infoExtraccion['monto'];
                        }

                        $montoKey = (string)$montoDividido;
                        if (in_array($montoKey, $montosRegistrados)) {
                            continue;
                        }
                        $montosRegistrados[] = $montoKey;

                        $indexActual = count($filasTemp);
                        
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

                foreach($filasTemp as $fila) {
                    $vaciosParaSheets[] = $fila;
                }
            }

            if (!empty($vaciosParaSheets)) {
                Log::info("Vacíos listos para enviar a GPC: " . count($vaciosParaSheets));
                $paquetes = array_chunk($vaciosParaSheets, 50);
                foreach ($paquetes as $index => $paquete) {
                    $esUltimo = ($index === count($paquetes) - 1);
                    try {
                        $this->enviarDatosAGoogleSheets($paquete, 'ZLO', 'ZLO', $esUltimo);
                        sleep(2);
                    } catch (\Throwable $e) {
                        Log::error("Error enviando lote Vacíos a Sheets (Paquete " . ($index + 1) . "): " . $e->getMessage());
                    }
                }
                Log::info("¡Todos los Vacíos enviados con éxito!");
            } else {
                Log::info("Vacíos: No se encontraron registros con monto para enviar a Sheets.");
            }

            return ['code' => 0, 'message' => 'completado'];
        } catch (\Throwable $e) {
            Log::error("Error General en Vacíos: " . $e->getMessage());
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

        if (!empty($rutaXml)) {
            try {
                $xmlData = $this->parsearXmlFlete($rutaXml);
                if ($xmlData) {
                    $naviera = $xmlData['emisor'] ?? '';
                    
                    if ($xmlData['total'] != -1) {
                        $monto = ($xmlData['moneda'] === "USD") ? round($xmlData['total'] * 1.0, 2) : $xmlData['total'];
                        $fecha = $xmlData['fecha'] ?? null;
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Fallo XML proveedor. Archivo: {$rutaXml} | Error: " . $e->getMessage());
            }
        }

        if ($monto <= 0 && !empty($rutaPdf)) {
            try {
                $pdfData = $this->extraerTotalDesdePdfProveedor($rutaPdf);
                if ($pdfData !== null && $pdfData['monto'] !== null) { 
                    $monto = $pdfData['monto']; 
                    if (!empty($pdfData['fecha'])) {
                        $fecha = $pdfData['fecha']; 
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Fallo PDF proveedor. Archivo: {$rutaPdf} | Error: " . $e->getMessage());
            }
        }

        return [
            'monto'   => (float) $monto,
            'naviera' => $naviera,
            'fecha'   => $fecha ?: now()->format('Y-m-d') 
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

        // Hueco SSRF (2026-10-09): el servidor descargaba CUALQUIER liga que
        // mandara alguien con sesión, también direcciones internas como la de
        // metadatos de DigitalOcean (169.254.169.254). Ahora solo https y solo
        // de los sitios de donde vienen las facturas.
        $partes = parse_url($urlExterna);
        $host = isset($partes['host']) ? strtolower($partes['host']) : '';

        if (
            !isset($partes['scheme']) || strtolower($partes['scheme']) !== 'https'
            || !in_array($host, self::HOSTS_DE_DOCUMENTOS, true)
            || isset($partes['user']) || isset($partes['port'])
        ) {
            \Log::warning('Proxy de documentos: liga rechazada', ['url' => $urlExterna, 'usuario' => auth()->id()]);
            abort(403, 'Ese documento no se puede abrir desde aquí.');
        }

        if ($request->has('info')) {
            // Como tu regla de negocio dice que todas las facturas externas son PDF,
            // podemos responder directamente sin necesidad de descargar el archivo.
            return response()->json(['tipo_archivo' => 'pdf']);
        }
        try {
            // Hacemos la petición desde nuestro servidor a la URL externa.
            // Sin seguir redirecciones: un sitio permitido no debe poder
            // mandarnos a otro (por ejemplo, a una dirección interna).
            $response = Http::withoutVerifying()
                ->withOptions(['allow_redirects' => false])
                ->timeout(20)
                ->get($urlExterna);

            if ($response->failed()) {
                abort(502, 'No se pudo obtener el documento desde el servidor de origen.');
            }

            // Servimos el contenido del PDF al navegador con las cabeceras correctas.
            return response($response->body(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) parse_url($urlExterna, PHP_URL_PATH))) . '"',
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