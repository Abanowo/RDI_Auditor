<?php

namespace App\Mail;

use App\Models\SaldoFavor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificacionSaldoContraMail extends Mailable
{
    use Queueable, SerializesModels;

    public $saldo;
    public $datosFirma;

    public function __construct(SaldoFavor $saldo, $datosFirma = null)
    {
        $this->saldo = $saldo;
        $this->datosFirma = $datosFirma;
    }

    public function build()
    {
        return $this->subject('Aviso de Saldo Pendiente de Cobro - ' . ($this->saldo->concepto ?? $this->saldo->sucursal_origen))
                    ->view('cuerpo_correo_saldo_contra');
    }
}