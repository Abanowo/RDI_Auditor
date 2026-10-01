<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IngresoConciliado;
use App\Models\Empresas;
use App\Mail\ReporteDiarioConceptosMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class EnviarReporteDiarioConceptos extends Command
{
    protected $signature = 'reportes:enviar-ingresos-diarios {fecha?}';
    protected $description = 'Procesa los ingresos diarios desglosados por concepto seccionando Transportactics e Intshipperts como sucursales independientes';

    public function handle()
    {
        $fechaConsulta = $this->argument('fecha') ?: Carbon::now()->format('Y-m-d');
        $this->info("Procesando reporte diario desde la base de datos para la fecha: {$fechaConsulta}...");

        $ingresos = IngresoConciliado::with('operaciones')
            ->whereDate('fecha', $fechaConsulta)
            ->get();

        if ($ingresos->isEmpty()) {
            $this->warn("No se encontraron ingresos para la fecha {$fechaConsulta}.");
            return 0;
        }

        $clienteIds = $ingresos->pluck('cliente_id')->filter()->unique()->toArray();
        $mapaClientes = [];

        if (!empty($clienteIds)) {
            $clientesDb = Empresas::whereIn('id', $clienteIds)->get();
            foreach ($clientesDb as $c) {
                $mapaClientes[$c->id] = $c->nombre ?? $c->razon_social ?? $c->cliente ?? '';
            }
        }

        $reporteDesglosado = [];

        foreach ($ingresos as $ingreso) {
            $sucursalFull = strtoupper($ingreso->sucursal_origen ?: 'NOGALES');

            $clienteNombre = 'SIN CLIENTE';
            if (!empty($ingreso->cliente_id) && isset($mapaClientes[$ingreso->cliente_id])) {
                $clienteNombre = $mapaClientes[$ingreso->cliente_id];
            } elseif (!empty($ingreso->nuevo_cliente_nombre)) {
                $clienteNombre = $ingreso->nuevo_cliente_nombre;
            } elseif (!empty($ingreso->getRawOriginal('cliente'))) {
                $clienteNombre = $ingreso->getRawOriginal('cliente');
            }

            $clienteUpper = strtoupper($clienteNombre);

            $isTransportactics = str_contains($clienteUpper, 'TRANSPORTACTIC') || str_contains($sucursalFull, 'TRANSPORTACTIC');
            $esIntshipperts    = str_contains($clienteUpper, 'INTSHIPPERT')    || str_contains($sucursalFull, 'INTSHIPPERT');
            $esManzanillo      = str_contains($sucursalFull, 'MANZANILLO')       || str_contains($sucursalFull, 'ZLO');

            $sucursalBase = trim(str_replace([' IMPO', ' EXPO', ' TRANSPORTACTICS', ' INTSHIPPERTS'], '', $sucursalFull));

            if ($isTransportactics) {
                $sucursalSeccion = 'TRANSPORTACTICS';
                $sucursalCod     = 'TRN';
            } elseif ($esIntshipperts) {
                $sucursalSeccion = 'INTSHIPPERTS';
                $sucursalCod     = 'INT';
            } else {
                $sucursalSeccion = $sucursalBase;
                $sucursalCod     = 'NOG';
                if (str_contains($sucursalBase, 'TIJUANA')) {
                    $sucursalCod = 'TIJ';
                } elseif (str_contains($sucursalBase, 'MANZANILLO') || str_contains($sucursalBase, 'ZLO')) {
                    $sucursalCod = 'ZLO';
                } elseif (str_contains($sucursalBase, 'LAREDO')) {
                    $sucursalCod = 'LAR';
                } elseif (str_contains($sucursalBase, 'MEXICALI')) {
                    $sucursalCod = 'MXL';
                }
            }

            $moneda = $ingreso->moneda ?: 'MXN';
            $facturaSCHeader = $ingreso->folio_sc ?: ($ingreso->referencia ?: '--');

            $procesadoEnOperaciones = false;

            // 1. SI TIENE OPERACIONES EN LA TABLA PIVOTE
            if ($ingreso->operaciones && $ingreso->operaciones->count() > 0) {

                foreach ($ingreso->operaciones as $op) {
                    $rawRef = trim($op->referencia ?? '');
                    $pedimentoOp = $rawRef ?: ($ingreso->pedimento_detectado ?: $facturaSCHeader);
                    $facturaSCOp = $facturaSCHeader;

                    if (!empty($rawRef)) {
                        $partes = array_map('trim', explode(' - ', $rawRef));

                        if (preg_match('/(\d{7})/', $rawRef, $mPed)) {
                            $pedimentoOp = $mPed[1];
                        } elseif (count($partes) > 1) {
                            $pedimentoOp = $partes[0];
                        }

                        if (!empty($partes[0])) {
                            $scCandidate = preg_replace('/^(F-|SC-)/i', '', $partes[0]);
                            if (!empty($scCandidate)) {
                                $facturaSCOp = $scCandidate;
                            }
                        }
                    }

                    if ($isTransportactics) {
                        $fleteVal    = (float) ($op->flete > 0 ? $op->flete : $op->monto_cfdi);
                        $pagoProvVal = (float) $op->pago_proveedor;
                        $gananciaVal = (float) ($op->ganancia != 0 ? $op->ganancia : ($fleteVal - $pagoProvVal));

                        $conceptosOp = [
                            'FLETE (XML)'    => ['monto' => $fleteVal, 'prov' => !empty($op->proveedor_flete) ? $op->proveedor_flete : ($ingreso->proveedor_flete ?: 'TRANSPORTACTICS'), 'fac' => $op->factura_flete ?? ($ingreso->factura_flete ?: '--')],
                            'PAGO PROVEEDOR' => ['monto' => $pagoProvVal, 'prov' => 'PROVEEDOR EXT.', 'fac' => '--'],
                            'GANANCIA'       => ['monto' => $gananciaVal, 'prov' => 'TRANSPORTACTICS', 'fac' => '--'],
                        ];
                    } elseif ($esIntshipperts) {
                        $anticipoVal = (float) $op->anticipo;
                        $fleteVal    = (float) ($op->flete > 0 ? $op->flete : $op->monto_cfdi);

                        $conceptosOp = [
                            'ALMAN / FLETE'  => ['monto' => $anticipoVal, 'prov' => '--', 'fac' => '--'],
                            'ANTICIPO'       => ['monto' => $fleteVal, 'prov' => !empty($op->proveedor_flete) ? $op->proveedor_flete : ($ingreso->proveedor_flete ?: 'INTSHIPPERTS'), 'fac' => $op->factura_flete ?? ($ingreso->factura_flete ?: '--')],
                        ];
                    } elseif ($esManzanillo) {
                        $conceptosOp = [
                            'ANTICIPO'       => ['monto' => (float) ($op->anticipo ?? 0), 'prov' => '--', 'fac' => '--'],
                            'GARANTIAS'      => ['monto' => (float) ($op->garantias ?? 0), 'prov' => 'NAVIERA', 'fac' => '--'],
                            'DESG. NAVIERA'  => ['monto' => (float) ($op->desglose_naviera ?? 0), 'prov' => 'NAVIERA', 'fac' => '--'],
                            'IMPUESTOS'      => ['monto' => (float) ($op->impuestos ?? 0), 'prov' => 'SAT / ADUANA', 'fac' => '--'],
                            'ALM / FLETE'    => ['monto' => (float) ($op->flete ?? 0), 'prov' => !empty($op->proveedor_flete) ? $op->proveedor_flete : 'TRANSPORTACTICS', 'fac' => $op->factura_flete ?? '--'],
                            'HONORARIOS'     => ['monto' => (float) ($op->monto_cfdi ?? 0), 'prov' => 'INTACTICS', 'fac' => '--'],
                        ];
                    } else {
                        $conceptosOp = [
                            'HONORARIOS'     => ['monto' => (float) ($op->monto_cfdi ?? 0), 'prov' => 'INTACTICS', 'fac' => '--'],
                            'IMPUESTOS'      => ['monto' => (float) ($op->impuestos ?? 0), 'prov' => 'SAT / ADUANA', 'fac' => '--'],
                            'ECI (DERECHOS)' => ['monto' => (float) ($op->eci ?? 0), 'prov' => 'SENASICA', 'fac' => '--'],
                            'MANIOBRAS'      => ['monto' => (float) ($op->maniobras ?? 0), 'prov' => !empty($op->proveedor_maniobras) ? $op->proveedor_maniobras : 'SAFINSA', 'fac' => $op->factura_maniobras ?? '--'],
                            'FLETE'          => ['monto' => (float) ($op->flete ?? 0), 'prov' => !empty($op->proveedor_flete) ? $op->proveedor_flete : 'TRANSPORTACTICS', 'fac' => $op->factura_flete ?? '--'],
                            'MUESTRAS'       => ['monto' => (float) ($op->muestras ?? 0), 'prov' => !empty($op->proveedor_muestras) ? $op->proveedor_muestras : '--', 'fac' => $op->factura_muestras ?? '--'],
                            'LLC'            => ['monto' => (float) ($op->llc ?? 0), 'prov' => !empty($op->proveedor_llc) ? $op->proveedor_llc : 'LLC', 'fac' => $op->factura_llc ?? '--'],
                        ];
                    }

                    foreach ($conceptosOp as $pxcc => $datos) {
                        if ($datos['monto'] != 0) {
                            $procesadoEnOperaciones = true;
                            $facturaP = (!empty($datos['fac']) && $datos['fac'] !== '-' && $datos['fac'] !== 'N/A') ? $datos['fac'] : '--';
                            $proveedor = !empty($datos['prov']) ? strtoupper(trim($datos['prov'])) : '--';

                            $reporteDesglosado[$sucursalSeccion][$pxcc][] = [
                                'sucursal_cod' => $sucursalCod,
                                'cliente'      => strtoupper($clienteNombre),
                                'pedimento'    => $pedimentoOp,
                                'proveedor'    => $proveedor,
                                'factura_p'    => $facturaP,
                                'monto'        => $datos['monto'],
                                'moneda'       => $moneda,
                                'factura_sc'   => $facturaSCOp,
                            ];
                        }
                    }
                }
            }

            // 2. RESPALDO CABECERA
            if (!$procesadoEnOperaciones) {
                $pedimentoHeader = $ingreso->pedimento_detectado ?: ($ingreso->referencia ?: $facturaSCHeader);

                if ($isTransportactics) {
                    $fleteVal = (float) $ingreso->flete;
                    $pagoProvVal = (float) $ingreso->pago_proveedor;
                    $gananciaVal = (float) ($ingreso->ganancia ?: ($fleteVal - $pagoProvVal));

                    $conceptosHeader = [
                        'FLETE (XML)'    => ['monto' => $fleteVal, 'prov' => !empty($ingreso->proveedor_flete) ? $ingreso->proveedor_flete : 'TRANSPORTACTICS', 'fac' => $ingreso->factura_flete ?: '--'],
                    ];
                } elseif ($esIntshipperts) {
                    $conceptosHeader = [
                        'ALMAN / FLETE'  => ['monto' => (float) $ingreso->anticipo, 'prov' => '--', 'fac' => '--'],
                        'ANTICIPO'       => ['monto' => (float) $ingreso->flete, 'prov' => !empty($ingreso->proveedor_flete) ? $ingreso->proveedor_flete : 'INTSHIPPERTS', 'fac' => $ingreso->factura_flete ?: '--'],
                    ];
                } elseif ($esManzanillo) {
                    $conceptosHeader = [
                        'ANTICIPO'       => ['monto' => (float) $ingreso->anticipo, 'prov' => '--', 'fac' => '--'],
                        'GARANTIAS'      => ['monto' => (float) $ingreso->garantias, 'prov' => 'NAVIERA', 'fac' => '--'],
                        'DESG. NAVIERA'  => ['monto' => (float) $ingreso->desglose_naviera, 'prov' => 'NAVIERA', 'fac' => '--'],
                        'ALM / FLETE'    => ['monto' => (float) $ingreso->flete, 'prov' => !empty($ingreso->proveedor_flete) ? $ingreso->proveedor_flete : 'TRANSPORTACTICS', 'fac' => $ingreso->factura_flete ?: '--'],
                    ];
                } else {
                    $conceptosHeader = [
                        'MANIOBRAS'      => ['monto' => (float) $ingreso->maniobras,   'prov' => $ingreso->proveedor_maniobras ?: 'SAFINSA', 'fac' => $ingreso->factura_maniobras ?: '--'],
                        'FLETE'          => ['monto' => (float) $ingreso->flete,       'prov' => $ingreso->proveedor_flete ?: 'TRANSPORTACTICS', 'fac' => $ingreso->factura_flete ?: '--'],
                        'MUESTRAS'       => ['monto' => (float) $ingreso->muestras,    'prov' => $ingreso->proveedor_muestras ?: '--', 'fac' => $ingreso->factura_muestras ?: '--'],
                        'LLC'            => ['monto' => (float) $ingreso->llc,         'prov' => $ingreso->proveedor_llc ?: 'LLC', 'fac' => $ingreso->factura_llc ?: '--'],
                    ];
                }

                foreach ($conceptosHeader as $pxcc => $datos) {
                    if ($datos['monto'] != 0) {
                        $facturaP = (!empty($datos['fac']) && $datos['fac'] !== '-' && $datos['fac'] !== 'N/A') ? $datos['fac'] : '--';

                        $reporteDesglosado[$sucursalSeccion][$pxcc][] = [
                            'sucursal_cod' => $sucursalCod,
                            'cliente'      => strtoupper($clienteNombre),
                            'pedimento'    => $pedimentoHeader,
                            'proveedor'    => strtoupper($datos['prov']),
                            'factura_p'    => $facturaP,
                            'monto'        => $datos['monto'],
                            'moneda'       => $moneda,
                            'factura_sc'   => $facturaSCHeader,
                        ];
                    }
                }
            }
        }

        if(app()->environment('production')) {
            $destinatarios = ['finanzas@intactics.com', 'oscar.sandoval@intactics.com', 'sayda.leyva@intactics.com'];
        } else {
            $destinatarios = ['carlos.perez@intactics.com'];
        }
        $fechaFormateada = Carbon::parse($fechaConsulta)->format('d/m/Y');

        try {
            Mail::to($destinatarios)->send(new ReporteDiarioConceptosMail($reporteDesglosado, $fechaFormateada));
            $this->info("Reporte diario enviado exitosamente.");
        } catch (\Exception $e) {
            $this->error("Error al enviar el correo diario: " . $e->getMessage());
        }

        return 0;
    }
}
