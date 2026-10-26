<?php

namespace App\Services;

use App\Models\IngresoConciliado;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera el archivo TXT del Recibo Electrónico de Pago (REP) para SyncCOM 6.1 o superior
 * (Layout Julio 2022).
 *
 * Secciones del archivo, en el orden del layout:
 *   1. CLIENTE (renglones 1 al 31)            → endpoint /v3/empresas/{id}/rep-compaq-momentaneo
 *   2. ENCABEZADO del documento (34 al 59)    → ingreso + endpoint (lugar de expedición, cuenta ordenante)
 *   3. PAGO (60 al 63)                        → ingreso
 *   4. DOCUMENTOS A SALDAR (64 al 67)         → ingreso_operacion + XML de cada factura (UUID, serie)
 *
 * Compatible con PHP 7.3.
 */
class GeneradorTxtRep
{
    // ------------------------------------------------------------------
    // CONFIGURACIÓN
    // Los valores de config/rep.php tienen prioridad sobre estas constantes.
    // ------------------------------------------------------------------

    /** Endpoint con los datos fiscales y de CONTPAQi del cliente (%d = id de la empresa) */
    const URL_DATOS_EMPRESA = 'https://sistema.intactics.com/v3/empresas/%d/rep-compaq-momentaneo';

    /** API de archivos de las operaciones (misma que usa buscarEnSheet) */
    const URL_ARCHIVOS_OPERACION = 'https://sistema.intactics.com/v3/operaciones/%s/%d/get-files-momentaneo';

    /** Ruta alterna de los XML cuando la URL original no responde */
    const URL_CDN_UPLOADS = 'https://intactics.nyc3.cdn.digitaloceanspaces.com/production/uploads/';

    /** [encTIPODOC] 9 = Pago del cliente */
    const TIPO_DOCUMENTO_PAGO = '9';

    /** [encDOCUMENTO] Código del concepto del REP en CONTPAQi (mismo formato que SaldarDOCUMENTO del endpoint) */
    const CONCEPTO_PAGO = '100014.0';

    /** [encSERIE] Serie del REP (en el XML de ejemplo: Serie="CP") */
    const SERIE_PAGO = 'CP';

    /** [encFORMAPAGO] Catálogo c_FormaPago del SAT: 03 = Transferencia electrónica */
    const FORMA_PAGO_DEFAULT = '03';

    /** [encUSOCFDI] En CFDI 4.0 el REP siempre usa CP01 (Pagos) */
    const USO_CFDI_REP = 'CP01';

    /** El endpoint ya trae el régimen fiscal, así que se valida como obligatorio */
    const VALIDAR_REGIMEN_FISCAL = true;

    /** Omitir las líneas sin valor ("[descriptor]" sin nada después) */
    const OMITIR_LINEAS_VACIAS = true;

    /**
     * Escribir [saldarFOLIO]. En false la factura se identifica solo por su UUID:
     * el folio se sigue usando internamente para encontrar el XML, y el UUID pasa a ser obligatorio.
     * Se controla con config/rep.php → incluir_folio (REP_INCLUIR_FOLIO en el .env).
     */
    const INCLUIR_FOLIO_EN_SALDAR = false;

    /**
     * Escribir el UUID de cada factura después de [saldarIMPORTE].
     * Se puede desactivar con REP_INCLUIR_UUID=false en el .env (config/rep.php → incluir_uuid).
     */
    const INCLUIR_UUID_EN_SALDAR = true;

    /** Descriptor con el que se escribe el UUID (config/rep.php → descriptor_uuid) */
    const DESCRIPTOR_UUID_SALDAR = 'saldarUUID';

    /** Claves de banco del SAT (Anexo 24) por nombre, cuando la cuenta no trae CLABE */
    const CLAVES_BANCO_SAT = [
        'BBVA'       => '012',
        'BANCOMER'   => '012',
        'SANTANDER'  => '014',
        'BANAMEX'    => '002',
        'CITIBANAMEX' => '002',
        'BANORTE'    => '072',
        'HSBC'       => '021',
        'SCOTIABANK' => '044',
        'INBURSA'    => '036',
        'BAJIO'      => '030',
        'BANREGIO'   => '058',
        'AZTECA'     => '127',
        'MONEX'      => '112',
    ];

    /** Fin de línea de Windows, que es el que espera SyncCOM */
    const FIN_DE_LINEA = "\r\n";

    // ------------------------------------------------------------------
    // ESTADO
    // ------------------------------------------------------------------

    /** @var array */
    private $errores = [];

    /** @var array */
    private $advertencias = [];

    /** @var array Caché de XML descargados: url => datos */
    private $cacheXml = [];

    /** @var array Caché de respuestas del endpoint de empresas: id => json */
    private $cacheEmpresas = [];

    /** @var array Datos usados en la última generación (para mostrarlos en el comando) */
    private $resumen = [];

    // =====================================================================
    // ARCHIVO COMPLETO
    // =====================================================================

    /**
     * Genera todas las líneas del REP a partir de un ingreso conciliado.
     *
     * Opciones (todas opcionales):
     *   tipo_cambio      float  Obligatorio si el pago es en dólares
     *   moneda           int    1 = Pesos, 2 = Dólares (por defecto, según el banco receptor)
     *   forma_pago       string Catálogo c_FormaPago (por defecto 03)
     *   folio_pago       int    Folio del REP; 0 = consecutivo del concepto en CONTPAQi
     *   timbrar          bool   [encCFDI]SI / NO
     *   parcialidad      int    Por defecto 1
     *   parcialidades    int    Por defecto 1
     *   documentos       array  [['folio' => '7475', 'importe' => 19970.00, 'serie' => 'ZLO'], ...]
     *   lugar_expedicion string Fuerza el CP de expedición
     *   empresa_id       int    Toma los datos del cliente de otra empresa (solo para pruebas)
     *
     * @return array Líneas del archivo (vacío si hay errores; revisar errores())
     */
    public function generarDesdeIngreso(IngresoConciliado $ingreso, array $opciones = []): array
    {
        $this->errores = [];
        $this->advertencias = [];
        $this->resumen = [];

        $empresaId = !empty($opciones['empresa_id']) ? (int) $opciones['empresa_id'] : (int) $ingreso->cliente_id;

        if ($empresaId <= 0) {
            $this->errores['cteCODIGO'] = 'El ingreso no tiene un cliente del catálogo de empresas (cliente_id vacío).';
            return [];
        }

        $sucursal = (string) $ingreso->sucursal_origen;
        $moneda   = isset($opciones['moneda']) ? (int) $opciones['moneda'] : $this->monedaPorBanco($ingreso->banco_receptor);

        // 1. Datos del cliente desde el endpoint
        $json = $this->obtenerDatosEmpresa($empresaId);
        if ($json === null) {
            return [];
        }

        $perfil = $this->resolverPerfil($json, $sucursal);
        if ($perfil === null) {
            return [];
        }

        // 2. Documentos a saldar
        $documentos = isset($opciones['documentos']) && is_array($opciones['documentos']) && !empty($opciones['documentos'])
            ? $opciones['documentos']
            : $this->documentosDesdeIngreso($ingreso, $perfil);

        if (!empty($documentos)) {
            $documentos = $this->completarUuids($documentos, $ingreso, $perfil);
        }

        // 3. Armar las secciones
        $lineas = $this->bloqueCliente($perfil, $moneda);

        $foliosTexto = implode(', ', array_map(function ($d) {
            return trim(($d['serie'] ?? '') . ' ' . ($d['folio'] ?? ''));
        }, $documentos));

        $lineas = array_merge($lineas, $this->bloqueEncabezado([
            'perfil'           => $perfil,
            'moneda'           => $moneda,
            'tipo_cambio'      => $opciones['tipo_cambio'] ?? ($moneda === 1 ? 1 : null),
            'folio_venta'      => (string) $ingreso->id,
            'observacion'      => $foliosTexto !== '' ? 'PAGO FACTURA(S) ' . $foliosTexto : '',
            'forma_pago'       => $opciones['forma_pago'] ?? self::FORMA_PAGO_DEFAULT,
            'parcialidad'      => $opciones['parcialidad'] ?? 1,
            'parcialidades'    => $opciones['parcialidades'] ?? 1,
            'banco_receptor'   => (string) $ingreso->banco_receptor,
            'lugar_expedicion' => $opciones['lugar_expedicion'] ?? '',
            'timbrar'          => array_key_exists('timbrar', $opciones) ? (bool) $opciones['timbrar'] : true,
        ]));

        $lineas = array_merge($lineas, $this->bloquePago([
            'fecha'          => $ingreso->fecha,
            'importe'        => (float) $ingreso->monto_deposito,
            'folio_pago'     => $opciones['folio_pago'] ?? 0,
            'con_documentos' => !empty($documentos),
        ]));

        $lineas = array_merge($lineas, $this->bloquesSaldar($documentos, $perfil));

        $this->resumen['documentos'] = $documentos;

        if ($this->tieneErrores()) {
            return [];
        }

        return self::OMITIR_LINEAS_VACIAS ? $this->quitarLineasVacias($lineas) : $lineas;
    }

    // =====================================================================
    // DATOS DEL CLIENTE (ENDPOINT)
    // =====================================================================

    /**
     * Descarga el JSON de /v3/empresas/{id}/rep-compaq-momentaneo.
     */
    public function obtenerDatosEmpresa(int $empresaId)
    {
        if (array_key_exists($empresaId, $this->cacheEmpresas)) {
            return $this->cacheEmpresas[$empresaId];
        }

        $url = sprintf((string) config('rep.url_datos_empresa', self::URL_DATOS_EMPRESA), $empresaId);

        try {
            $respuesta = Http::withoutVerifying()->timeout(20)->acceptJson()->get($url);
        } catch (\Exception $e) {
            $this->errores['cteCODIGO'] = "No se pudo conectar con el endpoint de datos del cliente: {$e->getMessage()}";
            return $this->cacheEmpresas[$empresaId] = null;
        }

        $json = $respuesta->successful() ? $respuesta->json() : null;

        if (!is_array($json) || !isset($json['campos']) || !is_array($json['campos'])) {
            $this->errores['cteCODIGO'] = "El endpoint de datos del cliente #{$empresaId} no respondió correctamente (HTTP {$respuesta->status()}).";
            Log::warning('[REP] Respuesta inválida del endpoint de empresa', [
                'empresa_id' => $empresaId,
                'status'     => $respuesta->status(),
                'body'       => substr($respuesta->body(), 0, 1000),
            ]);
            return $this->cacheEmpresas[$empresaId] = null;
        }

        return $this->cacheEmpresas[$empresaId] = $json;
    }

    /**
     * A partir del JSON del endpoint, elige los datos que aplican al ingreso:
     * tipo de operación (IMPO/EXPO), razón social (principal u otra) y sucursal.
     *
     * @return array|null
     */
    public function resolverPerfil(array $json, string $sucursalIngreso)
    {
        $sucursalUpper = $this->normalizar($sucursalIngreso);
        $tipo = str_contains($sucursalUpper, 'EXPO') ? 'exportacion' : 'importacion';

        $datosTipo = $json['tipos_operacion'][$tipo] ?? null;
        if (!is_array($datosTipo)) {
            // Si no existe el tipo, se intenta con el otro
            $otroTipo = $tipo === 'importacion' ? 'exportacion' : 'importacion';
            $datosTipo = $json['tipos_operacion'][$otroTipo] ?? [];
            if (!empty($datosTipo)) {
                $this->advertencias['tipo_operacion'] = "El cliente no tiene configuración de {$tipo}; se usó la de {$otroTipo}.";
                $tipo = $otroTipo;
            }
        }

        $campos = (array) ($json['campos'] ?? []);
        $sucursales = (array) ($datosTipo['sucursales'] ?? []);
        $razonSocialAlterna = null;

        // Facturación a otra razón social
        if (!empty($datosTipo['factura_a_otra_razon_social'])) {
            foreach ((array) ($json['otras_razones_sociales'] ?? []) as $otra) {
                $camposOtra = (array) ($otra['campos'] ?? []);
                if (trim((string) ($camposOtra['cteRFC'] ?? '')) !== '') {
                    $razonSocialAlterna = $otra['id'] ?? null;
                    $campos = $camposOtra;
                    if (!empty($otra['sucursales'])) {
                        $sucursales = (array) $otra['sucursales'];
                    }
                    break;
                }
            }
            if ($razonSocialAlterna === null) {
                $this->advertencias['razon_social'] = 'El cliente está marcado para facturar a otra razón social, pero ninguna tiene RFC; se usó la principal.';
            }
        }

        // Sucursal del endpoint que corresponde al ingreso ("Laredo y Reynosa", "Manzanillo y Veracruz", ...)
        $sucursalJson = null;
        foreach ($sucursales as $candidata) {
            $palabras = preg_split('/\s+/', $this->normalizar((string) ($candidata['sucursal'] ?? '')));
            foreach ($palabras as $palabra) {
                if (strlen($palabra) > 2 && str_contains($sucursalUpper, $palabra)) {
                    $sucursalJson = $candidata;
                    break 2;
                }
            }
        }

        if ($sucursalJson === null) {
            $this->errores['cteCODIGO'] = "El endpoint del cliente no tiene configurada la sucursal '{$sucursalIngreso}' para {$tipo}.";
            return null;
        }

        // Cuenta bancaria del cliente: la del tipo de operación o, si no es válida, la del otro tipo
        $banco = $this->bancoOrdenante((array) ($datosTipo['bancos'] ?? []));
        if ($banco === null) {
            $otroTipo = $tipo === 'importacion' ? 'exportacion' : 'importacion';
            $banco = $this->bancoOrdenante((array) ($json['tipos_operacion'][$otroTipo]['bancos'] ?? []));
            if ($banco !== null) {
                $this->advertencias['bancos'] = "La cuenta bancaria de {$tipo} no está identificada; se usó la de {$otroTipo} ({$banco['banco']} {$banco['cuenta']}).";
            } else {
                $this->advertencias['bancos'] = 'El cliente no tiene una cuenta bancaria identificada en el endpoint; se omiten las líneas de banco.';
            }
        }

        $perfil = [
            'empresa_id'          => $json['empresa_id'] ?? null,
            'tipo'                => $tipo,
            'campos'              => $campos,
            'datos_tipo'          => $datosTipo,
            'sucursal'            => $sucursalJson,
            'sucursales'          => $sucursales,
            'banco_ordenante'     => $banco,
            'razon_social_alterna' => $razonSocialAlterna,
        ];

        $this->resumen['perfil'] = [
            'empresa_id'    => $perfil['empresa_id'],
            'razon_social'  => $campos['cteRAZONSOCIAL'] ?? '',
            'rfc'           => $campos['cteRFC'] ?? '',
            'tipo'          => $tipo,
            'sucursal'      => $sucursalJson['sucursal'] ?? '',
            'cte_codigo'    => $sucursalJson['cteCodigo'] ?? '',
            'otra_razon'    => $razonSocialAlterna,
        ];

        return $perfil;
    }

    /**
     * Primera cuenta del cliente con número de cuenta o CLABE válidos (se ignora "NO IDENTIFICADO").
     */
    private function bancoOrdenante(array $bancos)
    {
        foreach ($bancos as $banco) {
            $cuenta = $this->soloDigitos((string) ($banco['numero_cuenta'] ?? ''));
            $clabe  = $this->soloDigitos((string) ($banco['clabe'] ?? ''));

            if ($cuenta === '' && strlen($clabe) !== 18) {
                continue;
            }

            // La clave SAT del banco son los 3 primeros dígitos de la CLABE (012 = BBVA)
            $clave = strlen($clabe) === 18 ? substr($clabe, 0, 3) : $this->claveBancoPorNombre((string) ($banco['banco'] ?? ''));

            return [
                'banco'     => trim((string) ($banco['banco'] ?? '')),
                'cuenta'    => $cuenta,
                'clabe'     => strlen($clabe) === 18 ? $clabe : '',
                'clave_sat' => $clave,
            ];
        }

        return null;
    }

    // =====================================================================
    // PASO 1: CLIENTE (renglones 1 al 31)
    // =====================================================================

    public function bloqueCliente(array $perfil, int $moneda = 1): array
    {
        $c = $perfil['campos'];
        $t = $perfil['datos_tipo'];
        $s = $perfil['sucursal'];
        $banco = $perfil['banco_ordenante'];

        $codigo   = trim((string) ($s['cteCodigo'] ?? ''));
        $ciudad   = $this->campo($c, 'cteCIUDAD');
        $municipio = $this->campo($c, 'cteMUNICIPIO');
        $codMoneda = $this->soloDigitos((string) ($t['cteCODMONEDA'] ?? $c['cteCODMONEDA'] ?? ''));
        if ($codMoneda === '') {
            $codMoneda = (string) $moneda;
        }

        $sucursalNombre = $s['sucursal'] ?? '';
        $this->requerido('cteCODIGO', $codigo, "El cliente no tiene cteCodigo para la sucursal {$sucursalNombre} ({$perfil['tipo']}).");
        $this->requerido('cteRAZONSOCIAL', $this->campo($c, 'cteRAZONSOCIAL'), 'Falta la razón social del cliente.');
        $this->requerido('cteRFC', $this->campo($c, 'cteRFC'), 'Falta el RFC del cliente.');
        $this->requerido('cteENTREGAFAC', $this->campo($c, 'cteENTREGAFAC'), 'Falta el tipo de entrega del documento.');
        $this->requerido('cteENTREGAFMTO', $this->campo($c, 'cteENTREGAFMTO'), 'Falta el formato de entrega del documento.');
        $this->requerido('cteCALLE', $this->campo($c, 'cteCALLE'), 'Falta la calle del domicilio fiscal.');
        $this->requerido('cteNUMEXTERIOR', $this->campo($c, 'cteNUMEXTERIOR'), 'Falta el número exterior del domicilio fiscal.');
        $this->requerido('cteCOLONIA', $this->campo($c, 'cteCOLONIA'), 'Falta la colonia del domicilio fiscal.');
        $this->requerido('cteCODIGOPOSTAL', $this->campo($c, 'cteCODIGOPOSTAL'), 'Falta el código postal del domicilio fiscal.');
        $this->requerido('cteCIUDAD', $ciudad !== '' ? $ciudad : $municipio, 'Falta la ciudad del domicilio fiscal.');
        $this->requerido('cteMUNICIPIO', $municipio, 'Falta el municipio del domicilio fiscal.');
        $this->requerido('cteESTADO', $this->campo($c, 'cteESTADO'), 'Falta el estado del domicilio fiscal.');
        $this->requerido('ctePAIS', $this->campo($c, 'ctePAIS'), 'Falta el país del domicilio fiscal.');

        if (self::VALIDAR_REGIMEN_FISCAL) {
            $this->requerido('cteREGIMENFISCAL', $this->campo($c, 'cteREGIMENFISCAL'), 'Falta el régimen fiscal del cliente.');
        }

        $lineas = [];
        $lineas[] = $this->linea('cteCODIGO', $this->soloCodigo($codigo), 15);
        $lineas[] = $this->linea('cteRAZONSOCIAL', $this->mayus($this->campo($c, 'cteRAZONSOCIAL')), 60);
        $lineas[] = $this->linea('cteNOMBRELARGO', $this->campo($c, 'cteNOMBRELARGO'), 254);
        $lineas[] = $this->linea('cteRFC', $this->soloRfc($this->campo($c, 'cteRFC')), 13);
        $lineas[] = $this->linea('cteCURP', strtoupper($this->campo($c, 'cteCURP')), 20);
        $lineas[] = $this->linea('cteENTREGAFAC', $this->soloDigitos($this->campo($c, 'cteENTREGAFAC')), 1);
        $lineas[] = $this->linea('cteENTREGAFMTO', $this->soloDigitos($this->campo($c, 'cteENTREGAFMTO')), 1);
        $lineas[] = $this->linea('cteEMAIL', $this->primerCorreo($this->campo($c, 'cteEMAIL')), 50);
        $lineas[] = $this->linea('cteDIASCREDITO', $this->soloDigitos((string) ($t['cteDIASCREDITO'] ?? '')), 6);
        $lineas[] = $this->linea('cteDESGLOSAIEPS', $this->soloDigitos($this->campo($c, 'cteDESGLOSAIEPS')), 1);
        $lineas[] = $this->linea('cteCODMONEDA', $codMoneda, 1);
        $lineas[] = $this->linea('cteCTAMENSAJERIA', '', 10);
        $lineas[] = $this->linea('cteNOMBREMESAJERIA', '', 60);
        $lineas[] = $this->linea('cteCALLE', $this->mayus($this->campo($c, 'cteCALLE')), 60);
        $lineas[] = $this->linea('cteNUMEXTERIOR', $this->campo($c, 'cteNUMEXTERIOR'), 30);
        $lineas[] = $this->linea('cteNUMINTERIOR', $this->campo($c, 'cteNUMINTERIOR'), 30);
        $lineas[] = $this->linea('cteCOLONIA', $this->mayus($this->campo($c, 'cteCOLONIA')), 60);
        $lineas[] = $this->linea('cteCODIGOPOSTAL', $this->soloDigitos($this->campo($c, 'cteCODIGOPOSTAL')), 6);
        $lineas[] = $this->linea('cteCIUDAD', $this->mayus($ciudad !== '' ? $ciudad : $municipio), 60);
        $lineas[] = $this->linea('cteMUNICIPIO', $this->mayus($municipio), 60);
        $lineas[] = $this->linea('cteESTADO', $this->mayus($this->campo($c, 'cteESTADO')), 60);
        $lineas[] = $this->linea('ctePAIS', strtoupper($this->campo($c, 'ctePAIS')), 60);
        $lineas[] = $this->linea('cteTELEFONO', preg_replace('/[^0-9()]/', '', $this->campo($c, 'cteTELEFONO')), 15);
        $lineas[] = $this->linea('cteBANCOCLAVESAT', $banco['clave_sat'] ?? '', 3);
        $lineas[] = $this->linea('cteBANCOCUENTA', $banco['cuenta'] ?? '', 30);
        $lineas[] = $this->linea('cteBANCOALIAS', $banco ? $this->mayus($banco['banco']) : '', 60);
        $lineas[] = $this->linea('cteBANCOCLABE', $banco['clabe'] ?? '', 20);
        $lineas[] = $this->linea('cteTEXTOEXTRA1', '', 50);
        $lineas[] = $this->linea('cteTEXTOEXTRA2', '', 50);
        $lineas[] = $this->linea('cteTEXTOEXTRA3', '', 50);
        $lineas[] = $this->linea('cteREGIMENFISCAL', $this->soloDigitos($this->campo($c, 'cteREGIMENFISCAL')), 3);

        return $lineas;
    }

    // =====================================================================
    // PASO 2: ENCABEZADO (renglones 34 al 59)
    // =====================================================================

    public function bloqueEncabezado(array $datos): array
    {
        $perfil     = $datos['perfil'];
        $moneda     = (int) ($datos['moneda'] ?? 1);
        $tipoCambio = $datos['tipo_cambio'] ?? null;
        $formaPago  = str_pad($this->soloDigitos((string) ($datos['forma_pago'] ?? '')), 2, '0', STR_PAD_LEFT);
        $banco      = $perfil['banco_ordenante'];

        // CP de expedición: opción > endpoint (por sucursal) > config
        $lugarExped = $this->soloDigitos((string) ($datos['lugar_expedicion'] ?? ''));
        if ($lugarExped === '') {
            $lugarExped = $this->soloDigitos((string) ($perfil['sucursal']['encLUGAREXPED'] ?? ''));
        }
        if ($lugarExped === '') {
            $lugarExped = $this->soloDigitos((string) config('rep.lugar_expedicion.' . strtoupper($this->normalizar((string) ($perfil['sucursal']['sucursal'] ?? ''))), ''));
        }

        $conceptoPago = (string) config('rep.concepto_pago', self::CONCEPTO_PAGO);
        $beneficiaria = $this->cuentaBeneficiaria((string) ($datos['banco_receptor'] ?? ''));
        $beneficiaria['origen'] = 'config/rep.php';

        // Si no hay cuenta capturada en config/rep.php, se usa la cuenta bancaria del endpoint del cliente
        if ($beneficiaria['cuenta'] === '' && $banco) {
            $beneficiaria = [
                'alias'     => $this->mayus($banco['banco']),
                'cuenta'    => $banco['cuenta'],
                'clabe'     => $banco['clabe'],
                'clave_sat' => $banco['clave_sat'],
                'origen'    => 'endpoint del cliente',
            ];
        }
        $seriePago    = (string) config('rep.serie_pago', self::SERIE_PAGO);

        if ($moneda === 2 && (!is_numeric($tipoCambio) || (float) $tipoCambio <= 1)) {
            $this->errores['encTIPOCAMBIO'] = 'El pago es en dólares y no se indicó un tipo de cambio válido.';
        }
        $this->requerido('encDOCUMENTO', $conceptoPago, 'Falta el concepto del REP (rep.concepto_pago).');
        $this->requerido('encLUGAREXPED', $lugarExped, 'El endpoint del cliente no trae encLUGAREXPED para la sucursal.');
        if ($formaPago === '00') {
            $this->errores['encFORMAPAGO'] = 'Falta la forma de pago (catálogo c_FormaPago del SAT).';
        }

        $lineas = [];
        $lineas[] = $this->linea('encTIPODOC', self::TIPO_DOCUMENTO_PAGO, 3);
        $lineas[] = $this->linea('encDOCUMENTO', $conceptoPago, 30);
        $lineas[] = $this->linea('encSERIE', $this->soloCodigo($seriePago), 7);
        $lineas[] = $this->linea('encTIPOCAMBIO', $this->formatearTipoCambio($moneda === 1 ? 1 : $tipoCambio), 8);
        $lineas[] = $this->linea('encFOLIOVENTA', $this->soloAlfanumerico((string) ($datos['folio_venta'] ?? '')), 20);
        $lineas[] = $this->linea('encOBSERVACION', (string) ($datos['observacion'] ?? ''), 250);
        $lineas[] = $this->linea('encTEXTOEXTRA1', (string) ($datos['banco_receptor'] ?? ''), 50);
        $lineas[] = $this->linea('encTEXTOEXTRA2', '', 50);
        $lineas[] = $this->linea('encTEXTOEXTRA3', '', 50);
        $lineas[] = $this->linea('encFORMAPAGO', $formaPago, 2);
        // El layout (2022) indica longitud 3, pero el uso CP01 de CFDI 4.0 tiene 4 caracteres
        $lineas[] = $this->linea('encUSOCFDI', self::USO_CFDI_REP, 4);
        $lineas[] = $this->linea('encCONDICIONES', '', 100);
        $lineas[] = $this->linea('encPARCIALIDAD', (string) max(1, (int) ($datos['parcialidad'] ?? 1)), 3);
        $lineas[] = $this->linea('encPARCIALIDADES', (string) max(1, (int) ($datos['parcialidades'] ?? 1)), 3);
        // La cuenta del cliente (ordenante) va en los campos cte*.
        // Aquí va la cuenta de la EMPRESA donde se recibió el pago (beneficiaria), como en el ejemplo oficial.
        $lineas[] = $this->linea('encLUGAREXPED', $lugarExped, 5);
        $lineas[] = $this->linea('encBANCOCLAVESAT', $beneficiaria['clave_sat'], 3);
        $lineas[] = $this->linea('encBANCOCUENTA', $beneficiaria['cuenta'], 30);
        $lineas[] = $this->linea('encBANCOALIAS', $beneficiaria['alias'], 60);
        $lineas[] = $this->linea('encBANCOCLABE', $beneficiaria['clabe'], 20);
        // [encTIPORELACION], [encCFDIRELACION], [encTIPORELACION40], [encCFDIRELACION40] y
        // [encCLAVECONFIRMA] no pueden ir vacíos: se omiten porque el REP no relaciona CFDI previos.
        $lineas[] = $this->linea('encCFDI', !empty($datos['timbrar']) ? 'SI' : 'NO', 2);

        if ($beneficiaria['cuenta'] === '') {
            $this->advertencias['encBANCOCUENTA'] = 'El cliente no tiene una cuenta bancaria en el endpoint ni hay una cuenta configurada en config/rep.php; las líneas de banco del encabezado se omiten.';
        }

        $this->resumen['encabezado'] = [
            'lugar_expedicion' => $lugarExped,
            // Cuenta del cliente (ordenante)
            'banco'            => $banco ? $banco['banco'] : '',
            'cuenta'           => $banco['cuenta'] ?? '',
            'clabe'            => $banco['clabe'] ?? '',
            'clave_sat'        => $banco['clave_sat'] ?? '',
            // Cuenta de la empresa (beneficiaria)
            'beneficiaria_alias'  => $beneficiaria['alias'],
            'beneficiaria_cuenta' => $beneficiaria['cuenta'],
            'beneficiaria_clabe'  => $beneficiaria['clabe'],
            'beneficiaria_clave'  => $beneficiaria['clave_sat'],
            'beneficiaria_origen' => $beneficiaria['origen'],
        ];

        return $lineas;
    }

    /**
     * Cuenta de la empresa donde se recibió el pago, según el banco receptor del ingreso
     * (config/rep.php → cuentas_beneficiarias). La clave SAT se deduce del nombre si no se configura.
     */
    private function cuentaBeneficiaria(string $bancoReceptor): array
    {
        $clave = $this->normalizar(trim(preg_replace('/\s+/', ' ', $bancoReceptor)));
        $datos = [];

        foreach ((array) config('rep.cuentas_beneficiarias', []) as $nombre => $config) {
            if ($this->normalizar(trim(preg_replace('/\s+/', ' ', (string) $nombre))) === $clave) {
                $datos = (array) $config;
                break;
            }
        }

        $cuenta = $this->soloDigitos((string) ($datos['cuenta'] ?? ''));
        $clabe  = $this->soloDigitos((string) ($datos['clabe'] ?? ''));
        $claveSat = $this->soloDigitos((string) ($datos['clave_sat'] ?? ''));
        if ($claveSat === '') {
            $claveSat = strlen($clabe) === 18 ? substr($clabe, 0, 3) : $this->claveBancoPorNombre($bancoReceptor);
        }

        return [
            'alias'     => trim((string) ($datos['alias'] ?? $bancoReceptor)),
            'cuenta'    => $cuenta,
            'clabe'     => strlen($clabe) === 18 ? $clabe : '',
            'clave_sat' => $claveSat,
        ];
    }

    // =====================================================================
    // PASO 3: PAGO (renglones 60 al 63)
    // =====================================================================

    public function bloquePago(array $datos): array
    {
        $fecha = null;
        try {
            $fecha = !empty($datos['fecha']) ? Carbon::parse($datos['fecha']) : null;
        } catch (\Exception $e) {
            $fecha = null;
        }

        if (!$fecha) {
            $this->errores['pagoFECHA'] = 'El ingreso no tiene una fecha de pago válida.';
        }

        $conDocumentos = !empty($datos['con_documentos']);

        $lineas = [];
        $lineas[] = $this->linea('pagoFECHA', $fecha ? $fecha->format('d-m-Y') : '', 10);
        $lineas[] = $this->linea('pagoHORA', '', 10);
        // Solo se expresa cuando no hay documentos a saldar; si los hay, SyncCOM lo calcula
        $lineas[] = $this->linea('pagoIMPORTE', $conDocumentos ? '' : $this->formatearImporte($datos['importe'] ?? 0), 40);
        $lineas[] = $this->linea('pagoFOLIO', (string) max(0, (int) ($datos['folio_pago'] ?? 0)), 20);

        return $lineas;
    }

    // =====================================================================
    // PASO 4: DOCUMENTOS A SALDAR (renglones 64 al 67)
    // =====================================================================

    public function bloquesSaldar(array $documentos, array $perfil): array
    {
        if (empty($documentos)) {
            $this->errores['saldarDOCUMENTO'] = 'No hay facturas a saldar: el ingreso no tiene operaciones con folio e importe (monto_cfdi).';
            return [];
        }

        $lineas = [];
        $incluirFolio = (bool) config('rep.incluir_folio', self::INCLUIR_FOLIO_EN_SALDAR);
        $incluirUuid  = (bool) config('rep.incluir_uuid', self::INCLUIR_UUID_EN_SALDAR);

        foreach (array_values($documentos) as $i => $documento) {
            $numero  = $i + 1;
            $folio   = $this->soloAlfanumerico((string) ($documento['folio'] ?? ''));
            $serie   = strtoupper(trim((string) ($documento['serie'] ?? '')));
            if ($serie === '') {
                $serie = strtoupper(trim((string) ($perfil['sucursal']['SaldarSERIE'] ?? '')));
            }
            $importe = (float) ($documento['importe'] ?? 0);
            $uuid    = strtoupper(trim((string) ($documento['uuid'] ?? '')));
            $concepto = $this->conceptoFacturaPorSerie($serie, $perfil);

            if ($concepto === '') {
                $this->errores["saldarDOCUMENTO#{$numero}"] = "No hay SaldarDOCUMENTO para la serie '{$serie}' en el endpoint del cliente.";
            }
            if ($importe <= 0) {
                $this->errores["saldarIMPORTE#{$numero}"] = "La factura {$serie} {$folio} no tiene importe.";
            }
            if ($incluirFolio && $folio === '') {
                $this->errores["saldarFOLIO#{$numero}"] = "La factura {$numero} no tiene folio.";
            }
            if (!$incluirFolio && $uuid === '') {
                $this->errores["saldarUUID#{$numero}"] = "No se encontró el UUID de la factura {$serie} {$folio}; sin folio ni UUID, SyncCOM no podría identificarla.";
            }

            $lineas[] = $this->linea('saldarDOCUMENTO', $concepto, 30);
            $lineas[] = $this->linea('saldarSERIE', $this->soloCodigo($serie), 7);
            if ($incluirFolio) {
                $lineas[] = $this->linea('saldarFOLIO', $folio, 20);
            }
            $lineas[] = $this->linea('saldarIMPORTE', $this->formatearImporte($importe), 40);
            // Sin folio, el UUID siempre se escribe: es lo único que identifica la factura
            if ($uuid !== '' && ($incluirUuid || !$incluirFolio)) {
                $lineas[] = $this->linea((string) config('rep.descriptor_uuid', self::DESCRIPTOR_UUID_SALDAR), $uuid, 36);
            }
        }

        return $lineas;
    }

    /**
     * SaldarDOCUMENTO de la sucursal cuyo SaldarSERIE coincide con la serie de la factura.
     * Si no hay coincidencia, usa el de la sucursal del ingreso.
     */
    private function conceptoFacturaPorSerie(string $serie, array $perfil): string
    {
        foreach ((array) $perfil['sucursales'] as $sucursal) {
            if (strtoupper(trim((string) ($sucursal['SaldarSERIE'] ?? ''))) === $serie && $serie !== '') {
                return trim((string) ($sucursal['SaldarDOCUMENTO'] ?? ''));
            }
        }
        return trim((string) ($perfil['sucursal']['SaldarDOCUMENTO'] ?? ''));
    }

    /**
     * Facturas a saldar desde ingreso_operacion:
     * folio = número de la referencia ("68245 - 6001815 - CLIENTE" → 68245), importe = monto_cfdi.
     * La serie inicial es la de la sucursal; se corrige con la del XML al encontrar el UUID.
     */
    public function documentosDesdeIngreso(IngresoConciliado $ingreso, array $perfil): array
    {
        $serie = strtoupper(trim((string) ($perfil['sucursal']['SaldarSERIE'] ?? '')));
        $porFolio = [];
        $operacionesPorFolio = [];

        $filas = DB::table('ingreso_operacion')->where('ingreso_id', $ingreso->id)->get();
        foreach ($filas as $fila) {
            $folio   = $this->extraerFolioFactura((string) $fila->referencia);
            $importe = round((float) ($fila->monto_cfdi ?? 0), 2);

            if ($folio === '' || $importe <= 0) {
                continue;
            }
            $porFolio[$folio] = round(($porFolio[$folio] ?? 0) + $importe, 2);

            if (!empty($fila->operacion_id)) {
                $operacionesPorFolio[$folio][] = [
                    'id'   => (int) $fila->operacion_id,
                    'type' => (string) $fila->operacion_type,
                ];
            }
        }

        if (empty($porFolio)) {
            $folios = array_values(array_filter(array_map(function ($texto) {
                return $this->extraerFolioFactura($texto);
            }, explode(',', (string) $ingreso->folio_sc))));

            if (count($folios) === 1 && (float) $ingreso->honorarios > 0) {
                $porFolio[$folios[0]] = round((float) $ingreso->honorarios, 2);
                $this->advertencias['saldarIMPORTE'] = 'Sin operaciones con importe: se usaron los honorarios del ingreso para el folio ' . $folios[0] . '.';
            }
        }

        $documentos = [];
        foreach ($porFolio as $folio => $importe) {
            $documentos[] = [
                'folio'       => (string) $folio,
                'importe'     => $importe,
                'serie'       => $serie,
                'operaciones' => $operacionesPorFolio[$folio] ?? [],
            ];
        }

        return $documentos;
    }

    // =====================================================================
    // UUID Y SERIE DE LAS FACTURAS (XML DE LA OPERACIÓN)
    // =====================================================================

    /**
     * Agrega 'uuid' y corrige 'serie' de cada documento con los datos de su XML timbrado.
     */
    public function completarUuids(array $documentos, IngresoConciliado $ingreso, array $perfil): array
    {
        $operacionesIngreso = DB::table('ingreso_operacion')
            ->where('ingreso_id', $ingreso->id)
            ->whereNotNull('operacion_id')
            ->get(['operacion_id', 'operacion_type'])
            ->map(function ($fila) {
                return ['id' => (int) $fila->operacion_id, 'type' => (string) $fila->operacion_type];
            })
            ->unique(function ($op) {
                return $op['type'] . '-' . $op['id'];
            })
            ->values()
            ->all();

        foreach ($documentos as $i => $documento) {
            $folio = $this->soloAlfanumerico((string) ($documento['folio'] ?? ''));
            if ($folio === '' || !empty($documento['uuid'])) {
                continue;
            }

            $operaciones = !empty($documento['operaciones']) ? $documento['operaciones'] : $operacionesIngreso;
            $encontrado = null;

            foreach ($operaciones as $op) {
                $encontrado = $this->buscarXmlFactura($op['type'], $op['id'], $folio, (string) ($documento['serie'] ?? ''));
                if ($encontrado) {
                    break;
                }
            }

            if (!$encontrado) {
                $this->advertencias["saldarUUID#{$folio}"] = "No se encontró el XML timbrado del folio {$folio} entre los archivos de la operación.";
                continue;
            }

            $documentos[$i]['uuid'] = $encontrado['uuid'];

            // La serie real es la del XML (un REP de Nogales puede saldar facturas ZLO)
            if ($encontrado['serie'] !== '') {
                $documentos[$i]['serie'] = $encontrado['serie'];
            }

            // En pagos parciales el importe es menor al total; solo se avisa si lo excede
            $importe = (float) ($documento['importe'] ?? 0);
            if ($encontrado['total'] > 0 && $importe - $encontrado['total'] > 0.01) {
                $this->advertencias["saldarIMPORTE#{$folio}"] = sprintf(
                    'El importe a saldar de %s %s (%s) es mayor al total de la factura (%s).',
                    $encontrado['serie'],
                    $folio,
                    number_format($importe, 2),
                    number_format($encontrado['total'], 2)
                );
            }
        }

        return $documentos;
    }

    /**
     * Busca entre los XML de una operación (y de su padre) el de la factura con ese folio.
     * Se descartan los REP (TipoDeComprobante P) y se prefiere el que coincide en serie.
     */
    private function buscarXmlFactura(string $operacionType, int $operacionId, string $folio, string $serie)
    {
        $tipoApi = $this->tipoApiOperacion($operacionType);
        if ($tipoApi === null || $operacionId <= 0) {
            return null;
        }

        $ids = [$operacionId];
        $tabla = $tipoApi === 'importaciones' ? 'operaciones_importacion' : 'operaciones_exportacion';
        $llave = $tipoApi === 'importaciones' ? 'id_importacion' : 'id_exportacion';
        $padre = DB::table($tabla)->where($llave, $operacionId)->value('parent');
        if (!empty($padre)) {
            $ids[] = (int) $padre;
        }

        $folioBuscado = ltrim($folio, '0');
        $serieBuscada = strtoupper(trim($serie));
        $sinCoincidirSerie = null;

        foreach ($ids as $id) {
            foreach ($this->archivosXmlDeOperacion($tipoApi, $id) as $urlXml) {
                $datos = $this->leerXmlFactura($urlXml);
                if (!$datos || $datos['uuid'] === '' || $datos['tipo'] === 'P') {
                    continue;
                }
                if (ltrim($datos['folio'], '0') !== $folioBuscado) {
                    continue;
                }
                if ($serieBuscada === '' || $datos['serie'] === $serieBuscada) {
                    return $datos;
                }
                $sinCoincidirSerie = $sinCoincidirSerie ?: $datos;
            }
        }

        return $sinCoincidirSerie;
    }

    private function archivosXmlDeOperacion(string $tipoApi, int $operacionId): array
    {
        try {
            $url = sprintf(self::URL_ARCHIVOS_OPERACION, $tipoApi, $operacionId);
            $respuesta = Http::withoutVerifying()->timeout(10)->get($url);

            if (!$respuesta->successful() || !is_array($respuesta->json())) {
                return [];
            }

            $urls = [];
            foreach ($respuesta->json() as $archivo) {
                if (!is_array($archivo)) {
                    continue;
                }
                $nombre = (string) ($archivo['name'] ?? '');
                if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'xml') {
                    continue;
                }
                $urlArchivo = $archivo['url']['normal'] ?? (is_string($archivo['url'] ?? null) ? $archivo['url'] : null);
                if ($urlArchivo) {
                    $urls[] = $urlArchivo;
                }
            }
            return $urls;
        } catch (\Exception $e) {
            Log::warning('[REP] No se pudieron leer los archivos de la operación', [
                'tipo' => $tipoApi,
                'id' => $operacionId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Descarga un XML (con respaldo en el CDN) y extrae UUID, tipo, folio, serie, total y lugar de expedición.
     */
    private function leerXmlFactura(string $url)
    {
        if (array_key_exists($url, $this->cacheXml)) {
            return $this->cacheXml[$url];
        }

        $xml = null;
        try {
            $headers = ['User-Agent' => 'Mozilla/5.0'];
            $respuesta = Http::withoutVerifying()->withHeaders($headers)->timeout(15)->get($url);
            $xml = $respuesta->successful() ? $respuesta->body() : null;

            if (!$xml) {
                $respuestaAlt = Http::withoutVerifying()->withHeaders($headers)->timeout(15)
                    ->get(self::URL_CDN_UPLOADS . basename($url));
                $xml = $respuestaAlt->successful() ? $respuestaAlt->body() : null;
            }
        } catch (\Exception $e) {
            $xml = null;
        }

        if (!$xml) {
            return $this->cacheXml[$url] = null;
        }

        $uuid = '';
        if (preg_match('/TimbreFiscalDigital[^>]*\sUUID\s*=\s*["\']([0-9A-Fa-f\-]{36})["\']/s', $xml, $m)) {
            $uuid = strtoupper($m[1]);
        }

        $atributo = function ($nombre, $atributos) {
            return preg_match('/\s' . $nombre . '\s*=\s*["\']([^"\']*)["\']/', $atributos, $m) ? trim($m[1]) : '';
        };

        $datos = ['uuid' => $uuid, 'tipo' => '', 'folio' => '', 'serie' => '', 'total' => 0.0, 'lugar_expedicion' => ''];

        if (preg_match('/<(?:\w+:)?Comprobante\b([^>]*)>/s', $xml, $mComp)) {
            $attrs = $mComp[1];
            $datos['tipo']  = strtoupper($atributo('TipoDeComprobante', $attrs));
            $datos['folio'] = $this->soloAlfanumerico($atributo('Folio', $attrs));
            $datos['serie'] = strtoupper($atributo('Serie', $attrs));
            $datos['total'] = (float) str_replace(',', '', $atributo('Total', $attrs));
            $datos['lugar_expedicion'] = $this->soloDigitos($atributo('LugarExpedicion', $attrs));
        }

        return $this->cacheXml[$url] = $datos;
    }

    private function tipoApiOperacion(string $operacionType)
    {
        $tipo = strtoupper($operacionType);
        if (str_contains($tipo, 'IMPORTACION')) {
            return 'importaciones';
        }
        if (str_contains($tipo, 'EXPORTACION')) {
            return 'exportaciones';
        }
        return null;
    }

    /**
     * Folio de factura dentro de una referencia: "68245 - 6001815 - CLIENTE" → "68245",
     * "F - 68370" → "68370", "ZLO6478" → "6478". Descarta números de 7 dígitos (pedimentos).
     */
    public function extraerFolioFactura(string $texto): string
    {
        $texto = strtoupper(trim($texto));
        $texto = preg_replace('/^(F|SC|TR)\s*[-:]\s*/', '', $texto);
        $primerSegmento = trim(preg_split('/\s+-\s+|\//', $texto)[0] ?? '');

        if (preg_match_all('/\d+/', $primerSegmento, $m)) {
            foreach ($m[0] as $numero) {
                if (strlen($numero) !== 7) {
                    return ltrim($numero, '0') !== '' ? ltrim($numero, '0') : $numero;
                }
            }
        }
        return '';
    }

    // =====================================================================
    // ARMADO DEL ARCHIVO
    // =====================================================================

    public function construirTexto(array $lineas, bool $ansi = false): string
    {
        if (self::OMITIR_LINEAS_VACIAS) {
            $lineas = $this->quitarLineasVacias($lineas);
        }

        $texto = rtrim(implode(self::FIN_DE_LINEA, $lineas));

        if ($ansi) {
            $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
            if ($convertido !== false) {
                $texto = $convertido;
            }
        }

        return $texto;
    }

    public function quitarLineasVacias(array $lineas): array
    {
        return array_values(array_filter($lineas, function ($linea) {
            return !preg_match('/^\[[A-Za-z0-9]+\]\s*$/', $linea);
        }));
    }

    public function errores(): array
    {
        return $this->errores;
    }

    public function tieneErrores(): bool
    {
        return !empty($this->errores);
    }

    public function advertencias(): array
    {
        return $this->advertencias;
    }

    /** Datos usados en la última generación: perfil del cliente, encabezado y documentos */
    public function resumen(): array
    {
        return $this->resumen;
    }

    /** 1 = Pesos, 2 = Dólares según la cuenta receptora */
    public function monedaPorBanco($banco): int
    {
        $bancoUpper = strtoupper((string) $banco);
        $esDolares = str_contains($bancoUpper, 'DLLS') || str_contains($bancoUpper, 'USD')
            || str_contains($bancoUpper, 'DOLARES') || str_contains($bancoUpper, 'DLL');

        return $esDolares ? 2 : 1;
    }

    // =====================================================================
    // AUXILIARES
    // =====================================================================

    private function linea(string $descriptor, $valor, int $longitudMaxima): string
    {
        $valor = $this->limpiar((string) $valor);

        if (mb_strlen($valor, 'UTF-8') > $longitudMaxima) {
            $valor = rtrim(mb_substr($valor, 0, $longitudMaxima, 'UTF-8'));
        }

        return '[' . $descriptor . ']' . $valor;
    }

    private function limpiar(string $valor): string
    {
        $valor = str_replace(["\r", "\n", "\t", '[', ']'], ' ', $valor);
        $valor = preg_replace('/\s{2,}/', ' ', $valor);
        return trim($valor);
    }

    private function campo(array $campos, string $clave): string
    {
        $valor = $campos[$clave] ?? '';
        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private function requerido(string $descriptor, string $valor, string $mensaje)
    {
        if (trim($valor) === '') {
            $this->errores[$descriptor] = $mensaje;
        }
    }

    private function mayus(string $valor): string
    {
        return mb_strtoupper($valor, 'UTF-8');
    }

    /** Mayúsculas sin acentos, para comparar nombres de sucursales */
    private function normalizar(string $valor): string
    {
        $valor = mb_strtoupper($valor, 'UTF-8');
        return strtr($valor, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    private function claveBancoPorNombre(string $banco): string
    {
        $bancoUpper = $this->normalizar($banco);
        foreach (self::CLAVES_BANCO_SAT as $nombre => $clave) {
            if (str_contains($bancoUpper, $nombre)) {
                return $clave;
            }
        }
        return '';
    }

    private function formatearImporte($importe): string
    {
        return number_format((float) $importe, 2, '.', '');
    }

    private function formatearTipoCambio($tipoCambio): string
    {
        $tc = (float) $tipoCambio;
        if ($tc <= 1) {
            return '1';
        }
        return rtrim(rtrim(number_format($tc, 4, '.', ''), '0'), '.');
    }

    private function soloDigitos(string $valor): string
    {
        return preg_replace('/[^0-9]/', '', $valor);
    }

    private function soloAlfanumerico(string $valor): string
    {
        return preg_replace('/[^0-9a-zA-Z]/', '', $valor);
    }

    private function soloCodigo(string $valor): string
    {
        return preg_replace('/[^0-9a-zA-Z\-]/', '', $valor);
    }

    private function soloRfc(string $valor): string
    {
        return preg_replace('/[^0-9A-Z&Ñ]/u', '', mb_strtoupper($valor, 'UTF-8'));
    }

    private function primerCorreo(string $valor): string
    {
        foreach (preg_split('/[,;\s]+/', $valor) as $correo) {
            if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return $correo;
            }
        }
        return '';
    }
}
