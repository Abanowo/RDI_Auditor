<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\AuditoriaImpuestosController;
use App\Models\AuditoriaTareas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ProcesarAuditoriaCompleta extends Command
{
    protected $signature = 'reporte:auditar';
    protected $description = 'Orquesta la ejecución de todos los comandos de auditoría para una tarea pendiente.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // 1. Busca la primera tarea que esté pendiente
        $tarea = AuditoriaTareas::where('status', 'pendiente')->orderBy('created_at')->first();

        if (!$tarea) {
            $this->info('No hay tareas de auditoría pendientes.');
            return 0;
        }

        $this->info("Iniciando orquestación para la Tarea #{$tarea->id}...");
        $tarea->update(['status' => 'procesando']);

        try {
            $status = null;
            $controller = new AuditoriaImpuestosController(); // Instanciamos una sola vez

            // ====================================================================
            // BLOQUE 1: AUDITORÍAS LOCALES (BASE DE DATOS)
            // Estas se ejecutan siempre, sin importar la sucursal.
            // ====================================================================

            // 1. IMPUESTOS (Fase 1)
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando Impuestos (Fase 1) para Tarea #{$tarea->id} ---");
            $status = $controller->importarImpuestosEnAuditorias($tarea->id);
            if ($status['code'] > 0) {
                throw $status['message'];
            }

            // 2. MAPEO
            gc_collect_cycles();
            $this->info("--- [INICIO] Creando mapeo para Tarea #{$tarea->id} ---");
            $status = $controller->mapearFacturasYFacturasSCEnAuditorias($tarea->id);
            if ($status['code'] > 0) {
                throw $status['message'];
            }

            // 3. SCs
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando SCs para Tarea #{$tarea->id} ---");
            $status = $controller->importarFacturasSCEnAuditoriasTotalesSC($tarea->id);
            if ($status['code'] > 0) {
                throw $status['message'];
            }

            // ====================================================================
            // BLOQUE 2: INSERCIÓN EN GOOGLE SHEETS (GPC)
            // Se ejecuta SOLO si la sucursal es Manzanillo (ZLO).
            // ====================================================================

            // Validamos contra 'ZLO' o 'Manzanillo' dependiendo de cómo lo guardes
            if (strtoupper($tarea->sucursal) === 'ZLO' || strtoupper($tarea->sucursal) === 'MANZANILLO') {
                $this->info("--- INICIANDO INSERCIONES EN GPC (ZLO DETECTADO) ---");
                Log::info("Sucursal ZLO detectada. Ejecutando métodos de inserción a GPC...");

                // 9. IMPUESTOS A GPC (Con validación interna de Santander)
                gc_collect_cycles();
                $this->info("Enviando Impuestos a GPC...");
                $status = $controller->enviarAGPCImpuestos($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 10. PAGOS DE DERECHO A GPC
                gc_collect_cycles();
                $this->info("Enviando Pagos de Derecho a GPC...");
                $status = $controller->enviarAGPCPagosDeDerecho($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 11. MANIOBRAS A GPC
                gc_collect_cycles();
                $this->info("Enviando Maniobras a GPC...");
                $status = $controller->enviarAGPCTerminales($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 12. VACÍOS A GPC
                gc_collect_cycles();
                $this->info("Enviando Vacíos a GPC...");
                $status = $controller->auditarFacturasDeVacios($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 13. ALMACENAJE (XML TERMINALES) A GPC
                gc_collect_cycles();
                $this->info("Enviando Almacenaje de Terminales a GPC...");
                $status = $controller->enviarAGPCTotalAlmacenaje($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 14. ALMACÉN (EXTERNO ALMAN) A GPC
                gc_collect_cycles();
                $this->info("Enviando Almacén (ALMAN) a GPC...");
                $status = $controller->enviarAGPCFacturasDeAlmacen($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                $this->info("--- FIN DE INSERCIONES EN GPC ---");
            } else {
                // 4. IMPUESTOS (Fase 2)
                gc_collect_cycles();
                $this->info("--- [INICIO] Procesando Impuestos (Fase 2) para Tarea #{$tarea->id} ---");
                $status = $controller->importarImpuestosEnAuditorias($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 5. FLETES
                gc_collect_cycles();
                $this->info("--- [INICIO] Procesando Fletes para Tarea #{$tarea->id} ---");
                $status = $controller->auditarFacturasDeFletes($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 6. LLCs
                gc_collect_cycles();
                $this->info("--- [INICIO] Procesando LLCs para Tarea #{$tarea->id} ---");
                $status = $controller->auditarFacturasDeLLC($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 7. PAGOS DE DERECHO (Solo BD)
                gc_collect_cycles();
                $this->info("--- [INICIO] Procesando Pagos de derecho para Tarea #{$tarea->id} ---");
                $status = $controller->auditarFacturasDePagosDeDerecho($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }

                // 8. MUESTRAS
                gc_collect_cycles();
                $this->info("--- [INICIO] Procesando Muestras para Tarea #{$tarea->id} ---");
                $status = $controller->auditarFacturasDeMuestras($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }
                // 9. MANIOBRAS
                gc_collect_cycles();
                $this->info("--- [INICIO] Procesando Maniobras para Tarea #{$tarea->id} ---");
                $status = $controller->auditarFacturasDeManiobras($tarea->id);
                if ($status['code'] > 0) {
                    throw $status['message'];
                }
            }


            // ====================================================================
            // BLOQUE 3: EXPORTACIÓN Y REPORTES FINALES
            // ====================================================================

            // 15. EXPORTACIÓN FACTURADOS
            gc_collect_cycles();
            $this->info("--- [INICIO] Exportando auditorias facturadas a Excel ---");
            $status = $controller->exportarAuditoriasFacturadasAExcel($tarea->id);
            if ($status['code'] > 0) {
                throw $status['message'];
            }

            // 16. EXPORTACIÓN PENDIENTES
            gc_collect_cycles();
            $this->info("--- [INICIO] Exportando auditorias pendientes a Excel ---");
            $status = $controller->exportarAuditoriasPendientesAExcel($tarea->id);
            if ($status['code'] > 0) {
                throw $status['message'];
            }

            // 17. ENVÍO DE CORREO
            gc_collect_cycles();
            $this->info("--- [INICIO] Enviando correo de reportes ---");
            $status = $controller->enviarReportesPorCorreo($tarea->id);
            if ($status['code'] > 0) {
                throw $status['message'];
            }


            // FIN. Marcar tarea completada
            $tarea->update(['status' => 'completado', 'resultado' => 'Proceso de auditoría finalizado con éxito.']);
            $this->info("¡Orquestación de la Tarea #{$tarea->id} completada con éxito!");
            $tarea->refresh();
            Storage::delete($tarea->mapeo_completo_facturas);
        } catch (\Exception $e) {
            $this->error("Falló la orquestación de la Tarea #{$tarea->id}: " . $e->getMessage());
            Log::error("Fallo en orquestación Tarea #{$tarea->id}: " . $e->getMessage());
            gc_collect_cycles();
            (new AuditoriaImpuestosController())->enviarErrorDeReportePorCorreo($tarea, $e);
            $tarea->refresh()->update(['resultado' => 'La orquestación se detuvo debido a un fallo en un subproceso. ' . $e->getMessage()]);
            Storage::delete($tarea->mapeo_completo_facturas);
        }

        return 0;
    }
}
