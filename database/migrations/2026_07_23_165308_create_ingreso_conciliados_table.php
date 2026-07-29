<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AQUÍ ESTABA EL ERROR: Le quitamos la "s" a Ingreso para que coincida con tu archivo original
class CreateIngresoConciliadosTable extends Migration
{
    public function up()
    {
        Schema::create('ingresos_conciliados', function (Blueprint $table) {
            $table->id();
            $table->string('sucursal_origen')->nullable();
            $table->string('banco_receptor')->nullable();
            $table->date('fecha')->nullable();
            
            // Relación directa con el Cliente (Foreign Key)
            $table->unsignedBigInteger('cliente_id');
            
            // Referencia
            $table->string('referencia')->nullable();
            $table->string('folio_sc')->nullable();
            $table->string('tipo_comprobante', 50)->default('CFDI');
            
            // Montos (Permiten decimales)
            $table->decimal('monto_deposito', 15, 2)->default(0);
            $table->decimal('honorarios', 15, 2)->default(0);
            $table->decimal('impuestos', 15, 2)->default(0);
            $table->decimal('eci', 15, 2)->default(0);
            $table->decimal('maniobras', 15, 2)->default(0);
            $table->decimal('flete', 15, 2)->default(0);
            $table->decimal('muestras', 15, 2)->default(0);
            $table->decimal('llc', 15, 2)->default(0);
            
            $table->string('proveedor_maniobras')->nullable();
            $table->string('factura_maniobras')->nullable();
            
            $table->string('proveedor_flete')->nullable();
            $table->string('factura_flete')->nullable();
            
            $table->string('proveedor_muestras')->nullable();
            $table->string('factura_muestras')->nullable();
            
            $table->string('proveedor_llc')->nullable();
            $table->string('factura_llc')->nullable();
            
            $table->string('estado_envio')->default('PENDIENTE');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ingresos_conciliados');
    }
}