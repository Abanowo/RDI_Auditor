<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCotizadorTables extends Migration
{
    public function up()
    {
        // Bloques de la cotización (Honorarios, Servicio de operación, Transporte...)
        Schema::create('cotizacion_secciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('nombre');                 // Título en el editor
            $table->string('titulo_pdf');             // Título en el PDF
            $table->enum('color', ['azul', 'naranja'])->default('naranja');
            $table->boolean('aplica_iva')->default(false);
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Conceptos predefinidos (combo "-- Cargar --")
        Schema::create('conceptos_catalogo', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('seccion_id')->constrained('cotizacion_secciones')->cascadeOnDelete();
            $table->string('codigo', 20)->nullable();
            $table->string('descripcion');
            $table->decimal('precio_usd', 12, 2)->default(0);
            $table->decimal('precio_mxn', 12, 2)->default(0);
            $table->string('descripcion_doc', 50)->default('Por factura');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('folio', 30)->nullable()->unique();
            $table->string('cliente');
            $table->string('contacto')->nullable();
            $table->date('fecha');
            $table->date('valida_hasta')->nullable();
            $table->string('puerto', 100)->nullable();
            $table->enum('tipo_operacion', ['IMPORTACION', 'EXPORTACION']);
            $table->string('producto')->nullable();
            $table->string('vendedor')->nullable();
            $table->decimal('tipo_cambio', 10, 4);
            $table->boolean('aprobada')->default(false);
            $table->timestamp('aprobada_at')->nullable();
            $table->decimal('total_mxn', 14, 2)->default(0);
            // Sin llave foránea: en tu BD "users.id" no es del mismo tipo (BIGINT UNSIGNED)
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('enviada_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cotizacion_conceptos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->foreignId('seccion_id')->constrained('cotizacion_secciones');
            $table->string('codigo', 20)->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('precio_usd', 12, 2)->default(0);   // tarifa unitaria en dólares
            $table->decimal('precio_mxn', 12, 2)->default(0);   // tarifa unitaria en pesos (si no hay USD)
            $table->decimal('importe_mxn', 14, 2)->default(0);  // cantidad × tarifa × tipo de cambio
            $table->string('descripcion_doc', 50)->default('Por factura');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('terminos_catalogo', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->text('texto');
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('cotizacion_terminos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->text('texto');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cotizacion_terminos');
        Schema::dropIfExists('terminos_catalogo');
        Schema::dropIfExists('cotizacion_conceptos');
        Schema::dropIfExists('cotizaciones');
        Schema::dropIfExists('conceptos_catalogo');
        Schema::dropIfExists('cotizacion_secciones');
    }
}