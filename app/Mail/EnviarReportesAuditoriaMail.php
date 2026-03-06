<?php

namespace App\Mail;

use App\Models\AuditoriaTareas; // Asegúrate de importar tu modelo
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                        $esDiscrepancia = Str::contains($estadoLower, 'pago de mas') || 
                                          Str::contains($estadoLower, 'pago de más') || 
                                          Str::contains($estadoLower, 'pago de menos');

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
        // 1. Configuración de Destinatarios
        if (app()->environment('production')) {
            $destinatario = ($this->tarea->user_id && $this->tarea->user) ? $this->tarea->user->email : 'sayda.leyva@intactics.com';
        } else {
            $destinatario = 'carlos.perez@intactics.com'; 
        }

        $cc = array_unique(array_filter([
            'sayda.leyva@intactics.com',
            'felipe.villarreal@intactics.com',
            'antonio.piedra@intactics.com',
            'zuzeth.quintero@intactics.com',
            'irvin.mendivil@intactics.com',
            'mirna.lopez@intactics.com',
            'sonia.gomez@intactics.com',
            'oscar.sandoval@intactics.com'
        ]));

        $email = $this->from('info@intactics.com', 'Intactics')
            ->to($destinatario)
            ->subject('Reporte de Auditoría de Impuestos - ' . $this->tarea->nombre_archivo)
            ->view('cuerpo_correo_reporte_auditoria')
            ->with([
                'tarea' => $this->tarea,
                'discrepancias' => $this->discrepancias,
            ]);

        if (app()->environment('production')) {
            $email->cc($cc);
        }

        // 2. Adjuntar Reportes Globales (Excel)
        if (Storage::disk('storageOldProyect')->exists($this->tarea->ruta_reporte_impuestos)) {
            $email->attachFromStorageDisk('storageOldProyect', $this->tarea->ruta_reporte_impuestos, $this->tarea->nombre_reporte_impuestos);
        }

        if (Storage::disk('storageOldProyect')->exists($this->tarea->ruta_reporte_impuestos_pendientes)) {
            $email->attachFromStorageDisk('storageOldProyect', $this->tarea->ruta_reporte_impuestos_pendientes, $this->tarea->nombre_reporte_pendientes);
        }

        // 3. ADJUNTAR PDFS INDIVIDUALES CON DISCREPANCIAS
        foreach ($this->discrepancias as $tipo => $items) {
            foreach ($items as $item) {
                if (!empty($item['ruta_pdf'])) {
                    $rutaFisica = $item['ruta_pdf'];
                    
                    // Verificamos si es una ruta absoluta o de storage
                    if (file_exists($rutaFisica)) {
                        $email->attach($rutaFisica, [
                            'as' => 'Discrepancia_' . $tipo . '_' . $item['pedimento'] . '.pdf',
                            'mime' => 'application/pdf',
                        ]);
                    }
                }
            }
        }

        return $email;
    }
}
