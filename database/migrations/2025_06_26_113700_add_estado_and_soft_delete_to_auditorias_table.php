<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEstadoAndSoftDeleteToAuditoriasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('auditorias', function (Blueprint $table) {
           $table->string('estado')->after('moneda_documento')->default('N/A')->comment('Estatus de la factura');
           $table->softDeletes();
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
            $table->dropColumn('estado');
            $table->dropSoftDeletes();
        });
    }
}
