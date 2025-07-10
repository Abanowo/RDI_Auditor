<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Models\Pedimento;
use App\Models\Importacion; // Tu modelo para 'operaciones_importacion'
use App\Models\Sucursales;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;// Importamos el nuevo modelo
use Symfony\Component\Finder\Finder;
use Spatie\Regex\Regex;
class AuditarScCommand extends Command
{
    protected $signature = 'reporte:auditar-sc {--tarea_id= : El ID de la tarea a procesar}';
    protected $description = 'Realiza la auditoría principal comparando el Estado de Cuenta contra la Factura SC.';

    public function handle()
    {
        $tareaId = $this->option('tarea_id');
        if (!$tareaId) {
            $this->error('Se requiere el ID de la tarea. Usa --tarea_id=X');
            return 1;
        }

        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("SC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }

        try {
            $sucursal = $tarea->sucursal;
            $this->info("Procesando Facturas SC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            // 1. Construimos el índice de SCs desde los archivos (tu lógica no cambia)
            $indiceSC = $this->construirIndiceSC($sucursal);
            if (empty($indiceSC)) {
                $this->info("No se encontraron archivos de SC para procesar en la sucursal {$sucursal}.");
                return 0;
            }
            $this->info("Se encontraron " . count($indiceSC) . " facturas SC en los archivos.");

            // 2. Obtenemos los números de pedimento de nuestro índice
            $numerosDePedimento = array_keys($indiceSC);

            $mapaPedimentoAId = $this->construirMapaDePedimentos($numerosDePedimento);
            $this->info("Pedimentos encontrados en tabla 'pedimentos': ". count($mapaPedimentoAId));

            // 3. MAPEADO EFICIENTE DE IDS (La misma lógica que en el comando anterior)
            // Creamos un mapa: num_pedimento => id_importacion
            $mapaPedimentoAImportacionId = Importacion::query()
                ->join('pedimiento', 'operaciones_importacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                ->whereIn('pedimiento.num_pedimiento', $numerosDePedimento)
                ->pluck('operaciones_importacion.id_importacion', 'pedimiento.num_pedimiento');
            $this->info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': ". $mapaPedimentoAImportacionId->count());

            // 4. PREPARAR DATOS PARA GUARDAR EN 'auditorias_totales_sc'
            $auditoriasParaGuardar = [];
            foreach ($indiceSC as $pedimento => $datosSC) {
                // Buscamos el id_importacion en nuestro mapa
                $pedimentoId = $mapaPedimentoAId[$pedimento] ?? null;
                $operacionId = $mapaPedimentoAImportacionId->get($pedimento) ?? null;

                if (!$operacionId && !$pedimentoId) {
                    //$this->warn("Se omitió la SC del pedimento '{$pedimento}' porque no se encontró una operación de importación asociada.");
                    continue; // Si no hay operación, no podemos guardar la SC
                }

                // Preparamos el desglose para guardarlo como JSON
                $desgloseSC =
                [
                    'moneda' => $datosSC['moneda'],
                    'tipo_cambio' => $datosSC['tipo_cambio'],
                    'montos' =>
                    [
                        'sc'                => $datosSC['monto_total_sc'],
                        'sc_mxn'            => ($datosSC['moneda'] == "USD" && $datosSC['monto_total_sc'] != -1) ? round($datosSC['monto_total_sc'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_total_sc'],

                        'impuestos'         => $datosSC['monto_impuestos'],
                        'impuestos_mxn'     => ($datosSC['moneda'] == "USD" && $datosSC['monto_impuestos'] != -1) ? round($datosSC['monto_impuestos'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_impuestos'],

                        'pago_derecho'      => $datosSC['monto_total_pdd'],
                        'pago_derecho_mxn'  => ($datosSC['moneda'] == "USD" && $datosSC['monto_total_pdd'] != -1) ? round($datosSC['monto_total_pdd'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_total_pdd'],

                        'llc'               => $datosSC['monto_llc'],
                        'llc_mxn'           => ($datosSC['moneda'] == "USD" && $datosSC['monto_llc'] != -1) ? round($datosSC['monto_llc'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_llc'],

                        'flete'             => $datosSC['monto_flete'],
                        'flete_mxn'         => ($datosSC['moneda'] == "USD" && $datosSC['monto_flete'] != -1) ? round($datosSC['monto_flete'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_flete'],

                        'maniobras'         => $datosSC['monto_maniobras'],
                        'maniobras_mxn'     => ($datosSC['moneda'] == "USD" && $datosSC['monto_maniobras'] != -1) ? round($datosSC['monto_maniobras'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_maniobras'],

                        'muestras'          => $datosSC['monto_muestras'],
                        'muestras_mxn'      => ($datosSC['moneda'] == "USD" && $datosSC['monto_muestras'] != -1) ? round($datosSC['monto_muestras'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_muestras'],

                        'termo'             => $datosSC['monto_termo'],
                        'termo_mxn'         => ($datosSC['moneda'] == "USD" && $datosSC['monto_termo'] != -1) ? round($datosSC['monto_termo'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_termo'],

                        'rojos'             => $datosSC['monto_rojos'],
                        'rojos_mxn'         => ($datosSC['moneda'] == "USD" && $datosSC['monto_rojos'] != -1) ? round($datosSC['monto_rojos'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosSC['monto_rojos'],
                    ]
                ];

                $auditoriasParaGuardar[] =
                [
                    'operacion_id'      => $operacionId, // ¡La vinculación auxiliar correcta!
                    'pedimento_id'      => $pedimentoId['id_pedimiento'], // ¡La vinculación correcta!
                    'folio_documento'   => $datosSC['folio_sc'],
                    'fecha_documento'   => $datosSC['fecha_sc'],
                    'desglose_conceptos'=> json_encode($desgloseSC),
                    'ruta_txt'          => $datosSC['ruta_txt'],
                    'ruta_pdf'          => $datosSC['ruta_pdf'],
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
            }

            // 5. GUARDAR EN BASE DE DATOS
            if (!empty($auditoriasParaGuardar)) {
                $this->info("\nGuardando/Actualizando " . count($auditoriasParaGuardar) . " registros de SC...");
                AuditoriaTotalSC::upsert(
                    $auditoriasParaGuardar,
                    ['operacion_id', 'pedimento_id'], // La llave única
                    ['folio_documento', 'fecha_documento', 'desglose_conceptos', 'ruta_txt', 'ruta_pdf', 'updated_at']
                );
                $this->info("¡Guardado con éxito!");
            }
            else {
                $this->info("No se encontraron SCs para guardar en la base de datos.");
            }

        }
        catch (\Exception $e) {
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);
            Log::error("Error al procesar SC para la Tarea #{$tareaId}: " . $e->getMessage());
            $this->error("Error al procesar SC para la Tarea #{$tareaId}: " . $e->getMessage());
            throw $e; // Lanzamos la excepción para que el orquestador la atrape
        }
    }

    // --- MÉTODOS DE AYUDA ---

    private function construirIndiceSC(string $sucursal): array
    {
        // Esta lógica es similar a la que hicimos para Fletes, pero apunta al directorio de SC.
        $directorioSC = config('reportes.rutas.sc_txt_filepath');
        $finder = new Finder();
        if($sucursal == 'NL' || $sucursal == 'REY') { $sucursal = 'NL'; }
        try {
            $finder->depth(0)
                ->path($sucursal)
                ->in($directorioSC)
                ->name('*.txt')
                ->date("since " . config('reportes.periodo_meses_busqueda', 3) . " months ago");

            $indice = [];
            foreach ($finder as $file) {
                $contenido = $file->getContents();
                // Refinamiento: Regex más preciso para el pedimento en la observación.
                if (preg_match('/(?<=\[encOBSERVACION\])(\d*\-*)(\d{7})/', $contenido, $matchesPedimento)) {

                    $pedimento = trim($matchesPedimento[2]);
                    //[encTEXTOEXTRA1] = IMPUESTOS (EDC - SC)
                    preg_match('/\[encTEXTOEXTRA1\](.*?)(\r|\n)/', $contenido, $matchM_Impuesto);
                    //[encTEXTOEXTRA2] = EMISION DE CERTIFICADO INTERNACIONAL (PAGOS DE DERECHO - SADER)
                    preg_match('/\[encTEXTOEXTRA2\](.*?)(\r|\n)/', $contenido, $matchM_PDD);
                    //[encTEXTOEXTRA3] = GASTOS GENERADOS EN ESTADOS UNIDOS (LLC)
                    preg_match('/\[encTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchM_LLC);
                    //[cteTEXTOEXTRA2] = FLETE (TRANSPORTACTICS)
                    preg_match('/\[cteTEXTOEXTRA2\](.*?)(\r|\n)/', $contenido, $matchM_Tr);
                    //[cteTEXTOEXTRA3] = MANIOBRAS
                    preg_match('/\[cteTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchM_Man);
                    //[cteCTAMENSAJERIA] = MUESTRAS
                    preg_match('/\[cteCTAMENSAJERIA\](.*?)(\r|\n)/', $contenido, $matchM_Mue);
                    //[encFECHA] = FECHA (REALMENTE NO EXISTE EN LA SC)
                    preg_match('/\[encFECHA\](.*?)(\r|\n)/', $contenido, $matchFecha);
                    //[encFOLIOVENTA] = FOLIO
                    preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);
                    //[cteCODMONEDA] = MONEDA
                    preg_match('/\[cteCODMONEDA\](.*?)(\r|\n)/', $contenido, $matchMoneda);
                    //[encIMPORTEEXTRA4] = TOTAL FACTURA SC (ESTE NO LO USO, PERO LO AGREGO)
                    preg_match('/\[encIMPORTEEXTRA4\](.*?)(\r|\n)/', $contenido, $matchTotalSC);

                    // Extraemos el tipo de cambio de [encTIPOCAMBIO] y en [cteIMPORTEEXTRA1]
                    $matchTCCount = preg_match('/\[cteIMPORTEEXTRA1\]([^\r\n]*)/', $contenido, $matchTC);
                    if($matchTCCount == 0) {
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }
                    elseif(($matchTC[1] == "1" && $matchMoneda[1] == "2")) {
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }

                    // Construimos la ruta al PDF
                    $rutaPdf = config('reportes.rutas.sc_pdf_filepath') . DIRECTORY_SEPARATOR . $file->getBasename();
                    $rutaPdf = str_replace('.txt', '.pdf', $rutaPdf);

                    $indice[$pedimento] =
                    [
                        'monto_impuestos'   => isset($matchM_Impuesto[1]) && strlen($matchM_Impuesto[1]) > 0 ? (float)trim($matchM_Impuesto[1]) : -1,
                        'monto_flete'       => isset($matchM_Tr[1]) && strlen($matchM_Tr[1]) > 0 ? (float)trim($matchM_Tr[1]) : -1,
                        'monto_llc'         => isset($matchM_LLC[1]) && strlen($matchM_LLC[1]) > 0 ? (float)trim($matchM_LLC[1]) : -1,
                        'monto_total_pdd'   => isset($matchM_PDD[1]) && strlen($matchM_PDD[1]) > 0 ? (float)trim($matchM_PDD[1]) : -1,
                        'monto_maniobras'   => isset($matchM_Man[1]) && strlen($matchM_Man[1]) > 0 ? (float)trim($matchM_Man[1]) : -1,
                        'monto_muestras'    => isset($matchM_Mue[1]) && strlen($matchM_Mue[1]) > 0 ? (float)trim($matchM_Mue[1]) : -1,

                        'folio_sc'          => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                        'fecha_sc'          => isset($matchFecha[2]) ? \Carbon\Carbon::parse(trim($matchFecha[1]))->format('Y-m-d') : now(), //ESTO PUEDES DECIRLE QUE TE LO IGUAL A NULL, NO HAY FECHA DENTRO DE LA SC
                        'ruta_txt'          => $file->getRealPath(),
                        'ruta_pdf'          => file_exists($rutaPdf) ? $rutaPdf : null,
                        'moneda'            => isset($matchMoneda[1]) && $matchMoneda[1] == "1" ? "MXN" : "USD",
                        'tipo_cambio'       => isset($matchTC[1]) ? (float)trim($matchTC[1]) : 1.0,
                        'monto_total_sc'    => isset($matchTotalSC[1]) && strlen($matchTotalSC[1]) > 0 ? (float)trim($matchTotalSC[1]) : -1,
                    ];

                    $aux = $this->extraerMultiplesConceptos($contenido);
                    if($aux['monto_maniobras_2'] > $indice[$pedimento]['monto_maniobras']) {
                        $indice[$pedimento]['monto_maniobras'] = $aux['monto_maniobras_2'];
                    }

                    unset($aux['monto_maniobras_2']);
                    $indice[$pedimento] = array_merge($indice[$pedimento], $aux);
                }
            }
        } catch (\Exception $e) {
            Log::error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
            $this->error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }
        return $indice;
    }


    private function compararMontos(float $montoBanco, float $montoSC): string
    {
        if ($montoSC < 0) return 'EXPO';
        if (abs($montoBanco - $montoSC) < 0.01) return 'Coinciden!';
        return ($montoBanco > $montoSC) ? 'Pago de menos!' : 'Pago de más!';
    }

/**
 * Extrae múltiples conceptos y sus respectivos precios de un texto con formato específico.
 *
 * @param string $contenidoTxt El contenido completo del archivo de texto.
 * @param array $conceptosBuscados Un array con los nombres de los productos a buscar.
 * @return array Un array asociativo donde cada clave es el nombre de un concepto encontrado
 * y su valor es un array con 'nombre' y 'precio'.
 */
    private function extraerMultiplesConceptos(string $contenidoTxt): array
    {
        // Un array con todos los conceptos que nos interesan.
        $conceptosBuscados =
        [
            'monto_maniobras_2' => 'MANIOBRAS EN ALMACEN FISCALIZADO',
            'monto_termo' => 'CONTROLADOR DE TEMPERATURA (TERMOGRAFO)',
            'monto_rojos' => 'RECONOCIMIENTO ADUANERO (ROJO)',
            // Puedes agregar aquí todos los que necesites
        ];
        $resultados =
        [
            'monto_maniobras_2' => -1,
            'monto_termo' => -1,
            'monto_rojos' => -1,
        ];
        // Hacemos una copia de los conceptos a buscar para poder modificarla.
        $conceptosPendientes = $conceptosBuscados;

        // 1. Dividimos el texto en bloques de "movimiento" o producto.
        $bloques = preg_split('/(?=\[movTIPOPRODUCTO\])/', $contenidoTxt, -1, PREG_SPLIT_NO_EMPTY);

        // 2. Iteramos sobre cada bloque de producto encontrado.
        foreach ($bloques as $bloque) {
            // Si ya encontramos todos los conceptos, no tiene caso seguir recorriendo bloques.
            if (empty($conceptosPendientes)) { break; }

            // 3. Dentro de cada bloque, iteramos sobre los conceptos que aún nos faltan por encontrar.
            foreach ($conceptosPendientes as $index => $concepto) {
                $lineaNombreBuscada = '[movPRODUCTONOMBRE]' . $concepto;

                // 4. Verificamos si el concepto actual está en este bloque.
                if (str_contains($bloque, $lineaNombreBuscada)) {
                    // ¡Encontrado! Ahora extraemos su precio.
                    if (preg_match('/\[movPRODUCTOPRECIO\](.*)/', $bloque, $matches)) {
                        $precio = trim($matches[1]);

                        // 5. Guardamos el resultado usando el nombre del concepto como clave.
                        $resultados[$index] = (float)$precio;

                        // 6. Eliminamos el concepto de la lista de pendientes para ser más eficientes.
                        unset($conceptosPendientes[$index]);

                        // Como un bloque solo puede tener un producto, rompemos el bucle interior
                        // para pasar al siguiente bloque.
                        break;
                    }
                }
            }
        }

        return $resultados;
    }

    private function construirMapaDePedimentos(array $pedimentosLimpios): array
    {
        if (empty($pedimentosLimpios)) { return []; }

        // 1. Hacemos una única consulta a la BD para traer todos los registros
        //    que POTENCIALMENTE contienen nuestros números.
        $query = Pedimento::query();
        $regexPattern = implode('|', $pedimentosLimpios);

        // Obtenemos solo las columnas que necesitamos
        $posiblesCoincidencias = $query->where('num_pedimiento', 'REGEXP', $regexPattern)->get(['id_pedimiento', 'num_pedimiento']);

        // 1. Creamos un mapa de los pedimentos que nos falta por encontrar.
        //    Usamos array_flip para que la búsqueda y eliminación sea instantánea.
        $pedimentosPorEncontrar = array_flip($pedimentosLimpios);

        //Ahora, procesamos los resultados en PHP para crear el mapa definitivo.
        $mapaFinal = [];
        // 2. Recorremos los resultados de la BD UNA SOLA VEZ.
        foreach ($posiblesCoincidencias as $pedimentoSucio) {
            // Si ya no quedan pedimentos por buscar, salimos del bucle para máxima eficiencia.
            if (empty($pedimentosPorEncontrar)) { break; }

            $pedimentoObtenido = $pedimentoSucio->num_pedimiento;

            // 3. Revisamos cuáles de los pedimentos PENDIENTES están en el string sucio actual.
            foreach ($pedimentosPorEncontrar as $pedimentoLimpio => $value) {
                if (str_contains($pedimentoObtenido, $pedimentoLimpio)) {
                    // ¡Coincidencia! La guardamos en el resultado final.
                    $mapaFinal[$pedimentoLimpio] =
                    [
                        'id_pedimiento' => $pedimentoSucio->id_pedimiento,
                        'num_pedimiento' => $pedimentoObtenido,
                    ];

                    // 4. (La optimización clave) Eliminamos el pedimento de la lista de pendientes.
                    //    Así, nunca más se volverá a buscar.
                    unset($pedimentosPorEncontrar[$pedimentoLimpio]);
                }
            }
        }

        // 5. (Opcional) Al final, lo que quede en $pedimentosPorEncontrar son los que no se encontraron.
        //    Podemos lanzar los warnings de forma mucho más eficiente.
        foreach (array_keys($pedimentosPorEncontrar) as $pedimentoNoEncontrado) {
            $this->warn('Omitiendo pedimento no encontrado en tabla \'pedimiento\': ' . $pedimentoNoEncontrado);
        }

        return $mapaFinal;
    }

}

