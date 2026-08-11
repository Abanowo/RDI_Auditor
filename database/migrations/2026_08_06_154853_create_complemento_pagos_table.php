<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateComplementoPagosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('complemento_pago', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ingreso_conciliado_id')->nullable();
            $table->string('request_id')->nullable();
            $table->string('serie')->nullable();
            $table->string('folio_factura')->nullable();
            $table->string('folio')->nullable();
            $table->dateTime('fecha')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('complemento_pagos');
    }
}
