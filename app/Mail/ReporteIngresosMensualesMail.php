<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReporteIngresosMensualesMail extends Mailable
{
    use Queueable, SerializesModels;

    public $mesNombre;
    public $rutaArchivo;

    public function __construct($mesNombre, $rutaArchivo)
    {
        $this->mesNombre = $mesNombre;
        $this->rutaArchivo = $rutaArchivo;
    }

    public function build()
    {
        return $this->subject("Reporte Mensual de Ingresos Conciliados - " . ucfirst($this->mesNombre))
                    ->view('reporte_ingresos_mensuales')
                    ->attach($this->rutaArchivo, [
                        'as' => 'Reporte_Ingresos_'. ucfirst($this->mesNombre) .'.xlsx',
                        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
    }
}