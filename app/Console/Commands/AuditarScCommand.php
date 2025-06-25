<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Operacion;
use App\Models\AuditoriaSc; // Importamos el nuevo modelo
use Symfony\Component\Finder\Finder;
use Spatie\Regex\Regex;
class AuditarScCommand extends Command
{
    protected $signature = 'reporte:auditar-sc';
    protected $description = 'Realiza la auditoría principal comparando el Estado de Cuenta contra la Factura SC.';

    public function handle()
    {
        $this->info('Iniciando la auditoría principal (Banco vs. SC)...');

        // 1. Obtenemos las operaciones que tienen un cargo del banco pero aún no han sido auditadas.
        $indiceSC = $this->construirIndiceSC();
         $this->info("LOG: Se encontraron ".count($indiceSC)." facturas SC.");
        $operacionesSC =  Operacion::whereIn('pedimento', array_keys($indiceSC))
                                    ->whereDoesntHave('auditoriaSc')
                                    ->get();



        $this->info("Se encontraron {$operacionesSC->count()} operaciones pendientes de auditoría de SC.");
        if ($operacionesSC->count() == 0) {
            $this->info('No hay nada que auditar. ¡Todo al día!');
            return 0;
        }

        $bar = $this->output->createProgressBar($operacionesSC->count());
        $bar->start();

        $auditoriasParaGuardar = [];
        foreach ($operacionesSC as $operacion) {
            // 2. Encontrar el archivo TXT de la SC para este pedimento.
            $datosSC = $indiceSC[$operacion->pedimento];

            if (!$datosSC) {
                // Opcional: Podríamos crear un registro con estado "Sin Factura SC".
                $bar->advance();
                continue;
            }

            $datosSC['monto_esperado_mxn'] = $datosSC['moneda'] == "USD" ? $datosSC['monto_impuestos'] *  $datosSC['tipo_cambio'] : $datosSC['monto_impuestos'];
            // 4. Realizar la comparación.
            $estado = $this->compararMontos((float)$operacion->cargo_edc, (float)$datosSC['monto_esperado_mxn']);
             $datosSC['monto_esperado_mxn'] = $datosSC['monto_impuestos'] < 0 ? $operacion->cargo_edc : $datosSC['monto_esperado_mxn'];
              $datosSC['monto_impuestos'] = $datosSC['monto_impuestos'] < 0 ? $operacion->cargo_edc : $datosSC['monto_impuestos'];
            $auditoriasParaGuardar[] = [
                'operacion_id'       => $operacion->id,
                'fecha_sc'           => $datosSC['fecha_sc'],
                'folio_sc'           => $datosSC['folio_sc'],
                'ruta_txt'           => $datosSC['ruta_txt'],
                'ruta_pdf'           => $datosSC['ruta_pdf'],
                'cargo_edc'          => $operacion->cargo_edc,
                'moneda'             => $datosSC['moneda'],
                'tipo_cambio'        => $datosSC['tipo_cambio'],
                'monto_impuestos_sc' => $datosSC['monto_impuestos'],
                'monto_impuestos_mxn' => $datosSC['monto_esperado_mxn'],
                'estado'             => $estado,
                'updated_at'         => now(),
            ];

            $bar->advance();
        }

        $bar->finish();
        // Guardamos todos los resultados en una sola consulta para máximo rendimiento.
        if (!empty($auditoriasParaGuardar)) {
            $this->info("\nGuardando/Actualizando " . count($auditoriasParaGuardar) . " resultados de auditoría...");
            AuditoriaSc::upsert(
                $auditoriasParaGuardar,
                ['operacion_id'], // Columna única para identificar
                ['fecha_sc', 'folio_sc', 'ruta_txt', 'ruta_pdf', 'cargo_edc', 'moneda', 'tipo_cambio', 'monto_impuestos_mxn', 'monto_impuestos_sc',  'estado', 'updated_at'] // Columnas a actualizar
            );
            $this->info('¡Guardado con éxito!');
        }

        $this->info("\nAuditoría de SC finalizada.");
        return 0;
    }

    // --- MÉTODOS DE AYUDA ---

    private function construirIndiceSC(): array
    {
        // Esta lógica es similar a la que hicimos para Fletes, pero apunta al directorio de SC.
        $directorioSC = config('reportes.rutas.sc_txt_filepath');
        $finder = new Finder();
        try {
            $finder->depth(0)
               ->path('NOG')
               ->in($directorioSC)
               ->name('*.txt')
               ->date("since " . config('reportes.periodo_meses_busqueda', 3) . " months ago");

            $indice = [];
            foreach ($finder as $file) {
                $contenido = $file->getContents();
                // Refinamiento: Regex más preciso para el pedimento en la observación.
                if (preg_match('/(?<=\[encOBSERVACION\])(\d*\-*)(\d{7})/', $contenido, $matchesPedimento)) {

                    $pedimento = trim($matchesPedimento[2]);
                    preg_match('/\[encTEXTOEXTRA1\](.*?)(\r|\n)/', $contenido, $matchMonto);
                    preg_match('/\[encFECHA\](.*?)(\r|\n)/', $contenido, $matchFecha);
                    preg_match('/\[encFOLIOVENTA\](.*?)(\r|\n)/', $contenido, $matchFolio);
                    preg_match('/\[cteCODMONEDA\](.*?)(\r|\n)/', $contenido, $matchMoneda);
                    // Extraemos el tipo de cambio de [encTIPOCAMBIO] y en [cteIMPORTEEXTRA1]
                    $matchTCCount = preg_match('/\[cteIMPORTEEXTRA1\]([^\r\n]*)/', $contenido, $matchTC);
                    if($matchTCCount == 0){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    } elseif(($matchTC[1] == "1" && $matchMoneda[1] == "2")){
                        preg_match('/\[encTIPOCAMBIO\]([^\r\n]*)/', $contenido, $matchTC);
                    }
                    // Construimos la ruta al PDF
                    $rutaPdf = config('reportes.rutas.sc_pdf_filepath') . DIRECTORY_SEPARATOR . $file->getBasename();
                    $rutaPdf = str_replace('.txt', '.pdf', $rutaPdf);

                    $indice[$pedimento] = [
                        'monto_impuestos' => isset($matchMonto[1]) && strlen($matchMonto[1]) > 0 ? (float)trim($matchMonto[1]) : -1,
                        'folio_sc' => isset($matchFolio[1]) ? trim($matchFolio[1]) : null,
                        'fecha_sc'  => isset($matchFecha[2]) ? \Carbon\Carbon::parse(trim($matchFecha[1]))->format('Y-m-d') : now(), //ESTO PUEDES DECIRLE QUE TE LO IGUAL A NULL, NO HAY FECHA DENTRO DE LA SC
                        'ruta_txt' => $file->getRealPath(),
                        'ruta_pdf' => file_exists($rutaPdf) ? $rutaPdf : null,
                        'moneda' => isset($matchMoneda[1]) && $matchMoneda[1] == "1" ? "MXN" : "USD",
                        'tipo_cambio' => isset($matchTC[1]) ? (float)trim($matchTC[1]) : 1.0,
                    ];

                }
            }
        } catch (\Exception $e) {
            $this->error("Error buscando archivo para pedimento {$pedimento}: " . $e->getMessage());
        }
        return $indice;
    }


    private function compararMontos(float $montoBanco, float $montoSC): string
    {   if ($montoSC < 0) return 'EXPO';
        if (abs($montoBanco - $montoSC) < 0.01) return 'Coinciden!';
        return ($montoBanco > $montoSC) ? 'Pago de menos!' : 'Pago de más!';
    }
}
