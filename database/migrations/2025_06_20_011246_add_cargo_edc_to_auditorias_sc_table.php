<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCargoEdcToAuditoriasScTable extends Migration
{
    public function up()
    {
        Schema::table('auditorias_sc', function (Blueprint $table) {
            // Añadimos la nueva columna después de la columna 'fecha_operacion'.
            // Es decimal, y puede ser nulo si en algún caso no se encuentra el cargo.
            $table->decimal('cargo_edc', 12, 2)->after('ruta_pdf')->nullable()
                  ->comment('El monto del cargo extraído del Estado de Cuenta.');
        });
    }

    public function down()
    {
        Schema::table('auditorias_sc', function (Blueprint $table) {
            // El método down() define cómo revertir el cambio, es una buena práctica.
            $table->dropColumn('cargo_edc');
        });
    }
}
