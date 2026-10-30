<?php

/*
 * Configuración para timbrar / descargar facturas de Pagos de Derecho (SENASICA).
 * Todo se lee del .env para no dejar datos fiscales ni contraseñas en el código.
 */

return [

    // Portal de autofactura
    'url'       => env('SENASICA_URL', 'https://facturasenasica.com/dashboard/autofactura/'),
    'url_login' => env('SENASICA_URL_LOGIN', 'https://facturasenasica.com/in/'),

    // Cuenta del portal (opcional). Si existe, se inicia sesión y el portal
    // llena solo los datos del receptor; los de abajo solo se usan si el campo queda vacío.
    'cuenta' => [
        'email'    => env('SENASICA_EMAIL'),
        'password' => env('SENASICA_PASSWORD'),
    ],

    // El RECEPTOR de la factura es el CLIENTE de cada operación (tabla de Empresas).
    // De aquí solo se toman los valores de uso cuando el cliente no los tiene.
    'receptor' => [
        'uso_cfdi'    => env('SENASICA_RECEPTOR_USO_CFDI', 'G03'),
        'correo'      => env('SENASICA_RECEPTOR_CORREO'),   // a dónde manda el portal la factura
        'metodo_pago' => env('SENASICA_METODO_PAGO', '03'),
    ],

    // Columnas de la tabla de clientes (Empresas). Déjalas en null para que se
    // detecten solas; si en tu tabla se llaman distinto, escribe aquí el nombre real.
    'columnas_cliente' => [
        'nombre'        => env('SENASICA_COL_NOMBRE'),        // ej. 'razon_social'
        'rfc'           => env('SENASICA_COL_RFC'),           // ej. 'rfc'
        'regimen'       => env('SENASICA_COL_REGIMEN'),       // ej. 'regimen_fiscal'
        'codigo_postal' => env('SENASICA_COL_CP'),            // ej. 'codigo_postal'
        'uso_cfdi'      => env('SENASICA_COL_USO_CFDI'),
        'correo'        => env('SENASICA_COL_CORREO'),
    ],

    // Navegador (Playwright / Node)
    'node'     => env('SENASICA_NODE', 'node'),
    'script'   => base_path('scripts/senasica/autofactura.mjs'),
    'headless' => env('SENASICA_HEADLESS', true),
    'canal'    => env('SENASICA_CANAL'), // 'chrome' para usar el Google Chrome instalado en vez de Chromium
    'timeout'  => (int) env('SENASICA_TIMEOUT', 240), // segundos por pago

    // Dónde se guardan las facturas descargadas (disco "local" = storage/app)
    'carpeta' => 'senasica/facturas',

    // El portal pide que el pago tenga al menos 48 h hábiles
    'dias_habiles_minimos' => 2,

    // Pausa entre pagos para no saturar el portal
    'pausa_segundos' => 3,
];
