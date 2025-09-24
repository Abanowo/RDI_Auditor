<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateIndexAuditoriasTotalesScPedimentoIdOperationTypeUnique extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('auditorias_totales_sc', function (Blueprint $table) {
            $table->index(['pedimento_id']);
            $table->dropUnique('auditorias_totales_sc_pedimento_id_operation_type_unique');
            $table->unique(['pedimento_id', 'operacion_id', 'operation_type'], 'auditorias_totales_sc_pedimento_id_operation_type_unique');
            $table->dropIndex(['pedimento_id']);
            // Or for a composite index:
            // $table->dropIndex(['column_name_1', 'column_name_2']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('auditorias_totales_sc', function (Blueprint $table) {
            $table->index(['pedimento_id']);
            $table->dropUnique('auditorias_totales_sc_pedimento_id_operation_type_unique');
            $table->unique(['pedimento_id', 'operation_type'], 'auditorias_totales_sc_pedimento_id_operation_type_unique');
            $table->dropIndex(['pedimento_id']);
            // Or for a composite index:
            // $table->dropIndex(['column_name_1', 'column_name_2']);
        });
    }
}
