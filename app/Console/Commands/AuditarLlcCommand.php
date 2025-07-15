<?php

namespace App\Console\Commands;

use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Pedimento;
use App\Models\Importacion; // Tu modelo para 'operaciones_importacion'
use App\Models\Sucursales;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;// Importamos el nuevo modelo

class AuditarLlcCommand extends Command
{
    protected $signature = 'reporte:auditar-llc {--tarea_id= : El ID de la tarea a procesar}';
    protected $description = 'Audita las facturas LLC contra las operaciones de la SC.';

    public function handle() {
        $tareaId = $this->option('tarea_id');
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("LLC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            Log::warning("LLC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }
        $this->info('Iniciando la auditoría de facturas LLC...');
        Log::info('Iniciando la auditoría de facturas LLC...');

        try {
            // --- FASE 1: Construir Índices en Memoria para Búsquedas Rápidas ---
            //Iniciamos con obtener el mapeo
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                throw new \Exception("No se encontró el archivo de mapeo universal para la tarea #{$tarea->id}.");
            }

            //Leemos y decodificamos el archivo JSON completo
            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array)json_decode($contenidoJson, true);

            //Leemos los demas campos de la tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                $this->info("LLC: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                Log::info("LLC: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }
            $this->info("Procesando Facturas de LLC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");
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

            $this->info("\nIniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");
            Log::info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");

            $this->info("Iniciando mapeo para Upsert.");
            Log::info("Iniciando mapeo para Upsert.");
            //----------------------------------------------------
            $bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            $bar->start();
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
                    $this->warn("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    Log::warning("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    $bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                // Buscamos en nuestros índices en memoria (búsqueda instantánea)
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;
                $datosLlc = $indiceLLC[$pedimentoLimpio] ?? null;

                if (!$datosLlc) {
                    $bar->advance();
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
                $estado = $this->compararMontos($montoSCMXN, $montoLLCMXN);

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

                $bar->advance();
            }
            $bar->finish();

            // --- FASE 5: Guardado Masivo ---
            if (!empty($llcsParaGuardar)) {
                $this->info("\nGuardando/Actualizando " . count($llcsParaGuardar) . " registros de LLC...");
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
                $this->info("¡Guardado con éxito!");
                Log::info("¡Guardado con éxito!");
            }

            $this->info("\nAuditoría de LLC finalizada.");
            Log::info("\nAuditoría de LLC finalizada.");
            return 0;
        } catch (\Exception $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);

            $this->error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
        }
    }


     /**
     * Parsea un archivo TXT de una factura LLC para extraer el folio, la fecha
     * y, lo más importante, la suma de todos los montos.
     *
     * @param string $rutaTxt La ruta al archivo .txt de la LLC.
     * @return array|null Un array con los datos o null si falla.
     */
   /*  private function parsearTxtLlc(string $rutaTxt, string $sucursal): ?array
    {
        if (!file_exists($rutaTxt)) { return null; }

        // Leemos todas las líneas del archivo en un array
        $lineas = file($rutaTxt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $datos =
        [
            'folio' => null,
            'fecha' => null,
            'monto_total' => 0.0,
            'ruta_txt' => $rutaTxt,
            'ruta_pdf' => null,

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
        $datos['ruta_pdf'] = config('reportes.rutas.llc_pdf_filepath') . DIRECTORY_SEPARATOR . $sucursal . $datos['folio'] . '.pdf';
        // Solo devolvemos los datos si encontramos un folio y un monto.
        return ($datos['folio'] && $datos['monto_total'] > 0) ? $datos : null;
    } */


    /**
     * Lee todos los TXT de LLCs recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceOperacionesLLCs(array $indicesOperacion): array
    {
        try {
            $indice = [];

            $bar = $this->output->createProgressBar(count($indicesOperacion));
            $bar->start();
            foreach ($indicesOperacion as $pedimento => $datos) {

                    //Si el archivo mapeado conto con un error no controlado, se continua, ignorandolo.
                    if (isset($datos['error'])) {
                        $bar->advance();
                        continue;
                    }

                    $coleccionFacturas = collect($datos['facturas']);
                    $facturaLLC = $coleccionFacturas->first(function ($factura) {
                        // La condición es la misma que ya tenías.
                        return $factura['tipo_documento'] === 'llc' &&
                            isset($factura['ruta_pdf']) && isset($factura['ruta_txt']);
                    });

                    if (!$facturaLLC) {
                        $bar->advance();
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
                        $bar->advance();
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
                $bar->advance();
            }
        } catch (\Exception $e) {
            $this->error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }

        $bar->finish();
        return $indice;
    }


   /**
     * Construye un índice de los archivos TXT de las LLC. Mapa: [pedimento => ruta_del_archivo]
     */
    /* private function construirIndiceLLC(string $sucursal): array
    {
        $directorio = config('reportes.rutas.llc_txt_filepath'); // Necesitaremos añadir esta ruta
        if($sucursal == 'NL' || $sucursal == 'REY') { $sucursal = 'LDO'; }
        $finder = new Finder();
        $finder->depth(0)->path($sucursal)->in($directorio)->name('*.txt')->date("since " . config('reportes.periodo_meses_busqueda', 2) . " months ago");

        $indice = [];
        foreach ($finder as $file) {
            $contenido = $file->getContents();
            // Buscamos el pedimento en el campo de notas
            if (preg_match('/(?<=\[encPdfRemarksNote3\])(.*)(\b\d{7}\b)/', $contenido, $matchPedimento)) {
                $pedimento = trim($matchPedimento[2]);
                $indice[$pedimento] = $file->getRealPath();
            }
        }
        return $indice;
    } */

    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     */
    private function compararMontos(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin LLC!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) { return 'Coinciden!'; }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }

}
