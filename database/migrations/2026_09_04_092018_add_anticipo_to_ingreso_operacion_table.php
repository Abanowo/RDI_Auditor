<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            // 1. Modificamos las columnas existentes para que ahora acepten NULL
            $table->unsignedBigInteger('operacion_id')->nullable()->change();
            $table->string('operacion_type')->nullable()->change();

            // 2. Agregamos las nuevas columnas
            if (!Schema::hasColumn('ingreso_operacion', 'referencia')) {
                $table->string('referencia')->nullable()->after('monto_gpc');
            }
            
            if (!Schema::hasColumn('ingreso_operacion', 'anticipo')) {
                $table->decimal('anticipo', 12, 2)->default(0.00)->after('referencia');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ingreso_operacion', function (Blueprint $table) {
            // En caso de rollback, eliminamos las columnas que agregamos
            $table->dropColumn(['referencia', 'anticipo']);
            
            // Y volvemos a hacer obligatorios los campos (si así estaban antes)
            $table->unsignedBigInteger('operacion_id')->nullable(false)->change();
            $table->string('operacion_type')->nullable(false)->change();
        });
    }
};