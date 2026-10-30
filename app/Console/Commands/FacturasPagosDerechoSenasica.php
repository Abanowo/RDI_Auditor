<?php

namespace App\Console\Commands;

use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\Pedimento;
use App\Services\Senasica\AutofacturaSenasica;
use App\Services\Senasica\ReceptorCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Timbra / descarga las facturas de los Pagos de Derecho (SENASICA) que no
 * están en el expediente. NO guarda nada en la base de datos: solo LEE los
 * pagos auditados y deja los archivos en storage.
 *
 *  - Si la factura ya está subida al expediente  -> no hace nada
 *  - Si ya se descargó antes (existe en storage)  -> no hace nada
 *  - Si el portal dice que ya estaba timbrada     -> AVISO y la descarga si el portal la ofrece
 *  - Si no estaba timbrada                        -> la timbra y la descarga
 *
 * Archivos: storage/app/senasica/facturas/{pedimento}/{llave}.pdf|.xml
 * Resumen de cada corrida: storage/app/senasica/resumenes/resumen_{fecha}.csv
 *
 * Ejemplos:
 *   php artisan senasica:facturas-pdd --simular
 *   php artisan senasica:facturas-pdd --llave=ABC123XYZ --debug
 *   php artisan senasica:facturas-pdd --tarea=103
 *   php artisan senasica:facturas-pdd --pedimento=6031393
 */
class FacturasPagosDerechoSenasica extends Command
{
    protected $signature = 'senasica:facturas-pdd
        {--tarea= : ID de la tarea de auditoría (usa sus pedimentos)}
        {--pedimento= : Pedimento (7 dígitos)}
        {--llave= : Una llave de pago específica}
        {--dias=30 : Solo pagos de los últimos N días}
        {--limite=20 : Máximo de pagos que entran al portal por corrida}
        {--forzar : Vuelve a entrar al portal aunque la factura ya esté descargada}
        {--sin-revisar-expediente : No revisa si la factura ya está subida al expediente}
        {--simular : Solo lista lo que haría, sin entrar al portal}
        {--debug : Guarda captura y HTML de cada paso en storage/app/senasica/debug}';

    protected $description = 'Timbra o descarga de facturasenasica.com las facturas de Pagos de Derecho que faltan en el expediente';

    public function handle(AutofacturaSenasica $autofactura, ReceptorCliente $receptores): int
    {
        if (!$this->validarConfiguracion($receptores)) {
            return self::FAILURE;
        }

        $pagos = $this->obtenerPagos();

        if ($pagos->isEmpty()) {
            $this->info('No hay pagos de derecho pendientes con los filtros indicados.');
            return self::SUCCESS;
        }

        $this->info('Pagos de derecho a revisar: ' . $pagos->count());

        $resumen = [];
        $avisos = [];
        $procesados = 0;
        $limite = (int) $this->option('limite');
        $carpetaBase = trim(config('senasica.carpeta', 'senasica/facturas'), '/');

        foreach ($pagos as $pago) {
            $llave = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $pago->llave_pago_pdd));
            $numOperacion = trim((string) $pago->num_operacion_pdd);
            $pedimento = $this->numeroPedimento($pago->pedimento_id);
            $pedimentoCorto = preg_match('/[4-7]\d{6}/', $pedimento, $m) ? $m[0] : 'SIN_PEDIMENTO';
            $carpeta = $carpetaBase . '/' . $pedimentoCorto;

            // 1. ¿Ya se descargó antes? (el propio archivo es el registro)
            $xmlPrevio = $carpeta . '/' . $llave . '.xml';
            $pdfPrevio = $carpeta . '/' . $llave . '.pdf';
            if (!$this->option('forzar') && (Storage::exists($xmlPrevio) || Storage::exists($pdfPrevio))) {
                $resumen[] = [$pedimentoCorto, $llave, 'ya_descargada', 'Ya está en ' . $carpeta . ' (usa --forzar para volver a bajarla)'];
                continue;
            }

            // 2. ¿Ya la subieron al expediente?
            if (!$this->option('sin-revisar-expediente') && $this->facturaEnExpediente($pago, $llave, $numOperacion)) {
                $resumen[] = [$pedimentoCorto, $llave, 'en_expediente', 'La factura ya está subida al expediente'];
                continue;
            }

            // Receptor = cliente de la operación
            $datosCliente = $receptores->paraPago($pago);
            $r = $datosCliente['receptor'];
            $descripcionCliente = ($r['nombre'] ?: $datosCliente['cliente'] ?: 'sin cliente')
                . ' | RFC ' . ($r['rfc'] ?: '¿?') . ' | Régimen ' . ($r['regimen'] ?: '¿?');

            if ($this->option('simular')) {
                $nota = empty($datosCliente['faltantes'])
                    ? ''
                    : ' | FALTA en el cliente: ' . implode(', ', $datosCliente['faltantes']) . ' (solo podría descargar si ya está timbrada)';
                $resumen[] = [$pedimentoCorto, $llave, 'simulado', "Operación {$numOperacion} | {$descripcionCliente}{$nota}"];
                continue;
            }

            if ($procesados >= $limite) {
                $resumen[] = [$pedimentoCorto, $llave, 'pendiente', "Límite de {$limite} por corrida; se hará en la siguiente"];
                continue;
            }

            // 3. Portal de SENASICA
            $this->line("→ Pedimento {$pedimentoCorto} | Llave {$llave} | Operación {$numOperacion}");
            $this->line("   Receptor: {$descripcionCliente}");

            $debugDir = $this->option('debug')
                ? storage_path('app/senasica/debug/' . $llave . '_' . now()->format('Ymd_His'))
                : null;

            $resultado = $autofactura->procesar($llave, $numOperacion, $pedimentoCorto, $debugDir, $r);
            $procesados++;

            $detalle = $resultado['mensaje'];
            if ($resultado['uuid']) {
                $detalle .= ' | UUID ' . $resultado['uuid'];
            }
            if ($resultado['ruta_pdf'] || $resultado['ruta_xml']) {
                $detalle .= ' | ' . implode(', ', array_filter([$resultado['ruta_pdf'], $resultado['ruta_xml']]));
            }
            $resumen[] = [$pedimentoCorto, $llave, $resultado['estado'], $detalle];

            if (in_array($resultado['estado'], ['sin_datos_cliente', 'revisar'], true)) {
                $avisos[] = "Pedimento {$pedimentoCorto}, llave {$llave}: NO se timbró. {$resultado['mensaje']}";
            }

            if ($resultado['estado'] === 'ya_timbrada') {
                $avisos[] = "Pedimento {$pedimentoCorto}, llave {$llave}: el portal indica que YA estaba timbrada"
                    . (($resultado['ruta_pdf'] || $resultado['ruta_xml']) ? ' (se descargó).' : ' (el portal no ofreció la descarga).');
            }

            Log::info("SENASICA PDD | Ped {$pedimentoCorto} | Llave {$llave} | {$resultado['estado']} | {$resultado['mensaje']}");

            if ($debugDir) {
                $this->line("   Evidencia guardada en: {$debugDir}");
            }

            sleep((int) config('senasica.pausa_segundos', 3));
        }

        $this->newLine();
        $this->table(['Pedimento', 'Llave de pago', 'Estado', 'Detalle'], array_map(function ($fila) {
            $fila[3] = mb_substr($fila[3], 0, 140);
            return $fila;
        }, $resumen));

        foreach ($avisos as $aviso) {
            $this->warn('AVISO: ' . $aviso);
            Log::warning('SENASICA PDD AVISO: ' . $aviso);
        }

        if (!$this->option('simular')) {
            $rutaResumen = $this->guardarResumen($resumen);
            $this->info("Resumen guardado en: storage/app/{$rutaResumen}");
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // SELECCIÓN DE PAGOS (solo lectura de la BD)
    // ------------------------------------------------------------------
    private function obtenerPagos(): Collection
    {
        $query = Auditoria::query()
            ->where('tipo_documento', 'pago_derecho')
            ->whereNotNull('llave_pago_pdd')->where('llave_pago_pdd', '!=', '')
            ->whereNotNull('num_operacion_pdd')->where('num_operacion_pdd', '!=', '')
            ->where('monto_total', '>', 0);

        if ($llave = $this->option('llave')) {
            $query->where('llave_pago_pdd', strtoupper(trim($llave)));
        } else {
            // El portal pide 48 h hábiles desde el pago
            $diasHabiles = (int) config('senasica.dias_habiles_minimos', 2);
            $query->whereDate('fecha_documento', '>=', now()->subDays((int) $this->option('dias'))->toDateString())
                  ->whereDate('fecha_documento', '<=', now()->subWeekdays($diasHabiles)->toDateString());
        }

        $idsPedimento = $this->idsPedimentoFiltrados();
        if ($idsPedimento !== null) {
            if (empty($idsPedimento)) {
                return collect();
            }
            $query->whereIn('pedimento_id', $idsPedimento);
        }

        return $query->orderBy('fecha_documento')
            ->get()
            ->unique(function ($a) { return strtoupper(trim($a->llave_pago_pdd)); })
            ->values();
    }

    /**
     * null = sin filtro de pedimento; [] = el filtro no encontró nada.
     */
    private function idsPedimentoFiltrados(): ?array
    {
        if (!$this->option('tarea') && !$this->option('pedimento')) {
            return null;
        }

        $digitos = [];

        if ($tareaId = $this->option('tarea')) {
            $tarea = AuditoriaTareas::find($tareaId);
            if (!$tarea) {
                $this->error("No existe la tarea #{$tareaId}.");
                return [];
            }

            // Primero los IDs del mapeo de la tarea (incluye rectificaciones)
            $ids = [];
            if ($tarea->mapeo_completo_facturas && Storage::exists($tarea->mapeo_completo_facturas)) {
                $mapeo = (array) json_decode(Storage::get($tarea->mapeo_completo_facturas), true);
                foreach (($mapeo['pedimentos_totales'] ?? []) as $datos) {
                    $ids = array_merge($ids, $datos['all_ids'] ?? [$datos['id_pedimiento'] ?? null]);
                }
            }
            $ids = array_values(array_unique(array_filter($ids)));
            if (!empty($ids)) {
                return $ids;
            }

            foreach ((array) json_decode((string) $tarea->pedimentos_procesados, true) as $p) {
                if (preg_match('/[4-7]\d{6}/', (string) $p, $m)) {
                    $digitos[] = $m[0];
                }
            }
        }

        if ($ped = $this->option('pedimento')) {
            if (preg_match('/[4-7]\d{6}/', $ped, $m)) {
                $digitos[] = $m[0];
            }
        }

        $digitos = array_values(array_unique($digitos));
        if (empty($digitos)) {
            return [];
        }

        return Pedimento::where(function ($q) use ($digitos) {
            foreach ($digitos as $d) {
                $q->orWhere('num_pedimiento', 'LIKE', "%{$d}%");
            }
        })->pluck('id_pedimiento')->all();
    }

    private function numeroPedimento($idPedimento): string
    {
        static $cache = [];
        if (!$idPedimento) {
            return '';
        }
        if (!array_key_exists($idPedimento, $cache)) {
            $cache[$idPedimento] = (string) (Pedimento::where('id_pedimiento', $idPedimento)->value('num_pedimiento') ?? '');
        }
        return trim($cache[$idPedimento]);
    }

    // ------------------------------------------------------------------
    // ¿YA ESTÁ LA FACTURA EN EL EXPEDIENTE?
    // ------------------------------------------------------------------
    /**
     * Revisa la carpeta PAGOS-DE-DERECHOS de la operación (y de su padre):
     *  1. Si algún XML contiene la llave o el número de operación -> sí está.
     *  2. Si no, y hay al menos tantos XML como pagos de derecho -> se asume que sí está.
     */
    private function facturaEnExpediente(Auditoria $pago, string $llave, string $numOperacion): bool
    {
        $esImpo = str_contains((string) $pago->operation_type, 'Importacion');
        $tipoApi = $esImpo ? 'importaciones' : 'exportaciones';
        $idOp = $pago->operacion_id;

        if (!$idOp) {
            return false;
        }

        $ids = [$idOp];
        $parent = $esImpo
            ? DB::table('operaciones_importacion')->where('id_importacion', $idOp)->value('parent')
            : DB::table('operaciones_exportacion')->where('id_exportacion', $idOp)->value('parent');
        if ($parent) {
            $ids[] = $parent;
        }

        $xmls = [];
        foreach ($ids as $id) {
            try {
                $resp = Http::withoutVerifying()->timeout(20)
                    ->get("https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$id}/get-files-momentaneo");
                if (!$resp->successful() || !is_array($resp->json())) {
                    continue;
                }
                foreach ($resp->json() as $archivo) {
                    $tipo = strtolower(str_replace([' ', '-', '_'], '', $archivo['pivot']['type'] ?? ''));
                    $ext = strtolower(pathinfo($archivo['name'] ?? '', PATHINFO_EXTENSION));
                    if ($tipo === 'pagosdederechos' && $ext === 'xml' && !empty($archivo['url']['normal'])) {
                        $xmls[$archivo['url']['normal']] = true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("SENASICA PDD: no se pudieron leer los archivos de la operación {$id}: " . $e->getMessage());
            }
        }

        if (empty($xmls)) {
            return false;
        }

        // 1. Coincidencia exacta por llave o número de operación dentro del XML
        foreach (array_keys($xmls) as $url) {
            try {
                $contenido = Http::withoutVerifying()->timeout(20)->get($url)->body();
                if ($contenido && (stripos($contenido, $llave) !== false || ($numOperacion !== '' && str_contains($contenido, $numOperacion)))) {
                    return true;
                }
            } catch (\Throwable $e) {
                // si no se puede leer, seguimos con la regla de conteo
            }
        }

        // 2. Regla de conteo
        $pagosDeLaOperacion = Auditoria::where('operacion_id', $idOp)
            ->where('operation_type', $pago->operation_type)
            ->where('tipo_documento', 'pago_derecho')
            ->where('monto_total', '>', 0)
            ->distinct()
            ->count('llave_pago_pdd');

        return count($xmls) >= max(1, $pagosDeLaOperacion);
    }

    // ------------------------------------------------------------------
    // RESUMEN DE LA CORRIDA (archivo CSV, no base de datos)
    // ------------------------------------------------------------------
    private function guardarResumen(array $resumen): string
    {
        $ruta = 'senasica/resumenes/resumen_' . now()->format('Ymd_His') . '.csv';

        $lineas = ["\xEF\xBB\xBF" . 'Pedimento,Llave de pago,Estado,Detalle'];
        foreach ($resumen as $fila) {
            $lineas[] = implode(',', array_map(function ($valor) {
                return '"' . str_replace('"', '""', (string) $valor) . '"';
            }, $fila));
        }

        Storage::put($ruta, implode("\r\n", $lineas));
        return $ruta;
    }

    private function validarConfiguracion(ReceptorCliente $receptores): bool
    {
        // Qué columnas de la tabla de clientes se van a usar
        $columnas = $receptores->columnas();
        $this->line('Columnas del cliente -> ' . implode(' | ', array_map(
            function ($campo, $columna) { return $campo . ': ' . ($columna ?: 'NO ENCONTRADA'); },
            array_keys($columnas),
            $columnas
        )));

        if (empty($columnas['rfc']) || empty($columnas['nombre'])) {
            $this->warn('No se encontró la columna de RFC o de razón social del cliente. Configúrala en el .env (SENASICA_COL_RFC / SENASICA_COL_NOMBRE). '
                . 'Mientras tanto solo se descargarán las facturas que ya estén timbradas.');
        }

        if ($this->option('simular')) {
            return true;
        }

        if (!is_file(config('senasica.script'))) {
            $this->error('No existe el script: ' . config('senasica.script'));
            return false;
        }

        return true;
    }
}
