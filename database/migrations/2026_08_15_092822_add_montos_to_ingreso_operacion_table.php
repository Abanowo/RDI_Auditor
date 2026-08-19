<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMontosToIngresoOperacionTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            $table->decimal('monto_cfdi', 15, 2)->default(0)->after('operacion_type');
            $table->decimal('monto_gpc', 15, 2)->default(0)->after('monto_cfdi');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            $table->dropColumn(['monto_cfdi', 'monto_gpc']);
        });
    }
}
