{{--
    Página pública que abre el cliente desde el botón del correo.
    $estado: pendiente | aprobada | cambios | vencido | invalido
--}}
@php
$empresa = config('cotizador.empresa');
$logo = null;
$rutaLogo = public_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $empresa['logo']));
if (is_file($rutaLogo)) {
$logo = 'data:image/png;base64,' . base64_encode(file_get_contents($rutaLogo));
}
$c = $cotizacion;
$operacion = $c && $c->tipo_operacion === 'IMPORTACION' ? 'Importación' : 'Exportación';
@endphp
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $c ? 'Cotización ' . $c->folio : 'Cotización' }} · {{ $empresa['nombre'] }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f4f6;
            font-family: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
            color: #1f2937;
            -webkit-font-smoothing: antialiased;
        }

        .pag {
            max-width: 760px;
            margin: 0 auto;
            padding: 32px 16px 48px;
        }

        .tarjeta {
            overflow: hidden;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, .15);
        }

        .franja {
            display: flex;
            height: 6px;
        }

        .franja span:nth-child(1) {
            flex: 62;
            background: #d32f2f;
        }

        .franja span:nth-child(2) {
            flex: 26;
            background: #4a2c3d;
        }

        .franja span:nth-child(3) {
            flex: 12;
            background: #d32f2f;
        }

        .cab {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 22px 28px;
            border-bottom: 1px solid #e5e7eb;
        }

        .cab img {
            height: 60px;
            max-width: 260px;
        }

        .cab .marca {
            font-size: 22px;
            font-weight: 800;
            color: #2A3A4D;
        }

        .cab .folio {
            text-align: right;
            font-size: 12px;
            color: #6b7280;
        }

        .cab .folio b {
            display: block;
            font-size: 16px;
            color: #2A3A4D;
        }

        .titulo {
            padding: 18px 28px;
            background: #2A3A4D;
            color: #fff;
        }

        .titulo h1 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .titulo p {
            margin: 4px 0 0;
            font-size: 13px;
            color: #bfdbfe;
        }

        .cuerpo {
            padding: 26px 28px 30px;
        }

        .datos {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px 20px;
            margin-bottom: 22px;
        }

        .dato span {
            display: block;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
            color: #6b7280;
        }

        .dato b {
            font-size: 14px;
            color: #1f2937;
            text-transform: uppercase;
        }

        .total {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 22px;
            padding: 14px 18px;
            border: 1px solid #2A3A4D;
            border-radius: 10px;
            background: #f3f4f6;
        }

        .total span {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
            color: #2A3A4D;
        }

        .total b {
            padding: 6px 14px;
            border: 1px solid #fef08a;
            border-radius: 8px;
            background: #fef9c3;
            font-size: 20px;
            color: #ca8a04;
        }

        .pdf {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 26px;
            padding: 10px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            font-size: 14px;
            font-weight: 700;
            color: #2A3A4D;
            text-decoration: none;
        }

        .pdf:hover {
            background: #f3f4f6;
        }

        .seccion {
            margin: 0 0 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #2A3A4D;
        }

        label.campo {
            display: block;
            margin-bottom: 14px;
        }

        label.campo span {
            display: block;
            margin-bottom: 6px;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
            color: #6b7280;
        }

        input[type=text],
        textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font: inherit;
            font-size: 14px;
            color: #1f2937;
        }

        input[type=text]:focus,
        textarea:focus {
            outline: none;
            border-color: #9ca3af;
        }

        textarea {
            min-height: 96px;
            resize: vertical;
        }

        .check {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 4px 0 20px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f3f4f6;
            font-size: 13px;
            color: #374151;
            cursor: pointer;
        }

        .check input {
            width: 18px;
            height: 18px;
            margin: 1px 0 0;
            accent-color: #00C09F;
            flex: none;
        }

        .botones {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 12px;
        }

        .btn {
            height: 48px;
            padding: 0 24px;
            border: 0;
            border-radius: 6px;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
        }

        .btn-gris {
            background: #edf2f7;
            color: #4a5568;
        }

        .btn-gris:hover {
            background: #e2e8f0;
        }

        .btn-verde {
            background: #38a169;
            color: #fff;
        }

        .btn-verde:hover {
            background: #2f855a;
        }

        .errores {
            margin-bottom: 18px;
            padding: 12px 16px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: #fef2f2;
            font-size: 13px;
            color: #991b1b;
        }

        .errores ul {
            margin: 0;
            padding-left: 18px;
        }

        .estado {
            padding: 34px 28px;
            text-align: center;
        }

        .estado .icono {
            display: inline-grid;
            place-items: center;
            width: 64px;
            height: 64px;
            margin-bottom: 14px;
            border-radius: 50%;
            font-size: 30px;
            color: #fff;
        }

        .estado h2 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #2A3A4D;
        }

        .estado p {
            margin: 0 auto;
            max-width: 480px;
            font-size: 14px;
            line-height: 1.6;
            color: #4b5563;
        }

        .ok {
            background: #38a169;
        }

        .aviso {
            background: #d97706;
        }

        .mal {
            background: #9ca3af;
        }

        .pie {
            padding: 14px 28px;
            background: #2A3A4D;
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: #fff;
        }

        .nota {
            margin-top: 14px;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
        }

        @media (max-width: 560px) {
            .datos {
                grid-template-columns: 1fr;
            }

            .cab {
                flex-direction: column;
                align-items: flex-start;
            }

            .cab .folio {
                text-align: left;
            }

            .btn {
                flex: 1;
            }
        }
    </style>
</head>

<body>
    <div class="pag">
        <div class="tarjeta">
            <div class="franja"><span></span><span></span><span></span></div>
            <div class="cab">
                @if($logo)<img src="{{ $logo }}" alt="{{ $empresa['nombre'] }}">@else<span class="marca">INTACTICS<span style="color:#d32f2f">®</span></span>@endif
                @if($c)
                <div class="folio">Propuesta comercial<b>{{ $c->folio }}</b>{{ \App\Models\Cotizacion::fechaLarga($c->fecha) }}</div>
                @endif
            </div>

            @if($estado === 'invalido')
            <div class="estado">
                <div class="icono mal">?</div>
                <h2>Enlace no válido</h2>
                <p>Este enlace de aprobación no existe o fue reemplazado por uno más reciente. Revisa el último correo que te enviamos o escríbenos a {{ strtolower($empresa['email']) }}.</p>
            </div>
            @else
            <div class="titulo">
                <h1>Cotización de servicios</h1>
                <p>{{ $c->cliente }}</p>
            </div>

            <div class="cuerpo">
                <div class="datos">
                    <div class="dato"><span>Tipo de operación</span><b>{{ $operacion }}</b></div>
                    <div class="dato"><span>Puerto de cruce</span><b>{{ $c->puerto ?: '—' }}</b></div>
                    <div class="dato"><span>Producto / mercancía</span><b>{{ $c->producto ?: '—' }}</b></div>
                    <div class="dato"><span>Vigencia</span><b>{{ $c->valida_hasta ? 'Hasta el ' . \App\Models\Cotizacion::fechaLarga($c->valida_hasta) : '—' }}</b></div>
                </div>

                @if(!empty($resumen['total']))
                <div class="total"><span>Total estimado (con IVA donde aplica)</span><b>${{ number_format($resumen['total'], 2) }} MXN</b></div>
                @endif

                <a class="pdf" href="{{ url('/cotizacion/aprobar/' . $token . '/pdf') }}" target="_blank" rel="noopener">📄 Ver cotización en PDF</a>

                @if($estado === 'aprobada')
                <div class="estado" style="padding:10px 0 0">
                    <div class="icono ok">✓</div>
                    <h2>¡Cotización aprobada!</h2>
                    <p>Aprobada por <b>{{ $c->aprobada_por }}</b> el {{ optional($c->aprobada_at)->format('d/m/Y \a \l\a\s H:i') }}.
                        Tu coordinador comercial ya recibió el aviso y se pondrá en contacto para iniciar el proceso de transición.</p>
                </div>
                @elseif($estado === 'cambios')
                <div class="estado" style="padding:10px 0 0">
                    <div class="icono aviso">✎</div>
                    <h2>Recibimos tu solicitud de cambios</h2>
                    <p>Gracias, {{ $c->aprobada_por }}. Tu coordinador comercial revisará tus comentarios y te enviará una propuesta actualizada.</p>
                </div>
                @elseif($estado === 'vencido')
                <div class="estado" style="padding:10px 0 0">
                    <div class="icono mal">!</div>
                    <h2>Esta cotización ya no está vigente</h2>
                    <p>La vigencia terminó el {{ \App\Models\Cotizacion::fechaLarga($c->token_expira_at) }}. Escríbenos a {{ strtolower($empresa['email']) }} y con gusto te enviamos una actualizada.</p>
                </div>
                @else
                <h2 class="seccion">Tu respuesta</h2>

                @if($errors->any())
                <div class="errores">
                    <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
                @endif

                <form method="POST" action="{{ url('/cotizacion/aprobar/' . $token) }}">
                    @csrf
                    <label class="campo"><span>Nombre de quien autoriza *</span>
                        <input type="text" name="nombre" value="{{ old('nombre', $c->contacto) }}" maxlength="150" required></label>
                    <label class="campo"><span>Comentarios (obligatorio si solicitas cambios)</span>
                        <textarea name="comentario" maxlength="3000">{{ old('comentario') }}</textarea></label>
                    <label class="check">
                        <input type="checkbox" name="acepto" value="1" {{ old('acepto') ? 'checked' : '' }}>
                        <span>He revisado la cotización {{ $c->folio }} y acepto las tarifas y los términos y condiciones incluidos en el PDF.</span>
                    </label>
                    <div class="botones">
                        <button type="submit" name="accion" value="cambios" class="btn btn-gris">Solicitar cambios</button>
                        <button type="submit" name="accion" value="aprobar" class="btn btn-verde">Aprobar cotización</button>
                    </div>
                </form>
                @endif
            </div>
            @endif

            <div class="pie">NOGALES • TIJUANA • LAREDO • MEXICALI • REYNOSA • MANZANILLO • VERACRUZ</div>
        </div>
        <p class="nota">{{ $empresa['razon_social'] }} · {{ strtolower($empresa['email']) }}</p>
    </div>
</body>

</html>