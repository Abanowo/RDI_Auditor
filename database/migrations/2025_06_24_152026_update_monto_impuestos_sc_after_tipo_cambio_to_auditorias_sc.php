<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Doctrine\DBAL;

class UpdateMontoImpuestosScAfterTipoCambioToAuditoriasSc extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    //Give the moving column a temporary name:
    Schema::table('auditorias_sc', function($table)
    {
        $table->renameColumn('monto_impuestos_sc', 'temp');
    });

    //Add a new column with the regular name:
    Schema::table('auditorias_sc', function(Blueprint $table)
    {
        $table->decimal('monto_impuestos_sc', 12, 2)->comment('El monto de Impuestos extraído del TXT de la SC.')->after('tipo_cambio');
    });

    //Copy the data across to the new column:
    DB::table('auditorias_sc')->update([
        'monto_impuestos_sc' => DB::raw('temp')
    ]);

    //Remove the old column:
    Schema::table('auditorias_sc', function(Blueprint $table)
    {
        $table->dropColumn('temp');
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
            //
        });
    }
}
