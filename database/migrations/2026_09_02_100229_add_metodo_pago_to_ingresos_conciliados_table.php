<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMetodoPagoToIngresosConciliadosTable extends Migration
{
    public function up()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->string('metodo_pago', 10)->nullable()->default('PPD')->after('honorarios');
        });
    }

    public function down()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->dropColumn('metodo_pago');
        });
    }
}
