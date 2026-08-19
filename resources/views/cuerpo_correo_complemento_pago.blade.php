<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complemento de Pago</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6;">
    <div style="background-color: #f3f4f6; padding: 40px 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6;">
        <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid #e5e7eb;">
            
            <!-- Encabezado con Logo Integrado -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-bottom: 4px solid #1e3a8a;">
                <tr>
                    <td style="padding: 25px 30px; text-align: left; vertical-align: middle;">
                        <h1 style="margin: 0; color: #1e3a8a; font-size: 22px; font-weight: 700; letter-spacing: 0.5px;">Complemento de Pago</h1>
                        <p style="margin: 5px 0 0 0; color: #64748b; font-size: 14px;">Folio: <strong style="color: #0f172a;">{{ $folioDocumento }}</strong></p>
                    </td>
                    <td style="padding: 25px 30px; text-align: right; vertical-align: middle; width: 45%;">
                        <!-- Lógica de Logos -->
                        @if($empresaEmisora == 'Transportactics')
                            <img src="{{ $message->embed(public_path('images/transportactics.png')) }}" alt="Transportactics" style="max-height: 55px; width: auto; display: block; margin-left: auto;">
                        @elseif($empresaEmisora == 'INTSHIPPERTS')
                            <img src="{{ $message->embed(public_path('images/intshipperts.png')) }}" alt="INTSHIPPERTS" style="max-height: 55px; width: auto; display: block; margin-left: auto;">
                        @else
                            <img src="{{ $message->embed(public_path('images/InTactics - Azul.png')) }}" alt="InTactics" style="max-height: 55px; width: auto; display: block; margin-left: auto;">
                        @endif
                    </td>
                </tr>
            </table>

            <!-- Cuerpo del Correo -->
            <div style="padding: 30px;">
                <p style="margin-top: 0; color: #374151; font-size: 16px;">Buen día estimado <strong>{{ $nombreCliente }}</strong>,</p>
                <p style="color: #4b5563; font-size: 15px;">Por medio del presente le compartimos su complemento de pago correspondiente a las facturas referenciadas.</p>

                <!-- Tabla de Detalles -->
                <table style="width: 100%; border-collapse: collapse; margin: 30px 0; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <thead>
                        <tr style="background-color: #f8fafc;">
                            <th style="padding: 12px 15px; text-align: left; font-size: 13px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0;">Fecha</th>
                            <th style="padding: 12px 15px; text-align: left; font-size: 13px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0;">Referencia</th>
                            <th style="padding: 12px 15px; text-align: center; font-size: 13px; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #bfdbfe; background-color: #eff6ff;">Complemento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e8f0; color: #334155; font-size: 14px;">{{ $fechaDocumento }}</td>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e8f0; color: #334155; font-size: 14px;">{{ $referencia }}</td>
                            <td style="padding: 15px; border-bottom: 1px solid #e2e8f0; color: #1e3a8a; font-size: 14px; font-weight: bold; background-color: #eff6ff; text-align: center;">{{ $folioDocumento }}</td>
                        </tr>
                    </tbody>
                </table>

                <p style="color: #64748b; font-size: 13px; font-style: italic; margin-bottom: 0;">* Adjunto a este correo encontrará el archivo PDF correspondiente a su documento fiscal.</p>
            </div>

            <!-- Pie de página -->
            <div style="background-color: #f8fafc; padding: 20px 30px; border-top: 1px solid #e2e8f0; text-align: center;">
                <p style="margin: 0; color: #334155; font-weight: 600; font-size: 14px;">Atentamente,</p>
                <p style="margin: 3px 0 10px 0; color: #1e3a8a; font-weight: 700; font-size: 15px;">Cuentas por Pagar {{ $empresaEmisora }}</p>
                <p style="margin: 0; color: #94a3b8; font-size: 11px;">Generado automáticamente por el Sistema {{ $empresaEmisora }}</p>
            </div>
            
        </div>
    </div>
</body>
</html>