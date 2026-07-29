<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSaldoFavorsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('saldos_favor', function (Blueprint $table) {
            $table->id();
            
            // Relación con el cliente (Llave foránea)
            $table->unsignedBigInteger('cliente_id');
            
            // Sucursal origen (texto, coincidiendo con tu multiselect)
            $table->string('sucursal_origen')->nullable();
            
            // Monto de crédito (Soporta decimales grandes, ej. $15,000.00)
            $table->decimal('monto', 15, 2)->default(0);
            
            // Fecha de detección del saldo
            $table->date('fecha_deteccion')->nullable();
            
            // Concepto o justificación (text permite textos largos)
            $table->text('concepto')->nullable();
            
            // Estatus de aplicación (Inicia como VIGENTE por defecto)
            $table->string('estatus')->default('VIGENTE');
            
            $table->timestamps();

            // Opcional: Si quieres forzar la integridad referencial a nivel base de datos
            // asumiendo que tu tabla de clientes se llama 'empresas'
            // $table->foreign('cliente_id')->references('id')->on('empresas')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('saldos_favor');
    }
}
