<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRutaTxtAndFechaToFletesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
{
    Schema::table('fletes', function (Blueprint $table) {
        // Añadimos la columna para la fecha de la factura después de la columna 'folio'.
        $table->date('fecha')->after('folio')->comment('Fecha de la factura de Fletes.');

        // Añadimos la columna para la ruta del TXT después de la de XML.
        $table->string('ruta_txt')->after('ruta_xml')->comment('Ruta al archivo TXT de la factura de Transportactics.');
    });
}

/**
 * Define cómo revertir la migración (buena práctica).
 */
public function down()
{
    Schema::table('fletes', function (Blueprint $table) {
        $table->dropColumn(['ruta_txt', 'fecha']);
    });
}
}
