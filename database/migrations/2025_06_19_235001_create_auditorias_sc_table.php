<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditoriasScTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('auditorias_sc', function (Blueprint $table) {
            $table->id();
            
            // Relación única: cada operación solo tiene una auditoría principal de SC.
            $table->foreignId('operacion_id')->unique()->constrained('operaciones')->onDelete('cascade');
            
            // --- DATOS EXTRAÍDOS DE LA FACTURA SC (TU SUGERENCIA) ---
            $table->date('fecha_sc')->comment('La fecha extraída de la factura SC.');
            $table->string('folio_sc');
            $table->string('ruta_txt')->comment('Ruta al archivo TXT de la SC usado en la auditoría.');
            $table->string('ruta_pdf')->nullable()->comment('Ruta al PDF de la SC para el hipervínculo.');
            // --- FIN DE CAMPOS AÑADIDOS ---
            
            $table->decimal('monto_impuestos_sc', 12, 2)->comment('El monto de Impuestos extraído del TXT de la SC.');
            $table->string('estado', 50)->comment('Ej: Coinciden!, No Coinciden, etc.');
            
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
        Schema::dropIfExists('auditorias_sc');
    }
}
