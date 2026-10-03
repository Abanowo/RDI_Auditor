<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEstatusPagoToIngresoOperacion extends Migration
{
    /**
     * Un estatus de pago por cada concepto que tiene proveedor.
     * Valores: NULL (pendiente), 'PAGADO', 'PAGADO POR ANT'.
     */
    private $columnas = [
        'estatus_pago_maniobras',
        'estatus_pago_flete',
        'estatus_pago_muestras',
        'estatus_pago_llc',
    ];

    public function up()
    {
        $columnas = $this->columnas;

        Schema::table('ingreso_operacion', function (Blueprint $table) use ($columnas) {
            foreach ($columnas as $columna) {
                if (!Schema::hasColumn('ingreso_operacion', $columna)) {
                    $table->string($columna, 30)->nullable();
                }
            }
        });
    }

    public function down()
    {
        $columnas = $this->columnas;

        Schema::table('ingreso_operacion', function (Blueprint $table) use ($columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn('ingreso_operacion', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}