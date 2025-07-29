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
            // Llave primaria
            $table->id();

            // Llaves Foráneas
            $table->unsignedInteger('operacion_id')->nullable()->comment('Vincula con el pedimento en la tabla `operaciones_importacion`');
            $table->unsignedInteger('pedimento_id')->nullable()->comment('Vincula con el pedimento en la tabla `pedimiento`');

            // Datos del Documento
            $table->string('operation_type')->nullable()->comment('Aqui se pone el modelo Importacion/Exportacion');
            $table->string('tipo_documento', 50)->comment('El tipo de evento auditado: flete, llc, pdd, etc.');
            $table->string('concepto_llave')->default('principal')->comment('Llave para unicidad con tipo_documento y operacion_id');
            $table->string('folio')->nullable();
            $table->date('fecha_documento')->nullable();

            // Montos
            $table->decimal('monto_total', 14, 2)->nullable()->comment('Monto en la moneda original del documento');
            $table->decimal('monto_total_mxn', 14, 2)->nullable()->comment('Valor estandarizado a MXN para cálculos');
            $table->string('monto_diferencia_sc', 14, 2)->nullable()->comment('Diferencia positiva o negativa de la auditoria');
            $table->string('moneda_documento', 3)->nullable();


            // Estado y Datos Específicos
            $table->string('estado')->nullable()->comment('Estatus de la factura (Ej: Pendiente, Conciliado)');
            $table->string('llave_pago_pdd')->nullable()->comment('Dato específico del PDD');
            $table->string('num_operacion_pdd')->nullable()->comment('Dato específico del PDD');

            // Rutas de Archivos
            $table->string('ruta_txt')->nullable();
            $table->string('ruta_pdf')->nullable();
            $table->string('ruta_xml')->nullable();


            // Timestamps de Laravel
            $table->timestamps();
            $table->softDeletes();

            // Definición de las Relaciones (Llaves Foráneas)
            $table->foreign('pedimento_id')->references('id_pedimiento')->nullable()->constrained()->on('pedimiento')->onDelete('cascade');

            // Definición de la Llave Única Compuesta
            $table->unique(['pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], 'auditorias_ped_id_operation_type_tipo_doc_concepto_unique');
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
