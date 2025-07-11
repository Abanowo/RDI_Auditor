<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Smalot\PdfParser\Parser;
use App\Models\Pedimento;
use App\Models\Importacion; // Tu modelo para 'operaciones_importacion'
use App\Models\Sucursales;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;
use Symfony\Component\Finder\Finder;
use Spatie\PdfToText\Pdf;

class AuditarPagosDerechoCommand extends Command
{
    protected $signature = 'reporte:auditar-pagos-derecho {--tarea_id= : El ID de la tarea a procesar}';
    protected $description = 'Busca y procesa los archivos PDF de Pagos de Derecho para cada operación.';

    public function handle()
    {
        $tareaId = $this->option('tarea_id');
        $tarea = AuditoriaTareas::find($tareaId);
        if (!$tarea || $tarea->status !== 'procesando') {
            $this->warn("Pagos derecho: No se encontró la tarea #{$tareaId} o no está en estado 'procesando'.");
            return 1;
        }
        $this->info('Iniciando la auditoría de Pagos de Derecho...');
        try
        {
            // --- FASE 1: Construir Índices en Memoria ---
            // 1. Obtenemos los datos necesarios de la Tarea
            $sucursal = $tarea->sucursal;
            $pedimentosJson = $tarea->pedimentos_procesados;
            $pedimentos = $pedimentosJson ? json_decode($pedimentosJson, true) : [];

            if (empty($pedimentos)) {
                $this->info("Pagos derecho: No hay pedimentos en la Tarea #{$tareaId} para procesar.");
                return 0;
            }

            //Construimos el índice de TODOS los PDFs de Pagos de Derecho UNA SOLA VEZ.
            $this->info('Construyendo índice de archivos de Pagos de Derecho...');
            $indicePagosDerecho = $this->construirIndicePagosDeDerecho();
            $this->info("Índice construido. Se encontraron facturas para " . count($indicePagosDerecho) . " pedimentos.");

            $mapaPedimentoAId = $this->construirMapaDePedimentos($pedimentos);
            $this->info("Pedimentos encontrados en tabla 'pedimentos': ". count($mapaPedimentoAId));

            // 3. Mapeamos los pedimentos a sus id_importacion
            $mapaPedimentoAImportacionId = Importacion::query()
                ->join('pedimiento', 'operaciones_importacion.id_pedimiento', '=', 'pedimiento.id_pedimiento')
                ->whereIn('pedimiento.num_pedimiento', $pedimentos)
                ->pluck('operaciones_importacion.id_importacion', 'pedimiento.num_pedimiento');
            $this->info("Pedimentos encontrados en tabla 'pedimentos' y en 'operaciones_importacion': ". $mapaPedimentoAImportacionId->count());

            $this->info("Iniciando vinculacion de los " . count($mapaPedimentoAId) . " pedimentos.");
            $bar = $this->output->createProgressBar(count($mapaPedimentoAId));
            $bar->start();
            $pagosParaGuardar = [];

            foreach ($mapaPedimentoAId as $pedimentoId => $numPedimento) {
                // 2. Buscamos el(los) PDF(s) de Pago de Derecho para este pedimento.
                $rutasPdfs = $indicePagosDerecho[$numPedimento['pedimento']] ?? null;
                if (!$rutasPdfs) {
                    $bar->advance();
                    continue;
                }
                foreach ($rutasPdfs as $rutaPdf) {
                    $operacionId = $mapaPedimentoAImportacionId[$numPedimento['pedimento']] ?? null;

                    //Parseamos cada PDF encontrado.
                    $datosPago = $this->parsearPdfPagoDeDerecho($rutaPdf);

                    if ($datosPago) {

                        // 4. Si obtuvimos datos, los acumulamos para el guardado masivo.
                        $pagosParaGuardar[] =
                        [
                            'operacion_id'      => $operacionId,
                            'pedimento_id'      => $pedimentoId,
                            'operation_type'    => "Intactics\Operaciones\Importacion",
                            'tipo_documento'    => 'pago_derecho',
                            'concepto_llave'    => $datosPago['llave_pago'],
                            'fecha_documento'   => $datosPago['fecha_pago'],
                            'monto_total'       => $datosPago['monto_total'],
                            'monto_total_mxn'   => $datosPago['monto_total'],
                            'moneda_documento'  => 'MXN',
                            'estado'            => $datosPago['tipo'],
                            'llave_pago_pdd'    => $datosPago['llave_pago'],
                            'num_operacion_pdd' => $datosPago['numero_operacion'],
                            'ruta_pdf'          => $rutaPdf,
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ];
                    }
                }
                $bar->advance();
            }

            $bar->finish();

            // 5. Guardado Masivo con upsert
            if (!empty($pagosParaGuardar)) {
                $this->info("\nGuardando/Actualizando " . count($pagosParaGuardar) . " registros de Pagos de Derecho...");
                // Usamos la llave de pago como identificador único para el upsert.

                Auditoria::upsert(
                    $pagosParaGuardar,
                    ['operacion_id', 'pedimento_id', 'operation_type', 'tipo_documento', 'concepto_llave'], // Columna única para identificar si debe actualizar o insertar
                    [
                        'fecha_documento',
                        'monto_total', // Asegúrate que estos nombres coincidan con tu migración
                        'monto_total_mxn',
                        'moneda_documento',
                        'estado',
                        'llave_pago_pdd',
                        'num_operacion_pdd',
                        'ruta_pdf',
                        'updated_at'
                    ]);
                $this->info("¡Guardado con éxito!");
            }

            $this->info("\nAuditoría de Pagos de Derecho finalizada.");
            return 0;
        }
        catch (\Exception $e) {
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
     * Escanea el directorio de Pagos de Derecho una vez y crea un mapa
     * de [pedimento => [lista_de_rutas_pdf]].
     */
    private function construirIndicePagosDeDerecho(): array {
        $directorio = config('reportes.rutas.pagos_de_derecho');
        $mesesABuscar = config('reportes.periodo_meses_busqueda', 3);
        $fechaLimite = new \DateTime("-{$mesesABuscar} months");

        $indice = [];
        $finder = new Finder();

        try {
            // Buscamos todos los PDFs recientes en todas las subcarpetas de sucursal (ZLO, NOG, etc.)
            $finder->in($directorio)->files()->name("*.pdf")->date(">= {$fechaLimite->format('Y-m-d')}");

            if ($finder->hasResults()) {
                foreach ($finder as $file) {
                    // Extraemos el pedimento del nombre del archivo.
                    // Este Regex busca 7 dígitos seguidos de un posible guion.
                    if (preg_match('/(\d{7})-?/', $file->getFilename(), $matches)) {
                        $pedimento = $matches[1];
                        // Añadimos la ruta al array de este pedimento.
                        $indice[$pedimento][] = $file->getRealPath();
                    }
                }
            }
        }
        catch (\Exception $e) {
            Log::error("Error construyendo el índice de Pagos de Derecho: " . $e->getMessage());
            $this->error("Error construyendo el índice de Pagos de Derecho: " . $e->getMessage());
        }

        return $indice;
    }



    /**
     * Parsea un PDF de Pago de Derecho para extraer los datos clave.
     * Debe ser lo suficientemente inteligente para detectar el formato (BBVA vs Santander).
     */
    private function parsearPdfPagoDeDerecho(string $rutaPdf): ?array
    {
        try {

            // 1. Crear una instancia del Parser.
            $parser = new Parser();

            // 2. Parsear el archivo PDF. Esto devuelve un objeto Pdf.
            $pdf = $parser->parseFile($rutaPdf);

            // 3. Obtener el texto de todas las páginas del documento.
            // El resultado es un string muy similar al que obtenías con pdftotext.
            $texto = $pdf->getText();
            // --- FIN DEL CAMBIO ---
            $datos = [];

            // Lógica para detectar el tipo de banco y aplicar el Regex correcto
            if (str_contains($texto, 'Creando Oportunidades')) {   // Es BBVA
                // Regex para BBVA
                preg_match('/No\.\s*de\s*Operaci.n:\s*(\d+)/', $texto, $matchOp);
                preg_match('/Llave\s*de\s*Pago:\s*([A-Z0-9]+)/', $texto, $matchLlave);
                preg_match('/Total\s*Efectivamente\s*Pagado:\s*\$ ([\d,.]+)/', $texto, $matchMonto);
                preg_match('/Fecha\s*y\s*Hora\s*del\s*Pago:\s*(\d{2}\/\d{2}\/\d{4})/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float)str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('d/m/Y', $matchFecha[1])->format('Y-m-d') : null;

            }
            else {
                // Asumimos que es Santander
                // Leemos la "cadena mágica" de la segunda página
                preg_match('/\|20002=(\d+)\|/', $texto, $matchOp);
                preg_match('/\|40008=([A-Z0-9]+)\|/', $texto, $matchLlave);
                preg_match('/\|10017=([\d,.]+)\|/', $texto, $matchMonto);
                preg_match('/\|40002=(\d{8})\|/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float)str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('Ymd', $matchFecha[1])->format('Y-m-d') : null;
            }

            // Lógica para determinar el 'tipo' (Normal, Medio, etc.) basado en el nombre del archivo
            if(str_contains($rutaPdf, 'MEDIO')) { $datos['tipo'] = 'Medio Pago'; }
            elseif(str_contains($rutaPdf, '-2')) { $datos['tipo'] = 'Segundo Pago'; }
            elseif(str_contains($rutaPdf, 'INTACTICS')) { $datos['tipo'] = 'Intactics'; }
            else { $datos['tipo'] = 'Normal'; }

            return $datos;

        } catch(\Exception $e) {
            Log::error("Error al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            $this->error("\nError al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            return null;
        }
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



