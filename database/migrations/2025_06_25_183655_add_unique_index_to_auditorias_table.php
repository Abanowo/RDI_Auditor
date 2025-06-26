<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUniqueIndexToAuditoriasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Usamos Schema::table para modificar una tabla existente.
        Schema::table('auditorias', function (Blueprint $table) {
            // ¡Esta es la línea clave!
            // Crea un índice único en la combinación de estas dos columnas.
            // Ahora la base de datos SÍ sabrá que no puede haber dos filas con el mismo
            // operacion_id Y el mismo tipo_documento.
            $table->unique(['operacion_id', 'tipo_documento']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Esto permite deshacer la migración si es necesario.
        Schema::table('auditorias', function (Blueprint $table) {
            // El nombre del índice es generado por Laravel automáticamente: 'tabla_col1_col2_unique'
            $table->dropUnique('auditorias_operacion_id_tipo_documento_unique');
        });
    }
}
