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

            // 1. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando Impuestos (Fase 1) para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->ids}: Ejecutando comando de Impuestos...");
            $status = (new AuditoriaImpuestosController())->importarImpuestosEnAuditorias($tarea->id); //Impuestos Fase (1)
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Procesamiento de Impuestos.");
            Log::info("--- [FIN] Procesamiento de Impuestos.");


            // 2. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Creando mapeo para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de mapeo...");
            $status = (new AuditoriaImpuestosController())->mapearFacturasYFacturasSCEnAuditorias($tarea->id);  //Mapeado
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Creacion de mapeo.");
            Log::info("--- [FIN] Creacion de mapeo.");


            // 3. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando SCs para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de SC...");
            $status = (new AuditoriaImpuestosController())->importarFacturasSCEnAuditoriasTotalesSC($tarea->id);  //SC
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Procesamiento de SCs.");
            Log::info("--- [FIN] Procesamiento de SCs.");


            // 4. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando Impuestos (Fase 2) para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Impuestos...");
            $status = (new AuditoriaImpuestosController())->importarImpuestosEnAuditorias($tarea->id); //Impuestos Fase (2)
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Procesamiento de Impuestos.");
            Log::info("--- [FIN] Procesamiento de Impuestos.");


            // 5. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando Fletes para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Fletes...");
            $status = (new AuditoriaImpuestosController())->auditarFacturasDeFletes($tarea->id); //Fletes
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Procesamiento de Fletes.");
            Log::info("--- [FIN] Procesamiento de Fletes.");


            // 6. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando LLCs para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de LLC...");
            $status = (new AuditoriaImpuestosController())->auditarFacturasDeLLC($tarea->id); //LLC
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Procesamiento de LLCs.");
            Log::info("--- [FIN] Procesamiento de LLCs.");


            // 7. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando Pagos de derecho para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Pagos de derecho...");
            $status = (new AuditoriaImpuestosController())->auditarFacturasDePagosDeDerecho($tarea->id);  //Pagos de derecho
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Procesamiento de Pagos de derecho.");
            Log::info("--- [FIN] Procesamiento de Pagos de derecho.");


            // 8. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Procesando Muestras para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Muestras...");
            $status = (new AuditoriaImpuestosController())->auditarFacturasDeMuestras($tarea->id);  //Muestras
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Procesamiento de Muestras.");
            Log::info("--- [FIN] Procesamiento de Muestras.");


            // 9. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Exportando auditorias facturadas a Excel para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Exportacion de auditorias...");
            $status = (new AuditoriaImpuestosController())->exportarAuditoriasFacturadasAExcel($tarea->id);  //Exportacion a Excel - Facturados
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Exportacion de auditorias facturadas a Excel.");
            Log::info("--- [FIN] Exportacion de auditorias facturadas a Excel.");

            // 10. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Exportando auditorias pendientes a Excel para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Exportacion de auditorias...");
            $status = (new AuditoriaImpuestosController())->exportarAuditoriasPendientesAExcel($tarea->id);  //Exportacion a Excel - Pendientes
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Exportacion de auditorias pendientes a Excel.");
            Log::info("--- [FIN] Exportacion de auditorias pendientes a Excel.");

            // 11. Llama a cada comando en secuencia, pasándole el ID de la tarea
            gc_collect_cycles();
            $this->info("--- [INICIO] Enviando correo de reportes a destinatario para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Envio de correo de reportes...");
            $status = (new AuditoriaImpuestosController())->enviarReportesPorCorreo($tarea->id);  //Envio de correo
            if($status['code'] > 0) throw $status['message'];

            $this->info("--- [FIN] Enviando correo de reportes.");
            Log::info("--- [FIN] Enviando correo de reportes.");

            //6. Si todos los comandos terminan bien, marca la tarea como completada
            $tarea->update(['status' => 'completado', 'resultado' => 'Proceso de auditoría finalizado con éxito.']);
            $this->info("¡Orquestación de la Tarea #{$tarea->id} completada con éxito!");
            $tarea->refresh();
            Storage::delete($tarea->mapeo_completo_facturas);

        } catch (\Exception $e) {
            // Si algún comando falla, la excepción lanzada será capturada aquí.
            // No es necesario actualizar el estado a 'fallido' aquí, porque el subcomando ya debería haberlo hecho.
            // Solo registramos el error en el orquestador.
            $this->error("Falló la orquestación de la Tarea #{$tarea->id}: " . $e->getMessage());
            Log::error("Fallo en orquestación Tarea #{$tarea->id}: " . $e->getMessage());
            gc_collect_cycles();
            (new AuditoriaImpuestosController())->enviarErrorDeReportePorCorreo($tarea, $e);
            // Opcional: puedes añadir un resultado más específico del orquestador si lo deseas.
            $tarea->refresh()->update(['resultado' => 'La orquestación se detuvo debido a un fallo en un subproceso. ' . $e->getMessage()]);
            Storage::delete($tarea->mapeo_completo_facturas);
        }

        return 0;
    }
}