<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReporteIngresosMail extends Mailable
{
    use Queueable, SerializesModels;

    // 1. Declaramos las 3 variables como públicas para que Blade las pueda leer
    public $ingresosAgrupados;
    public $fecha;
    public $sucursalesNombres; 

    // 2. Las recibimos en el constructor
    public function __construct($ingresosAgrupados, $fecha, $sucursalesNombres)
    {
        $this->ingresosAgrupados = $ingresosAgrupados;
        $this->fecha = $fecha;
        $this->sucursalesNombres = $sucursalesNombres; 
    }

    public function build()
    {
        // 3. Retornamos la vista correcta
        return $this->subject('Reporte Diario de Ingresos por Sucursal - ' . $this->fecha)
                    ->view('cuerpo_correo_reporte_ingresos'); 
    }
}