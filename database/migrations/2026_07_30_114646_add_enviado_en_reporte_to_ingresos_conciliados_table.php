<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEnviadoEnReporteToIngresosConciliadosTable extends Migration
{
    public function up()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->boolean('enviado_en_reporte')->default(false)->after('tipo_comprobante');
        });
    }

    public function down()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->dropColumn('enviado_en_reporte');
        });
    }
}