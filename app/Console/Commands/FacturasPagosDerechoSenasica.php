<?php

namespace App\Console\Commands;

use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\Exportacion;
use App\Models\Importacion;
use App\Models\Pedimento;
use App\Services\Senasica\AutofacturaSenasica;
use App\Services\Senasica\ReceptorCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

/**
 * Timbra / descarga las facturas de los Pagos de Derecho (SENASICA) que no
 * están en el expediente. NO guarda nada en la base de datos: solo LEE los
 * pagos auditados y deja los archivos en storage.
 *
 *  - Si el expediente trae VARIOS pagos de derecho, se procesan TODOS
 *    (los auditados y los que solo están en la carpeta PAGOS-DE-DERECHOS).
 *  - Solo se timbran los pagos que hizo el CLIENTE: se lee el recibo bancario
 *    (R.F.C. y Denominación o Razón Social) y se compara con el cliente.
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
        {--operacion= : Número de operación del pago (para recibos escaneados, junto con --llave y --pedimento)}
        {--dias=30 : Solo pagos de los últimos N días (no aplica con --pedimento, --tarea ni --llave)}
        {--limite=20 : Máximo de pagos que entran al portal por corrida}
        {--forzar : Vuelve a entrar al portal aunque la factura ya esté descargada}
        {--sin-revisar-expediente : No revisa si la factura ya está subida al expediente}
        {--sin-validar-cliente : Procesa los pagos aunque el recibo no sea del cliente}
        {--simular : Solo lista lo que haría, sin entrar al portal}
        {--debug : Guarda captura y HTML de cada paso en storage/app/senasica/debug}';

    protected $description = 'Timbra o descarga de facturasenasica.com las facturas de Pagos de Derecho que faltan en el expediente';

    /** Comprobantes leídos del expediente, por llave: ['lecturas' => [...], 'archivos' => [...]] */
    private array $comprobantes = [];

    /** Caché de lectura de PDFs (por URL) y de archivos por operación */
    private array $cacheLecturas = [];
    private array $cacheArchivos = [];

    public function handle(AutofacturaSenasica $autofactura, ReceptorCliente $receptores): int
    {
        if (!$this->validarConfiguracion($receptores)) {
            return self::FAILURE;
        }

        $pagos = $this->obtenerPagos();

        if ($pagos->isEmpty()) {
            $this->info('No hay pagos de derecho pendientes con los filtros indicados.');
            $this->explicarSinPagos();
            return self::SUCCESS;
        }

        $this->info('Pagos de derecho a revisar: ' . $pagos->count());

        // Cuántos pagos de derecho tiene cada operación (para la regla de "ya está en el expediente")
        $pagosPorOperacion = $pagos->groupBy(function ($p) { return $this->claveOperacion($p); })->map->count();

        $resumen = [];
        $avisos = [];
        $procesados = 0;
        $limite = (int) $this->option('limite');
        $carpetaBase = trim(config('senasica.carpeta', 'senasica/facturas'), '/');
        $fechaLimite = now()->subWeekdays((int) config('senasica.dias_habiles_minimos', 2))->toDateString();

        foreach ($pagos as $pago) {
            $llave = $this->normalizarLlave($pago->llave_pago_pdd);
            $numOperacion = trim((string) $pago->num_operacion_pdd);
            $pedimento = $this->numeroPedimento($pago->pedimento_id);
            $pedimentoCorto = preg_match('/[4-7]\d{6}/', $pedimento, $m) ? $m[0] : 'SIN_PEDIMENTO';
            $carpeta = $carpetaBase . '/' . $pedimentoCorto;
            $origen = $pago->getAttribute('capturado_a_mano') ? 'capturado a mano'
                : ($pago->getAttribute('desde_expediente') ? 'expediente' : 'auditoría');

            // 1. ¿Ya se descargó antes? (el propio archivo es el registro)
            $xmlPrevio = $carpeta . '/' . $llave . '.xml';
            $pdfPrevio = $carpeta . '/' . $llave . '.pdf';
            if (!$this->option('forzar') && (Storage::exists($xmlPrevio) || Storage::exists($pdfPrevio))) {
                $resumen[] = [$pedimentoCorto, $llave, 'ya_descargada', 'Ya está en ' . $carpeta . ' (usa --forzar para volver a bajarla)'];
                continue;
            }

            // Receptor = cliente de la operación
            $datosCliente = $receptores->paraPago($pago);
            $r = $datosCliente['receptor'];
            $descripcionCliente = ($r['nombre'] ?: $datosCliente['cliente'] ?: 'sin cliente')
                . ' | RFC ' . ($r['rfc'] ?: '¿?') . ' | Régimen ' . ($r['regimen'] ?: '¿?');

            // 2. ¿El pago lo hizo el cliente? (se lee el recibo bancario)
            $validacionCliente = '';
            if (!$this->option('sin-validar-cliente')) {
                [$esDelCliente, $validacionCliente] = $this->pagoEsDelCliente($pago, $llave, $datosCliente);
                if (!$esDelCliente) {
                    $resumen[] = [$pedimentoCorto, $llave, 'otro_cliente', $validacionCliente];
                    continue;
                }
            }

            if ($numOperacion === '') {
                $resumen[] = [$pedimentoCorto, $llave, 'error', 'El comprobante no trae número de operación'];
                continue;
            }

            // 3. El portal pide 48 h hábiles desde el pago (los auditados ya vienen filtrados)
            $fechaPago = $pago->fecha_documento ? substr((string) $pago->fecha_documento, 0, 10) : null;
            if ($fechaPago && $fechaPago > $fechaLimite) {
                $resumen[] = [$pedimentoCorto, $llave, 'en_espera', "Pagado el {$fechaPago}; el portal pide 48 h hábiles"];
                continue;
            }

            // 4. ¿Ya la subieron al expediente?
            $totalPagos = (int) ($pagosPorOperacion[$this->claveOperacion($pago)] ?? 1);
            if (!$this->option('sin-revisar-expediente') && $this->facturaEnExpediente($pago, $llave, $numOperacion, $totalPagos)) {
                $resumen[] = [$pedimentoCorto, $llave, 'en_expediente', 'La factura ya está subida al expediente'];
                continue;
            }

            if ($this->option('simular')) {
                $nota = empty($datosCliente['faltantes'])
                    ? ''
                    : ' | FALTA en el cliente: ' . implode(', ', $datosCliente['faltantes']) . ' (solo podría descargar si ya está timbrada)';
                $textoValidacion = $validacionCliente ? " | {$validacionCliente}" : '';
                $resumen[] = [$pedimentoCorto, $llave, 'simulado', "Operación {$numOperacion} ({$origen}){$textoValidacion} | {$descripcionCliente}{$nota}"];
                continue;
            }

            if ($procesados >= $limite) {
                $resumen[] = [$pedimentoCorto, $llave, 'pendiente', "Límite de {$limite} por corrida; se hará en la siguiente"];
                continue;
            }

            // 5. Portal de SENASICA
            $this->line("→ Pedimento {$pedimentoCorto} | Llave {$llave} | Operación {$numOperacion} ({$origen})");
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

        $idsPedimento = $this->idsPedimentoFiltrados();

        if ($llave = $this->option('llave')) {
            $query->where('llave_pago_pdd', strtoupper(trim($llave)));
        } elseif ($idsPedimento === null) {
            // Corrida general: solo los últimos N días (las 48 h hábiles se revisan pago por pago)
            $query->whereDate('fecha_documento', '>=', now()->subDays((int) $this->option('dias'))->toDateString());
        }

        if ($idsPedimento !== null) {
            if (empty($idsPedimento)) {
                return collect();
            }
            $query->whereIn('pedimento_id', $idsPedimento);
        }

        $auditados = $query->orderBy('fecha_documento')
            ->get()
            ->unique(function ($a) { return $this->normalizarLlave($a->llave_pago_pdd); })
            ->values();

        // Con --llave solo se procesa esa llave
        if ($this->option('llave')) {
            // Recibo escaneado (no se puede leer): la llave y la operación se capturan a mano
            if ($auditados->isEmpty() && $this->option('operacion') && !empty($idsPedimento)) {
                return $this->pagoCapturadoAMano($idsPedimento);
            }
            return $auditados;
        }

        // Operaciones cuyo expediente se revisa: las de los pagos auditados
        // y, si se pidió un pedimento o una tarea, TODAS las de esos pedimentos
        // (aunque la auditoría todavía no haya leído sus pagos de derecho).
        $referencias = $auditados->groupBy(function ($a) { return $this->claveOperacion($a); })->map->first();

        if ($idsPedimento !== null) {
            foreach ($this->operacionesDePedimentos($idsPedimento) as $clave => $referencia) {
                if (!$referencias->has($clave)) {
                    $referencias->put($clave, $referencia);
                }
            }
        }

        return $this->completarConExpediente($auditados, $referencias);
    }

    /**
     * Pago armado con la llave y el número de operación que el usuario capturó
     * (para recibos escaneados que no se pueden leer). No se guarda en la BD.
     */
    private function pagoCapturadoAMano(array $idsPedimento): Collection
    {
        $operaciones = $this->operacionesDePedimentos($idsPedimento);
        if (empty($operaciones)) {
            $this->warn('El pedimento no tiene operaciones en la base de datos.');
            return collect();
        }

        // Se prefiere la importación
        uasort($operaciones, function ($a, $b) {
            return str_contains((string) $b->operation_type, 'Importacion') <=> str_contains((string) $a->operation_type, 'Importacion');
        });
        $referencia = reset($operaciones);

        $llave = $this->normalizarLlave($this->option('llave'));
        $numOperacion = preg_replace('/\D/', '', (string) $this->option('operacion'));

        // Para validar el cliente: los comprobantes de la carpeta que no se pueden leer
        // se asocian a esta llave por su nombre de archivo
        foreach ($operaciones as $op) {
            foreach ($this->archivosPagosDerecho($op)['pdfs'] as $url => $nombre) {
                $datos = $this->leerComprobante($url);
                if (!$datos) {
                    $this->registrarComprobante($llave, null, $nombre);
                } elseif ($datos['llave_pago'] === $llave) {
                    $this->registrarComprobante($llave, $datos, $nombre);
                }
            }
        }

        $pago = new Auditoria();
        $pago->forceFill([
            'operacion_id'      => $referencia->operacion_id,
            'operation_type'    => $referencia->operation_type,
            'pedimento_id'      => $referencia->pedimento_id,
            'tipo_documento'    => 'pago_derecho',
            'llave_pago_pdd'    => $llave,
            'num_operacion_pdd' => $numOperacion,
            'monto_total'       => 0,
            'fecha_documento'   => null,
            'ruta_pdf'          => null,
        ]);
        $pago->setAttribute('capturado_a_mano', true);

        return collect([$pago]);
    }

    /**
     * Operaciones (importación y exportación) de los pedimentos, como "pagos" de
     * referencia en memoria para poder revisar su expediente.
     */
    private function operacionesDePedimentos(array $idsPedimento): array
    {
        $referencias = [];

        $tipos = [
            Importacion::class => Importacion::whereIn('id_pedimiento', $idsPedimento)->get(['id_importacion as id_op', 'id_pedimiento']),
            Exportacion::class => Exportacion::whereIn('id_pedimiento', $idsPedimento)->get(['id_exportacion as id_op', 'id_pedimiento']),
        ];

        foreach ($tipos as $clase => $operaciones) {
            foreach ($operaciones as $op) {
                $referencia = new Auditoria();
                $referencia->forceFill([
                    'operacion_id'   => $op->id_op,
                    'operation_type' => $clase,
                    'pedimento_id'   => $op->id_pedimiento,
                    'tipo_documento' => 'pago_derecho',
                ]);
                $referencias[$this->claveOperacion($referencia)] = $referencia;
            }
        }

        return $referencias;
    }

    /**
     * Cuando no salió ningún pago, explica por qué (solo con --pedimento o --tarea).
     */
    private function explicarSinPagos(): void
    {
        $ids = $this->idsPedimentoFiltrados();
        if ($ids === null) {
            $this->line('Prueba con --dias=60 o filtra por --pedimento / --tarea.');
            return;
        }

        if (empty($ids)) {
            $this->warn('El pedimento no se encontró en la base de datos.');
            return;
        }

        $operaciones = $this->operacionesDePedimentos($ids);
        $this->line('Registros del pedimento en la BD: ' . count($ids) . ' | Operaciones: ' . count($operaciones));

        $auditados = Auditoria::whereIn('pedimento_id', $ids)->where('tipo_documento', 'pago_derecho')->get(['llave_pago_pdd', 'num_operacion_pdd', 'monto_total']);
        $this->line('Pagos de derecho en la auditoría: ' . $auditados->count()
            . ($auditados->count() ? ' (' . $auditados->map(function ($a) {
                return ($a->llave_pago_pdd ?: 'sin llave') . ' / op ' . ($a->num_operacion_pdd ?: 'sin número') . ' / $' . $a->monto_total;
            })->implode(', ') . ')' : ''));

        foreach ($operaciones as $referencia) {
            $pdfs = $this->archivosPagosDerecho($referencia)['pdfs'];
            $nombreTipo = str_contains((string) $referencia->operation_type, 'Importacion') ? 'Importación' : 'Exportación';
            $this->line("{$nombreTipo} #{$referencia->operacion_id}: " . count($pdfs) . ' PDF en PAGOS-DE-DERECHOS');

            foreach ($pdfs as $url => $nombre) {
                $datos = $this->leerComprobante($url);
                $this->line('   - ' . $nombre . ' -> ' . ($datos
                    ? "llave {$datos['llave_pago']} | operación " . ($datos['numero_operacion'] ?: '¿?') . " | RFC " . ($datos['rfc'] ?: '¿?')
                    : 'NO se pudo leer: ' . ($this->motivosLectura[$url] ?? 'motivo desconocido')));
            }
        }

        $this->newLine();
        $this->line('Si el recibo está escaneado, captura a mano la llave de pago y el número de operación:');
        $this->line('   php artisan senasica:facturas-pdd --pedimento=' . ($this->option('pedimento') ?: 'XXXXXXX') . ' --llave=LLAVE --operacion=NUMERO --simular');
    }

    /**
     * Agrega los pagos de derecho que están en la carpeta PAGOS-DE-DERECHOS del expediente
     * pero que la auditoría no registró (cuando el expediente trae más de uno).
     * Se crean en memoria, NO se guardan en la base de datos.
     */
    private function completarConExpediente(Collection $auditados, Collection $referencias): Collection
    {
        $resultado = $auditados->keyBy(function ($a) { return $this->normalizarLlave($a->llave_pago_pdd); });

        foreach ($referencias as $referencia) {

            foreach ($this->archivosPagosDerecho($referencia)['pdfs'] as $url => $nombre) {
                $datos = $this->leerComprobante($url);
                if (!$datos) {
                    continue;
                }

                $llave = $datos['llave_pago'];
                $this->registrarComprobante($llave, $datos, $nombre);

                if ($resultado->has($llave)) {
                    continue;
                }

                $nuevo = new Auditoria();
                $nuevo->forceFill([
                    'operacion_id'      => $referencia->operacion_id,
                    'operation_type'    => $referencia->operation_type,
                    'pedimento_id'      => $referencia->pedimento_id,
                    'tipo_documento'    => 'pago_derecho',
                    'llave_pago_pdd'    => $llave,
                    'num_operacion_pdd' => $datos['numero_operacion'] ?? '',
                    'monto_total'       => $datos['monto_total'] ?? 0,
                    'fecha_documento'   => $datos['fecha_pago'],
                    'ruta_pdf'          => $url,
                ]);
                $nuevo->setAttribute('desde_expediente', true);

                $resultado->put($llave, $nuevo);
            }
        }

        return $resultado->values();
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

    private function normalizarLlave($llave): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $llave));
    }

    private function claveOperacion(Auditoria $pago): string
    {
        return $pago->operation_type . '|' . $pago->operacion_id;
    }

    // ------------------------------------------------------------------
    // ARCHIVOS DE LA CARPETA PAGOS-DE-DERECHOS (operación y su padre)
    // ------------------------------------------------------------------
    /**
     * @return array{pdfs: array<string,string>, xmls: array<string,string>}  [url => nombre]
     */
    private function archivosPagosDerecho(Auditoria $pago): array
    {
        $clave = $this->claveOperacion($pago);
        if (isset($this->cacheArchivos[$clave])) {
            return $this->cacheArchivos[$clave];
        }

        $resultado = ['pdfs' => [], 'xmls' => []];
        $esImpo = str_contains((string) $pago->operation_type, 'Importacion');
        $tipoApi = $esImpo ? 'importaciones' : 'exportaciones';
        $idOp = $pago->operacion_id;

        if (!$idOp) {
            return $this->cacheArchivos[$clave] = $resultado;
        }

        $ids = [$idOp];
        $parent = $esImpo
            ? DB::table('operaciones_importacion')->where('id_importacion', $idOp)->value('parent')
            : DB::table('operaciones_exportacion')->where('id_exportacion', $idOp)->value('parent');
        if ($parent) {
            $ids[] = $parent;
        }

        foreach ($ids as $id) {
            try {
                $resp = Http::withoutVerifying()->timeout(20)
                    ->get("https://sistema.intactics.com/v3/operaciones/{$tipoApi}/{$id}/get-files-momentaneo");
                if (!$resp->successful() || !is_array($resp->json())) {
                    continue;
                }
                foreach ($resp->json() as $archivo) {
                    $tipo = strtolower(str_replace([' ', '-', '_'], '', $archivo['pivot']['type'] ?? ''));
                    $url = $archivo['url']['normal'] ?? '';
                    if ($tipo !== 'pagosdederechos' || $url === '') {
                        continue;
                    }
                    $nombre = (string) ($archivo['name'] ?? basename((string) parse_url($url, PHP_URL_PATH)));
                    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
                    if ($ext === 'pdf') {
                        $resultado['pdfs'][$url] = $nombre;
                    } elseif ($ext === 'xml') {
                        $resultado['xmls'][$url] = $nombre;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("SENASICA PDD: no se pudieron leer los archivos de la operación {$id}: " . $e->getMessage());
            }
        }

        return $this->cacheArchivos[$clave] = $resultado;
    }

    // ------------------------------------------------------------------
    // LECTURA DEL RECIBO BANCARIO (BBVA / Santander)
    // ------------------------------------------------------------------
    /**
     * Lee el comprobante: llave, operación, monto, fecha y QUIÉN PAGÓ (RFC y razón social).
     * Primero usa la CADENA ORIGINAL del recibo (|10001=RFC|20002=OPERACIÓN|40008=LLAVE|...),
     * que traen BBVA y Santander y no depende de cómo el PDF acomode el texto;
     * después, las etiquetas del recibo ("Llave de Pago:", "No. de Operación:"...).
     * Si no se puede leer, el motivo queda en $this->motivosLectura[$url].
     */
    private function leerComprobante(string $url): ?array
    {
        if (array_key_exists($url, $this->cacheLecturas)) {
            return $this->cacheLecturas[$url];
        }

        // 1. Descargar
        $contenido = null;
        $candidatas = [$url, 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/' . basename((string) parse_url($url, PHP_URL_PATH))];
        foreach ($candidatas as $candidata) {
            try {
                $resp = Http::withoutVerifying()->timeout(30)->get($candidata);
                if ($resp->successful() && str_contains(substr($resp->body(), 0, 1024), '%PDF')) {
                    $contenido = $resp->body();
                    break;
                }
            } catch (\Throwable $e) {
                // siguiente candidata
            }
        }

        if (!$contenido) {
            return $this->sinLectura($url, 'no se pudo descargar el PDF');
        }

        // 2. Texto (documento completo y, si falla, página por página)
        $texto = '';
        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(20971520);
            $pdf = (new Parser([], $config))->parseContent($contenido);

            try {
                $texto = (string) $pdf->getText();
            } catch (\Throwable $e) {
                $texto = '';
            }

            if (trim($texto) === '') {
                foreach ($pdf->getPages() as $pagina) {
                    try {
                        $texto .= "\n" . $pagina->getText();
                    } catch (\Throwable $e) {
                        // página ilegible, se sigue con la siguiente
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("SENASICA PDD: no se pudo leer el comprobante {$url}: " . $e->getMessage());
            return $this->sinLectura($url, 'el lector de PDF falló: ' . mb_substr($e->getMessage(), 0, 120));
        }

        $texto = str_replace("\0", '', $texto);
        if (trim($texto) === '') {
            return $this->sinLectura($url, 'el PDF no tiene texto (es una imagen escaneada)');
        }

        $plano = preg_replace('/\s+/u', ' ', $texto);
        $compacto = preg_replace('/\s+/u', '', $texto); // la cadena original suele venir partida en varias líneas
        $patronRfc = '[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}';

        $datos = ['llave_pago' => null, 'numero_operacion' => null, 'monto_total' => 0, 'fecha_pago' => null, 'rfc' => null, 'razon_social' => null];

        // 3. Cadena original (BBVA y Santander)
        if (preg_match('/\|40008=([A-Z0-9]+)\|/u', $compacto, $m)) $datos['llave_pago'] = $m[1];
        if (preg_match('/\|20002=(\d+)\|/u', $compacto, $m)) $datos['numero_operacion'] = $m[1];
        if (preg_match('/\|10017=([\d,.]+)\|/u', $compacto, $m)) $datos['monto_total'] = (float) str_replace(',', '', $m[1]);
        if (preg_match('/\|40002=(\d{4})(\d{2})(\d{2})\|/u', $compacto, $m)) $datos['fecha_pago'] = "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('/\|10001=(' . $patronRfc . ')\|/u', $compacto, $m)) $datos['rfc'] = $m[1];

        // 4. Etiquetas del recibo (si la cadena no vino completa)
        if (!$datos['llave_pago'] && preg_match('/Llave\s*de\s*Pago\s*:?\s*([A-Z0-9]{6,})/iu', $plano, $m)) {
            $datos['llave_pago'] = strtoupper($m[1]);
        }
        if (!$datos['numero_operacion'] && preg_match('/(?:No\.|N.mero)\s*de\s*Operaci.n\s*:?\s*(\d+)/iu', $plano, $m)) {
            $datos['numero_operacion'] = $m[1];
        }
        if (!$datos['fecha_pago'] && preg_match('/Fecha\s*y\s*Hora\s*del\s*Pago\s*:?\s*(\d{2})\/(\d{2})\/(\d{4})/iu', $plano, $m)) {
            $datos['fecha_pago'] = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (!$datos['monto_total'] && (preg_match('/Total\s*Efectivamente(?:\s*Pagado)?\s*:?\s*\$\s*([\d,]+(?:\.\d{1,2})?)/iu', $plano, $m)
            || preg_match('/Cantidad\s*Pagada\s*:?\s*\$?\s*([\d,]+(?:\.\d{1,2})?)/iu', $plano, $m))) {
            $datos['monto_total'] = (float) str_replace(',', '', $m[1]);
        }
        if (!$datos['rfc']) {
            if (preg_match('/R\.?\s*F\.?\s*C\.?\s*:?\s*(' . $patronRfc . ')/u', $plano, $m)) {
                $datos['rfc'] = $m[1];
            } elseif (preg_match('/\b(' . $patronRfc . ')\b/u', $plano, $m)) {
                $datos['rfc'] = $m[1];
            }
        }
        if (preg_match('/Denominaci.n\s*o\s*Raz.n\s*Social\s*:?\s*(.+?)\s*(?=Fecha\s*y\s*Hora|Cuenta\s*de\s*Cargo|N.mero\s*de\s*Operaci|No\.\s*de\s*Operaci|Llave\s*de\s*Pago|Total\s*Efectivamente|R\.?\s*F\.?\s*C|$)/iu', $plano, $m)) {
            $candidata = trim($m[1], " :\t");
            if ($candidata !== '' && mb_strlen($candidata) <= 150 && !preg_match('/^(Fecha|Hora|N.mero|Llave|Total|Dependencia)/iu', $candidata)) {
                $datos['razon_social'] = $candidata;
            }
        }

        if (!$datos['llave_pago']) {
            return $this->sinLectura($url, 'tiene texto pero no se encontró la llave de pago. Texto leído: "' . mb_substr(trim($plano), 0, 250) . '"');
        }

        $datos['llave_pago'] = $this->normalizarLlave($datos['llave_pago']);
        return $this->cacheLecturas[$url] = $datos;
    }

    /** Motivo por el que no se pudo leer cada comprobante (para el diagnóstico) */
    private array $motivosLectura = [];

    private function sinLectura(string $url, string $motivo): ?array
    {
        $this->motivosLectura[$url] = $motivo;
        return $this->cacheLecturas[$url] = null;
    }

    private function registrarComprobante(string $llave, ?array $datos, string $nombreArchivo): void
    {
        if (!isset($this->comprobantes[$llave])) {
            $this->comprobantes[$llave] = ['lecturas' => [], 'archivos' => []];
        }
        if ($datos) {
            $this->comprobantes[$llave]['lecturas'][] = $datos;
        }
        if ($nombreArchivo !== '' && !in_array($nombreArchivo, $this->comprobantes[$llave]['archivos'], true)) {
            $this->comprobantes[$llave]['archivos'][] = $nombreArchivo;
        }
    }

    // ------------------------------------------------------------------
    // ¿EL PAGO LO HIZO EL CLIENTE?
    // ------------------------------------------------------------------
    /**
     * 1. RFC del recibo = RFC del cliente -> sí. Si son distintos -> no.
     * 2. Sin RFC para comparar: la razón social del recibo debe coincidir con el cliente.
     * 3. Recibo sin RFC ni razón social legibles: se revisa el nombre del archivo.
     *
     * @return array{0: bool, 1: string}
     */
    private function pagoEsDelCliente(Auditoria $pago, string $llave, array $datosCliente): array
    {
        // El comprobante del pago auditado también se lee (queda en caché)
        if (!empty($pago->ruta_pdf) && preg_match('/^https?:\/\//i', (string) $pago->ruta_pdf)) {
            $datos = $this->leerComprobante((string) $pago->ruta_pdf);
            $this->registrarComprobante($llave, ($datos && $datos['llave_pago'] === $llave) ? $datos : null, rawurldecode(basename((string) parse_url($pago->ruta_pdf, PHP_URL_PATH))));
        }

        $lecturas = $this->comprobantes[$llave]['lecturas'] ?? [];
        $archivos = $this->comprobantes[$llave]['archivos'] ?? [];

        $rfcCliente = strtoupper(preg_replace('/[^A-Z0-9&Ñ]/u', '', (string) ($datosCliente['receptor']['rfc'] ?? '')));
        $nombreCliente = (string) ($datosCliente['cliente'] ?: ($datosCliente['receptor']['nombre'] ?? ''));

        // 1. RFC
        $rfcsRecibo = array_values(array_unique(array_filter(array_map(function ($d) {
            return strtoupper((string) ($d['rfc'] ?? ''));
        }, $lecturas))));

        if ($rfcCliente !== '' && !empty($rfcsRecibo)) {
            if (in_array($rfcCliente, $rfcsRecibo, true)) {
                return [true, "RFC del comprobante {$rfcCliente} = cliente"];
            }
            return [false, 'RFC del comprobante ' . implode(', ', $rfcsRecibo) . " ≠ cliente {$rfcCliente} ({$nombreCliente})"];
        }

        // 2. Razón social
        $razones = array_values(array_unique(array_filter(array_map(function ($d) {
            return (string) ($d['razon_social'] ?? '');
        }, $lecturas))));

        foreach ($razones as $razon) {
            if ($this->nombreCoincide($razon, $datosCliente)) {
                return [true, "Razón social del comprobante \"{$razon}\" = cliente"];
            }
        }
        if (!empty($razones)) {
            return [false, 'Razón social del comprobante "' . implode('", "', $razones) . "\" ≠ cliente {$nombreCliente}"];
        }

        // 3. Nombre del archivo
        foreach ($archivos as $archivo) {
            if ($this->nombreCoincide(pathinfo($archivo, PATHINFO_FILENAME), $datosCliente)) {
                return [true, "No se pudo leer quién pagó; el nombre del archivo \"{$archivo}\" coincide con el cliente"];
            }
        }

        $listaArchivos = empty($archivos) ? 'sin archivo' : implode(', ', $archivos);
        return [false, "No se pudo leer quién pagó en el comprobante y el nombre del archivo ({$listaArchivos}) no es del cliente {$nombreCliente}"];
    }

    /**
     * Coincide si el texto trae el RFC, la razón social completa o al menos la mitad
     * de las palabras distintivas del cliente (sin "SA DE CV", conectores ni genéricas).
     */
    private function nombreCoincide(string $texto, array $datosCliente): bool
    {
        $rfc = strtoupper((string) ($datosCliente['receptor']['rfc'] ?? ''));
        $nombresCliente = array_filter(array_unique([
            (string) ($datosCliente['cliente'] ?? ''),
            (string) ($datosCliente['receptor']['nombre'] ?? ''),
        ]));

        $compacto = str_replace(' ', '', $this->normalizarNombre($texto));
        if ($compacto === '') {
            return false;
        }

        if ($rfc !== '' && str_contains($compacto, $rfc)) {
            return true;
        }

        foreach ($nombresCliente as $nombreCliente) {
            $palabras = $this->palabrasDistintivas($nombreCliente);
            if (empty($palabras)) {
                continue;
            }

            if (str_contains($compacto, implode('', $palabras))) {
                return true;
            }

            $encontradas = 0;
            foreach ($palabras as $palabra) {
                if (str_contains($compacto, $palabra)) {
                    $encontradas++;
                }
            }

            if ($encontradas >= max(1, (int) ceil(count($palabras) / 2))) {
                return true;
            }
        }

        return false;
    }

    private function normalizarNombre(string $texto): string
    {
        $texto = strtoupper(Str::ascii($texto));
        $texto = preg_replace('/[^A-Z0-9&]+/', ' ', $texto);
        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    private function palabrasDistintivas(string $nombre): array
    {
        $ignorar = [
            'SA', 'DE', 'CV', 'RL', 'S', 'A', 'C', 'V', 'SAPI', 'SAB', 'SC', 'AC', 'SPR', 'MI', 'Y', 'E',
            'LA', 'EL', 'LOS', 'LAS', 'DEL', 'EN', 'CON', 'PARA', 'POR', 'THE', 'OF', 'AND', 'INC', 'LLC', 'CO', 'CORP',
        ];
        $genericas = [
            'COMERCIALIZADORA', 'INTERNACIONAL', 'GRUPO', 'MEXICO', 'MEXICANA', 'IMPORTADORA', 'EXPORTADORA',
            'DISTRIBUIDORA', 'SERVICIOS', 'CORPORATIVO', 'INDUSTRIAS', 'INDUSTRIAL', 'COMPANIA', 'EMPRESA',
            'NACIONAL', 'PRODUCTOS', 'ALIMENTOS', 'IMPORT', 'EXPORT', 'IMPORTS', 'EXPORTS', 'TRADING',
            'LOGISTICS', 'LOGISTICA', 'TRANSPORTES',
        ];

        $palabras = array_values(array_filter(explode(' ', $this->normalizarNombre($nombre)), function ($p) use ($ignorar) {
            return strlen($p) >= 3 && !in_array($p, $ignorar, true);
        }));

        $distintivas = array_values(array_filter($palabras, function ($p) use ($genericas) {
            return !in_array($p, $genericas, true);
        }));

        return !empty($distintivas) ? $distintivas : $palabras;
    }

    // ------------------------------------------------------------------
    // ¿YA ESTÁ LA FACTURA EN EL EXPEDIENTE?
    // ------------------------------------------------------------------
    /**
     * Revisa la carpeta PAGOS-DE-DERECHOS de la operación (y de su padre):
     *  1. Si algún XML contiene la llave o el número de operación -> sí está.
     *  2. Si no, y hay al menos tantos XML como pagos de derecho -> se asume que sí está.
     */
    private function facturaEnExpediente(Auditoria $pago, string $llave, string $numOperacion, int $totalPagos): bool
    {
        $xmls = $this->archivosPagosDerecho($pago)['xmls'];

        if (empty($xmls)) {
            return false;
        }

        // 1. Coincidencia exacta por llave o número de operación dentro del XML
        foreach (array_keys($xmls) as $url) {
            try {
                $contenido = Http::withoutVerifying()->timeout(20)->get($url)->body();
                if ($contenido && (stripos($contenido, $llave) !== false || (strlen($numOperacion) >= 6 && str_contains($contenido, $numOperacion)))) {
                    return true;
                }
            } catch (\Throwable $e) {
                // si no se puede leer, seguimos con la regla de conteo
            }
        }

        // 2. Regla de conteo
        return count($xmls) >= max(1, $totalPagos);
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
