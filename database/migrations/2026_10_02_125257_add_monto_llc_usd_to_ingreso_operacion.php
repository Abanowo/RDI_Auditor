<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMontoLlcUsdToIngresoOperacion extends Migration
{
    /**
     * Monto de la LLC en dólares (columna K del Google Sheet).
     * La columna "llc" existente conserva el equivalente en pesos (columna G).
     */
    public function up()
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            if (!Schema::hasColumn('ingreso_operacion', 'monto_llc_usd')) {
                $table->decimal('monto_llc_usd', 14, 2)->default(0);
            }
        });
    }

    public function down()
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            if (Schema::hasColumn('ingreso_operacion', 'monto_llc_usd')) {
                $table->dropColumn('monto_llc_usd');
            }
        });
    }
}