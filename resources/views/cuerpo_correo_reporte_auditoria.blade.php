<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Auditoría</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">

    <h2>Reporte de Auditoría Automático</h2>

    <p>Hola,</p>

    <p>Se ha completado un proceso de auditoría. Los reportes generados se encuentran adjuntos a este correo.</p>

    <ul>
        <li><strong>Estado de cuenta:</strong> {{ $tarea->nombre_archivo }}</li>

        <li><strong>Banco:</strong> {{ $tarea->banco }}</li>
        <li><strong>Sucursal:</strong> {{ $tarea->sucursal }}</li>
        <li><strong>Fecha de Creacion:</strong> {{ $tarea->created_at->format('d/m/Y H:i') }}</li>
        <li><strong>Fecha de Proceso:</strong> {{ $tarea->updated_at->format('d/m/Y H:i') }}</li>
    </ul>
    @if(!empty($tarea->pedimentos_descartados))
        @php
            // El modelo ya convierte 'pedimentos_descartados' en un array
            $descartados = $tarea->pedimentos_descartados;
            $lista_descartados = $descartados ? array_keys(is_array($descartados) ? $descartados : json_decode($descartados, true)) : [];
        @endphp

        @if(count($lista_descartados) > 0)
            <div
                style="padding: 10px; background-color: #FFFBE6; border: 1px solid #FFE58F; border-radius: 4px; margin-top: 20px;">
                <strong style="color: #D46B08;">Aviso:</strong> Se descartaron los siguientes pedimentos (no se encontraron en
                la base de datos o su numero de patente está errónea):
                <ul style="margin-top: 5px; padding-left: 20px;">
                    @foreach($lista_descartados as $pedimento)
                        <li>{{ $pedimento }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
    <p>
        <strong>Reportes Adjuntos:</strong>
        <br>
        - {{ $tarea->nombre_reporte_impuestos }} [Reporte de Impuestos - Facturados]
        <br>
        - {{ $tarea->nombre_reporte_pendientes }} [Reporte de Impuestos - Pendientes]
    </p>

        @if(isset($discrepancias) && count($discrepancias) > 0)
        <div style="padding: 15px; background-color: #FEF2F2; border: 1px solid #FCA5A5; border-radius: 6px; margin-top: 20px;">
            <h3 style="color: #991B1B; margin-top: 0; margin-bottom: 10px;">⚠️ Alerta de Discrepancias Detectadas</h3>
            <p style="margin-bottom: 15px; font-size: 14px; color: #7F1D1D;">Se detectaron diferencias entre el monto esperado (SC) y el monto facturado en los siguientes pedimentos:</p>

            @foreach($discrepancias as $tipo => $items)
                <h4 style="color: #B91C1C; margin-bottom: 5px; border-bottom: 1px solid #FECACA; padding-bottom: 3px; text-transform: uppercase;">
                    Discrepancias en {{ str_replace('_', ' ', $tipo) }}
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; text-align: left;">
                    <thead>
                        <tr style="background-color: #FEE2E2; color: #991B1B;">
                            <th style="padding: 8px; border: 1px solid #FCA5A5;">Pedimento</th>
                            <th style="padding: 8px; border: 1px solid #FCA5A5; text-align: right;">Monto Factura (MXN)</th>
                            <th style="padding: 8px; border: 1px solid #FCA5A5; text-align: right;">Monto SC (MXN)</th>
                            <th style="padding: 8px; border: 1px solid #FCA5A5; text-align: right;">Diferencia</th>
                            <th style="padding: 8px; border: 1px solid #FCA5A5; text-align: center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            <tr style="background-color: #FFFFFF;">
                                <td style="padding: 8px; border: 1px solid #FCA5A5;"><strong>{{ $item['pedimento'] }}</strong></td>
                                <td style="padding: 8px; border: 1px solid #FCA5A5; text-align: right;">${{ number_format($item['monto_factura'], 2) }}</td>
                                <td style="padding: 8px; border: 1px solid #FCA5A5; text-align: right;">${{ number_format($item['monto_sc'], 2) }}</td>
                                <td style="padding: 8px; border: 1px solid #FCA5A5; text-align: right; color: {{ $item['diferencia'] < 0 ? '#DC2626' : '#D97706' }}; font-weight: bold;">
                                    {{ $item['diferencia'] > 0 ? '+' : '' }}${{ number_format($item['diferencia'], 2) }}
                                </td>
                                <td style="padding: 8px; border: 1px solid #FCA5A5; text-align: center; color: #991B1B; font-weight: bold;">
                                    {{ $item['estado'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </div>
    @endif

    <hr style="margin-top: 30px; border: none; border-top: 1px solid #E5E7EB;">
    <p style="font-size: 0.8em; color: #9CA3AF; text-align: center;">
        Este es un correo generado automáticamente. Por favor, no respondas a este mensaje.
    </p>

</body>

</html>