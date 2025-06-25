<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTipoCambioToAuditoriasScTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */

    public function up()
    {
        Schema::table('auditorias_sc', function (Blueprint $table) {
            $table->decimal('tipo_cambio', 12, 2)->after('moneda')->nullable()
                  ->comment('El tipo de cambio de esta factura.');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('auditorias_sc', function (Blueprint $table) {
             $table->dropColumn('tipo_cambio');
        });
    }
}
