{{--
    Correo de envío de la cotización ("carta de presentación"). El PDF va adjunto.
    Diseñado con tablas y estilos en línea para que se vea igual en Gmail, Outlook y móviles.

    Variables: $cotizacion, $mensaje, $nombreRemitente,
               $firmaDatos / $firmaMime (base64 de signature_image), $firmaRuta (archivo), $firmaUrl (URL)
--}}
@php
$empresa = config('cotizador.empresa');
$operacion = $cotizacion->tipo_operacion === 'IMPORTACION' ? 'importación' : 'exportación';
$puerto = $cotizacion->puerto ?: 'Nogales';

// Mensaje editado en el modal; si llega vacío, el de config('cotizador.mensaje_correo')
$texto = trim((string) $mensaje) !== ''
? (string) $mensaje
: strtr((string) config('cotizador.mensaje_correo'), ['{operacion}' => $operacion, '{puerto}' => $puerto]);
$parrafos = array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', $texto)), 'strlen'));

// Logo incrustado (public/images/INTACTICS ROJO.png)
$urlLogo = null;
$rutaLogo = public_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $empresa['logo']));
if (isset($message) && is_file($rutaLogo)) {
$urlLogo = $message->embed($rutaLogo);
}

// Firma del coordinador
$urlFirma = null;
if (!empty($firmaDatos) && isset($message)) {
$urlFirma = $message->embedData($firmaDatos, 'firma.' . (strpos($firmaMime, 'jpeg') !== false ? 'jpg' : 'png'), $firmaMime);
} elseif (!empty($firmaRuta) && isset($message)) {
$urlFirma = $message->embed($firmaRuta);
} elseif (!empty($firmaUrl)) {
$urlFirma = $firmaUrl;
}

$fecha = \App\Models\Cotizacion::fechaLarga($cotizacion->fecha);
$validaHasta = \App\Models\Cotizacion::fechaLarga($cotizacion->valida_hasta);
$sucursales = 'NOGALES • TIJUANA • LAREDO • MEXICALI • REYNOSA • MANZANILLO • VERACRUZ';

$rojo = '#d32f2f';
$navy = '#2A3A4D';
$gris = '#64748b';
$linea = '#e5e7eb';
@endphp
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Cotización {{ $cotizacion->folio }}</title>
</head>

<body style="margin:0; padding:0; background:#f1f4f8; -webkit-text-size-adjust:100%;">

    {{-- Texto de vista previa en la bandeja de entrada --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; font-size:1px; line-height:1px; color:#f1f4f8;">
        Propuesta de servicios {{ $operacion }} por {{ $puerto }} · {{ $cotizacion->folio }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f4f8;">
        <tr>
            <td align="center" style="padding:28px 12px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                    style="width:100%; max-width:600px; background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid {{ $linea }};">

                    {{-- Franja de color superior --}}
                    <tr>
                        <td style="padding:0; font-size:0; line-height:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="62%" style="height:6px; background:{{ $rojo }};"></td>
                                    <td width="26%" style="height:6px; background:#4a2c3d;"></td>
                                    <td width="12%" style="height:6px; background:{{ $rojo }};"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Encabezado con logo --}}
                    <tr>
                        <td style="padding:26px 36px 18px 36px; border-bottom:1px solid {{ $linea }};">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td valign="middle">
                                        @if($urlLogo)
                                        <img src="{{ $urlLogo }}" alt="{{ $empresa['nombre'] }}" height="42"
                                            style="display:block; height:42px; width:auto; max-width:200px; border:0; outline:none;">
                                        @else
                                        <span style="font-family:Arial,Helvetica,sans-serif; font-size:22px; font-weight:bold; color:{{ $navy }}; letter-spacing:1px;">INTACTICS<span style="color:{{ $rojo }};">®</span></span>
                                        @endif
                                    </td>
                                    <td valign="middle" align="right"
                                        style="font-family:Arial,Helvetica,sans-serif; font-size:11px; line-height:16px; color:{{ $gris }};">
                                        <span style="font-size:10px; font-weight:bold; letter-spacing:1px; text-transform:uppercase; color:{{ $rojo }};">Propuesta comercial</span><br>
                                        <b style="font-size:14px; color:{{ $navy }};">{{ $cotizacion->folio }}</b><br>
                                        {{ $fecha }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Mensaje --}}
                    <tr>
                        <td style="padding:30px 36px 8px 36px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:23px; color:#1f2937;">
                            @foreach($parrafos as $i => $parrafo)
                            <p style="margin:0 0 16px 0;{{ $i === 0 ? ' font-size:15px; font-weight:bold; color:' . $navy . ';' : '' }}">{!! nl2br(e($parrafo)) !!}</p>
                            @endforeach
                        </td>
                    </tr>

                    {{-- Resumen de la cotización --}}
                    <tr>
                        <td style="padding:6px 36px 4px 36px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                style="background:#f8fafc; border:1px solid {{ $linea }}; border-left:4px solid {{ $navy }}; border-radius:6px;">
                                <tr>
                                    <td colspan="2" style="padding:14px 18px 6px 18px; font-family:Arial,Helvetica,sans-serif; font-size:10px; font-weight:bold; letter-spacing:1px; text-transform:uppercase; color:{{ $gris }};">
                                        Resumen de la propuesta
                                    </td>
                                </tr>
                                @foreach([
                                'Cliente' => $cotizacion->cliente,
                                'Tipo de operación' => ucfirst($operacion),
                                'Puerto de cruce' => $puerto,
                                'Producto / mercancía' => $cotizacion->producto,
                                'Vigencia' => $validaHasta ? 'Hasta el ' . $validaHasta : null,
                                ] as $etiqueta => $valor)
                                @if($valor)
                                <tr>
                                    <td width="42%" style="padding:5px 18px; font-family:Arial,Helvetica,sans-serif; font-size:12px; color:{{ $gris }};">{{ $etiqueta }}</td>
                                    <td style="padding:5px 18px 5px 0; font-family:Arial,Helvetica,sans-serif; font-size:12px; font-weight:bold; color:{{ $navy }}; text-transform:uppercase;">{{ $valor }}</td>
                                </tr>
                                @endif
                                @endforeach
                                <tr>
                                    <td colspan="2" style="padding:10px 18px 14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:12px; color:#1f2937;">
                                        <span style="display:inline-block; padding:5px 10px; background:#ffffff; border:1px solid {{ $linea }}; border-radius:4px;">
                                            📎 <b>{{ $cotizacion->nombreArchivo() }}</b> &nbsp;·&nbsp; adjunto en este correo
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Firma del coordinador --}}
                    <tr>
                        <td style="padding:28px 36px 30px 36px;">
                            @if($urlFirma)
                            <img src="{{ $urlFirma }}" alt="Firma del Asesor"
                                style="display:block; max-width:320px; max-height:120px; border:0; outline:none;">
                            @else
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:2px 0 2px 14px; border-left:3px solid {{ $rojo }}; font-family:Arial,Helvetica,sans-serif;">
                                        <div style="font-size:15px; font-weight:bold; color:{{ $navy }};">{{ $nombreRemitente ?: $cotizacion->vendedor }}</div>
                                        <div style="padding-top:2px; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:{{ $gris }};">{{ config('cotizador.firma_puesto') }}</div>
                                        <div style="padding-top:6px; font-size:12px; color:{{ $gris }};">
                                            {{ $empresa['nombre'] }} &nbsp;·&nbsp;
                                            <a href="mailto:{{ strtolower($empresa['email']) }}" style="color:{{ $rojo }}; text-decoration:none;">{{ strtolower($empresa['email']) }}</a>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            @endif
                        </td>
                    </tr>

                    {{-- Pie: sucursales, dirección y sitio --}}
                    <tr>
                        <td style="padding:16px 36px; background:{{ $navy }}; font-family:Arial,Helvetica,sans-serif; text-align:center;">
                            <div style="font-size:10px; font-weight:bold; letter-spacing:1.5px; color:#ffffff;">{{ $sucursales }}</div>
                            <div style="padding-top:8px; font-size:11px; line-height:16px; color:#cbd5e1;">
                                {{ implode(' ', $empresa['direccion']) }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:9px 36px; background:{{ $rojo }}; font-family:Arial,Helvetica,sans-serif; text-align:center;">
                            <a href="https://www.intactics.com" style="font-size:11px; font-weight:bold; letter-spacing:2px; color:#ffffff; text-decoration:none;">WWW.INTACTICS.COM</a>
                        </td>
                    </tr>
                </table>

                {{-- Aviso de confidencialidad --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
                    <tr>
                        <td style="padding:14px 10px 0 10px; font-family:Arial,Helvetica,sans-serif; font-size:10px; line-height:15px; color:#94a3b8; text-align:center;">
                            Este correo y su archivo adjunto contienen información confidencial dirigida exclusivamente a {{ $cotizacion->cliente }}.
                            Si lo recibió por error, le agradecemos notificarlo a {{ strtolower($empresa['email']) }} y eliminarlo.
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>

</html>