@php
$c = $cotizacion;
// Font Awesome 6 Free (licencia SIL OFL): dompdf necesita la ruta del archivo; el navegador, la URL
// En Windows una ruta como "C:/xampp/…" dompdf la confunde con un protocolo ("C:") y no carga
// la fuente (los íconos salen como "?"). Con file:/// la toma como archivo local en cualquier sistema.
$fa = function ($archivo) use ($modo) {
if ($modo !== 'pdf') {
return asset('fonts/fontawesome/' . $archivo);
}
$ruta = str_replace('\\', '/', public_path('fonts/fontawesome/' . $archivo));

return 'file://' . (substr($ruta, 0, 1) === '/' ? '' : '/') . $ruta;
};
$faSolid = $fa('fa-solid-900.ttf');
$faBrands = $fa('fa-brands-400.ttf');
@endphp
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Cotización {{ $c->folio ?: 'borrador' }} - {{ $c->cliente }}</title>
    <style>
        /*
     | Un solo flujo: los conceptos continúan en las hojas que haga falta y los términos
     | van justo después, en la misma hoja si caben o en la siguiente si no.
     | El encabezado (franja, logo y datos) se repite en cada hoja (position: fixed en dompdf).
     */
        @page {
            margin: 150pt 46pt 92pt 46pt;
        }

        body {
            margin: 0;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 7.6pt;
            color: #1f2937;
        }

        .pagina-enc {
            position: fixed;
            top: -150pt;
            left: -46pt;
            right: -46pt;
            height: 150pt;
            padding: 46pt 46pt 0;
        }

        /* Formas del encabezado: imagen a todo lo ancho de la hoja */
        .enc-fondo {
            position: absolute;
            top: 0;
            left: 0;
            width: 612pt;
            height: 86pt;
        }

        .enc {
            position: relative;
            margin-top: 10pt !important;
        }

        /* Pie en cada hoja: formas (cuña, línea gris, banda), sucursales con ícono, globo y redes */
        .pagina-pie {
            position: fixed;
            bottom: -92pt;
            left: -46pt;
            right: -46pt;
            height: 92pt;
        }

        .pie-fondo {
            position: absolute;
            left: 0;
            bottom: 0;
            width: 612pt;
            height: 92pt;
        }

        /* Sucursales: cada renglón con su ícono, en posiciones fijas (igual en todas las versiones de dompdf) */
        .pie-fila {
            position: absolute;
            left: 258pt;
            width: 290pt;
            height: 12pt;
        }

        .pie-fila .suc-tipo {
            position: absolute;
            left: 22pt;
            top: 1.6pt;
            width: 52pt;
            font-size: 6.4pt;
            font-weight: bold;
            letter-spacing: .9pt;
        }

        .pie-fila .suc-lista {
            position: absolute;
            left: 76pt;
            top: 1pt;
            width: 200pt;
            white-space: nowrap;
            font-size: 7.6pt;
            letter-spacing: .5pt;
            color: #6b7280;
        }

        /* ---- Íconos de Font Awesome 6 (fuentes en public/fonts/fontawesome) ---- */
        @font-face {
            font-family: 'FA6 Solid';
            font-style: normal;
            font-weight: normal;
            src: url("{{ $faSolid }}") format("truetype");
        }

        @font-face {
            font-family: 'FA6 Brands';
            font-style: normal;
            font-weight: normal;
            src: url("{{ $faBrands }}") format("truetype");
        }

        .fas,
        .fa-solid {
            font-family: 'FA6 Solid';
            font-weight: normal;
            font-style: normal;
            line-height: 1;
        }

        .fab,
        .fa-brands {
            font-family: 'FA6 Brands';
            font-weight: normal;
            font-style: normal;
            line-height: 1;
        }

        .fa-truck:before {
            content: "\f0d1";
        }

        .fa-ship:before {
            content: "\f21a";
        }

        .fa-earth-americas:before,
        .fa-globe-americas:before {
            content: "\f57d";
        }

        .fa-facebook-f:before {
            content: "\f39e";
        }

        .fa-instagram:before {
            content: "\f16d";
        }

        .fa-linkedin-in:before {
            content: "\f0e1";
        }

        .fa-x-twitter:before {
            content: "\e61b";
        }

        /* Íconos del pie */
        .pie-fila .fas {
            position: absolute;
            left: 2pt;
            top: .6pt;
            font-size: 9pt;
        }

        .pie-fila .fr-ico {
            color: #d63031;
        }

        .pie-fila .ma-ico {
            color: #1d5fa5;
        }

        .pie-globo {
            position: absolute;
            right: 18pt;
            top: 19pt;
            width: 30pt;
            height: 30pt;
            background: #fff;
            border: 2pt solid #7fd3ee;
            border-radius: 17pt;
            text-align: center;
        }

        .pie-globo .fas {
            display: block;
            padding-top: 4.5pt;
            font-size: 21pt;
            color: #8e8e8e;
            line-height: 1;
        }

        .pie-globo .p1,
        .pie-globo .p2 {
            position: absolute;
            width: 4.4pt;
            height: 4.4pt;
            background: #9a9a9a;
            border-radius: 2.2pt;
        }

        .pie-globo .p1 {
            right: -4pt;
            top: -3pt;
        }

        .pie-globo .p2 {
            left: -4pt;
            top: 28pt;
        }

        .pie-red {
            display: inline-block;
            width: 18pt;
            height: 18pt;
            margin-left: 4pt;
            background: #fff;
            border-radius: 9pt;
            text-align: center;
            vertical-align: top;
        }

        .pie-red .fab {
            display: block;
            padding-top: 4pt;
            font-size: 10pt;
            line-height: 1;
            color: #ef7373;
        }

        .pie-fila b {
            font-size: 6.4pt;
            letter-spacing: .9pt;
        }

        .pie-fila .fr {
            color: #d63031;
        }

        .pie-fila .ma {
            color: #1d5fa5;
        }

        .pie-sitio {
            position: absolute;
            left: 46pt;
            top: 71pt;
            font-size: 10.4pt;
            letter-spacing: .8pt;
            color: #fff;
        }

        .pie-redes {
            position: absolute;
            right: 22pt;
            top: 68pt;
        }


        .franja {
            position: absolute;
            top: 0;
            right: 0;
            width: 186pt;
            height: 24pt;
        }

        .franja span {
            position: absolute;
            top: 0;
            height: 24pt;
        }

        .franja .f1 {
            left: 0;
            width: 98pt;
            background: #d63031;
        }

        .franja .f2 {
            left: 98pt;
            width: 66pt;
            background: #4a2c3d;
        }

        .franja .f3 {
            left: 164pt;
            width: 22pt;
            background: #d63031;
        }

        table {
            border-collapse: collapse;
        }

        .enc {
            width: 100%;
            margin-top: 18pt;
        }

        .enc td {
            vertical-align: top;
        }

        .enc-datos {
            text-align: right;
            font-size: 6.4pt;
            line-height: 1.55;
            color: #475569;
            font-weight: bold;
        }

        .enc-datos b {
            color: #111827;
            font-size: 7.4pt;
        }

        .rojo {
            color: #d63031;
        }

        .dots td {
            padding: 0;
            vertical-align: middle;
        }

        .dots table td {
            padding: 1pt;
        }

        .dot {
            width: 4.6pt;
            height: 4.6pt;
            border-radius: 2.3pt;
        }

        .dot-r {
            background: #d63031;
        }

        .dot-g {
            background: #9ca3af;
        }

        .marca {
            padding-left: 5pt !important;
            font-size: 13pt;
            font-weight: bold;
            color: #1f2937;
        }

        .marca sup {
            color: #d63031;
            font-size: 7pt;
        }

        .sep {
            border-top: 1pt solid #e5e7eb;
            margin: 12pt 0 14pt;
        }

        .info {
            width: 100%;
            border: 1pt solid #d9dee6;
            margin-bottom: 14pt;
        }

        .info td {
            border: 1pt solid #e5e9ef;
            padding: 4pt 6pt 5pt;
            vertical-align: top;
        }

        .lbl {
            display: block;
            font-size: 5.4pt;
            font-weight: bold;
            color: #94a3b8;
            text-transform: uppercase;
            margin-bottom: 2pt;
        }

        .val {
            font-size: 8.4pt;
            font-weight: bold;
            color: #111827;
            text-transform: uppercase;
        }

        .val-n {
            font-size: 8.4pt;
            color: #111827;
            text-transform: uppercase;
        }

        .pill {
            display: inline-block;
            padding: 1pt 5pt;
            border-radius: 2pt;
            font-size: 6.4pt;
            font-weight: bold;
        }

        .pill-exp {
            background: #fdf4ff;
            color: #a21caf;
            border: .6pt solid #f0abfc;
        }

        .pill-imp {
            background: #eff6ff;
            color: #1d5fa5;
            border: .6pt solid #93c5fd;
        }

        .bloque {
            width: 100%;
            border: 1pt solid #e5e9ef;
            margin-bottom: 11pt;
            page-break-inside: avoid;
        }

        .bloque th {
            background: #1d5fa5;
            color: #fff;
            font-size: 6.6pt;
            font-weight: bold;
            text-align: left;
            padding: 5pt 9pt;
            text-transform: uppercase;
        }

        .bloque th.t,
        .bloque td.t {
            text-align: right;
            width: 92pt;
        }

        .bloque th.d,
        .bloque td.d {
            text-align: center;
            width: 104pt;
        }

        .bloque td {
            padding: 4pt 9pt;
            border-top: 1pt solid #eef1f5;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .bloque td.t {
            font-family: Courier, monospace;
            font-weight: normal;
            text-transform: none;
        }

        .bloque td.d {
            font-size: 6.2pt;
            color: #1d5fa5;
        }

        /* Nota del concepto: solo en la columna de la descripción, sin invadir TARIFA */
        .bloque tr.nota td {
            border-top: 0;
            padding-top: 0;
        }

        .bloque td.nota-txt {
            padding-left: 16pt;
            font-weight: normal;
            text-transform: none;
            font-size: 6.4pt;
            line-height: 1.45;
            color: #334155;
        }

        .tot {
            width: 100%;
            margin-top: 6pt;
        }

        .tot td {
            text-align: right;
            padding: 2pt 0;
            font-size: 7.6pt;
        }

        .tot .g td {
            font-size: 9pt;
            font-weight: bold;
            color: #166534;
        }

        .tc-wrap {
            margin-top: 16pt;
            page-break-inside: avoid;
        }

        .tc-titulo {
            background: #f1f4f8;
            border-left: 3pt solid #1f2937;
            padding: 5pt 9pt;
            font-size: 8.2pt;
            font-weight: bold;
            letter-spacing: .5pt;
            margin-bottom: 8pt;
        }

        .tc p {
            margin: 0 0 3pt;
            font-size: 6.3pt;
            font-weight: bold;
            line-height: 1.35;
            text-transform: uppercase;
        }

        .firmas {
            width: 100%;
            margin-top: 28pt;
        }

        .firmas td {
            vertical-align: bottom;
            width: 50%;
        }

        .atte {
            font-weight: bold;
            font-style: italic;
            font-size: 8pt;
            margin-bottom: 20pt;
        }

        .linea {
            border-top: .8pt solid #475569;
            padding-top: 3pt;
        }

        .vend {
            font-size: 8pt;
            font-weight: bold;
            text-decoration: underline;
            text-decoration-color: #d63031;
        }

        .puesto {
            font-size: 5.8pt;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            width: 170pt;
        }

        @if($modo ==='html') html {
            background: #e5e7eb;
        }

        body {
            padding: 18px 0;
        }

        .hoja {
            position: relative;
            width: 8.5in;
            min-height: 11in;
            box-sizing: border-box;
            padding: 0 46pt 40pt;
            margin: 0 auto 22px;
            background: #fff;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .18);
        }

        /* Vista previa: una sola hoja continua con el encabezado arriba */
        .pagina-enc {
            position: static;
            height: auto;
            padding: 22pt 0 0;
        }

        .hoja {
            padding-bottom: 110pt;
        }

        .pagina-pie {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
        }

        @endif
    </style>
</head>

<body>
    @if($modo === 'html')<div class="hoja">@endif

        {{-- Encabezado: en el PDF se repite en cada hoja --}}
        <div class="pagina-enc">
            @include('cotizacion_pdf_encabezado')
        </div>

        {{-- Pie: en el PDF se repite en cada hoja --}}
        <div class="pagina-pie">
            @if(!empty($graficos['pie']))<img class="pie-fondo" src="{{ $graficos['pie'] }}" alt="">@endif
            @foreach(array_values((array) config('cotizador.empresa.sucursales_pie')) as $n => $lista)
            @php
            $tipo = array_keys((array) config('cotizador.empresa.sucursales_pie'))[$n];
            $esMaritimo = stripos($tipo, 'mar') === 0;
            @endphp
            <div class="pie-fila" style="top: {{ 23 + $n * 15 }}pt">
                @if($esMaritimo)
                <i class="fas fa-ship ma-ico"></i>
                @else
                <i class="fas fa-truck fr-ico"></i>
                @endif
                <div class="suc-tipo {{ $esMaritimo ? 'ma' : 'fr' }}">{{ mb_strtoupper($tipo, 'UTF-8') }}:</div>
                <div class="suc-lista">{{ mb_strtoupper(implode(' · ', $lista), 'UTF-8') }}</div>
            </div>
            @endforeach
            <div class="pie-globo"><i class="fas fa-earth-americas"></i><span class="p1"></span><span class="p2"></span></div>
            <span class="pie-sitio">{{ config('cotizador.empresa.sitio', 'WWW.INTACTICS.COM') }}</span>
            <span class="pie-redes">
                <span class="pie-red"><i class="fab fa-facebook-f"></i></span>
                <span class="pie-red"><i class="fab fa-instagram"></i></span>
                <span class="pie-red"><i class="fab fa-linkedin-in"></i></span>
                <span class="pie-red"><i class="fab fa-x-twitter"></i></span>
            </span>
        </div>

        <table class="info">
            <tr>
                <td colspan="2" style="width:50%"><span class="lbl">Cliente / prospecto</span><span class="val">{{ $c->cliente }}</span></td>
                <td><span class="lbl">Fecha</span><span class="val">{{ \App\Models\Cotizacion::fechaLarga($c->fecha) }}</span></td>
                <td><span class="lbl">Válida hasta</span><span class="val">{{ \App\Models\Cotizacion::fechaLarga($c->valida_hasta) }}</span></td>
            </tr>
            <tr>
                <td><span class="lbl">Contacto / representante</span><span class="val-n">{{ $c->contacto }}</span></td>
                <td><span class="lbl">Puerto de cruce</span><span class="val">{{ $c->puerto }}</span></td>
                <td><span class="lbl">Producto / mercancía</span><span class="val-n">{{ $c->producto }}</span></td>
                <td><span class="lbl">Tipo de operación</span>
                    <span class="pill {{ $c->tipo_operacion === 'IMPORTACION' ? 'pill-imp' : 'pill-exp' }}">{{ $c->tipo_operacion_texto }}</span>
                </td>
            </tr>
        </table>

        @forelse($resumen['bloques'] as $b)
        <table class="bloque">
            <thead>
                <tr>
                    <th>{{ $b['seccion']->titulo_pdf }}</th>
                    <th class="t">Tarifa</th>
                    <th class="d">Descripción</th>
                </tr>
            </thead>
            <tbody>
                @foreach($b['conceptos'] as $concepto)
                <tr>
                    <td>{{ $concepto->descripcion }}@if($concepto->cantidad != 1) <span style="color:#64748b">(×{{ rtrim(rtrim(number_format($concepto->cantidad, 2), '0'), '.') }})</span>@endif</td>
                    <td class="t">{{ $concepto->tarifaTexto() }}</td>
                    <td class="d">{{ mb_strtoupper($concepto->descripcion_doc, 'UTF-8') }}</td>
                </tr>
                @if($concepto->nota)
                <tr class="nota">
                    <td class="nota-txt">• {!! nl2br(e($concepto->nota)) !!}</td>
                    <td class="t"></td>
                    <td class="d"></td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>
        @empty
        <p style="text-align:center;color:#94a3b8;padding:30pt 0">Esta cotización todavía no tiene conceptos.</p>
        @endforelse

        @if(config('cotizador.mostrar_totales_pdf') && count($resumen['bloques']))
        <table class="tot">
            @foreach($resumen['bloques'] as $b)
            <tr>
                <td>{{ $b['seccion']->titulo_pdf }}: ${{ number_format($b['subtotal'], 2) }} MXN
                    @if($b['subtotal_usd']) + ${{ number_format($b['subtotal_usd'], 2) }} USD @endif
                    @if($b['iva'] > 0) + IVA ${{ number_format($b['iva'], 2) }} @endif</td>
            </tr>
            @endforeach
            <tr class="g">
                <td>TOTAL ESTIMADO: ${{ number_format($resumen['total'], 2) }} MXN
                    @if($resumen['total_usd']) + ${{ number_format($resumen['total_usd'], 2) }} USD @endif
                </td>
            </tr>
        </table>
        @endif

        {{-- Términos y firmas: debajo de los conceptos si caben en la hoja; si no, pasan juntos a la siguiente --}}
        <div class="tc-wrap">

            <div class="tc-titulo">TÉRMINOS Y CONDICIONES:</div>
            <div class="tc">
                @foreach($c->terminos as $i => $t)
                <p>{{ $i + 1 }}. {!! nl2br(e(mb_strtoupper($t->texto, 'UTF-8'))) !!}</p>
                @endforeach
            </div>

            <table class="firmas">
                <tr>
                    <td>
                        <div class="atte">ATENTAMENTE,</div>
                        <div class="linea" style="width:90%">
                            <div class="vend">{{ $c->vendedor }}</div>
                            <div class="puesto">{{ config('cotizador.firma_puesto') }}</div>
                        </div>
                    </td>
                    <td></td>
                </tr>
            </table>
        </div>

        @if($modo === 'html')
    </div>@endif
</body>

</html>