<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Models\Pedimento;
use App\Models\Importacion; // Tu modelo para 'operaciones_importacion'
use App\Models\Sucursales;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;
use Illuminate\Support\Facades\Config; // Otra forma de acceder


class ImportarOperacionesCommand extends Command
{
     /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reporte:importar-operaciones {--tarea_id= : El ID de la tarea a procesar}'; // <--- ¡ESTA ES LA LÍNEA CLAVE!

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inicia el proceso de auditoría y genera el Reporte de Impuestos (RDI)'; // <--- Y esta es la descripción.

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $tareaId = $this->option('tarea_id');

        if (!$tareaId) {
            $this->error('Se requiere el ID de la tarea. Usa --tarea_id=X');
            return 1;
        }

        // 1. Busca la primera tarea que esté pendiente
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("Impuestos: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }
        if (!$tarea) {
            $this->error("No se encontró la tarea con ID: {$tareaId}");
            return 1;
        }

        $this->info('Iniciando lectura de PDF con Python y Tabula...');


        // 2. Usa los datos del registro de la tarea
        $rutaPdf = storage_path('app/' . $tarea->ruta_estado_de_cuenta);
        $banco = $tarea->banco;
        $sucursal = $tarea->sucursal;

        $this->info("Procesando tarea #{$tarea->id} para el banco {$banco} y sucursal {$sucursal}");
        $this->info("Procesando: {$rutaPdf}");

        if (!file_exists($rutaPdf)) {
            $tarea->update(
            [
                'status' => 'fallido',
                'resultado' => "Ruta pdf no encontrada: ({$rutaPdf})"
            ]);
            return 1;
        }

        $process = new Process(['python', base_path('scripts/python/parser.py'), $rutaPdf]);

        try {
            $process->mustRun();
            $jsonOutput = $process->getOutput();
            $jsonOutput = mb_convert_encoding($jsonOutput, "utf-8");
            // Ahora este json_decode debería funcionar sin problemas y sin encode previo.
            $tablaDeDatos = json_decode($jsonOutput, true);

            preg_match('/(?<=\d{2}\/\d{2}\/)\d{4}/', $tablaDeDatos[0][0], $matchYear); //Aqui se localiza el año del estado de cuenta - BBVA (CAMBIALO LUEGO)

            if (is_null($tablaDeDatos) || isset($tablaDeDatos['error'])) {
                $this->error("Error al decodificar el JSON o error devuelto por Python.");
                // Si hay error en Python, lo mostramos:
                if(isset($tablaDeDatos['error'])) { $this->error("Detalle: " . $tablaDeDatos['error']); }
                return 1;
            }

            // Convertimos el array de datos crudos en una Colección de Laravel.
            $coleccionDeFilas = collect($tablaDeDatos);
            $this->info("Tabula y Python procesaron el PDF y encontraron {$coleccionDeFilas->count()} filas de datos crudos.");

            // 3. Usamos map() para transformar y filter() para limpiar la colección.
            $operacionesLimpias = $coleccionDeFilas->map(function ($fila) use ($matchYear) {
                // Verificamos que la fila tenga al menos 3 celdas (Fecha, Concepto, Cargo).
                if (isset($fila[0], $fila[1], $fila[2])) {
                    $textoConcepto = $fila[1];
                    // El Regex para encontrar el pedimento que ya conocemos.
                    $patron = '/PEDMTO:\s*([\w-]+)/';

                    if (preg_match($patron, $textoConcepto, $match)) {
                        // Si es una fila de pedimento válida, devolvemos un array limpio y estructurado.
                        return
                        [
                            'pedimento' => $match[1],
                            'fecha_str' => $fila[0],
                            'cargo_str' => $fila[2],
                        ];
                    }
                }
                return null; // Si no es una fila de pedimento, la marcamos para ser eliminada.
            })->filter(); // El método filter() elimina todos los resultados 'null'.

            if ($operacionesLimpias->isEmpty()) {
                $this->info('No se encontraron operaciones válidas para procesar.');
                return 0;
            }

            // Preparamos un array con TODOS los registros que vamos a guardar/actualizar
            $datosParaOperaciones = $operacionesLimpias->map(function ($op){ return ['pedimento' => $op['pedimento']];} )->all(); // ->all() lo convierte de nuevo a un array simple

            $this->info("Se identificaron ". count($datosParaOperaciones) . "/{$operacionesLimpias->count()} operaciones válidas para procesar.");
            // --- Llamamos a upsert UNA SOLA VEZ con todos los datos ---
            if (!empty($datosParaOperaciones)) {
                // 1. Obtenemos todos los números de pedimento únicos del estado de cuenta
                $numerosDePedimento = $operacionesLimpias->pluck('pedimento')->unique()->toArray();
                $this->info("Pedimentos del estado de cuenta: ". count($datosParaOperaciones));
                // 2. Hacemos UNA SOLA consulta para obtener los IDs de esos pedimentos
                //    y creamos un mapa: num_pedimento => id_pedimiento
                $mapaPedimentoAId = $this->construirMapaDePedimentos($numerosDePedimento);
                $this->info("Pedimentos encontrados en tabla 'pedimentos': ". count($mapaPedimentoAId));

                // 3. Hacemos UNA SOLA consulta a operaciones_importacion usando los IDs que encontramos
                //    y creamos nuestro mapa final: num_pedimento => id_importacion
                $mapaPedimentoAImportacionId = Importacion::whereIn('operaciones_importacion.id_pedimiento', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                    ->join('pedimiento', 'operaciones_importacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                    ->pluck('operaciones_importacion.id_importacion', 'pedimiento.num_pedimiento');
                $this->info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': ". $mapaPedimentoAImportacionId->count());

                // 3. Obtenemos todas las SC de una vez para la comparación de montos
                $auditoriasSC = AuditoriaTotalSC::query()
                    ->whereIn('operacion_id', $mapaPedimentoAImportacionId->values())
                    ->orWhereIn('pedimento_id', Arr::pluck($mapaPedimentoAId, 'id_pedimiento'))
                    ->get()
                    ->keyBy('pedimento_id'); // Las indexamos por operacion_id para búsqueda rápida
                $this->info("Facturas SC encontradas con relacion a Impuestos: ". $auditoriasSC->count());

                // PASO 5: Construir el array para las auditorías, usando nuestro mapa.
                // Pasamos el mapa a la clausula `use` para que esté disponible dentro del `map`.
                $datosParaAuditorias = $operacionesLimpias->map(function ($op)

                use ($rutaPdf, $auditoriasSC, $mapaPedimentoAId, $mapaPedimentoAImportacionId, $tarea) {
                    $pedimento = $op['pedimento'];

                    // Obtenemos el id_importacion desde nuestro mapa. Si no existe, omitimos este registro.
                    $operacionId = $mapaPedimentoAImportacionId[$pedimento] ?? null;
                    $pedimentoId = $mapaPedimentoAId[$pedimento] ?? null;

                    if (!$operacionId && !$pedimentoId) {
                        $this->warn("Omitiendo pedimento no encontrado en operaciones_importacion: {$pedimento}");
                        return null; // Marcamos para ser filtrado
                    }

                    // Buscamos la SC correspondiente en nuestro mapa de SCs
                    $sc = $auditoriasSC->get($pedimentoId['id_pedimiento']);
                    $desgloseSc = $sc ? $sc->desglose_conceptos : null;
                    $montoSCMXN = $desgloseSc['montos']['impuestos_mxn'] ?? -1.1; // -1.1 = Sin SC!


                    preg_match('/[^$\s\r\n].*/', $op['cargo_str'], $matchCargo);
                    $montoImpuestoMXN = (float) filter_var($matchCargo[0], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $estado = $this->compararMontos($montoSCMXN, $montoImpuestoMXN);

                    // Devolvemos el array completo, AHORA con el `operacion_id` correcto.
                    return
                    [
                        'operacion_id'      => $operacionId, // ¡Aquí está la vinculación auxiliar!
                        'pedimento_id'      => $pedimentoId['id_pedimiento'], // ¡Aquí está la vinculación!
                        'tipo_documento'    => 'impuestos',
                        'concepto_llave'    => 'principal',
                        'fecha_documento'   => \Carbon\Carbon::createFromFormat('d-m', $op['fecha_str'])->format('Y-m-d'),
                        'monto_total'       => (float) str_replace(',', '', $matchCargo[0] ?? '0'),
                        'monto_total_mxn'   => (float) str_replace(',', '', $matchCargo[0] ?? '0'),
                        'moneda_documento'  => 'MXN',
                        'estado'            => $estado,
                        'ruta_pdf'          => $rutaPdf,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];
                })->filter()->all(); // ->filter() elimina cualquier valor `null` que hayamos retornado en la verificación de seguridad.

                $this->info("Pedimentos con impuestos listos para subir: ". count($datosParaAuditorias));



                // PASO 6: Hacer el upsert a la tabla de auditorías.
                // Este código ya estaba casi perfecto, solo ajustamos los nombres de las columnas a actualizar.
                if (!empty($datosParaAuditorias)) {
                    Auditoria::upsert(
                        $datosParaAuditorias,
                        ['operacion_id', 'pedimento_id', 'tipo_documento', 'concepto_llave'], // La llave única correcta
                        [
                            'fecha_documento',
                            'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                            'monto_total_mxn',
                            'moneda_documento',
                            'estado',
                            'ruta_pdf',
                            'updated_at'
                        ]);
                }
                $this->info('¡Guardado con éxito!');
            }
            $this->info('¡Base de datos de operaciones actualizada con éxito!');

            // --- ¡NUEVA LÓGICA! ---
            // Guardamos la lista de pedimentos procesados en la tarea para que los siguientes comandos la usen.
            if ($operacionesLimpias->isNotEmpty()) {
                $pedimentosProcesados = $operacionesLimpias->pluck('pedimento')->unique()->values()->all();
                $tarea->update(['pedimentos_procesados' => json_encode($pedimentosProcesados)]);
                $this->info("Se registraron " . count($pedimentosProcesados) . " pedimentos en la Tarea #{$tareaId}.");
            }
            // --- FIN DE LA NUEVA LÓGICA ---

            $this->info("Procesamiento de Impuestos para la Tarea #{$tareaId} finalizado.");
            return 0;

        } catch (ProcessFailedException $exception) {
            Log::error('Falló el script de Python: ' . $exception->getErrorOutput());
            $this->error('Falló el script de Python: ' . $exception->getErrorOutput());
            return 1;

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

    private function compararMontos(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'EXPO'; }
        if($esperado == -1.1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin Flete!'; }
        // Usamos una pequeña tolerancia (epsilon) para comparar números flotantes
        // y evitar problemas de precisión.
        if (abs($esperado - $real) < 0.001) { return 'Coinciden!'; }
        //LA SC SIEMPRE DEBE DE TENER MAS CANTIDAD, SI TIENE MENOS, SIGNIFICA PERDIDA
        return ($esperado > $real) ? 'Pago de mas!' : 'Pago de menos!';
    }

    /**
     * Construye un mapa [num_pedimento_limpio => id_pedimiento] buscando coincidencias
     * parciales en la base de datos para manejar datos "sucios".
     *
     * @param array $pedimentosLimpios Array de números de pedimento de 7 dígitos.
     * @return array El mapa final.
     */
    private function construirMapaDePedimentos(array $pedimentosLimpios): array {
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
