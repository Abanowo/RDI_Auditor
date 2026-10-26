@php
$c = $cotizacion;
@endphp
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Cotización {{ $c->folio ?: 'borrador' }} - {{ $c->cliente }}</title>
    <style>
        @page {
            margin: 0;
        }

        body {
            margin: 0;
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 7.6pt;
            color: #1f2937;
        }

        .hoja {
            position: relative;
            padding: 40pt 46pt 36pt;
        }

        .salto {
            page-break-after: always;
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
            font-family: 'DejaVu Sans Mono', monospace;
            font-weight: normal;
            text-transform: none;
        }

        .bloque td.d {
            font-size: 6.2pt;
            color: #1d5fa5;
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

        .tc-titulo {
            background: #f1f4f8;
            border-left: 3pt solid #1f2937;
            padding: 6pt 9pt;
            font-size: 8.6pt;
            font-weight: bold;
            letter-spacing: .5pt;
            margin-bottom: 12pt;
        }

        .tc p {
            margin: 0 0 7pt;
            font-size: 6.6pt;
            font-weight: bold;
            line-height: 1.45;
            text-transform: uppercase;
        }

        .firmas {
            width: 100%;
            margin-top: 44pt;
        }

        .firmas td {
            vertical-align: bottom;
            width: 50%;
        }

        .atte {
            font-weight: bold;
            font-style: italic;
            font-size: 8pt;
            margin-bottom: 26pt;
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

        .cliente-f {
            text-align: center;
            font-size: 6.6pt;
            font-weight: bold;
            color: #475569;
        }

        @if($modo ==='html') html {
            background: #e5e7eb;
        }

        body {
            padding: 18px 0;
        }

        .hoja {
            width: 8.5in;
            min-height: 11in;
            margin: 0 auto 22px;
            background: #fff;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .18);
        }

        @endif
    </style>
</head>

<body>

    {{-- ================= HOJA 1: conceptos ================= --}}
    <div class="hoja salto">
        @include('cotizacion_pdf_encabezado')

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
                    @if($b['iva'] > 0) + IVA ${{ number_format($b['iva'], 2) }} @endif</td>
            </tr>
            @endforeach
            <tr class="g">
                <td>TOTAL ESTIMADO: ${{ number_format($resumen['total'], 2) }} MXN</td>
            </tr>
        </table>
        @endif
    </div>

    {{-- ================= HOJA 2: términos y firmas ================= --}}
    <div class="hoja">
        @include('cotizacion_pdf_encabezado')

        <div class="tc-titulo">TÉRMINOS Y CONDICIONES:</div>
        <div class="tc">
            @foreach($c->terminos as $i => $t)
            <p>{{ $i + 1 }}. {!! nl2br(e(mb_strtoupper($t->texto, 'UTF-8'))) !!}</p>
            @endforeach
        </div>

        <table class="firmas">
            <tr>
                <td>
                    <div class="atte">
                        ATENTAMENTE, 
                        <div class="vend">{{ $c->vendedor }}</div>
                    </div>
                    <div class="linea" style="width:90%">
                        <div class="puesto">{{ config('cotizador.firma_puesto') }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

</body>

</html>