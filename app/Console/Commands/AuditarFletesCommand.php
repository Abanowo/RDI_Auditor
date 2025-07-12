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

class AuditarFletesCommand extends Command
{
    /**
     * La firma de nuestro comando.
     */
    protected $signature = 'reporte:auditar-fletes {--tarea_id= : El ID de la tarea a procesar}';

    /**
     * La descripción de nuestro comando.
     */
    protected $description = 'Audita las facturas de Fletes (Transportactics) contra las operaciones de la SC.';

    /**
     * Orquesta el proceso completo de la auditoría de Fletes.
     */
    public function handle()
    {
        $tareaId = $this->option('tarea_id');
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("Fletes: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            Log::warning("Fletes: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }

        $this->info('Iniciando la auditoría de Fletes (Transportactics)...');
        Log::info('Iniciando la auditoría de Fletes (Transportactics)...');
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
                $this->info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                Log::info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }
            $this->info("Procesando Facturas de Fletes para Tarea #{$tarea->id} en la sucursal: {$sucursal}");
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

            $this->info("\nIniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");
            Log::info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");

            $this->info("Iniciando mapeo para Upsert.");
            Log::info("Iniciando mapeo para Upsert.");

            $bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            $bar->start();
            $fletesParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                // Obtemenos la operacionId por medio del pedimento sucio
                //Se verifica si la operacion ID esta en Importacion
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                $tipoOperacion = "Intactics\Operaciones\Importacion";

                if (!$operacionId) { //Si no, entonces busca en Exportacion
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                    $tipoOperacion = "Intactics\Operaciones\Exportacion";
                }

                if (!$operacionId) { //Si no esta ni en Importacion o en Exportacion, que lo guarde por pedimento_id
                    $tipoOperacion = "N/A";
                }

                if (!$operacionId && !$pedimentoId) {
                    $this->warn("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    Log::warning("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    $bar->advance();
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                // Buscamos en nuestros índices en memoria (búsqueda instantánea)
                $datosFlete = $indiceFletes[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;

                if (!$datosFlete/* || $datosSC['monto_esperado_flete'] == 0 */) {
                    $bar->advance();
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
                $estado = $this->compararMontos($montoSCMXN, $montoFleteMXN);

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

                $bar->advance();
            }
            $bar->finish();

            // --- FASE 5: Guardado Masivo en Base de Datos ---
            if (!empty($fletesParaGuardar)) {
                $this->info("\nPaso 3/3: Guardando/Actualizando " . count($fletesParaGuardar) . " registros de fletes...");
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

                $this->info("¡Guardado con éxito!");
                Log::info("¡Guardado con éxito!");
            }

            $this->info("\nAuditoría de Fletes finalizada.");
            Log::info("\nAuditoría de Fletes finalizada.");
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
     * Lee todos los TXT de Fletes recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceOperacionesFletes(array $indicesOperacion): array
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
                    $facturaFlete = $coleccionFacturas->first(function ($factura) {
                        // La condición es la misma que ya tenías.
                        return $factura['tipo_documento'] === 'flete' &&
                            isset($factura['ruta_pdf']) && isset($factura['ruta_txt']) && isset($factura['ruta_xml']);
                    });

                    if (!$facturaFlete) {
                        $bar->advance();
                        continue;
                    }
                    try {   //Cuando la URL esta mal construida, lo que se hace es buscar por medio del get el txt
                        $contenido = file_get_contents($facturaFlete['ruta_txt']);
                    } catch (\Exception $th) {

                        $operacionID = $datos['operacion_id'];
                        $url_txt = Http::withoutVerifying()->get("https://sistema.intactics.com/v3/operaciones/{$datos['tipo_operacion']}/{$operacionID}/get-files-txt-momentaneo");

                        if (!$url_txt->successful()) {
                            // Si la API falla para este ID, lo saltamos y continuamos con el siguiente.
                            $this->warn("No se pudieron obtener los archivos para la importación ID: {$operacionID}");
                            Log::warning("No se pudieron obtener los archivos para la importación ID: {$operacionID}");
                            $bar->advance();
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
                        $contenido = file_get_contents('https://sistema.intactics.com' . $urls[2]['path'], false, stream_context_create($arrContextOptions));
                    }

                    if (!$contenido) {
                        $bar->advance();
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
                $bar->advance();
            }
        } catch (\Exception $e) {
            $this->error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }

        return $indice;
    }


    // Dentro de la clase AuditarFletesCommand

    /**
     * Parsea un archivo XML de Transportactics y devuelve los datos clave.
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
                $this->error("Error al parsear el XML {$rutaXml}: " . $e->getMessage());
                Log::error("Error al parsear el XML {$rutaXml}: " . $e->getMessage());
                return
                [
                    'total'  => -1,
                    'moneda' => 'N/A',
                ];
            }
    }

    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     */
    private function compararMontos(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin Flete!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) { return 'Coinciden!'; }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }

}
