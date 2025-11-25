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

    /**
     * Create a new message instance.
     *
     * @param \App\Models\AuditoriaTarea $tarea
     * @return void
     */
    public function __construct(AuditoriaTareas $tarea)
    {
        $this->tarea = $tarea;
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
