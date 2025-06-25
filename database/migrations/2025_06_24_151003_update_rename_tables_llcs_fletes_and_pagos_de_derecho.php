<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateRenameTablesLlcsFletesAndPagosDeDerecho extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::rename('llcs', 'auditorias_llc');
        Schema::rename('fletes','auditorias_fletes');
        Schema::rename('pagos_de_derecho','auditorias_pdd');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::rename('auditoria_llc', 'llcs');
        Schema::rename('auditoria_fletes', 'fletes');
        Schema::rename('auditoria_pdd', 'pagos_de_derecho');

    }
}
