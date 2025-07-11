<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Models\Pedimento;
use App\Models\Importacion; // Tu modelo para 'operaciones_importacion'
use App\Models\Sucursales;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;
use Symfony\Component\Finder\Finder;

class AuditarLlcCommand extends Command
{
    protected $signature = 'reporte:auditar-llc {--tarea_id= : El ID de la tarea a procesar}';
    protected $description = 'Audita las facturas LLC contra las operaciones de la SC.';

    public function handle() {
        $tareaId = $this->option('tarea_id');
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("LLC: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }
        $this->info('Iniciando la auditoría de facturas LLC...');

        try {
            // --- FASE 1: Construir Índices en Memoria ---
            // 1. Obtenemos los datos necesarios de la Tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                $this->info("LLC: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }
            $this->info("Procesando LLC para Tarea #{$tarea->id} en la sucursal: {$sucursal}");

            $this->info('Construyendo índice de facturas LLC...');
            // 2. Construimos el índice de LLC desde los archivos TXT
            $indiceLLC = $this->construirIndiceLLC($sucursal);
            $this->info("LOG: Se encontraron ".count($indiceLLC)." facturas LLC.");

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

            $this->info("Facturas SC encontradas con relacion a LLCs: ". $auditoriasSC->count());

            // Aquí es donde extraemos el tipo de cambio del JSON.
            $indiceSC = [];
            foreach ($auditoriasSC as $auditoria) {
                // Laravel ya ha convertido el `desglose_conceptos` en un array gracias a la propiedad `$casts`.
                $desglose = $auditoria->desglose_conceptos;
                $arrPedimento = $mapaPedimentoAId[$auditoria->pedimento_id];
                // Creamos la entrada en nuestro mapa.
                $indiceSC[$arrPedimento['pedimento']] =
                [
                    'monto_llc_sc' => (float)$desglose['montos']['llc'],
                    'monto_llc_sc_mxn' => (float)$desglose['montos']['llc_mxn'],
                    'moneda' => $desglose['moneda'],
                    // Accedemos al tipo de cambio. Usamos el 'null coalescing operator' (??)
                    // para asignar un valor por defecto (ej. 1) si no se encuentra.
                    'tipo_cambio' => (float)$desglose['tipo_cambio'] ?? 1.0,
                ];
            }

            $this->info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");
            $bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            $bar->start();
            $llcsParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoId => $numPedimento) {
                $operacionId = $mapaPedimentoAImportacionId[$numPedimento['pedimento']] ?? null;

                $datosSC = $indiceSC[$numPedimento['pedimento']] ?? null;
                $rutaTxtLlc = $indiceLLC[$numPedimento['pedimento']] ?? null;

                if (!$rutaTxtLlc) {
                    $bar->advance();
                    continue;
                } elseif (!$datosSC && $rutaTxtLlc) {
                    //Aqui es cuando hay LLC pero no existe SC para esta factura.\\
                    $datosSC =
                    [
                        'monto_llc_sc'      => -1,
                        'monto_llc_sc_mxn'  => -1,
                        'tipo_cambio'       => -1,
                        'moneda'            => 'N/A',
                    ];
                }

                // --- FASE 3: Parsear el TXT de la LLC ---
                $datosLlc = $this->parsearTxtLlc($rutaTxtLlc, $sucursal);
                if (!$datosLlc) {
                    $bar->advance();
                    continue;
                }

                $montoLLCMXN = $datosSC['monto_llc_sc'] != -1 ?  round($datosLlc['monto_total'] * $datosSC['tipo_cambio'], 2, PHP_ROUND_HALF_UP) : -1;
                $montoSCMXN = $datosSC['monto_llc_sc_mxn'];
                // --- FASE 4: Comparar y Preparar Datos ---
                $estado = $this->compararMontos($montoSCMXN, $montoLLCMXN);

                $llcsParaGuardar[] =
                [
                    'operacion_id'      => $operacionId,
                    'pedimento_id'      => $pedimentoId,
                    'operation_type'    => "Intactics\Operaciones\Importacion",
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
                Auditoria::upsert(
                    $llcsParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'],  // Columna única para identificar si debe actualizar o insertar
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

            $this->info("\nAuditoría de LLC finalizada.");
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
     * Parsea un archivo TXT de una factura LLC para extraer el folio, la fecha
     * y, lo más importante, la suma de todos los montos.
     *
     * @param string $rutaTxt La ruta al archivo .txt de la LLC.
     * @return array|null Un array con los datos o null si falla.
     */
    private function parsearTxtLlc(string $rutaTxt, string $sucursal): ?array
    {
        if (!file_exists($rutaTxt)) { return null; }

        // Leemos todas las líneas del archivo en un array
        $lineas = file($rutaTxt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $datos =
        [
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
        $datos['ruta_pdf'] = config('reportes.rutas.llc_pdf_filepath') . DIRECTORY_SEPARATOR . $sucursal . $datos['folio'] . '.pdf';
        // Solo devolvemos los datos si encontramos un folio y un monto.
        return ($datos['folio'] && $datos['monto_total'] > 0) ? $datos : null;
    }

   /**
     * Construye un índice de los archivos TXT de las LLC. Mapa: [pedimento => ruta_del_archivo]
     */
    private function construirIndiceLLC(string $sucursal): array
    {
        $directorio = config('reportes.rutas.llc_txt_filepath'); // Necesitaremos añadir esta ruta
        if($sucursal == 'NL' || $sucursal == 'REY') { $sucursal = 'LDO'; }
        $finder = new Finder();
        $finder->depth(0)->path($sucursal)->in($directorio)->name('*.txt')->date("since " . config('reportes.periodo_meses_busqueda', 2) . " months ago");

        $indice = [];
        foreach ($finder as $file) {
            $contenido = $file->getContents();
            // Buscamos el pedimento en el campo de notas
            if (preg_match('/(?<=\[encPdfRemarksNote3\])(.*)(\b\d{7}\b)/', $contenido, $matchPedimento)) {
                $pedimento = trim($matchPedimento[2]);
                $indice[$pedimento] = $file->getRealPath();
            }
        }
        return $indice;
    }

    /**
     * Compara dos montos y devuelve el estado de la auditoría.
     */
    private function compararMontos(float $esperado, float $real): string
    {
        if($esperado == -1){ return 'Sin SC!'; }
        if($real == -1){ return 'Sin LLC!'; }
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
        foreach ($posiblesCoincidencias as $pedimentoSucio)
        {
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
