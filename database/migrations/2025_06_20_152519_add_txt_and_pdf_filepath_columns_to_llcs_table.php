<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTxtAndPdfFilepathColumnsToLlcsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
     public function up()
    {
        Schema::table('llcs', function (Blueprint $table) {
            // Añadimos la nueva columna después de la columna 'fecha_operacion'.
            // Es decimal, y puede ser nulo si en algún caso no se encuentra el cargo.
            // Añadimos la columna para la ruta del TXT después de la de XML.
        $table->string('ruta_pdf')->after('moneda')->nullable()->comment('Ruta al archivo PDF de la factura de LLC.');
        $table->string('ruta_txt')->after('moneda')->nullable()->comment('Ruta al archivo TXT de la factura de LLC.');

        });
    }

    public function down()
    {
        Schema::table('llcs', function (Blueprint $table) {
            // El método down() define cómo revertir el cambio, es una buena práctica.
            $table->dropColumn('ruta_pdf');
             $table->dropColumn('ruta_txt');
        });
    }
}
