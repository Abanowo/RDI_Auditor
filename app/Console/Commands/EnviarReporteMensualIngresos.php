<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Exports\IngresosMensualesExport;
use App\Mail\ReporteIngresosMensualesMail;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class EnviarReporteMensualIngresos extends Command
{
    protected $signature = 'reportes:enviar-ingresos-mensuales';
    protected $description = 'Genera un Excel con los ingresos del mes y lo envía por correo';

    public function handle()
    {
        $this->info('Iniciando generación del reporte mensual...');

        $inicioMes = Carbon::now()->startOfMonth();
        $finMes = Carbon::now()->endOfMonth();
        
        Carbon::setLocale('es');
        $mesNombre = $inicioMes->translatedFormat('F Y');

        $fileName = 'reporte_ingresos_' . $inicioMes->format('Y_m') . '.xlsx';
        $rutaTemporal = storage_path('app/temp/' . $fileName);

        if (!Storage::exists('temp')) {
            Storage::makeDirectory('temp');
        }

        // Generar el Excel
        Excel::store(new IngresosMensualesExport($inicioMes->format('Y-m-d'), $finMes->format('Y-m-d')), 'temp/' . $fileName);

        if(app()->environment('production')) {
            $destinatarios = ['finanzas@intactics.com', 'oscar.sandoval@intactics.com', 'sayda.leyva@intactics.com'];
        } else {
            $destinatarios = ['carlos.perez@intactics.com'];
        }

        $this->info("Enviando correo para el mes de {$mesNombre} (Desde: {$inicioMes->format('Y-m-d')} hasta: {$finMes->format('Y-m-d')})...");

        try {
            Mail::to($destinatarios)->send(new ReporteIngresosMensualesMail($mesNombre, $rutaTemporal)); 
            $this->info('Correo enviado exitosamente.');
        } catch (\Exception $e) {
            $this->error('Error al enviar el correo: ' . $e->getMessage());
        }

        if (file_exists($rutaTemporal)) {
            unlink($rutaTemporal);
        }

        $this->info('Proceso finalizado.');
    }
}