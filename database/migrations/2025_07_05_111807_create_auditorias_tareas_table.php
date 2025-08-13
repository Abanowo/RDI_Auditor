<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditoriasTareasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('auditorias_tareas', function (Blueprint $table) {
            // `id` bigint(20) UNSIGNED NOT NULL
            $table->id();

            //Datos generales
            $table->string('banco');
            $table->string('sucursal');
            $table->string('nombre_archivo');

            //Rutas de archivos subidos
            $table->string('ruta_estado_de_cuenta');
            $table->text('rutas_extras')->nullable();

            //Pedimentos utilizados para esta tarea
            $table->text('pedimentos_procesados')->nullable();
            $table->text('pedimentos_descartados')->nullable();

            // Columnas adicionales para gestionar el estado de la tarea
            $table->string('status')->default('pendiente')->comment('pendiente, procesando, completado, fallido');
            $table->text('resultado')->nullable()->comment('Guarda mensajes de éxito o error del proceso');
            $table->integer('periodo_meses')->unsigned()->nullable();
            $table->date('fecha_documento')->nullable();

            //Rutas de los reportes
            $table->text('mapeo_completo_facturas')->nullable()->comment('Ruta del mapeo de todos los archivos de factura Importacion/Exportacion');
            $table->string('ruta_reporte_impuestos')->nullable()->comment('Reporte de Impuestos - Facturado');
            $table->string('nombre_reporte_impuestos')->nullable();
            $table->string('ruta_reporte_impuestos_pendientes')->nullable()->comment('Reporte de Impuestos - Sin facturar');
            $table->string('nombre_reporte_pendientes')->nullable();

            // Campos estándar de Laravel
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('auditorias_tareas');
    }
}
