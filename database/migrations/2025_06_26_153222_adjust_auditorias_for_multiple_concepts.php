<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AdjustAuditoriasForMultipleConcepts extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('auditorias', function (Blueprint $table) {
            // Eliminamos la llave foránea temporalmente.
            // Laravel sabe cómo encontrarla si le pasamos el nombre de la columna en un array.
            $table->dropForeign(['operacion_id']);
            // 1. Primero, eliminamos el índice antiguo que era demasiado restrictivo.
            // El nombre del índice es generado por Laravel: 'tabla_col1_col2_unique'
            $table->dropUnique('auditorias_operacion_id_tipo_documento_unique');

            $table->string('num_operacion_pdd')->default('N/A')->comment('N. Operacion para pago de derecho')->change();
            $table->string('llave_pago_pdd')->default('N/A')->comment('Llave de pago para pago de derecho')->change();

            // 3. Creamos el nuevo índice UNIQUE, ahora sobre TRES columnas.
            // Esta es la nueva regla de oro para la unicidad en toda la tabla.
            $table->unique(['operacion_id', 'tipo_documento', 'llave_pago_pdd']);

            // 4. Finalmente, volvemos a crear la llave foránea.
            $table->foreign('operacion_id')->references('id')->on('operaciones')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('auditorias', function (Blueprint $table) {
            // Pasos para revertir la migración
            $table->dropForeign(['operacion_id']);
            $table->dropUnique('auditorias_operacion_id_tipo_documento_llave_pago_pdd_unique');
            $table->string('llave_pago_pdd')->nullable()->comment('Llave de pago para pago de derecho')->change();
            $table->string('num_operacion_pdd')->nullable()->comment('N. Operacion para pago de derecho')->change();
            $table->unique(['operacion_id', 'tipo_documento']); // Recreamos el índice original
            $table->foreign('operacion_id')->references('id')->on('operaciones')->onDelete('cascade')->comment('Vincula con la operación en la tabla `operaciones`');
        });
    }
};
