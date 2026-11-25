<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            $table->string('folio_factura', 50)->nullable()->after('referencia');
            $table->decimal('honorarios_facturado', 15, 2)->default(0)->after('monto_cfdi');
            $table->index('folio_factura');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            //
        });
    }
};
