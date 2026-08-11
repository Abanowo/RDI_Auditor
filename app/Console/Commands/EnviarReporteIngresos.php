<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IngresoConciliado;
use App\Mail\ReporteIngresosMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class EnviarReporteIngresos extends Command
{
    protected $signature = 'email:reporte-ingresos';

    protected $description = 'Envía un correo con todos los ingresos conciliados que no han sido reportados.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // 2. Usamos la fecha de HOY solo para ponerla en el título del correo
        $fechaReporte = Carbon::today()->toDateString();
        $this->info("Generando reporte de ingresos pendientes...");

        // CONSULTAMOS TODOS LOS PENDIENTES (Sin importar la fecha de depósito)
        $ingresos = IngresoConciliado::with('cliente')
            ->where('enviado_en_reporte', false)
            ->get();

        if ($ingresos->isEmpty()) {
            $this->warn("No hay ingresos nuevos sin reportar. No se enviará correo.");
            return 0; 
        }

        // 4. Agrupación Doble (Sucursal > Banco)
        $ingresosAgrupados = $ingresos->groupBy('sucursal_origen')->map(function ($grupoSucursal) {
            return $grupoSucursal->groupBy('banco_receptor');
        });

        $sucursalesNombres = implode(', ', $ingresosAgrupados->keys()->toArray());
        if(app()->environment('production')) {
            $destinatarios = ['finanzas@intactics.com', 'oscar.sandoval@intactics.com', 'sayda.leyva@intactics.com'];
        } else {
            $destinatarios = ['carlos.perez@intactics.com'];
        }

        // 5. Enviamos el correo
        Mail::to($destinatarios)->send(new ReporteIngresosMail($ingresosAgrupados, $fechaReporte, $sucursalesNombres));

        // 6. Actualizamos LOS QUE SE ACABAN DE ENVIAR para que no se repitan
        $idsEnviados = $ingresos->pluck('id')->toArray();
        
        IngresoConciliado::whereIn('id', $idsEnviados)->update([
            'enviado_en_reporte' => true
        ]);

        $this->info("¡El reporte se envió correctamente y se marcaron " . count($idsEnviados) . " registros como enviados!");
        return 0;
    }
}