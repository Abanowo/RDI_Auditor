<?php

namespace App\Http\Controllers;

use DateTime;
use App\Exports\AuditoriaFacturadoExport;
use App\Models\Importacion; // <-- CAMBIO CLAVE: Usamos Importacion como base
use App\Models\Exportacion;
use App\Models\Sucursales;
use App\Models\Pedimento;
use App\Models\Empresas;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;

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
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Builder;

use Smalot\PdfParser\Parser;
use Maatwebsite\Excel\Facades\Excel;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $filters = $request->query();
            $query = $this->obtenerQueryFiltrado($request);

            // Ahora, cargamos las relaciones que necesitamos para la transformación
            // La ruta es más larga, pero es la forma correcta: pedimento -> importacion -> auditorias/totalSc
            // Cargamos dinámicamente las relaciones necesarias para ambos tipos de operación
            $resultados = $query->with([
                'importacion' => function ($q) {
                    $q->with(['auditorias', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                },
                'exportacion' => function ($q) {
                    $q->with(['auditorias', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                }
            ])
                ->latest('pedimiento.created_at') // Ordena por el 'created_at' de operaciones_importacion
                ->paginate(15)
                ->withQueryString();

            // La transformación ahora recibe un objeto 'Pedimento'
            $resultados->getCollection()->transform(function ($pedimento) use ($filters) {
                return $this->transformarOperacion($pedimento, $filters);
            });

            return response()->json($resultados);
        }
        // Esto es para la primera visita desde el navegador.
        // Le dice a Laravel: "Carga y muestra el archivo de la vista principal".
        return view('welcome'); // O el nombre de tu vista, ej: 'audits.index'
    }
    //Para no estar escribiendo todo el filtrado en cada parte que lo ocupe, hago la construccion del query junto con todos sus filtros
    //y lo devuelvo de aqui hacia a donde se ocupe.
    private function obtenerQueryFiltrado(Request $request) : Builder
    {
        $filters = $request->query();

        $operationType = $filters['operation_type'] ?? 'todos';

        // Asumimos que este código está dentro de un método que recibe los filtros,
        // como el index() de tu AuditController o el query() de tu clase de Export.

        // --- LÓGICA DE FILTROS RECONSTRUIDA ---

        // 1. La consulta ahora empieza desde el modelo Pedimento.
        $query = Pedimento::query();

        // 2. CONDICIÓN BASE: El pedimento DEBE tener una auditoría asociada DIRECTAMENTE.
        // Esto resuelve el problema de los 6,400 registros y los 0 resultados.
        $query->where(function ($q) {
            $q->has('auditorias')->orHas('auditoriasTotalSC');
        });

        // 2. Filtro por Número de Pedimento (directo sobre la tabla pedimiento)
        $query->when($filters['pedimento'] ?? null, function ($q, $val) {
            return $q->where('num_pedimiento', 'like', "%{$val}%");
        });

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

        // 4. Aplicamos los filtros de relación según el tipo de operación
        if ($operationType === 'importacion') {
            $query->whereHas('importacion', $filtrosAplicados);
        } elseif ($operationType === 'exportacion') {
            $query->whereHas('exportacion', $filtrosAplicados);
        } else { // 'todos'
            // Para 'todos', un pedimento es válido si cumple los filtros en CUALQUIERA de sus operaciones
            $query->where(function($q) use ($filtrosAplicados) {
                $q->whereHas('importacion', $filtrosAplicados)
                ->orWhereHas('exportacion', $filtrosAplicados);  // El 'or' aquí es clave
            });
        }


        return $query;


    }
    //Metodo para mapear lo que se mostrara en la pagina
    private function transformarOperacion($pedimento, $filters)
    {
        // Los datos ahora vienen de las relaciones del modelo Importacion
        // Determinamos si el pedimento tiene una operación de importación o exportación cargada
        $operationType = $filters['operation_type'] ?? 'todos';
        $operacion = null;

        // Con esta lógica, forzamos a que se use la relación correcta
        // según el filtro que el usuario seleccionó.
        if ($operationType === 'importacion') {
            $operacion = $pedimento->importacion;
        } elseif ($operationType === 'exportacion') {
            $operacion = $pedimento->exportacion;
        } else { // Para el caso 'todos', mantenemos la prioridad de importación
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;
        }
        // Si por alguna razón no hay operación, no devolvemos nada.
        if (!$operacion) {
            return null;
        }

        $auditorias = $operacion->auditorias;
        $sc = $operacion->auditoriasTotalSC; // .first() porque es hasOne

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
        $resultados = $query->with([
                'importacion' => function ($q) {
                    $q->with(['auditorias', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                },
                'exportacion' => function ($q) {
                    $q->with(['auditorias', 'auditoriasTotalSC', 'cliente', 'getSucursal']);
                }
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

    //--------------------------------------------------------------------------------------------------------------
    //----------------------------------- INICIO DE LOS COMMANDS - AUDITCONTROLLER ---------------------------------
    //--------------------------------------------------------------------------------------------------------------

    //--- METODO IMPORTAR IMPUESTOS A AUDITORIAS
    // Se encarga de leer el estado de cuenta y obtener los Pedimentos, Cargos y fechas de este.
    // Este metodo sirve de base para iniciar todas las auditorias, debido que de aqui derivan los pedimentos
    // que se mostraran unicamente para el panel de auditorias, y son exclusivamente de aqui.
    public function importarImpuestosEnAuditorias(string $tareaId)
    {
        if (!$tareaId) {
            Log::error('Se requiere el ID de la tarea. Usa --tarea_id=X');
            return ['code' => 1, 'message' => new \Exception('Se requiere el ID de la tarea. Usa --tarea_id=X')];
        }

        // 1. Busca la primera tarea que esté pendiente
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            Log::warning("Impuestos: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return ['code' => 1, 'message' => new \Exception("Impuestos: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.")];
        }
        if (!$tarea) {
            Log::error("No se encontró la tarea con ID: {$tareaId}");
            return ['code' => 1, 'message' => new \Exception("No se encontró la tarea con ID: {$tareaId}")];
        }

        Log::info('Iniciando lectura de PDF con Python y Tabula...');


        // 2. Usa los datos del registro de la tarea
        $rutaPdf = storage_path('app/' . $tarea->ruta_estado_de_cuenta);
        $banco = $tarea->banco;
        $sucursal = $tarea->sucursal;

        Log::info("Procesando tarea #{$tarea->id} para el banco {$banco} y sucursal {$sucursal}");
        Log::info("Procesando: {$rutaPdf}");

        if (!file_exists($rutaPdf)) {
            $tarea->update(
            [
                'status' => 'fallido',
                'resultado' => "Ruta pdf no encontrada: ({$rutaPdf})"
            ]);
            return ['code' => 1, 'message' => new \Exception("Ruta pdf no encontrada: ({$rutaPdf})")];
        }

        $process = new Process(['python', base_path('scripts/python/parser.py'), $rutaPdf]);

        try {
            $process->mustRun();
            $jsonOutput = $process->getOutput();
            $jsonOutput = mb_convert_encoding($jsonOutput, "utf-8");
            // Ahora este json_decode debería funcionar sin problemas y sin encode previo.
            $tablaDeDatos = json_decode($jsonOutput, true);

            preg_match('/(?<=\d{2}\/\d{2}\/)\d{4}/', $tablaDeDatos[0][0], $matchYear); //Aqui se localiza el año del estado de cuenta - BBVA (CAMBIALO LUEGO)

            if (is_null($tablaDeDatos) || isset($tablaDeDatos['error'])) {
                Log::error("Error al decodificar el JSON o error devuelto por Python.");
                // Si hay error en Python, lo mostramos:
                if(isset($tablaDeDatos['error'])) { Log::error("Detalle: " . $tablaDeDatos['error']); }
                return ['code' => 1, 'message' => new \Exception("Error al decodificar el JSON o error devuelto por Python.")];
            }

            // Convertimos el array de datos crudos en una Colección de Laravel.
            $coleccionDeFilas = collect($tablaDeDatos);
            Log::info("Tabula y Python procesaron el PDF y encontraron {$coleccionDeFilas->count()} filas de datos crudos.");

            // 3. Usamos map() para transformar y filter() para limpiar la colección.
            $operacionesLimpias = $coleccionDeFilas->map(function ($fila) use ($matchYear) {
                // Verificamos que la fila tenga al menos 3 celdas (Fecha, Concepto, Cargo).
                if (isset($fila[0], $fila[1], $fila[2])) {
                    $textoConcepto = $fila[1];
                    // El Regex para encontrar el pedimento que ya conocemos.
                    $patron = '/PEDMTO:\s*([\w-]+)/';

                    if (preg_match($patron, $textoConcepto, $match)) {
                        // Si es una fila de pedimento válida, devolvemos un array limpio y estructurado.
                        return
                        [
                            'pedimento' => $match[1],
                            'fecha_str' => $fila[0],
                            'cargo_str' => $fila[2],
                        ];
                    }
                }
                return null; // Si no es una fila de pedimento, la marcamos para ser eliminada.
            })->filter(); // El método filter() elimina todos los resultados 'null'.

            if ($operacionesLimpias->isEmpty()) {
                Log::info('No se encontraron operaciones válidas para procesar.');
                return 0;
            }

            // Preparamos un array con TODOS los registros que vamos a guardar/actualizar
            $datosParaOperaciones = $operacionesLimpias->map(function ($op){ return ['pedimento' => $op['pedimento']];} )->all(); // ->all() lo convierte de nuevo a un array simple

            Log::info("Se identificaron ". count($datosParaOperaciones) . "/{$operacionesLimpias->count()} operaciones válidas para procesar.");
            // --- Llamamos a upsert UNA SOLA VEZ con todos los datos ---
            if (!empty($datosParaOperaciones)) {
                // 1. Obtenemos todos los números de pedimento únicos del estado de cuenta
                $numerosDePedimento = $operacionesLimpias->pluck('pedimento')->unique()->toArray();
                Log::info("Pedimentos del estado de cuenta: ". count($datosParaOperaciones));
                // 2. Hacemos UNA SOLA consulta para obtener los IDs de esos pedimentos
                //    y creamos un mapa: num_pedimento => id_pedimiento
                $mapaPedimentoAId = $this->construirMapaDePedimentos($numerosDePedimento);
                Log::info("Pedimentos encontrados en tabla 'pedimentos': ". count($mapaPedimentoAId));

                // 3. Hacemos UNA SOLA consulta a operaciones_importacion usando los IDs que encontramos
                //    y creamos nuestro mapa final: num_pedimento => id_importacion
                $mapaPedimentoAImportacionId = Importacion::whereIn('operaciones_importacion.id_pedimiento', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                    /* ->join('pedimiento', 'operaciones_importacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                    ->select('operaciones_importacion.id_importacion', 'pedimiento.num_pedimiento') */
                    ->orderBy('operaciones_importacion.created_at', 'desc')
                    ->get()
                    ->keyBy('id_pedimiento');

                Log::info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': ". $mapaPedimentoAImportacionId->count());


                $mapaPedimentoAExportacionId = Exportacion::whereIn('operaciones_exportacion.id_pedimiento', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                   /*  ->join('pedimiento', 'operaciones_exportacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                    ->select('operaciones_exportacion.id_exportacion', 'pedimiento.num_pedimiento') */
                    ->orderBy('operaciones_exportacion.created_at', 'desc')
                    ->get()
                    ->keyBy('id_pedimiento');

                Log::info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_exportacion': ". $mapaPedimentoAExportacionId->count());


                // 3. Obtenemos todas las SC de una vez para la comparación de montos
                $auditoriasSC = AuditoriaTotalSC::query()
                    ->whereIn('operacion_id', $mapaPedimentoAImportacionId->keys())
                    ->orWhereIn('operacion_id', $mapaPedimentoAExportacionId->keys())
                    ->orWhereIn('pedimento_id', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                    ->get()
                    ->keyBy('pedimento_id'); // Las indexamos por operacion_id para búsqueda rápida
                Log::info("Facturas SC encontradas con relacion a Impuestos: ". $auditoriasSC->count());

                // PASO 5: Construir el array para las auditorías, usando nuestro mapa.
                // Pasamos el mapa a la clausula `use` para que esté disponible dentro del `map`.
                $datosParaAuditorias = $operacionesLimpias->map(function ($op)
                use ($rutaPdf, $auditoriasSC, $mapaPedimentoAId, $mapaPedimentoAExportacionId, $mapaPedimentoAImportacionId, $tarea) {

                    $pedimentoLimpio = $op['pedimento'];
                    $pedimentoInfo = $mapaPedimentoAId[$pedimentoLimpio] ?? null;

                    if (!$pedimentoInfo) {
                        Log::warning("Omitiendo pedimento no encontrado en el mapa: {$pedimentoLimpio}");
                        return null;
                    }

                    $id_pedimento_db = $pedimentoInfo['id_pedimiento'];
                    $operacionId = null;
                    $tipoOperacion = null;

                    // Buscamos la operación más reciente en nuestros nuevos mapas
                    $ultimaImportacion = $mapaPedimentoAImportacionId->get($id_pedimento_db);
                    $ultimaExportacion = $mapaPedimentoAExportacionId->get($id_pedimento_db);

                    // Obtenemos el id_importacion desde nuestro mapa. Si no existe, omitimos este registro.
                    $pedimentoId = $mapaPedimentoAId[$pedimentoLimpio] ?? null;

                    // Damos prioridad a la operación más reciente entre impo y expo si ambas existen
                    if ($ultimaImportacion && $ultimaExportacion) {
                        if ($ultimaImportacion->created_at > $ultimaExportacion->created_at) {
                            $operacionId = $ultimaImportacion->id_importacion;
                            $tipoOperacion = Importacion::class;
                        } else {
                            $operacionId = $ultimaExportacion->id_exportacion;
                            $tipoOperacion = Exportacion::class;
                        }
                    } elseif ($ultimaImportacion) {
                        $operacionId = $ultimaImportacion->id_importacion;
                        $tipoOperacion = Importacion::class;
                    } elseif ($ultimaExportacion) {
                        $operacionId = $ultimaExportacion->id_exportacion;
                        $tipoOperacion = Exportacion::class;
                    }

                    if (!$operacionId) { // Si no esta ni en Importacion o en Exportacion, que lo guarde por pedimento_id
                        $tipoOperacion = "N/A";
                    }

                    if (!$operacionId && !$pedimentoId) {
                        Log::warning("Omitiendo pedimento no encontrado en operaciones_importacion: {$pedimentoLimpio}");
                        return null; // Marcamos para ser filtrado
                    }

                    // Buscamos la SC correspondiente en nuestro mapa de SCs
                    $sc = $auditoriasSC->get($pedimentoId['id_pedimiento']);
                    $desgloseSc = $sc ? $sc->desglose_conceptos : null;
                    $montoSCMXN = $desgloseSc['montos']['impuestos_mxn'] ?? -1.1; // -1.1 = Sin SC!


                    preg_match('/[^$\s\r\n].*/', $op['cargo_str'], $matchCargo);
                    $montoImpuestoMXN = (float) filter_var($matchCargo[0], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $estado = $this->compararMontos($montoSCMXN, $montoImpuestoMXN);

                    // Devolvemos el array completo, AHORA con el `operacion_id` correcto.
                    return
                    [
                        'operacion_id'      => $operacionId,
                        'pedimento_id'      => $id_pedimento_db,
                        'operation_type'    => $tipoOperacion,
                        'tipo_documento'    => 'impuestos',
                        'concepto_llave'    => 'principal',
                        'fecha_documento'   => \Carbon\Carbon::createFromFormat('d-m', $op['fecha_str'])->format('Y-m-d'),
                        'monto_total'       => (float) str_replace(',', '', $matchCargo[0] ?? '0'),
                        'monto_total_mxn'   => (float) str_replace(',', '', $matchCargo[0] ?? '0'),
                        'moneda_documento'  => 'MXN',
                        'estado'            => $estado,
                        'ruta_pdf'          => $rutaPdf,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];
                })->filter()->all(); // ->filter() elimina cualquier valor `null` que hayamos retornado en la verificación de seguridad.

                Log::info("Pedimentos con impuestos listos para subir: ". count($datosParaAuditorias));



                // PASO 6: Hacer el upsert a la tabla de auditorías.
                // Este código ya estaba casi perfecto, solo ajustamos los nombres de las columnas a actualizar.
                if (!empty($datosParaAuditorias)) {
                    Auditoria::upsert(
                        $datosParaAuditorias,
                        ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], // La llave única correcta
                        [
                            'fecha_documento',
                            'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                            'monto_total_mxn',
                            'moneda_documento',
                            'estado',
                            'ruta_pdf',
                            'updated_at'
                        ]);
                }
                Log::info('¡Guardado con éxito!');
            }
            Log::info('¡Base de datos de operaciones actualizada con éxito!');

            // --- ¡NUEVA LÓGICA! ---
            // Guardamos la lista de pedimentos procesados en la tarea para que los siguientes comandos la usen.
            if ($operacionesLimpias->isNotEmpty()) {
                $pedimentosProcesados = $operacionesLimpias->pluck('pedimento')->unique()->values()->all();
                $tarea->update(['pedimentos_procesados' => json_encode($pedimentosProcesados)]);
                Log::info("Se registraron " . count($pedimentosProcesados) . " pedimentos en la Tarea #{$tareaId}.");
            }
            // --- FIN DE LA NUEVA LÓGICA ---

            Log::info("Procesamiento de Impuestos para la Tarea #{$tareaId} finalizado.");
            return 0;

        } catch (ProcessFailedException $exception) {
            Log::error('Falló el script de Python: ' . $exception->getErrorOutput());
            return ['code' => 1, 'message' => new \Exception('Falló el script de Python: ' . $exception->getErrorOutput())];

        } catch (\Exception $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);
            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Falló la tarea #{$tarea->id}: " . $e->getMessage())];
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
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }
            Log::info("Procesando Facturas SC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            // 1. Obtenemos los números de pedimento de nuestro índice
            $numerosDePedimento = $pedimentos;

            $mapaPedimentoAId = $this->construirMapaDePedimentos($numerosDePedimento);
            $numerosDePedimento = collect($mapaPedimentoAId)->pluck('num_pedimiento')->toArray();
            Log::info("Pedimentos encontrados en tabla 'pedimentos': ". count($mapaPedimentoAId));

            // 2. MAPEADO EFICIENTE DE IDS
            // PROCESAR IMPORTACIONES
            $mapaPedimentoAImportacionId = Importacion::query()
                ->join('pedimiento', 'operaciones_importacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                ->select('operaciones_importacion.id_importacion', 'pedimiento.num_pedimiento', 'pedimiento.id_pedimiento')
                ->whereIn('pedimiento.num_pedimiento', $numerosDePedimento)
                ->orderBy('operaciones_importacion.created_at', 'desc')
                ->get()
                ->map(function($operacion){
                    return[
                        'pedimento'      => $operacion->num_pedimiento,
                        'id_operacion'   => $operacion->id_importacion,
                        'id_pedimento'   => $operacion->id_pedimiento,
                    ];
                })
                ->keyBy('pedimento');
            Log::info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': ". $mapaPedimentoAImportacionId->count());

            // 2. MAPEADO EFICIENTE DE IDS
            // PROCESAR EXPORTACIONES
            $mapaPedimentoAExportacionId = Exportacion::query()
                ->join('pedimiento', 'operaciones_exportacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                ->select('operaciones_exportacion.id_exportacion', 'pedimiento.num_pedimiento', 'pedimiento.id_pedimiento')
                ->whereIn('pedimiento.num_pedimiento', $numerosDePedimento)
                ->orderBy('operaciones_exportacion.created_at', 'desc')
                ->get()
                ->map(function($operacion){
                    return[
                        'pedimento'      => $operacion->num_pedimiento,
                        'id_operacion'   => $operacion->id_exportacion,
                        'id_pedimento'   => $operacion->id_pedimiento,
                    ];
                })
                ->keyBy('pedimento');


            Log::info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_exportacion': ". $mapaPedimentoAExportacionId->count());

            // --- LOGICA PARA DETECTAR LOS NO ENCONTRADOS
            //Esto lo hago debido a que hay pedimentos que estan bastante sucios que ni se pueden encontrar
            //Un ejemplo es que haya dos registros con exactamente el mismo valor, pero con la diferencia de que tiene un carrete
            //un enter o una tabulacion en el registro, volviendola 'unica'. Y aqui lo que hare es mostrar esos pedimentos que
            //causan confusion y los subire a la tabla de tareas para que queden expuestos ante todo el mundo! awawaw

            // 1. Preparamos la búsqueda REGEXP para la base de datos
            $regexPattern = implode('|', array_unique($numerosDePedimento)); // Usamos array_unique para una query más corta
            $posiblesCoincidencias = Pedimento::where('num_pedimiento', 'REGEXP', $regexPattern)->get();

            // 2. Creamos un mapa de los pedimentos que nos falta por encontrar.
            //    OJO: Esta vez lo creamos a partir de la lista original CON duplicados.
            $pedimentosPorEncontrar = array_count_values($numerosDePedimento);
            $mapaNoEncontrados = [];

            // 3. (LÓGICA CORREGIDA) Recorremos los resultados de la BD
            foreach ($posiblesCoincidencias as $pedimentoSucio) {
                if (empty($pedimentosPorEncontrar)) {
                    break;
                }

                $pedimentoObtenido = $pedimentoSucio->num_pedimiento;

                foreach ($pedimentosPorEncontrar as $pedimentoLimpio => $cantidad) {
                    if (str_contains($pedimentoObtenido, $pedimentoLimpio)) {
                        // ----- INICIO DE LA CORRECCIÓN -----

                        // a. Mapeamos la coincidencia (opcional, igual que antes)
                        if ($pedimentosPorEncontrar[$pedimentoLimpio] > 1) {
                            $mapaNoEncontrados[$pedimentoLimpio] = [
                            'id_pedimento' => $pedimentoSucio->id_pedimiento,
                            'num_pedimiento' => $pedimentoObtenido,
                        ];
                        }


                        // b. Restamos 1 al contador de este pedimento.
                        $pedimentosPorEncontrar[$pedimentoLimpio]--;

                        // c. Si ya encontramos todas las ocurrencias, lo eliminamos de la lista.
                        if ($pedimentosPorEncontrar[$pedimentoLimpio] === 0) {
                            unset($pedimentosPorEncontrar[$pedimentoLimpio]);
                        }

                        // d. Rompemos el bucle interno. Un pedimento sucio solo puede "satisfacer"
                        //    a un pedimento limpio por pasada. Esto lo hace más rápido y correcto.
                        break;

                        // ----- FIN DE LA CORRECCIÓN -----
                    }
                }
            }

            // 5. Mostramos los que quedaron en la lista de pendientes. ESTO SÍ FUNCIONARÁ.
            if (!empty($pedimentosPorEncontrar)) {
                $tarea->update([
                    'pedimentos_descartados' => $pedimentosPorEncontrar
                ]);
                Log::warning("Subiendo pedimentos no encontrados!");
            } else {
                Log::info("¡Todos los pedimentos fueron encontrados y mapeados correctamente!");
            }

            // CONSTRUIR EL ÍNDICE - EXPORTACIONES
            Log::info("Iniciando mapeo de archivos de exportaciones.");
            $indiceExportaciones = $this->construirIndiceFacturasParaMapeo($mapaPedimentoAExportacionId, $sucursal, 'exportaciones');
            Log::info("Mapeo de exportaciones finalizado!");

            // --- OJO: Aqui se gastan muchos recursos en el mapeo!!!
            // CONSTRUIR EL ÍNDICE - IMPORTACIONES
            Log::info("Iniciando mapeo de archivos de importaciones.");
            $indiceImportaciones = $this->construirIndiceFacturasParaMapeo($mapaPedimentoAImportacionId,  $sucursal, 'importaciones');
            Log::info("Mapeo de importaciones finalizado!");


            $mapeadoOperacionesID =
            [
                'pedimentos_totales'        => $mapaPedimentoAId,
                'pedimentos_no_encontrados' => $mapaNoEncontrados,
                'pedimentos_importacion'    => $mapaPedimentoAImportacionId,
                'pedimentos_exportacion'    => $mapaPedimentoAExportacionId,
                'indices_importacion'       => $indiceImportaciones,
                'indices_exportacion'       => $indiceExportaciones,

            ];
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
            return 0;

        } catch (\Exception $e) {
            Log::error("Fallo en Tarea #{$tarea->id} [reporte:mapear-facturas]: " . $e->getMessage());
            $tarea->update(['status' => 'fallido', 'resultado' => 'Error al generar el mapeo de facturas.' . $e->getMessage() ]);
            return ['code' => 1, 'message' => new \Exception("Fallo en Tarea #{$tarea->id} [reporte:mapear-facturas]: " . $e->getMessage())];
        }
    }


    //--- METODO IMPORTAR SC A AUDITORIAS_TOTALES_SC
    // Se encarga de leer el archivo de TXT de la SC y obtener todos los montos de impuestos requeridos para la auditoria
    // Este metodo sirve para dejar mapeados y a la mano los montos requeridos para los demas impuestos, para hacer comparativas
    // ,conversiones y condiciones que dependen de la SC como auxiliar.
    public function importarFacturasSCEnAuditoriasTotalesSC(string $tareaId)
    {
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
            $mapeadoFacturas = (array)json_decode($contenidoJson, true);

            //Leemos los demas campos de la tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("SC: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
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
                return 0;
            }
            Log::info("Se encontraron " . count($indiceSC) . " facturas SC en los archivos.");
            // 4. PREPARAR DATOS PARA GUARDAR EN 'auditorias_totales_sc'

            Log::info("Iniciando mapeo para Upsert.");

            $auditoriasParaGuardar = [];
            //$bar = $this->output->createProgressBar(count($indiceSC));
            //$bar->start();
            foreach ($indiceSC as $pedimento => $datosSC) {

                // Buscamos el id_importacion en nuestro mapa
                $pedimentoReal = $mapaPedimentoAId[$pedimento];
                $tipoOperacion = Importacion::class;

                //Se verifica si la operacion ID esta en Importacion
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoReal['num_pedimiento']] ?? null;

                if (!$operacionId) { //Si no, entonces busca en Exportacion
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoReal['num_pedimiento']]  ?? null;
                    $tipoOperacion = Exportacion::class;
                }

                if (!$operacionId) { //Si no esta ni en Importacion o en Exportacion, que lo guarde por pedimento_id
                    $tipoOperacion = "N/A";
                }

                if (!$operacionId && !$pedimentoId) {
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
                        'sc'                => $datosSC['monto_total_sc'],
                        'sc_mxn'            => ($datosSC['moneda'] == "USD" && $datosSC['monto_total_sc'] != -1) ? round($datosSC['monto_total_sc'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_total_sc'],

                        'impuestos'         => $datosSC['monto_impuestos'],
                        'impuestos_mxn'     => ($datosSC['moneda'] == "USD" && $datosSC['monto_impuestos'] != -1) ? round($datosSC['monto_impuestos'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_impuestos'],

                        'pago_derecho'      => $datosSC['monto_total_pdd'],
                        'pago_derecho_mxn'  => ($datosSC['moneda'] == "USD" && $datosSC['monto_total_pdd'] != -1) ? round($datosSC['monto_total_pdd'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_total_pdd'],

                        'llc'               => $datosSC['monto_llc'],
                        'llc_mxn'           => ($datosSC['moneda'] == "USD" && $datosSC['monto_llc'] != -1) ? round($datosSC['monto_llc'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_llc'],

                        'flete'             => $datosSC['monto_flete'],
                        'flete_mxn'         => ($datosSC['moneda'] == "USD" && $datosSC['monto_flete'] != -1) ? round($datosSC['monto_flete'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_flete'],

                        'maniobras'         => $datosSC['monto_maniobras'],
                        'maniobras_mxn'     => ($datosSC['moneda'] == "USD" && $datosSC['monto_maniobras'] != -1) ? round($datosSC['monto_maniobras'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_maniobras'],

                        'muestras'          => $datosSC['monto_muestras'],
                        'muestras_mxn'      => ($datosSC['moneda'] == "USD" && $datosSC['monto_muestras'] != -1) ? round($datosSC['monto_muestras'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_muestras'],

                        'termo'             => $datosSC['monto_termo'],
                        'termo_mxn'         => ($datosSC['moneda'] == "USD" && $datosSC['monto_termo'] != -1) ? round($datosSC['monto_termo'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_termo'],

                        'rojos'             => $datosSC['monto_rojos'],
                        'rojos_mxn'         => ($datosSC['moneda'] == "USD" && $datosSC['monto_rojos'] != -1) ? round($datosSC['monto_rojos'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_rojos'],
                    ]
                ];

                $auditoriasParaGuardar[] =
                [
                    'operacion_id'       => isset($operacionId['id_operacion']) ? $operacionId['id_operacion'] : null, // ¡La vinculación auxiliar correcta!
                    'pedimento_id'       => $pedimentoReal['id_pedimiento'], // ¡La vinculación correcta!
                    'operation_type'     => $tipoOperacion,
                    'folio_documento'    => $datosSC['folio_sc'],
                    'fecha_documento'    => $datosSC['fecha_sc'],
                    'desglose_conceptos' => json_encode($desgloseSC),
                    'ruta_txt'           => $datosSC['ruta_txt'],
                    'ruta_pdf'           => $datosSC['ruta_pdf'],
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ];
                //$bar->advance();
            }

            // 5. GUARDAR EN BASE DE DATOS
            if (!empty($auditoriasParaGuardar)) {

                Log::info("\nGuardando/Actualizando " . count($auditoriasParaGuardar) . " registros de SC...");

                AuditoriaTotalSC::upsert(
                    $auditoriasParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type'], // La llave única
                    ['folio_documento', 'fecha_documento', 'desglose_conceptos', 'ruta_txt', 'ruta_pdf', 'updated_at']
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
                $mapeadoFacturas['auditorias_sc'] = $auditoriasSC->toArray();
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
                    'updated_at'              => now(),
                ]);

                //Borramos el anterior
                Storage::delete($rutaMapeo);
                Log::info("SCs guardadas, actualizadas y registradas con exito!");
            }
            else {
                Log::info("No se encontraron SCs para guardar en la base de datos.");
            }

        }
        catch (\Exception $e) {
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);
            Log::error("Error al procesar SC para la Tarea #{$tareaId}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Error al procesar SC para la Tarea #{$tareaId}: " . $e->getMessage())]; // Lanzamos la excepción para que el orquestador la atrape
        }
    }


    //--- METODO AUDITAR E IMPORTAR FLETES A AUDITORIAS
    // Se encarga de leer el archivo de TXT de los Fletes, obteniendo los montos y datos generales necesarios para la auditoria
    public function auditarFacturasDeFletes(string $tareaId)
    {
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
            $mapeadoFacturas = (array)json_decode($contenidoJson, true);

            //Leemos los demas campos de la tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
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
                    'monto_flete_sc' => (float)$desglose['montos']['flete'],
                    'monto_flete_sc_mxn' => (float)$desglose['montos']['flete_mxn'],
                    'moneda' => $desglose['moneda'],
                    // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                    // para asignar un valor por defecto (ej. 1) si no se encuentra.
                    'tipo_cambio' => (float)$desglose['tipo_cambio'] ?? 1.0,
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
                    $tipoOperacion = "N/A";
                }

                if (!$operacionId && !$pedimentoId) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
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
                            'monto_flete_sc'     => -1,
                            'monto_flete_sc_mxn' => -1,
                            'tipo_cambio'        => -1,
                            'moneda'             => 'N/A',
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

                // Añadimos el resultado al array para el upsert masivo
                $fletesParaGuardar[] =
                [
                    'operacion_id'      => $operacionId['id_operacion'],
                    'pedimento_id'      => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type'    => $tipoOperacion,
                    'tipo_documento'    => 'flete',
                    'concepto_llave'    => 'principal',
                    'folio'             => $datosFlete['folio'],
                    'fecha_documento'   => date('Y-m-d', date_timestamp_get(DateTime::createFromFormat('d/m/Y', $datosFlete['fecha']))),
                    'monto_total'       => $datosFlete['total'],
                    'monto_total_mxn'   => $montoFleteMXN,
                    'moneda_documento'  => $datosFlete['moneda'],
                    'estado'            => $estado,
                    'ruta_xml'          => $datosFlete['path_xml_tr'],
                    'ruta_txt'          => $datosFlete['path_txt_tr'],
                    'ruta_pdf'          => $datosFlete['path_pdf_tr'],
                    'created_at'        => now(),
                    'updated_at'        => now(),
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
                        'moneda_documento',
                        'estado',
                        'ruta_xml',
                        'ruta_txt',
                        'ruta_pdf',
                        'updated_at'
                    ]);

                Log::info("¡Guardado con éxito!");
            }

            Log::info("\nAuditoría de Fletes finalizada.");
            return 0;
        } catch (\Exception $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);

            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Falló la tarea #{$tarea->id}: " . $e->getMessage())];
        }
    }


    //--- METODO AUDITAR E IMPORTAR LLCS A AUDITORIAS
    // Se encarga de leer el archivo de TXT de las LLCs, obteniendo los montos y datos generales necesarios para la auditoria
    public function auditarFacturasDeLLC(string $tareaId)
    {
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
            $mapeadoFacturas = (array)json_decode($contenidoJson, true);

            //Leemos los demas campos de la tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("LLC: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
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
                    'monto_llc_sc' => (float)$desglose['montos']['llc'],
                    'monto_llc_sc_mxn' => (float)$desglose['montos']['llc_mxn'],
                    'moneda' => $desglose['moneda'],
                    // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                    // para asignar un valor por defecto (ej. 1) si no se encuentra.
                    'tipo_cambio' => (float)$desglose['tipo_cambio'] ?? 1.0,
                ];
            }

            Log::info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");

            Log::info("Iniciando mapeo para Upsert.");
            //----------------------------------------------------
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
                    $tipoOperacion = "N/A";
                }

                if (!$operacionId && !$pedimentoId) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    //$bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                // Buscamos en nuestros índices en memoria (búsqueda instantánea)
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;
                $datosLlc = $indiceLLC[$pedimentoLimpio] ?? null;

                if (!$datosLlc) {
                    //$bar->advance();
                    continue;

                } elseif (!$datosSC && $rutaTxtLlc) {
                    // Aqui es cuando hay LLC pero no existe SC para esta factura.\\
                    $datosSC =
                    [
                        'monto_llc_sc'      => -1,
                        'monto_llc_sc_mxn'  => -1,
                        'tipo_cambio'       => -1,
                        'moneda'            => 'N/A',
                    ];
                }

                $montoLLCMXN = $datosSC['monto_llc_sc'] != -1 ?  round($datosLlc['monto_total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : -1;
                $montoSCMXN = $datosSC['monto_llc_sc_mxn'];
                // --- FASE 4: Comparar y Preparar Datos ---
                $estado = $this->compararMontos_LLC($montoSCMXN, $montoLLCMXN);

                $llcsParaGuardar[] =
                [
                    'operacion_id'      => $operacionId['id_operacion'],
                    'pedimento_id'      => $pedimentoSucioYId['id_pedimiento'],
                    'operation_type'    => $tipoOperacion,
                    'tipo_documento'    => 'llc',
                    'concepto_llave'    => 'principal',
                    'folio'             => $datosLlc['folio'],
                    'fecha_documento'   => $datosLlc['fecha'],
                    'monto_total'       => $datosLlc['monto_total'],
                    'monto_total_mxn'   => $montoLLCMXN,
                    'moneda_documento'  => 'USD',
                    'estado'            => $estado,
                    'ruta_txt'          => $datosLlc['ruta_txt'],
                    'ruta_pdf'          => $datosLlc['ruta_pdf'],
                    'created_at'        => now(),
                    'updated_at'        => now(),
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
                        'moneda_documento',
                        'estado',
                        'ruta_txt',
                        'ruta_pdf',
                        'updated_at'
                    ]);
                Log::info("¡Guardado con éxito!");
            }

            Log::info("\nAuditoría de LLC finalizada.");
            return 0;
        } catch (\Exception $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);

            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Falló la tarea #{$tarea->id}: " . $e->getMessage())];
        }
    }


    //--- METODO AUDITAR E IMPORTAR PAGOS DE DERECHO A AUDITORIAS
    // Se encarga de leer el archivo de TXT de los Pagos de derecho, obteniendo los montos y datos generales necesarios para la auditoria
    public function auditarFacturasDePagosDeDerecho(string $tareaId)
    {
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
            $mapeadoFacturas = (array)json_decode($contenidoJson, true);

            //Leemos los demas campos de la tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                Log::info("Pagos de derecho: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
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
                    $tipoOperacion = "N/A";
                }

                if (!$operacionId && !$pedimentoId) {
                    Log::warning("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    //$bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                $rutasPdfs = $indicePagosDerecho[$pedimentoSucioYId['num_pedimiento']] ?? null;
                if (!$rutasPdfs) {
                    //$bar->advance();
                    continue;
                }
                foreach ($rutasPdfs as $rutaPdf) {

                    if($rutaPdf === "https://sistema.intactics.com/v2/uploads/3010233-Pago-GUIA-TRANSITO-3711-5001848-MERCADO-MK-.pdf"){
                    $ao = 0;
                    }
                    //Parseamos cada PDF encontrado.
                    $datosPago = $this->parsearPdfPagoDeDerecho($rutaPdf) ?? null;

                    if ($datosPago) {
                        if (isset($datosPago['ruta_alternativa'])) {
                            $rutaPdf = $datosPago['ruta_alternativa'];
                        }
                        if(!$datosPago['llave_pago'])
                        {
                            $ao = 0;
                        }
                        // 4. Si obtuvimos datos, los acumulamos para el guardado masivo.
                        $pagosParaGuardar[] =
                        [
                            'operacion_id'      => $operacionId['id_operacion'],
                            'pedimento_id'      => $pedimentoSucioYId['id_pedimiento'],
                            'operation_type'    => $tipoOperacion,
                            'tipo_documento'    => 'pago_derecho',
                            'concepto_llave'    => $datosPago['llave_pago'],
                            'fecha_documento'   => $datosPago['fecha_pago'],
                            'monto_total'       => $datosPago['monto_total'],
                            'monto_total_mxn'   => $datosPago['monto_total'],
                            'moneda_documento'  => 'MXN',
                            'estado'            => $datosPago['tipo'],
                            'llave_pago_pdd'    => $datosPago['llave_pago'],
                            'num_operacion_pdd' => $datosPago['numero_operacion'],
                            'ruta_pdf'          => $rutaPdf,
                            'created_at'        => now(),
                            'updated_at'        => now(),
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
                        'moneda_documento',
                        'estado',
                        'llave_pago_pdd',
                        'num_operacion_pdd',
                        'ruta_pdf',
                        'updated_at'
                    ]);
                Log::info("¡Guardado con éxito!");
            }

            Log::info("\nAuditoría de Pagos de Derecho finalizada.");
            return 0;
        }
        catch (\Exception $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);
            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            return ['code' => 1, 'message' => new \Exception("Falló la tarea #{$tarea->id}: " . $e->getMessage())];
        }
    }


    //--------------------------------------------------------------------------------------------------------------
    //----------------------------------- FINAL DE LOS COMMANDS - AUDITCONTROLLER ----------------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================

    //--------------------------------------------------------------------------------------------------------------
    //----------------------------- INICIO DE LOS METODOS INDEXANTES - AUDITCONTROLLER -----------------------------
    //--------------------------------------------------------------------------------------------------------------


    /**
     * Lógica central para obtener y procesar los archivos de la API.
     * UTILIZADO EN [mapearFacturasYFacturasSCEnAuditorias()]
     */
    private function construirIndiceFacturasParaMapeo(Collection $pedimentosOperacion, string $sucursal, string $tipoOperacion): array
    {
        $indiceFacturas = [];

        $mapeoFacturas =
        [
            'HONORARIOS-SC' => 'sc',
            'TransporTactics' => 'flete',
            'HONORARIOS-LLC' => 'llc',
            'PAGOS-DE-DERECHOS' => 'pago_derecho',
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
                            'creation_date'  => $fechaCreacion,
                            'update_date'    => $fechaActualizacion,
                            'tipo_documento' => $mapeoFacturas[$tipoJson], // Usamos el nombre amigable
                            'ruta_pdf'       => null,
                            'ruta_xml'       => null,
                            'ruta_txt'       => null,
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
                        switch($tipoJson){
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
                $indiceFacturas[$pedimento]['facturas'] = array_values($agrupadorTemp);
                //$bar->advance();

            } catch (\Exception $e) {
                Log::error("Ocurrió un error procesando la operacion ID ({$tipoOperacion}) {$operacionID['id_operacion']}: " . $e->getMessage());
                // Aseguramos que haya una entrada para este pedimento aunque falle, para evitar errores posteriores.
                if (!isset($indiceFacturas[$pedimento])) {
                     $indiceFacturas[$pedimento] = ['error' => $e->getMessage()];
                }
            }

        }
        //$bar->finish();
        return $indiceFacturas;
    }


    /**
     * Lógica central para obtener y procesar los archivos de la factura SC e indexarlos por pedimentos.
     * UTILIZADO EN [mapearFacturasYFacturasSCEnAuditorias()]
     */
    private function construirIndiceSC(array $indicesOperacion): array
    {
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
                } catch (\Exception $th) {

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
                            "verify_peer"      => false,
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
                if (preg_match('/(?<=\[encOBSERVACION\])(\d*\-*)(\d{7})/', $contenido, $matchesPedimento)) {

                    $pedimento = trim($matchesPedimento[2]);
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
                    if($matchTCCount == 0) {
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }
                    elseif(($matchTC[1] == "1" && $matchMoneda[1] == "2")) {
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }

                    $indice[$pedimento] =
                    [
                        'monto_impuestos'   => isset($matchM_Impuesto[1]) && strlen($matchM_Impuesto[1]) > 0 ? (float)trim($matchM_Impuesto[1]) : -1,
                        'monto_flete'       => isset($matchM_Tr[1]) && strlen($matchM_Tr[1]) > 0 ? (float)trim($matchM_Tr[1]) : -1,
                        'monto_llc'         => isset($matchM_LLC[1]) && strlen($matchM_LLC[1]) > 0 ? (float)trim($matchM_LLC[1]) : -1,
                        'monto_total_pdd'   => isset($matchM_PDD[1]) && strlen($matchM_PDD[1]) > 0 ? (float)trim($matchM_PDD[1]) : -1,
                        'monto_maniobras'   => isset($matchM_Man[1]) && strlen($matchM_Man[1]) > 0 ? (float)trim($matchM_Man[1]) : -1,
                        'monto_muestras'    => isset($matchM_Mue[1]) && strlen($matchM_Mue[1]) > 0 ? (float)trim($matchM_Mue[1]) : -1,

                        'folio_sc'          => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                        'fecha_sc'          => \Carbon\Carbon::parse(trim($facturaSC['update_date']))->format('Y-m-d'),
                        //isset($matchFecha[2]) ? \Carbon\Carbon::parse(trim($matchFecha[1]))->format('Y-m-d') : now(), //ESTO PUEDES DECIRLE QUE TE LO IGUAL A NULL, NO HAY FECHA DENTRO DE LA SC
                        'ruta_txt'          => $facturaSC['ruta_txt'],
                        'ruta_pdf'          => $facturaSC['ruta_pdf'],
                        'moneda'            => isset($matchMoneda[1]) && $matchMoneda[1] == "1" ? "MXN" : "USD",
                        'tipo_cambio'       => isset($matchTC[1]) ? (float)trim($matchTC[1]) : 1.0,
                        'monto_total_sc'    => isset($matchTotalSC[1]) && strlen($matchTotalSC[1]) > 0 ? (float)trim($matchTotalSC[1]) : -1,
                    ];

                    $aux = $this->extraerMultiplesConceptos($contenido);
                    if($aux['monto_maniobras_2'] > $indice[$pedimento]['monto_maniobras']) {
                        $indice[$pedimento]['monto_maniobras'] = $aux['monto_maniobras_2'];
                    }

                    unset($aux['monto_maniobras_2']);
                    $indice[$pedimento] = array_merge($indice[$pedimento], $aux);
                }
                //$bar->advance();
            }
        } catch (\Exception $e) {
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());

        }
        return $indice;
    }


    /**
     * Lee todos los TXT de Fletes recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceOperacionesFletes(array $indicesOperacion): array
    {
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
                    } catch (\Exception $th) {

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
                                "verify_peer"      => false,
                                "verify_peer_name" => false,
                            ],
                        ];
                        //$urls[0] - SC
                        //$urls[2] - Flete
                        $contenido = $urls ?  file_get_contents('https://sistema.intactics.com' . $urls[2]['path'], false, stream_context_create($arrContextOptions)) : null;
                    }

                    if (!$contenido) {
                        //$bar->advance();
                        continue;
                    }

                // Refinamiento: Regex más preciso para el pedimento en la observación.
                if (preg_match('/(?<=\[encOBSERVACION\])(\d*\-*)(\d{7})/', $contenido, $matches)) {
                    preg_match('/\[cteTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchFecha);
                    preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);
                    $pedimento = $matches[2];
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
        } catch (\Exception $e) {
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }

        return $indice;
    }


     /**
     * Lee todos los TXT de LLCs recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceOperacionesLLCs(array $indicesOperacion): array
    {
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
                                "verify_peer"      => false,
                                "verify_peer_name" => false,
                            ],
                        ];
                        $contenido = file_get_contents($facturaLLC['ruta_txt'], false, stream_context_create($arrContextOptions));
                    } catch (\Exception $th) {
                        // En las LLC no se verificara si existe el archivo dentro del GET del files-txt-momentaneo
                        // debido a que no existe o no se presenta dentro del JSON. Por lo tanto, si no existe el url construido
                        // con anterioridad, simplemente continuara con el siguiente archivo.

                        $contenido = null;
                    }

                    if (!$contenido) {
                        //$bar->advance();
                        continue;
                    }

                // Refinamiento: Regex más preciso para el pedimento en la observación.
                if (preg_match('/(?<=\[encPdfRemarksNote3\])(.*)(\b\d{7}\b)/', $contenido, $matchPedimento)) {
                    $pedimento = trim($matchPedimento[2]);

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
        } catch (\Exception $e) {
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
            }

            catch (\Exception $e) {
                Log::error("Error construyendo el índice de Pagos de Derecho: " . $e->getMessage());
            }
        //$bar->finish();
        return $indice;
    }


    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ FINAL DE LOS METODOS INDEXANTES - AUDITCONTROLLER -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================

    //--------------------------------------------------------------------------------------------------------------
    //----------------------------- INICIO DE LOS METODOS AUXILIARES - AUDITCONTROLLER -----------------------------
    //--------------------------------------------------------------------------------------------------------------


    // Se encarga de hacer la comparativa de montos
    private function compararMontos(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'EXPO'; }
        if($esperado == -1.1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin Flete!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) { return 'Coinciden!'; }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }

    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     * UTILIZADO EN [auditarFacturasDeFletes()]
     */
    private function compararMontos_Fletes(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin Flete!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) { return 'Coinciden!'; }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }


    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     * UTILIZADO EN [auditarFacturasDeFletes()]
     */
    private function compararMontos_LLC(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin LLC!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) { return 'Coinciden!'; }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }


    /**
     * Parsea un archivo XML de Transportactics y devuelve los datos clave.
     * UTILIZADO EN [auditarFacturasDeFletes()]
     */
    private function parsearXmlFlete(string $rutaXml): ?array
    {

        try {
            //$arrContextOptions resuelve el siguiente error:
            //'file_get_contents(): SSL operation failed with code 1. OpenSSL Error messages:
            //error:1416F086:SSL routines:tls_process_server_certificate:certificate verify failed'
            $arrContextOptions =
            [
                "ssl" =>
                [
                    "verify_peer"      => false,
                    "verify_peer_name" => false,
                ],
            ];
            // Usamos SimpleXMLElement, que es nativo de PHP.
            $xml = new \SimpleXMLElement(file_get_contents($rutaXml, false, stream_context_create($arrContextOptions)));

            // Devolvemos un array con los datos que nos interesan.
            return
                [
                    'total'  => (float) $xml['Total'],
                    'moneda' => (string) $xml['Moneda'],
                ];
            } catch (\Exception $e) {
                Log::error("Error al parsear el XML {$rutaXml}: " . $e->getMessage());
                return
                [
                    'total'  => -1,
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
            if (empty($conceptosPendientes)) { break; }

            // 3. Dentro de cada bloque, iteramos sobre los conceptos que aún nos faltan por encontrar.
            foreach ($conceptosPendientes as $index => $concepto) {
                $lineaNombreBuscada = '[movPRODUCTONOMBRE]' . $concepto;

                // 4. Verificamos si el concepto actual está en este bloque.
                if (str_contains($bloque, $lineaNombreBuscada)) {
                    // ¡Encontrado! Ahora extraemos su precio.
                    if (preg_match('/\[movPRODUCTOPRECIO\](.*)/', $bloque, $matches)) {
                        $precio = trim($matches[1]);

                        // 5. Guardamos el resultado usando el nombre del concepto como clave.
                        $resultados[$index] = (float)$precio;

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
    private function construirMapaDePedimentos(array $pedimentosLimpios): array
    {
        if (empty($pedimentosLimpios)) { return []; }

        // 1. Hacemos una única consulta a la BD para traer todos los registros
        //    que POTENCIALMENTE contienen nuestros números.
        $query = Pedimento::query();
        $regexPattern = implode('|', $pedimentosLimpios);

            // Obtenemos solo las columnas que necesitamos
        $posiblesCoincidencias = $query->where('num_pedimiento', 'REGEXP', $regexPattern)->get(['id_pedimiento', 'num_pedimiento']);
        // 1. Creamos un mapa de los pedimentos que nos falta por encontrar.
        //    Usamos array_flip para que la búsqueda y eliminación sea instantánea.
        $pedimentosPorEncontrar = array_flip($pedimentosLimpios);

        //Ahora, procesamos los resultados en PHP para crear el mapa definitivo.
        $mapaFinal = [];
        // 2. Recorremos los resultados de la BD UNA SOLA VEZ.
        foreach ($posiblesCoincidencias as $pedimentoSucio) {
            // Si ya no quedan pedimentos por buscar, salimos del bucle para máxima eficiencia.
            if (empty($pedimentosPorEncontrar)) { break; }

            $pedimentoObtenido = $pedimentoSucio->num_pedimiento;

            // 3. Revisamos cuáles de los pedimentos PENDIENTES están en el string sucio actual.
            foreach ($pedimentosPorEncontrar as $pedimentoLimpio => $value) {
                if (str_contains($pedimentoObtenido, $pedimentoLimpio)) {
                    // ¡Coincidencia! La guardamos en el resultado final.
                    $mapaFinal[$pedimentoLimpio] =
                    [
                        'id_pedimiento' => $pedimentoSucio->id_pedimiento,
                        'num_pedimiento' => $pedimentoObtenido,
                    ];

                    // 4. (La optimización clave) Eliminamos el pedimento de la lista de pendientes.
                    //    Así, nunca más se volverá a buscar.
                    unset($pedimentosPorEncontrar[$pedimentoLimpio]);
                }
            }
        }

        // 5. (Opcional) Al final, lo que quede en $pedimentosPorEncontrar son los que no se encontraron.
        //    Podemos lanzar los warnings de forma mucho más eficiente.
        foreach (array_keys($pedimentosPorEncontrar) as $pedimentoNoEncontrado) {
            Log::warning('Omitiendo pedimento no encontrado en tabla \'pedimiento\': ' . $pedimentoNoEncontrado);
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
        try {
            $rutaAlternativa = null;
            // 1. Crear una instancia del Parser.
            $parser = new Parser();
            try
            {
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
            if ($texto === '' || str_contains($texto, 'citibanamex')) { return null; }
            // --- FIN DEL CAMBIO ---
            $datos = [];

            if($rutaAlternativa){
                $datos['ruta_alternativa'] = $rutaAlternativa;
            }
            // Lógica para detectar el tipo de banco y aplicar el Regex correcto
            if (str_contains($texto, 'Creando Oportunidades')) {   // Es BBVA
                // Regex para BBVA
                preg_match('/No\.\s*de\s*Operaci.n:\s*(\d+)/', $texto, $matchOp);
                preg_match('/Llave\s*de\s*Pago:\s*([A-Z0-9]+)/', $texto, $matchLlave);
                preg_match('/Total\s*Efectivamente\s*Pagado:\s*\$ ([\d,.]+)/', $texto, $matchMonto);
                preg_match('/Fecha\s*y\s*Hora\s*del\s*Pago:\s*(\d{2}\/\d{2}\/\d{4})/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float)str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('d/m/Y', $matchFecha[1])->format('Y-m-d') : null;

            }
            else {
                // Asumimos que es Santander
                // Leemos la "cadena mágica" de la segunda página
                preg_match('/\|20002=(\d+)\|/', $texto, $matchOp);
                preg_match('/\|40008=([A-Z0-9]+)\|/', $texto, $matchLlave);
                preg_match('/\|10017=([\d,.]+)\|/', $texto, $matchMonto);
                preg_match('/\|40002=(\d{8})\|/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float)str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('Ymd', $matchFecha[1])->format('Y-m-d') : null;
            }

            // Lógica para determinar el 'tipo' (Normal, Medio, etc.) basado en el nombre del archivo
            if(str_contains($rutaPdf, 'MEDIO')) { $datos['tipo'] = 'Medio Pago'; }
            elseif(str_contains($rutaPdf, '-2')) { $datos['tipo'] = 'Segundo Pago'; }
            elseif(str_contains($rutaPdf, 'INTACTICS')) { $datos['tipo'] = 'Intactics'; }
            else { $datos['tipo'] = 'Normal'; }

            if ($datos['llave_pago']) return $datos;
            else return null;

        } catch(\Exception $e) {
            Log::error("Error al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            return null;
        }


    }


    //--------------------------------------------------------------------------------------------------------------
    //------------------------------ FINAL DE LOS METODOS AUXILIARES - AUDITCONTROLLER -----------------------------
    //--------------------------------------------------------------------------------------------------------------

    //================================================================================================================================================================
}
