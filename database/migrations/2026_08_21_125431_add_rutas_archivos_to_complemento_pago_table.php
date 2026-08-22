<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('complemento_pago', function (Blueprint $table) {
            // Agregamos las columnas para las rutas (pueden ser nulas al inicio)
            $table->string('ruta_pdf')->nullable();
            $table->string('ruta_xml')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('complemento_pago', function (Blueprint $table) {
            $table->dropColumn(['ruta_pdf', 'ruta_xml']);
        });
    }
};