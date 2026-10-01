<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReporteDiarioConceptosMail extends Mailable
{
    use Queueable, SerializesModels;

    public $reporteDesglosado;
    public $fecha;

    public function __construct(array $reporteDesglosado, string $fecha)
    {
        $this->reporteDesglosado = $reporteDesglosado;
        $this->fecha = $fecha;
    }

    public function build()
    {
        return $this->subject('PAGOS INGRESOS - ' . $this->fecha)
                    ->from('info@intactics.com', 'Intactics')
                    ->view('reporte_diario_conceptos');
    }
}