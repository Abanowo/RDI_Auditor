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
            $table->json('detalle_maniobras')->nullable()->after('factura_maniobras');
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
