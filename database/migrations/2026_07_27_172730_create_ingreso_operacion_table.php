<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ingreso_operacion', function (Blueprint $table) {
            $table->id();
            // Relación con tu ingreso
            $table->foreignId('ingreso_id')->constrained('ingresos_conciliados')->onDelete('cascade');
            
            // Relación polimórfica (acepta múltiples IDs de diferentes tablas)
            $table->unsignedBigInteger('operacion_id');
            $table->string('operacion_type'); 
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ingreso_operacion');
    }
};