<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segmentos contables: cada concepto predefinido pertenece a una sucursal
 * (por el prefijo de su código) y tiene su clave de producto/servicio SAT.
 */
class AddSucursalClaveSatToConceptos extends Migration
{
    public function up()
    {
        Schema::table('conceptos_catalogo', function (Blueprint $table) {
            $table->string('sucursal', 40)->nullable()->after('seccion_id')->index();   // null = todas
            $table->string('clave_sat', 20)->nullable()->after('codigo');
        });

        Schema::table('cotizacion_conceptos', function (Blueprint $table) {
            $table->string('clave_sat', 20)->nullable()->after('codigo');
        });
    }

    public function down()
    {
        Schema::table('cotizacion_conceptos', function (Blueprint $table) {
            $table->dropColumn('clave_sat');
        });

        Schema::table('conceptos_catalogo', function (Blueprint $table) {
            $table->dropIndex(['sucursal']);
            $table->dropColumn(['sucursal', 'clave_sat']);
        });
    }
}
