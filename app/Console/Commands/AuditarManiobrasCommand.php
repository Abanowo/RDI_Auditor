<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Pedimento;
use App\Models\Importacion;
use App\Models\Exportacion;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;

class AuditarManiobrasCommand extends Command
{
    protected $signature = 'reporte:auditar-maniobras {--tarea_id= : El ID de la tarea a procesar}';
    protected $description = 'Realiza la auditoría comparando las facturas de Maniobras contra la Factura SC.';

    public function handle()
    {
        $tareaId = $this->option('tarea_id');
        
        if (!$tareaId) {
            $this->error('Se requiere el ID de la tarea. Usa --tarea_id=X');
            Log::error('Se requiere el ID de la tarea. Usa --tarea_id=X');
            return 1;
        }

        $tarea = AuditoriaTareas::find($tareaId);
        
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("Maniobras: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            Log::warning("Maniobras: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }

        try {
            // 1. OBTENER EL MAPEO UNIVERSAL
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                throw new \Exception("No se encontró el archivo de mapeo universal para la tarea #{$tarea->id}.");
            }

            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = (array)json_decode($contenidoJson, true);

            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                $this->info("Maniobras: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                Log::info("Maniobras: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }

            $this->info("Procesando Facturas de Maniobras para Tarea #{$tarea->id} en la sucursal: {$sucursal}");
            Log::info("Procesando Facturas de Maniobras para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            // 2. CONSTRUIR ÍNDICES
            $indiceManiobras = $this->construirIndiceOperacionesManiobras($indicesOperaciones);
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            if (empty($indiceManiobras)) {
                $this->info("\nNo se encontraron facturas de Maniobras para procesar.");
                Log::info("No se encontraron facturas de Maniobras para procesar.");
                return 0;
            }

            $this->info("\nSe encontraron " . count($indiceManiobras) . " facturas de Maniobras en los archivos.");

            // Extraemos los datos de la SC
            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = $auditoria['desglose_conceptos'];
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                if(!empty($arrPedimento)) {
                    $indiceSC[key($arrPedimento)] = [
                        'monto_maniobras_sc' => (float) ($desglose['montos']['maniobras'] ?? -1),
                        'monto_maniobras_sc_mxn' => (float) ($desglose['montos']['maniobras_mxn'] ?? -1),
                        'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            // 3. PREPARAR DATOS PARA UPSERT
            $this->info("Iniciando cruce y mapeo para Upsert de Maniobras...");
            $maniobrasParaGuardar = [];
            
            $bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            $bar->start();

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
                    $bar->advance();
                    continue; 
                }

                $datosManiobra = $indiceManiobras[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;

                if (!$datosManiobra) {
                    $bar->advance();
                    continue;
                }

                if (!$datosSC) {
                    $datosSC = [
                        'monto_maniobras_sc' => -1,
                        'monto_maniobras_sc_mxn' => -1,
                        'tipo_cambio' => -1,
                    ];
                }

                // Parseamos el XML
                $datosFacturaXml = $this->parsearXmlManiobras($datosManiobra['path_xml_man']) ?? ['total' => -1, 'moneda' => 'N/A'];
                
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
                    'folio' => $datosManiobra['folio'] ?? 'S/F',
                    'fecha_documento' => now()->format('Y-m-d'), // O extraer fecha real
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
                
                $bar->advance();
            }
            $bar->finish();

            // 4. GUARDAR EN LA BASE DE DATOS
            if (!empty($maniobrasParaGuardar)) {
                $this->info("\nGuardando/Actualizando " . count($maniobrasParaGuardar) . " registros de Maniobras...");
                Log::info("\nGuardando/Actualizando " . count($maniobrasParaGuardar) . " registros de Maniobras...");

                Auditoria::upsert(
                    $maniobrasParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],
                    [
                        'fecha_documento', 'monto_total', 'monto_total_mxn', 
                        'monto_diferencia_sc', 'moneda_documento', 'estado', 
                        'ruta_xml', 'ruta_pdf', 'updated_at'
                    ]
                );

                $this->info("¡Guardado con éxito!");
            } else {
                $this->info("\nNo hubo registros válidos de Maniobras para guardar.");
            }

            return 0;

        } catch (\Exception $e) {
            $tarea->update([
                'status' => 'fallido',
                'resultado' => "Error Maniobras: " . $e->getMessage()
            ]);
            $this->error("\nError al procesar Maniobras para la Tarea #{$tareaId}: " . $e->getMessage());
            Log::error("Error al procesar Maniobras para la Tarea #{$tareaId}: " . $e->getMessage());
            throw $e;
        }
    }

    // --- MÉTODOS AUXILIARES ---

    private function construirIndiceOperacionesManiobras(array $indicesOperacion): array
    {
        $indice = [];
        foreach ($indicesOperacion as $pedimento => $datos) {
            if (isset($datos['error'])) continue;

            $coleccionFacturas = collect($datos['facturas']);
            $facturaManiobra = $coleccionFacturas->first(function ($factura) {
                return $factura['tipo_documento'] === 'maniobras' &&
                       isset($factura['ruta_pdf']) && isset($factura['ruta_xml']);
            });

            if (!$facturaManiobra) continue;

            $indice[$pedimento] = [
                'folio' => $facturaManiobra['nombre_base'] ?? 'S/F',
                'path_xml_man' => $facturaManiobra['ruta_xml'],
                'path_pdf_man' => $facturaManiobra['ruta_pdf'],
            ];
        }
        return $indice;
    }

    private function compararMontos_Maniobras(float $esperado, float $real): string
    {
        if ($esperado == -1) return 'Sin SC!';
        if ($real == -1) return 'Sin Maniobras!';
        if (abs($esperado - $real) < 0.001) return 'Coinciden!';
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }

    private function parsearXmlManiobras(string $rutaXml): ?array
    {
        try {
            $arrContextOptions = [
                "ssl" => [
                    "verify_peer" => false,
                    "verify_peer_name" => false,
                ],
            ];
            $xml = new \SimpleXMLElement(file_get_contents($rutaXml, false, stream_context_create($arrContextOptions)));
            
            return [
                'total' => (float) $xml['Total'],
                'moneda' => (string) $xml['Moneda'],
            ];
        } catch (\Throwable $e) {
            Log::error("Error al parsear el XML de maniobras {$rutaXml}: " . $e->getMessage());
            return [
                'total' => -1,
                'moneda' => 'N/A',
            ];
        }
    }
}