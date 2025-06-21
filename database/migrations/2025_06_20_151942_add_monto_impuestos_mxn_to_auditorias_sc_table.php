<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMontoImpuestosMxnToAuditoriasScTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('auditorias_sc', function (Blueprint $table) {
            // Añadimos la nueva columna después de la columna 'fecha_operacion'.
            // Es decimal, y puede ser nulo si en algún caso no se encuentra el cargo.
            $table->decimal('monto_impuestos_mxn', 12, 2)->after('cargo_edc')->nullable()
                  ->comment('El monto de impuestos con la conversion a MXN.');
        });
    }

    public function down()
    {
        Schema::table('auditorias_sc', function (Blueprint $table) {
            // El método down() define cómo revertir el cambio, es una buena práctica.
            $table->dropColumn('monto_impuestos_mxn');
        });
    }
}
