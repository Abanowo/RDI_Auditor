<?php

use App\Models\Cotizacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Folio nuevo: COT + sucursal + DDMMAAAA + número de cotización del cliente + -cliente
 * (ej. COTN26102026001-asimex-global). La columna crece para que quepa el nombre del cliente
 * y se recalculan los folios de las cotizaciones existentes.
 */
class NuevaNomenclaturaFolioCotizaciones extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE cotizaciones MODIFY folio VARCHAR(191) NULL');

        Cotizacion::withTrashed()->orderBy('id')->get()->each(function (Cotizacion $c) {
            $folio = $c->generarFolio();
            Cotizacion::withTrashed()->whereKey($c->id)->update(['folio' => $folio]);
        });
    }

    public function down()
    {
        Cotizacion::withTrashed()->orderBy('id')->get()->each(function (Cotizacion $c) {
            $anio = $c->fecha ? $c->fecha->format('Y') : date('Y');
            Cotizacion::withTrashed()->whereKey($c->id)->update(['folio' => sprintf('COT-%s-%04d', $anio, $c->id)]);
        });

        DB::statement('ALTER TABLE cotizaciones MODIFY folio VARCHAR(30) NULL');
    }
}
