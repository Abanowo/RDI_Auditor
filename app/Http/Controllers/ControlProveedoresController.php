<?php

namespace App\Http\Controllers;

use App\Models\IngresoConciliado;
use Illuminate\Http\Request;

class ControlProveedoresController extends Controller
{
    public function index(Request $request)
    {
        // 1. Leemos qué concepto/pestaña está activa y qué tipo de operación buscamos
        $concepto = strtoupper($request->input('concepto', 'GENERAL'));
        $tipoOperacion = strtoupper($request->input('tipo_operacion', 'TODOS'));
        $sucursal = strtoupper($request->input('sucursal', 'TODAS'));
        
        $query = IngresoConciliado::with('cliente');

        if ($tipoOperacion === 'IMPO') {
            $query->where('sucursal_origen', 'LIKE', '%IMPO%');
        } elseif ($tipoOperacion === 'EXPO') {
            $query->where('sucursal_origen', 'LIKE', '%EXPO%');
        }

        if ($sucursal !== 'TODAS') {
            $query->where('sucursal_origen', 'LIKE', "%{$sucursal}%");
        }

        if ($request->filled('pedimento')) {
            $query->where('referencia', 'LIKE', "%{$request->pedimento}%");
        }

        if ($request->filled('fecha')) {
            $query->whereDate('fecha', $request->fecha);
        }

        // 3. Filtramos para que solo traiga operaciones con montos en el concepto seleccionado
        switch ($concepto) {
            case 'MANIOBRAS':
                $query->where('maniobras', '>', 0);
                break;
            case 'MUESTRAS':
                $query->where('muestras', '>', 0);
                break;
            case 'FLETE':
                $query->where('flete', '>', 0);
                break;
            case 'LLC':
                $query->where('llc', '>', 0);
                break;
            case 'ROJOS':
                $query->where('eci', '>', 0);
                break;
            case 'GENERAL':
            default:
                $query->where('monto_deposito', '>', 0);
                break;
        }

        // Traemos las 30 más recientes para agilizar
        $ingresosDB = $query->orderBy('created_at', 'desc')->take(30)->get();

        // 4. Mapeamos la información para el Frontend
        $todasLasOperaciones = $ingresosDB->map(function ($op) use ($concepto) {
            
            $ventaConcepto = 0;
            $proveedorExtraido = '';
            $facturaExtraida = '';

            // Detectamos los datos correctos según la pestaña activa
            switch ($concepto) {
                case 'MANIOBRAS': 
                    $ventaConcepto = $op->maniobras; 
                    $proveedorExtraido = $op->proveedor_maniobras;
                    $facturaExtraida = $op->factura_maniobras;
                    break;
                case 'MUESTRAS':  
                    $ventaConcepto = $op->muestras; 
                    $proveedorExtraido = $op->proveedor_muestras;
                    $facturaExtraida = $op->factura_muestras;
                    break;
                case 'FLETE':     
                    $ventaConcepto = $op->flete; 
                    $proveedorExtraido = $op->proveedor_flete;
                    $facturaExtraida = $op->factura_flete;
                    break;
                case 'LLC':       
                    $ventaConcepto = $op->llc; 
                    $proveedorExtraido = $op->proveedor_llc;
                    $facturaExtraida = $op->factura_llc;
                    break;
                case 'ROJOS':     
                    $ventaConcepto = $op->eci; 
                    break;
                case 'GENERAL':   
                    $ventaConcepto = $op->monto_deposito; 
                    break;
            }

            return [
                'id'            => 'ING-' . $op->id, 
                'tipo'          => $concepto,
                'cliente'       => $op->cliente ? $op->cliente->nombre : 'Desconocido', 
                'pedimento'     => $op->referencia ?? '--',
                'transportista' => !empty($proveedorExtraido) ? $proveedorExtraido : 'SIN ASIGNAR', 
                'fProveedor'    => !empty($facturaExtraida) ? $facturaExtraida : '--', 
                'costo'         => (float) $ventaConcepto, 
                'venta'         => (float) $ventaConcepto,
                'ganancia'      => 0, 
                'moneda'        => 'MXN', 
                'fInTactics'    => $op->folio_sc ?? '--', 
                'status'        => 'sin_facturar', 
                'hasAnticipo'   => false, 
            ];
        });

        // 5. Filtros aplicados sobre la colección resultante
        if ($request->filled('proveedor')) {
            $todasLasOperaciones = $todasLasOperaciones->where('transportista', $request->proveedor);
        }

        return response()->json([
            'controlFleteData' => $todasLasOperaciones->values(),
            'tableData'        => $todasLasOperaciones->values(),
            'orderData'        => [],
            'paidOrders'       => []
        ]);
    }
}