<?php

namespace App\Console\Commands;
use DateTime;
use Illuminate\Console\Command;
use App\Models\Operacion;
use App\Models\Flete;
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
        $this->info('Paso 1/4: Construyendo índice de archivos de Facturas SC...');
        $indiceSC = $this->construirIndiceSC();
        $operacionesSC = Operacion::whereIn('pedimento', array_keys($indiceSC))->get();

        $this->info('Paso 2/4: Construyendo índice de archivos de Fletes...');
        $indiceFletes = $this->construirIndiceFletes();

        // --- FASE 2: Auditar Operaciones Pendientes ---
        $operaciones = Operacion::whereIn('pedimento', array_keys($indiceFletes))->get();
        //$operaciones = Operacion::whereIn('pedimento', array_keys($indiceFletes))->whereDoesntHave('flete')->get();
        $this->info("Paso 3/4: Se encontraron {$operaciones->count()} operaciones pendientes de auditoría.");

        $bar = $this->output->createProgressBar($operaciones->count());
        $bar->start();
        $fletesParaGuardar = [];
        foreach ($operaciones as $operacion) {
            // Buscamos en nuestros índices en memoria (búsqueda instantánea)
            $datosSC = $indiceSC[$operacion->pedimento] ?? null;
            $datosFlete = $indiceFletes[$operacion->pedimento] ?? null;

            if (!$datosSC || !$datosFlete/* || $datosSC['monto_esperado_flete'] == 0 */) {
                $bar->advance();
                continue; // Si no tenemos todos los datos, saltamos a la siguiente operación.
            }

            // --- FASE 3: Procesar los archivos encontrados ---
            $folioFlete = $datosFlete['folio'] ?? null;
            if (!$folioFlete) {
                $this->warn("\nNo se pudo extraer el folio del archivo: {$datosFlete['path_txt_tr']}");
                $bar->advance();
                continue;
            }
            //En un futuro donde ya tengas implementadas las Sedes y series, cambia la linea de abajo, tanto de este comando
            //como el de los demas que sigan esta logica, por esta nueva:
            // $rutaXmlFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . $operacion->sede->serie . $datosFleteTxt['folio'] . '.xml';
            $rutaXmlFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . 'NOG' . $folioFlete . '.xml';
            $rutaPdfFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . 'NOG' . $folioFlete . '.pdf';
            $datosFlete = array_merge($datosFlete, $this->parsearXmlFlete($rutaXmlFlete) ?? [0, 'n/a']);

            if (!$datosFlete || $datosFlete['moneda'] == 'n/a') {
                $this->error("\nNo se pudo leer el XML del Flete: {$rutaXmlFlete}");
                $bar->advance();
                continue;
            }
             // --- FASE 4: Comparar y Preparar Datos para Guardar ---
            $estado = $this->compararMontos($datosSC['monto_esperado_flete'], $datosFlete['total']);


             // Añadimos el resultado al array para el upsert masivo
            $fletesParaGuardar[] = [
                'operacion_id' => $operacion->id,
                'folio' => $datosFlete['folio'],
                'fecha' => date('Y-m-d', date_timestamp_get(DateTime::createFromFormat('d/m/Y', $datosFlete['fecha']))),
                'monto_total' => $datosFlete['total'],
                'moneda' => $datosFlete['moneda'],
                'ruta_xml' => $rutaXmlFlete,
                'ruta_txt' => $datosFlete['path_txt_tr'],
                'ruta_pdf' => file_exists($rutaPdfFlete) ? $rutaPdfFlete : null,
                'monto_esperado_sc' => $datosSC['monto_esperado_flete'],
                'monto_esperado_mxn' => $datosSC['monto_esperado_flete'], // Asumiendo MXN por ahora
                'estado' => $estado,
                'updated_at' => now(),
            ];

            $bar->advance();
        }
        $bar->finish();

         // --- FASE 5: Guardado Masivo en Base de Datos ---
        if (!empty($fletesParaGuardar)) {
            $this->info("\nGuardando/Actualizando " . count($fletesParaGuardar) . " registros de fletes...");

            Flete::upsert(
                $fletesParaGuardar,
                ['operacion_id'], // Columna única para identificar si debe actualizar o insertar
                ['folio', 'fecha', 'monto_total', 'moneda', 'ruta_xml', 'ruta_txt', 'ruta_pdf', 'monto_esperado_sc', 'monto_esperado_mxn', 'estado', 'updated_at']
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
            if (preg_match('/\[encOBSERVACION\][^\d]*(\d{7})/', $contenido, $matches)) {
                preg_match('/\[cteTEXTOEXTRA3\](.*?)(\r|\n)/', $contenido, $matchFecha);
                preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);
                $pedimento = $matches[1];
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
    private function construirIndiceSC(): array
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
            if (preg_match('/\[encOBSERVACION\][^\d]*(\d{7})/', $contenido, $matchPedimento)) {
                $pedimento = $matchPedimento[1];

                // Extraemos el monto esperado del flete.
                preg_match('/\[cteTEXTOEXTRA2\](.*?)(\r|\n)/', $contenido, $matchMonto);

                // Extraemos el folio del flete.
                // **** ¡AQUÍ NECESITAMOS EL CAMPO CORRECTO! ****
                preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);

                $indice[$pedimento] = [
                    'pedimento' => $pedimento,
                    'monto_esperado_flete' => isset($matchMonto[1]) ? (float)trim($matchMonto[1]) : 0.0,
                    'folio' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                    'path_txt_sc' => $file->getRealPath()
                ];
            }
        }
        return $indice;
    }


   // Dentro de la clase AuditarFletesCommand

    /**
     * Parsea un archivo XML de Transportactics y devuelve los datos clave.
     */
    private function parsearXmlFlete(string $rutaXml): ?array
    {
        if (!file_exists($rutaXml)) {
            $this->error("XML no encontrado en: {$rutaXml}");
            return [
                'total'  => 0,
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
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) {
            return 'Coinciden!';
        }

        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }
}
