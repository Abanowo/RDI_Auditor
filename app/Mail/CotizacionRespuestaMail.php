<?php

namespace App\Mail;

use App\Models\Cotizacion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * Aviso interno: el cliente aprobó la cotización o pidió cambios desde el enlace del correo.
 */
class CotizacionRespuestaMail extends Mailable
{
    use Queueable;

    public $cotizacion;

    public function __construct(Cotizacion $cotizacion)
    {
        $this->cotizacion = $cotizacion;
    }

    public function build()
    {
        $c      = $this->cotizacion;
        $accion = $c->aprobada ? 'APROBADA' : 'CAMBIOS SOLICITADOS';

        return $this->subject('Cotización ' . $c->folio . ' ' . $accion . ' / ' . mb_strtoupper((string) $c->cliente, 'UTF-8'))
            ->view('cotizacion_respuesta_correo');
    }
}
