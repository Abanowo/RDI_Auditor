<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ComplementoPagoMail extends Mailable
{
    use Queueable, SerializesModels;

    public $ingreso;
    public $datosCorreo;
    public $pdfData;
    public $xmlData;

    public function __construct($ingreso, $datosCorreo, $pdfData = null, $xmlData = null)
    {
        $this->ingreso = $ingreso;
        $this->datosCorreo = $datosCorreo;
        $this->pdfData = $pdfData;
        $this->xmlData = $xmlData;
    }

    public function build()
    {
        // Tomamos el nombre y el correo real del remitente
        $nombreRemitente = $this->datosCorreo['nombreUsuarioDebug'] ?? 'InTactics';
        $emailRemitente  = $this->datosCorreo['emailUsuario'] ?? 'no-reply@intactics.com';

        // Iniciamos el correo dinámico
        $correo = $this->from($emailRemitente, $nombreRemitente)
                       ->subject("Complemento de Pago - {$this->datosCorreo['nombreCliente']}")
                       ->view('cuerpo_correo_complemento_pago')
                       ->with($this->datosCorreo);

        $folioStr = $this->datosCorreo['folioDocumento'] ?? 'CFDI';

        if ($this->pdfData) {
            $correo->attachData($this->pdfData, "Complemento_{$folioStr}.pdf", [
                'mime' => 'application/pdf',
            ]);
        }

        if ($this->xmlData) {
            $correo->attachData($this->xmlData, "Complemento_{$folioStr}.xml", [
                'mime' => 'application/xml',
            ]);
        }

        return $correo;
    }
}