<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Operacion;
use App\Models\AuditoriaTotalSC; // Importamos el nuevo modelo
use Symfony\Component\Finder\Finder;
use Spatie\Regex\Regex;
class AuditarScCommand extends Command
{
    protected $signature = 'reporte:auditar-sc';
    protected $description = 'Realiza la auditoría principal comparando el Estado de Cuenta contra la Factura SC.';

    public function handle()
    {
        $this->info('Iniciando la auditoría principal (Banco vs. SC)...');

        // 1. Obtenemos las operaciones que tienen un cargo del banco pero aún no han sido auditadas.
        $indiceSC = $this->construirIndiceSC();
        $this->info("LOG: Se encontraron ".count($indiceSC)." facturas SC.");
        //--ESTE DE ABAJO ES PARA ACTUALIZAR TODA LA TABLA CON LOS SC RECIENTES, EN CASO DE QUE SE HAYA HECHO UN CAMBIO
        $operacionesSC = Operacion::whereIn('pedimento', array_keys($indiceSC))->get();

        //--Y ESTE ES PARA UNICAMENTE CREAR REGISTROS PARA LAS SC NUEVAS
        /* $operacionesSC =  Operacion::query()
            // 1. Filtramos para considerar solo las operaciones que nos interesan (opcional pero recomendado).
            ->whereIn('pedimento', array_keys($indiceSC))

            // 2. Aquí está la magia. Buscamos operaciones que no tengan una auditoría
            //    que cumpla con la condición que definimos dentro de la función.
            ->whereDoesntHave('auditorias', function ($query) {
                // 3. La condición: el tipo_documento debe ser 'sc'.
                // Esta sub-consulta se ejecuta sobre la tabla 'auditorias'.
                $query->where('tipo_documento', 'sc');
            })
            ->get(); */


        $this->info("Se encontraron {$operacionesSC->count()} operaciones pendientes de auditoría de SC.");
        if ($operacionesSC->count() == 0) {
            $this->info('No hay nada que auditar. ¡Todo al día!');
            return 0;
        }

        $bar = $this->output->createProgressBar($operacionesSC->count());
        $bar->start();

        $auditoriasParaGuardar = [];
        foreach ($operacionesSC as $operacion) {
            // 2. Encontrar el archivo TXT de la SC para este pedimento.
            $datosSC = $indiceSC[$operacion->pedimento];

            if (!$datosSC) {
                // Opcional: Podríamos crear un registro con estado "Sin Factura SC".
                $bar->advance();
                continue;
            }

            $desgloseSC = [
                 // --- Metadatos del desglose ---
                'moneda' => $datosSC['moneda'], // La moneda para TODOS los montos de abajo
                'tipo_cambio'      => $datosSC['tipo_cambio'],

                // --- Lista de conceptos y sus montos ---
                'montos' => [
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
            $auditoriasParaGuardar[] = [
                'operacion_id'       => $operacion->id,
                'folio_documento'    => $datosSC['folio_sc'],
                'fecha_documento'    => $datosSC['fecha_sc'],
                'desglose_conceptos' => json_encode($desgloseSC),
                'ruta_txt'           => $datosSC['ruta_txt'],
                'ruta_pdf'           => $datosSC['ruta_pdf'],
                'created_at'         => now(),
                'updated_at'         => now(),
            ];

            $bar->advance();
        }

        $bar->finish();
        // Guardamos todos los resultados en una sola consulta para máximo rendimiento.
        if (!empty($auditoriasParaGuardar)) {
            $this->info("\nGuardando/Actualizando " . count($auditoriasParaGuardar) . " resultados de auditoría...");
            AuditoriaTotalSC::upsert(
                $auditoriasParaGuardar,
                ['operacion_id'],
                ['folio_documento', 'fecha_documento', 'desglose_conceptos', 'ruta_txt', 'ruta_pdf', 'updated_at']
            );
            $this->info("\n¡Guardado con éxito!");
        }

        $this->info("\nAuditoría de SC finalizada.");
        return 0;
    }

    // --- MÉTODOS DE AYUDA ---

    private function construirIndiceSC(): array
    {
        // Esta lógica es similar a la que hicimos para Fletes, pero apunta al directorio de SC.
        $directorioSC = config('reportes.rutas.sc_txt_filepath');
        $finder = new Finder();
        try {
            $finder->depth(0)
               ->path('NOG')
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
                    if($matchTCCount == 0){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    } elseif(($matchTC[1] == "1" && $matchMoneda[1] == "2")){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }

                    // Construimos la ruta al PDF
                    $rutaPdf = config('reportes.rutas.sc_pdf_filepath') . DIRECTORY_SEPARATOR . $file->getBasename();
                    $rutaPdf = str_replace('.txt', '.pdf', $rutaPdf);

                    $indice[$pedimento] = [
                        'monto_impuestos' => isset($matchM_Impuesto[1]) && strlen($matchM_Impuesto[1]) > 0 ? (float)trim($matchM_Impuesto[1]) : -1,
                        'monto_flete' => isset($matchM_Tr[1]) && strlen($matchM_Tr[1]) > 0 ? (float)trim($matchM_Tr[1]) : -1,
                        'monto_llc' => isset($matchM_LLC[1]) && strlen($matchM_LLC[1]) > 0 ? (float)trim($matchM_LLC[1]) : -1,
                        'monto_total_pdd' => isset($matchM_PDD[1]) && strlen($matchM_PDD[1]) > 0 ? (float)trim($matchM_PDD[1]) : -1,
                        'monto_maniobras' => isset($matchM_Man[1]) && strlen($matchM_Man[1]) > 0 ? (float)trim($matchM_Man[1]) : -1,
                        'monto_muestras' => isset($matchM_Mue[1]) && strlen($matchM_Mue[1]) > 0 ? (float)trim($matchM_Mue[1]) : -1,

                        'folio_sc' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                        'fecha_sc'  => isset($matchFecha[2]) ? \Carbon\Carbon::parse(trim($matchFecha[1]))->format('Y-m-d') : now(), //ESTO PUEDES DECIRLE QUE TE LO IGUAL A NULL, NO HAY FECHA DENTRO DE LA SC
                        'ruta_txt' => $file->getRealPath(),
                        'ruta_pdf' => file_exists($rutaPdf) ? $rutaPdf : null,
                        'moneda' => isset($matchMoneda[1]) && $matchMoneda[1] == "1" ? "MXN" : "USD",
                        'tipo_cambio' => isset($matchTC[1]) ? (float)trim($matchTC[1]) : 1.0,
                        'monto_total_sc' => isset($matchTotalSC[1]) && strlen($matchTotalSC[1]) > 0 ? (float)trim($matchTotalSC[1]) : -1,
                    ];
                    $aux = $this->extraerMultiplesConceptos($contenido);
                    if($aux['monto_maniobras_2'] > $indice[$pedimento]['monto_maniobras']){
                        $indice[$pedimento]['monto_maniobras'] = $aux['monto_maniobras_2'];
                    }
                    unset($aux['monto_maniobras_2']);
                    $indice[$pedimento] = array_merge($indice[$pedimento], $aux);
                }
            }
        } catch (\Exception $e) {
            $this->error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }
        return $indice;
    }


    private function compararMontos(float $montoBanco, float $montoSC): string
    {   if ($montoSC < 0) return 'EXPO';
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
    $conceptosBuscados = [
        'monto_maniobras_2' => 'MANIOBRAS EN ALMACEN FISCALIZADO',
        'monto_termo' => 'CONTROLADOR DE TEMPERATURA (TERMOGRAFO)',
        'monto_rojos' => 'RECONOCIMIENTO ADUANERO (ROJO)',
        // Puedes agregar aquí todos los que necesites
    ];
    $resultados = [
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
        if (empty($conceptosPendientes)) {
            break;
        }

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

}
