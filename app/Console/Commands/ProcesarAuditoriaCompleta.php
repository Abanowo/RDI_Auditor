<?php

namespace App\Console\Commands;

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

            // 1. Llama a cada comando en secuencia, pasándole el ID de la tarea
            $this->info("--- [INICIO] Procesando SCs para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de SC...");
            Artisan::call('reporte:auditar-sc', ['--tarea_id' => $tarea->id]); // <-- Descomentarás cuando lo tengas
            // Capturamos y mostramos la salida del comando anterior
            $this->line(Artisan::output());
            $this->info("--- [FIN] Procesamiento de SCs.");

            // 2. Llama a cada comando en secuencia, pasándole el ID de la tarea
            $this->info("--- [INICIO] Procesando Impuestos para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Impuestos...");
            Artisan::call('reporte:importar-operaciones', ['--tarea_id' => $tarea->id]);
            // Capturamos y mostramos la salida del comando anterior
            $this->line(Artisan::output());
            $this->info("--- [FIN] Procesamiento de Impuestos.");

            // 3. Llama a cada comando en secuencia, pasándole el ID de la tarea
            $this->info("--- [INICIO] Procesando Fletes para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Fletes...");
            Artisan::call('reporte:auditar-fletes', ['--tarea_id' => $tarea->id]); // <-- Descomentarás cuando lo tengas
            // Capturamos y mostramos la salida del comando anterior
            $this->line(Artisan::output());
            $this->info("--- [FIN] Procesamiento de Fletes.");

            // 4. Llama a cada comando en secuencia, pasándole el ID de la tarea
            $this->info("--- [INICIO] Procesando LLCs para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de LLC...");
            Artisan::call('reporte:auditar-llc', ['--tarea_id' => $tarea->id]); // <-- Descomentarás cuando lo tengas
            // Capturamos y mostramos la salida del comando anterior
            $this->line(Artisan::output());
            $this->info("--- [FIN] Procesamiento de LLCs.");

            // 5. Llama a cada comando en secuencia, pasándole el ID de la tarea
            $this->info("--- [INICIO] Procesando Pagos de derecho para Tarea #{$tarea->id} ---");
            Log::info("Tarea #{$tarea->id}: Ejecutando comando de Pagos de derecho...");
            Artisan::call('reporte:auditar-pagos-derecho', ['--tarea_id' => $tarea->id]); // <-- Descomentarás cuando lo tengas
            // Capturamos y mostramos la salida del comando anterior
            $this->line(Artisan::output());
            $this->info("--- [FIN] Procesamiento de Pagos de derecho.");

            //6. Si todos los comandos terminan bien, marca la tarea como completada
            $tarea->update(['status' => 'completado', 'resultado' => 'Proceso de auditoría finalizado con éxito.']);
            $this->info("¡Orquestación de la Tarea #{$tarea->id} completada con éxito!");

        } catch (\Exception $e) {
            // 7. Si algún comando falla, captura el error y marca la tarea como fallida
            $tarea->update(['status' => 'fallido', 'resultado' => 'Error durante la orquestación: ' . $e->getMessage()]);
            $this->error("Falló la orquestación de la Tarea #{$tarea->id}: " . $e->getMessage());
            Log::error("Fallo en orquestación Tarea #{$tarea->id}: " . $e->getMessage());
        }

        return 0;
    }
}
