<?php

/*
 | Conceptos de cada servicio, tal como vienen en el generador original (VentasView).
 | [bloque, código, descripción, precio USD, descripción doc]
 |
 | La sucursal sale del prefijo del código (N → Nogales, T → Tijuana, M → Mexicali,
 | NL/L → Nuevo Laredo, Z/ZLO → Manzanillo). WS, C, MU y 0000 no tienen sucursal
 | y aparecen en todas.
 */

return [
    // Honorarios
    ['Honorarios', 'N101', 'HONORARIOS DE EXPORTACIÓN (A1)', 50, 'Por factura'],
    ['Honorarios', 'N101', 'HONORARIOS DE EXPORTACIÓN (H1)', 20, 'Por factura'],
    ['Honorarios', 'N102', 'HONORARIOS DE IMPORTACIÓN (A1)', 60, 'Por factura'],

    // Servicio de operación
    ['Servicio de operación', 'N110', 'VALIDACIÓN Y CONECTIVIDAD DE PEDIMENTO', 6, 'Por pedimento'],
    ['Servicio de operación', 'N108', 'COVE', 6, 'Por factura'],
    ['Servicio de operación', 'N105', 'RECONOCIMIENTO ADUANERO (ROJO ALEATORIO)', 65, 'Por carga'],
    ['Servicio de operación', 'N114', 'CONTRAPRESTACION', 0, 'Por pedimento'],
    ['Servicio de operación', 'N120', 'VALIDACIÓN CAAAREM', 0, 'Por factura'],
    ['Servicio de operación', 'N209', 'VERIFICACION CFDI', 0, 'Por factura'],

    // Transporte
    ['Transporte', 'T101', 'FLETE INTERNACIONAL', 400, 'Por viaje'],
    ['Transporte', 'T102', 'FLETE NACIONAL', 300, 'Por viaje'],

    // Impuestos
    ['Impuestos', '0000', 'IMPUESTOS Y DERECHOS ADUANALES', 0, 'Por pedimento'],

    // Intactics LLC - Gastos americanos pagados a cuenta de cliente
    ['Intactics LLC - Gastos americanos pagados a cuenta de cliente', 'WS412', 'ENTRY (MANIFIESTO AMERICANO)', 50, 'Por factura'],
    ['Intactics LLC - Gastos americanos pagados a cuenta de cliente', 'WS413', 'OVERWEIGHT (PERMISO DE SOBREPESO)', 85, 'Por carga'],
    ['Intactics LLC - Gastos americanos pagados a cuenta de cliente', 'WS500', 'INSPECCIÓN EN ADUANA AMERICANA (ALEATORIO)', 0, 'Por partida'],
    ['Intactics LLC - Gastos americanos pagados a cuenta de cliente', 'WS501', 'IN & OUT DE MERCANCÍA REFRIGERADA', 160, 'Por carga'],
    ['Intactics LLC - Gastos americanos pagados a cuenta de cliente', 'WS502', 'ALMACENAJE EN REFRIGERADO', 180, 'Por carga / Por noche'],
    ['Intactics LLC - Gastos americanos pagados a cuenta de cliente', 'WS503', 'TRANSBORDO EN REFRIGERADO', 130, 'Por carga'],

    // Emisión de certificado internacional
    ['Emisión de certificado internacional', 'C101', 'EMISION DE CERTIFICADO INTERNACIONAL', 100, 'Por certificado'],

    // Maniobras por cuenta de cliente
    ['Maniobras por cuenta de cliente', 'M101', 'MANIOBRAS DE CARGA/DESCARGA', 120, 'Por maniobra'],

    // Muestra
    ['Muestra', 'MU101', 'RECOLECCIÓN DE MUESTRAS', 50, 'Por muestra'],
];