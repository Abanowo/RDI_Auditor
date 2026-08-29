<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificacionSaldoFavorMail extends Mailable
{
    use Queueable, SerializesModels;

    public $saldo;
    public $datosFirma; // Debe existir esta variable

    // En el constructor recibimos el saldo y el arreglo de datosFirma
    public function __construct($saldo, $datosFirma)
    {
        $this->saldo = $saldo;
        $this->datosFirma = $datosFirma;
    }

    public function build()
    {
        $nombreRemitente = $this->datosFirma['nombreUsuarioDebug'] ?? 'InTactics';
        $emailRemitente  = $this->datosFirma['emailUsuario'] ?? 'no-reply@intactics.com';

        return $this->from($emailRemitente, $nombreRemitente)
                    ->subject('Notificación de Saldo a Favor - InTactics')
                    ->view('cuerpo_correo_saldo_favor')
                    ->with([
                        'nombreEmpresaEmisora' => 'InTactics',
                        'urlFirma'             => $this->datosFirma['urlFirma'] ?? '',
                        'nombreUsuarioDebug'   => $this->datosFirma['nombreUsuarioDebug'] ?? 'Usuario No Identificado'
                    ]);
    }
}