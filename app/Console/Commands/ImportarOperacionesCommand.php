<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Models\Operacion; // No olvides importar el modelo
use App\Models\Auditoria; // No olvides importar el modelo
use Illuminate\Support\Facades\Config; // Otra forma de acceder


class ImportarOperacionesCommand extends Command
{
     /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reporte:importar-operaciones'; // <--- ¡ESTA ES LA LÍNEA CLAVE!

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
    $this->info('Iniciando lectura de PDF con Python y Tabula...');
    $rutaPdf = config('reportes.rutas.bbva_edc');
    $this->info("Procesando: {$rutaPdf}");

    if (!file_exists($rutaPdf)) { /* ... manejo de error ... */ }

    $process = new Process(['python', base_path('scripts/python/parser.py'), $rutaPdf]);

    try {
        $process->mustRun();
        $jsonOutput = $process->getOutput();
        $jsonOutput = mb_convert_encoding($jsonOutput, "utf-8");
        // Ahora este json_decode debería funcionar sin problemas y sin encode previo.
        $tablaDeDatos = json_decode($jsonOutput, true);

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
        $operacionesLimpias = $coleccionDeFilas->map(function ($fila) {
            // Verificamos que la fila tenga al menos 3 celdas (Fecha, Concepto, Cargo).
            if (isset($fila[0], $fila[1], $fila[2])) {
                $textoConcepto = $fila[1];
                // El Regex para encontrar el pedimento que ya conocemos.
                $patron = '/PEDMTO:\s*([\w-]+)/';

                if (preg_match($patron, $textoConcepto, $match)) {
                    // Si es una fila de pedimento válida, devolvemos un array limpio y estructurado.
                    return [
                        'pedimento' => $match[1],
                        'fecha_str' => $fila[0],
                        'cargo_str' => $fila[2],
                    ];
                }
            }
            return null; // Si no es una fila de pedimento, la marcamos para ser eliminada.
        })->filter(); // El método filter() elimina todos los resultados 'null'.

        $this->info("Se identificaron {$operacionesLimpias->count()} operaciones válidas para procesar.");
        // ... (después del ->filter() que nos da $operacionesLimpias)

        // Preparamos un array con TODOS los registros que vamos a guardar/actualizar
        $datosParaOperaciones = $operacionesLimpias->map(function ($op) {
            // ... (tu lógica para limpiar cargo_str y obtener Ids) ...
            preg_match('/[^$\s\r\n].*/', $op['cargo_str'], $matchCargo);
            return [
                'pedimento' => $op['pedimento'],
                'fecha_operacion' => \Carbon\Carbon::createFromFormat('d-m', $op['fecha_str'])->format('Y-m-d'),
                'cliente_id' => 1, // Placeholder
                'sede_id' => 1,    // Placeholder
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all(); // ->all() lo convierte de nuevo a un array simple


        // --- Llamamos a upsert UNA SOLA VEZ con todos los datos ---
        if (!empty($datosParaOperaciones)) {
            // PASO 1: Asegurarte de que todas las operaciones existen en la base de datos.
            // Esta parte de tu código está perfecta.
            Operacion::upsert(
                $datosParaOperaciones,
                ['pedimento'], // Columna(s) única(s) para la comparación
                ['fecha_operacion', 'cliente_id', 'sede_id', 'updated_at'] // Columnas a actualizar si ya existe
            );

            // --- COMIENZA LA NUEVA LÓGICA EFICIENTE ---

            // PASO 2: Obtener todos los pedimentos que acabamos de procesar en un array simple.
            // Usamos el método `pluck` de las colecciones de Laravel, que es perfecto para esto.
            $pedimentos = $operacionesLimpias->pluck('pedimento');

            // PASO 3: Hacer UNA SOLA consulta para traer todas las operaciones de la DB.
            // Esto es muchísimo más rápido que buscar una por una dentro de un bucle.
            $operaciones = Operacion::whereIn('pedimento', $pedimentos)->get();

            // PASO 4: Crear un "mapa" para una búsqueda instantánea.
            // Convertimos la colección de operaciones en un array asociativo donde la CLAVE es el 'pedimento'.
            // Ahora podemos acceder a una operación así: $operacionMap['PEDIMENTO_123']
            $operacionMap = $operaciones->keyBy('pedimento');


            // PASO 5: Construir el array para las auditorías, usando nuestro mapa.
            // Pasamos el mapa a la clausula `use` para que esté disponible dentro del `map`.
            $datosParaAuditorias = $operacionesLimpias->map(function ($op) use ($operacionMap) {

                $pedimento = $op['pedimento'];

                // Verificación de seguridad: si por alguna razón la operación no se encontró, omitimos este registro.
                if (!isset($operacionMap[$pedimento])) {
                    return null;
                }

                // ¡LA MAGIA! Obtenemos el ID de la operación desde nuestro mapa, sin consultar la DB.
                $operacionId = $operacionMap[$pedimento]->id;

                preg_match('/[^$\s\r\n].*/', $op['cargo_str'], $matchCargo);

                // Devolvemos el array completo, AHORA con el `operacion_id` correcto.
                return [
                    'operacion_id' => $operacionId, // ¡Aquí está la vinculación!
                    'tipo_documento' => 'edc',
                    'fecha_documento' => \Carbon\Carbon::createFromFormat('d-m', $op['fecha_str'])->format('Y-m-d'),
                    'monto_total' => (float) str_replace(',', '', $matchCargo[0] ?? '0'),
                    'monto_total_mxn' => (float) str_replace(',', '', $matchCargo[0] ?? '0'),
                    'moneda_documento' => 'MXN',
                    'ruta_pdf' => config('reportes.rutas.bbva_edc'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->filter()->all(); // ->filter() elimina cualquier valor `null` que hayamos retornado en la verificación de seguridad.


            // PASO 6: Hacer el upsert a la tabla de auditorías.
            // Este código ya estaba casi perfecto, solo ajustamos los nombres de las columnas a actualizar.
            if (!empty($datosParaAuditorias)) {
                Auditoria::upsert(
                    $datosParaAuditorias,
                    ['operacion_id', 'tipo_documento'], // La llave única correcta
                    [
                        'fecha_documento',
                        'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                        'monto_total_mxn',
                        'moneda_documento',
                        'ruta_pdf',
                        'updated_at'
                    ]
                );
            }
            $this->info('¡Guardado con éxito!');
        }
        $this->info('¡Base de datos de operaciones actualizada con éxito!');
        return 0;

    } catch (ProcessFailedException $exception) {
        $this->error('Falló el script de Python: ' . $exception->getErrorOutput());
        return 1;
    }
}
}
