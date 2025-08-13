<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditoriasTotalesScTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('auditorias_totales_sc', function (Blueprint $table) {
            // Llave primaria
            $table->id();

            // Llaves Foráneas
            $table->unsignedInteger('operacion_id')->nullable()->comment('Vincula con el pedimento en la tabla `operaciones_importacion`');
            $table->unsignedInteger('pedimento_id')->nullable()->comment('Vincula con el pedimento en la tabla `pedimiento`');


            // Datos del Documento
            $table->string('operation_type')->nullable()->comment('Aqui se pone el modelo Importacion/Exportacion');
            $table->string('folio')->nullable()->comment('Consistente con la tabla de auditorías');
            $table->date('fecha_documento')->nullable()->comment('Consistente con la tabla de auditorías');
            $table->text('desglose_conceptos')->nullable()->comment('Guarda el desglose de montos de la factura SC');

            //Rutas
            $table->string('ruta_txt')->nullable();
            $table->string('ruta_pdf')->nullable();


            // Timestamps de Laravel
            $table->timestamps();
            $table->softDeletes();

            // Definición de las Relaciones (Llaves Foráneas)
            $table->foreign('pedimento_id')->nullable()->constrained()->references('id_pedimiento')->on('pedimiento')->onDelete('cascade');

            // Le decimos a la base de datos que no puede haber dos filas con el mismo 'operacion_id'.
            $table->unique(['pedimento_id', 'operation_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('auditorias_totales_sc');
    }
}
