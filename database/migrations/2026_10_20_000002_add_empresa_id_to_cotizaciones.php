<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga cada cotización con su registro de la tabla "empresas".
 * Sin llave foránea física para no depender del tipo de columna de tu tabla.
 */
class AddEmpresaIdToCotizaciones extends Migration
{
    public function up()
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable()->after('folio')->index();
        });
    }

    public function down()
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropIndex(['empresa_id']);
            $table->dropColumn('empresa_id');
        });
    }
}
