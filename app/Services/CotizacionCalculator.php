<?php

namespace App\Services;

use App\Models\Cotizacion;
use Illuminate\Support\Collection;

/**
 * Toda la aritmética de la cotización vive aquí (el JS del editor replica
 * las mismas reglas solo para mostrar los totales en vivo).
 *
 *  - Concepto en dólares:  importe = cantidad × USD × tipo de cambio
 *  - Concepto en pesos:    importe = cantidad × MXN
 *  - Bloque con IVA:       total   = subtotal + subtotal × 16 %
 *  - Total estimado:       suma de los totales de cada bloque
 */
class CotizacionCalculator
{
    public function importe($cantidad, $usd, $mxn, $tipoCambio)
    {
        $cantidad = (float) $cantidad;
        $usd      = (float) $usd;
        $mxn      = (float) $mxn;

        if ($usd > 0) {
            return round($cantidad * $usd * (float) $tipoCambio, 2);
        }

        return round($cantidad * $mxn, 2);
    }

    /**
     * @param  Cotizacion  $cotizacion  con la relación "conceptos" cargada (guardada o en borrador)
     * @param  Collection  $secciones   secciones activas en orden
     * @return array
     */
    public function resumen(Cotizacion $cotizacion, Collection $secciones)
    {
        $tasa       = (float) config('cotizador.iva', 0.16);
        $porSeccion = $cotizacion->conceptos->groupBy('seccion_id');
        $bloques    = [];
        $total      = 0;

        foreach ($secciones as $seccion) {
            $conceptos = $porSeccion->get($seccion->id, collect());

            if ($conceptos->isEmpty()) {
                continue;
            }

            $subtotal = round($conceptos->sum('importe_mxn'), 2);
            $iva      = $seccion->aplica_iva ? round($subtotal * $tasa, 2) : 0.0;

            $bloques[] = [
                'seccion'   => $seccion,
                'conceptos' => $conceptos->values(),
                'subtotal'  => $subtotal,
                'iva'       => $iva,
                'total'     => round($subtotal + $iva, 2),
            ];

            $total += $subtotal + $iva;
        }

        return [
            'bloques'  => $bloques,
            'total'    => round($total, 2),
            'tasa_iva' => $tasa,
        ];
    }
}
