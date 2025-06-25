<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Operacion;
use App\Models\Llc; // ¡Importamos nuestro nuevo modelo!
use Symfony\Component\Finder\Finder;

class AuditarLlcCommand extends Command
{
    protected $signature = 'reporte:auditar-llc';
    protected $description = 'Audita las facturas LLC contra las operaciones de la SC.';

    public function handle()
    {
        $this->info('Iniciando la auditoría de facturas LLC...');

        // --- FASE 1: Construir Índices en Memoria ---
        $this->info('Construyendo índice de Facturas SC...');
        $indiceSC = $this->construirIndiceSCparaLLC();
        $this->info("LOG: Se encontraron ".count($indiceSC)." operaciones SC.");
        $this->info('Construyendo índice de facturas LLC...');
        $indiceLLC = $this->construirIndiceLLC();

        // --- FASE 2: Auditar Operaciones ---
        $operaciones = Operacion::whereIn('pedimento', array_keys($indiceLLC))
                                ->whereDoesntHave('llc')
                                ->get();

        $this->info("Se encontraron {$operaciones->count()} operaciones pendientes de auditoría de LLC.");
        if ($operaciones->count() == 0) {
            $this->info('No hay facturas LLC nuevas que auditar.');
            return 0;
        }

        $bar = $this->output->createProgressBar($operaciones->count());
        $bar->start();

        $llcsParaGuardar = [];

        foreach ($operaciones as $operacion) {
            $datosSC = $indiceSC[$operacion->pedimento] ?? null;
            $rutaTxtLlc = $indiceLLC[$operacion->pedimento] ?? null;

            if (!$datosSC && !$rutaTxtLlc) {
                $bar->advance();
                continue;
            } elseif ($rutaTxtLlc){
                //Aqui es cuando hay LLC pero no existe SC para esta factura.\\
            }

            // --- FASE 3: Parsear el TXT de la LLC ---
            $datosLlc = $this->parsearTxtLlc($rutaTxtLlc);
            if (!$datosLlc) {
                $bar->advance();
                continue;
            }
            $datosLlc['monto_total_llc_mxn'] = $datosLlc['monto_total'] * $datosSC['tipo_cambio'];
            $datosSC['monto_esperado_mxn'] = $datosSC['moneda'] == "USD" ? $datosSC['monto_esperado_llc'] * $datosSC['tipo_cambio'] : $datosSC['monto_esperado_llc'];
            // --- FASE 4: Comparar y Preparar Datos ---
            $estado = $this->compararMontos($datosSC['monto_esperado_mxn'], $datosLlc['monto_total_llc_mxn']);

            $llcsParaGuardar[] = [
                'operacion_id'      => $operacion->id,
                'folio_llc'         => $datosLlc['folio'],
                'fecha_llc'         => $datosLlc['fecha'],
                'ruta_txt'          => $datosLlc['ruta_txt'],
                'ruta_pdf'          => $datosLlc['ruta_pdf'],
                'monto_total_llc'   => $datosLlc['monto_total'],
                'monto_total_llc_mxn' => $datosLlc['monto_total_llc_mxn'],
                'monto_esperado_sc' => $datosSC['monto_esperado_llc'],
                'monto_esperado_mxn'=> $datosSC['monto_esperado_mxn'], // Aplicamos TC
                'moneda_sc'            => $datosSC['moneda'],
                'estado'            => $estado,
                'updated_at'        => now(),
            ];

            $bar->advance();
        }
        $bar->finish();

        // --- FASE 5: Guardado Masivo ---
        if (!empty($llcsParaGuardar)) {
            $this->info("\nGuardando/Actualizando " . count($llcsParaGuardar) . " registros de LLC...");
            Llc::upsert($llcsParaGuardar, ['operacion_id'], ['folio_llc', 'fecha_llc', 'ruta_txt', 'ruta_pdf', 'monto_total_llc', 'monto_total_llc_mxn','monto_esperado_sc', 'monto_esperado_mxn', 'moneda_sc', 'estado', 'updated_at']);
            $this->info("¡Guardado con éxito!");
        }

        $this->info("\nAuditoría de LLC finalizada.");
        return 0;
    }


     /**
     * Parsea un archivo TXT de una factura LLC para extraer el folio, la fecha
     * y, lo más importante, la suma de todos los montos.
     *
     * @param string $rutaTxt La ruta al archivo .txt de la LLC.
     * @return array|null Un array con los datos o null si falla.
     */
    private function parsearTxtLlc(string $rutaTxt): ?array
    {
        if (!file_exists($rutaTxt)) {
            return null;
        }

        // Leemos todas las líneas del archivo en un array
        $lineas = file($rutaTxt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $datos = [
            'folio' => null,
            'fecha' => null,
            'monto_total' => 0.0,
            'ruta_txt' => $rutaTxt,
            'ruta_pdf' => null,

        ];

        $montoTotal = 0.0;

        // Iteramos sobre cada línea del archivo
        foreach ($lineas as $linea) {
            // Buscamos el folio
            if (strpos($linea, '[encRefNumber]') !== false) {
                $datos['folio'] = trim(explode(']', $linea)[1]);
            }
            // Buscamos la fecha
            if (strpos($linea, '[encTxnDate]') !== false) {
                // Usamos Carbon para parsear y formatear la fecha de forma segura
                $datos['fecha'] = \Carbon\Carbon::parse(trim(explode(']', $linea)[1]))->format('Y-m-d');
            }
            // Buscamos y SUMAMOS cada monto
            if (strpos($linea, '[movAmount]') !== false) {
                $monto = (float) trim(explode(']', $linea)[1]);
                $montoTotal += $monto;
            }
        }

        $datos['monto_total'] = $montoTotal;
        $datos['ruta_pdf'] = config('reportes.rutas.llc_pdf_filepath') . DIRECTORY_SEPARATOR . 'NOG' . $datos['folio'] . '.pdf';
        // Solo devolvemos los datos si encontramos un folio y un monto.
        return ($datos['folio'] && $datos['monto_total'] > 0) ? $datos : null;
    }

   /**
     * Construye un índice de los archivos TXT de las LLC. Mapa: [pedimento => ruta_del_archivo]
     */
    private function construirIndiceLLC(): array
    {
        $directorio = config('reportes.rutas.llc_txt_filepath'); // Necesitaremos añadir esta ruta
        $finder = new Finder();
        $finder->depth(0)->path('NOG')->in($directorio)->name('*.txt')->date("since " . config('reportes.periodo_meses_busqueda', 2) . " months ago");


        $indice = [];
        foreach ($finder as $file) {
            $contenido = $file->getContents();
            // Buscamos el pedimento en el campo de notas
            if (preg_match('/(?<=\[encPdfRemarksNote3\])(.*)(\b\d{7}\b)/', $contenido, $matchPedimento)) { //SI NO FUNCIONA CAMBIALO POR d+
                $pedimento = trim($matchPedimento[2]);
                $indice[$pedimento] = $file->getRealPath();
            }
        }
        return $indice;
    }

    /**
     * Construye un índice de las SC para obtener el monto esperado y TC para las LLC.
     */
    private function construirIndiceSCparaLLC(): array
    {
        $directorio = config('reportes.rutas.sc_txt_filepath');
        $finder = new Finder();
        $finder->depth(0)
               ->path('NOG')
               ->in($directorio)
               ->name('*.txt')
               ->date("since " . config('reportes.periodo_meses_busqueda', 2) . " months ago");

        $indice = [];
        foreach ($finder as $file) {
            $contenido = $file->getContents();
            if (preg_match('/(?<=\[encOBSERVACION\])(\d*\-*)(\d{7})/', $contenido, $matchPedimento)) {
                $pedimento = trim($matchPedimento[2]);

                // Extraemos el monto esperado de [encTEXTOEXTRA3]
                preg_match('/\[encTEXTOEXTRA3\]([^\r\n]*)/', $contenido, $matchMonto);
                preg_match('/\[cteCODMONEDA\](.*?)(\r|\n)/', $contenido, $matchMoneda);
                // Extraemos el tipo de cambio de [encTIPOCAMBIO] y en [cteIMPORTEEXTRA1]
                $matchMonedaCount = preg_match('/\[cteIMPORTEEXTRA1\]([^\r\n]*)/', $contenido, $matchTC);
                    if($matchMonedaCount == 0){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    } elseif(($matchTC[1] == "1" && $matchMoneda[1] == "2")){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }

                $indice[$pedimento] = [
                    'monto_esperado_llc' => isset($matchMonto[1]) ? (float)trim($matchMonto[1]) : 0.0,
                    'tipo_cambio'        => isset($matchTC[1]) ? (float)trim($matchTC[1]) : 1.0,
                    'moneda'             => isset($matchMoneda[1]) && $matchMoneda[1] == "1" ? "MXN" : "USD",
                ];
            }
        }
        return $indice;
    }
    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     */
    private function compararMontos(float $esperado, float $real): string
    {
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }

        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }
}
