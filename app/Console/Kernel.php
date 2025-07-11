<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\GenerarReporteRdiCommand;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\ProcesarAuditoriaCompleta::class,
        \App\Console\Commands\ImportarOperacionesCommand::class, // El que ya teníamos (con su nuevo nombre)
        \App\Console\Commands\MapearFacturasCommand::class,
        \App\Console\Commands\AuditarFletesCommand::class,
        \App\Console\Commands\AuditarScCommand::class,     // ¡El nuevo que acabamos de crear!
        \App\Console\Commands\AuditarLlcCommand::class,     // ¡El nuevo que acabamos de crear!
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
