<?php

namespace App\Mail;

use App\Models\Cotizacion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

class CotizacionMail extends Mailable
{
    use Queueable;

    public $cotizacion;
    public $mensaje;
    public $asunto;
    public $firmaRuta;
    public $firmaUrl;
    public $firmaDatos;
    public $firmaMime;
    public $nombreRemitente;
    protected $pdf;
    protected $responderA;

    /**
     * @param array $remitente ['nombre' => ..., 'email' => ..., 'firma_ruta' => ..., 'firma_url' => ...]
     */
    public function __construct(Cotizacion $cotizacion, $pdf, $mensaje = null, $asunto = null, array $remitente = [])
    {
        $this->cotizacion      = $cotizacion;
        $this->pdf             = $pdf;
        $this->mensaje         = $mensaje;
        $this->asunto          = $asunto;
        $this->firmaRuta       = isset($remitente['firma_ruta']) ? $remitente['firma_ruta'] : null;
        $this->firmaUrl        = isset($remitente['firma_url']) ? $remitente['firma_url'] : null;
        $this->firmaDatos      = isset($remitente['firma_datos']) ? $remitente['firma_datos'] : null;
        $this->firmaMime       = isset($remitente['firma_mime']) ? $remitente['firma_mime'] : 'image/png';
        $this->nombreRemitente = isset($remitente['nombre']) ? $remitente['nombre'] : null;
        $this->responderA      = isset($remitente['email']) ? $remitente['email'] : null;
    }

    public function build()
    {
        $asunto = trim((string) $this->asunto) !== ''
            ? $this->asunto
            : 'COTIZACION DE SERVICIOS INTACTICS / ' . mb_strtoupper(trim((string) $this->cotizacion->cliente), 'UTF-8');

        // Las respuestas del cliente llegan al coordinador que envió la cotización
        if ($this->responderA && filter_var($this->responderA, FILTER_VALIDATE_EMAIL)) {
            $this->replyTo($this->responderA, $this->nombreRemitente);
        }

        return $this->subject($asunto)
            ->view('cotizacion_correo')
            ->attachData($this->pdf, $this->cotizacion->nombreArchivo(), ['mime' => 'application/pdf']);
    }
}
