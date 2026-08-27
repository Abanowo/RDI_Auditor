<?php

namespace App\Http\Controllers;

use App\Models\Importacion;
use App\Models\Exportacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ControlProveedoresController extends Controller
{
    public function index(Request $request)
    {
        try {
            $concepto = strtoupper($request->input('concepto', 'GENERAL'));
            $tipoOperacion = strtoupper($request->input('tipo_operacion', 'TODOS'));
            $sucursal = strtoupper($request->input('sucursal', 'TODAS'));

            $operaciones = collect();

            $aplicarFiltros = function ($query) use ($sucursal, $request, $concepto) {
                $query->with('cliente');

                if ($sucursal !== 'TODAS') {
                    $query->whereHas('getSucursal', function ($q) use ($sucursal) {
                        $q->where('nombre', 'LIKE', "%{$sucursal}%");
                    });
                }

                if ($request->filled('pedimento')) {
                    $query->where(function ($q) use ($request) {
                        $q->where('pedimento', 'LIKE', "%{$request->pedimento}%")
                            ->orWhere('referencia', 'LIKE', "%{$request->pedimento}%");
                    });
                }

                if ($request->filled('fecha')) {
                    $query->whereDate('fecha', $request->fecha);
                }

                return $query->orderBy('created_at', 'desc')->take(30)->get();
            };

            if ($tipoOperacion === 'IMPO' || $tipoOperacion === 'TODOS') {
                $impos = $aplicarFiltros(Importacion::query());
                $operaciones = $operaciones->merge($impos);
            }

            if ($tipoOperacion === 'EXPO' || $tipoOperacion === 'TODOS') {
                $expos = $aplicarFiltros(Exportacion::query());
                $operaciones = $operaciones->merge($expos);
            }

            // Ordenamos y usamos values() para resetear los índices a 0, 1, 2, 3...
            $operaciones = $operaciones->sortByDesc('created_at')->take(30)->values();

            // Agregamos $index a la función map
            $todasLasOperaciones = $operaciones->map(function ($op, $index) use ($concepto) {

                $ventaConcepto = 0;
                $proveedorExtraido = '';
                $facturaExtraida = '';

                switch ($concepto) {
                    case 'MANIOBRAS':
                        $ventaConcepto = $op->maniobras ?? 0;
                        $proveedorExtraido = $op->proveedor_maniobras ?? '';
                        $facturaExtraida = $op->factura_maniobras ?? '';
                        break;
                    case 'FLETE':
                        $ventaConcepto = $op->flete ?? 0;
                        $proveedorExtraido = $op->proveedor_flete ?? '';
                        $facturaExtraida = $op->factura_flete ?? '';
                        break;
                    case 'GENERAL':
                        $ventaConcepto = $op->total ?? $op->subtotal ?? 0;
                        break;
                }

                $esImpo = $op instanceof Importacion;
                $tipoApi = $esImpo ? 'impo' : 'expo';

                $atributos = $op->getAttributes();

                $realId = $atributos['id']
                    ?? $atributos['id_importacion']
                    ?? $atributos['id_exportacion']
                    ?? $atributos['id_operacion']
                    ?? null;

                // Si la base de datos no nos entrega un ID, usamos el $index del ciclo
                // Esto garantiza 100% que Vue nunca tendrá "Duplicate Keys"
                if (!$realId) {
                    $realId = 'R' . $index . uniqid();
                }

                $statusFactura = 'sin_facturar';

                try {
                    // Usamos $realId si es que la API consulta usando el ID principal
                    $url = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$realId}/get-files-momentaneo";
                    $response = Http::timeout(3)->get($url);

                    if ($response->successful()) {
                        $archivos = $response->json();
                        if (isset($archivos['HONORARIOS SC'])) {
                            $seccionTexto = strtoupper(json_encode($archivos['HONORARIOS SC']));
                            if (str_contains($seccionTexto, 'PDF') && str_contains($seccionTexto, 'XML')) {
                                $statusFactura = 'facturado';
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignorar error de API silenciosamente
                }

                return [
                    //  Regresamos el prefijo 'ING-' para no romper la edición en el frontend
                    'id'            => 'ING-' . $realId,
                    'tipo_operacion' => $esImpo ? 'IMPO' : 'EXPO',
                    'tipo'          => $concepto,
                    'cliente'       => $op->cliente ? $op->cliente->nombre : 'Sin Cliente',
                    'pedimento'     => is_object($op->pedimento) ? ($op->pedimento->num_pedimiento ?? '--') : ($op->pedimento ?? $op->referencia ?? '--'),
                    'transportista' => !empty($proveedorExtraido) ? $proveedorExtraido : 'SIN ASIGNAR',
                    'fProveedor'    => !empty($facturaExtraida) ? $facturaExtraida : '--',
                    'costo'         => (float) $ventaConcepto,
                    'venta'         => (float) $ventaConcepto,
                    'ganancia'      => 0,
                    'moneda'        => 'MXN',
                    'fInTactics'    => $op->folio_sc ?? '--',
                    'status'        => $statusFactura,
                    'hasAnticipo'   => false,
                ];
            });

            if ($request->filled('proveedor')) {
                $todasLasOperaciones = $todasLasOperaciones->where('transportista', $request->proveedor);
            }

            // Aquí filtramos si en el frontend seleccionaron "Facturado" o "Sin Facturar"
            if ($request->filled('status')) {
                $todasLasOperaciones = $todasLasOperaciones->where('status', $request->status);
            }

            return response()->json([
                'controlFleteData' => $todasLasOperaciones->values(),
                'tableData'        => $todasLasOperaciones->values(),
                'orderData'        => [],
                'paidOrders'       => []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Hubo un error en la BD: ' . $e->getMessage()
            ], 500);
        }
    }
}