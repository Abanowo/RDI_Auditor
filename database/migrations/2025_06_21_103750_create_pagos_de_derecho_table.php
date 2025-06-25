<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePagosDeDerechoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pagos_de_derecho', function (Blueprint $table) {
            $table->id();

            // Relacionamos con una operación. Una operación puede tener MÚLTIPLES pagos.
            $table->foreignId('operacion_id')->constrained('operaciones')->onDelete('cascade');

            // --- Datos extraídos del PDF del Pago de Derecho ---
            $table->string('llave_pago')->comment('Llave de pago para SADER');
            $table->string('numero_operacion')->comment('Numero de operacion para SADER');
            $table->decimal('monto_total', 12, 2);
            $table->date('fecha_pago');
            $table->string('ruta_pdf')->comment('Ruta al PDF al PDD para el hipervínculo.');;

            // --- NUEVA COLUMNA AÑADIDA POR TU SUGERENCIA ---
            $table->string('tipo', 50)->default('Normal')->comment('Identifica el comportamiento: Normal, Medio Pago, Segundo Pago, Intactics, etc.');

            // Este campo nos servirá después para saber si ya buscamos su SADER
            $table->string('estado_sader')->default('Pendiente')->comment('Estatus de la SADER');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pagos_de_derecho');
    }
}
