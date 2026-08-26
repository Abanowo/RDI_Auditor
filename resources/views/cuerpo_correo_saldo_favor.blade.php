<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificación de Saldo a Favor</title>
    <style>
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            background-color: #ffffff; 
            margin: 0; 
            padding: 20px 0; 
            color: #333333; 
        }
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            background-color: #ffffff; 
            padding: 20px;
        }
        .highlight { 
            background-color: #fde047; /* Amarillo resaltado */
            padding: 2px 6px; 
        }
        .text-dark-blue { 
            color: #041e42; /* Azul marino corporativo */
        }
        .bg-dark-blue { 
            background-color: #041e42; 
            color: #ffffff; 
        }
        .table-headers th {
            padding: 12px; 
            font-size: 11px; 
            text-transform: uppercase;
        }
        .table-data td {
            padding: 15px 12px; 
            font-size: 12px; 
            border-bottom: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        
        <!-- ENCABEZADO (TÍTULO Y LOGO) -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 40px;">
            <tr>
                <td align="left" valign="middle">
                    <h1 class="text-dark-blue" style="font-size: 24px; margin: 0 0 15px 0; font-weight: bold;">
                        Notificación de <span class="highlight">Saldo a Favor</span>
                    </h1>
                    <div style="font-size: 14px; color: #555;">
                        Fecha: <strong style="background-color: #fde047; padding: 2px 6px; color: #000;">{{ date('d/m/Y') }}</strong>
                    </div>
                </td>
                <td align="right" valign="middle">
                    <!-- Logo incrustado -->
                    <img src="{{ $message->embed(public_path('images/InTactics - Azul.png')) }}" alt="Logo InTactics" style="max-height: 70px; height: auto; display: block;">
                </td>
            </tr>
        </table>
        
        <!-- TEXTO INTRODUCTORIO -->
        <p style="text-align: center; font-size: 14px; margin-bottom: 30px; color: #444;">
            A continuación se comparte el detalle del <span class="highlight">saldo a favor</span> detectado en su cuenta para: <strong class="text-dark-blue">{{ $saldo->cliente->nombre ?? 'CLIENTE' }}</strong>
        </p>

        <!-- FRANJA DE SUCURSAL -->
        <div class="bg-dark-blue" style="text-align: center; padding: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 30px;">
            SUCURSAL: {{ $saldo->sucursal_origen ?? 'NO ESPECIFICADA' }}
        </div>

        <!-- RECUADRO DEL MONTO -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 40px;">
            <tr>
                <td align="center">
                    <div style="border: 1px solid #e2e8f0; width: 60%; max-width: 450px; padding: 30px 20px; background-color: #fcfcfc;">
                        <div style="color: #64748b; font-size: 11px; font-weight: bold; letter-spacing: 1px; margin-bottom: 15px; text-transform: uppercase;">
                            MONTO DE CRÉDITO A FAVOR
                        </div>
                        <div class="text-dark-blue" style="font-size: 32px; font-weight: bold;">
                            ${{ number_format($saldo->monto, 2) }}
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <!-- TABLA DE DETALLES -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 50px; border-collapse: collapse;">
            <thead>
                <tr class="bg-dark-blue table-headers">
                    <th style="text-align: center; width: 20%;">FECHA DETECCIÓN</th>
                    <th style="text-align: center; width: 25%;">CLIENTE</th>
                    <th style="text-align: center; width: 15%;">TOTAL</th>
                    <th style="text-align: left; width: 40%;">CONCEPTO O JUSTIFICACIÓN</th>
                </tr>
            </thead>
            <tbody>
                <tr class="table-data">
                    <td style="text-align: center; color: #555;">{{ \Carbon\Carbon::parse($saldo->fecha_deteccion)->format('Y-m-d') }}</td>
                    <td style="text-align: center; text-transform: uppercase; color: #555;">{{ $saldo->cliente->nombre ?? 'CLIENTE' }}</td>
                    <td style="text-align: center; font-weight: bold; color: #000;">${{ number_format($saldo->monto, 2) }}</td>
                    <td style="text-align: left; color: #555;">{{ $saldo->concepto }}</td>
                </tr>
            </tbody>
        </table>
        
        <!-- MENSAJE DE DESPEDIDA -->
        <div style="margin-top: 40px;">
            <p style="font-size: 13px; color: #333; margin-bottom: 25px;">
                Si tiene alguna duda sobre este saldo o desea solicitar su aplicación en una operación específica, por favor póngase en contacto con su ejecutivo de cuenta.
            </p>

            @if(isset($urlFirma) && !empty($urlFirma))
                <img src="{{ $urlFirma }}" alt="Firma del Ejecutivo" style="max-width: 320px; max-height: 120px; border: none; outline: none; display: block;">
            @else
                <p style="color: #333333; font-weight: bold; margin: 0;">El equipo de Finanzas</p>
            @endif
        </div>
        
    </div>
</body>
</html>