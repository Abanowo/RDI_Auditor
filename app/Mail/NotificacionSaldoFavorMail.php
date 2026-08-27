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
        // Aquí es donde desempaquetamos el arreglo y se lo enviamos a Blade
        return $this->subject('Notificación de Saldo a Favor - InTactics')
                    ->view('cuerpo_correo_saldo_favor') // Verifica que el nombre de tu vista Blade sea este
                    ->with([
                        'nombreEmpresaEmisora' => 'InTactics',
                        'urlFirma'             => $this->datosFirma['urlFirma'] ?? '',
                        'nombreUsuarioDebug'   => $this->datosFirma['nombreUsuarioDebug'] ?? 'Usuario No Identificado'
                    ]);
    }
}