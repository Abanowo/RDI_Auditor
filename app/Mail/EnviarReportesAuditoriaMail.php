<?php

namespace App\Mail;

use App\Models\AuditoriaTareas; // Asegúrate de importar tu modelo
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class EnviarReportesAuditoriaMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * La instancia de la tarea de auditoría.
     *
     * @var \App\Models\AuditoriaTarea
     */
    public $tarea;
    public $discrepancias;

    /**
     * Create a new message instance.
     *
     * @param \App\Models\AuditoriaTarea $tarea
     * @return void
     */
    public function __construct($tarea)
    {
        $this->tarea = $tarea;
        $this->discrepancias = [];
        
        try {
            // 1. Obtenemos el mapa de vinculación exacto (La misma solución que en Excel)
            $rutaMapeo = $tarea->mapeo_completo_facturas;
            
            if ($rutaMapeo && Storage::exists($rutaMapeo)) {
                $contenidoJson = Storage::get($rutaMapeo);
                $mapeadoFacturas = (array) json_decode($contenidoJson, true);
                
                // Extraemos los IDs reales de la base de datos
                $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];
                
                if (!empty($mapaPedimentoAId)) {
                    $idsPedDb = \Illuminate\Support\Arr::pluck($mapaPedimentoAId, 'id_pedimiento');
                    
                    // 2. Buscamos usando los IDs internos, ignorando si el texto está "sucio"
                    $pedimentos = \App\Models\Pedimento::whereIn('id_pedimiento', $idsPedDb)
                        ->with([
                            'importacion.auditorias',
                            'importacion.auditoriasTotalSC',
                            'exportacion.auditorias',
                            'exportacion.auditoriasTotalSC'
                        ])
                        ->get();

                    // 3. Recorremos los pedimentos encontrados
                    foreach ($pedimentos as $pedimento) {
                        
                        $operacion = $pedimento->importacion ?? $pedimento->exportacion;
                        if (!$operacion) {
                            continue;
                        }

                        $sc = $operacion->auditoriasTotalSC;
                        $auditorias = $operacion->auditorias;

                        if (!$auditorias) {
                            continue;
                        }

                        // Buscamos discrepancias
                        foreach ($auditorias as $auditoria) {
                            // Convertimos a minúsculas para evitar problemas de formato
                            $estadoLower = strtolower($auditoria->estado);
                            
                            // Validamos ÚNICAMENTE pagos de más o pagos de menos (ignorando acentos)
                            $esDiscrepancia = str_contains($estadoLower, 'pago de mas') || 
                                              str_contains($estadoLower, 'pago de más') || 
                                              str_contains($estadoLower, 'pago de menos');

                            if ($esDiscrepancia) {
                                $tipo = $auditoria->tipo_documento;
                                $montoFactura = (float) $auditoria->monto_total_mxn;
                                $diferencia = (float) $auditoria->monto_diferencia_sc;
                                
                                $montoSC = 0;
                                if ($sc && isset($sc->desglose_conceptos['montos'])) {
                                    $llaveSc = $tipo === 'pago_derecho' ? 'pago_derecho_mxn' : $tipo . '_mxn';
                                    $montoSC = (float) ($sc->desglose_conceptos['montos'][$llaveSc] ?? 0);
                                } else {
                                    $montoSC = $montoFactura + $diferencia;
                                }

                                // Extraemos los 7 dígitos para que en el correo se vea bonito y no sucio
                                preg_match('/\b([4-7]\d{6})\b/', $pedimento->num_pedimiento, $matchPed);
                                $pedimentoLimpio = $matchPed[1] ?? $pedimento->num_pedimiento;

                                $this->discrepancias[$tipo][$pedimentoLimpio] = [
                                    'pedimento'     => $pedimentoLimpio,
                                    'monto_factura' => $montoFactura,
                                    'monto_sc'      => $montoSC,
                                    'diferencia'    => $diferencia,
                                    'estado'        => $auditoria->estado // Guardamos el estado original
                                ];
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error al generar tabla de discrepancias en correo: ' . $e->getMessage());
        }
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // Obtenemos las rutas completas a los archivos desde el disco de storage
        $rutaReportePrincipal = $this->tarea->ruta_reporte_impuestos;
        $rutaReportePendientes = $this->tarea->ruta_reporte_impuestos_pendientes;

        // Construimos el correo
        $email = $this->subject('Reporte de Auditoría de Impuestos - ' . $this->tarea->nombre_archivo)
            ->view('cuerpo_correo_reporte_auditoria'); // Usaremos una vista de Blade para el cuerpo del correo

        // Adjuntamos el primer reporte si existe
        // Verificamos que el archivo exista DENTRO del nuevo disco.
        if (Storage::disk('storageOldProyect')->exists($rutaReportePrincipal)) {

            // Usamos attachFromStorageDisk para especificar el disco correcto
            $email->attachFromStorageDisk('storageOldProyect', $rutaReportePrincipal, $this->tarea->nombre_reporte_impuestos, [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        // Adjuntamos el segundo reporte si existe
        if (Storage::disk('storageOldProyect')->exists($rutaReportePendientes)) {

            $email->attachFromStorageDisk('storageOldProyect', $rutaReportePendientes, $this->tarea->nombre_reporte_pendientes, [
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $email;
    }
}
