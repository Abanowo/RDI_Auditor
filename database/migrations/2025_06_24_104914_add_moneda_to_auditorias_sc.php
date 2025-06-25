<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMonedaToAuditoriasSc extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('auditorias_sc', function (Blueprint $table) {
            $table->string('moneda', 3)->after('cargo_edc')->comment('Ej: MXN o USD.');
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
            $table->dropColumn('moneda');
        });
    }
}
