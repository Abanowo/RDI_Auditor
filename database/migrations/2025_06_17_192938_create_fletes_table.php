<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFletesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fletes', function (Blueprint $table) {
            $table->id();
            
            // Llave foránea para vincular esta factura con la operación principal.
            // Esto crea la relación en la base de datos.
            $table->foreignId('operacion_id')->constrained('operaciones')->onDelete('cascade');
            
            // --- Datos extraídos de la factura de Fletes (XML/PDF) ---
            $table->string('folio')->unique()->comment('Folio único de la factura de Transportactics.');
            $table->decimal('monto_total', 12, 2)->comment('El monto Total extraído del XML.');
            $table->string('moneda', 3)->comment('Ej: MXN o USD.');
            $table->string('ruta_xml')->comment('Ruta al archivo XML para referencia futura.');
            $table->string('ruta_pdf')->nullable()->comment('Ruta al PDF si existe.');
            $table->string('ruta_txt')->nullable()->comment('Ruta al TXT si existe.');
            // --- Datos para la auditoría ---
            $table->decimal('monto_esperado_sc', 12, 2)->comment('El monto del concepto Fletes en la SC.');
            $table->decimal('monto_esperado_mxn', 12, 2)->comment('El monto esperado de la SC convertido a MXN para la comparación.');
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
        Schema::dropIfExists('fletes');
    }
}
