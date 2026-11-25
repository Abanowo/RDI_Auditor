<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complemento_pago', function (Blueprint $table) {
            if (!Schema::hasColumn('complemento_pago', 'ruta_txt')) {
                $table->string('ruta_txt')->nullable()->after('ruta_xml');
            }
        });

        Schema::table('complemento_pago', function (Blueprint $table) {
            if (!Schema::hasColumn('complemento_pago', 'nombre_txt')) {
                $table->string('nombre_txt')->nullable()->after('ruta_txt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('complemento_pago', function (Blueprint $table) {
            if (Schema::hasColumn('complemento_pago', 'nombre_txt')) {
                $table->dropColumn('nombre_txt');
            }
        });

        Schema::table('complemento_pago', function (Blueprint $table) {
            if (Schema::hasColumn('complemento_pago', 'ruta_txt')) {
                $table->dropColumn('ruta_txt');
            }
        });
    }
};