<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTotalGpcToIngresosConciliadosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->decimal('total_gpc', 15, 2)->default(0.00)->after('monto_deposito');
        });
    }

    public function down()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->dropColumn('total_gpc');
        });
    }
}
