<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IngresoConciliado;
use App\Models\Empresas;
use App\Models\Sucursales;
use App\Models\SaldoFavor;
use App\Models\ComplementoPago;
use App\Mail\NotificacionSaldoFavorMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;

class IngresoConciliadoController extends Controller
{
    // =====================================================================
    // MÉTODOS PARA: INGRESOS CONCILIADOS
    // =====================================================================

    // 1. Enviar los catálogos al frontend (Modal y Filtros)
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

        return response()->json([
            'clientes'       => $clientes,
            'sucursales'     => $sucursales,
            'sucursalesBase' => $sucursalesBase,
            'bancos'         => $bancos,
        ]);
    }

    // 2. Traer los datos para mostrarlos en la tabla principal de Vue
    public function index(Request $request)
    {
        $query = IngresoConciliado::select('ingresos_conciliados.*', 'empresas.nombre as cliente')
            ->join('empresas', 'ingresos_conciliados.cliente_id', '=', 'empresas.id')
            // Usamos un addSelect con subconsulta para no duplicar filas si hay varios complementos
            ->addSelect([
                'folio_complemento' => ComplementoPago::select('folio')
                    ->whereColumn('ingreso_conciliado_id', 'ingresos_conciliados.id')
                    ->orderBy('created_at', 'desc')
                    ->limit(1)
            ]);

        if ($request->filled('tipo_comprobante') && $request->tipo_comprobante !== 'Todos') {
            $query->where('ingresos_conciliados.tipo_comprobante', $request->tipo_comprobante);
        }

        $ingresos = $query->orderBy('ingresos_conciliados.created_at', 'desc')->get();

        return response()->json($ingresos);
    }

    public function listarPedimentosSheet(Request $request)
    {
        $sucursal = strtoupper(trim($request->input('sucursal', '')));

        if (empty($sucursal)) {
            return response()->json([]);
        }

        $esManzanillo = str_contains($sucursal, 'MANZANILLO') || str_contains($sucursal, 'INTSHIPPERT');

        // Configuramos la hoja según la sucursal (Usamos "sheet" en lugar de "gid" para que sea dinámico)
        if ($esManzanillo) {
            $sheetId = app()->environment('production') ? '18-5okzV-vw35V0Ugjn5KjNcWgHyZ9Qfc6pf5w4VU-2I' : '1zHUYpViLZyu_KPkNCUEx37WjoK0lVt7F0bC1B9Jo8s0';
            $nombrePestanaCodificado = 'ZLO';
        } else {
            $sheetId = app()->environment('production') ? '1FvhWp2AeOyoiv1KIrmQNOKf9ZoDRy5L7HVd5FcRQBio' : '17hBoRx5u5jxi2hqHiC98zX-3lqF0w5tTkHBzElCHwDM';
            $nombrePestanaCodificado = rawurlencode($sucursal);
        }

        // Se usa 'sheet=' para que Google Sheets busque la pestaña por nombre automáticamente
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

            // Posiciones por defecto
            $idxCliente = 1;
            $idxPedimento = 2;

            // Sabueso para buscar cabeceras
            foreach ($rows as $cols) {
                foreach ($cols as $i => $col) {
                    $txt = strtolower(trim($col));
                    if (str_contains($txt, 'cliente')) {
                        $idxCliente = $i;
                    }
                    if (str_contains($txt, 'pedimento') || str_contains($txt, 'folio')) {
                        $idxPedimento = $i;
                    }
                }
                break;
            }

            // Recorremos las filas
            foreach ($rows as $index => $cols) {
                if ($index === 0) {
                    continue;
                }

                $clienteCelda = trim($cols[$idxCliente] ?? '');
                $pedimentoCelda = trim($cols[$idxPedimento] ?? '');

                if ($pedimentoCelda !== '' && $clienteCelda !== '') {
                    // Evitar incluir encabezados repetidos
                    if (strtoupper($clienteCelda) === 'CLIENTE' || strtoupper($pedimentoCelda) === 'PEDIMENTO') {
                        continue;
                    }

                    $pedimentos[] = [
                        'label' => $pedimentoCelda . ' - ' . $clienteCelda,
                        'folio' => $pedimentoCelda,
                        'cliente' => strtoupper($clienteCelda)
                    ];
                }
            }

            // Eliminamos duplicados
            $pedimentos = collect($pedimentos)->unique('folio')->values()->all();

            return response()->json($pedimentos);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Excepción: ' . $e->getMessage()], 500);
        }
    }

    public function buscarEnSheet(Request $request)
    {
        $terminosBuscados = $request->input('pedimentos', []);
        $sucursalBuscada = strtoupper(trim($request->input('sucursal')));
        $tiposComprobante = $request->input('tipo_comprobante', []);

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
                    'operaciones' => []
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

                        if ($quiereNotaCargo) {
                            if (str_contains($concepto, 'IMPUEST')) {
                                $excelImpuestos += $monto;
                            } elseif (str_contains($concepto, 'ANTICIPO')) {
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

                    $pedimentoDB = \Illuminate\Support\Facades\DB::table('pedimiento')
                        ->where('num_pedimiento', 'LIKE', "%{$pedimentoBusqueda}%")
                        ->orderBy('id_pedimiento', 'desc')
                        ->first();

                    if ($pedimentoDB) {
                        $encontroAlgo = true;

                        if (!in_array($pedimentoReal, $resultados['pedimento_detectado'])) {
                            $resultados['pedimento_detectado'][] = $pedimentoReal;
                        }

                        $impo = \Illuminate\Support\Facades\DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
                        $expo = \Illuminate\Support\Facades\DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

                        $idOp = null;
                        $tipoApi = null;
                        $idPadre = null;

                        if ($impo) {
                            $idOp = $impo->id_importacion;
                            $tipoApi = 'importaciones';
                            $idPadre = $impo->parent ?? null;
                            $resultados['operaciones'][] = ['id' => $idOp, 'type' => 'App\Models\OperacionImportacion'];
                        } elseif ($expo) {
                            $idOp = $expo->id_exportacion;
                            $tipoApi = 'exportaciones';
                            $idPadre = $expo->parent ?? null;
                            $resultados['operaciones'][] = ['id' => $idOp, 'type' => 'App\Models\OperacionExportacion'];
                        }

                        if ($idOp) {
                            if ($quiereNotaCargo) {
                                $urlPrefactura = "https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$idOp}/prefacturas-momentaneo";
                                $respPrefactura = Http::withoutVerifying()->timeout(10)->get($urlPrefactura);

                                if ($respPrefactura->successful() && is_array($respPrefactura->json())) {

                                    $impuestosPref = 0;
                                    $garantiasPref = 0;
                                    $navieraPref = 0;
                                    $anticipoAcumulado = 0;
                                    $totalPrefactura = 0;
                                    $clientePrefactura = '';

                                    $todasLasDescripciones = '';

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

                                            if ($key === 'impuestos' || str_contains($titulo, 'IMPUEST')) {
                                                $impuestosPref += $totalEfectivo;
                                            } elseif ($key === 'muestra' || str_contains($titulo, 'MUESTRA')) {
                                                $navieraPref += $totalEfectivo;
                                            } elseif ($key === 'naviera' || str_contains($titulo, 'NAVIERA') || $key === 'desglose_naviera') {
                                                $navieraPref += $totalEfectivo;
                                            } elseif ($key === 'honorarios' || str_contains($titulo, 'HONORARIO')) {
                                                // Nada
                                            } else {
                                                $anticipoAcumulado += $totalEfectivo;
                                            }
                                        }
                                    }

                                    $clienteEvaluar = strtoupper(!empty($clientePrefactura) ? $clientePrefactura : ($resultados['cliente_detectado'] ?? ''));

                                    if (str_contains($clienteEvaluar, 'G Y S') || str_contains($clienteEvaluar, 'GYS')) {
                                        $mod = fmod($totalPrefactura, 100);
                                        $ajusteRedondeo = 0;
                                        if ($mod < 10) {
                                            $ajusteRedondeo = -$mod;
                                        } else {
                                            $ajusteRedondeo = (100 - $mod);
                                        }
                                        $totalPrefactura += $ajusteRedondeo;
                                        $anticipoAcumulado += $ajusteRedondeo;
                                    }

                                    $anticipoPref = 0;
                                    $clienteEvaluar = strtoupper(!empty($clientePrefactura) ? $clientePrefactura : ($resultados['cliente_detectado'] ?? ''));

                                    if (str_contains($clienteEvaluar, 'G Y S') || str_contains($clienteEvaluar, 'GYS')) {
                                        $mod = fmod($totalPrefactura, 100);
                                        $ajusteRedondeo = 0;
                                        if ($mod < 10) {
                                            $ajusteRedondeo = -$mod;
                                        } else {
                                            $ajusteRedondeo = (100 - $mod);
                                        }
                                        $totalPrefactura += $ajusteRedondeo;
                                        $anticipoAcumulado += $ajusteRedondeo;
                                    }

                                    $anticipoPref = 0;
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

                                    if ($anticipoPref > 0) {
                                        $apiAnticipo += $anticipoPref;
                                    }
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
                                        $esNombreSC = false;

                                        if (!empty($folioFacturaZlo)) {
                                            $esNombreSC = str_contains($nombreMayus, 'ZLO' . $folioFacturaZlo) || str_contains($nombreMayus, $folioFacturaZlo);
                                        } else {
                                            $esNombreSC = str_contains($nombreMayus, $pedimentoBusqueda) || (str_contains($nombreMayus, 'ZLO') && !str_contains($nombreMayus, 'PEDIMENTO'));
                                        }

                                        if ($ext === 'xml' && ($esTipoSC || $esNombreSC)) {
                                            $urlXmlReal = $archivo['url']['normal'] ?? null;
                                            break;
                                        }
                                    }

                                    if ($urlXmlReal && method_exists($this, 'extraerHonorariosAgenciaXML')) {
                                        $resultadoXML = $this->extraerHonorariosAgenciaXML($urlXmlReal);
                                        if ($resultadoXML['honorarios'] > 0) {
                                            $apiHonorarios += $resultadoXML['honorarios'];
                                        }
                                        if (!empty($resultadoXML['folio'])) {
                                            $resultados['folio_sc'][] = $resultadoXML['folio'];
                                        }
                                    }
                                }
                            }
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
                    $pedimentoCelda = strtoupper(trim($fila[2] ?? ''));
                    $folioCelda = strtoupper(trim($fila[8] ?? ''));

                    foreach ($terminosBuscados as $termino) {
                        $termino = strtoupper(trim($termino));
                        if ($termino === '') {
                            continue;
                        }

                        if (str_contains($pedimentoCelda, $termino) || str_contains($folioCelda, $termino)) {
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
                'proveedor_flete'     => null,
                'factura_flete'     => null,
                'proveedor_muestras'  => null,
                'factura_muestras'  => null,
                'proveedor_llc'       => null,
                'factura_llc'       => null,
                'folio_sc'            => [],
                'operacion_id'        => null,
                'operation_type'      => null,
                'pedimento_detectado' => [],
                'operaciones'         => [],
            ];

            foreach ($bloquesEncontrados as $bloqueFinal) {

                $folioFacturaEnSheet = trim($bloqueFinal[0][8] ?? '');
                $pedimentoReal = '';
                foreach ($bloqueFinal as $fila) {
                    if (!empty(trim($fila[2] ?? ''))) {
                        $pedimentoReal = strtoupper(trim($fila[2]));
                        break;
                    }
                }

                if (!empty($pedimentoReal)) {
                    $resultados['pedimento_detectado'][] = $pedimentoReal;
                }

                if (!empty($folioFacturaEnSheet)) {
                    $resultados['folio_sc'][] = $folioFacturaEnSheet;
                }

                $pedimentoDB = \Illuminate\Support\Facades\DB::table('pedimiento')
                    ->where('num_pedimiento', 'LIKE', "%{$pedimentoReal}%")
                    ->orderBy('id_pedimiento', 'desc')
                    ->first();

                $idOp = null;
                $tipoApi = null;
                $idPadre = null;

                if ($pedimentoDB) {
                    $impo = \Illuminate\Support\Facades\DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
                    $expo = \Illuminate\Support\Facades\DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

                    if ($impo) {
                        $idOp = $impo->id_importacion;
                        $tipoApi = 'importaciones';
                        $idPadre = $impo->parent ?? null;

                        $resultados['operaciones'][] = [
                            'id' => $idOp,
                            'type' => 'App\Models\OperacionImportacion'
                        ];
                    } elseif ($expo) {
                        $idOp = $expo->id_exportacion;
                        $tipoApi = 'exportaciones';
                        $idPadre = $expo->parent ?? null;

                        $resultados['operaciones'][] = [
                            'id' => $idOp,
                            'type' => 'App\Models\OperacionExportacion'
                        ];
                    }
                }

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
                        } elseif (str_contains($concepto, 'IMPUEST')) {
                            $resultados['impuestos'] += $montoLimpio;
                        } elseif (str_contains($concepto, 'ECI')) {
                            $resultados['eci'] += $montoLimpio;
                        } elseif ($esManiobra) {
                            $resultados['maniobras'] += $montoLimpio;
                            if (!empty($proveedor)) {
                                $resultados['proveedor_maniobras'] = $proveedor;
                            }
                            if (!empty($factura)) {
                                $resultados['factura_maniobras'] = $factura;
                            }
                        } elseif (str_contains($concepto, 'FLETE')) {
                            $resultados['flete'] += $montoLimpio;
                            if (!empty($proveedor)) {
                                $resultados['proveedor_flete'] = $proveedor;
                            }
                            if (!empty($factura)) {
                                $resultados['factura_flete'] = $factura;
                            }
                        } elseif (str_contains($concepto, 'MUESTRA')) {
                            $resultados['muestras'] += $montoLimpio;
                            if (!empty($proveedor)) {
                                $resultados['proveedor_muestras'] = $proveedor;
                            }
                            if (!empty($factura)) {
                                $resultados['factura_muestras'] = $factura;
                            }
                        } elseif (str_contains($concepto, 'LLC')) {
                            $resultados['llc'] += $montoLimpio;
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
                    if (!empty($folioFacturaEnSheet)) {
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
                                    $esNombreSC = str_contains($nombreMayus, $nombreBaseBuscado) || str_contains($nombreMayus, $folioLimpio);

                                    if ($ext === 'xml' && ($esTipoSC || $esNombreSC)) {
                                        $urlXmlReal = $archivo['url']['normal'] ?? null;
                                        break;
                                    }
                                }

                                if ($urlXmlReal && method_exists($this, 'extraerHonorariosAgenciaXML')) {
                                    $resultadoXML = $this->extraerHonorariosAgenciaXML($urlXmlReal);
                                    if ($resultadoXML['honorarios'] > 0) {
                                        $resultados['honorarios'] += $resultadoXML['honorarios'];
                                    }
                                    if (!empty($resultadoXML['folio'])) {
                                        $resultados['folio_sc'][] = $resultadoXML['folio'];
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $resultados['pedimento_detectado'] = implode(', ', array_unique((array) $resultados['pedimento_detectado']));
            $resultados['folio_sc'] = implode(', ', array_unique((array) $resultados['folio_sc']));
            $resultados['operaciones'] = collect($resultados['operaciones'])->unique('id')->values()->all();

            $montoGarantias = floatval($resultados['garantias'] ?? 0);
            $montoNaviera = floatval($resultados['desglose_naviera'] ?? 0);

            if ($montoGarantias > 0 || $montoNaviera > 0) {
                // Forzamos el flete a 0 si se cumple la condición
                $resultados['flete'] = 0;
            }

            // Redondeo final de llaves numéricas
            $llavesNumericas = ['honorarios', 'impuestos', 'eci', 'maniobras', 'flete', 'muestras', 'llc', 'anticipo', 'garantias', 'desglose_naviera'];
            foreach ($llavesNumericas as $key) {
                if (isset($resultados[$key])) {
                    // Si es flete y fue forzado a 0, lo enviamos asegurado como 0.00
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
            'folio_extraido' => null
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
                return ['honorarios' => 0.0, 'folio' => null, 'debug' => $debug];
            }

            $debug['xml_encontrado'] = true;
            $honorarios = 0.0;
            $folio = null;

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
                // Buscamos Folio="XXXX" en el comprobante
                if (preg_match('/Comprobante[^>]+Folio=["\']([^"\']+)["\']/i', $xmlString, $matchesFolio)) {
                    $folio = $matchesFolio[1];
                }
            }

            $debug['honorarios_extraidos'] = $honorarios;
            $debug['folio_extraido'] = $folio;

            return ['honorarios' => $honorarios, 'folio' => $folio, 'debug' => $debug];
        } catch (\Throwable $e) {
            $debug['error'] = $e->getMessage();
            return ['honorarios' => 0.0, 'folio' => null, 'debug' => $debug];
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
        $pedimentosLimpios = [];
        $totalFacturasXML = 0;

        // =========================================================================
        // PASO 1: EXTRAER TOTALES DIRECTAMENTE DE LOS XMLs VÍA API
        // =========================================================================
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
            $pedimentosLimpios[] = $pedimentoBusqueda;
            $logDebug[] = "1. Buscando pedimento: {$pedimentoBusqueda}";

            $pedimentoDB = \Illuminate\Support\Facades\DB::table('pedimiento')
                ->where('num_pedimiento', 'LIKE', "%{$pedimentoBusqueda}%")
                ->orderBy('id_pedimiento', 'desc')
                ->first();

            if ($pedimentoDB) {
                $resultados['pedimento_detectado'][] = $pedimentoDB->num_pedimiento;

                $impo = \Illuminate\Support\Facades\DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
                $expo = \Illuminate\Support\Facades\DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

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
                                // Aquí usamos tu super función que busca en DigitalOcean
                                $datosFactura = $this->parsearXmlFlete($urlXml);

                                $nombreEmisor = strtoupper($datosFactura['emisor']);
                                $logDebug[] = "API XML: Leído Emisor '{$nombreEmisor}' con Total \${$datosFactura['total']}";

                                // Verificamos que sea de TRANSPORTACTICS
                                if ($datosFactura['total'] > 0 && str_contains($nombreEmisor, 'TRANSPORTACTICS')) {
                                    $totalFacturasXML += $datosFactura['total'];

                                    if (!empty($datosFactura['folio'])) {
                                        $resultados['folio_sc'][] = $datosFactura['folio'];
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $logDebug[] = "❌ Pedimento {$pedimentoBusqueda} NO existe en BD.";
            }
        }

        // =========================================================================
        // PASO 2: ASIGNACIÓN Y CÁLCULO FINAL (Solo Flete)
        // =========================================================================

        $resultados['flete'] = round($totalFacturasXML, 2);
        $resultados['honorarios'] = 0;
        $resultados['anticipo'] = 0;

        $logDebug[] = "📊 RESULTADO: Flete XML= \${$totalFacturasXML}";

        if (empty($resultados['pedimento_detectado']) || $resultados['flete'] == 0) {
            return response()->json([
                'error' => "Análisis fallido. Motivos: " . implode(" | ", $logDebug)
            ], 400);
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
        gc_collect_cycles();

        if (!$rutaXml) {
            return ['total' => 0, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];
        }

        try {
            $arrContextOptions = [
                "ssl"  => ["verify_peer" => false, "verify_peer_name" => false],
                "http" => ["ignore_errors" => true]
            ];

            $xmlString = @file_get_contents($rutaXml, false, stream_context_create($arrContextOptions));

            if (!$xmlString || str_contains($http_response_header[0] ?? '', '404')) {
                $rutaAlternativa = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename($rutaXml);
                $xmlString = @file_get_contents($rutaAlternativa, false, stream_context_create($arrContextOptions));
            }

            if (!$xmlString || str_contains($http_response_header[0] ?? '', '404')) {
                return ['total' => 0, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];
            }

            $total = 0;
            $moneda = 'MXN';
            $emisor = '';
            $fecha = null;
            $folio = null;

            // INTENTO 1: Lector XML Nativo de PHP
            try {
                $xmlObj = @simplexml_load_string($xmlString);
                if ($xmlObj !== false) {
                    if (isset($xmlObj['Total'])) {
                        $total = (float) $xmlObj['Total'];
                    }
                    if (isset($xmlObj['Moneda'])) {
                        $moneda = strtoupper((string) $xmlObj['Moneda']);
                    }
                    if (isset($xmlObj['Fecha'])) {
                        $fecha = explode('T', (string) $xmlObj['Fecha'])[0];
                    }
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
                // Fallo silencioso si el XML está roto
            }

            // INTENTO 2: Fallback Regex INDEPENDIENTE 
            if ($total === 0) {
                if (preg_match('/Comprobante[^>]+Total=["\']([0-9\,\.]+)["\']/is', $xmlString, $matchesTotal)) {
                    $total = (float) str_replace(',', '', $matchesTotal[1]);
                }
            }

            if (empty($emisor)) {
                if (preg_match('/Emisor[^>]+Nombre=["\']([^"\']+)["\']/is', $xmlString, $matchesEmisor)) {
                    $emisor = trim($matchesEmisor[1]);
                }
            }

            if (empty($fecha)) {
                if (preg_match('/Comprobante[^>]+Fecha=["\']([^"\']+)["\']/is', $xmlString, $matchesFecha)) {
                    $fecha = explode('T', $matchesFecha[1])[0];
                }
            }

            if ($moneda === 'MXN') {
                if (preg_match('/Comprobante[^>]+Moneda=["\']([A-Z]{3})["\']/is', $xmlString, $matchesMoneda)) {
                    $moneda = strtoupper($matchesMoneda[1]);
                }
            }

            if (!$folio) {
                if (preg_match('/Folio=["\']([^"\']+)["\']/i', $xmlString, $m)) {
                    $folioRaw = $m[1];
                    $folio = str_contains($folioRaw, '_') ? last(explode('_', $folioRaw)) : $folioRaw;
                }
            }

            return ['total' => (float)$total, 'moneda' => $moneda, 'emisor' => $emisor, 'fecha' => $fecha, 'folio' => $folio];
        } catch (\Throwable $e) {
            Log::error("Error parseando XML {$rutaXml}: " . $e->getMessage());
            return ['total' => 0, 'moneda' => 'N/A', 'emisor' => '', 'fecha' => null, 'folio' => null];
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

            $pedimentoDB = \Illuminate\Support\Facades\DB::table('pedimiento')
                ->where('num_pedimiento', 'LIKE', "%{$pedimentoBusqueda}%")
                ->orderBy('id_pedimiento', 'desc')
                ->first();

            if ($pedimentoDB) {
                $resultados['pedimento_detectado'][] = $pedimentoDB->num_pedimiento;

                $impo = \Illuminate\Support\Facades\DB::table('operaciones_importacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();
                $expo = \Illuminate\Support\Facades\DB::table('operaciones_exportacion')->where('id_pedimiento', $pedimentoDB->id_pedimiento)->first();

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
        // 1. Validamos SOLAMENTE los datos del complemento (quitamos los de factura para que no marque 422)
        $request->validate([
            'ingreso_id' => 'required|integer',
            'cliente_id' => 'required|integer',
            'sucursal' => 'required|string',
            'moneda' => 'required|integer',
            'tipo_cambio' => 'required|numeric',
            'referencia' => 'required|string',
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

            // ==============================================================
            // PASO 1: CREAR EL COMPLEMENTO DE PAGO
            // ==============================================================
            $conceptoPagoCP = '100014.0';
            $conceptoFactura = '520174.0'; // El concepto de la factura a saldar según tu JSON

            $payloadCrear = [
                '$type' => 'CrearDocumentoRequest',
                'model' => [
                    'documento' => [
                        'concepto' => ['codigo' => $conceptoPagoCP],
                        'cliente' => ['codigo' => $codigoContpaqi],
                        'moneda' => ['id' => (int) $request->moneda],
                        'tipoCambio' => (float) $request->tipo_cambio,
                        'referencia' => $request->referencia,
                        'observaciones' => $request->observaciones ?? '',
                        'total' => (float) $request->total,
                        'formaPago' => $request->forma_pago,
                        'metodoPago' => $request->metodo_pago
                    ]
                ],
                'options' => [
                    'usarFechaDelDia' => true,
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

            $responseCrear = \Illuminate\Support\Facades\Http::withoutVerifying()
                ->timeout(30)
                ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadCrear);

            if (!$responseCrear->successful()) {
                return response()->json(['error' => 'Error de la API al crear el Complemento: ' . $responseCrear->body()], $responseCrear->status());
            }

            $resData = $responseCrear->json();
            $idRequest = $resData['data']['id'] ?? ($resData['id'] ?? null);

            // Obtenemos los datos del Ingreso para extraer el folio de la factura original
            $ingreso = IngresoConciliado::find($request->ingreso_id);
            $foliosFacturas = $ingreso && !empty($ingreso->folio_sc) ? $ingreso->folio_sc : $request->referencia;

            // Extraemos únicamente los números del folio (por si dice "ZLO6873" o "F-123")
            preg_match('/[0-9]+/', $foliosFacturas, $matches);
            $folioFacturaLimpio = isset($matches[0]) ? (int) $matches[0] : 0;

            // Extraemos los datos del Complemento devueltos por la API
            $folioComplemento = $resData['raw']['response']['contpaqiResponse']['model']['documento']['folio'] ?? null;
            $serieComplemento = $resData['raw']['response']['contpaqiResponse']['model']['documento']['serie'] ?? 'CP';

            ComplementoPago::create([
                'ingreso_conciliado_id' => $request->ingreso_id,
                'request_id' => $idRequest,
                'serie' => $serieComplemento,
                'folio_factura' => $foliosFacturas,
                'folio' => $folioComplemento,
                'fecha' => \Carbon\Carbon::now()
            ]);

            // ==============================================================
            // PASO 2: SALDAR LA FACTURA AUTOMÁTICAMENTE (Si hay folio disponible)
            // ==============================================================
            $resultadoSaldado = null;
            $mensajeSaldado = "Complemento creado exitosamente, pero el folio es asíncrono y no se saldó la factura.";
            $saldadoExitoso = false;

            if ($folioComplemento && $folioFacturaLimpio > 0) {
                // Formateamos la fecha en ISO 8601
                $fechaAplicacion = $request->fecha_pago ? \Carbon\Carbon::parse($request->fecha_pago) : \Carbon\Carbon::now();
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
                        'importe' => (float) $request->total
                    ],
                    'options' => [
                        'cargarDatosExtra' => false
                    ]
                ];

                $responseSaldar = Http::withoutVerifying()
                    ->timeout(30)
                    ->post('https://sistema.intactics.com/v3/contpaqi/api/requests', $payloadSaldar);

                if ($responseSaldar->successful()) {
                    $resultadoSaldado = $responseSaldar->json();

                    $isContpaqiSuccess = $resultadoSaldado['respuesta']['isSuccess'] ?? false;

                    if ($isContpaqiSuccess) {
                        $mensajeSaldado = "Complemento creado y factura saldada correctamente en Contpaqi.";
                        $saldadoExitoso = true;
                    } else {
                        // Extraemos el error exacto de Contpaqi
                        $errorContpaqi = $resultadoSaldado['respuesta']['errorMessage'] ?? 'Error desconocido al intentar saldar.';
                        $mensajeSaldado = "Complemento creado, pero NO se pudo saldar: " . $errorContpaqi;
                    }
                } else {
                    $mensajeSaldado = "Complemento creado, pero hubo un error de conexión al saldar: " . $responseSaldar->body();
                }
            } elseif (!$folioFacturaLimpio) {
                $mensajeSaldado = "Complemento creado, pero no se detectó un folio de factura válido para saldar.";
            }

            return response()->json([
                'success' => true, // El proceso en general no tronó
                'saldado' => $saldadoExitoso, // Bandera extra para que Vue sepa si pintarlo verde o amarillo
                'message' => $mensajeSaldado,
                'data_complemento' => $resData,
                'data_saldado' => $resultadoSaldado
            ]);

            return response()->json([
                'success' => true,
                'message' => $mensajeSaldado,
                'data_complemento' => $resData,
                'data_saldado' => $resultadoSaldado
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $ingreso = new IngresoConciliado();

        $ingreso->sucursal_origen = $request->sucursal_origen;
        $ingreso->banco_receptor = $request->banco_receptor;
        $ingreso->fecha = $request->fecha;
        $ingreso->cliente_id = $request->cliente_id;
        $ingreso->referencia = $request->referencia;
        $ingreso->tipo_comprobante = $request->tipo_comprobante;
        $ingreso->monto_deposito = $request->monto_deposito;
        $ingreso->folio_sc = $request->folio_sc;

        // Buscamos el nombre del cliente para asegurar la validación
        $cliente = \Illuminate\Support\Facades\DB::table('empresas')->where('id', $request->cliente_id)->first();

        $sucursalUpper = strtoupper($request->sucursal_origen ?? '');
        $clienteUpper = $cliente ? strtoupper($cliente->nombre) : '';

        // ==========================================================
        // 1. DESGLOSE EXCLUSIVO INTSHIPPERTS
        // ==========================================================
        if (str_contains($sucursalUpper, 'INTSHIPPERT') || str_contains($clienteUpper, 'INTSHIPPERT')) {
            $ingreso->anticipo = $request->anticipo ?? 0;
            $ingreso->flete = $request->flete ?? 0;

            // Limpiamos todo lo demás
            $ingreso->honorarios = 0;
            $ingreso->impuestos = 0;
            $ingreso->garantias = 0;
            $ingreso->desglose_naviera = 0;
            $ingreso->eci = 0;
            $ingreso->maniobras = 0;
            $ingreso->muestras = 0;
            $ingreso->llc = 0;
            $ingreso->pago_proveedor = 0;
            $ingreso->ganancia = 0;
        }
        // ==========================================================
        // 2. DESGLOSE EXCLUSIVO MANZANILLO (Normal)
        // ==========================================================
        elseif (str_contains($sucursalUpper, 'MANZANILLO')) {
            $ingreso->honorarios = $request->honorarios ?? 0;
            $ingreso->impuestos = $request->impuestos ?? 0;
            $ingreso->flete = $request->flete ?? 0;
            $ingreso->anticipo = $request->anticipo ?? 0;
            $ingreso->garantias = $request->garantias ?? 0;
            $ingreso->desglose_naviera = $request->desglose_naviera ?? 0;

            // Limpiamos los campos que esta modalidad no usa
            $ingreso->eci = 0;
            $ingreso->maniobras = 0;
            $ingreso->muestras = 0;
            $ingreso->llc = 0;
            $ingreso->pago_proveedor = 0;
            $ingreso->ganancia = 0;
        }
        // ==========================================================
        // 3. DESGLOSE ESTÁNDAR Y TRANSPORTACTICS
        // ==========================================================
        else {
            $ingreso->honorarios = $request->honorarios ?? 0;
            $ingreso->impuestos = $request->impuestos ?? 0;
            $ingreso->eci = $request->eci ?? 0;
            $ingreso->maniobras = $request->maniobras ?? 0;
            $ingreso->flete = $request->flete ?? 0;
            $ingreso->muestras = $request->muestras ?? 0;
            $ingreso->llc = $request->llc ?? 0;

            // Campos de Transportactics
            $ingreso->pago_proveedor = $request->pago_proveedor ?? 0;
            $ingreso->ganancia = ((float)$ingreso->flete) - ((float)$ingreso->pago_proveedor);

            // Limpiamos los exclusivos de Manzanillo/Intshipperts
            $ingreso->anticipo = 0;
            $ingreso->garantias = 0;
            $ingreso->desglose_naviera = 0;
        }

        // Datos complementarios de facturas / proveedores
        $ingreso->proveedor_maniobras = $request->proveedor_maniobras;
        $ingreso->factura_maniobras = $request->factura_maniobras;
        $ingreso->proveedor_flete = $request->proveedor_flete;
        $ingreso->factura_flete = $request->factura_flete;
        $ingreso->proveedor_muestras = $request->proveedor_muestras;
        $ingreso->factura_muestras = $request->factura_muestras;
        $ingreso->proveedor_llc = $request->proveedor_llc;
        $ingreso->factura_llc = $request->factura_llc;

        $ingreso->save();

        return response()->json([
            'success' => true,
            'message' => 'Ingreso guardado correctamente',
            'data' => $ingreso
        ]);
    }

    public function update(Request $request, $id)
    {
        $ingreso = IngresoConciliado::findOrFail($id);

        $ingreso->sucursal_origen = $request->sucursal_origen;
        $ingreso->banco_receptor = $request->banco_receptor;
        $ingreso->fecha = $request->fecha;
        $ingreso->cliente_id = $request->cliente_id;
        $ingreso->referencia = $request->referencia;
        $ingreso->tipo_comprobante = $request->tipo_comprobante;
        $ingreso->monto_deposito = $request->monto_deposito;
        $ingreso->folio_sc = $request->folio_sc;

        $cliente = \Illuminate\Support\Facades\DB::table('empresas')->where('id', $request->cliente_id)->first();

        $sucursalUpper = strtoupper($request->sucursal_origen ?? '');
        $clienteUpper = $cliente ? strtoupper($cliente->nombre) : '';

        // ==========================================================
        // 1. DESGLOSE EXCLUSIVO INTSHIPPERTS
        // ==========================================================
        if (str_contains($sucursalUpper, 'INTSHIPPERT') || str_contains($clienteUpper, 'INTSHIPPERT')) {
            $ingreso->anticipo = $request->anticipo ?? 0;
            $ingreso->flete = $request->flete ?? 0;

            $ingreso->honorarios = 0;
            $ingreso->impuestos = 0;
            $ingreso->garantias = 0;
            $ingreso->desglose_naviera = 0;
            $ingreso->eci = 0;
            $ingreso->maniobras = 0;
            $ingreso->muestras = 0;
            $ingreso->llc = 0;
            $ingreso->pago_proveedor = 0;
            $ingreso->ganancia = 0;
        }
        // ==========================================================
        // 2. DESGLOSE EXCLUSIVO MANZANILLO (Normal)
        // ==========================================================
        elseif (str_contains($sucursalUpper, 'MANZANILLO')) {
            $ingreso->honorarios = $request->honorarios ?? 0;
            $ingreso->impuestos = $request->impuestos ?? 0;
            $ingreso->flete = $request->flete ?? 0;
            $ingreso->anticipo = $request->anticipo ?? 0;
            $ingreso->garantias = $request->garantias ?? 0;
            $ingreso->desglose_naviera = $request->desglose_naviera ?? 0;

            $ingreso->eci = 0;
            $ingreso->maniobras = 0;
            $ingreso->muestras = 0;
            $ingreso->llc = 0;
            $ingreso->pago_proveedor = 0;
            $ingreso->ganancia = 0;
        }
        // ==========================================================
        // 3. DESGLOSE ESTÁNDAR Y TRANSPORTACTICS
        // ==========================================================
        else {
            $ingreso->honorarios = $request->honorarios ?? 0;
            $ingreso->impuestos = $request->impuestos ?? 0;
            $ingreso->eci = $request->eci ?? 0;
            $ingreso->maniobras = $request->maniobras ?? 0;
            $ingreso->flete = $request->flete ?? 0;
            $ingreso->muestras = $request->muestras ?? 0;
            $ingreso->llc = $request->llc ?? 0;

            $ingreso->pago_proveedor = $request->pago_proveedor ?? 0;
            $ingreso->ganancia = ((float)$ingreso->flete) - ((float)$ingreso->pago_proveedor);

            $ingreso->anticipo = 0;
            $ingreso->garantias = 0;
            $ingreso->desglose_naviera = 0;
        }

        // Datos complementarios de facturas / proveedores
        $ingreso->proveedor_maniobras = $request->proveedor_maniobras;
        $ingreso->factura_maniobras = $request->factura_maniobras;
        $ingreso->proveedor_flete = $request->proveedor_flete;
        $ingreso->factura_flete = $request->factura_flete;
        $ingreso->proveedor_muestras = $request->proveedor_muestras;
        $ingreso->factura_muestras = $request->factura_muestras;
        $ingreso->proveedor_llc = $request->proveedor_llc;
        $ingreso->factura_llc = $request->factura_llc;

        $ingreso->save();

        return response()->json([
            'success' => true,
            'message' => 'Ingreso actualizado correctamente',
            'data' => $ingreso
        ]);
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

        $data = $request->except(['cliente']);

        $data['sucursal_origen'] = $data['sucursal'];
        unset($data['sucursal']);

        $data['fecha_deteccion'] = now()->toDateString();
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

        $data = $request->except(['cliente']);

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
        $firmaUsuario = Auth::user()->firma ?? null;

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
            return response()->json(['error' => 'No hay correos válidos para enviar'], 400);
        }

        // 4. Enviamos el correo a la lista de destinatarios
        Mail::to($destinatarios)->send(new NotificacionSaldoFavorMail($saldo, $firmaUsuario));

        return response()->json([
            'success' => true,
            'message' => 'Correo enviado correctamente'
        ]);
    }
    public function destroySaldo($id)
    {
        SaldoFavor::destroy($id);
        return response()->json(['success' => true]);
    }
}
