<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobaciones internas de la cotización (después de que la aprueba el cliente):
 * Dirección, Desarrollo de negocios y Contabilidad.
 */
class CreateCotizacionAprobacionesTable extends Migration
{
    public function up()
    {
        Schema::create('cotizacion_aprobaciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->string('area', 40);                         // direccion | desarrollo_negocios | contabilidad
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente');
            $table->unsignedBigInteger('usuario_id')->nullable(); // id_usuario de quien respondió
            $table->string('usuario_nombre')->nullable();
            $table->text('comentario')->nullable();
            $table->timestamp('respondida_at')->nullable();
            $table->timestamps();

            $table->unique(['cotizacion_id', 'area']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('cotizacion_aprobaciones');
    }
}
