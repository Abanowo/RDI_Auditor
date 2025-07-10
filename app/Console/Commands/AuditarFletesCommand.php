<?php

namespace App\Console\Commands;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Models\Pedimento;
use App\Models\Importacion; // Tu modelo para 'operaciones_importacion'
use App\Models\Sucursales;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;
use Symfony\Component\Finder\Finder;

class AuditarFletesCommand extends Command
{
    /**
     * La firma de nuestro comando.
     */
    protected $signature = 'reporte:auditar-fletes {--tarea_id= : El ID de la tarea a procesar}';

    /**
     * La descripción de nuestro comando.
     */
    protected $description = 'Audita las facturas de Fletes (Transportactics) contra las operaciones de la SC.';

    /**
     * Orquesta el proceso completo de la auditoría de Fletes.
     */
    public function handle()
    {
        $tareaId = $this->option('tarea_id');
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("Fletes: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }

        $this->info('Iniciando la auditoría de Fletes (Transportactics)...');
        try {
            // --- FASE 1: Construir Índices en Memoria para Búsquedas Rápidas ---
            // 1. Obtenemos los datos necesarios de la Tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                $this->info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }

            $this->info("Procesando Fletes para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            $this->info('Paso 1/3: Construyendo índice de archivos de Fletes...');
            // 2. Construimos el índice de Fletes desde los archivos TXT
            $indiceFletes = $this->construirIndiceFletes($sucursal);
            $this->info("LOG: Se encontraron ".count($indiceFletes)." facturas de Transportactics.");

            $mapaPedimentoAId = $this->construirMapaDePedimentos($pedimentos);
            $this->info("Pedimentos encontrados en tabla 'pedimentos': ". count($mapaPedimentoAId));

            // 3. Mapeamos los pedimentos a sus id_importacion
            $mapaPedimentoAImportacionId = Importacion::query()
                ->join('pedimiento', 'operaciones_importacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                ->whereIn('pedimiento.num_pedimiento', $pedimentos)
                ->pluck('operaciones_importacion.id_importacion', 'pedimiento.num_pedimiento');
            $this->info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': ". $mapaPedimentoAImportacionId->count());

            // 4. Obtenemos los montos esperados de las SC de una sola vez
            $auditoriasSC = AuditoriaTotalSC::query()
                ->whereIn('operacion_id', $mapaPedimentoAImportacionId->values())
                ->orWhereIn('pedimento_id', array_keys($mapaPedimentoAId))
                ->get()
                ->keyBy('pedimento_id'); // Las indexamos por operacion_id para búsqueda rápida
            $this->info("Facturas SC encontradas con relacion a Fletes: ". $auditoriasSC->count());

            // Aquí es donde extraemos el tipo de cambio del JSON.
            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                // Laravel ya ha convertido el `desglose_conceptos` en un array gracias a la propiedad `$casts`.
                $desglose = $auditoria->desglose_conceptos;
                $arrPedimento = $mapaPedimentoAId[$auditoria->pedimento_id];
                // Creamos la entrada en nuestro mapa.
                $indiceSC[$arrPedimento['pedimento']] =
                [
                    'monto_flete_sc' => (float)$desglose['montos']['flete'],
                    'monto_flete_sc_mxn' => (float)$desglose['montos']['flete_mxn'],
                    'moneda' => $desglose['moneda'],
                    // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                    // para asignar un valor por defecto (ej. 1) si no se encuentra.
                    'tipo_cambio' => (float)$desglose['tipo_cambio'] ?? 1.0,
                ];
            }

            $this->info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");
            $bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            $bar->start();
            $fletesParaGuardar = [];
            foreach ($mapaPedimentoAId as $pedimentoId => $numPedimento) {
                $operacionId = $mapaPedimentoAImportacionId[$numPedimento['pedimento']] ?? null;

                // Buscamos en nuestros índices en memoria (búsqueda instantánea)
                $datosFlete = $indiceFletes[$numPedimento['pedimento']] ?? null;
                $datosSC = $indiceSC[$numPedimento['pedimento']] ?? null;
                if (!$datosFlete/* || $datosSC['monto_esperado_flete'] == 0 */) {
                    $bar->advance();
                    continue; // Si no tenemos todos los datos, saltamos a la siguiente operación.
                }
                if (!$datosSC) {
                    //Aqui es cuando hay LLC pero no existe SC para esta factura.\\
                    $datosSC =
                        [
                            'monto_flete_sc' => -1,
                            'monto_flete_sc_mxn' => -1,
                            'tipo_cambio'        => -1,
                            'moneda'             => 'N/A',
                        ];
                }
                // --- FASE 3: Procesar los archivos encontrados ---

                //En un futuro donde ya tengas implementadas las sucursals y series, cambia la linea de abajo, tanto de este comando
                //como el de los demas que sigan esta logica, por esta nueva:
                // $rutaXmlFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . $operacion->sucursal->serie . $datosFleteTxt['folio'] . '.xml';
                $rutaXmlFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . $sucursal . $datosFlete['folio'] . '.xml';
                $rutaXmlFlete = file_exists($rutaXmlFlete) ? $rutaXmlFlete : 'No encontrado!';

                $rutaPdfFlete = config('reportes.rutas.tr_pdf_filepath') . DIRECTORY_SEPARATOR . $sucursal . $datosFlete['folio'] . '.pdf';
                $rutaPdfFlete = file_exists($rutaPdfFlete) ? $rutaPdfFlete : 'No encontrado!';

                $datosFlete = array_merge($datosFlete, $this->parsearXmlFlete($rutaXmlFlete) ?? [-1, 'N/A']);


                $montoFleteMXN = (($datosFlete['moneda'] == "USD" && $datosFlete['total'] != -1) && $datosSC['tipo_cambio'] != -1) ? round($datosFlete['total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : $datosFlete['total'];
                $montoSCMXN = $datosSC['monto_flete_sc_mxn'];
                $estado = $this->compararMontos($montoSCMXN, $montoFleteMXN);

                // Añadimos el resultado al array para el upsert masivo
                $fletesParaGuardar[] =
                [
                    'operacion_id'      => $operacionId,
                    'pedimento_id'      => $pedimentoId,
                    'operation_type'    => "Intactics\Operaciones\Importacion",
                    'tipo_documento'    => 'flete',
                    'concepto_llave'    => 'principal',
                    'folio'             => $datosFlete['folio'],
                    'fecha_documento'   => date('Y-m-d', date_timestamp_get(DateTime::createFromFormat('d/m/Y', $datosFlete['fecha']))),
                    'monto_total'       => $datosFlete['total'],
                    'monto_total_mxn'   => $montoFleteMXN,
                    'moneda_documento'  => $datosFlete['moneda'],
                    'estado'            => $estado,
                    'ruta_xml'          => $rutaXmlFlete,
                    'ruta_txt'          => $datosFlete['path_txt_tr'],
                    'ruta_pdf'          => $rutaPdfFlete,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];

                $bar->advance();
            }
            $bar->finish();

            // --- FASE 5: Guardado Masivo en Base de Datos ---
            if (!empty($fletesParaGuardar)) {
                $this->info("\nPaso 3/3: Guardando/Actualizando " . count($fletesParaGuardar) . " registros de fletes...");

                Auditoria::upsert(
                    $fletesParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], // La llave única correcta
                    [
                        'fecha_documento',
                        'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                        'monto_total_mxn',
                        'moneda_documento',
                        'estado',
                        'ruta_pdf',
                        'updated_at'
                    ]);

                $this->info("¡Guardado con éxito!");
            }

            $this->info("\nAuditoría de Fletes finalizada.");
            return 0;
        } catch (\Exception $e) {
            // 5. Si algo falla, marca la tarea como 'fallido' y guarda el error
            $tarea->update(
                [
                    'status' => 'fallido',
                    'resultado' => $e->getMessage()
                ]);
            Log::error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
            $this->error("Falló la tarea #{$tarea->id}: " . $e->getMessage());
        }
    }

    /**
     * Lee todos los TXT de Fletes recientes y crea un mapa [pedimento => ruta_del_archivo].
     */
    private function construirIndiceFletes(string $sucursal): array
    {
        $directorioFletes = config('reportes.rutas.tr_txt_filepath');
        $finder = new Finder();
        if($sucursal == 'NL' || $sucursal == 'REY') { $sucursal = 'LAR'; }
        $finder->depth(0)
            ->path($sucursal)
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
                $indice[$pedimento] =
                [
                    'folio' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                    'path_txt_tr' => $file->getRealPath(),
                    'fecha' => isset($matchFecha[1]) ? trim($matchFecha[1]) : null,
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
            return
                [
                    'total'  => -1,
                    'moneda' => 'N/A',
                ];
        }

        try {
            // Usamos SimpleXMLElement, que es nativo de PHP.
            $xml = new \SimpleXMLElement(file_get_contents($rutaXml));

            // Devolvemos un array con los datos que nos interesan.
            return
                [
                    'total'  => (float) $xml['Total'],
                    'moneda' => (string) $xml['Moneda'],
                ];
            } catch (\Exception $e) {
                $this->error("Error al parsear el XML {$rutaXml}: " . $e->getMessage());
                return
                [
                    'total'  => -1,
                    'moneda' => 'N/A',
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
        if (abs($esperado - $real) < 0.001) { return 'Coinciden!'; }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
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
                    //Ahora el 'id_pedimiento' es el que servira de indice
                    $mapaFinal[$pedimentoSucio->id_pedimiento] =
                        [
                            'pedimento' =>$pedimentoLimpio,
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
