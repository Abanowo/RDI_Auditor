<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Auditoría</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .alert-box { padding: 15px; background-color: #FEF2F2; border: 1px solid #FCA5A5; border-radius: 6px; margin-top: 20px; }
        .table-discrepancies { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; text-align: left; }
        .table-discrepancies th { padding: 8px; border: 1px solid #FCA5A5; background-color: #FEE2E2; color: #991B1B; }
        .table-discrepancies td { padding: 8px; border: 1px solid #FCA5A5; background-color: #FFFFFF; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-gray { color: #4B5563; }
        .text-red { color: #DC2626; font-weight: bold; }
        .text-orange { color: #D97706; font-weight: bold; }
        .text-dark-red { color: #991B1B; font-weight: bold; }
    </style>
</head>

<body>

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
            $descartados = $tarea->pedimentos_descartados;
            $lista_descartados = $descartados ? array_keys(is_array($descartados) ? $descartados : json_decode($descartados, true)) : [];
        @endphp

        @if(count($lista_descartados) > 0)
            <div style="padding: 10px; background-color: #FFFBE6; border: 1px solid #FFE58F; border-radius: 4px; margin-top: 20px;">
                <strong style="color: #D46B08;">Aviso:</strong> Se descartaron los siguientes pedimentos (no se encontraron en la base de datos o su numero de patente está errónea):
                <ul style="margin-top: 5px; padding-left: 20px;">
                    @foreach($lista_descartados as $pedimento)
                        <li>{{ $pedimento }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    <p>
        <strong>Reportes Adjuntos:</strong><br>
        - {{ $tarea->nombre_reporte_impuestos }} [Reporte de Impuestos - Facturados]<br>
        - {{ $tarea->nombre_reporte_pendientes }} [Reporte de Impuestos - Pendientes]
    </p>

    @if(isset($discrepancias) && count($discrepancias) > 0)
        <div class="alert-box">
            <h3 style="color: #991B1B; margin-top: 0; margin-bottom: 10px;">⚠️ Alerta de Discrepancias Detectadas</h3>
            <p style="margin-bottom: 15px; font-size: 14px; color: #7F1D1D;">Se detectaron diferencias entre el monto esperado (SC) y el monto facturado.</p>

            @foreach($discrepancias as $tipo => $items)
                @php
                    $totalItems = count($items);
                    $maxMostrar = 20;
                    $itemsLimitados = array_slice($items, 0, $maxMostrar);
                    $diferenciaOcultos = $totalItems - $maxMostrar;
                @endphp

                <h4 style="color: #B91C1C; margin-bottom: 5px; border-bottom: 1px solid #FECACA; padding-bottom: 3px; text-transform: uppercase;">
                    Discrepancias en {{ str_replace('_', ' ', $tipo) }} ({{ $totalItems }} encontradas)
                </h4>
                
                <table class="table-discrepancies">
                    <thead>
                        <tr>
                            <th>Pedimento</th>
                            <th class="text-center">Operación</th>
                            <th class="text-right">Estado de cuenta (MXN)</th>
                            <th class="text-right">Monto SC (MXN)</th>
                            <th class="text-right">Diferencia</th>
                            <th class="text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($itemsLimitados as $item)
                            <tr>
                                <td><strong>{{ $item['pedimento'] }}</strong></td>
                                <td class="text-center text-gray">{{ $item['tipo_operacion'] ?? 'N/A' }}</td>
                                <td class="text-right">${{ number_format($item['monto_factura'] ?? 0, 2) }}</td>
                                <td class="text-right">${{ number_format($item['monto_sc'] ?? 0, 2) }}</td>
                                <td class="text-right {{ ($item['diferencia'] ?? 0) < 0 ? 'text-red' : 'text-orange' }}">
                                    {{ ($item['diferencia'] ?? 0) > 0 ? '+' : '' }}${{ number_format($item['diferencia'] ?? 0, 2) }}
                                </td>
                                <td class="text-center text-dark-red">
                                    {{ $item['estado'] ?? 'N/A' }}
                                </td>
                            </tr>
                        @endforeach

                        @if($diferenciaOcultos > 0)
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 12px; background-color: #F9FAFB; border: 1px solid #E5E7EB; color: #4B5563; font-style: italic;">
                                    ⚠️ Hay <strong>{{ $diferenciaOcultos }} discrepancias adicionales</strong> en esta categoría que se omitieron para no saturar este correo.<br>
                                    <span style="font-weight: bold; color: #111827;">Por favor, consulta el archivo Excel adjunto para revisar la lista completa.</span>
                                </td>
                            </tr>
                        @endif
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