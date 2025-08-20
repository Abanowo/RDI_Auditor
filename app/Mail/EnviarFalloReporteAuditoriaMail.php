<?php

namespace App\Mail;

use App\Models\AuditoriaTareas;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable; // Importamos la clase Throwable

class EnviarFalloReporteAuditoriaMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * La instancia de la tarea de auditoría que falló.
     * @var \App\Models\AuditoriaTareas
     */
    public $tarea;

    /**
     * La excepción que causó el fallo.
     * @var \Throwable
     */
    public $exception;

    /**
     * Crea una nueva instancia del mensaje.
     *
     * @param \App\Models\AuditoriaTareas $tarea
     * @param \Throwable $exception
     * @return void
     */
    public function __construct(AuditoriaTareas $tarea, Throwable $exception)
    {
        $this->tarea = $tarea;
        $this->exception = $exception;
    }

    /**
     * Construye el mensaje.
     *
     * @return $this
     */
    public function build()
    {
        $email = $this->subject('⚠️ERROR! Reporte de Auditoría - '.  $this->tarea->nombre_archivo)
                      ->view('cuerpo_correo_reporte_auditoria_error'); // Usaremos una vista de Blade para el error

        $rutaEstadoDeCuenta = $this->tarea->ruta_estado_de_cuenta;

        // Adjuntamos el estado de cuenta que causó el problema.
        // Usamos el disco 'local' porque estos archivos son privados.
        if ($rutaEstadoDeCuenta && Storage::disk('local')->exists($rutaEstadoDeCuenta)) {
            $email->attachFromStorage($rutaEstadoDeCuenta, $this->tarea->nombre_archivo, [
                'mime' => $this->tarea->banco !== "EXTERNO" ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $email;
    }
}
