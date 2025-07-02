<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Parámetros del Autómata de Reportes
    |--------------------------------------------------------------------------
    |
    | Aquí centralizamos todas las rutas y configuraciones de nuestro sistema.
    | Usamos la función env() para leer desde el archivo .env, con un valor
    | por defecto por si no se encuentra.
    |
    */

    'banco_predeterminado' => env('REPORTE_BANCO', 'BBVA'),
    'periodo_meses_busqueda' => env('REPORTE_PERIODO_MESES', 3), // Busca en los últimos 2 meses por defecto
    'rutas' => [
        // En config/reportes.php
        // Rutas que dependen de la configuración del entorno en .env
        'estados_de_cuenta' => env('RUTA_BASE_ESTADOS_DE_CUENTA', 'T:\\CUENTAS PECE'),
        'escritorio'        => env('RUTA_BASE_ESCRITORIO', 'C:\\Users\\Daniel.Gomez\\Desktop'),

        'sc_txt_filepath' => '\\\\192.168.1.245\\TXTs\\InTactics-S.C.-2017\\FACTURAS\\ArchivosProcesados',
        'sc_pdf_filepath' => '\\\\192.168.1.245\\TXTs\\InTacticsSC2017\\PDFXML-Factura',

        'tr_txt_filepath' => '\\\\192.168.1.245\\TXTs\\Tranportactics\\FACTURAS\\ArchivosProcesados',
        'tr_pdf_filepath' => '\\\\192.168.1.245\\TXTs\\Tranportactics\\pdf-temporales', //Para los .xml es la misma

        'llc_txt_filepath' => '\\\\192.168.1.246\\txts\\InTactics-LLC\\Facturas-TXT\\ArchivosProcesados',
        'llc_pdf_filepath' => '\\\\192.168.1.245\\TXTs\\InTacticsLLC\\PDFLLC',

        'pagos_de_derecho' => '\\\\192.168.1.252\\General\\PAGOS DE DERECHO DANIEL',
        // Rutas que podemos construir a partir de las anteriores.
        // Fíjate cómo usamos la función config() para leer otro valor de la configuración. '\\2633 NOGALES\\NOG del 2 al 19 de Junio.pdf', '\\2633 NOGALES\\Nog del 1 al 31 de Mayo.pdf',
        'bbva_edc' => env('RUTA_BASE_ESTADOS_DE_CUENTA') . '\\2633 NOGALES\\NOG del 2 al 30 de Junio.pdf',
        'santander_edc' => env('RUTA_BASE_ESTADOS_DE_CUENTA') . '\\1232 NOGALES\\NOG del 2 al 10 de Junio.pdf',
        // En config/reportes.php
        'pdftotext_path' => 'C:\Users\Daniel.Gomez\Downloads\xpdf-tools-win-4.05\xpdf-tools-win-4.05\bin32\pdftotext.exe',
        // ... Aquí añadiremos las demás rutas (Parametros.xlsx, etc.) a medida que las necesitemos.
    ],
];
