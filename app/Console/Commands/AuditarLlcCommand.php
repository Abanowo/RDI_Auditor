<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Operacion;
use App\Models\Auditoria;
use App\Models\AuditoriaTotalSC;
use Symfony\Component\Finder\Finder;

class AuditarLlcCommand extends Command
{
    protected $signature = 'reporte:auditar-llc';
    protected $description = 'Audita las facturas LLC contra las operaciones de la SC.';

    public function handle()
    {
        $this->info('Iniciando la auditoría de facturas LLC...');

        // --- FASE 1: Construir Índices en Memoria ---
        $this->info('Construyendo índice de facturas LLC...');
        $indiceLLC = $this->construirIndiceLLC();
        $this->info("LOG: Se encontraron ".count($indiceLLC)." facturas LLC.");

        //--ESTE DE ABAJO ES PARA ACTUALIZAR TODA LA TABLA CON LOS LLCs RECIENTES, EN CASO DE QUE SE HAYA HECHO UN CAMBIO
        $operaciones = Operacion::whereIn('pedimento', array_keys($indiceLLC))->get();

         //--Y ESTE ES PARA UNICAMENTE CREAR REGISTROS PARA LOS LLCs NUEVOS
        /* $operaciones =  Operacion::query()
            // 1. Filtramos para considerar solo las operaciones que nos interesan (opcional pero recomendado).
            ->whereIn('pedimento', array_keys($indiceLLC))

            // 2. Aquí está la magia. Buscamos operaciones que no tengan una auditoría
            //    que cumpla con la condición que definimos dentro de la función.
            ->whereDoesntHave('auditorias', function ($query) {
                // 3. La condición: el tipo_documento debe ser 'sc'.
                // Esta sub-consulta se ejecuta sobre la tabla 'auditorias'.
                $query->where('tipo_documento', 'llc');
            })
            ->get(); */

        $auditoriasSC = AuditoriaTotalSC::query()
            //->with(['operacion'])
            // Unimos con la tabla de operaciones para poder filtrar por pedimento
            ->join('operaciones', 'auditorias_totales_sc.operacion_id', '=', 'operaciones.id')
            // Filtramos para traer solo las que coinciden con los pedimentos de nuestros fletes
            ->whereIn('operaciones.pedimento', array_keys($indiceLLC))
            // Seleccionamos solo los campos que realmente necesitamos para ser eficientes
            ->select('operaciones.pedimento', 'auditorias_totales_sc.desglose_conceptos')
            ->get();


        $this->info("Se encontraron {$operaciones->count()} operaciones pendientes de auditoría de LLC.");
        if ($operaciones->count() == 0) {
            $this->info('No hay facturas LLC nuevas que auditar.');
            return 0;
        }

         // Aquí es donde extraemos el tipo de cambio del JSON.
        $indiceSC = [];
        foreach ($auditoriasSC as $auditoria) {
            // Laravel ya ha convertido el `desglose_conceptos` en un array gracias a la propiedad `$casts`.
            $desglose = $auditoria->desglose_conceptos;

            // Creamos la entrada en nuestro mapa.
            $indiceSC[$auditoria->pedimento] = [
                'monto_llc_sc' => (float)$desglose['montos']['llc'],
                'monto_llc_sc_mxn' => (float)$desglose['montos']['llc_mxn'],
                'moneda' => $desglose['moneda'],
                 // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                 // para asignar un valor por defecto (ej. 1) si no se encuentra.
                'tipo_cambio' => (float)$desglose['tipo_cambio'] ?? 1.0,
            ];
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
            } elseif (!$datosSC && $rutaTxtLlc){
                //Aqui es cuando hay LLC pero no existe SC para esta factura.\\
                $datosSC = [
                    'monto_llc_sc' => -1,
                    'monto_llc_sc_mxn' => -1,
                    'tipo_cambio'        => -1,
                    'moneda'             => 'N/A',
                ];
            }

            // --- FASE 3: Parsear el TXT de la LLC ---
            $datosLlc = $this->parsearTxtLlc($rutaTxtLlc);
            if (!$datosLlc) {
                $bar->advance();
                continue;
            }

            $montoLLCMXN = $datosSC['monto_llc_sc'] != -1 ?  round($datosLlc['monto_total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : -1;
            $montoSCMXN = $datosSC['monto_llc_sc_mxn'];
            // --- FASE 4: Comparar y Preparar Datos ---
            $estado = $this->compararMontos($montoSCMXN, $montoLLCMXN);

            $llcsParaGuardar[] = [
                'operacion_id'      => $operacion->id,
                'tipo_documento'    => 'llc',
                'concepto_llave'    => 'principal',
                'folio'             => $datosLlc['folio'],
                'fecha_documento'   => $datosLlc['fecha'],
                'monto_total'       => $datosLlc['monto_total'],
                'monto_total_mxn'   => $montoLLCMXN,
                'moneda_documento'  => 'USD',
                'estado'            => $estado,
                'ruta_txt'          => $datosLlc['ruta_txt'],
                'ruta_pdf'          => $datosLlc['ruta_pdf'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ];

            $bar->advance();
        }
        $bar->finish();

        // --- FASE 5: Guardado Masivo ---
        if (!empty($llcsParaGuardar)) {
            $this->info("\nGuardando/Actualizando " . count($llcsParaGuardar) . " registros de LLC...");
            Auditoria::upsert($llcsParaGuardar,
            ['operacion_id', 'tipo_documento', 'concepto_llave'], // Columna única para identificar si debe actualizar o insertar
            ['folio', 'fecha_documento', 'monto_total', 'monto_total_mxn', 'moneda_documento', 'estado', 'ruta_txt', 'ruta_pdf', 'updated_at']
            );
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
    /* private function construirIndiceSCparaLLC(): array
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
    } */
    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     */
    private function compararMontos(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin LLC!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }
}
