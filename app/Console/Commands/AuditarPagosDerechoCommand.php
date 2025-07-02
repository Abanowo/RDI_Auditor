<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Operacion;
use App\Models\Auditoria;
use App\Models\AuditoriaTotalSC;
use Symfony\Component\Finder\Finder;
use Spatie\PdfToText\Pdf;

class AuditarPagosDerechoCommand extends Command
{
    protected $signature = 'reporte:auditar-pagos-derecho';
    protected $description = 'Busca y procesa los archivos PDF de Pagos de Derecho para cada operación.';

    public function handle()
    {
        $this->info('Iniciando la auditoría de Pagos de Derecho...');

        // 1. Construimos el índice de TODOS los PDFs de Pagos de Derecho UNA SOLA VEZ.
        $this->info('Construyendo índice de archivos de Pagos de Derecho...');
        $indicePagosDerecho = $this->construirIndicePagosDeDerecho();
        $this->info("Índice construido. Se encontraron facturas para " . count($indicePagosDerecho) . " pedimentos.");

        // 2. Obtenemos solo las operaciones que están en nuestro índice y que no han sido auditadas.
        //--ESTE DE ABAJO ES PARA ACTUALIZAR TODA LA TABLA CON LOS PAGOS DE DERECHO RECIENTES, EN CASO DE QUE SE HAYA HECHO UN CAMBIO
        $operaciones = Operacion::whereIn('pedimento', array_keys($indicePagosDerecho))->get();

         //--Y ESTE ES PARA UNICAMENTE CREAR REGISTROS PARA LOS PAGOS DE DERECHO NUEVOS
        /* $operaciones =  Operacion::query()
            // 1. Filtramos para considerar solo las operaciones que nos interesan (opcional pero recomendado).
            ->whereIn('pedimento', array_keys($indicePagosDerecho))

            // 2. Aquí está la magia. Buscamos operaciones que no tengan una auditoría
            //    que cumpla con la condición que definimos dentro de la función.
            ->whereDoesntHave('auditorias', function ($query) {
                // 3. La condición: el tipo_documento debe ser 'sc'.
                // Esta sub-consulta se ejecuta sobre la tabla 'auditorias'.
                $query->where('tipo_documento', 'pago_derecho');
            })
            ->get(); */


        $this->info("Se encontraron {$operaciones->count()} operaciones nuevas para auditar.");
        if ($operaciones->count() === 0) return 0;

        $bar = $this->output->createProgressBar($operaciones->count());
        $bar->start();

        $pagosParaGuardar = [];

        foreach ($operaciones as $operacion) {
            // 2. Buscamos el(los) PDF(s) de Pago de Derecho para este pedimento.
            $rutasPdfs = $indicePagosDerecho[$operacion->pedimento];

            foreach ($rutasPdfs as $rutaPdf) {
                // 3. Parseamos cada PDF encontrado.
                $datosPago = $this->parsearPdfPagoDeDerecho($rutaPdf);

                if ($datosPago) {

                    // 4. Si obtuvimos datos, los acumulamos para el guardado masivo.
                    $pagosParaGuardar[] = [
                        'operacion_id'       => $operacion->id,
                        'tipo_documento'     => 'pago_derecho',
                        'concepto_llave'     => $datosPago['llave_pago'],
                        'fecha_documento'    => $datosPago['fecha_pago'],
                        'monto_total'        => $datosPago['monto_total'],
                        'monto_total_mxn'    => $datosPago['monto_total'],
                        'moneda_documento'   => 'MXN',
                        'estado'             => $datosPago['tipo'],
                        'llave_pago_pdd'     => $datosPago['llave_pago'],
                        'num_operacion_pdd'  => $datosPago['numero_operacion'],
                        'ruta_pdf'           => $rutaPdf,
                        'created_at'         => now(),
                        'updated_at'         => now(),
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
                ['operacion_id', 'tipo_documento', 'concepto_llave'], // Columna única para identificar si debe actualizar o insertar
                ['fecha_documento', 'monto_total', 'monto_total_mxn', 'moneda_documento', 'estado', 'llave_pago_pdd', 'num_operacion_pdd', 'ruta_pdf', 'updated_at']
            );
            $this->info("¡Guardado con éxito!");
        }

        $this->info("\nAuditoría de Pagos de Derecho finalizada.");
        return 0;
    }


    /**
     * Escanea el directorio de Pagos de Derecho una vez y crea un mapa
     * de [pedimento => [lista_de_rutas_pdf]].
     */
    private function construirIndicePagosDeDerecho(): array
    {
        $directorio = config('reportes.rutas.pagos_de_derecho');
        $mesesABuscar = config('reportes.periodo_meses_busqueda', 3);
        $fechaLimite = new \DateTime("-{$mesesABuscar} months");

        $indice = [];
        $finder = new Finder();

        try {
            // Buscamos todos los PDFs recientes en todas las subcarpetas de sede (ZLO, NOG, etc.)
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
        } catch (\Exception $e) {
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
            $pdftotextPath = config('reportes.rutas.pdftotext_path');
            $texto = (new Pdf($pdftotextPath))->setPdf($rutaPdf)->text();
            $datos = [];

            // Lógica para detectar el tipo de banco y aplicar el Regex correcto
            if (str_contains($texto, 'Creando Oportunidades')) { // Es BBVA
                // Regex para BBVA
                preg_match('/No\.\s*de\s*Operaci.n:\s*(\d+)/', $texto, $matchOp);
                preg_match('/Llave\s*de\s*Pago:\s*([A-Z0-9]+)/', $texto, $matchLlave);
                preg_match('/Total\s*Efectivamente\s*Pagado:\s*\$ ([\d,.]+)/', $texto, $matchMonto);
                preg_match('/Fecha\s*y\s*Hora\s*del\s*Pago:\s*(\d{2}\/\d{2}\/\d{4})/', $texto, $matchFecha);

                $datos['numero_operacion'] = $matchOp[1] ?? null;
                $datos['llave_pago'] = $matchLlave[1] ?? null;
                $datos['monto_total'] = isset($matchMonto[1]) ? (float)str_replace(',', '', $matchMonto[1]) : 0;
                $datos['fecha_pago'] = isset($matchFecha[1]) ? \Carbon\Carbon::createFromFormat('d/m/Y', $matchFecha[1])->format('Y-m-d') : null;

            } else { // Asumimos que es Santander
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
            $this->error("\nError al parsear el PDF: {$rutaPdf} - " . $e->getMessage());
            return null;
        }
    }

}

