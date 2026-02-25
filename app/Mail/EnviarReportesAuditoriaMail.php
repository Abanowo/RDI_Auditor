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
            // 1. Extraemos los pedimentos que se procesaron en esta tarea
            $pedimentosProcesados = json_decode($tarea->pedimentos_procesados, true) ?? [];

            if (!empty($pedimentosProcesados)) {
                
                // 2. Usamos la relación 'auditorias' directa (más segura y general)
                $pedimentos = \App\Models\Pedimento::whereIn('num_pedimiento', $pedimentosProcesados)
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
                    if (!$operacion) continue;

                    $sc = $operacion->auditoriasTotalSC;
                    $auditorias = $operacion->auditorias;

                    if (!$auditorias) continue;

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

                            // Al usar el número de pedimento como llave, evitamos registros duplicados en pantalla
                            $this->discrepancias[$tipo][$pedimento->num_pedimiento] = [
                                'pedimento'     => $pedimento->num_pedimiento,
                                'monto_factura' => $montoFactura,
                                'monto_sc'      => $montoSC,
                                'diferencia'    => $diferencia,
                                'estado'        => $auditoria->estado // Guardamos el estado original
                            ];
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
