<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tipo de cambio e IVA
    |--------------------------------------------------------------------------
    | El tipo de cambio es el valor por defecto de cada cotización nueva;
    | después se puede editar en el encabezado de la cotización.
    */
    'tipo_cambio' => env('COTIZADOR_TIPO_CAMBIO', 18.16),

    'iva' => 0.16,

    /*
    | Opciones de la columna "Descripción doc" (también se imprimen en el PDF).
    */
    'descripciones_doc' => [
        'Por factura',
        'Por pedimento',
        'Por evento',
        'Por embarque',
        'Por contenedor',
        'Por hora',
        'Por día',
        'Por carga',
        'Por carga / Por noche',
        'Por partida',
        'Por viaje',
        'Por maniobra',
        'Por muestra',
        'Por certificado',
    ],

    /*
    | Mostrar subtotal / IVA / total al final de la hoja 1 del PDF.
    | En el formato actual no se muestran, solo tarifas.
    */
    'mostrar_totales_pdf' => false,

    /*
    |--------------------------------------------------------------------------
    | Sucursales (tabla "sucursales" de tu base de datos)
    |--------------------------------------------------------------------------
    | Llenan el select "Sucursal / puerto" del generador. Si una columna no existe
    | se busca entre nombres comunes (nombre, sucursal, descripcion / tipo, categoria).
    */
    /*
    | Sucursales que aparecen en el selector "Puerto de entrada/salida" del generador,
    | en este orden. El nombre se toma de la tabla "sucursales" si existe ahí.
    */
    'sucursales_cotizador' => [
        'NOGALES'    => ['nombre' => 'Nogales',    'tipo' => 'Frontera'],
        'TIJUANA'    => ['nombre' => 'Tijuana',    'tipo' => 'Frontera'],
        'LAREDO'     => ['nombre' => 'Laredo',     'tipo' => 'Frontera'],
        'MEXICALI'   => ['nombre' => 'Mexicali',   'tipo' => 'Frontera'],
        'REYNOSA'    => ['nombre' => 'Reynosa',    'tipo' => 'Frontera'],
        'MANZANILLO' => ['nombre' => 'Manzanillo', 'tipo' => 'Marítimo'],
        'VERACRUZ'   => ['nombre' => 'Veracruz',   'tipo' => 'Marítimo'],
    ],

    'sucursales_tabla' => [
        'tabla'  => 'sucursales',
        'nombre' => 'nombre',   // Nogales, Tijuana, Laredo…
        'tipo'   => 'tipo',     // Frontera / Marítimo (opcional)
    ],

    /*
    | Prefijo de los Segmentos Contables de cada sucursal (N101 → Nogales, ZLO306 → Manzanillo).
    | La llave es el nombre de la sucursal en mayúsculas y sin acentos.
    | Una sucursal sin prefijos (Reynosa, Veracruz) solo muestra los códigos generales.
    */
    /*
    | Bloques cuyos códigos no están amarrados a una sucursal (0000, WS412, C101…):
    | su combo "-- Cargar --" muestra todos sus conceptos sin importar la sucursal elegida.
    */
    'bloques_sin_sucursal' => [
        'Impuestos',
        'Intactics LLC - Gastos americanos pagados a cuenta de cliente',
        'Emisión de certificado internacional',
    ],

    'prefijos_sucursal' => [
        'NOGALES'    => ['N', 'NOG', 'NOG1'],
        'LAREDO'     => ['NL', 'L'],   // Nuevo Laredo (L100 cruce fronterizo, L400 servicios logísticos)
        'TIJUANA'    => ['T'],
        'MEXICALI'   => ['M'],
        'REYNOSA'    => [],
        'MANZANILLO' => ['Z', 'ZLO'],
        'VERACRUZ'   => [],
    ],

    'empresa' => [
        'nombre'       => 'INTACTICS S.C.',
        'razon_social' => 'LOGÍSTICA INTERNACIONAL INTACTICS S.C.',
        'direccion'    => [
            'CALZADA INDUSTRIAL NUEVO NOGALES NO.190 LOCAL B2,',
            'COLONIA PARQUE INDUSTRIAL NUEVO NOGALES,',
            'NOGALES, SONORA CP. 84094. TEL. (631) 314 0101',
        ],
        'email'        => 'COMERCIALIZACION@INTACTICS.COM',
        // Ruta relativa a /public. Si el archivo no existe se dibuja el logo con CSS.
        // Ruta relativa a /public (C:\xampp\htdocs\RDI_Auditor\public\images\INTACTICS ROJO.png)
        'logo'         => 'images/INTACTICS ROJO.png',
    ],

    /*
    | Firma del coordinador que envía la cotización por correo (usuario en sesión).
    | Se busca en este orden:
    |   1. columna de la tabla de usuarios con la ruta o URL de la imagen (null si no existe)
    |   2. public/{carpeta}/{id_usuario}.png (o .jpg / .jpeg)
    | Si no se encuentra, el correo lleva el nombre del coordinador en texto.
    */
    /*
    | Mensaje por defecto del correo de la cotización. Aparece ya escrito en el modal
    | "Enviar cotización" y se puede editar antes de enviar.
    | {operacion} → exportación / importación    {puerto} → sucursal elegida
    | Deja una línea en blanco entre párrafos.
    */
    'mensaje_correo' => "Muy buenas tardes,\n\n"
        . "En base a su solicitud de servicios; la presente es para hacerles llegar las propuestas de servicios "
        . "a manejar para las operaciones de {operacion} de sus mercancías a manejar por {puerto} con nosotros.\n\n"
        . "Quedamos a la espera de su confirmación y autorización firmada de la misma para iniciar con el "
        . "proceso de transición con nosotros.\n\n"
        . "Cualquier duda o comentario, con gusto quedamos a sus órdenes,\n\n"
        . "Saludos cordiales.",

    'firma_correo' => [
        'columna' => 'signature_image',   // columna del modelo App\Models\User (tabla usuario)
        'carpeta' => 'images/firmas',
    ],

    'firma_puesto' => 'SUPERVISOR DE DESARROLLO DE NEGOCIOS & SERVICIO AL CLIENTE',
    /*
    |--------------------------------------------------------------------------
    | Vista de prueba de clientes  ClientesDemo.vue
    |--------------------------------------------------------------------------
    | Mapea aquí los nombres reales de las columnas de tu tabla "empresas".
    | Pon null en cualquier columna que no exista y esa celda mostrará "—".
    */
    'clientes' => [
        'tabla'       => 'empresas',
        'llave'       => 'id',
        'soft_delete' => false,          // true si "empresas" tiene deleted_at
        'por_pagina'  => 25,
        'logo_url'    => 'storage/',     // prefijo para logos guardados como ruta relativa

        'columnas' => [
            'nombre'      => 'nombre',
            'logo'        => 'logo',
            'sucursal'    => 'sucursal',
            'sector'      => 'sector',
            'estatus'     => 'estatus',      // filtro "Seguimiento": Urgente, Mediano...
            'contacto'    => 'contacto',     // pasa a "Representante / contacto" de la cotización
            'progreso'    => null,           // columna 0-100; null = se calcula con las etapas
            // Solo si vendedores.origen = 'empresa' (tu tabla "empresas" no tiene vendedor):
            'vendedor'    => null,           // columna con el nombre escrito en "empresas"
            'vendedor_id' => null,           // columna con el id del vendedor
            'fecha'       => 'created_at',   // filtro Desde / Hasta
        ],

        /*
        | Vendedor que se muestra en la tabla.
        | origen 'cotizacion': el usuario que hizo la última cotización de la empresa
        |                      (cotizaciones.user_id → tabla "usuario").
        | origen 'empresa':    columnas vendedor / vendedor_id de "empresas".
        | En cotizaciones nuevas, "Vendedor / atentamente" se llena con el usuario en sesión.
        */
        'vendedores' => [
            'origen'    => 'cotizacion',
            'tabla'     => 'usuario',
            'llave'     => 'id_usuario',
            'nombre'    => 'nombre',
            'apellidos' => 'apellidos',
            'avatar'    => null,             // tu tabla no tiene foto: se muestran las iniciales
        ],

        /*
        | Íconos de "Progreso" (Font Awesome 5), en el orden en que se muestran.
        | tipo "booleano": 1/true = verde, 0 = rojo.
        | tipo "tres":     0 = rojo, 1 = amarillo (en proceso), 2 = verde.
        | La etapa con clave "cotizacion" y columna null se calcula sola:
        | sin cotización = rojo, con cotización = amarillo, aprobada por cliente = verde.
        */
        'etapas' => [
            ['clave' => 'contacto',    'titulo' => 'Contacto',            'icono' => 'fas fa-user-tie',            'columna' => 'etapa_contacto',    'tipo' => 'booleano'],
            ['clave' => 'documentos',  'titulo' => 'Documentación',       'icono' => 'fas fa-shield-alt',          'columna' => 'etapa_documentos',  'tipo' => 'booleano'],
            ['clave' => 'cotizacion',  'titulo' => 'Cotización',          'icono' => 'fas fa-stamp',               'columna' => null],
            ['clave' => 'expediente',  'titulo' => 'Expediente',          'icono' => 'fas fa-id-card-alt',         'columna' => 'etapa_expediente',  'tipo' => 'booleano'],
            ['clave' => 'operacion',   'titulo' => 'Primera operación',   'icono' => 'fas fa-people-carry',        'columna' => 'etapa_operacion',   'tipo' => 'booleano'],
            ['clave' => 'visita',      'titulo' => 'Visita / evidencia',  'icono' => 'fas fa-camera',              'columna' => 'etapa_visita',      'tipo' => 'booleano'],
        ],

        // Color de la pastilla de Estatus (en minúsculas)
        'estatus_colores' => [
            'urgente' => 'rojo',
            'alto'    => 'rojo',
            'mediano' => 'ambar',
            'medio'   => 'ambar',
            'bajo'    => 'verde',
        ],

        /*
        | Botones de la tabla. Rutas de tu Vue Router o URLs de tu API, con {id}.
        | null = se oculta el botón.
        |   crear / editar / seguimiento → se abren con this.$router.push()
        |   eliminar                     → se llama con DELETE por axios
        */
        'rutas' => [
            'crear'       => null,   // ej. '/empresas/nueva'
            'editar'      => null,   // ej. '/empresas/{id}/editar'
            'seguimiento' => null,   // ej. '/empresas/{id}/seguimiento'
            'eliminar'    => null,   // ej. '/empresas/{id}'
        ],
    ],
];