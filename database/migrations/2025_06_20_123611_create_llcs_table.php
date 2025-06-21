<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLlcsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
{
    Schema::create('llcs', function (Blueprint $table) {
        $table->id();

        // Relacionamos cada registro de auditoría LLC con una operación.
        $table->foreignId('operacion_id')->constrained('operaciones')->onDelete('cascade');

        // --- Datos extraídos de la factura LLC ---
        $table->string('folio_llc')->unique()->comment('Folio único de la factura LLC.');
        $table->date('fecha_llc')->comment('La fecha extraída de la factura LLC.');
        $table->decimal('monto_total_llc', 12, 2)->comment('La SUMA de todos los movAmount del TXT.');
        $table->string('moneda', 3)->default('USD');

        // --- Datos para la auditoría ---
        $table->decimal('monto_esperado_sc', 12, 2)->comment('El monto de "Gastos en EEUU" en la SC.');
        $table->decimal('monto_esperado_mxn', 12, 2)->comment('El monto esperado de la SC convertido a MXN.');
        $table->string('estado', 50)->comment('Ej: Coinciden!, Pago de más!, etc.');

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
        Schema::dropIfExists('llcs');
    }
}
