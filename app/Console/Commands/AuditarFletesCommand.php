<?php

namespace App\Console\Commands;
use DateTime;
use Illuminate\Console\Command;
use App\Models\Operacion;
use App\Models\Auditoria;
use Symfony\Component\Finder\Finder;

class AuditarFletesCommand extends Command
{
    /**
     * La firma de nuestro comando.
     */
    protected $signature = 'reporte:auditar-fletes';

    /**
     * La descripción de nuestro comando.
     */
    protected $description = 'Audita las facturas de Fletes (Transportactics) contra las operaciones de la SC.';

    /**
     * Orquesta el proceso completo de la auditoría de Fletes.
     */
    public function handle()
    {
        $prueba = now();
        $this->info('Iniciando la auditoría de Fletes (Transportactics)...');

        // --- FASE 1: Construir Índices en Memoria para Búsquedas Rápidas ---

        $this->info('Paso 1/3: Construyendo índice de archivos de Fletes...');
        $indiceFletes = $this->construirIndiceFletes();
        $this->info("LOG: Se encontraron ".count($indiceFletes)." facturas de Transportactics.");
        // --- FASE 2: Auditar Operaciones Pendientes ---
        //--ESTE DE ABAJO ES PARA ACTUALIZAR TODA LA TABLA CON LOS FLETES RECIENTES, EN CASO DE QUE SE HAYA HECHO UN CAMBIO
        //$operaciones = Operacion::whereIn('pedimento', array_keys($indiceFletes))->get();

         //--Y ESTE ES PARA UNICAMENTE CREAR REGISTROS PARA LOS FLETES NUEVOS
        $operaciones =  Operacion::query()
            // 1. Filtramos para considerar solo las operaciones que nos interesan (opcional pero recomendado).
            ->whereIn('pedimento', array_keys($indiceFletes))

            // 2. Aquí está la magia. Buscamos operaciones que no tengan una auditoría
            //    que cumpla con la condición que definimos dentro de la función.
            ->whereDoesntHave('auditorias', function ($query) {
                // 3. La condición: el tipo_documento debe ser 'sc'.
                // Esta sub-consulta se ejecuta sobre la tabla 'auditorias'.
                $query->where('tipo_documento', 'flete');
            })
            ->get();

        $auditoriasSC = Auditoria::query()
            //->with(['operacion'])
            // Unimos con la tabla de operaciones para poder filtrar por pedimento
            ->join('operaciones', 'auditorias.operacion_id', '=', 'operaciones.id')
            // Nos interesan únicamente las auditorías de tipo 'sc'
            ->where('auditorias.tipo_documento', 'sc')
            // Filtramos para traer solo las que coinciden con los pedimentos de nuestros fletes
            ->whereIn('operaciones.pedimento', array_keys($indiceFletes))
            // Seleccionamos solo los campos que realmente necesitamos para ser eficientes
            ->select('operaciones.pedimento', 'auditorias.desglose_conceptos')
            ->get();

        $this->info("Paso 2/3: Se encontraron {$operaciones->count()} operaciones pendientes de auditoría.");
         if ($operaciones->count() == 0) {
            $this->info('No hay facturas Transportactics nuevas que auditar.');
            return 0;
        }

        // Aquí es donde extraemos el tipo de cambio del JSON.
        $indiceSC = [];
        foreach ($auditoriasSC as $auditoria) {
            // Laravel ya ha convertido el `desglose_conceptos` en un array gracias a la propiedad `$casts`.
            $desglose = $auditoria->desglose_conceptos;

            // Creamos la entrada en nuestro mapa.
            $indiceSC[$auditoria->pedimento] = [
                'monto_flete_sc' => (float)$desglose['montos']['flete'],
                'moneda' => $desglose['moneda'],
                 // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                 // para asignar un valor por defecto (ej. 1) si no se encuentra.
                'tipo_cambio' => (float)$desglose['tipo_cambio'] ?? 1.0,
            ];
        }

        $bar = $this->output->createProgressBar($operaciones->count());
        $bar->start();
        $fletesParaGuardar = [];
        foreach ($operaciones as $operacion) {
            // Buscamos en nuestros índices en memoria (búsqueda instantánea)
            $datosFlete = $indiceFletes[$operacion->pedimento] ?? null;
            $datosSC = $indiceSC[$operacion->pedimento] ?? null;
            if (!$datosFlete/* || $datosSC['monto_esperado_flete'] == 0 */) {
                $bar->advance();
                continue; // Si no tenemos todos los datos, saltamos a la siguiente operación.
            }

            // --- FASE 3: Procesar los archivos encontrados ---

            //En un futuro donde ya tengas implementadas las Sedes y series, cambia la linea de abajo, tanto de este comando
            //como el de los demas que sigan esta logica, por esta nueva:
            // $rutaXmlFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . $operacion->sede->serie . $datosFleteTxt['folio'] . '.xml';
            $rutaXmlFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . 'NOG' . $datosFlete['folio'] . '.xml';
            $rutaXmlFlete = file_exists($rutaXmlFlete) ? $rutaXmlFlete : 'No encontrado!';

            $rutaPdfFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . 'NOG' . $datosFlete['folio'] . '.pdf';
            $rutaPdfFlete = file_exists($rutaPdfFlete) ? $rutaPdfFlete : 'No encontrado!';

            $datosFlete = array_merge($datosFlete, $this->parsearXmlFlete($rutaXmlFlete) ?? [-1, 'n/a']);

            $montoFleteMXN = ($datosFlete['moneda'] == "USD" && $datosFlete['total'] != -1) ? $datosFlete['total'] * $datosSC[$operacion->pedimento] : $datosFlete['total'];
            $montoSCMXN = ($datosSC['moneda'] == "USD" && $datosSC['monto_flete_sc'] != -1) ? $datosSC['monto_flete_sc'] * $datosSC['tipo_cambio'] : $datosSC['monto_flete_sc'];
            $estado = $this->compararMontos($montoSCMXN, $montoFleteMXN);

            // Añadimos el resultado al array para el upsert masivo
            $fletesParaGuardar[] = [
                'operacion_id' => $operacion->id,
                'tipo_documento' => 'flete',
                'folio' => $datosFlete['folio'],
                'fecha_documento' => date('Y-m-d', date_timestamp_get(DateTime::createFromFormat('d/m/Y', $datosFlete['fecha']))),
                'monto_total' => $datosFlete['total'],
                'monto_total_mxn' => $montoFleteMXN,
                'moneda_documento' => $datosFlete['moneda'],
                'estado' => $estado,
                'ruta_xml' => $rutaXmlFlete,
                'ruta_txt' => $datosFlete['path_txt_tr'],
                'ruta_pdf' => $rutaPdfFlete,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $bar->advance();
        }
        $bar->finish();

         // --- FASE 5: Guardado Masivo en Base de Datos ---
        if (!empty($fletesParaGuardar)) {
            $this->info("\nPaso 3/3: Guardando/Actualizando " . count($fletesParaGuardar) . " registros de fletes...");

            Auditoria::upsert(
                $fletesParaGuardar,
                ['operacion_id', 'tipo_documento'], // Columna única para identificar si debe actualizar o insertar
                ['folio', 'fecha_documento', 'monto_total', 'monto_total_mxn', 'moneda_documento', 'estado', 'ruta_xml', 'ruta_txt', 'ruta_pdf', 'updated_at']
            );

            $this->info("¡Guardado con éxito!");
        }

        $this->info("\nAuditoría de Fletes finalizada.");
        return 0;
    }

    /**
     * Lee todos los TXT de Fletes recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceFletes(): array
    {
        $directorioFletes = config('reportes.rutas.tr_txt_filepath');
        $finder = new Finder();
        $finder->depth(0)
               ->path('NOG')
               ->in($directorioFletes)
               ->name('*.txt')
               ->date("since " . config('reportes.periodo_meses_busqueda', 2) . " months ago");

        $indice = [];
        foreach ($finder as $file) {
            $contenido = $file->getContents();
            // Refinamiento: Regex más preciso para el pedimento en la observación.
            if (preg_match('/(?<=\[encOBSERVACION\])(\d*\-*)(\d{7})/', $contenido, $matches)) {
                preg_match('/\[cteTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchFecha);
                preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);
                $pedimento = $matches[2];
                $indice[$pedimento] = [
                    'folio' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                    'path_txt_tr' => $file->getRealPath(),
                    'fecha' => isset($matchFecha[1]) ? trim($matchFecha[1]) : null,
                ];

            }
        }
        return $indice;
    }

    /**
     * Lee todos los TXT de SC recientes y crea un mapa [pedimento => [datos_de_la_sc]].
     */
    /* private function construirIndiceSC(): array
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
            // Extraemos el pedimento de la observación para usarlo como llave.
            if (preg_match('/(?<=\[encOBSERVACION\])(\d*\-*)(\d{7})/', $contenido, $matchPedimento)) {
                $pedimento = $matchPedimento[2];

                // Extraemos el monto esperado del flete.
                preg_match('/\[cteTEXTOEXTRA2\](.*?)(\r|\n)/', $contenido, $matchMonto);

                // Extraemos el folio del flete.
                // **** ¡AQUÍ NECESITAMOS EL CAMPO CORRECTO! ****
                preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);

                preg_match('/\[cteCODMONEDA\](.*?)(\r|\n)/', $contenido, $matchMoneda);
                    // Extraemos el tipo de cambio de [encTIPOCAMBIO] y en [cteIMPORTEEXTRA1]
                    $matchTCCount = preg_match('/\[cteIMPORTEEXTRA1\]([^\r\n]*)/', $contenido, $matchTC);
                    if($matchTCCount == 0){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    } elseif(($matchTC[1] == "1" && $matchMoneda[1] == "2")){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }

                $indice[$pedimento] = [
                    'pedimento' => $pedimento,
                    'monto_esperado_flete' => isset($matchMonto[1]) ? (float)trim($matchMonto[1]) : 0.0,
                    'folio' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                    'path_txt_sc' => $file->getRealPath(),
                    'moneda' => isset($matchMoneda[1]) && $matchMoneda[1] == "1" ? "MXN" : "USD",
                ];
            }
        }
        return $indice;
    } */


   // Dentro de la clase AuditarFletesCommand

    /**
     * Parsea un archivo XML de Transportactics y devuelve los datos clave.
     */
    private function parsearXmlFlete(string $rutaXml): ?array
    {
        if (!file_exists($rutaXml)) {
            $this->error("XML no encontrado en: {$rutaXml}");
            return [
                'total'  => -1,
                'moneda' => 'n/a',
            ];
        }

        try {
            // Usamos SimpleXMLElement, que es nativo de PHP.
            $xml = new \SimpleXMLElement(file_get_contents($rutaXml));

            // Devolvemos un array con los datos que nos interesan.
            return [
                'total'  => (float) $xml['Total'],
                'moneda' => (string) $xml['Moneda'],
            ];
        } catch (\Exception $e) {
            $this->error("Error al parsear el XML {$rutaXml}: " . $e->getMessage());
            return [
                'total'  => 0,
                'moneda' => 'n/a',
            ];
        }
    }

    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     */
    private function compararMontos(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin Flete!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }
}
