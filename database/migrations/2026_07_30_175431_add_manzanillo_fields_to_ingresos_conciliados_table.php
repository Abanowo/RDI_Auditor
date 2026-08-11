<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddManzanilloFieldsToIngresosConciliadosTable extends Migration
{
    public function up()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->decimal('anticipo', 15, 2)->default(0)->after('llc');
            $table->decimal('garantias', 15, 2)->default(0)->after('anticipo');
            $table->decimal('desglose_naviera', 15, 2)->default(0)->after('garantias');
        });
    }

    public function down()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->dropColumn(['anticipo', 'garantias', 'desglose_naviera']);
        });
    }
}