<?php

use App\Models\Cotizacion;
use App\Models\Seccion;
use App\Services\CotizacionCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los conceptos en dólares ya no se convierten a pesos en la cotización (se hace al facturar).
 * Se agrega el total en dólares y se recalculan las cotizaciones existentes.
 */
class CotizacionMontosPorMoneda extends Migration
{
    public function up()
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->decimal('total_usd', 14, 2)->default(0)->after('total_mxn');
        });

        // Los conceptos en dólares dejan de tener importe en pesos
        DB::table('cotizacion_conceptos')->where('precio_usd', '>', 0)->update(['importe_mxn' => 0]);

        $calc      = new CotizacionCalculator();
        $secciones = Seccion::orderBy('orden')->get();
        Cotizacion::withTrashed()->with('conceptos')->get()->each(function ($c) use ($calc, $secciones) {
            $r = $calc->resumen($c, $secciones);
            Cotizacion::withTrashed()->whereKey($c->id)->update(['total_mxn' => $r['total'], 'total_usd' => $r['total_usd']]);
        });
    }

    public function down()
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('total_usd');
        });
    }
}
