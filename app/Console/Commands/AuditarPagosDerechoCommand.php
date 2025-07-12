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
use Smalot\PdfParser\Parser;

class AuditarPagosDerechoCommand extends Command
{
    protected $signature = 'reporte:auditar-pagos-derecho {--tarea_id= : El ID de la tarea a procesar}';
    protected $description = 'Busca y procesa los archivos PDF de Pagos de Derecho para cada operación.';

    public function handle()
    {
        $tareaId = $this->option('tarea_id');
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("Pagos derecho: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }
        $this->info('Iniciando la auditoría de Pagos de Derecho...');
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
                $this->info("Pagos de derecho: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                Log::info("Pagos de derecho: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }
            $this->info("Procesando Facturas de Pagos de derecho para Tarea #{$tarea->id} en la sucursal: {$sucursal}");
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

            $this->info("\nIniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");
            Log::info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");

            $this->info("Iniciando mapeo para Upsert.");
            Log::info("Iniciando mapeo para Upsert.");
            //----------------------------------------------------
            $bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            $bar->start();
            $pagosParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoLimpio => $pedimentoSucioYId) {
                // Obtemenos la operacionId por medio del pedimento sucio
                // Se verifica si la operacion ID esta en Importacion
                $operacionId = $mapaPedimentoAImportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                $tipoOperacion = "Intactics\Operaciones\Importacion";

                if (!$operacionId) { // Si no, entonces busca en Exportacion
                    $operacionId = $mapaPedimentoAExportacionId[$pedimentoSucioYId['num_pedimiento']] ?? null;
                    $tipoOperacion = "Intactics\Operaciones\Exportacion";
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

                $rutasPdfs = $indicePagosDerecho[$pedimentoSucioYId['num_pedimiento']] ?? null;
                if (!$rutasPdfs) {
                    $bar->advance();
                    continue;
                }
                foreach ($rutasPdfs as $rutaPdf) {

                    if($rutaPdf === 'https://sistema.intactics.com/v2/uploads/3757275-PAGO-DE-DERECHO.pdf'){
                    $ao = 0;
                    }
                    //Parseamos cada PDF encontrado.
                    $datosPago = $this->parsearPdfPagoDeDerecho($rutaPdf) ?? null;

                    if ($datosPago) {
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
                $bar->advance();
            }

            $bar->finish();

            // 5. Guardado Masivo con upsert
            if (!empty($pagosParaGuardar)) {
                $this->info("\nGuardando/Actualizando " . count($pagosParaGuardar) . " registros de Pagos de Derecho...");
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
                $this->info("¡Guardado con éxito!");
            }

            $this->info("\nAuditoría de Pagos de Derecho finalizada.");
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
            $this->error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
        }
    }


    /**
     * Escanea el directorio de Pagos de Derecho una vez y crea un mapa
     * de [pedimento => [lista_de_rutas_pdf]].
     */
    private function construirIndiceOperacionesPagosDerecho(array $indicesOperacion): array {
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
                $facturaPDD = $coleccionFacturas->filter(function ($factura) {
                    // La condición es la misma que ya tenías.
                    return $factura['tipo_documento'] === 'pago_derecho' &&
                        isset($factura['ruta_pdf']);
                })->toArray();

                if (!$facturaPDD) {
                    $bar->advance();
                    continue;
                }

                // Extraemos el pedimento del nombre del archivo.
                // Este Regex busca 7 dígitos seguidos de un posible guion.
                foreach ($facturaPDD as $factura => $datos) {
                    $indice[$pedimento][] = $datos['ruta_pdf'];
                }

                $bar->advance();
                }
            }

            catch (\Exception $e) {
                Log::error("Error construyendo el índice de Pagos de Derecho: " . $e->getMessage());
                $this->error("Error construyendo el índice de Pagos de Derecho: " . $e->getMessage());
            }
        $bar->finish();
        return $indice;
    }



    /**
     * Parsea un PDF de Pago de Derecho para extraer los datos clave.
     * Debe ser lo suficientemente inteligente para detectar el formato (BBVA vs Santander).
     */
    private function parsearPdfPagoDeDerecho(string $rutaPdf): ?array
    {
        try {

            // 1. Crear una instancia del Parser.
            $parser = new Parser();

            // 2. Parsear el archivo PDF. Esto devuelve un objeto Pdf.
            $pdf = $parser->parseFile($rutaPdf);

            // 3. Obtener el texto de todas las páginas del documento.
            // El resultado es un string muy similar al que obtenías con pdftotext.
            $texto = $pdf->getText();

            //Si el valor esta vacio, o si es un pago de derecho de Banamex (es a cuenta del cliente, por lo que lo descartamos)
            if ($texto === '' || str_contains($texto, 'citibanamex')) { return null; }
            // --- FIN DEL CAMBIO ---
            $datos = [];

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

            return $datos;

        } catch(\Exception $e) {
            Log::error("Error al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            $this->error("\nError al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            $datos['numero_operacion'] = 'N/A';
            $datos['llave_pago'] = 'N/A';
            $datos['monto_total'] = -1;
            $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('Ymd', $matchFecha[1])->format('Y-m-d') : null;
            $datos['tipo'] = 'No encontrado';
            return null;
        }


    }

}



