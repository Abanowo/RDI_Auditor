<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\IngresoConciliado;
use App\Models\Empresas;
use App\Models\Sucursales;
use App\Models\SaldoFavor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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
            'clientes' => $clientes,
            'sucursales' => $sucursales, 
            'sucursalesBase' => $sucursalesBase, 
            'bancos' => $bancos,
        ]);
    }

    // 2. Traer los datos para mostrarlos en la tabla principal de Vue
    public function index(Request $request)
    {
        $query = IngresoConciliado::join('empresas', 'ingresos_conciliados.cliente_id', '=', 'empresas.id')
            ->select(
                'ingresos_conciliados.*', 
                'empresas.nombre as cliente',
                'empresas.cp'
            );

        if ($request->filled('tipo_comprobante') && $request->tipo_comprobante !== 'Todos') {
            $query->where('ingresos_conciliados.tipo_comprobante', $request->tipo_comprobante);
        }

        $ingresos = $query->orderBy('ingresos_conciliados.created_at', 'desc')->get();

        return response()->json($ingresos);
    }

    // 3. Guardar el nuevo registro desde el Modal
    public function store(Request $request)
    {
        // 1. Validación básica
        $request->validate([
            'cliente_id' => 'required|integer',
            'monto_deposito' => 'required|numeric'
        ]);

        // 2. Extraemos solo los datos que pertenecen a la tabla ingresos_conciliados
        // Ignoramos los arreglos y objetos que usamos en Vue para que Laravel no marque error
        $data = $request->except([
            'cliente', 
            'operaciones', 
            'referenciasObj', 
            'pedimento_detectado',
            'operacion_id',
            'operation_type',
            'created_at',
            'updated_at'
        ]);

        // 3. Limpiamos valores vacíos para que entren como NULL en lugar de cadenas vacías
        foreach ($data as $key => $value) {
            if ($value === '' || $value === 'null') {
                $data[$key] = null;
            }
        }

        // 4. Guardamos el Ingreso principal
        $ingreso = IngresoConciliado::create($data);

        // 5. Guardamos en la Tabla Pivote (Relación de Operaciones)
        if ($request->has('operaciones') && is_array($request->operaciones)) {
            $pivotData = [];
            
            foreach ($request->operaciones as $op) {
                // Verificamos que el objeto de la operación tenga los datos necesarios
                if (isset($op['id']) && isset($op['type'])) {
                    $pivotData[] = [
                        'ingreso_id'     => $ingreso->id,
                        'operacion_id'   => $op['id'],
                        'operacion_type' => $op['type'],
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                }
            }

            // Insertamos todos los registros de golpe en la tabla pivote
            if (!empty($pivotData)) {
                \Illuminate\Support\Facades\DB::table('ingreso_operacion')->insert($pivotData);
            }
        }

        return response()->json([
            'success' => true, 
            'message' => 'Ingreso registrado correctamente.',
            'data'    => $ingreso
        ]);
    }
    
    public function listarPedimentosSheet(Request $request)
    {
        $sucursalBuscada = strtoupper(trim($request->query('sucursal', '')));
        if (empty($sucursalBuscada)) {
            return response()->json(['error' => 'Sucursal requerida.'], 400);
        }

        $spreadsheetId = app()->environment('production') ? '1f_I9miUfQb5Xl379DNP1fciHUEYAU6MrM832Z6QqSi0' : '17hBoRx5u5jxi2hqHiC98zX-3lqF0w5tTkHBzElCHwDM';
        $nombrePestanaCodificado = rawurlencode($sucursalBuscada);
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&sheet={$nombrePestanaCodificado}";

        try {
            $response = Http::withoutVerifying()->timeout(30)->get($csvUrl);
            if (!$response->successful()) {
                return response()->json(['error' => 'Google Sheets rechazó la conexión.'], 500);
            }

            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $response->body());
            rewind($stream);

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

            $listaPedimentos = [];
            foreach ($bloques as $bloque) {
                $cliente = strtoupper(trim($bloque[0][1] ?? ''));
                $folioFactura = trim($bloque[0][8] ?? '');
                
                $pedimentoReal = '';
                foreach ($bloque as $fila) {
                    if (!empty(trim($fila[2] ?? ''))) {
                        $pedimentoReal = strtoupper(trim($fila[2]));
                        break;
                    }
                }

                if (!empty($pedimentoReal) || !empty($folioFactura)) {
                    $folioMostrar = $folioFactura ?: 'S/F';
                    $pedMostrar = $pedimentoReal ?: 'S/P';
                    $listaPedimentos[] = [
                        'cliente' => $cliente,
                        'folio' => $folioFactura,
                        'pedimento' => $pedimentoReal,
                        'label' => "{$folioMostrar} (Ped: {$pedMostrar}) - {$cliente}"
                    ];
                }
            }

            $listaUnica = collect($listaPedimentos)->unique('label')->values()->all();

            return response()->json($listaUnica);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al leer Excel'], 500);
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

        $spreadsheetId = app()->environment('production') ? '1f_I9miUfQb5Xl379DNP1fciHUEYAU6MrM832Z6QqSi0' : '17hBoRx5u5jxi2hqHiC98zX-3lqF0w5tTkHBzElCHwDM';
        $nombrePestanaCodificado = rawurlencode($sucursalBuscada);
        $csvUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&sheet={$nombrePestanaCodificado}";

        try {
            $response = Http::withoutVerifying()->timeout(30)->get($csvUrl);
            if (!$response->successful()) {
                return response()->json(['error' => 'Google Sheets rechazó la conexión.'], 500);
            }

            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $response->body());
            rewind($stream);

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

            // ====================================================================
            // BÚSQUEDA MULTIPLE SEGURA
            // ====================================================================
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

            // Validación de cliente único
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

            $quiereCFDI = in_array('CFDI', $tiposComprobante);
            $quiereNotaCargo = in_array('Nota Cargo', $tiposComprobante);
            
            // INICIALIZACIÓN DE VARIABLES
            $resultados = [
                'honorarios' => 0, 'impuestos' => 0, 'eci' => 0, 
                'maniobras' => 0, 'flete' => 0, 'muestras' => 0, 'llc' => 0,
                'proveedor_maniobras' => null, 'factura_maniobras' => null,
                'proveedor_flete'     => null, 'factura_flete'     => null,
                'proveedor_muestras'  => null, 'factura_muestras'  => null,
                'proveedor_llc'       => null, 'factura_llc'       => null,
                'folio_sc'            => [],
                'operacion_id'        => null,
                'operation_type'      => null,
                'pedimento_detectado' => [],
                'operaciones'         => [],
            ];

            // PROCESAMOS Y SUMAMOS CADA BLOQUE ENCONTRADO
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

                $estadoBloque = [
                    'folio' => $folioFacturaEnSheet,
                    'pedimento' => $pedimentoReal,
                    'honorario_sumado' => 0,
                    'mensaje_xml' => 'Ignorado (No es CFDI)'
                ];

                // 1. BUSCAMOS EL PEDIMENTO Y LA OPERACIÓN PARA LA RELACIÓN POLIMÓRFICA
                $pedimentoDB = \Illuminate\Support\Facades\DB::table('pedimiento') 
                    ->where('num_pedimiento', 'LIKE', "%{$pedimentoReal}%")
                    ->orderBy('id_pedimiento', 'desc')
                    ->first();

                $idOp = null; $tipoApi = null; $idPadre = null;

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

                // 2. SUMAMOS MONTOS DESDE GOOGLE SHEETS (GPC)
                if ($quiereNotaCargo) {
                    
                    $clientesDesgloseNL = [
                        'CENTRO ABARROTERO DEL BAJIO',
                        'ALMACENADORA Y MAQUILAS',
                        'ALMACENADORAS Y MAQUILA',
                        'ALMACENADORAS Y MAQUILAS',
                        'SURTIDORA DEL BAJIO'
                    ];
                    
                    $esLaredo = str_contains($sucursalBuscada, 'LAREDO');
                    $aplicaDesgloseManiobras = $esLaredo && in_array($clienteMaestro, $clientesDesgloseNL);

                    foreach ($bloqueFinal as $fila) {
                        $concepto = strtoupper(trim($fila[3] ?? ''));
                        
                        $proveedor = trim($fila[4] ?? ''); 
                        $factura   = trim($fila[5] ?? '');

                        $montoLimpio = (float) filter_var($fila[6] ?? '0', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                        
                        $esManiobra = false;
                        if ($aplicaDesgloseManiobras) {
                            // Si es cliente especial en Laredo, sumamos los 3 conceptos juntos
                            if (str_contains($concepto, 'MANIOBRAS') || str_contains($concepto, 'FERROVIARIA') || str_contains($concepto, 'FUMIGACI')) {
                                $esManiobra = true;
                            }
                        } else {
                            // Si es cualquier otro cliente/sucursal, solo buscamos "MANIOBRAS"
                            if (str_contains($concepto, 'MANIOBRAS')) {
                                $esManiobra = true;
                            }
                        }

                        if (str_contains($concepto, 'HONORARIO')) {
                            $resultados['honorarios'] += $montoLimpio;
                            $estadoBloque['honorario_sumado'] += $montoLimpio;
                        } elseif (str_contains($concepto, 'IMPUEST')) {
                            $resultados['impuestos'] += $montoLimpio;
                        } elseif (str_contains($concepto, 'ECI')) {
                            $resultados['eci'] += $montoLimpio;
                        } elseif ($esManiobra) { 
                            // Aquí entra la magia: sumará Maniobras + Fumigación + Ferroviarias en la misma variable
                            $resultados['maniobras'] += $montoLimpio;
                            // Solo sobreescribimos el proveedor/factura si la fila actual trae uno escrito
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
                
                // 3. BUSCAMOS Y SUMAMOS DESDE XML DE LA API DE INTACTICS
                if ($quiereCFDI) {
                    if (!empty($folioFacturaEnSheet)) {
                        $prefijos = ['NOGALES' => 'NOG', 'LAREDO' => 'NL', 'TIJUANA' => 'TIJ', 'MEXICALI' => 'MXL'];
                        $ciudad = explode(' ', $sucursalBuscada)[0];
                        $prefijo = $prefijos[$ciudad] ?? 'NOG'; 
                        $folioLimpio = preg_replace('/[^0-9]/', '', $folioFacturaEnSheet); 
                        $nombreBaseBuscado = $prefijo . $folioLimpio; 

                        if ($pedimentoDB) {
                            $estadoBloque['debug_ids'] = "Ped: {$pedimentoDB->id_pedimiento}, Op: {$idOp}, Padre: {$idPadre}";

                            if ($idOp) {
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

                                $nombresArchivosApi = [];
                                foreach ($archivos as $a) {
                                    $nombresArchivosApi[] = ($a['name'] ?? 'SinNombre') . ' | Tipo: ' . ($a['pivot']['type'] ?? 'SinTipo');
                                }
                                $estadoBloque['archivos_en_api'] = $nombresArchivosApi;

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
                                    
                                    if ($urlXmlReal) {
                                        $resultadoXML = $this->extraerHonorariosAgenciaXML($urlXmlReal);
                                        if ($resultadoXML['honorarios'] > 0) {
                                            $resultados['honorarios'] += $resultadoXML['honorarios'];
                                            $estadoBloque['honorario_sumado'] += $resultadoXML['honorarios'];
                                            $estadoBloque['mensaje_xml'] = "XML sumado exitosamente: $" . $resultadoXML['honorarios'];
                                        } else {
                                            $estadoBloque['mensaje_xml'] = "El XML se leyó, pero el 'Total' decía $0.00.";
                                        }
                                    } else {
                                        $estadoBloque['mensaje_xml'] = "Hay archivos, pero ninguno cumple el filtro de XML para {$nombreBaseBuscado}.";
                                    }
                                } else {
                                    $estadoBloque['mensaje_xml'] = "La API V3 devolvió 0 archivos para esta operación y su padre.";
                                }
                            } else {
                                $estadoBloque['mensaje_xml'] = "Pedimento sin Operación asociada.";
                            }
                        } else {
                            $estadoBloque['mensaje_xml'] = "El pedimento {$pedimentoReal} NO existe en la BD local.";
                        }
                    }
                }
            }

            $resultados['pedimento_detectado'] = implode(', ', array_unique((array) $resultados['pedimento_detectado']));
            $resultados['folio_sc'] = implode(', ', array_unique((array) $resultados['folio_sc']));
            
            // Eliminamos operaciones duplicadas por si el Excel repitió la fila
            $resultados['operaciones'] = collect($resultados['operaciones'])->unique('id')->values()->all();

            return response()->json($resultados);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }

    private function extraerHonorariosAgenciaXML(string $urlCompleta): array
    {
        $debug = [
            'intento_1' => $urlCompleta, 'status_1' => 0,
            'xml_encontrado' => false, 'honorarios_extraidos' => 0
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
                return ['honorarios' => 0.0, 'debug' => $debug];
            }

            $debug['xml_encontrado'] = true;
            $honorarios = 0.0;

            try {
                $xmlObj = @simplexml_load_string($xmlString);
                if ($xmlObj !== false && isset($xmlObj['Total'])) {
                    $honorarios = (float) $xmlObj['Total'];
                }
            } catch (\Throwable $th) {}

            if ($honorarios === 0.0) {
                if (preg_match('/Comprobante[^>]+Total=["\']([0-9\,\.]+)["\']/i', $xmlString, $matchesTotal)) {
                    $honorarios = (float) str_replace(',', '', $matchesTotal[1]);
                }
            }

            $debug['honorarios_extraidos'] = $honorarios;
            return ['honorarios' => $honorarios, 'debug' => $debug];

        } catch (\Throwable $e) {
            $debug['error'] = $e->getMessage();
            return ['honorarios' => 0.0, 'debug' => $debug];
        }
    }

    public function update(Request $request, $id)
    {
        // 1. Buscamos el ingreso existente
        $ingreso = IngresoConciliado::findOrFail($id);

        // 2. Extraemos los datos a actualizar, ignorando basura del frontend
        $data = $request->except([
            'cliente', 
            'operaciones', 
            'referenciasObj', 
            'pedimento_detectado',
            'operacion_id',
            'operation_type',
            'created_at',
            'updated_at'
        ]);

        // 3. Limpiamos valores vacíos
        foreach ($data as $key => $value) {
            if ($value === '' || $value === 'null') {
                $data[$key] = null;
            }
        }

        // 4. Actualizamos el Ingreso principal
        $ingreso->update($data);

        // 5. Actualizamos la Tabla Pivote (Borrar los viejos e insertar los nuevos)
        if ($request->has('operaciones') && is_array($request->operaciones)) {
            
            // Primero, eliminamos las relaciones anteriores de este ingreso
            \Illuminate\Support\Facades\DB::table('ingreso_operacion')
                ->where('ingreso_id', $ingreso->id)
                ->delete();
            
            $pivotData = [];
            
            foreach ($request->operaciones as $op) {
                if (isset($op['id']) && isset($op['type'])) {
                    $pivotData[] = [
                        'ingreso_id'     => $ingreso->id,
                        'operacion_id'   => $op['id'],
                        'operacion_type' => $op['type'],
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                }
            }

            // Insertamos las nuevas relaciones actualizadas
            if (!empty($pivotData)) {
                \Illuminate\Support\Facades\DB::table('ingreso_operacion')->insert($pivotData);
            }
        }

        return response()->json([
            'success' => true, 
            'message' => 'Ingreso actualizado correctamente.',
            'data'    => $ingreso
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
        
        if($request->has('sucursal')) {
            $data['sucursal_origen'] = $data['sucursal'];
            unset($data['sucursal']);
        }

        $saldo->update($data);

        return response()->json(['success' => true]);
    }

    public function destroySaldo($id)
    {
        SaldoFavor::destroy($id);
        return response()->json(['success' => true]);
    }
}