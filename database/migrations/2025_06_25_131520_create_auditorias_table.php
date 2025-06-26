<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditoriasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('auditorias', function (Blueprint $table) {
            // id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
            $table->id();

            // operacion_id BIGINT UNSIGNED NOT NULL
            $table->foreignId('operacion_id')->constrained('operaciones')->onDelete('cascade')->comment('Vincula con la operación en la tabla `operaciones`');
            // tipo_documento VARCHAR(50) NOT NULL
            $table->string('tipo_documento', 50)->comment('El tipo de evento: edc, sc, flete, lic, pdd');

            // --- DATOS GENERALES DEL DOCUMENTO ---
            $table->string('folio')->nullable();
            $table->date('fecha_documento')->nullable();

            // --- DATOS FINANCIEROS SIMPLES ---
            // Para flete, lic, etc., es su monto principal. Para sc, es el total general.
            $table->decimal('monto_total', 14, 2)->nullable();
            $table->decimal('monto_total_mxn', 14, 2)->nullable()->comment('Valor estandarizado a MXN para cálculos');
            $table->string('moneda_documento', 3)->nullable();

            // --- EL CONTENEDOR DE DATOS COMPLEJOS (LA ESTRELLA DEL DISEÑO) ---
            // Esta columna SOLO se usará para el tipo_documento = 'sc'
            $table->json('desglose_conceptos')->nullable()->comment('Guarda el desglose de montos de la factura SC');

            // --- CAMPOS ESPECÍFICOS Y RUTAS ---
            $table->string('llave_pago_pdd')->nullable()->comment('Llave de pago para pago de derecho');
            $table->string('num_operacion_pdd')->nullable()->comment('N. Operacion para pago de derecho');

            $table->string('ruta_txt')->nullable();
            $table->string('ruta_pdf')->nullable();
            $table->string('ruta_xml')->nullable();

            // created_at y updated_at TIMESTAMPS
            $table->timestamps();

            // --- ÍNDICES Y LLAVE FORÁNEA ---
            // Un índice para buscar rápidamente por tipo de documento
            $table->index('tipo_documento');
            // La llave foránea que une esta tabla con la operación principal

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('auditorias');
    }
}
