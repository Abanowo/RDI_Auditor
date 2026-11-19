<?php

namespace App\Services;

use App\Models\Cotizacion;
use Illuminate\Support\Collection;

/**
 * Toda la aritmética de la cotización vive aquí (el JS del editor replica
 * las mismas reglas solo para mostrar los totales en vivo).
 *
 * En la cotización NO se convierten dólares a pesos: eso se hace hasta que se factura.
 *  - Concepto en dólares:  importe = cantidad × USD   (se suma en USD)
 *  - Concepto en pesos:    importe = cantidad × MXN   (se suma en MXN)
 *  - Concepto al costo:    sin importe
 *  - Bloque con IVA:       IVA de cada moneda = subtotal de esa moneda × 16 %
 */
class CotizacionCalculator
{
    /** Importe en pesos del concepto (0 si se cotiza en dólares) */
    public function importe($cantidad, $usd, $mxn)
    {
        return (float) $usd > 0 ? 0.0 : round((float) $cantidad * (float) $mxn, 2);
    }

    /** Importe en dólares del concepto (0 si se cotiza en pesos) */
    public function importeUsd($cantidad, $usd)
    {
        return round((float) $cantidad * (float) $usd, 2);
    }

    /**
     * @param  Cotizacion  $cotizacion  con la relación "conceptos" cargada (guardada o en borrador)
     * @param  Collection  $secciones   secciones activas en orden
     * @return array  total / subtotal / iva = pesos; total_usd / subtotal_usd / iva_usd = dólares
     */
    public function resumen(Cotizacion $cotizacion, Collection $secciones)
    {
        $tasa       = (float) config('cotizador.iva', 0.16);
        $porSeccion = $cotizacion->conceptos->groupBy('seccion_id');
        $bloques    = [];
        $totalMxn   = 0;
        $totalUsd   = 0;

        foreach ($secciones as $seccion) {
            $conceptos = $porSeccion->get($seccion->id, collect());

            if ($conceptos->isEmpty()) {
                continue;
            }

            $subMxn = round($conceptos->sum(function ($c) {
                return $c->al_costo ? 0 : $this->importe($c->cantidad, $c->precio_usd, $c->precio_mxn);
            }), 2);
            $subUsd = round($conceptos->sum(function ($c) {
                return $c->al_costo ? 0 : $this->importeUsd($c->cantidad, $c->precio_usd);
            }), 2);
            $ivaMxn = $seccion->aplica_iva ? round($subMxn * $tasa, 2) : 0.0;
            $ivaUsd = $seccion->aplica_iva ? round($subUsd * $tasa, 2) : 0.0;

            $bloques[] = [
                'seccion'      => $seccion,
                'conceptos'    => $conceptos->values(),
                'subtotal'     => $subMxn,
                'iva'          => $ivaMxn,
                'total'        => round($subMxn + $ivaMxn, 2),
                'subtotal_usd' => $subUsd,
                'iva_usd'      => $ivaUsd,
                'total_usd'    => round($subUsd + $ivaUsd, 2),
            ];

            $totalMxn += $subMxn + $ivaMxn;
            $totalUsd += $subUsd + $ivaUsd;
        }

        return [
            'bloques'   => $bloques,
            'total'     => round($totalMxn, 2),
            'total_usd' => round($totalUsd, 2),
            'tasa_iva'  => $tasa,
        ];
    }
}
