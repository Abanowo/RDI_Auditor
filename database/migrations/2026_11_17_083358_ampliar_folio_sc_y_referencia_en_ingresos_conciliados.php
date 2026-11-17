<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
        public function up()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->text('folio_sc')->nullable()->change();
            $table->text('referencia')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('ingresos_conciliados', function (Blueprint $table) {
            $table->string('folio_sc', 255)->nullable()->change();
            $table->string('referencia', 255)->nullable()->change();
        });
    }
};
