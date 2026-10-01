<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            $table->decimal('impuestos', 12, 2)->default(0)->nullable();
            $table->decimal('eci', 12, 2)->default(0)->nullable();
            $table->decimal('maniobras', 12, 2)->default(0)->nullable();
            $table->decimal('flete', 12, 2)->default(0)->nullable();
            $table->decimal('muestras', 12, 2)->default(0)->nullable();
            $table->decimal('llc', 12, 2)->default(0)->nullable();
            $table->decimal('garantias', 12, 2)->default(0)->nullable();
            $table->decimal('desglose_naviera', 12, 2)->default(0)->nullable();
            $table->decimal('pago_proveedor', 12, 2)->default(0)->nullable();
            $table->decimal('ganancia', 12, 2)->default(0)->nullable();

            $table->string('proveedor_maniobras')->nullable();
            $table->string('factura_maniobras')->nullable();
            
            $table->string('proveedor_flete')->nullable();
            $table->string('factura_flete')->nullable();
            
            $table->string('proveedor_muestras')->nullable();
            $table->string('factura_muestras')->nullable();
            
            $table->string('proveedor_llc')->nullable();
            $table->string('factura_llc')->nullable();
        });
    }

    public function down()
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            $table->dropColumn([
                'impuestos', 'eci', 'maniobras', 'flete',
                'muestras', 'llc', 'garantias', 'desglose_naviera',
                'pago_proveedor', 'ganancia', 'proveedor_maniobras',
                'factura_maniobras', 'proveedor_flete', 'factura_flete',
                'proveedor_muestras', 'factura_muestras', 'proveedor_llc', 'factura_llc'
            ]);
        });
    }
};