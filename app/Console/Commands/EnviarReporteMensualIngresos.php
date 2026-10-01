<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\IngresoConciliado;
use App\Exports\IngresosMensualesExport;
use App\Mail\ReporteIngresosMensualesMail;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class EnviarReporteMensualIngresos extends Command
{
    protected $signature = 'reportes:enviar-ingresos-mensuales {--mes-anterior : Genera el reporte del mes pasado}';
    protected $description = 'Genera un Excel con los ingresos del mes y lo envía por correo';

    public function handle()
    {
        $this->info('Iniciando generación del reporte mensual...');

        $fechaReferencia = Carbon::now();
        if ($this->option('mes-anterior') || Carbon::now()->day <= 5) {
            $fechaReferencia->subMonth();
            $this->info("Detectado inicio de mes. Generando reporte para el mes de: " . $fechaReferencia->translatedFormat('F Y'));
        }

        $inicioMes = $fechaReferencia->copy()->startOfMonth();
        $finMes = $fechaReferencia->copy()->endOfMonth();
        
        Carbon::setLocale('es');
        $mesNombre = $fechaReferencia->translatedFormat('F Y');

        $hayRegistros = IngresoConciliado::whereDate('fecha', '>=', $inicioMes->format('Y-m-d'))
            ->whereDate('fecha', '<=', $finMes->format('Y-m-d'))
            ->exists();

        if (!$hayRegistros) {
            $this->warn("No existen ingresos registrados entre el {$inicioMes->format('d/m/Y')} y el {$finMes->format('d/m/Y')}. Se cancela el envío del reporte.");
            return 0;
        }

        $fileName = 'reporte_ingresos_' . $inicioMes->format('Y_m') . '.xlsx';
        $rutaTemporal = storage_path('app/temp/' . $fileName);

        if (!Storage::exists('temp')) {
            Storage::makeDirectory('temp');
        }

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
        return 0;
    }
}