<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IngresoConciliado;
use App\Models\Empresas;
use App\Models\Sucursales;
use App\Models\SaldoFavor;
use App\Models\ComplementoPago;
use App\Models\User;
use App\Mail\NotificacionSaldoFavorMail;
use App\Mail\ComplementoPagoMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class IngresoConciliadoController extends Controller
{
    // =====================================================================
    // MÉTODOS PARA: INGRESOS CONCILIADOS
    // =====================================================================
    // 1. Enviar los catálogos al frontend (Modal y Filtros)
    public function opciones()
    {
        $clientes = Empresas::select('id', 'nombre')->orderBy('nombre', 'asc')->get();

        $sucursalesDb = Sucursales::whereIn('id', [1, 2, 3, 4, 5, 11, 12])
            ->orderBy('id', 'asc')
            ->get();

        $sucursales = [];
        $sucursalesBase = [];

        foreach ($sucursalesDb as $sucursal) {
            $nombreRaw = $sucursal->nombre;
            $sucursalesBase[] = mb_convert_case(strtolower($nombreRaw), MB_CASE_TITLE, "UTF-8");

            if (str_contains(strtoupper($nombreRaw), 'MANZANILLO')) {
                $sucursales[] = 'MANZANILLO';
                continue;
            }
            $nombreBase = strtoupper($nombreRaw);
            $sucursales[] = $nombreBase . ' IMPO';
            $sucursales[] = $nombreBase . ' EXPO';
        }

        $bancos = [
            'BBVA REC NOG 2749',
            'BBVA REC LRD 1199',
            'BBVA REC TIJ 1465',
            'BBVA REC MXL 2461',
            'BBVA REC ZLO 9378',
            'BBVA REC VER 6464',
            'BBVA REC REY 3802',
            'Santander Rec Nog 1306',
            'Santander Rec Zlo ',
            'Santander Rec Ver',
            'Santander DLLS Nog 2628',
            'Santander Dlls ZLO 6205',
            'BBVA Rec Transpo 1903',
            'BBVA Rec Intshipperts 1586',
            'Santander Rec Transpo 1347'
        ];

        $foliosFactura = IngresoConciliado::whereNotNull('folio_sc')
            ->pluck('folio_sc')
            ->unique()
            ->values()
            ->toArray();

        $foliosComplemento = ComplementoPago::whereNotNull('folio')
            ->pluck('folio')
            ->unique()
            ->values()
            ->toArray();

        return response()->json([
            'clientes'       => $clientes,
            'sucursales'     => $sucursales,
            'sucursalesBase' => $sucursalesBase,
            'bancos'         => $bancos,
            'foliosFactura' => $foliosFactura,
            'foliosComplemento' => $foliosComplemento
        ]);
    }

    // 2. Traer los datos para mostrarlos en la tabla principal de Vue
    public function index(Request $request)
    {
        $query = IngresoConciliado::with(['operaciones'])
            ->select(
                'ingresos_conciliados.*',
                // Toma empresas.nombre, si es nulo, toma ingresos_conciliados.cliente
                DB::raw('COALESCE(empresas.nombre, ingresos_conciliados.cliente) as cliente')
            )
            ->leftJoin('empresas', 'ingresos_conciliados.cliente_id', '=', 'empresas.id')
            ->addSelect([
                'folio_complemento' => ComplementoPago::select('folio')
                    ->whereColumn('ingreso_conciliado_id', 'ingresos_conciliados.id')
                    ->orderBy('created_at', 'desc')
                    ->limit(1)
            ]);

        // ==========================================
        // 2. APLICAR FILTROS (Enviados desde Vue)
        // ==========================================

        if ($request->filled('sucursal') && $request->sucursal !== 'Todas') {
            $query->where('ingresos_conciliados.sucursal_origen', 'LIKE', '%' . $request->sucursal . '%');
        }

        if ($request->filled('cliente') && $request->cliente !== 'Todos') {
            $query->where('empresas.nombre', $request->cliente);
        }

        if ($request->filled('tipo_operacion') && $request->tipo_operacion !== 'Ambos') {
            $query->where('ingresos_conciliados.sucursal_origen', 'LIKE', '%' . $request->tipo_operacion . '%');
        }

        if ($request->filled('tipo_comprobante') && $request->tipo_comprobante !== 'Todos') {
            $query->where('ingresos_conciliados.tipo_comprobante', $request->tipo_comprobante);
        }

        if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
            $query->whereBetween('ingresos_conciliados.fecha', [$request->fecha_inicio, $request->fecha_fin]);
        }

        // Filtro por Folio SC / Factura
        if ($request->filled('folio_factura') && $request->folio_factura !== 'Todos') {
            $query->where('ingresos_conciliados.folio_sc', 'LIKE', '%' . $request->folio_factura . '%');
        }

        // Filtro por Folio Complemento
        if ($request->filled('folio_complemento') && $request->folio_complemento !== 'Todos') {
            $query->whereIn('ingresos_conciliados.id', function ($subquery) use ($request) {
                $subquery->select('ingreso_conciliado_id')
                    ->from('complemento_pago')
                    ->where('folio', 'LIKE', '%' . $request->folio_complemento . '%');
            });
        }

        if ($request->filled('estado_envio')) {
            $estadoEnvio = strtoupper($request->estado_envio);

            if ($estadoEnvio === 'ENVIADO') {
                $query->where('ingresos_conciliados.estado_envio', 'ENVIADO');
            } elseif ($estadoEnvio === 'PENDIENTE') {
                $query->where(function ($q) {
                    $q->where('ingresos_conciliados.estado_envio', 'PENDIENTE')
                        ->orWhereNull('ingresos_conciliados.estado_envio')
                        ->orWhere('ingresos_conciliados.estado_envio', '');
                });
            }
        }

        if ($request->filled('tipo_servicio') && $request->tipo_servicio !== 'Todos') {
            $servicio = $request->tipo_servicio;

            if ($servicio === 'INTSHIPPERTS') {
                $query->where(function ($q) {
                    $q->where('empresas.nombre', 'LIKE', '%INTSHIPPERTS%')
                        ->orWhere('ingresos_conciliados.sucursal_origen', 'LIKE', '%INTSHIPPERT%');
                });
            } elseif ($servicio === 'Transportactics') {
                $query->where(function ($q) {
                    $q->where('empresas.nombre', 'LIKE', '%TRANSPORTACTICS%')
                        ->orWhere('ingresos_conciliados.sucursal_origen', 'LIKE', '%TRANSPORTACTIC%');
                });
            } elseif ($servicio === 'InTactics') {
                $query->where(function ($q) {
                    $q->where('empresas.nombre', 'NOT LIKE', '%INTSHIPPERTS%')
                        ->where('ingresos_conciliados.sucursal_origen', 'NOT LIKE', '%INTSHIPPERT%')
                        ->where('empresas.nombre', 'NOT LIKE', '%TRANSPORTACTICS%')
                        ->where('ingresos_conciliados.sucursal_origen', 'NOT LIKE', '%TRANSPORTACTIC%');
                });
            }
        }

        // ==========================================
        // 3. CÁLCULO DE KPIs (Ingresos Totales, etc.)
        // ==========================================

        $kpis = [
            'depositos'  => (clone $query)->sum('ingresos_conciliados.monto_deposito'),
            'honorarios' => (clone $query)->sum('ingresos_conciliados.honorarios'),

            'notaCargo'  => (clone $query)->sum(DB::raw("
                CASE 
                    WHEN ingresos_conciliados.sucursal_origen LIKE '%MANZANILLO%' OR ingresos_conciliados.sucursal_origen LIKE '%INTSHIPPERT%' 
                    THEN (COALESCE(ingresos_conciliados.impuestos, 0) + COALESCE(ingresos_conciliados.flete, 0) + COALESCE(ingresos_conciliados.anticipo, 0) + COALESCE(ingresos_conciliados.garantias, 0) + COALESCE(ingresos_conciliados.desglose_naviera, 0))
                    
                    ELSE (COALESCE(ingresos_conciliados.impuestos, 0) + COALESCE(ingresos_conciliados.eci, 0) + COALESCE(ingresos_conciliados.maniobras, 0) + COALESCE(ingresos_conciliados.flete, 0) + COALESCE(ingresos_conciliados.muestras, 0) + COALESCE(ingresos_conciliados.llc, 0))
                END
            "))
        ];

        // ==========================================
        // 4. PAGINACIÓN Y RETORNO
        // ==========================================
        $perPage = $request->input('per_page', 10);
        $resultados = $query->orderBy('ingresos_conciliados.created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data'  => $resultados->items(),
            'total' => $resultados->total(),
            'kpis'  => $kpis
        ]);
    }

    public function listarPedimentosSheet(Request $request)
    {
        $sucursal = strtoupper(trim($request->input('sucursal', '')));
        $esTransportactics = filter_var($request->input('es_transportactics', false), FILTER_VALIDATE_BOOLEAN);
        $esIntshipperts = filter_var($request->input('es_intshipperts', false), FILTER_VALIDATE_BOOLEAN);

        if (empty($sucursal)) {
            return response()->json([]);
        }

        $esManzanillo = str_contains($sucursal, 'MANZANILLO') || str_contains($sucursal, 'INTSHIPPERT');

        if ($esManzanillo) {
            $sheetId = app()->environment('production') ? '18-5okzV-vw35V0Ugjn5KjNcWgHyZ9Qfc6pf5w4VU-2I' : '1zHUYpViLZyu_KPkNCUEx37WjoK0lVt7F0bC1B9Jo8s0';
            $nombrePestanaCodificado = 'ZLO';
        } else {
            $sheetId = app()->environment('production') ? '1f_I9miUfQb5Xl379DNP1fciHUEYAU6MrM832Z6QqSi0' : '17hBoRx5u5jxi2hqHiC98zX-3lqF0w5tTkHBzElCHwDM';
            $nombrePestanaCodificado = rawurlencode($sucursal);
        }

        $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&sheet={$nombrePestanaCodificado}";

        try {
            $response = Http::withoutVerifying()->timeout(30)->get($csvUrl);

            if (!$response->successful()) {
                return response()->json(['error' => 'Error al conectar con Google Sheets'], 500);
            }

            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $response->body());
            rewind($stream);

            $rows = [];
            while (($cols = fgetcsv($stream)) !== false) {
                if (empty(trim(implode('', $cols)))) {
                    continue;
                }
                $rows[] = $cols;
            }
            fclose($stream);

            $pedimentos = [];

            $idxCliente = 1;
            $idxPedimento = 2;
            $idxProveedor = 4;
            $idxFacturaP = 5;
            $idxMonto = 6;
            $idxFacturaSC = 8;

            $encontroPedimento = false;

            foreach ($rows as $cols) {
                foreach ($cols as $i => $col) {
                    $txt = strtolower(trim($col));

                    if (str_contains($txt, 'cliente')) {
                        $idxCliente = $i;
                    }
                    if (!$encontroPedimento && str_contains($txt, 'pedimento') && !str_contains($txt, 'folio')) {
                        $idxPedimento = $i;
                        $encontroPedimento = true;
                    }
                    if (str_contains($txt, 'factura sc') || str_contains($txt, 'folio sc')) {
                        $idxFacturaSC = $i;
                    }
                    if (str_contains($txt, 'proveedor')) {
                        $idxProveedor = $i;
                    }
                    if (str_contains($txt, 'factura p') || str_contains($txt, 'factura prov')) {
                        $idxFacturaP = $i;
                    }
                    if (str_contains($txt, 'monto') && !str_contains($txt, 'llc')) {
                        $idxMonto = $i;
                    }
                }
                break;
            }

            $ultimoCliente = '';
            $ultimoPedimento = '';
            $ultimaFacturaSC = '';

            foreach ($rows as $index => $cols) {
                if ($index === 0) {
                    continue;
                }

                // 1. Limpiamos espacios y saltos de línea ocultos
                $clienteCeldaOriginal = trim(preg_replace('/\s+/', ' ', $cols[$idxCliente] ?? ''));
                $pedimentoCeldaOriginal = trim(preg_replace('/\s+/', ' ', $cols[$idxPedimento] ?? ''));
                $facturaCeldaOriginal = trim(preg_replace('/\s+/', ' ', $cols[$idxFacturaSC] ?? ''));

                $facturaPCeldaTemporal = trim(preg_replace('/\s+/', ' ', $cols[$idxFacturaP] ?? ''));
                $proveedorCeldaTemporal = strtoupper(trim($cols[$idxProveedor] ?? ''));

                // Solo rescatamos si el proveedor es "N/A" o vacío, o el número es de 7+ dígitos
                if ($pedimentoCeldaOriginal === '' && is_numeric($facturaPCeldaTemporal)) {
                    if ($proveedorCeldaTemporal === 'N/A' || $proveedorCeldaTemporal === '' || strlen($facturaPCeldaTemporal) >= 7) {
                        $pedimentoCeldaOriginal = $facturaPCeldaTemporal;
                    }
                }

                // 3. REGLA DE ORO: Si aún hay diagonales, tomamos únicamente el primero
                if (str_contains($pedimentoCeldaOriginal, '/')) {
                    $pedimentoCeldaOriginal = trim(explode('/', $pedimentoCeldaOriginal)[0]);
                }

                // Lógica de herencia (si las celdas de abajo vienen en blanco, heredan las de arriba)
                if ($clienteCeldaOriginal !== '') {
                    if ($clienteCeldaOriginal !== $ultimoCliente) {
                        $ultimoPedimento = '';
                        $ultimaFacturaSC = '';
                    }
                    $ultimoCliente = $clienteCeldaOriginal;
                }

                if ($pedimentoCeldaOriginal !== '') {
                    $ultimoPedimento = $pedimentoCeldaOriginal;
                }

                if ($facturaCeldaOriginal !== '') {
                    $ultimaFacturaSC = $facturaCeldaOriginal;
                }

                $clienteCelda = $ultimoCliente;
                $pedimentoCelda = $ultimoPedimento;
                $facturaCelda = $ultimaFacturaSC;

                $proveedorCelda = strtoupper(trim($cols[$idxProveedor] ?? ''));
                $facturaPCelda = trim($cols[$idxFacturaP] ?? '');
                $montoCelda = (float) filter_var(trim($cols[$idxMonto] ?? '0'), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                if ($pedimentoCelda !== '' && $clienteCelda !== '') {
                    if (strtoupper($clienteCelda) === 'CLIENTE' || strtoupper($pedimentoCelda) === 'PEDIMENTO') {
                        continue;
                    }

                    if ($esTransportactics) {
                        if (!str_contains($proveedorCelda, 'TRANSPORTACTICS') || $montoCelda <= 0) {
                            continue;
                        }
                    }

                    $folioIntshipperts = '';
                    if (preg_match('/(ZLOI\s*[0-9]+)/i', $facturaCelda, $matches)) {
                        $folioIntshipperts = strtoupper(str_replace(' ', '', $matches[1]));
                    } elseif (preg_match('/(ZLOI\s*[0-9]+)/i', $pedimentoCelda, $matches)) {
                        $folioIntshipperts = strtoupper(str_replace(' ', '', $matches[1]));
                    }

                    if ($esIntshipperts && $folioIntshipperts === '') {
                        continue;
                    }

                    // 4. Armado de la etiqueta final para Vue
                    $etiqueta = '';

                    if ($esTransportactics && $facturaPCelda !== '') {
                        $etiqueta .= 'TR: ' . $facturaPCelda . ' - ';
                    } elseif ($esIntshipperts && $folioIntshipperts !== '') {
                        $etiqueta .= 'INT: ' . $folioIntshipperts . ' - ';
                    }

                    if ($facturaCelda !== '' && !str_contains($facturaCelda, '$')) {
                        $etiqueta .= $facturaCelda . ' - ';
                    }

                    $etiqueta .= $pedimentoCelda . ' - ' . $clienteCelda;

                    $pedimentos[] = [
                        'label' => $etiqueta,
                        'folio' => $etiqueta,
                        'cliente' => strtoupper($clienteCelda)
                    ];
                }
            }

            // Quitamos duplicados y reindexamos
            $pedimentos = collect($pedimentos)->unique('label')->values()->all();

            return response()->json($pedimentos);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Excepción: ' . $e->getMessage()], 500);
        }
    }
    public function buscarEnSheet(Request $request)
    {
        $terminosCrudos = $request->input('pedimentos', []);
        $sucursalBuscada = strtoupper(trim($request->input('sucursal')));
        $tiposComprobante = $request->input('tipo_comprobante', []);

        $terminosBuscados = [];
        foreach ($terminosCrudos as $term) {
            $termLimpio = str_replace('TR: ', '', $term);

            if (str_contains($termLimpio, ' - ')) {
                $partes = explode(' - ', $termLimpio);

                foreach ($partes as $parte) {
                    $parteLimpia = trim($parte);
                    if (preg_match('/[0-9]/', $parteLimpia)) {
                        $terminosBuscados[] = $parteLimpia;
                    }
                }
            } else {
                // Si lo escribieron a mano sin guiones
                $terminosBuscados[] = trim($termLimpio);
            }
        }

        // Limpiamos vacíos y duplicados
        $terminosBuscados = array_unique(array_filter($terminosBuscados));

        if (empty($terminosBuscados) || empty($sucursalBuscada)) {
            return response()->json(['error' => 'Faltan datos para buscar.'], 400);
        }

        // Si detecta Transportactics, cancela el Excel y se va a la API
        if (str_contains($sucursalBuscada, 'TRANSPORTACTICS')) {
            return $this->procesarIngresoTransportactics($terminosBuscados);
        }

        // Si detecta Intshipperts, se va a su lógica especial (API + hoja ALMAN)
        if (str_contains($sucursalBuscada, 'INTSHIPPERTS')) {
            return $this->procesarIngresoIntshipperts($terminosBuscados);
        }

        $quiereCFDI = in_array('CFDI', $tiposComprobante);
        $quiereNotaCargo = in_array('Nota Cargo', $tiposComprobante);

        $esManzanillo = str_contains($sucursalBuscada, 'MANZANILLO');
        $isTransportacticsGlobal = str_contains($sucursalBuscada, 'TRANSPORTACTICS');

        if ($esManzanillo) {
            $spreadsheetId = app()->environment('production') ? '18-5okzV-vw35V0Ugjn5KjNcWgHyZ9Qfc6pf5w4VU-2I' : '1zHUYpViLZyu_KPkNCUEx37WjoK0lVt7F0bC1B9Jo8s0';
            $nombrePestanaCodificado = 'ZLO';
        } else {
            $spreadsheetId = app()->environment('production') ? '1f_I9miUfQb5Xl379DNP1fciHUEYAU6MrM832Z6QqSi0' : '17hBoRx5u5jxi2hqHiC98zX-3lqF0w5tTkHBzElCHwDM';
            $nombrePestanaCodificado = rawurlencode($sucursalBuscada);
        }

        $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&sheet={$nombrePestanaCodificado}";

        try {
            $response = Http::withoutVerifying()->timeout(30)->get($csvUrl);
            if (!$response->successful()) {
                return response()->json(['error' => 'Google Sheets rechazó la conexión.'], 500);
            }

            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $response->body());
            rewind($stream);

            if ($esManzanillo) {
                $resultados = [
                    'honorarios' => 0,
                    'impuestos' => 0,
                    'eci' => 0,
                    'maniobras' => 0,
                    'flete' => 0,
                    'muestras' => 0,
                    'llc' => 0,
                    'anticipo' => 0,
                    'garantias' => 0,
                    'desglose_naviera' => 0,
                    'proveedor_maniobras' => null,
                    'factura_maniobras' => null,
                    'proveedor_flete' => null,
                    'factura_flete' => null,
                    'proveedor_muestras' => null,
                    'factura_muestras' => null,
                    'proveedor_llc' => null,
                    'factura_llc' => null,
                    'folio_sc' => [],
                    'operacion_id' => null,
                    'operation_type' => null,
                    'pedimento_detectado' => [],
                    'cliente_detectado' => null,
                    'operaciones' => [],
                    'metodo_pago' => 'PUE'
                ];

                $ultimoCliente = '';
                $ultimoPedimento = '';
                $pedimentosDetectados = [];
                $encontroAlgo = false;

                $excelHonorarios = 0;
                $excelImpuestos = 0;
                $excelAnticipo = 0;

                while (($fila = fgetcsv($stream)) !== false) {
                    if (count($fila) < 6) {
                        continue;
                    }

                    $clienteFila = trim($fila[1] ?? '');
                    $pedimentoFila = trim($fila[2] ?? '');

                    if (strtoupper($clienteFila) === 'CLIENTE' || strtoupper($pedimentoFila) === 'PEDIMENTO') {
                        continue;
                    }

                    if (!empty($clienteFila)) {
                        $ultimoCliente = $clienteFila;
                    }
                    if (!empty($pedimentoFila)) {
                        $ultimoPedimento = preg_replace('/\s+/', ' ', $pedimentoFila);
                    }

                    if (empty($ultimoPedimento)) {
                        continue;
                    }

                    $coincide = false;
                    foreach ($terminosBuscados as $termino) {
                        $termino = strtoupper(trim($termino));
                        if ($termino !== '' && (str_contains(strtoupper($ultimoPedimento), $termino) || str_contains($termino, strtoupper($ultimoPedimento)))) {
                            $coincide = true;
                            break;
                        }
                    }

                    if ($coincide) {
                        $encontroAlgo = true;
                        $resultados['cliente_detectado'] = $ultimoCliente;

                        if (!in_array($ultimoPedimento, $pedimentosDetectados)) {
                            $pedimentosDetectados[] = $ultimoPedimento;
                        }

                        $concepto = strtoupper(trim($fila[7] ?? ''));
                        $montoStr = trim($fila[5] ?? '0');
                        $monto = (float) filter_var(str_replace(['$', ','], '', $montoStr), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                        $clienteUpper = strtoupper($ultimoCliente);
                        $esAlmacenadoras = str_contains($clienteUpper, 'ALMACENADORA');

                        if ($quiereNotaCargo) {
                            if (str_contains($concepto, 'IMPUEST')) {
                                $excelImpuestos += $monto;
                            } elseif (str_contains($concepto, 'ANTICIPO') && !$esAlmacenadoras) {
                                // Solo acumula anticipo si NO es Almacenadoras
                                $excelAnticipo += $monto;
                            }
                        }

                        if ($quiereCFDI || $quiereNotaCargo) {
                            if (str_contains($concepto, 'HONORARIO') || str_contains($concepto, 'COMISION') || str_contains($concepto, 'COMISIÓN')) {
                                $excelHonorarios += $monto;
                            }
                        }
                    }
                }
                fclose($stream);

                $pedimentosParaBuscar = array_unique(array_merge($terminosBuscados, $pedimentosDetectados));
                $terminosParaTransito = $terminosBuscados;

                $apiImpuestos = 0;
                $apiAnticipo = 0;
                $apiGarantias = 0;
                $apiNaviera = 0;
                $apiHonorarios = 0;

                foreach ($pedimentosParaBuscar as $pedimentoReal) {

                    $partes = explode(' ', $pedimentoReal);
                    $pedimentoCompleto = trim($partes[0] ?? $pedimentoReal);
                    $pedimentoBusqueda = str_contains($pedimentoCompleto, '-') ? explode('-', $pedimentoCompleto)[1] : $pedimentoCompleto;

                    $terminosParaTransito[] = $pedimentoBusqueda;
                    $terminosParaTransito[] = $pedimentoCompleto;

                    $folioFacturaZlo = '';
                    if (count($partes) > 1) {
                        $folioFacturaZlo = preg_replace('/[^0-9]/', '', $partes[1]);
                        if (!empty($folioFacturaZlo)) {
                            $resultados['folio_sc'][] = $folioFacturaZlo;
                        }
                    }

                    $pedimentoDB = DB::table('pedimiento')
                        ->where('num_pedimiento', 'LIKE', "%{$pedimentoBusqueda}%")
                        ->orderBy('id_pedimiento', 'desc')
                        ->first();

                    if ($pedimentoDB) {
                        $encontroAlgo = true;

                        if (!in_array($pedimentoReal, $resultados['pedimento_detectado'])) {
                            $resultados['pedimento_detectado'][] = $pedimentoReal;
                        }

                        $impo = DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
                        $expo = DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

                        $idOp = null;
                        $tipoApi = null;
                        $idPadre = null;
                        $opType = null;

                        $op_cfdi = 0;
                        $op_gpc = 0;
                        $op_honorariosXML = 0;

                        if ($impo) {
                            $idOp = $impo->id_importacion;
                            $tipoApi = 'importaciones';
                            $idPadre = $impo->parent ?? null;
                            $opType = 'App\Models\OperacionImportacion';
                        } elseif ($expo) {
                            $idOp = $expo->id_exportacion;
                            $tipoApi = 'exportaciones';
                            $idPadre = $expo->parent ?? null;
                            $opType = 'App\Models\OperacionExportacion';
                        }

                        if ($idOp) {
                            $impuestosPref = 0;
                            $garantiasPref = 0;
                            $navieraPref = 0;
                            $anticipoPref = 0;

                            if ($quiereNotaCargo) {
                                $urlPrefactura = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/prefacturas-momentaneo";
                                $respPrefactura = Http::withoutVerifying()->timeout(10)->get($urlPrefactura);

                                if ($respPrefactura->successful() && is_array($respPrefactura->json())) {

                                    $anticipoAcumulado = 0;
                                    $totalPrefactura = 0;
                                    $clientePrefactura = '';

                                    $todasLasDescripciones = '';

                                    $folioPref = $prefactura['encabezado']['folio'] ?? $prefactura['encabezado']['factura'] ?? $prefactura['encabezado']['serie_folio'] ?? '';
                                    if (!empty($folioPref)) {
                                        $resultados['folio_sc'][] = preg_replace('/[^0-9]/', '', $folioPref);
                                    }

                                    foreach ($respPrefactura->json() as $prefactura) {
                                        $clientePrefactura = strtoupper($prefactura['encabezado']['cliente'] ?? $clientePrefactura);
                                        $totalPrefactura += (float) ($prefactura['totales']['total'] ?? 0);

                                        foreach ($prefactura['secciones'] ?? [] as $seccion) {
                                            $key = strtolower($seccion['key'] ?? '');
                                            $titulo = strtoupper($seccion['titulo'] ?? '');
                                            $totalSeccion = floatval($seccion['total'] ?? 0);

                                            $garantiasEnEstaSeccion = 0;

                                            if (isset($seccion['items']) && is_array($seccion['items'])) {
                                                foreach ($seccion['items'] as $item) {
                                                    $desc = strtoupper($item['descripcion'] ?? '');
                                                    $pesos = floatval($item['pesos'] ?? 0);

                                                    // Guardamos el texto para la "Lupa de Navieras"
                                                    $todasLasDescripciones .= $desc . ' ';

                                                    if ((str_contains($desc, 'GARANTIA') || str_contains($desc, 'GARANTÍA')) && !str_contains($desc, 'RECUPERACION')) {
                                                        $garantiasEnEstaSeccion += $pesos;
                                                    }
                                                }
                                            }

                                            $garantiasPref += $garantiasEnEstaSeccion;

                                            $totalEfectivo = $totalSeccion - $garantiasEnEstaSeccion;
                                            if ($totalEfectivo < 0) {
                                                $totalEfectivo = 0;
                                            }

                                            $clienteEvaluar = strtoupper(!empty($clientePrefactura) ? $clientePrefactura : ($resultados['cliente_detectado'] ?? ''));
                                            $esAlmacenadoras = str_contains($clienteEvaluar, 'ALMACENADORA');

                                            if ($key === 'impuestos' || str_contains($titulo, 'IMPUEST')) {
                                                $impuestosPref += $totalEfectivo;
                                            } elseif ($key === 'muestra' || str_contains($titulo, 'MUESTRA')) {
                                                $navieraPref += $totalEfectivo;
                                            } elseif ($key === 'naviera' || str_contains($titulo, 'NAVIERA') || $key === 'desglose_naviera') {
                                                $navieraPref += $totalEfectivo;
                                            } elseif ($key === 'honorarios' || str_contains($titulo, 'HONORARIO')) {
                                                // Nada
                                            } else {
                                                // Si no es ninguno de los anteriores y NO es Almacenadoras, se toma como anticipo
                                                if (!$esAlmacenadoras) {
                                                    $anticipoPref += $totalEfectivo;
                                                }
                                            }
                                        }
                                    }

                                    $clienteEvaluar = strtoupper(!empty($clientePrefactura) ? $clientePrefactura : ($resultados['cliente_detectado'] ?? ''));

                                    if (str_contains($clienteEvaluar, 'G Y S') || str_contains($clienteEvaluar, 'GYS')) {
                                        $mod = fmod($totalPrefactura, 100);
                                        $ajusteRedondeo = ($mod < 10) ? -$mod : (100 - $mod);
                                        $totalPrefactura += $ajusteRedondeo;
                                        $anticipoAcumulado += $ajusteRedondeo;
                                    }

                                    if ($anticipoAcumulado > 0) {
                                        $anticipoPref = $anticipoAcumulado;
                                    }

                                    $esClienteTransito = str_contains($clienteEvaluar, 'COPSAYS') || str_contains($clienteEvaluar, 'ACUMEN') || str_contains($clienteEvaluar, 'MAYOUT');

                                    if ($esClienteTransito) {

                                        $estaEnTransito = method_exists($this, 'existeEnTransito') && $this->existeEnTransito($terminosParaTransito);
                                        if ($estaEnTransito) {
                                            // 1. Corregir el error de la API (Mueve el dinero de Impuestos al Anticipo)
                                            if ($impuestosPref > 0) {
                                                $anticipoPref += $impuestosPref;
                                                $impuestosPref = 0;
                                            }

                                            // 2. Garantía Fija basada en Naviera
                                            $textoNaviera = (isset($impo) && !empty($impo->viaje) ? strtoupper($impo->viaje) : '') . ' ' . $todasLasDescripciones;

                                            $tc = 18.50;
                                            $nuevaGarantia = null;

                                            // Formato exacto solicitado:
                                            if (str_contains($textoNaviera, 'CMA')) {
                                                $nuevaGarantia = 66000;
                                            } elseif (str_contains($textoNaviera, 'COSCO')) {
                                                $nuevaGarantia = 25000;
                                            } elseif (str_contains($textoNaviera, 'EVERGREEN')) {
                                                $nuevaGarantia = 21000;
                                            } elseif (str_contains($textoNaviera, 'HAPAG')) {
                                                $nuevaGarantia = 25000;
                                            } elseif (str_contains($textoNaviera, 'MAERSK')) {
                                                $nuevaGarantia = 1000 * $tc;
                                            } elseif (str_contains($textoNaviera, 'MSC')) {
                                                $nuevaGarantia = 2000 * $tc;
                                            } elseif (str_contains($textoNaviera, 'NORTON')) {
                                                $nuevaGarantia = 1000 * $tc;
                                            } elseif (str_contains($textoNaviera, 'ONE')) {
                                                $nuevaGarantia = 1000 * $tc;
                                            } elseif (str_contains($textoNaviera, 'PIL')) {
                                                $nuevaGarantia = 1000 * $tc;
                                            } elseif (str_contains($textoNaviera, 'WAN')) {
                                                $nuevaGarantia = 30000;
                                            } elseif (str_contains($textoNaviera, 'ALTAMARITIMA')) {
                                                $nuevaGarantia = 50000;
                                            }

                                            // Sobrescribimos el valor de la Garantía
                                            if ($nuevaGarantia !== null) {
                                                $garantiasPref = $nuevaGarantia;
                                            }
                                        }
                                    }

                                    // Sumatoria final para enviar al frontend
                                    $apiImpuestos += $impuestosPref;
                                    $apiGarantias += $garantiasPref;
                                    $apiNaviera += $navieraPref;
                                    $apiAnticipo += $anticipoPref;
                                }
                            }

                            if ($quiereCFDI) {
                                $archivos = [];
                                $urlApi = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/get-files-momentaneo";
                                $respOp = Http::withoutVerifying()->timeout(10)->get($urlApi);
                                if ($respOp->successful() && is_array($respOp->json())) {
                                    $archivos = array_merge($archivos, $respOp->json());
                                }

                                if (!empty($idPadre)) {
                                    $urlApiPadre = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idPadre}/get-files-momentaneo";
                                    $respPadre = Http::withoutVerifying()->timeout(10)->get($urlApiPadre);
                                    if ($respPadre->successful() && is_array($respPadre->json())) {
                                        $archivos = array_merge($archivos, $respPadre->json());
                                    }
                                }

                                if (!empty($archivos)) {
                                    $urlXmlReal = null;
                                    foreach ($archivos as $archivo) {
                                        $ext = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));
                                        $tipoPivot = strtolower($archivo['pivot']['type'] ?? '');
                                        $nombreMayus = strtoupper($archivo['name'] ?? '');

                                        $esTipoSC = in_array($tipoPivot, ['sc', 'honorarios-sc']);
                                        $esNombreSC = !empty($folioFacturaZlo)
                                            ? (str_contains($nombreMayus, 'ZLO' . $folioFacturaZlo) || str_contains($nombreMayus, $folioFacturaZlo))
                                            : (str_contains($nombreMayus, $pedimentoBusqueda) || (str_contains($nombreMayus, 'ZLO') && !str_contains($nombreMayus, 'PEDIMENTO')));

                                        if ($ext === 'xml' && ($esTipoSC || $esNombreSC)) {
                                            $urlXmlReal = $archivo['url']['normal'] ?? null;
                                            break;
                                        }
                                    }

                                    if ($urlXmlReal && method_exists($this, 'extraerHonorariosAgenciaXML')) {
                                        $resultadoXML = $this->extraerHonorariosAgenciaXML($urlXmlReal);
                                        if ($resultadoXML['honorarios'] > 0) {
                                            $op_honorariosXML = $resultadoXML['honorarios'];
                                            $apiHonorarios += $op_honorariosXML;
                                        }
                                        if (!empty($resultadoXML['folio'])) {
                                            $resultados['folio_sc'][] = $resultadoXML['folio'];
                                        }
                                        if (!empty($resultadoXML['metodo_pago']) && strtoupper($resultadoXML['metodo_pago']) === 'PPD') {
                                            $resultados['metodo_pago'] = 'PPD';
                                        }
                                    }
                                }
                            }

                            $op_gpc = $anticipoPref + $garantiasPref + $navieraPref + $impuestosPref;
                            $op_cfdi = $op_honorariosXML;

                            $folioLimpio = '';
                            if (!empty($folioPref)) {
                                $folioLimpio = preg_replace('/[^0-9]/', '', $folioPref);
                            }

                            if (empty($folioLimpio) && !empty($folioFacturaZlo)) {
                                $folioLimpio = $folioFacturaZlo;
                            }

                            $resultados['operaciones'][] = [
                                'id'         => $idOp,
                                'type'       => $opType,
                                'folio'      => $folioLimpio,
                                'monto_cfdi' => round($op_cfdi, 2),
                                'monto_gpc'  => round($op_gpc, 2)
                            ];
                        }
                    }
                }

                if (!$encontroAlgo) {
                    return response()->json(['error' => 'No se encontró información en el Google Sheet ni en el Sistema Intactics.'], 404);
                }

                $resultados['impuestos'] = $apiImpuestos > 0 ? $apiImpuestos : $excelImpuestos;
                $resultados['anticipo']  = $apiAnticipo > 0 ? $apiAnticipo : $excelAnticipo;
                $resultados['honorarios'] = $apiHonorarios > 0 ? $apiHonorarios : $excelHonorarios;

                $resultados['garantias'] = $apiGarantias;
                $resultados['desglose_naviera'] = $apiNaviera;

                $nombreClienteFinal = strtoupper($resultados['cliente_detectado'] ?? '');

                if (str_contains($nombreClienteFinal, 'ALMACENADORA')) {
                    $apiAnticipo = 0;
                    $excelAnticipo = 0;
                    $resultados['anticipo'] = 0;
                }

                if (str_contains($nombreClienteFinal, 'COPSAYS') || str_contains($nombreClienteFinal, 'ACUMEN') || str_contains($nombreClienteFinal, 'MAYOUT')) {
                    if ($resultados['impuestos'] > 0) {
                        $resultados['anticipo'] += $resultados['impuestos'];
                        $resultados['impuestos'] = 0;
                    }
                }

                if ($quiereNotaCargo && method_exists($this, 'extraerFleteAlman')) {
                    $fleteAlman = $this->extraerFleteAlman($terminosParaTransito);
                    if ($fleteAlman > 0) {
                        $resultados['flete'] += $fleteAlman;
                    }
                }

                $montoGarantias = floatval($resultados['garantias'] ?? 0);
                $montoNaviera = floatval($resultados['desglose_naviera'] ?? 0);
                $montoImpuestos = floatval($resultados['impuestos'] ?? 0);

                if ($montoGarantias > 0 || $montoNaviera > 0 || $montoImpuestos > 0) {
                    $resultados['flete'] = 0;
                }

                $resultados['operaciones'] = collect($resultados['operaciones'])->unique('id')->values()->all();
                $resultados['folio_sc'] = implode(', ', array_unique((array) $resultados['folio_sc']));
                $resultados['pedimento_detectado'] = implode(', ', array_unique((array) $resultados['pedimento_detectado']));

                $llavesNumericas = ['honorarios', 'impuestos', 'eci', 'maniobras', 'flete', 'muestras', 'llc', 'anticipo', 'garantias', 'desglose_naviera'];
                foreach ($llavesNumericas as $key) {
                    if (isset($resultados[$key])) {
                        $resultados[$key] = round($resultados[$key], 2);
                    }
                }

                return response()->json($resultados);
            }

            // ==============================================================
            // LÓGICA PARA SUCURSALES DISTINTAS A MANZANILLO
            // ==============================================================
            $bloques = [];
            $bloqueActual = [];

            while (($fila = fgetcsv($stream)) !== false) {
                if (count($fila) < 4) {
                    continue;
                }
                $concepto = strtoupper(trim($fila[3] ?? ''));
                if (str_contains($concepto, 'IMPUEST') && !empty($bloqueActual)) {
                    $bloques[] = $bloqueActual;
                    $bloqueActual = [];
                }
                $bloqueActual[] = $fila;
            }
            if (!empty($bloqueActual)) {
                $bloques[] = $bloqueActual;
            }
            fclose($stream);

            $bloquesEncontrados = [];
            foreach ($bloques as $bloque) {
                $bloqueYaAgregado = false;
                foreach ($bloque as $fila) {

                    // Convertimos la fila a texto para evaluar coincidencia general a prueba de balas
                    $textoFila = strtoupper(implode(' | ', $fila));

                    foreach ($terminosBuscados as $termino) {
                        $termino = strtoupper(trim($termino));
                        if ($termino === '') {
                            continue;
                        }

                        // Evaluamos si el término buscado existe en cualquier lugar de la fila
                        if (str_contains($textoFila, $termino)) {
                            $bloquesEncontrados[] = $bloque;
                            $bloqueYaAgregado = true;
                            break;
                        }
                    }
                    if ($bloqueYaAgregado) {
                        break;
                    }
                }
            }

            if (empty($bloquesEncontrados)) {
                return response()->json(['error' => 'No se encontró información para los folios ingresados.'], 404);
            }

            $clienteMaestro = '';
            foreach ($bloquesEncontrados as $idx => $bloqueFinal) {
                $clienteActual = strtoupper(trim($bloqueFinal[0][1] ?? ''));
                if ($idx === 0) {
                    $clienteMaestro = $clienteActual;
                } else {
                    if ($clienteMaestro !== $clienteActual && !empty($clienteActual)) {
                        return response()->json([
                            'error' => "Los folios pertenecen a clientes diferentes ({$clienteMaestro} vs {$clienteActual})."
                        ], 400);
                    }
                }
            }

            $resultados = [
                'honorarios' => 0,
                'impuestos' => 0,
                'eci' => 0,
                'maniobras' => 0,
                'flete' => 0,
                'muestras' => 0,
                'llc' => 0,
                'anticipo' => 0,
                'garantias' => 0,
                'desglose_naviera' => 0,
                'proveedor_maniobras' => null,
                'factura_maniobras' => null,
                'proveedor_flete' => null,
                'factura_flete' => null,
                'proveedor_muestras' => null,
                'factura_muestras' => null,
                'proveedor_llc' => null,
                'factura_llc' => null,
                'folio_sc' => [],
                'operacion_id' => null,
                'operation_type' => null,
                'pedimento_detectado' => [],
                'operaciones' => [],
                'metodo_pago' => 'PUE'
            ];

            foreach ($bloquesEncontrados as $bloqueFinal) {

                $folioFacturaEnSheet = trim($bloqueFinal[0][8] ?? '');

                $pedimentoReal = '';
                foreach ($bloqueFinal as $fila) {
                    $colPedimento = strtoupper(trim($fila[2] ?? ''));
                    $colRefProv = strtoupper(trim($fila[5] ?? ''));

                    $candidato = '';
                    if (!empty($colPedimento)) {
                        $candidato = $colPedimento;
                    } elseif (!empty($colRefProv) && is_numeric($colRefProv)) {
                        $candidato = $colRefProv;
                    }

                    if (!empty($candidato)) {
                        // Cortamos todo lo que esté después de la primera diagonal
                        if (str_contains($candidato, '/')) {
                            $candidato = trim(explode('/', $candidato)[0]);
                        }
                        $pedimentoReal = $candidato; // ¡Pedimento puro rescatado!
                        break;
                    }
                }

                if (!empty($pedimentoReal)) {
                    $resultados['pedimento_detectado'][] = $pedimentoReal;
                }

                if (!empty($folioFacturaEnSheet)) {
                    $resultados['folio_sc'][] = $folioFacturaEnSheet;
                }

                // Búsqueda exacta y limpia en la base de datos
                $pedimentoDB = null;
                if (!empty($pedimentoReal)) {
                    $pedimentoDB = DB::table('pedimiento')
                        ->where('num_pedimiento', 'LIKE', "%{$pedimentoReal}%")
                        ->orderBy('id_pedimiento', 'desc')
                        ->first();
                }

                $idOp = null;
                $tipoApi = null;
                $idPadre = null;
                $opType = null;

                if ($pedimentoDB) {
                    $impo = DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
                    $expo = DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

                    if ($impo) {
                        $idOp = $impo->id_importacion;
                        $tipoApi = 'importaciones';
                        $idPadre = $impo->parent ?? null;
                        $opType = 'App\Models\OperacionImportacion';
                    } elseif ($expo) {
                        $idOp = $expo->id_exportacion;
                        $tipoApi = 'exportaciones';
                        $idPadre = $expo->parent ?? null;
                        $opType = 'App\Models\OperacionExportacion';
                    }
                }

                $op_honorarios = 0;
                $op_impuestos = 0;
                $op_eci = 0;
                $op_maniobras = 0;
                $op_flete = 0;
                $op_muestras = 0;
                $op_llc = 0;

                if ($quiereNotaCargo) {
                    $clientesDesgloseNL = ['CENTRO ABARROTERO DEL BAJIO', 'ALMACENADORA Y MAQUILAS', 'ALMACENADORAS Y MAQUILA', 'ALMACENADORAS Y MAQUILAS', 'SURTIDORA DEL BAJIO'];

                    $esLaredo = str_contains($sucursalBuscada, 'LAREDO');
                    $aplicaDesgloseManiobras = $esLaredo && in_array($clienteMaestro, $clientesDesgloseNL);

                    foreach ($bloqueFinal as $fila) {
                        $concepto = strtoupper(trim($fila[3] ?? ''));
                        $proveedor = trim($fila[4] ?? '');
                        $factura   = trim($fila[5] ?? '');
                        $montoLimpio = (float) filter_var($fila[6] ?? '0', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                        $esManiobra = false;
                        if ($aplicaDesgloseManiobras) {
                            if (str_contains($concepto, 'MANIOBRAS') || str_contains($concepto, 'FERROVIARIA') || str_contains($concepto, 'FUMIGACI')) {
                                $esManiobra = true;
                            }
                        } else {
                            if (str_contains($concepto, 'MANIOBRAS')) {
                                $esManiobra = true;
                            }
                        }

                        if (str_contains($concepto, 'HONORARIO')) {
                            $resultados['honorarios'] += $montoLimpio;
                            $op_honorarios += $montoLimpio;
                        } elseif (str_contains($concepto, 'IMPUEST')) {
                            $resultados['impuestos'] += $montoLimpio;
                            $op_impuestos += $montoLimpio;
                        } elseif (str_contains($concepto, 'ECI')) {
                            $resultados['eci'] += $montoLimpio;
                            $op_eci += $montoLimpio;
                        } elseif ($esManiobra) {
                            $resultados['maniobras'] += $montoLimpio;
                            $op_maniobras += $montoLimpio;
                            if (!empty($proveedor)) {
                                $resultados['proveedor_maniobras'] = $proveedor;
                            }
                            if (!empty($factura)) {
                                $resultados['factura_maniobras'] = $factura;
                            }
                        } elseif (str_contains($concepto, 'FLETE')) {
                            $resultados['flete'] += $montoLimpio;
                            $op_flete += $montoLimpio;
                            if (!empty($proveedor)) {
                                $resultados['proveedor_flete'] = $proveedor;
                            }
                            if (!empty($factura)) {
                                $resultados['factura_flete'] = $factura;
                            }
                        } elseif (str_contains($concepto, 'MUESTRA')) {
                            $resultados['muestras'] += $montoLimpio;
                            $op_muestras += $montoLimpio;
                            if (!empty($proveedor)) {
                                $resultados['proveedor_muestras'] = $proveedor;
                            }
                            if (!empty($factura)) {
                                $resultados['factura_muestras'] = $factura;
                            }
                        } elseif (str_contains($concepto, 'LLC')) {
                            $resultados['llc'] += $montoLimpio;
                            $op_llc += $montoLimpio;
                            if (!empty($proveedor)) {
                                $resultados['proveedor_llc'] = $proveedor;
                            }
                            if (!empty($factura)) {
                                $resultados['factura_llc'] = $factura;
                            }
                        }
                    }
                }

                if ($quiereCFDI) {
                    if (!empty($folioFacturaEnSheet) || !empty($pedimentoReal)) {
                        $prefijos = ['NOGALES' => 'NOG', 'LAREDO' => 'NL', 'TIJUANA' => 'TIJ', 'MEXICALI' => 'MXL'];
                        $ciudad = explode(' ', $sucursalBuscada)[0];
                        $prefijo = $prefijos[$ciudad] ?? 'NOG';
                        $folioLimpio = preg_replace('/[^0-9]/', '', $folioFacturaEnSheet);
                        $nombreBaseBuscado = $prefijo . $folioLimpio;

                        if ($pedimentoDB && $idOp) {
                            $archivos = [];

                            $urlApi = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/get-files-momentaneo";
                            $respOp = Http::withoutVerifying()->timeout(10)->get($urlApi);
                            if ($respOp->successful() && is_array($respOp->json())) {
                                $archivos = array_merge($archivos, $respOp->json());
                            }

                            if (!empty($idPadre)) {
                                $urlApiPadre = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idPadre}/get-files-momentaneo";
                                $respPadre = Http::withoutVerifying()->timeout(10)->get($urlApiPadre);
                                if ($respPadre->successful() && is_array($respPadre->json())) {
                                    $archivos = array_merge($archivos, $respPadre->json());
                                }
                            }

                            if (!empty($archivos)) {
                                $urlXmlReal = null;

                                foreach ($archivos as $archivo) {
                                    $ext = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));
                                    $tipoPivot = strtolower($archivo['pivot']['type'] ?? '');
                                    $nombreMayus = strtoupper($archivo['name'] ?? '');

                                    $esTipoSC = in_array($tipoPivot, ['sc', 'honorarios-sc']);
                                    $esNombreSC = (!empty($folioLimpio) && str_contains($nombreMayus, $folioLimpio)) || str_contains($nombreMayus, $pedimentoReal);

                                    if ($ext === 'xml' && ($esTipoSC || $esNombreSC)) {
                                        $urlXmlReal = $archivo['url']['normal'] ?? null;
                                        break;
                                    }
                                }

                                if ($urlXmlReal && method_exists($this, 'extraerHonorariosAgenciaXML')) {
                                    $resultadoXML = $this->extraerHonorariosAgenciaXML($urlXmlReal);
                                    if ($resultadoXML['honorarios'] > 0) {
                                        $resultados['honorarios'] += $resultadoXML['honorarios'];
                                        $op_honorarios += $resultadoXML['honorarios'];
                                    }
                                    if (!empty($resultadoXML['folio'])) {
                                        $resultados['folio_sc'][] = $resultadoXML['folio'];
                                    }
                                    if (!empty($resultadoXML['metodo_pago']) && strtoupper($resultadoXML['metodo_pago']) === 'PPD') {
                                        $resultados['metodo_pago'] = 'PPD';
                                    }
                                }
                            }
                        }
                    }
                }

                if ($idOp) {
                    if ($isTransportacticsGlobal) {
                        $op_cfdi = $op_flete;
                        $op_gpc = 0;
                    } else {
                        $op_cfdi = $op_honorarios;
                        $op_gpc = $op_impuestos + $op_eci + $op_maniobras + $op_flete + $op_muestras + $op_llc;
                    }

                    $resultados['operaciones'][] = [
                        'id' => $idOp,
                        'type' => $opType,
                        'folio' => $folioFacturaEnSheet,
                        'monto_cfdi' => round($op_cfdi, 2),
                        'monto_gpc' => round($op_gpc, 2)
                    ];
                }
            }

            $resultados['pedimento_detectado'] = implode(', ', array_unique((array) $resultados['pedimento_detectado']));
            $resultados['folio_sc'] = implode(', ', array_unique((array) $resultados['folio_sc']));
            $resultados['operaciones'] = collect($resultados['operaciones'])->unique('id')->values()->all();

            $montoGarantias = floatval($resultados['garantias'] ?? 0);
            $montoNaviera = floatval($resultados['desglose_naviera'] ?? 0);

            if ($montoGarantias > 0 || $montoNaviera > 0) {
                $resultados['flete'] = 0;
            }

            // Redondeo final de llaves numéricas
            $llavesNumericas = ['honorarios', 'impuestos', 'eci', 'maniobras', 'flete', 'muestras', 'llc', 'anticipo', 'garantias', 'desglose_naviera'];
            foreach ($llavesNumericas as $key) {
                if (isset($resultados[$key])) {
                    if ($key === 'flete' && $resultados[$key] == 0) {
                        $resultados[$key] = 0.00;
                    } else {
                        $resultados[$key] = round($resultados[$key], 2);
                    }
                }
            }

            return response()->json($resultados);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Verifica de forma ultrarrápida si el pedimento existe en el reporte de TRANSITO.
     */
    private function existeEnTransito(array $terminos): bool
    {
        $sheetId = app()->environment('production') ? '1MS1A1bDIY2H2HHZvNbZwOHLs_NIT0jIoZECLF0mk3KE' : '1CW-0O4efVXOIBA1Rm3jJ96nlgfNtmw2HXxzBiFWcDUo';
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&sheet=TRANSITO";

        try {
            $response = Http::withoutVerifying()->timeout(10)->get($csvUrl);

            if ($response->successful()) {
                // Pasamos todo el CSV a mayúsculas para que la búsqueda sea insensible a minúsculas
                $body = strtoupper($response->body());

                foreach ($terminos as $termino) {
                    $termino = strtoupper(trim($termino));
                    if (!empty($termino) && str_contains($body, $termino)) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            // Silencioso
        }

        return false;
    }

    /**
     * Se conecta a la hoja de ALMAN para extraer el total de ALMAN/Flete asociado a los pedimentos o contenedores.
     */
    private function extraerFleteAlman(array $terminos): float
    {
        if (empty($terminos)) {
            return 0.0;
        }

        $montoFlete = 0.0;

        $sheetId = app()->environment('production') ? '1FvhWp2AeOyoiv1KIrmQNOKf9ZoDRy5L7HVd5FcRQBio' : '1yOcPGlvycRBCg5KpWs5-b8EmLQrUNPgh4aqurXQT1Uo';
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&sheet=ALMAN";

        try {
            $response = Http::withoutVerifying()->timeout(15)->get($csvUrl);

            if ($response->successful()) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $response->body());
                rewind($stream);

                $rows = [];
                while (($cols = fgetcsv($stream)) !== false) {
                    if (empty(trim(implode('', $cols)))) {
                        continue;
                    }
                    $rows[] = $cols;

                    // 🔥 TRAMPA DEFINITIVA: Rayos X al archivo crudo de Google
                    // Convertimos toda la fila en un solo texto separado por " | "
                    $filaCruda = implode(' | ', $cols);

                    // Si la fila menciona a Surtidora, 3739 o el pedimento 464, lo capturamos
                    if (str_contains($filaCruda, '3739') || str_contains(strtoupper($filaCruda), 'SURTIDORA') || str_contains($filaCruda, '464/')) {
                        Log::info('--- RAYOS X CSV ---', ['datos_crudos' => $filaCruda]);
                    }
                }
                fclose($stream);

                $idxTotal = -1;
                $idxPedimento = -1;
                $idxContenedor = -1;

                foreach ($rows as $cols) {
                    if ($idxTotal === -1) {
                        foreach ($cols as $i => $col) {
                            $txt = strtolower(trim($col));
                            if (strpos($txt, 'total') !== false && $idxTotal === -1) {
                                $idxTotal = $i;
                            }
                            if (strpos($txt, 'pedimento') !== false && $idxPedimento === -1) {
                                $idxPedimento = $i;
                            }
                            if (strpos($txt, 'contenedor') !== false && $idxContenedor === -1) {
                                $idxContenedor = $i;
                            }
                        }
                    }
                    if ($idxTotal !== -1) {
                        break;
                    }
                }

                $tCol = $idxTotal !== -1 ? $idxTotal : 9;
                $pCol = $idxPedimento !== -1 ? $idxPedimento : 4;
                $cCol = $idxContenedor !== -1 ? $idxContenedor : 3;

                $ultimoPedimento = '';
                $ultimoContenedor = '';

                foreach ($rows as $cols) {
                    $pedimentoCelda = trim($cols[$pCol] ?? '');
                    $contenedorCelda = trim($cols[$cCol] ?? '');
                    $totalCelda = trim($cols[$tCol] ?? '0');

                    if (preg_match('/[4-7]\d{6}/', $pedimentoCelda, $mPed)) {
                        $ultimoPedimento = $mPed[0];
                    }

                    if ($contenedorCelda !== '') {
                        $ultimoContenedor = $contenedorCelda;
                    }

                    $coincide = false;
                    foreach ($terminos as $termino) {
                        $term = strtoupper(trim($termino));
                        if ($term === '') {
                            continue;
                        }

                        // Buscamos coincidencia exacta o parcial en las celdas y en la memoria heredada
                        if (
                            str_contains(strtoupper($ultimoPedimento), $term) ||
                            str_contains(strtoupper($ultimoContenedor), $term) ||
                            str_contains(strtoupper($pedimentoCelda), $term) ||
                            str_contains(strtoupper($contenedorCelda), $term)
                        ) {
                            $coincide = true;
                            break;
                        }
                    }

                    if ($coincide) {
                        $montoLimpio = (float) filter_var(str_replace(',', '', $totalCelda), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

                        // Si tiene dinero y no es la fila de encabezado
                        if ($montoLimpio > 0 && stripos($totalCelda, 'total') === false && stripos($cols[0] ?? '', 'total') === false) {
                            $montoFlete += $montoLimpio;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Falla silenciosa para no interrumpir el flujo principal si el excel no responde
        }

        return $montoFlete;
    }

    private function extraerHonorariosAgenciaXML(string $urlCompleta): array
    {
        $debug = [
            'intento_1' => $urlCompleta,
            'status_1' => 0,
            'xml_encontrado' => false,
            'honorarios_extraidos' => 0,
            'folio_extraido' => null,
            'metodo_pago_extraido' => null
        ];

        try {
            $headers = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'];

            $response = Http::withoutVerifying()->withHeaders($headers)->timeout(15)->get($urlCompleta);
            $debug['status_1'] = $response->status();
            $xmlString = $response->successful() ? $response->body() : null;

            if (!$xmlString) {
                $nombreArchivo = basename($urlCompleta);
                $rutaAlternativa = "https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/{$nombreArchivo}";
                $debug['intento_2'] = $rutaAlternativa;

                $responseAlt = Http::withoutVerifying()->withHeaders($headers)->timeout(15)->get($rutaAlternativa);
                $debug['status_2'] = $responseAlt->status();
                $xmlString = $responseAlt->successful() ? $responseAlt->body() : null;
            }

            if (!$xmlString) {
                // Devolvemos PUE por defecto en caso de falla
                return ['honorarios' => 0.0, 'folio' => null, 'metodo_pago' => 'PUE', 'debug' => $debug];
            }

            $debug['xml_encontrado'] = true;
            $honorarios = 0.0;
            $folio = null;
            $metodoPago = null; // Inicializamos nulo para saber si lo encontramos

            // 1. Intentamos leer con SimpleXML
            try {
                $xmlObj = @simplexml_load_string($xmlString);
                if ($xmlObj !== false) {
                    if (isset($xmlObj['Total'])) {
                        $honorarios = (float) $xmlObj['Total'];
                    }
                    if (isset($xmlObj['Folio'])) {
                        $folio = (string) $xmlObj['Folio'];
                    }
                    if (isset($xmlObj['MetodoPago'])) {
                        $metodoPago = (string) $xmlObj['MetodoPago'];
                    }
                }
            } catch (\Throwable $th) {
            }

            // 2. Si falla SimpleXML, usamos Expresiones Regulares (Regex)
            if ($honorarios === 0.0) {
                if (preg_match('/Comprobante[^>]+Total=["\']([0-9\,\.]+)["\']/i', $xmlString, $matchesTotal)) {
                    $honorarios = (float) str_replace(',', '', $matchesTotal[1]);
                }
            }
            if (empty($folio)) {
                if (preg_match('/Comprobante[^>]+Folio=["\']([^"\']+)["\']/i', $xmlString, $matchesFolio)) {
                    $folio = $matchesFolio[1];
                }
            }
            // Regex para buscar MetodoPago="PPD" o "PUE"
            if (empty($metodoPago)) {
                if (preg_match('/MetodoPago=["\'](PUE|PPD)["\']/i', $xmlString, $matchesMetodo)) {
                    $metodoPago = strtoupper(trim($matchesMetodo[1]));
                } else {
                    $metodoPago = 'PUE'; // Default infalible
                }
            }

            $debug['honorarios_extraidos'] = $honorarios;
            $debug['folio_extraido'] = $folio;
            $debug['metodo_pago_extraido'] = $metodoPago;

            return [
                'honorarios'  => $honorarios,
                'folio'       => $folio,
                'metodo_pago' => $metodoPago, // Devolvemos el dato
                'debug'       => $debug
            ];
        } catch (\Throwable $e) {
            $debug['error'] = $e->getMessage();
            return ['honorarios' => 0.0, 'folio' => null, 'metodo_pago' => 'PUE', 'debug' => $debug];
        }
    }

    private function procesarIngresoTransportactics(array $terminosBuscados)
    {
        $resultados = [
            'honorarios' => 0,
            'impuestos' => 0,
            'eci' => 0,
            'maniobras' => 0,
            'flete' => 0,
            'muestras' => 0,
            'llc' => 0,
            'anticipo' => 0,
            'garantias' => 0,
            'desglose_naviera' => 0,
            'folio_sc' => [],
            'pedimento_detectado' => [],
            'operaciones' => []
        ];

        $logDebug = [];
        $totalFacturasXML = 0;

        foreach ($terminosBuscados as $termino) {
            $termLimpio = strtoupper(trim($termino));
            if (empty($termLimpio)) {
                continue;
            }

            if (preg_match('/\b\d{6,7}\b/', $termLimpio, $matches)) {
                $pedimentoBusqueda = $matches[0];
            } else {
                $pedimentoExtraido = explode(' ', $termLimpio)[0];
                $pedimentoBusqueda = str_contains($pedimentoExtraido, '-') ? explode('-', $pedimentoExtraido)[1] : $pedimentoExtraido;
            }

            $pedimentoBusqueda = trim($pedimentoBusqueda);
            $logDebug[] = "Buscando: {$pedimentoBusqueda}";

            $pedimentoDB = DB::table('pedimiento')
                ->where('num_pedimiento', 'LIKE', "%{$pedimentoBusqueda}%")
                ->orderBy('id_pedimiento', 'desc')
                ->first();

            if (!$pedimentoDB) {
                $logDebug[] = "Pedimento NO existe en BD.";
                continue;
            }

            $resultados['pedimento_detectado'][] = $pedimentoDB->num_pedimiento;
            $impo = DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
            $expo = DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

            $idOp = null;
            $tipoApi = null;
            $idPadre = null;
            $opType = null;

            if ($impo) {
                $idOp = $impo->id_importacion;
                $tipoApi = 'importaciones';
                $idPadre = $impo->parent ?? null;
                $opType = 'App\Models\OperacionImportacion';
            } elseif ($expo) {
                $idOp = $expo->id_exportacion;
                $tipoApi = 'exportaciones';
                $idPadre = $expo->parent ?? null;
                $opType = 'App\Models\OperacionExportacion';
            }

            if (!$idOp) {
                $logDebug[] = "El pedimento existe, pero NO está ligado a una Impo/Expo ($impo / $expo).";
                continue;
            }

            $logDebug[] = "✅ Op: {$idOp} | Padre: " . ($idPadre ?: 'Nulo');

            $archivos = [];
            // 1. Archivos del hijo
            $respOp = Http::withoutVerifying()->timeout(10)->get("https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/get-files-momentaneo");
            if ($respOp->successful() && is_array($respOp->json())) {
                $archivos = array_merge($archivos, $respOp->json());
            }

            // 2. Archivos del Padre (donde suele estar el flete)
            if (!empty($idPadre)) {
                $respPadre = Http::withoutVerifying()->timeout(10)->get("https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idPadre}/get-files-momentaneo");
                if ($respPadre->successful() && is_array($respPadre->json())) {
                    $archivos = array_merge($archivos, $respPadre->json());
                }
            }

            $xmlCount = 0;
            $montoCfdiOp = 0; // Guardará el total del flete de ESTA operación

            foreach ($archivos as $archivo) {
                $ext = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));
                if ($ext === 'xml') {
                    $xmlCount++;
                    $urlXml = $archivo['url']['normal'] ?? null;
                    if ($urlXml) {
                        $datosFactura = $this->parsearXmlFlete($urlXml);
                        $nombreEmisor = strtoupper($datosFactura['emisor']);
                        $montoXML = $datosFactura['total'];

                        $logDebug[] = "XML '{$archivo['name']}': Emisor '{$nombreEmisor}' -> \${$montoXML}";

                        if ($montoXML > 0 && str_contains($nombreEmisor, 'TRANSPORTACTICS')) {
                            $totalFacturasXML += $montoXML;
                            $montoCfdiOp += $montoXML; // Sumamos a la operación individual

                            if (!empty($datosFactura['folio'])) {
                                $resultados['folio_sc'][] = $datosFactura['folio'];
                            }
                        }
                    }
                }
            }

            // Guardamos la operación con su monto para la tabla pivote
            $resultados['operaciones'][] = [
                'id' => $idOp,
                'type' => $opType,
                'monto_cfdi' => round($montoCfdiOp, 2),
                'monto_gpc' => 0
            ];

            if ($xmlCount === 0) {
                $logDebug[] = "Se descargaron " . count($archivos) . " archivos, pero NINGUNO era .xml";
            }
        }

        $resultados['flete'] = $totalFacturasXML;

        // TRAMPA DE DIAGNÓSTICO: Si el flete es 0, forzamos un error mostrando TODO el recorrido
        if ($totalFacturasXML == 0) {
            return response()->json(['error' => 'Análisis Detallado: ' . implode(' | ', $logDebug)], 404);
        }

        $resultados['pedimento_detectado'] = implode(', ', array_unique($resultados['pedimento_detectado']));
        $resultados['folio_sc'] = implode(', ', array_unique($resultados['folio_sc']));
        $resultados['operaciones'] = collect($resultados['operaciones'])->unique('id')->values()->all();

        return response()->json($resultados);
    }

    /**
     * Extrae información del XML utilizando SimpleXML y Regex como respaldo,
     * conectándose al CDN de DigitalOcean si el archivo original no existe.
     */
    private function parsearXmlFlete(?string $rutaXml): ?array
    {
        $defaultReturn = ['total' => 0, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];

        if (!$rutaXml) {
            return $defaultReturn;
        }

        try {
            // Usamos el motor HTTP de Laravel (cURL) en lugar de file_get_contents
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->get($rutaXml);

            $xmlString = $response->successful() ? $response->body() : null;

            // Si falla o da error 404, intentamos la ruta alternativa
            if (!$xmlString) {
                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);

                $respAlt = Http::withoutVerifying()
                    ->timeout(10)
                    ->get($rutaAlternativa);

                $xmlString = $respAlt->successful() ? $respAlt->body() : null;
            }

            // Si ambos fallaron, regresamos 0
            if (!$xmlString) {
                Log::warning("No se pudo descargar el XML de ninguna ruta: {$rutaXml}");
                return $defaultReturn;
            }

            $total = null;
            $moneda = null;
            $emisor = null;
            $fecha = null;
            $folio = null;

            // INTENTO 1: Lector XML Nativo de PHP
            try {
                $xmlObj = @simplexml_load_string($xmlString);
                if ($xmlObj !== false) {
                    $total = isset($xmlObj['Total']) ? (float) $xmlObj['Total'] : null;
                    $moneda = isset($xmlObj['Moneda']) ? strtoupper((string) $xmlObj['Moneda']) : null;
                    $fecha = isset($xmlObj['Fecha']) ? explode('T', (string) $xmlObj['Fecha'])[0] : null;

                    if (isset($xmlObj['Folio'])) {
                        $folioRaw = (string) $xmlObj['Folio'];
                        $folio = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
                    }

                    $namespaces = $xmlObj->getNamespaces(true);
                    if (isset($namespaces['cfdi'])) {
                        $emisorObj = $xmlObj->children($namespaces['cfdi'])->Emisor;
                        if ($emisorObj && isset($emisorObj['Nombre'])) {
                            $emisor = trim((string) $emisorObj['Nombre']);
                        }
                    }
                }
            } catch (\Throwable $th) {
                // Fallo silencioso, pasamos al regex
            }

            // INTENTO 2: Fallback Regex Aislado (Solo busca en la cabecera)
            if (preg_match('/<[^:]*:?Comprobante([^>]+)>/is', $xmlString, $comprobanteMatch)) {
                $comprobanteAttrs = $comprobanteMatch[1];

                if ($total === null && preg_match('/Total=["\']([0-9\,\.]+)["\']/is', $comprobanteAttrs, $mTotal)) {
                    $total = (float) str_replace(',', '', $mTotal[1]);
                }

                if ($fecha === null && preg_match('/Fecha=["\']([^"\']+)["\']/is', $comprobanteAttrs, $mFecha)) {
                    $fecha = explode('T', $mFecha[1])[0];
                }

                if ($moneda === null && preg_match('/Moneda=["\']([A-Z]{3})["\']/is', $comprobanteAttrs, $mMoneda)) {
                    $moneda = strtoupper($mMoneda[1]);
                }

                if ($folio === null && preg_match('/Folio=["\']([^"\']+)["\']/is', $comprobanteAttrs, $mFolio)) {
                    $folioRaw = $mFolio[1];
                    $folio = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
                }
            }

            // Regex del Emisor
            if (empty($emisor) && preg_match('/Emisor[^>]+Nombre=["\']([^"\']+)["\']/is', $xmlString, $mEmisor)) {
                $emisor = trim($mEmisor[1]);
            }

            return [
                'total'  => (float) $total,
                'moneda' => $moneda ?: 'MXN',
                'emisor' => $emisor ?: '',
                'fecha'  => $fecha,
                'folio'  => $folio
            ];
        } catch (\Throwable $e) {
            Log::error("Error parseando XML {$rutaXml}: " . $e->getMessage());
            return $defaultReturn;
        }
    }

    private function procesarIngresoIntshipperts(array $terminosBuscados)
    {
        $resultados = [
            'honorarios'          => 0,
            'impuestos'           => 0,
            'eci'                 => 0,
            'maniobras'           => 0,
            'flete'               => 0,
            'muestras'            => 0,
            'llc'                 => 0,
            'anticipo'            => 0,
            'garantias'           => 0,
            'desglose_naviera'    => 0,
            'folio_sc'            => [],
            'pedimento_detectado' => [],
            'operaciones'         => []
        ];

        $logDebug = [];
        $totalFacturasXML = 0; // Aquí sumaremos el total de Intshipperts
        $pedimentosLimpios = []; // Guardaremos los números de pedimento para buscar en el Excel

        foreach ($terminosBuscados as $termino) {
            $termLimpio = strtoupper(trim($termino));
            if (empty($termLimpio)) {
                continue;
            }

            if (preg_match('/\b\d{6,7}\b/', $termLimpio, $matches)) {
                $pedimentoBusqueda = $matches[0];
            } else {
                $pedimentoExtraido = explode(' ', $termLimpio)[0];
                $pedimentoBusqueda = str_contains($pedimentoExtraido, '-') ? explode('-', $pedimentoExtraido)[1] : $pedimentoExtraido;
            }

            $pedimentoBusqueda = trim($pedimentoBusqueda);
            $pedimentosLimpios[] = $pedimentoBusqueda; // Lo guardamos para el paso 2

            $logDebug[] = "1. Buscando pedimento: {$pedimentoBusqueda}";

            $pedimentoDB = DB::table('pedimiento')
                ->where('num_pedimiento', 'LIKE', "%{$pedimentoBusqueda}%")
                ->orderBy('id_pedimiento', 'desc')
                ->first();

            if ($pedimentoDB) {
                $resultados['pedimento_detectado'][] = $pedimentoDB->num_pedimiento;

                $impo = DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
                $expo = DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

                $idOp = null;
                $tipoApi = null;

                if ($impo) {
                    $idOp = $impo->id_importacion;
                    $tipoApi = 'importaciones';
                    $resultados['operaciones'][] = ['id' => $idOp, 'type' => 'App\Models\OperacionImportacion'];
                } elseif ($expo) {
                    $idOp = $expo->id_exportacion;
                    $tipoApi = 'exportaciones';
                    $resultados['operaciones'][] = ['id' => $idOp, 'type' => 'App\Models\OperacionExportacion'];
                }

                if ($idOp) {
                    $archivos = [];
                    $urlApi = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/get-files-momentaneo";
                    $respOp = Http::withoutVerifying()->timeout(10)->get($urlApi);

                    if ($respOp->successful() && is_array($respOp->json())) {
                        $archivos = array_merge($archivos, $respOp->json());
                    }

                    foreach ($archivos as $archivo) {
                        $ext = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));

                        if ($ext === 'xml') {
                            $urlXml = $archivo['url']['normal'] ?? null;
                            if ($urlXml) {
                                $datosFactura = $this->parsearXmlFlete($urlXml);

                                if ($datosFactura['total'] > 0 && str_contains(strtoupper($datosFactura['emisor']), 'INTSHIPPERT')) {
                                    $totalFacturasXML += $datosFactura['total'];

                                    if (!empty($datosFactura['folio'])) {
                                        $resultados['folio_sc'][] = $datosFactura['folio'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $almanFleteTotal = 0;
        try {
            $sheetIdZlo = '1yOcPGlvycRBCg5KpWs5-b8EmLQrUNPgh4aqurXQT1Uo';
            $csvUrl = "https://docs.google.com/spreadsheets/d/{$sheetIdZlo}/gviz/tq?tqx=out:csv&sheet=ALMAN";

            $response = Http::withoutVerifying()->timeout(15)->get($csvUrl);

            if ($response->successful()) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $response->body());
                rewind($stream);

                $headersLeidos = false;
                $ultimoPedimento = '';

                while (($cols = fgetcsv($stream)) !== false) {
                    if (!$headersLeidos) {
                        $headersLeidos = true;
                        continue;
                    }

                    $montoCelda = isset($cols[2]) ? trim($cols[2]) : '0';
                    $pedimentoCelda = isset($cols[4]) ? trim($cols[4]) : '';

                    // Si está vacía, "heredamos" el último que vimos (porque es una celda combinada hacia abajo)
                    if ($pedimentoCelda !== '') {
                        $ultimoPedimento = $pedimentoCelda;
                    } else {
                        $pedimentoCelda = $ultimoPedimento;
                    }

                    // Solo revisamos si realmente hay un monto (para ignorar filas totalmente vacías)
                    if ($montoCelda !== '' && $montoCelda !== '0') {
                        foreach ($pedimentosLimpios as $pedLimpio) {
                            if (str_contains($pedimentoCelda, $pedLimpio)) {
                                // Limpiamos los signos de $ y comas del Excel para poder sumar
                                $montoLimpio = (float) str_replace(['$', ',', ' '], '', $montoCelda);
                                $almanFleteTotal += $montoLimpio;
                                $logDebug[] = "Suma ALMAN: Pedimento {$pedLimpio} sumó \${$montoLimpio}";
                                break;
                            }
                        }
                    }
                }
                fclose($stream);
            }
        } catch (\Exception $e) {
            Log::error('Error leyendo ALMAN: ' . $e->getMessage());
        }

        // 1. Flete / ALMAN: La sumatoria que extrajimos del Google Sheets ($81,896.00)
        $resultados['flete'] = round($almanFleteTotal, 2);

        // 2. Anticipo: (Total de XMLs de INTSHIPPERTS) - (ALMAN/Flete)
        $resultados['anticipo'] = round($totalFacturasXML - $almanFleteTotal, 2);

        // 3. Honorarios no se usa en este caso, lo forzamos a 0
        $resultados['honorarios'] = 0;

        $resultados['pedimento_detectado'] = implode(', ', array_unique($resultados['pedimento_detectado']));
        $resultados['folio_sc'] = implode(', ', array_unique($resultados['folio_sc']));
        $resultados['operaciones'] = collect($resultados['operaciones'])->unique('id')->values()->all();

        return response()->json($resultados);
    }

    public function generarComplemento(Request $request)
    {
        // 1. Validamos SOLAMENTE los datos del complemento
        $request->validate([
            'ingreso_id' => 'required|integer',
            'cliente_id' => 'required|integer',
            'sucursal' => 'required|string',
            'moneda' => 'required|integer',
            'tipo_cambio' => 'required|numeric',
            'referencia' => 'nullable|string',
            'observaciones' => 'nullable|string',
            'total' => 'required|numeric',
            'forma_pago' => 'required|string',
            'metodo_pago' => 'required|string',
        ]);

        try {
            $cliente = Empresas::findOrFail($request->cliente_id);
            $sucursalBuscada = strtoupper($request->sucursal);

            // Variables para Contpaqi (Código de cliente y Serie de la Factura)
            $columnaCodigo = 'codigo_de_cliente';
            $serieFactura = 'NOG'; // Por defecto

            if (str_contains($sucursalBuscada, 'LAREDO') || str_contains($sucursalBuscada, 'NL')) {
                $columnaCodigo = 'codigo_de_cliente_laredo';
                $serieFactura = 'NL';
            } elseif (str_contains($sucursalBuscada, 'TIJUANA') || str_contains($sucursalBuscada, 'TIJ')) {
                $columnaCodigo = 'codigo_de_cliente_tijuana';
                $serieFactura = 'TIJ';
            } elseif (str_contains($sucursalBuscada, 'MEXICALI') || str_contains($sucursalBuscada, 'MXL')) {
                $columnaCodigo = 'codigo_de_cliente_mexicali';
                $serieFactura = 'MXL';
            } elseif (str_contains($sucursalBuscada, 'MANZANILLO') || str_contains($sucursalBuscada, 'ZLO')) {
                $columnaCodigo = 'codigo_de_cliente_manzanillo';
                $serieFactura = 'ZLO';
            }

            $codigoContpaqi = $cliente->{$columnaCodigo};

            if (empty($codigoContpaqi)) {
                return response()->json(['error' => "El cliente no tiene configurado un código de Contpaqi para la sucursal {$sucursalBuscada}."], 400);
            }

            // Obtenemos el folio ANTES de crear nada
            $ingreso = IngresoConciliado::find($request->ingreso_id);
            $foliosFacturas = $ingreso && !empty($ingreso->folio_sc) ? $ingreso->folio_sc : $request->referencia;

            $fechaIngreso = $ingreso && $ingreso->fecha ? Carbon::parse($ingreso->fecha) : Carbon::now();
            $fechaIngresoIso = $fechaIngreso->toIso8601String();

            // Extraemos únicamente los números del folio
            preg_match('/[0-9]+/', $foliosFacturas, $matches);
            $folioFacturaLimpio = isset($matches[0]) ? (int) $matches[0] : 0;

            if ($folioFacturaLimpio === 0) {
                // Detenemos el proceso y mandamos la advertencia al frontend
                return response()->json([
                    'error' => "No se detectó un folio numérico válido en la referencia ('{$foliosFacturas}'). Por favor, valide en Contpaqi la existencia de la factura original y asegúrese de ingresarlo correctamente."
                ], 422);
            }

            // ==============================================================
            // PASO 1: CREAR EL COMPLEMENTO DE PAGO
            // ==============================================================
            $conceptoPagoCP = '100014.0';
            $conceptoFactura = '520174.0';

            $payloadCrear = [
                '$type' => 'CrearDocumentoRequest',
                'model' => [
                    'documento' => [
                        'concepto' => ['codigo' => $conceptoPagoCP],
                        'cliente' => ['codigo' => $codigoContpaqi],
                        'fecha' => $fechaIngresoIso,
                        'moneda' => ['id' => (int) $request->moneda],
                        'tipoCambio' => (float) $request->tipo_cambio,
                        'referencia' => $request->referencia ?? '',
                        'observaciones' => $request->observaciones ?? '',
                        'total' => (float) $request->total,
                        'formaPago' => $request->forma_pago,
                        'metodoPago' => $request->metodo_pago
                    ]
                ],
                'options' => [
                    'usarFechaDelDia' => false,
                    'buscarSiguienteFolio' => true,
                    'crearActualizarCatalogos' => false,
                    'crearActualizarCliente' => false,
                    'crearActualizarAgente' => false,
                    'crearActualizarProducto' => false,
                    'crearActualizarUnidadMedida' => false,
                    'crearActualizarAlmacen' => false,
                    'cargarDatosExtra' => false
                ]
            ];

            $responseCrear = Http::withoutVerifying()
                ->timeout(30)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadCrear);

            if (!$responseCrear->successful()) {
                return response()->json(['error' => 'Error de la API al crear el Complemento: ' . $responseCrear->body()], $responseCrear->status());
            }

            $resData = $responseCrear->json();
            $idRequest = $resData['data']['id'] ?? ($resData['id'] ?? null);

            // Extraemos los datos del Complemento devueltos por la API
            $folioComplemento = $resData['raw']['response']['contpaqiResponse']['model']['documento']['folio'] ?? null;
            $serieComplemento = $resData['raw']['response']['contpaqiResponse']['model']['documento']['serie'] ?? 'CP';

            ComplementoPago::create([
                'ingreso_conciliado_id' => $request->ingreso_id,
                'request_id' => $idRequest,
                'serie' => $serieComplemento,
                'folio_factura' => $foliosFacturas,
                'folio' => $folioComplemento,
                'fecha' => $fechaIngreso
            ]);

            // ==============================================================
            // PASO 2: SALDAR LA FACTURA AUTOMÁTICAMENTE
            // ==============================================================
            $resultadoSaldado = null;
            $mensajeSaldado = "Complemento creado exitosamente, pero no se saldó la factura.";
            $saldadoExitoso = false;

            if ($folioComplemento && $folioFacturaLimpio > 0) {
                $fechaAplicacion = $request->fecha_pago ? Carbon::parse($request->fecha_pago) : $fechaIngreso;
                $fechaIso = $fechaAplicacion->toIso8601String();

                $payloadSaldar = [
                    '$type' => 'SaldarDocumentoRequest',
                    'model' => [
                        'documentoAPagar' => [
                            'conceptoCodigo' => $conceptoFactura,
                            'serie'          => $serieFactura,
                            'folio'          => $folioFacturaLimpio
                        ],
                        'documentoPago' => [
                            'conceptoCodigo' => $conceptoPagoCP,
                            'serie'          => $serieComplemento,
                            'folio'          => (int) $folioComplemento
                        ],
                        'fecha'   => $fechaIso,
                        'importe' => (float) $request->total,
                        'monedaId' => (int) $request->moneda
                    ],
                    'options' => ['cargarDatosExtra' => false]
                ];

                $responseSaldar = Http::withoutVerifying()
                    ->timeout(30)
                    ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadSaldar);

                if ($responseSaldar->successful()) {
                    $resultadoSaldado = $responseSaldar->json();
                    $isContpaqiSuccess = $resultadoSaldado['respuesta']['isSuccess'] ?? false;

                    if ($isContpaqiSuccess) {
                        $mensajeSaldado = "Complemento creado y factura saldada correctamente en Contpaqi. Listo para revisión y timbrado.";
                        $saldadoExitoso = true;
                    } else {
                        // Advertencia si Contpaqi no encuentra el folio
                        $errorContpaqi = $resultadoSaldado['respuesta']['errorMessage'] ?? 'Error desconocido al intentar saldar.';

                        if (str_contains($errorContpaqi, 'LlaveDocumento no existe')) {
                            $mensajeSaldado = "ADVERTENCIA: El complemento fue creado (Folio: {$folioComplemento}), pero la Factura Original (Serie: {$serieFactura} | Folio: {$folioFacturaLimpio}) NO EXISTE en Contpaqi. Por favor, valide en el sistema Contpaqi.";
                        } else {
                            $mensajeSaldado = "Complemento creado, pero NO se pudo saldar: " . $errorContpaqi;
                        }
                    }
                } else {
                    $mensajeSaldado = "Complemento creado, pero hubo un error de conexión al saldar: " . $responseSaldar->body();
                }
            }

            return response()->json([
                'success' => true,
                'saldado' => $saldadoExitoso,
                'message' => $mensajeSaldado,
                'serie'   => $serieComplemento,
                'folio'   => $folioComplemento,
                'data_complemento' => $resData,
                'data_saldado' => $resultadoSaldado
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }
    public function timbrarComplemento(Request $request)
    {
        // 1. Validamos los datos necesarios
        $request->validate([
            'serie' => 'required|string',
            'folio' => 'required|integer',
            'concepto_codigo' => 'nullable|string'
        ]);

        try {
            $folio = (int) $request->folio;

            // ==========================================
            // PASO 1: TIMBRAR EL DOCUMENTO
            // ==========================================
            $payloadTimbrar = [
                '$type' => 'TimbrarDocumentoRequest',
                'model' => [
                    'llaveDocumento' => [
                        'conceptoCodigo' => 'CP',
                        'serie'          => '100014.0',
                        'folio'          => $folio
                    ],
                    'contrasenaCertificado' => 'INT081028GF0'
                ],
                'options' => new \stdClass()
            ];

            $responseTimbrar = Http::withoutVerifying()
                ->timeout(60)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadTimbrar);

            // Validamos si falló la conexión al timbrar
            if (!$responseTimbrar->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Error de conexión al intentar timbrar (Código ' . $responseTimbrar->status() . '). ' . $responseTimbrar->body(),
                    'request_timbre' => $payloadTimbrar // Agregado para auditoría
                ], $responseTimbrar->status());
            }

            $resultadoTimbre = $responseTimbrar->json();
            $isSuccessTimbre = $resultadoTimbre['respuesta']['isSuccess'] ?? false;

            // Si el PAC/SAT rechaza el timbrado, detenemos todo y avisamos
            if (!$isSuccessTimbre) {
                $errorTimbre = $resultadoTimbre['respuesta']['errorMessage'] ?? 'Error devuelto por el PAC/SAT.';
                return response()->json([
                    'success' => false,
                    'error' => 'Fallo al timbrar: ' . $errorTimbre,
                    'request_timbre' => $payloadTimbrar // Agregado para auditoría
                ], 400);
            }

            // ==========================================
            // PASO 2: GENERAR ARCHIVOS (SOLO SI EL TIMBRE FUE EXITOSO)
            // ==========================================

            $payloadPdf = [
                '$type' => 'GenerarDocumentoDigitalRequest',
                'model' => [
                    'llaveDocumento' => [
                        'conceptoCodigo' => 'CP',
                        'serie'          => '100014.0',
                        'folio'          => $folio
                    ]
                ],
                'options' => [
                    'tipo' => 'Pdf',
                    'nombrePlantilla' => 'REP Intactics.rdl'
                ]
            ];

            $payloadXml = [
                '$type' => 'GenerarDocumentoDigitalRequest',
                'model' => [
                    'llaveDocumento' => [
                        'conceptoCodigo' => 'CP',
                        'serie'          => '100014.0',
                        'folio'          => $folio
                    ]
                ],
                'options' => [
                    'tipo' => 'Xml',
                    'nombrePlantilla' => ''
                ]
            ];

            $responsePdf = Http::withoutVerifying()
                ->timeout(60)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadPdf);

            $responseXml = Http::withoutVerifying()
                ->timeout(60)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadXml);

            // Si falla HTTP en la generación de archivos, devolvemos éxito del timbre pero con aviso
            if (!$responsePdf->successful() || !$responseXml->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => '¡El Complemento fue TIMBRADO con éxito!, pero hubo un error de red al intentar descargar los archivos.',
                    'data_timbre' => $resultadoTimbre,
                    'request_timbre' => $payloadTimbrar,
                    'request_pdf' => $payloadPdf,
                    'request_xml' => $payloadXml
                ]);
            }

            $dataPdf = $responsePdf->json();
            $dataXml = $responseXml->json();

            $pdfSuccess = $dataPdf['raw']['response']['isSuccess'] ?? $dataPdf['respuesta']['isSuccess'] ?? false;
            $xmlSuccess = $dataXml['raw']['response']['isSuccess'] ?? $dataXml['respuesta']['isSuccess'] ?? false;

            if ($pdfSuccess && $xmlSuccess) {

                // ==========================================
                // PASO 3: EXTRAER Y GUARDAR LOS ARCHIVOS
                // ==========================================
                $base64Pdf = $dataPdf['raw']['response']['contpaqiResponse']['model']['documentoDigital']['contenido'] ?? null;
                $base64Xml = $dataXml['raw']['response']['contpaqiResponse']['model']['documentoDigital']['contenido'] ?? null;

                if ($base64Pdf && $base64Xml) {

                    $archivoPdf = base64_decode($base64Pdf);
                    $archivoXml = base64_decode($base64Xml);

                    $nombrePdf = "complementos_pago/CP_{$folio}_" . time() . ".pdf";
                    $nombreXml = "complementos_pago/CP_{$folio}_" . time() . ".xml";

                    /** @var \Illuminate\Filesystem\FilesystemAdapter $discoPersonalizado */
                    $discoPersonalizado = Storage::disk('storageOldProyect');

                    $discoPersonalizado->put($nombrePdf, $archivoPdf);
                    $discoPersonalizado->put($nombreXml, $archivoXml);

                    // Actualizamos la base de datos en la tabla complementos_pago
                    DB::table('complemento_pago')
                        ->where('serie', 'CP')
                        ->where('folio', $folio)
                        ->update([
                            'ruta_pdf'   => $nombrePdf,
                            'ruta_xml'   => $nombreXml,
                            'timbrado'   => true,
                            'updated_at' => Carbon::now()
                        ]);

                    // Opcional pero recomendado: Actualizar el estado de envío a ENVIADO o TIMBRADO
                    // en ingresos_conciliados si recibiste el ingreso_id en el request
                    if ($request->has('ingreso_id')) {
                        DB::table('ingresos_conciliados')
                            ->where('id', $request->ingreso_id)
                            ->update(['estado_envio' => 'ENVIADO']);
                    }

                    $urlPdf = $discoPersonalizado->url($nombrePdf);
                    $urlXml = $discoPersonalizado->url($nombreXml);

                    // Todo salió perfecto: Timbre y Archivos
                    return response()->json([
                        'success' => true,
                        'message' => '¡El Complemento fue TIMBRADO con éxito y los archivos fueron generados y guardados!',
                        'url_pdf' => $urlPdf,
                        'url_xml' => $urlXml,
                        'data_timbre' => $resultadoTimbre,
                        'request_timbre' => $payloadTimbrar,
                        'request_pdf' => $payloadPdf,
                        'request_xml' => $payloadXml
                    ]);
                }
            }

            // Si el PAC rechazó la generación del documento pero SÍ se timbró
            return response()->json([
                'success' => true,
                'message' => '¡El Complemento fue TIMBRADO con éxito!, pero Contpaqi no pudo devolver el PDF/XML en este momento.',
                'data_timbre' => $resultadoTimbre,
                'error_pdf' => $dataPdf['raw']['response']['errorMessage'] ?? 'N/A',
                'error_xml' => $dataXml['raw']['response']['errorMessage'] ?? 'N/A',
                'request_timbre' => $payloadTimbrar,
                'request_pdf' => $payloadPdf,
                'request_xml' => $payloadXml
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error interno al procesar el timbrado y los archivos: ' . $e->getMessage()
            ], 500);
        }
    }
    public function verComplementoPdf($id)
    {
        try {
            $complemento = ComplementoPago::where('ingreso_conciliado_id', $id)->latest()->first();

            if (!$complemento || !$complemento->folio) {
                return response('<h1>Error 404</h1><p>No se encontró el Complemento.</p>', 404)->header('Content-Type', 'text/html');
            }

            $payloadPdf = [
                '$type' => 'GenerarDocumentoDigitalRequest',
                'model' => [
                    'llaveDocumento' => [
                        'conceptoCodigo' => '100014.0',
                        'serie'          => $complemento->serie ?? 'CP',
                        'folio'          => (int) $complemento->folio
                    ]
                ],
                'options' => [
                    'tipo' => 'Pdf',
                    'nombrePlantilla' => 'REP Intactics.rdl'
                ]
            ];

            $response = Http::withoutVerifying()
                ->timeout(60)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadPdf);

            if ($response->successful()) {
                $data = $response->json();

                // ==========================================
                // 2. BUSCAMOS EL BASE64 EN LA RUTA EXACTA
                // ==========================================
                $pdfBase64 = $data['raw']['response']['contpaqiResponse']['model']['documentoDigital']['contenido'] ?? null;

                if (!empty($pdfBase64)) {
                    $archivoDecodificado = base64_decode($pdfBase64);

                    // Devolvemos el archivo binario para que el iframe lo lea directo
                    return response($archivoDecodificado, 200)
                        ->header('Content-Type', 'application/pdf')
                        // Usamos 'inline' para que se visualice en el navegador en lugar de descargar forzosamente
                        ->header('Content-Disposition', 'inline; filename="Complemento_' . $complemento->serie . '_' . $complemento->folio . '.pdf"');
                }

                // Si la API respondió 200 OK pero no mandó el Base64, mostramos el JSON para depurar
                return response("<h1>Documento Timbrado, pero PDF no encontrado.</h1><pre>" . json_encode($data, JSON_PRETTY_PRINT) . "</pre>", 200)->header('Content-Type', 'text/html');
            }

            // Si la API falló a nivel HTTP (ej. 500, 404)
            return response("<h1>Error de API ({$response->status()})</h1><pre>" . htmlspecialchars($response->body()) . "</pre>", $response->status())->header('Content-Type', 'text/html');
        } catch (\Exception $e) {
            return response('<h1>Error Interno</h1><p>' . $e->getMessage() . '</p>', 500)->header('Content-Type', 'text/html');
        }
    }

    public function enviarCorreoComplemento(Request $request, $id)
    {
        // 1. Validamos que nos manden un arreglo de correos (gracias a vue-multiselect)
        $request->validate([
            'correos'   => 'required|array|min:1',
            'correos.*' => 'email' // Valida que cada elemento del arreglo sea un email válido
        ]);

        try {
            $complemento = ComplementoPago::where('ingreso_conciliado_id', $id)->latest()->first();
            $ingreso = IngresoConciliado::find($id);

            if (!$complemento || !$complemento->folio) {
                return response()->json(['error' => 'No se encontró el Complemento para este ingreso.'], 404);
            }

            // Guardamos el arreglo de correos
            $correosDestino = $request->correos;

            $folio = (int) $complemento->folio;

            // ==========================================
            // 2. PAYLOADS PARA SOLICITAR PDF Y XML
            // ==========================================
            $payloadPdf = [
                '$type' => 'GenerarDocumentoDigitalRequest',
                'model' => [
                    'llaveDocumento' => [
                        'conceptoCodigo' => '100014.0',
                        'serie'          => 'CP',
                        'folio'          => $folio
                    ]
                ],
                'options' => [
                    'tipo' => 'Pdf',
                    'nombrePlantilla' => 'REP Intactics.rdl'
                ]
            ];

            $payloadXml = [
                '$type' => 'GenerarDocumentoDigitalRequest',
                'model' => [
                    'llaveDocumento' => [
                        'conceptoCodigo' => '100014.0',
                        'serie'          => 'CP',
                        'folio'          => $folio
                    ]
                ],
                'options' => [
                    'tipo' => 'Xml',
                    'nombrePlantilla' => ''
                ]
            ];

            // ==========================================
            // 3. REALIZAMOS LAS PETICIONES A LA API
            // ==========================================
            $responsePdf = Http::withoutVerifying()
                ->timeout(60)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadPdf);

            $responseXml = Http::withoutVerifying()
                ->timeout(60)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadXml);

            if (!$responsePdf->successful() || !$responseXml->successful()) {
                Log::error("Fallo API Contpaqi al obtener archivos para Complemento ID: {$id}");
                return response()->json(['error' => 'Error al comunicarse con Contpaqi para obtener los documentos.'], 500);
            }

            $dataPdf = $responsePdf->json();
            $dataXml = $responseXml->json();

            // Extraemos los Base64 de la ruta correcta
            $pdfBase64 = $dataPdf['raw']['response']['contpaqiResponse']['model']['documentoDigital']['contenido'] ?? null;
            $xmlBase64 = $dataXml['raw']['response']['contpaqiResponse']['model']['documentoDigital']['contenido'] ?? null;

            if (!$pdfBase64 || !$xmlBase64) {
                return response()->json(['error' => 'No se pudo obtener el contenido del documento PDF o XML desde el PAC.'], 404);
            }

            // Decodificamos a binario para poder adjuntarlos "al vuelo"
            $pdfDecoded = base64_decode($pdfBase64);
            $xmlDecoded = base64_decode($xmlBase64);

            // ==========================================
            // 4. ARMAMOS VARIABLES PARA LA VISTA BLADE Y MAIL
            // ==========================================
            $variableEmpresa = $request->input('sucursal', '');

            if (empty($variableEmpresa) && $ingreso) {
                $variableEmpresa = $ingreso->sucursal_origen ?? '';
            }

            $nombreEmpresaEmisora = 'InTactics';

            if (str_contains(strtoupper($variableEmpresa), 'TRANSPORTACTICS')) {
                $nombreEmpresaEmisora = 'Transportactics';
            } elseif (str_contains(strtoupper($variableEmpresa), 'INTSHIPPERTS')) {
                $nombreEmpresaEmisora = 'INTSHIPPERTS';
            }

            if (app()->environment('production')) {
                $remitente = auth()->user();
            } else {
                $remitente = User::find(3);
            }

            $nombreRemitente = $remitente->nombre ?? $remitente->name ?? 'Usuario No Identificado';
            $apellidosRemitente = $remitente->apellidos ?? $remitente->last_name ?? '';
            $nombreCompletoRemitente = trim($nombreRemitente . ' ' . $apellidosRemitente);

            $nombreArchivoFirma = ($remitente && $remitente->signature_image) ? $remitente->signature_image : 'firma_generica.png';
            $urlFirmaLista = "https://sistema.intactics.com/v3/storage/firmas_usuarios/{$nombreArchivoFirma}?v=" . time();

            // ==========================================
            // BLINDAJE PARA OBTENER EL NOMBRE DEL CLIENTE
            // ==========================================
            $nombreClienteFinal = 'Estimado Cliente';

            if ($ingreso) {
                // 1. Si existe un cliente_id, lo buscamos en el catálogo de clientes
                if (!empty($ingreso->cliente_id)) {
                    $clienteDB = DB::table('empresas')->where('id', $ingreso->cliente_id)->first();

                    if ($clienteDB && !empty($clienteDB->nombre)) {
                        $nombreClienteFinal = $clienteDB->nombre;
                    } else {
                        // Fallback: Si el ID existe pero no se encontró, leemos el texto crudo
                        $nombreClienteFinal = $ingreso->getRawOriginal('cliente') ?: 'Estimado Cliente';
                    }
                }
                // 2. Si NO hay cliente_id (ingreso manual), tomamos la columna de texto cruda
                else {
                    $nombreClienteFinal = $ingreso->getRawOriginal('cliente') ?: 'Estimado Cliente';
                }
            }

            $datosCorreo = [
                'folioDocumento'     => 'CP' . '-' . $folio,
                'fechaDocumento'     => $complemento->fecha ? Carbon::parse($complemento->fecha)->format('d-m-Y') : date('d-m-Y'),
                'nombreCliente'      => $nombreClienteFinal,
                'referencia'         => $ingreso ? ($ingreso->folio_sc ?? 'N/A') : 'N/A',
                'empresaEmisora'     => $nombreEmpresaEmisora,
                'sucursalPura'       => $variableEmpresa,
                'urlFirma'           => $urlFirmaLista,
                'nombreUsuarioDebug' => $nombreCompletoRemitente,
                'emailUsuario'       => $remitente->email ?? 'no-reply@intactics.com'
            ];

            // ==========================================
            // 5. ENVIAMOS EL CORREO A TRAVÉS DE LA CLASE
            // ==========================================
            Log::info("Enviando Complemento de Pago (ID: {$id}) a destinatarios.", ['correos' => $correosDestino]);

            if (app()->environment('production')) {
                // En producción, enviamos a los correos reales
                $correosDestino = $request->correos;
                // Llamamos a la clase Mailable y agregamos copia a Finanzas
                Mail::to($correosDestino)
                    ->cc('finanzas@intactics.com')
                    ->send(new ComplementoPagoMail($ingreso, $datosCorreo, $pdfDecoded, $xmlDecoded));
            } else {
                // En desarrollo, enviamos a un correo de prueba para evitar envíos masivos
                $correosDestino = 'carlos.perez@intactics.com';
                // Llamamos a la clase Mailable y agregamos copia a Finanzas
                Mail::to($correosDestino)
                    ->send(new ComplementoPagoMail($ingreso, $datosCorreo, $pdfDecoded, $xmlDecoded));
            }


            if ($ingreso) {
                $ingreso->estado_envio = 'ENVIADO';
                $ingreso->save();
            }

            return response()->json([
                'success' => true,
                'message' => "El correo con los archivos PDF y XML ha sido enviado exitosamente a todos los destinatarios."
            ]);
        } catch (\Exception $e) {
            Log::error("ERROR al enviar complemento (ID: {$id}): " . $e->getMessage(), [
                'archivo' => $e->getFile(),
                'linea' => $e->getLine()
            ]);
            return response()->json(['error' => 'Ocurrió un error al enviar el correo. Revisa los logs para más detalles.'], 500);
        }
    }
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $sucursal = strtoupper($request->sucursal_origen ?? '');
            $clienteId = $request->cliente_id;
            $clienteManual = null;
            $clienteNombre = '';

            if (empty($clienteId) && $request->filled('nuevo_cliente_nombre')) {
                // Es un cliente escrito manualmente (no existe en la tabla empresas)
                $clienteManual = strtoupper(trim($request->nuevo_cliente_nombre));
                $clienteNombre = $clienteManual; // Lo usamos para validar Transportactics abajo
            } else {
                // Es un cliente del catálogo normal
                $empresa = Empresas::find($clienteId);
                $clienteNombre = $empresa ? strtoupper($empresa->nombre) : '';
            }

            // Validaciones de negocio
            $isTransportactics = str_contains($clienteNombre, 'TRANSPORTACTICS') || str_contains($sucursal, 'TRANSPORTACTIC');
            $esManzanillo = str_contains($sucursal, 'MANZANILLO') || str_contains($sucursal, 'INTSHIPPERT');

            // BLINDAJE: Limpiamos y forzamos a que sean números reales
            $fleteReal = (float) str_replace(['$', ','], '', $request->flete ?? 0);
            $pagoProvReal = (float) str_replace(['$', ','], '', $request->pago_proveedor ?? 0);
            $honorariosReal = (float) str_replace(['$', ','], '', $request->honorarios ?? 0);

            $gananciaFrontend = $request->ganancia ?? $request->ganancias ?? 0;
            $gananciaReal = (float) str_replace(['$', ','], '', $gananciaFrontend);

            $monto_gpc = 0;

            if ($isTransportactics) {
                $monto_gpc = 0; // En transportactics todo es CFDI
            } elseif ($esManzanillo) {
                $monto_gpc = (float)($request->anticipo ?? 0) + (float)($request->garantias ?? 0) +
                    (float)($request->desglose_naviera ?? 0) + (float)($request->impuestos ?? 0) + $fleteReal;
            } else {
                $monto_gpc = (float)($request->impuestos ?? 0) + (float)($request->eci ?? 0) +
                    (float)($request->maniobras ?? 0) + $fleteReal +
                    (float)($request->muestras ?? 0) + (float)($request->llc ?? 0);
            }

            $ingreso = new IngresoConciliado();
            $ingreso->sucursal_origen = $request->sucursal_origen;
            $ingreso->banco_receptor = $request->banco_receptor;
            $ingreso->fecha = $request->fecha;

            // ASIGNACIÓN DEL CLIENTE
            $ingreso->cliente_id = $clienteId;
            $ingreso->cliente = $clienteManual;

            $ingreso->monto_deposito = (float) str_replace(['$', ','], '', $request->monto_deposito ?? 0);
            $ingreso->total_gpc = $monto_gpc;

            // Resto de campos
            $ingreso->impuestos = $request->impuestos ?? 0;
            $ingreso->eci = $request->eci ?? 0;
            $ingreso->maniobras = $request->maniobras ?? 0;
            $ingreso->flete = $fleteReal;
            $ingreso->muestras = $request->muestras ?? 0;
            $ingreso->llc = $request->llc ?? 0;
            $ingreso->anticipo = $request->anticipo ?? 0;
            $ingreso->garantias = $request->garantias ?? 0;
            $ingreso->desglose_naviera = $request->desglose_naviera ?? 0;
            $ingreso->pago_proveedor = $pagoProvReal;

            $ingreso->honorarios = $honorariosReal;

            $ingreso->metodo_pago = $request->metodo_pago ?? 'PUE';

            if ($isTransportactics) {
                $ingreso->ganancia = $fleteReal - $pagoProvReal;
            } else {
                $ingreso->ganancia = $gananciaReal;
            }

            // Textos
            $textoLibre = $request->folio_sc ?? $request->referencia;
            $ingreso->folio_sc = $textoLibre;
            $ingreso->referencia = $textoLibre;
            $ingreso->tipo_comprobante = $request->tipo_comprobante;

            $ingreso->save();

            // ==========================================
            // 2. GUARDAR EL PIVOTE (OPERACIONES)
            // ==========================================
            // 1. Validamos que el request traiga operaciones
            if ($request->has('operaciones') && is_array($request->operaciones)) {
                DB::table('ingreso_operacion')->where('ingreso_id', $ingreso->id)->delete();

                $pivotData = [];
                $totalElementos = count($request->operaciones); // Incluye reales y genéricas

                // 1. Sumatoria de Flete para Transportactics
                $sumaFleteXml = 0;
                foreach ($request->operaciones as $op) {
                    $sumaFleteXml += (float) ($op['monto_cfdi'] ?? 0);
                }
                $totalFlete = $sumaFleteXml > 0 ? $sumaFleteXml : 1;

                // 2. División de Anticipo para Manzanillo
                $esManzanillo = str_contains(strtoupper($ingreso->sucursal_origen), 'MANZANILLO') ||
                    str_contains(strtoupper($ingreso->sucursal_origen), 'ZLO');

                $anticipoGlobal = (float) ($request->anticipo ?? 0);
                $anticipoUnitario = 0;

                if ($esManzanillo && $totalElementos > 0 && $anticipoGlobal > 0) {
                    $anticipoUnitario = round($anticipoGlobal / $totalElementos, 2);
                }

                // 3. Inserción en Pivote (Admite con o sin operacion_id)
                foreach ($request->operaciones as $op) {
                    $fleteOperacion = (float) ($op['monto_cfdi'] ?? 0);
                    $montoGpcVal    = (float) ($op['monto_gpc'] ?? 0);
                    $montoCfdiFinal = $fleteOperacion;

                    // Cálculo proporcional exclusivo para Transportactics
                    if ($isTransportactics && $totalFlete > 0) {
                        $proporcion = $fleteOperacion / $totalFlete;
                        $montoCfdiFinal = $fleteOperacion - ($pagoProvReal * $proporcion);
                    }

                    $folioTexto = $op['referencia'] ?? $op['folio'] ?? $op['label'] ?? (is_string($op) ? $op : null);
                    $opId = (isset($op['id']) && is_numeric($op['id'])) ? $op['id'] : null;
                    $opType = $op['type'] ?? $op['operacion_type'] ?? 'GENERICO';

                    $pivotData[] = [
                        'ingreso_id'     => $ingreso->id,
                        'operacion_id'   => $opId,
                        'operacion_type' => $opType,
                        'monto_cfdi'     => round($montoCfdiFinal, 2),
                        'monto_gpc'      => round($montoGpcVal, 2), // 🔥 Se guarda el valor devuelto
                        'referencia'     => $folioTexto,
                        'anticipo'       => $anticipoUnitario,
                        'created_at'     => now(),
                        'updated_at'     => now()
                    ];
                }

                if (!empty($pivotData)) {
                    DB::table('ingreso_operacion')->insert($pivotData);
                }
            }

            // ==========================================
            // 3. CÁLCULO Y REGISTRO AUTOMÁTICO DE SALDO
            // ==========================================
            $costoTotal = $ingreso->total_gpc + $ingreso->honorarios;
            $diferencia = round($ingreso->monto_deposito - $costoTotal, 2);

            $costoTotal = $ingreso->total_gpc + $ingreso->honorarios;
            $diferencia = round($ingreso->monto_deposito - $costoTotal, 2);

            if ($diferencia !== 0.00) {
                // 1. Identificamos el tipo de documento exacto
                $tipoDoc = 'CFDI o GPC'; // Valor por defecto si son 'Ambos' o está vacío

                if ($ingreso->tipo_comprobante === 'CFDI') {
                    $tipoDoc = 'CFDI';
                } elseif ($ingreso->tipo_comprobante === 'Nota Cargo') {
                    $tipoDoc = 'GPC'; // Nota de cargo equivale a los Gastos Por Comprobar
                }

                // 2. Definimos el texto dependiendo de la diferencia y el documento
                $textoObservacion = $diferencia > 0
                    ? "Pago de más en {$tipoDoc}"
                    : "Pago de menos en {$tipoDoc}";

                // 3. Extraemos la referencia base
                $referenciaBase = $ingreso->referencia ?? $ingreso->folio_sc ?? 'Sin Referencia';

                // 4. Unimos ambos textos para guardarlos en la columna 'concepto'
                $conceptoFinal = $referenciaBase . ' - ' . $textoObservacion;

                SaldoFavor::updateOrCreate(
                    ['ingreso_conciliado_id' => $ingreso->id],
                    [
                        'cliente_id'      => $ingreso->cliente_id,
                        'cliente'         => $ingreso->cliente,
                        'sucursal_origen' => $ingreso->sucursal_origen,
                        'monto'           => $diferencia,
                        'fecha_deteccion' => $ingreso->fecha,
                        'concepto'        => $conceptoFinal
                    ]
                );
            } else {
                // Si ahora quedaron en ceros, eliminamos el saldo si es que existía
                SaldoFavor::where('ingreso_conciliado_id', $ingreso->id)->delete();
            }

            DB::commit();
            return response()->json(['message' => 'Ingreso guardado exitosamente', 'data' => $ingreso]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al guardar: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // 1. Encontrar el ingreso que estamos editando
            $ingreso = IngresoConciliado::findOrFail($id);

            // Actualizar los campos principales
            $ingreso->sucursal_origen = $request->sucursal_origen ?? $ingreso->sucursal_origen;
            $ingreso->banco_receptor = $request->banco_receptor ?? $ingreso->banco_receptor;
            $ingreso->fecha = $request->fecha ?? $ingreso->fecha;

            $clienteNombre = '';

            if (empty($request->cliente_id) && $request->filled('nuevo_cliente_nombre')) {
                // A. Es un cliente escrito manualmente
                $ingreso->cliente_id = null;
                $ingreso->cliente = strtoupper(trim($request->nuevo_cliente_nombre));
                $clienteNombre = $ingreso->cliente;
            } elseif ($request->has('cliente_id') && !empty($request->cliente_id)) {
                // B. Es un cliente válido del catálogo
                $ingreso->cliente_id = $request->cliente_id;
                $ingreso->cliente = null;

                $empresa = Empresas::find($ingreso->cliente_id);
                $clienteNombre = $empresa ? strtoupper($empresa->nombre) : '';
            } else {
                // C. Si no mandaron cambios de cliente, usamos lo que ya tiene la BD
                if ($ingreso->cliente_id) {
                    $empresa = Empresas::find($ingreso->cliente_id);
                    $clienteNombre = $empresa ? strtoupper($empresa->nombre) : '';
                } else {
                    $clienteNombre = $ingreso->cliente ?? '';
                }
            }

            // Identificar si es Transportactics
            $isTransportactics = str_contains($clienteNombre, 'TRANSPORTACTICS') || str_contains(strtoupper($ingreso->sucursal_origen), 'TRANSPORTACTIC');

            // BLINDAJE: Limpiamos y forzamos a que sean números reales
            $fleteReal = (float) str_replace(['$', ','], '', $request->flete ?? $ingreso->flete);
            $pagoProvReal = (float) str_replace(['$', ','], '', $request->pago_proveedor ?? $ingreso->pago_proveedor);
            $honorariosReal = (float) str_replace(['$', ','], '', $request->honorarios ?? $ingreso->honorarios);

            $gananciaFrontend = $request->ganancia ?? $request->ganancias ?? $ingreso->ganancia;
            $gananciaReal = (float) str_replace(['$', ','], '', $gananciaFrontend);

            $ingreso->monto_deposito = (float) str_replace(['$', ','], '', $request->monto_deposito ?? $ingreso->monto_deposito);
            $ingreso->total_gpc = $request->total_gpc ?? $ingreso->total_gpc;

            $ingreso->metodo_pago = $request->metodo_pago ?? 'PUE';

            $ingreso->impuestos = $request->impuestos ?? $ingreso->impuestos;
            $ingreso->eci = $request->eci ?? $ingreso->eci;
            $ingreso->maniobras = $request->maniobras ?? $ingreso->maniobras;
            $ingreso->flete = $fleteReal;
            $ingreso->muestras = $request->muestras ?? $ingreso->muestras;
            $ingreso->llc = $request->llc ?? $ingreso->llc;

            // CANDADO: ALMACENADORAS Y MAQUILAS es exento de anticipo
            if (str_contains($clienteNombre, 'ALMACENADORA')) {
                $ingreso->anticipo = 0;
            } else {
                $ingreso->anticipo = (float) ($request->anticipo ?? $ingreso->anticipo ?? 0);
            }

            $ingreso->garantias = $request->garantias ?? $ingreso->garantias;
            $ingreso->desglose_naviera = $request->desglose_naviera ?? $ingreso->desglose_naviera;
            $ingreso->pago_proveedor = $pagoProvReal;

            $ingreso->honorarios = $honorariosReal;

            if ($isTransportactics) {
                $ingreso->ganancia = $fleteReal - $pagoProvReal;
            } else {
                $ingreso->ganancia = $gananciaReal;
            }

            // TEXTOS: Priorizamos $request->referencia enviada desde Vue
            $textoLibre = $request->referencia ?? $request->folio_sc ?? $ingreso->referencia;
            $ingreso->referencia = $textoLibre;
            $ingreso->folio_sc = $textoLibre;
            $ingreso->tipo_comprobante = $request->tipo_comprobante ?? $ingreso->tipo_comprobante;

            $ingreso->save();

            // Insertamos las operaciones actualizadas en el pivote
            if ($request->has('operaciones') && is_array($request->operaciones)) {
                DB::table('ingreso_operacion')->where('ingreso_id', $ingreso->id)->delete();

                $pivotData = [];
                $totalElementos = count($request->operaciones);

                // 1. Sumatoria de Flete para Transportactics
                $sumaFleteXml = 0;
                foreach ($request->operaciones as $op) {
                    $sumaFleteXml += (float) ($op['monto_cfdi'] ?? 0);
                }
                $totalFlete = $sumaFleteXml > 0 ? $sumaFleteXml : 1;

                // 2. División de Anticipo para Manzanillo
                $esManzanillo = str_contains(strtoupper($ingreso->sucursal_origen), 'MANZANILLO') ||
                    str_contains(strtoupper($ingreso->sucursal_origen), 'ZLO');

                // Toma el anticipo definitivo ya procesado del modelo $ingreso
                $anticipoGlobal = (float) $ingreso->anticipo;
                $anticipoUnitario = 0;

                if ($esManzanillo && $totalElementos > 0 && $anticipoGlobal > 0) {
                    $anticipoUnitario = round($anticipoGlobal / $totalElementos, 2);
                }

                // 3. Inserción en Pivote
                foreach ($request->operaciones as $op) {
                    $fleteOperacion = (float) ($op['monto_cfdi'] ?? $op['flete'] ?? 0);
                    $montoGpcVal    = (float) ($op['monto_gpc'] ?? $op['total_gpc'] ?? 0);
                    $montoCfdiFinal = $fleteOperacion;

                    if ($isTransportactics && $totalFlete > 0) {
                        $proporcion = $fleteOperacion / $totalFlete;
                        $montoCfdiFinal = $fleteOperacion - ($pagoProvReal * $proporcion);
                    }

                    $folioTexto = $op['referencia'] ?? $op['folio'] ?? $op['label'] ?? (is_string($op) ? $op : null);

                    $opId = null;
                    if (isset($op['id']) && is_numeric($op['id'])) {
                        $opId = (int) $op['id'];
                    } elseif (isset($op['operacion_id']) && is_numeric($op['operacion_id'])) {
                        $opId = (int) $op['operacion_id'];
                    }

                    $opType = $op['type'] ?? $op['operacion_type'] ?? $op['operation_type'] ?? 'GENERICO';

                    $pivotData[] = [
                        'ingreso_id'     => $ingreso->id,
                        'operacion_id'   => $opId,
                        'operacion_type' => $opType,
                        'monto_cfdi'     => round($montoCfdiFinal, 2),
                        'monto_gpc'      => round($montoGpcVal, 2),
                        'referencia'     => $folioTexto,
                        'anticipo'       => $anticipoUnitario,
                        'created_at'     => now(),
                        'updated_at'     => now()
                    ];
                }

                if (!empty($pivotData)) {
                    DB::table('ingreso_operacion')->insert($pivotData);
                }
            }

            // ==========================================
            // CÁLCULO Y REGISTRO AUTOMÁTICO DE SALDO
            // ==========================================
            $costoTotal = $ingreso->total_gpc + $ingreso->honorarios;
            $diferencia = round($ingreso->monto_deposito - $costoTotal, 2);

            if ($diferencia !== 0.00) {
                $tipoDoc = 'CFDI o GPC';

                if ($ingreso->tipo_comprobante === 'CFDI') {
                    $tipoDoc = 'CFDI';
                } elseif ($ingreso->tipo_comprobante === 'Nota Cargo') {
                    $tipoDoc = 'GPC';
                }

                $textoObservacion = $diferencia > 0
                    ? "Pago de más en {$tipoDoc}"
                    : "Pago de menos en {$tipoDoc}";

                $referenciaBase = $ingreso->referencia ?? $ingreso->folio_sc ?? 'Sin Referencia';
                $conceptoFinal = $referenciaBase . ' - ' . $textoObservacion;

                SaldoFavor::updateOrCreate(
                    ['ingreso_conciliado_id' => $ingreso->id],
                    [
                        'cliente_id'      => $ingreso->cliente_id,
                        'cliente'         => $ingreso->cliente,
                        'sucursal_origen' => $ingreso->sucursal_origen,
                        'monto'           => $diferencia,
                        'fecha_deteccion' => $ingreso->fecha,
                        'concepto'        => $conceptoFinal
                    ]
                );
            } else {
                SaldoFavor::where('ingreso_conciliado_id', $ingreso->id)->delete();
            }

            DB::commit();
            return response()->json(['message' => 'Ingreso actualizado exitosamente', 'data' => $ingreso]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        IngresoConciliado::destroy($id);
        return response()->json(['success' => true]);
    }

    // =====================================================================
    // MÉTODOS PARA: SALDOS A FAVOR
    // =====================================================================

    public function indexSaldos()
    {
        $saldos = SaldoFavor::join('empresas', 'saldos_favor.cliente_id', '=', 'empresas.id')
            ->select('saldos_favor.*', 'empresas.nombre as cliente')
            ->orderBy('saldos_favor.created_at', 'desc')
            ->get();

        return response()->json($saldos);
    }

    public function storeSaldo(Request $request)
    {
        $request->validate([
            'cliente_id' => 'required|integer',
            'monto' => 'required|numeric',
            'sucursal' => 'required|string'
        ]);

        // Whitelisting: solo tomamos los campos que el modelo espera para evitar
        // que cualquier key extra en el request rompa el INSERT (SaldoFavor tiene
        // $guarded = [] así que cualquier campo se intenta insertar como columna).
        $data = $request->only(['cliente_id', 'monto', 'sucursal', 'fecha_deteccion', 'concepto']);

        $data['sucursal_origen'] = $data['sucursal'];
        unset($data['sucursal']);

        $data['fecha_deteccion'] = $data['fecha_deteccion'] ?? now()->toDateString();
        $data['estatus'] = 'VIGENTE';

        $saldo = SaldoFavor::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Saldo a favor registrado correctamente',
            'data' => $saldo
        ]);
    }

    public function marcarAplicadoSaldo($id)
    {
        $saldo = SaldoFavor::findOrFail($id);
        $saldo->estatus = 'APLICADO';
        $saldo->save();

        return response()->json(['success' => true]);
    }

    public function reactivarSaldo($id)
    {
        $saldo = SaldoFavor::findOrFail($id);
        $saldo->estatus = 'VIGENTE';
        $saldo->save();

        return response()->json(['success' => true]);
    }

    public function updateSaldo(Request $request, $id)
    {
        $saldo = SaldoFavor::findOrFail($id);

        // Whitelisting: solo tomamos los campos que el modelo espera para evitar
        // que cualquier key extra en el request rompa el UPDATE (SaldoFavor tiene
        // $guarded = [] así que cualquier campo se intenta actualizar como columna).
        $data = $request->only(['cliente_id', 'monto', 'sucursal', 'fecha_deteccion', 'concepto']);

        if ($request->has('sucursal')) {
            $data['sucursal_origen'] = $data['sucursal'];
            unset($data['sucursal']);
        }

        $saldo->update($data);

        return response()->json(['success' => true]);
    }
    public function notificarCliente(Request $request, $id)
    {
        // 1. Buscamos el saldo con su cliente
        $saldo = SaldoFavor::with('cliente')->findOrFail($id);

        // Tomamos siempre al usuario logueado en el sistema
        if (app()->environment('production')) {
            $remitente = auth()->user();
        } else {
            $remitente = User::find(3);
        }

        // Hacemos un pequeño truco por si tu base de datos usa "name" en lugar de "nombre"
        $nombreRemitente = $remitente->nombre ?? $remitente->name ?? '';
        $apellidosRemitente = $remitente->apellidos ?? $remitente->last_name ?? '';

        $nombreUsuarioLogueado = $remitente ? trim($nombreRemitente . ' ' . $apellidosRemitente) : 'Usuario No Identificado';

        $nombreArchivoFirma = ($remitente && $remitente->signature_image) ? $remitente->signature_image : 'firma_generica.png';
        $urlFirmaLista = "https://sistema.intactics.com/v3/storage/firmas_usuarios/{$nombreArchivoFirma}?v=" . time();

        // Empaquetamos los datos de la firma y el debug para la vista
        $datosFirma = [
            'urlFirma'           => $urlFirmaLista,
            'nombreUsuarioDebug' => $nombreUsuarioLogueado,
            'emailUsuario'       => $remitente->email ?? 'no-reply@intactics.com'
        ];

        // 2. Recibimos los correos desde el SweetAlert (frontend)
        $correosString = $request->input('correos');

        // (Protección) Si por alguna razón llega vacío, usamos el del cliente por defecto
        if (empty($correosString) && $saldo->cliente) {
            $correosString = $saldo->cliente->email;
        }

        // 3. Convertimos el string separado por comas en un arreglo
        $destinatarios = array_map('trim', explode(',', $correosString));
        $destinatarios = array_filter($destinatarios); // Quitamos espacios en blanco accidentales

        if (empty($destinatarios)) {
            Log::warning("Intento de envío fallido: No hay destinatarios válidos para el Saldo a Favor ID {$id}.");
            return response()->json(['error' => 'No hay correos válidos para enviar'], 400);
        }

        // ==========================================
        // 4. INICIO DE LOGS Y ENVÍO DE CORREO
        // ==========================================

        Log::info("Iniciando envío de correo de saldo a favor (ID: {$id}).", [
            'cliente'       => $saldo->cliente->nombre ?? 'Desconocido',
            'destinatarios' => $destinatarios,
            'emailUsuario'  => $remitente->email ?? 'no-reply@intactics.com'
        ]);
        Log::info('Datos empaquetados para la vista del correo:', $datosFirma);

        try {
            // Intentamos enviar el correo a la lista de destinatarios
            Mail::to($destinatarios)->send(new NotificacionSaldoFavorMail($saldo, $datosFirma));

            // Si llega a esta línea, significa que Laravel entregó el correo al servidor SMTP con éxito
            Log::info("EXITO: El correo de saldo a favor (ID: {$id}) fue procesado y enviado a los destinatarios correctamente.");

            return response()->json([
                'success' => true,
                'message' => 'Correo enviado correctamente'
            ]);
        } catch (\Exception $e) {
            // Si el servidor de correos falla (credenciales inválidas, rechazo de spam, etc.), lo atrapamos aquí
            Log::error("ERROR CRÍTICO al enviar correo de saldo a favor (ID: {$id}): " . $e->getMessage(), [
                'destinatarios' => $destinatarios,
                'archivo_error' => $e->getFile(),
                'linea_error' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'El servidor de correos rechazó el envío. Revisa los logs de Laravel para más detalles.'
            ], 500);
        }
    }
    public function destroySaldo($id)
    {
        SaldoFavor::destroy($id);
        return response()->json(['success' => true]);
    }
}
