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

class AuditarMuestrasCommand extends Command
{
    protected $signature = 'reporte:auditar-muestras {--tarea_id= : El ID de la tarea a procesar}';
    protected $description = 'Realiza la auditoría comparando las facturas de Muestras contra la Factura SC.';

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
            $this->warn("Muestras: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            Log::warning("Muestras: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
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
                $this->info("Muestras: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                Log::info("Muestras: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }

            $this->info("Procesando Facturas de Muestras para Tarea #{$tarea->id} en la sucursal: {$sucursal}");
            Log::info("Procesando Facturas de Muestras para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            $mapaPedimentoAImportacionId = $mapeadoFacturas['pedimentos_importacion'];
            $mapaPedimentoAExportacionId = $mapeadoFacturas['pedimentos_exportacion'];
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'];
            $indicesOperaciones = $mapeadoFacturas['indices_importacion'] + $mapeadoFacturas['indices_exportacion'];

            // 2. CONSTRUIR ÍNDICES
            $indiceMuestras = $this->construirIndiceOperacionesMuestras($indicesOperaciones);
            $auditoriasSC = $mapeadoFacturas['auditorias_sc'];

            if (empty($indiceMuestras)) {
                $this->info("\nNo se encontraron facturas de Muestras para procesar.");
                Log::info("No se encontraron facturas de Muestras para procesar.");
                return 0;
            }

            $this->info("\nSe encontraron " . count($indiceMuestras) . " facturas de Muestras en los archivos.");

            // Extraemos los datos de la SC
            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                $desglose = $auditoria['desglose_conceptos'];
                $arrPedimento = array_filter($mapaPedimentoAId, function ($datosAuditoria) use ($auditoria) {
                    return $datosAuditoria['id_pedimiento'] == $auditoria['pedimento_id'];
                });

                if(!empty($arrPedimento)) {
                    $indiceSC[key($arrPedimento)] = [
                        'monto_muestras_sc' => (float) ($desglose['montos']['muestras'] ?? -1),
                        'monto_muestras_sc_mxn' => (float) ($desglose['montos']['muestras_mxn'] ?? -1),
                        'tipo_cambio' => (float) ($desglose['tipo_cambio'] ?? 1.0),
                    ];
                }
            }

            // 3. PREPARAR DATOS PARA UPSERT
            $this->info("Iniciando cruce y mapeo para Upsert de Muestras...");
            $muestrasParaGuardar = [];
            
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

                $datosMuestra = $indiceMuestras[$pedimentoLimpio] ?? null;
                $datosSC = $indiceSC[$pedimentoLimpio] ?? null;

                if (!$datosMuestra) {
                    $bar->advance();
                    continue;
                }

                if (!$datosSC) {
                    $datosSC = [
                        'monto_muestras_sc' => -1,
                        'monto_muestras_sc_mxn' => -1,
                        'tipo_cambio' => -1,
                    ];
                }

                // Parseamos el XML
                $datosFacturaXml = $this->parsearXmlMuestras($datosMuestra['path_xml_mue']) ?? ['total' => -1, 'moneda' => 'N/A'];
                
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
                    'folio' => $datosMuestra['folio'] ?? 'S/F',
                    'fecha_documento' => now()->format('Y-m-d'), // O extraer fecha real si la parseamos
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
                
                $bar->advance();
            }
            $bar->finish();

            // 4. GUARDAR EN LA BASE DE DATOS
            if (!empty($muestrasParaGuardar)) {
                $this->info("\nGuardando/Actualizando " . count($muestrasParaGuardar) . " registros de Muestras...");
                Log::info("\nGuardando/Actualizando " . count($muestrasParaGuardar) . " registros de Muestras...");

                Auditoria::upsert(
                    $muestrasParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],
                    [
                        'fecha_documento', 'monto_total', 'monto_total_mxn', 
                        'monto_diferencia_sc', 'moneda_documento', 'estado', 
                        'ruta_xml', 'ruta_pdf', 'updated_at'
                    ]
                );

                $this->info("¡Guardado con éxito!");
            } else {
                $this->info("\nNo hubo registros válidos de Muestras para guardar.");
            }

            return 0;

        } catch (\Exception $e) {
            $tarea->update([
                'status' => 'fallido',
                'resultado' => "Error Muestras: " . $e->getMessage()
            ]);
            $this->error("\nError al procesar Muestras para la Tarea #{$tareaId}: " . $e->getMessage());
            Log::error("Error al procesar Muestras para la Tarea #{$tareaId}: " . $e->getMessage());
            throw $e;
        }
    }

    // --- MÉTODOS AUXILIARES ---

    private function construirIndiceOperacionesMuestras(array $indicesOperacion): array
    {
        $indice = [];
        foreach ($indicesOperacion as $pedimento => $datos) {
            if (isset($datos['error'])) continue;

            $coleccionFacturas = collect($datos['facturas']);
            $facturaMuestra = $coleccionFacturas->first(function ($factura) {
                return $factura['tipo_documento'] === 'muestras' &&
                       isset($factura['ruta_pdf']) && isset($factura['ruta_xml']);
            });

            if (!$facturaMuestra) continue;

            $indice[$pedimento] = [
                'folio' => $facturaMuestra['nombre_base'] ?? 'S/F',
                'path_xml_mue' => $facturaMuestra['ruta_xml'],
                'path_pdf_mue' => $facturaMuestra['ruta_pdf'],
            ];
        }
        return $indice;
    }

    private function compararMontos_Muestras(float $esperado, float $real): string
    {
        if ($esperado == -1) return 'Sin SC!';
        if ($real == -1) return 'Sin Muestras!';
        if (abs($esperado - $real) < 0.001) return 'Coinciden!';
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }

    private function parsearXmlMuestras(string $rutaXml): ?array
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
            Log::error("Error al parsear el XML de muestras {$rutaXml}: " . $e->getMessage());
            return [
                'total' => -1,
                'moneda' => 'N/A',
            ];
        }
    }
}