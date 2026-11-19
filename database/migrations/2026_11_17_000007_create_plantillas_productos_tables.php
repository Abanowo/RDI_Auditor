<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de conceptos por producto (Aguacate Fresco, Fruta fresca…).
 * Al elegir el producto en una cotización se cargan sus conceptos en cada bloque.
 */
class CreatePlantillasProductosTables extends Migration
{
    public function up()
    {
        Schema::create('plantillas_productos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('producto');                          // "Aguacate Fresco"
            $table->string('tipo_operacion', 20)->nullable();    // IMPORTACION | EXPORTACION | null = ambas
            $table->string('sucursal', 40)->nullable();          // NOGALES, TIJUANA… | null = todas
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['producto', 'tipo_operacion', 'sucursal']);
        });

        Schema::create('plantilla_conceptos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('plantilla_id')->constrained('plantillas_productos')->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('cotizacion_secciones');
            $table->string('codigo', 20)->nullable();
            $table->string('clave_sat', 20)->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('precio_usd', 12, 2)->default(0);
            $table->decimal('precio_mxn', 12, 2)->default(0);
            $table->boolean('al_costo')->default(false);         // "Al costo" en lugar de tarifa
            $table->string('descripcion_doc', 50)->default('Por factura');
            $table->text('nota')->nullable();                    // "Incluye: revisión de la mercancía…"
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        // La cotización conserva la nota y el "Al costo" que vienen de la plantilla
        Schema::table('cotizacion_conceptos', function (Blueprint $table) {
            $table->boolean('al_costo')->default(false)->after('importe_mxn');
            $table->text('nota')->nullable()->after('descripcion_doc');
        });
    }

    public function down()
    {
        Schema::table('cotizacion_conceptos', function (Blueprint $table) {
            $table->dropColumn(['al_costo', 'nota']);
        });
        Schema::dropIfExists('plantilla_conceptos');
        Schema::dropIfExists('plantillas_productos');
    }
}
