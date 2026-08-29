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

    // Recibimos los datos y los archivos en formato binario (decodificados)
    public function __construct($ingreso, $datosCorreo, $pdfData = null, $xmlData = null)
    {
        $this->ingreso = $ingreso;
        $this->datosCorreo = $datosCorreo;
        $this->pdfData = $pdfData;
        $this->xmlData = $xmlData;
    }

    public function build()
    {
        // Tomamos el nombre del remitente si está disponible
        $nombreRemitente = $this->datosCorreo['nombreUsuarioDebug'] ?? 'InTactics';

        // Iniciamos el correo
        $correo = $this->from('no-reply@intactics.com', $nombreRemitente)
                       ->subject("Complemento de Pago - {$this->datosCorreo['nombreCliente']}")
                       ->view('cuerpo_correo_complemento_pago') // Tu vista actual
                       ->with($this->datosCorreo);

        $folioStr = $this->datosCorreo['folioDocumento'] ?? 'CFDI';

        // Adjuntamos el PDF desde memoria
        if ($this->pdfData) {
            $correo->attachData($this->pdfData, "Complemento_{$folioStr}.pdf", [
                'mime' => 'application/pdf',
            ]);
        }

        // Adjuntamos el XML desde memoria
        if ($this->xmlData) {
            $correo->attachData($this->xmlData, "Complemento_{$folioStr}.xml", [
                'mime' => 'application/xml',
            ]);
        }

        return $correo;
    }
}