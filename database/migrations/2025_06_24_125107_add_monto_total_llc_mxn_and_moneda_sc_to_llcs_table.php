<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMontoTotalLlcMxnAndMonedaScToLlcsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('llcs', function (Blueprint $table) {
            $table->decimal('monto_total_llc_mxn', 12, 2)->after('monto_total_llc')->comment('La conversion a MXN del monto LLC.');
            $table->string('moneda_sc', 3)->after('monto_esperado_mxn');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('llcs', function (Blueprint $table) {
            $table->dropColumn('monto_total_llc_mxn');
            $table->dropColumn('moneda_sc');
        });
    }
}
