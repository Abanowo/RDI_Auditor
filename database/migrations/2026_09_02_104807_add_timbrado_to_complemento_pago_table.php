<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTimbradoToComplementoPagoTable extends Migration
{
    public function up()
    {
        Schema::table('complemento_pago', function (Blueprint $table) {
            // Agregamos la columna booleana (por defecto false)
            $table->boolean('timbrado')->default(false)->after('folio'); // Puedes quitar el ->after() si no tienes columna 'folio'
        });
    }

    public function down()
    {
        Schema::table('complemento_pago', function (Blueprint $table) {
            $table->dropColumn('timbrado');
        });
    }
}
