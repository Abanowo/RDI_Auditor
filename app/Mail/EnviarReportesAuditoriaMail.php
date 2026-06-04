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
            // 1. Obtener el archivo de mapeo (Base de toda la auditoría)
            $rutaMapeo = $this->tarea->mapeo_completo_facturas;
            if (!$rutaMapeo || !Storage::exists($rutaMapeo)) {
                \Illuminate\Support\Facades\Log::error("Correo Auditoría: No se encontró el archivo de mapeo en: " . $rutaMapeo);
                return;
            }

            $contenidoJson = Storage::get($rutaMapeo);
            $mapeadoFacturas = json_decode($contenidoJson, true);
            $mapaPedimentoAId = $mapeadoFacturas['pedimentos_totales'] ?? [];

            if (empty($mapaPedimentoAId)) {
                return;
            }

            // 2. Extraer los IDs de los pedimentos procesados
            $idsPedDb = \Illuminate\Support\Arr::pluck($mapaPedimentoAId, 'id_pedimiento');

            // 3. Consultar Pedimentos con sus operaciones y auditorías (Carga masiva)
            $pedimentos = \App\Models\Pedimento::whereIn('id_pedimiento', $idsPedDb)
                ->with([
                    'importacion.auditorias',
                    'importacion.auditoriasTotalSC',
                    'exportacion.auditorias',
                    'exportacion.auditoriasTotalSC'
                ])->get();

            // 4. Procesar cada pedimento para encontrar discrepancias
            foreach ($pedimentos as $pedimento) {
                
                // Revisamos tanto importación como exportación
                $operaciones = array_filter([$pedimento->importacion, $pedimento->exportacion]);

                foreach ($operaciones as $operacion) {
                    $sc = $operacion->auditoriasTotalSC;
                    $auditorias = $operacion->auditorias;

                    if (!$auditorias || $auditorias->isEmpty()) {
                        continue;
                    }

                    // Buscamos discrepancias en la colección de auditorías
                    foreach ($auditorias as $auditoria) {
                        $estadoLower = strtolower($auditoria->estado);
                        
                        // Verificamos si el estado indica un error de pago
                        $esDiscrepancia = Str::contains($estadoLower, 'pago de mas') || 
                                          Str::contains($estadoLower, 'pago de más') || 
                                          Str::contains($estadoLower, 'pago de menos');

                        if ($esDiscrepancia) {
                        
                            $claseOp = $auditoria->operation_type ?? '';
                            $tipoOperacionNombre = 'Desconocida';
                            
                            if (str_contains($claseOp, 'Importacion')) {
                                $tipoOperacionNombre = 'Importación';
                            } elseif (str_contains($claseOp, 'Exportacion')) {
                                $tipoOperacionNombre = 'Exportación';
                            }

                            $tipo = $auditoria->tipo_documento;
                            $montoFactura = (float) $auditoria->monto_total_mxn;
                            $diferencia = (float) $auditoria->monto_diferencia_sc;
                            
                            // Cálculo del monto SC (igual que en el reporte)
                            $montoSC = 0;
                            if ($sc && isset($sc->desglose_conceptos['montos'])) {
                                $llaveSc = $tipo === 'pago_derecho' ? 'pago_derecho_mxn' : $tipo . '_mxn';
                                $montoSC = (float) ($sc->desglose_conceptos['montos'][$llaveSc] ?? 0);
                            } else {
                                $montoSC = $montoFactura + $diferencia;
                            }

                            // Usamos una llave única para evitar que registros se sobrescriban
                            $llaveUnica = $pedimento->num_pedimiento . '_' . $auditoria->id;

                            $this->discrepancias[$tipo][$llaveUnica] = [
                                'pedimento'      => $pedimento->num_pedimiento,
                                'tipo_operacion' => $tipoOperacionNombre,
                                'monto_factura'  => $montoFactura,
                                'monto_sc'       => $montoSC,
                                'diferencia'     => $diferencia,
                                'estado'         => $auditoria->estado,
                                'ruta_pdf'       => $auditoria->ruta_pdf
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Fallo en el constructor del correo de auditoría: ' . $e->getMessage());
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
            'oscar.sandoval@intactics.com',
            'carlos.perez@intactics.com',
            'jose.sibrian@intactics.com'
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

        return $email;
    }
}
