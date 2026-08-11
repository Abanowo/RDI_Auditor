<?php

namespace App\Mail;

use App\Models\SaldoFavor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificacionSaldoFavorMail extends Mailable
{
    use Queueable, SerializesModels;
    public $saldo;
    public $nombreArchivoFirma;

    /**
     * Create a new message instance.
     */
    public function __construct(SaldoFavor $saldo, $nombreArchivoFirma = null)
    {
        $this->saldo = $saldo;
        $this->nombreArchivoFirma = $nombreArchivoFirma;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Aviso de Saldo a Favor - ' . ($this->saldo->cliente->nombre ?? ''))
                    ->view('cuerpo_correo_saldo_favor'); // El nombre de tu archivo .blade.php
    }
}