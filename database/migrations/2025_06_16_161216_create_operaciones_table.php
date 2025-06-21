<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOperacionesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
       Schema::create('operaciones', function (Blueprint $table) {
            $table->id(); // Llave primaria auto-incremental (bigIncrements).

            // --- Identificadores Clave ---
            $table->string('pedimento')->unique()->comment('El identificador universal de la operación.');
            $table->string('bl')->nullable()->comment('Bill of Lading, clave para Manzanillo.');
            $table->string('contenedor')->nullable()->comment('Contenedor, clave para Manzanillo.');

            // --- Llaves Foráneas (Relaciones) ---
            // Nota: Estas líneas asumirán que crearemos las tablas 'clientes' y 'sedes' más adelante.
            $table->unsignedBigInteger('cliente_id')->comment('Relación con la tabla de clientes.');
            $table->unsignedBigInteger('sede_id')->comment('Relación con la tabla de sedes.');
            
            // --- Datos Generales ---
            $table->date('fecha_operacion')->comment('La fecha principal de la operación.');
            
            $table->timestamps(); // Campos created_at y updated_at.
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('operaciones');
    }
}
