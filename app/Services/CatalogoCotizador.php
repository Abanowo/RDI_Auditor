<?php

namespace App\Services;

use App\Models\ConceptoCatalogo;
use App\Models\Seccion;
use App\Models\TerminoCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos por defecto del cotizador: bloques, conceptos predefinidos y
 * términos y condiciones. El controlador llama a asegurar() al abrir el
 * modal, así que no hace falta correr ningún seeder.
 */
class CatalogoCotizador
{
    /** Súbelo cuando cambien los catálogos de app/Services/catalogos para volver a sincronizar. */
    const VERSION = 5;

    /** Llena los catálogos solo si están vacíos (no sobrescribe lo que ya editaste). */
    public function asegurar()
    {
        if (Seccion::count() === 0 || TerminoCatalogo::count() === 0) {
            $this->cargar();
        }

        $columnas = $this->tieneColumnasSegmentos();
        // La marca distingue si ya se corrió la migración: al correrla se vuelve a sincronizar
        $marca = storage_path('app/cotizador_catalogo_v' . self::VERSION . ($columnas ? '' : '_sin_segmentos') . '.flag');

        $pendiente = !is_file($marca);
        if ($columnas && !$pendiente) {
            $claves      = array_keys((array) config('cotizador.prefijos_sucursal'));
            $sinImportar = ConceptoCatalogo::whereNotNull('clave_sat')->count() === 0;
            // Sucursales guardadas con un nombre que ya no está en la configuración (ej. "NUEVO LAREDO")
            $desfasados  = ConceptoCatalogo::whereNotNull('sucursal')->whereNotIn('sucursal', $claves)->exists();
            $pendiente   = $sinImportar || $desfasados;
        }

        if ($pendiente) {
            $this->sincronizarSecciones();
            if ($columnas) {
                $this->importarSegmentos();
                $this->reasignarBloques();
            }
            $this->cargarServicios($columnas);
            $completos = is_file(__DIR__ . '/catalogos/segmentos_contables.php')
                && is_file(__DIR__ . '/catalogos/servicios_predefinidos.php');
            if ($completos) {
                @file_put_contents($marca, date('c'));
            }
        }
    }

    /** ¿Ya existe la migración 2026_10_20_000003 (sucursal y clave SAT)? */
    public function tieneColumnasSegmentos()
    {
        return Schema::hasColumn('conceptos_catalogo', 'clave_sat')
            && Schema::hasColumn('conceptos_catalogo', 'sucursal');
    }

    /** Mensajes para mostrar en el generador cuando falta algo en el servidor. */
    public function avisos()
    {
        $avisos = [];
        if (!$this->tieneColumnasSegmentos()) {
            $avisos[] = 'Faltan los 197 Segmentos Contables del Excel y el filtro por sucursal: '
                . 'ejecuta "php artisan migrate" (migración 2026_10_20_000003_add_sucursal_clave_sat_to_conceptos).';
        }
        foreach (['segmentos_contables.php', 'servicios_predefinidos.php', 'terminos.php'] as $archivo) {
            if (!is_file(__DIR__ . '/catalogos/' . $archivo)) {
                $avisos[] = 'Falta el archivo app/Services/catalogos/' . $archivo
                    . ' (copia la carpeta "catalogos" dentro de app/Services).';
            }
        }

        return $avisos;
    }

    /** Bloques del generador (incluye Muestra) en su orden. */
    protected function definicionSecciones()
    {
        return [
            ['nombre' => 'Honorarios',            'titulo_pdf' => 'SERVICIOS ADUANALES EN MEXICO',  'color' => 'azul',    'aplica_iva' => true],
            ['nombre' => 'Servicio de operación', 'titulo_pdf' => 'MANIOBRAS EN MEXICO (MAS IVA)',  'color' => 'azul',    'aplica_iva' => true],
            ['nombre' => 'Transporte',            'titulo_pdf' => 'TRANSPORTE (MAS IVA)',           'color' => 'naranja', 'aplica_iva' => true],
            ['nombre' => 'Impuestos',             'titulo_pdf' => 'IMPUESTOS & DERECHOS EN MEXICO', 'color' => 'naranja', 'aplica_iva' => false],
            [
                'nombre' => 'Intactics LLC - Gastos americanos pagados a cuenta de cliente',
                'titulo_pdf' => 'SERVICIOS PRESTADOS EN USA (NO APLICA IVA)',
                'color' => 'naranja',
                'aplica_iva' => false
            ],
            ['nombre' => 'Emisión de certificado internacional', 'titulo_pdf' => 'EMISIÓN DE CERTIFICADO INTERNACIONAL', 'color' => 'naranja', 'aplica_iva' => false],
            ['nombre' => 'Maniobras por cuenta de cliente',      'titulo_pdf' => 'MANIOBRAS POR CUENTA DE CLIENTE',      'color' => 'naranja', 'aplica_iva' => false],
            ['nombre' => 'Muestra',               'titulo_pdf' => 'MUESTRAS (MAS IVA)',             'color' => 'naranja', 'aplica_iva' => true],
        ];
    }

    /** Crea los bloques que falten (Muestra) y actualiza orden, color e IVA de los existentes. */
    public function sincronizarSecciones()
    {
        foreach ($this->definicionSecciones() as $orden => $datos) {
            Seccion::updateOrCreate(
                ['nombre' => $datos['nombre']],
                array_merge($datos, ['orden' => $orden + 1, 'activo' => true])
            );
        }
    }

    /** Mueve MUESTRAS y MANIOBRAS EN ALMACEN FISCALIZADO del Excel a su bloque. */
    protected function reasignarBloques()
    {
        $bloques = Seccion::pluck('id', 'nombre');

        foreach (ConceptoCatalogo::whereNotNull('clave_sat')->get() as $concepto) {
            $nombre = strtoupper($concepto->descripcion);
            if (strpos($nombre, 'MUESTRA') === false && strpos($nombre, 'ALMACEN FISCALIZADO') === false) {
                continue;
            }
            $bloque = $this->bloqueDeCodigo($concepto->codigo, $nombre);
            if (isset($bloques[$bloque]) && (int) $concepto->seccion_id !== (int) $bloques[$bloque]) {
                $concepto->update([
                    'seccion_id'      => $bloques[$bloque],
                    'descripcion_doc' => $this->descripcionDocDe($bloque),
                ]);
            }
        }
    }

    /**
     * Conceptos de cada servicio (app/Services/catalogos/servicios_predefinidos.php),
     * con su código, descripción, precio y descripción doc tal como vienen en el archivo.
     * La sucursal sale del prefijo del código, igual que en los Segmentos Contables.
     */
    public function cargarServicios($conSegmentos = true)
    {
        $archivo = __DIR__ . '/catalogos/servicios_predefinidos.php';
        if (!is_file($archivo)) {
            return;     // avisos() lo reporta en el modal
        }
        $bloques = Seccion::pluck('id', 'nombre');
        $filas   = require $archivo;

        // Versión anterior: los precios de N102/N105 del prototipo se habían pasado a N100/N102 del Excel
        if ($conSegmentos) ConceptoCatalogo::whereNotNull('clave_sat')
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->where('codigo', 'N100')->where('precio_usd', 60);
                })
                    ->orWhere(function ($w) {
                        $w->where('codigo', 'N102')->where('precio_usd', 65);
                    });
            })
            ->update(['precio_usd' => 0]);

        foreach ($filas as $fila) {
            list($bloque, $codigo, $descripcion, $usd, $doc) = $fila;
            if (!isset($bloques[$bloque])) {
                continue;
            }

            $buscado  = $this->normalizar($descripcion);
            $concepto = ConceptoCatalogo::where('seccion_id', $bloques[$bloque])
                ->where('codigo', $codigo)
                ->get()
                ->first(function ($c) use ($buscado) {
                    $actual = $this->normalizar($c->descripcion);
                    // Mismo nombre, o uno contiene al otro ("CONECTIVIDAD / VALIDACIÓN Y CONECTIVIDAD DE PEDIMENTO")
                    return $actual === $buscado || strpos($actual, $buscado) !== false || strpos($buscado, $actual) !== false;
                });

            $datos = [
                'seccion_id'      => $bloques[$bloque],
                'codigo'          => $codigo,
                'descripcion'     => $descripcion,
                'precio_usd'      => $usd,
                'descripcion_doc' => $doc,
                'activo'          => true,
            ];

            if ($conSegmentos) {
                // Misma regla que el Excel: N → Nogales, T → Tijuana, M → Mexicali…
                // WS, C, MU y 0000 no tienen sucursal y se ven en todas.
                $datos['sucursal'] = $this->sucursalDeCodigo($codigo);
            }

            if ($concepto) {
                // El precio en pesos (ej. CONTRAPRESTACION $53.45) se conserva
                $concepto->update($datos);
            } else {
                ConceptoCatalogo::create(array_merge($datos, ['precio_mxn' => 0]));
            }
        }
    }

    /**
     * Carga app/Services/catalogos/segmentos_contables.php en conceptos_catalogo.
     * Un concepto del mismo bloque con el mismo código y nombre parecido (o la misma
     * clave SAT) se actualiza con su sucursal y clave SAT; si no existe, se crea con precio 0.
     * Así T101 FLETE (Transporte) y T101 HONORARIOS DE EXPORTACION (Honorarios, Tijuana)
     * conviven sin pisarse.
     */
    public function importarSegmentos()
    {
        $archivo = __DIR__ . '/catalogos/segmentos_contables.php';
        if (!is_file($archivo)) {
            return 0;   // avisos() lo reporta en el modal
        }
        $bloques = Seccion::pluck('id', 'nombre');
        $filas   = require $archivo;
        $creados = 0;

        foreach ($filas as $fila) {
            list($codigo, $claveSat, $nombre) = $fila;
            $bloque = $this->bloqueDeCodigo($codigo, $nombre);
            if (!isset($bloques[$bloque])) {
                continue;
            }

            $sucursal = $this->sucursalDeCodigo($codigo);
            $buscado  = $this->normalizar($nombre);

            $existentes = ConceptoCatalogo::where('seccion_id', $bloques[$bloque])
                ->where('codigo', $codigo)
                ->get()
                ->filter(function ($c) use ($buscado, $claveSat) {
                    if ($c->clave_sat && $c->clave_sat === $claveSat) {
                        return true;
                    }
                    $actual = $this->normalizar($c->descripcion);

                    return $actual === $buscado || strpos($actual, $buscado) !== false || strpos($buscado, $actual) !== false;
                });

            if ($existentes->isNotEmpty()) {
                foreach ($existentes as $concepto) {
                    $concepto->update(['sucursal' => $sucursal, 'clave_sat' => $claveSat]);
                }
                continue;
            }

            ConceptoCatalogo::create([
                'seccion_id'      => $bloques[$bloque],
                'sucursal'        => $sucursal,
                'codigo'          => $codigo,
                'clave_sat'       => $claveSat,
                'descripcion'     => $nombre,
                'precio_usd'      => 0,
                'precio_mxn'      => 0,
                'descripcion_doc' => $this->descripcionDocDe($bloque),
                'activo'          => true,
            ]);
            $creados++;
        }

        return $creados;
    }

    /** "ZLO306" → "MANZANILLO". Prefijos más largos primero (ZLO antes que Z, NL antes que N). */
    public function sucursalDeCodigo($codigo)
    {
        $codigo   = strtoupper(trim($codigo));
        $prefijos = [];
        foreach ((array) config('cotizador.prefijos_sucursal') as $clave => $lista) {
            foreach ((array) $lista as $prefijo) {
                $prefijos[strtoupper($prefijo)] = $clave;
            }
        }
        uksort($prefijos, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($prefijos as $prefijo => $clave) {
            if (strpos($codigo, $prefijo) === 0 && preg_match('/^\d*$/', substr($codigo, strlen($prefijo)))) {
                return $clave;
            }
        }

        return null;   // sin sucursal: se muestra en todas
    }

    /**
     * Bloque según el número del segmento:
     *   100–101 Honorarios · 102–299 Servicio de operación · 300–399 Transporte
     *   400+ Servicios logísticos → Transporte, el resto → Servicio de operación
     */
    protected function bloqueDeCodigo($codigo, $nombre)
    {
        if (!preg_match('/^([A-Z]+)(\d*)$/', strtoupper($codigo), $m)) {
            return 'Servicio de operación';
        }
        list(, $prefijo, $numero) = $m;
        $n = (int) $numero;

        if (strpos($nombre, 'MUESTRA') !== false) {
            return 'Muestra';                         // x201 MUESTRAS
        }
        if (strpos($nombre, 'ALMACEN FISCALIZADO') !== false) {
            return 'Maniobras por cuenta de cliente'; // x207 MANIOBRAS EN ALMACEN FISCALIZADO
        }
        if ($prefijo === 'L') {
            return 'Transporte';                      // L100 cruce fronterizo, L400 servicios logísticos
        }
        if ($prefijo === 'NOG' || $prefijo === 'ZLO') {
            return 'Servicio de operación';
        }
        if ($n === 100 || $n === 101) {
            return 'Honorarios';
        }
        if ($n >= 300 && $n < 400) {
            return 'Transporte';
        }
        if ($n >= 400 && strpos($nombre, 'LOGISTIC') !== false) {
            return 'Transporte';
        }

        return 'Servicio de operación';
    }

    protected function descripcionDocDe($bloque)
    {
        if ($bloque === 'Honorarios') {
            return 'Por factura';
        }
        if ($bloque === 'Muestra') {
            return 'Por muestra';
        }
        if ($bloque === 'Maniobras por cuenta de cliente') {
            return 'Por maniobra';
        }

        return $bloque === 'Transporte' ? 'Por evento' : 'Por pedimento';
    }

    /** Crea o actualiza los registros por defecto (se puede correr varias veces). */
    public function cargar()
    {
        $secciones = $this->definicionSecciones();

        $ids = [];
        foreach ($secciones as $orden => $datos) {
            $seccion = Seccion::updateOrCreate(
                ['nombre' => $datos['nombre']],
                array_merge($datos, ['orden' => $orden + 1, 'activo' => true])
            );
            $ids[$datos['nombre']] = $seccion->id;
        }

        // [sección, código, descripción, USD, MXN, descripción doc]
        $catalogo = [
            ['Honorarios', 'N101', 'HONORARIOS DE EXPORTACIÓN (A1)', 50, 0, 'Por factura'],
            ['Honorarios', 'N101', 'HONORARIOS DE EXPORTACIÓN (H1)', 5, 0, 'Por factura'],
            ['Servicio de operación', 'N110', 'CONECTIVIDAD / VALIDACIÓN Y CONECTIVIDAD DE PEDIMENTO', 5, 0, 'Por pedimento'],
            ['Servicio de operación', 'N108', 'COVE', 5, 0, 'Por pedimento'],
            ['Servicio de operación', 'N114', 'CONTRAPRESTACION', 0, 53.45, 'Por pedimento'],
            ['Servicio de operación', 'N209', 'VERIFICACION CFDI', 0, 50, 'Por pedimento'],
            ['Impuestos', '0000', 'IMPUESTOS Y DERECHOS', 0, 383, 'Por pedimento'],
            ['Intactics LLC - Gastos americanos pagados a cuenta de cliente', 'WS412', 'FORWARDING SERVICES', 1493.03, 0, 'Por factura'],
        ];

        foreach ($catalogo as $c) {
            ConceptoCatalogo::updateOrCreate(
                ['seccion_id' => $ids[$c[0]], 'codigo' => $c[1], 'descripcion' => $c[2]],
                ['precio_usd' => $c[3], 'precio_mxn' => $c[4], 'descripcion_doc' => $c[5], 'activo' => true]
            );
        }

        $terminos = [
            'UN CONJUNTO DE SERVICIOS ESTÁN DISPONIBLES EN CASO DE REQUERIRSE.',
            'EN CASO DE ROJO EN ADUANA AMERICANA, EL PROVEEDOR QUE OTORGA EL SERVICIO LE FACTURARA DIRECTAMENTE AL IMPORTADOR AMERICANO',
            'CUALQUIER OTRO GASTO NO MENCIONADO SE SOLICITARÁ BAJO PREVIA AUTORIZACIÓN.',
            'COTIZACIÓN SUJETA A CAMBIOS POR INCREMENTOS SALARIALES, O CUALQUIER OTRO FACTOR DETERMINANTE EN LA ECONOMÍA DEL PAÍS, QUE AFECTE DIRECTAMENTE LOS COSTOS DE OPERACIÓN. LO CUAL SERA NOTIFICADO.',
            'LA PRESENTE TARIFA NO APLICA CRÉDITO, EL PAGO DE IMPUESTOS A TERCEROS, FLETES Y CUALQUIER COSTO DERIVADO DE LA OPERACIÓN DE MERCANCÍAS, DEBERÁN SER LIQUIDADA POR ANTICIPADO; EN CASO CONTRARIO SE CARGARÁ EL **% MENSUAL DE FINANCIAMIENTO.',
            'LA FACTURA SE DEBERÁ REALIZAR A NOMBRE EL IMPORTADOR O EXPORTADOR QUE APARECE EN EL PEDIMENTO.',
            'EN CASO DE CORTE DE CHEQUE, HABRÁ UN CARGO DE 25.00 DLLS.',
            'EL CLIENTE ASUME RIESGOS POR DAÑO, PERDIDA, ROBO, O CUALQUIER OTRA AFECTACIÓN DE LA MERCANCÍA, INTACTICS NO SE HACE RESPONSABLE.',
            'EL HORARIO DE ALMACEN EN NOGALES AZ ES DE LUNES A VIERNES DE 8:00 A 21:00 HRS (HORA LIMITE PARA CHECK IN 20:00 HRS) Y SABADO DE 9:00 A 16:00 HR (HORA LIMITE PARA CHECK IN 15:00 HRS), EN CASO DE REQUERIR SERVICIO FUERA DE ESOS HORARIOS SE ESTARA COBRANDO OVERTIME POR HORA.',
            'SE REQUIERE PAGO ANTICIPADO Y SE ESTARA COTIZANDO POR EVENTO',
            'ESTA COTIZACIÓN ESTARÁ VIGENTE DURANTE EL ' . date('Y') . ', REQUIRIENDO FIRMA PARA INICIO DE OPERACIONES.',
        ];

        foreach ($terminos as $orden => $texto) {
            TerminoCatalogo::updateOrCreate(['orden' => $orden + 1], ['texto' => $texto, 'activo' => true]);
        }
    }

    /**
     * Sucursales de la tabla "sucursales" para el select del generador:
     * [ ['clave' => 'LAREDO', 'nombre' => 'Laredo', 'tipo' => 'Frontera', 'con_codigos' => true], ... ]
     */
    public function sucursales()
    {
        $permitidas = (array) config('cotizador.sucursales_cotizador');
        $prefijos   = (array) config('cotizador.prefijos_sucursal');

        // Solo las 7 sucursales del cotizador, en su orden. Si la tabla "sucursales"
        // trae la misma sucursal (ej. "NOGALES" o "Nuevo Laredo"), se usa su nombre.
        if (!empty($permitidas)) {
            $deTabla = [];
            foreach ($this->sucursalesDeTabla() as $s) {
                $deTabla[$s['clave']] = $s;
            }

            $lista = [];
            foreach ($permitidas as $clave => $datos) {
                $lista[] = [
                    'clave'       => $clave,
                    'nombre'      => isset($deTabla[$clave]) ? $deTabla[$clave]['nombre'] : $datos['nombre'],
                    'tipo'        => isset($datos['tipo']) ? $datos['tipo'] : (isset($deTabla[$clave]) ? $deTabla[$clave]['tipo'] : null),
                    'con_codigos' => !empty($prefijos[$clave]),
                    'prefijos'    => isset($prefijos[$clave]) ? array_values((array) $prefijos[$clave]) : [],
                ];
            }

            return $lista;
        }

        return $this->sucursalesDeTabla();
    }

    /** Todas las sucursales de la tabla "sucursales" (o de la configuración si no existe). */
    protected function sucursalesDeTabla()
    {
        $cfg   = (array) config('cotizador.sucursales_tabla');
        $tabla = isset($cfg['tabla']) ? $cfg['tabla'] : 'sucursales';

        if (!Schema::hasTable($tabla)) {
            return $this->sucursalesDeConfiguracion();
        }

        $columnas = Schema::getColumnListing($tabla);
        $nombre   = $this->primeraColumna($columnas, [isset($cfg['nombre']) ? $cfg['nombre'] : null, 'nombre', 'sucursal', 'nombre_sucursal', 'descripcion', 'name']);
        $tipo     = $this->primeraColumna($columnas, [isset($cfg['tipo']) ? $cfg['tipo'] : null, 'tipo', 'tipo_sucursal', 'categoria', 'modalidad', 'tipo_operacion']);

        if (!$nombre) {
            return $this->sucursalesDeConfiguracion();
        }

        $q = DB::table($tabla)->select($nombre . ' as nombre');
        if ($tipo) {
            $q->addSelect($tipo . ' as tipo');
        }
        if (in_array('deleted_at', $columnas, true)) {
            $q->whereNull('deleted_at');
        }
        $q->whereNotNull($nombre)->where($nombre, '<>', '');
        $q->orderBy(in_array('id', $columnas, true) ? 'id' : $nombre);

        $prefijos = (array) config('cotizador.prefijos_sucursal');
        $vistas   = [];
        $lista    = [];

        foreach ($q->get() as $fila) {
            $nombreSucursal = trim($fila->nombre);
            $clave          = $this->claveSucursal($nombreSucursal);
            if (isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;

            $lista[] = [
                'clave'       => $clave,
                'nombre'      => $nombreSucursal,
                'tipo'        => $tipo ? $this->tipoLegible(isset($fila->tipo) ? $fila->tipo : null) : null,
                'con_codigos' => !empty($prefijos[$clave]),
                'prefijos'    => isset($prefijos[$clave]) ? array_values((array) $prefijos[$clave]) : [],
            ];
        }

        return $lista ?: $this->sucursalesDeConfiguracion();
    }

    /**
     * "Nuevo Laredo" / "laredo" → "LAREDO": la llave de prefijos_sucursal que corresponde.
     */
    public function claveSucursal($nombre)
    {
        $normal = $this->normalizar($nombre);

        foreach (array_keys((array) config('cotizador.prefijos_sucursal')) as $clave) {
            if ($normal === $clave) {
                return $clave;
            }
        }
        foreach (array_keys((array) config('cotizador.prefijos_sucursal')) as $clave) {
            if (strpos($normal, $clave) !== false || strpos($clave, $normal) !== false) {
                return $clave;
            }
        }

        return $normal;
    }

    protected function sucursalesDeConfiguracion()
    {
        $lista = [];
        foreach ((array) config('cotizador.prefijos_sucursal') as $clave => $prefijos) {
            $lista[] = [
                'clave'       => $clave,
                'nombre'      => mb_convert_case(mb_strtolower($clave, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
                'tipo'        => null,
                'con_codigos' => !empty($prefijos),
                'prefijos'    => array_values((array) $prefijos),
            ];
        }

        return $lista;
    }

    protected function primeraColumna(array $columnas, array $candidatas)
    {
        $porMinuscula = [];
        foreach ($columnas as $c) {
            $porMinuscula[strtolower($c)] = $c;
        }
        foreach ($candidatas as $c) {
            if ($c && isset($porMinuscula[strtolower($c)])) {
                return $porMinuscula[strtolower($c)];
            }
        }

        return null;
    }

    protected function tipoLegible($valor)
    {
        $v = $this->normalizar($valor);
        if ($v === '') {
            return null;
        }
        if (strpos($v, 'MAR') !== false) {
            return 'Marítimo';
        }
        if (strpos($v, 'FRONT') !== false || strpos($v, 'TERR') !== false) {
            return 'Frontera';
        }

        return mb_convert_case(mb_strtolower((string) $valor, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    protected function normalizar($texto)
    {
        $texto = mb_strtoupper(trim((string) $texto), 'UTF-8');

        return strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    /**
     * Términos por defecto de app/Services/catalogos/terminos.php.
     * Sin tipo: ['IMPORTACION' => [...], 'EXPORTACION' => [...]]. Con tipo: solo esa lista.
     * Si falta el archivo se usan los de la tabla terminos_catalogo.
     */
    public function terminos($tipo = null)
    {
        $archivo = __DIR__ . '/catalogos/terminos.php';

        if (is_file($archivo)) {
            $listas = (array) require $archivo;
        } else {
            $deTabla = TerminoCatalogo::activos()->pluck('texto')->all();
            $listas  = ['IMPORTACION' => $deTabla, 'EXPORTACION' => $deTabla];
        }

        if ($tipo === null) {
            return $listas;
        }

        return isset($listas[$tipo]) ? array_values($listas[$tipo]) : [];
    }
}
