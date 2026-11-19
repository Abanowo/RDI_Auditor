<?php

/*
|--------------------------------------------------------------------------
| Recibo Electrónico de Pago (REP) - SyncCOM
|--------------------------------------------------------------------------
|
| Configuración que usa App\Services\GeneradorTxtRep.
| Guardarla aquí evita perderla cuando se reemplaza el archivo del servicio.
|
*/

return [

    /*
    | Endpoint con los datos fiscales y de CONTPAQi del cliente.
    | %d se reemplaza por el id de la empresa (ingresos_conciliados.cliente_id).
    */
    'url_datos_empresa' => env('REP_URL_DATOS_EMPRESA', 'https://sistema.intactics.com/v3/empresas/%d/rep-compaq-momentaneo'),

    /*
    | Concepto y serie del REP en CONTPAQi ([encDOCUMENTO] y [encSERIE]).
    | Mismo formato que SaldarDOCUMENTO del endpoint (con ".0").
    */
    'concepto_pago' => env('REP_CONCEPTO_PAGO', '100014.0'),
    'serie_pago'    => env('REP_SERIE_PAGO', 'CP'),

    /*
    | Documentos a saldar: concepto + serie + folio (guía técnica de SyncCOM, nov. 2026).
    | El SDK de CONTPAQi obtiene el UUID por su cuenta; aquí solo se lee del XML para validar.
    */
    'incluir_uuid'    => env('REP_INCLUIR_UUID', false),

    'incluir_folio'   => env('REP_INCLUIR_FOLIO', true),
    'descriptor_uuid' => env('REP_DESCRIPTOR_UUID', 'saldarUUID'),

    /*
    | Cuentas de Intactics donde se reciben los pagos (cuenta BENEFICIARIA del REP):
    | [encBANCOCLAVESAT], [encBANCOCUENTA], [encBANCOALIAS] y [encBANCOCLABE].
    | La llave es el texto exacto del banco receptor del ingreso.
    | Captura el número de cuenta completo (10 dígitos) y, si la tienes, la CLABE (18 dígitos).
    | Mientras la cuenta esté vacía, esas líneas se omiten y el comando muestra una advertencia.
    | (La cuenta del CLIENTE, ordenante, se toma del endpoint y va en los campos cte*.)
    */
    'cuentas_beneficiarias' => [

        // ---------------- InTactics SC (INT081028GF0) - Pesos ----------------
        'BBVA REC NOG 2749'      => ['clave_sat' => '012', 'cuenta' => '0167692749', 'clabe' => '012778001676927496'], // Nogales
        'BBVA REC LRD 1199'      => ['clave_sat' => '012', 'cuenta' => '0105831199', 'clabe' => '012778001058311992'], // Nuevo Laredo
        'BBVA REC TIJ 1465'      => ['clave_sat' => '012', 'cuenta' => '0196951465', 'clabe' => '012778001969514655'], // Tijuana
        'BBVA REC MXL 2461'      => ['clave_sat' => '012', 'cuenta' => '0113642461', 'clabe' => '012778001136424617'], // Mexicali
        // Santander: cuenta de 11 dígitos (65-50653130-6); se usan los primeros 10, igual que en el REP timbrado (6550996937)
        'Santander Rec Nog 1306' => ['clave_sat' => '014', 'cuenta' => '6550653130', 'clabe' => '014778655065313065'], // Nogales (la CLABE del documento traía 17 dígitos; se completó con el 0 inicial)
        'Santander Rec Zlo'      => ['clave_sat' => '014', 'cuenta' => '6550996937', 'clabe' => '014778655099693780'], // Manzanillo 65-50996937-8

        // Sin datos en los documentos recibidos (capturar cuando se tengan)
        'BBVA REC ZLO 9378'      => ['clave_sat' => '012', 'cuenta' => '', 'clabe' => ''],
        'BBVA REC VER 6464'      => ['clave_sat' => '012', 'cuenta' => '', 'clabe' => ''],
        'BBVA REC REY 3802'      => ['clave_sat' => '012', 'cuenta' => '', 'clabe' => ''],
        'Santander Rec Ver'      => ['clave_sat' => '014', 'cuenta' => '', 'clabe' => ''],

        // ---------------- InTactics SC - Dólares ----------------
        'Santander DLLS Nog 2628' => ['clave_sat' => '014', 'cuenta' => '8250084262', 'clabe' => '014778825008426286'], // Nogales 82500842628
        'Santander Dlls ZLO 6205' => ['clave_sat' => '014', 'cuenta' => '8250113620', 'clabe' => '014778825011362052'], // Manzanillo 82-50113620-5

        // ---------------- Intshipperts (INT2512223A1) ----------------
        'BBVA Rec Intshipperts 1586' => ['clave_sat' => '012', 'cuenta' => '0126671586', 'clabe' => '012778001266715869'],
        // Otras cuentas BBVA de Intshipperts en los estados de cuenta (no están en el catálogo de bancos receptores):
        //   0127029152 / CLABE 012778001270291528
        //   0126672108 / CLABE 012778001266721086

        // ---------------- Transportactics (TRA180712V36) ----------------
        'BBVA Rec Transpo 1903'       => ['clave_sat' => '012', 'cuenta' => '0113100790', 'clabe' => '012778001131007903'], // VERIFICAR: la terminación 1903 no coincide con la cuenta 0113100790
        'Santander Rec Transpo 1347'  => ['clave_sat' => '014', 'cuenta' => '6550711134', 'clabe' => ''], // 65-50711134-7 (Cuenta tradicional)
        'Santander DLLS Transpo 5154' => ['clave_sat' => '014', 'cuenta' => '8250089515', 'clabe' => ''], // 82-50089515-4 (Cuenta dólares morales)
    ],

    /*
    | Respaldo del código postal de expedición por sucursal, solo si el endpoint
    | no trae encLUGAREXPED. Normalmente no hace falta llenarlo.
    */
    'lugar_expedicion' => [
        'NOGALES'    => env('REP_CP_NOGALES', ''),
        'LAREDO'     => env('REP_CP_LAREDO', ''),
        'TIJUANA'    => env('REP_CP_TIJUANA', ''),
        'MEXICALI'   => env('REP_CP_MEXICALI', ''),
        'MANZANILLO' => env('REP_CP_MANZANILLO', ''),
    ],

];
