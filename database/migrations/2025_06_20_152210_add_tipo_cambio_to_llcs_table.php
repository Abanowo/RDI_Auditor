<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTipoCambioToLlcsTable extends Migration
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
            $table->decimal('tipo_cambio', 12, 2)->after('moneda')->nullable()
                  ->comment('El tipo de cambio de esta factura.');
        });
    }

    public function down()
    {
        Schema::table('llcs', function (Blueprint $table) {
            // El método down() define cómo revertir el cambio, es una buena práctica.
            $table->dropColumn('tipo_cambio');
        });
    }
}
