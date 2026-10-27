{{-- Aviso interno al coordinador: el cliente respondió la cotización desde el enlace del correo --}}
@php
    $c       = $cotizacion;
    $aprobo  = (bool) $c->aprobada;
    $color   = $aprobo ? '#38a169' : '#d97706';
    $titulo  = $aprobo ? 'El cliente APROBÓ la cotización' : 'El cliente SOLICITÓ CAMBIOS';
    $fecha   = $aprobo ? $c->aprobada_at : $c->cambios_solicitados_at;
@endphp
<!DOCTYPE html>
<html lang="es">
<body style="margin:0; padding:24px 12px; background:#f3f4f6; font-family:Arial,Helvetica,sans-serif; color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center">
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:560px; background:#ffffff; border-radius:10px; overflow:hidden;">
        <tr><td style="padding:16px 24px; background:#2A3A4D; color:#ffffff; font-size:14px; font-weight:bold; letter-spacing:1px; text-transform:uppercase;">
            Cotización {{ $c->folio }}
        </td></tr>
        <tr><td style="padding:22px 24px 6px 24px;">
            <div style="display:inline-block; padding:6px 12px; border-radius:6px; background:{{ $color }}; color:#ffffff; font-size:13px; font-weight:bold;">{{ $titulo }}</div>
        </td></tr>
        <tr><td style="padding:12px 24px; font-size:14px; line-height:22px;">
            <b>Cliente:</b> {{ $c->cliente }}<br>
            <b>Respondió:</b> {{ $c->aprobada_por }}<br>
            <b>Fecha:</b> {{ optional($fecha)->format('d/m/Y H:i') }}<br>
            <b>Operación:</b> {{ $c->tipo_operacion === 'IMPORTACION' ? 'Importación' : 'Exportación' }} por {{ $c->puerto }}
        </td></tr>
        @if($c->respuesta_comentario)
            <tr><td style="padding:4px 24px 18px 24px;">
                <div style="padding:12px 14px; border-left:3px solid {{ $color }}; background:#f8fafc; font-size:13px; line-height:20px;">
                    {!! nl2br(e($c->respuesta_comentario)) !!}
                </div>
            </td></tr>
        @endif
        <tr><td style="padding:6px 24px 22px 24px; font-size:12px; color:#6b7280;">
            {{ $aprobo ? 'La cotización quedó marcada como aprobada en el sistema.' : 'Abre la cotización en el generador para hacer los ajustes y envíala de nuevo.' }}
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
