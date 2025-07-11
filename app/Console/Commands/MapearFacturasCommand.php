<?php

namespace App\Console\Commands;

use Illuminate\Http\File;
use App\Models\Pedimento;
use App\Models\Exportacion;
use App\Models\Importacion; // Tu modelo para 'operaciones_importacion'
use App\Models\Sucursales;
use App\Models\AuditoriaTareas; // Asegúrate de que la ruta a tu modelo Tarea es correcta
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MapearFacturasCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reporte:mapear-facturas {--tarea_id= : El ID de la tarea a procesar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Centraliza la obtención de todas las facturas y URLs de una tarea y guarda el resultado en un archivo de mapeo.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $tareaId = $this->option('tarea_id');
        if (!$tareaId) {
            $this->error('Se requiere el argumento --tarea_id.');
            return 1;
        }

        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea) {
            $this->error("No se encontró la Tarea con ID: {$tareaId}");
            return 1;
        }

        $this->info("--- [INICIO] Mapeo de facturas para Tarea #{$tarea->id} ---");
        Log::info("Tarea #{$tarea->id}: Iniciando mapeo de facturas...");

        try {
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                $this->info("Fletes: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }
            $this->info("Procesando Facturas SC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            // 1. Obtenemos los números de pedimento de nuestro índice
            $numerosDePedimento = $pedimentos;

            $mapaPedimentoAId = $this->construirMapaDePedimentos($numerosDePedimento);
            $numerosDePedimento = collect($mapaPedimentoAId)->pluck('num_pedimiento')->toArray();
            $this->info("Pedimentos encontrados en tabla 'pedimentos': ". count($mapaPedimentoAId));

            // 2. MAPEADO EFICIENTE DE IDS
            // PROCESAR IMPORTACIONES
            $mapaPedimentoAImportacionId = Importacion::query()
                ->join('pedimiento', 'operaciones_importacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                ->whereIn('pedimiento.num_pedimiento', $numerosDePedimento)
                ->pluck('operaciones_importacion.id_importacion', 'pedimiento.num_pedimiento');
            $this->info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': ". $mapaPedimentoAImportacionId->count());

            // 2. MAPEADO EFICIENTE DE IDS
            // PROCESAR EXPORTACIONES
            $mapaPedimentoAExportacionId = Exportacion::query()
                ->join('pedimiento', 'operaciones_exportacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                ->whereIn('pedimiento.num_pedimiento', $numerosDePedimento)
                ->pluck('operaciones_exportacion.id_exportacion', 'pedimiento.num_pedimiento');
            $this->info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_exportacion': ". $mapaPedimentoAExportacionId->count());

            // --- LOGICA PARA DETECTAR LOS NO ENCONTRADOS
            //Esto lo hago debido a que hay pedimentos que estan bastante sucios que ni se pueden encontrar
            //Un ejemplo es que haya dos registros con exactamente el mismo valor, pero con la diferencia de que tiene un carrete
            //un enter o una tabulacion en el registro, volviendola 'unica'. Y aqui lo que hare es mostrar esos pedimentos que
            //causan confusion y los subire a la tabla de tareas para que queden expuestos ante todo el mundo! awawaw

            // 1. Preparamos la búsqueda REGEXP para la base de datos
            $regexPattern = implode('|', array_unique($numerosDePedimento)); // Usamos array_unique para una query más corta
            $posiblesCoincidencias = Pedimento::where('num_pedimiento', 'REGEXP', $regexPattern)->get();

            // 2. Creamos un mapa de los pedimentos que nos falta por encontrar.
            //    OJO: Esta vez lo creamos a partir de la lista original CON duplicados.
            $pedimentosPorEncontrar = array_count_values($numerosDePedimento);
            $mapaNoEncontrados = [];

            // 3. (LÓGICA CORREGIDA) Recorremos los resultados de la BD
            foreach ($posiblesCoincidencias as $pedimentoSucio) {
                if (empty($pedimentosPorEncontrar)) {
                    break;
                }

                $pedimentoObtenido = $pedimentoSucio->num_pedimiento;

                foreach ($pedimentosPorEncontrar as $pedimentoLimpio => $cantidad) {
                    if (str_contains($pedimentoObtenido, $pedimentoLimpio)) {
                        // ----- INICIO DE LA CORRECCIÓN -----

                        // a. Mapeamos la coincidencia (opcional, igual que antes)
                        if ($pedimentosPorEncontrar[$pedimentoLimpio] > 1) {
                            $mapaNoEncontrados[$pedimentoLimpio] = [
                            'id_pedimento' => $pedimentoSucio->id_pedimiento,
                            'num_pedimiento' => $pedimentoObtenido,
                        ];
                        }


                        // b. Restamos 1 al contador de este pedimento.
                        $pedimentosPorEncontrar[$pedimentoLimpio]--;

                        // c. Si ya encontramos todas las ocurrencias, lo eliminamos de la lista.
                        if ($pedimentosPorEncontrar[$pedimentoLimpio] === 0) {
                            unset($pedimentosPorEncontrar[$pedimentoLimpio]);
                        }

                        // d. Rompemos el bucle interno. Un pedimento sucio solo puede "satisfacer"
                        //    a un pedimento limpio por pasada. Esto lo hace más rápido y correcto.
                        break;

                        // ----- FIN DE LA CORRECCIÓN -----
                    }
                }
            }

            // 5. Mostramos los que quedaron en la lista de pendientes. ESTO SÍ FUNCIONARÁ.
            if (!empty($pedimentosPorEncontrar)) {
                $tarea->update([
                    'pedimentos_descartados' => $pedimentosPorEncontrar
                ]);
                $this->warn("Subiendo pedimentos no encontrados!");
            } else {
                $this->info("¡Todos los pedimentos fueron encontrados y mapeados correctamente!");
            }

            // --- OJO: Aqui se gastan muchos recursos en el mapeo!!!
            // CONSTRUIR EL ÍNDICE - IMPORTACIONES
            $indiceImportaciones = $this->construirIndiceFacturas($mapaPedimentoAImportacionId, $sucursal);

            // CONSTRUIR EL ÍNDICE - EXPORTACIONES
            $indiceExportaciones = $this->construirIndiceFacturas($mapaPedimentoAExportacionId, $sucursal);

            $mapeadoOperacionesID =
            [
                'pedimentos_totales'        => $mapaPedimentoAId,
                'pedimentos_no_encontrados' => $mapaNoEncontrados,
                'pedimentos_importacion'    => $mapaPedimentoAImportacionId,
                'pedimentos_exportacion'    => $mapaPedimentoAExportacionId,
                'indices_importacion'       => $indiceImportaciones,
                'indices_exportacion'       => $indiceExportaciones,

            ];
            // 3. GUARDAR EL ÍNDICE EN UN ARCHIVO PRIVADO CON NOMBRE HASHEADO
            // Usamos json_encode para convertir el array en un string JSON.
            // JSON_PRETTY_PRINT hace que el archivo sea fácil de leer para un humano.
            $contenidoJson = json_encode($mapeadoOperacionesID, JSON_PRETTY_PRINT);

            // a) Creamos un archivo temporal y guardamos nuestro JSON en él.
            $tempFilePath = tempnam(sys_get_temp_dir(), 'mapeo_json_');
            file_put_contents($tempFilePath, $contenidoJson);

            // b) Usamos Storage::putFile() para que Laravel genere el hash y lo guarde.
            // Esto es el equivalente a ->store()
            $rutaRelativa = Storage::putFile(
                'mapeo_completo_facturas', // La carpeta destino dentro de storage/app
                new File($tempFilePath) // Le pasamos el archivo temporal
            );
            // Storage::path() convierte la ruta relativa en la ruta completa del sistema de archivos.
            $rutaAbsoluta = Storage::path($rutaRelativa);

            $this->info("Mapeo guardado exitosamente en: {$rutaRelativa}");

            // 4. ACTUALIZAR LA TAREA CON LA RUTA RELATIVA DEL ARCHIVO
            $tarea->update([
                'mapeo_completo_facturas' => $rutaRelativa
            ]);
            $this->info("Ruta del mapeo guardada en la Tarea #{$tarea->id}.");

            $this->info("--- [FIN] Mapeo de facturas completado con éxito. ---");
            return 0;

        } catch (\Exception $e) {
            $this->error("Falló el mapeo de facturas para la Tarea #{$tarea->id}: " . $e->getMessage());
            Log::error("Fallo en Tarea #{$tarea->id} [reporte:mapear-facturas]: " . $e->getMessage());
            $tarea->update(['status' => 'fallido', 'resultado' => 'Error al generar el mapeo de facturas.' . $e->getMessage() ]);
            return 1;
        }
    }

    /**
     * Lógica central para obtener y procesar los archivos de la API.
     */
    private function construirIndiceFacturas(Collection $importacionPedimentos, string $sucursal): array
    {
        $indiceFacturas = [];
        if($sucursal == 'NL' || $sucursal == 'REY') { $sucursal = 'NL'; }

        $mapeoFacturas =
        [
            'HONORARIOS-SC' => 'sc',
            'TransporTactics' => 'flete',
            'HONORARIOS-LLC' => 'llc',
            'PAGOS-DE-DERECHOS' => 'pago_derecho',
        ];
        foreach ($importacionPedimentos as $pedimento => $operacionID) {
            try {

                // --- 1. OBTENCIÓN DE DATOS ---
                // Usamos el cliente HTTP de Laravel que es más seguro y maneja errores.
                //Urls
                // ARREGLO TEMPORAL PARA SSL: Usamos withoutVerifying() para saltar la verificación del certificado SSL.
                // ¡¡¡IMPORTANTE!!! Esto es solo para desarrollo local. Eliminar en producción.
                //$url_txt = Http::withoutVerifying()->get("https://sistema.intactics.com/v3/operaciones/exportaciones/{$operacionID}/get-files-txt-momentaneo");
                $url_pdf = Http::withoutVerifying()->get("https://sistema.intactics.com/v3/operaciones/exportaciones/{$operacionID}/get-files-momentaneo");

                if (!$url_pdf->successful()) {
                    // Si la API falla para este ID, lo saltamos y continuamos con el siguiente.
                    $this->warn("No se pudieron obtener los archivos para la importación ID: {$operacionID}");
                    Log::warning("No se pudieron obtener los archivos para la importación ID: {$operacionID}");
                    continue;
                }

                //Resultados JSON del get
                //$archivos_txt = json_decode($url_txt);
                $archivos_pdf = collect($url_pdf->json());

                // Inicializamos la entrada para el pedimento actual
                $indiceFacturas[$pedimento] = [
                    'operacion_id' => $operacionID,
                    'facturas' => [], // Aquí guardaremos las facturas encontradas
                ];

                // --- 3. FILTRADO Y PROCESAMIENTO DE FACTURAS (Nueva Lógica) ---
                $agrupadorTemp = [];

                $archivosFacturas = $archivos_pdf->whereIn('pivot.type', array_keys($mapeoFacturas));

                foreach ($archivosFacturas as $archivo) {
                    $tipoJson = $archivo['pivot']['type'];
                    $url = $archivo['url']['normal'];
                    $fechaCreacion = $archivo['created_at'];
                    $fechaActualizacion = $archivo['updated_at'];
                    // Usamos el nombre del archivo sin extensión para agrupar PDF y XML
                    $nombreBase = pathinfo($archivo['name'], PATHINFO_FILENAME);
                    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

                    // Si aún no existe una entrada para este nombre base, la creamos.
                    if (!isset($agrupadorTemp[$nombreBase])) {
                        $agrupadorTemp[$nombreBase] = [
                            'creation_date'  => $fechaCreacion,
                            'update_date'    => $fechaActualizacion,
                            'tipo_documento' => $mapeoFacturas[$tipoJson], // Usamos el nombre amigable
                            'ruta_pdf'       => null,
                            'ruta_xml'       => null,
                            'ruta_txt'       => null,
                        ];
                    }

                    // Asignamos la URL a la clave correcta según su extensión.
                    if ($extension === 'pdf') {
                        $agrupadorTemp[$nombreBase]['ruta_pdf'] = $url;
                    } elseif ($extension === 'xml') {
                        $agrupadorTemp[$nombreBase]['ruta_xml'] = $url;
                    }

                    // Obtenemos las rutas txt y su contenido
                    if ($tipoJson != 'PAGOS-DE-DERECHOS') {
                        switch($tipoJson){
                            case 'HONORARIOS-SC':
                            case 'TransporTactics':
                                $agrupadorTemp[$nombreBase]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/{$nombreBase}.txt";
                                break;

                            case 'HONORARIOS-LLC':
                                $agrupadorTemp[$nombreBase]['ruta_txt'] = "https://sistema.intactics.com/v2/uploads/llc-{$nombreBase}.txt";
                                break;
                        }
                    }
                }

                // Asignamos las facturas agrupadas y limpias al resultado final.
                // array_values() reinicia los índices del array para que sea una lista limpia.
                $indiceFacturas[$pedimento]['facturas'] = array_values($agrupadorTemp);

            } catch (\Exception $e) {
                $this->error("Ocurrió un error procesando la importación ID {$operacionID}: " . $e->getMessage());
                Log::error("Ocurrió un error procesando la importación ID {$operacionID}: " . $e->getMessage());
                // Aseguramos que haya una entrada para este pedimento aunque falle, para evitar errores posteriores.
                if (!isset($indiceFacturas[$pedimento])) {
                     $indiceFacturas[$pedimento] = ['error' => $e->getMessage()];
                }
            }

        }

        return $indiceFacturas;
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
