<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Pagos por Concepto</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #000000; margin: 0; padding: 20px; font-size: 13px;">

    <p style="margin-bottom: 15px;">Buen dia,</p>
    <p style="margin-bottom: 25px;">Su apoyo con autorización de los siguientes pagos</p>

    @foreach($reporteDesglosado as $sucursalNombre => $conceptos)

        <!-- TITULO SUCURSAL (AZUL) -->
        <h3 style="color: #2f5597; font-size: 15px; font-weight: bold; margin: 25px 0 15px 0; text-transform: uppercase;">
            {{ $sucursalNombre }}
        </h3>

        @foreach($conceptos as $nombreConcepto => $filas)
            @php
                $totalGrupo = collect($filas)->sum('monto');
            @endphp

            <!-- AGRUPADOR POR CONCEPTO -->
            <div style="margin-bottom: 8px; font-size: 13px;">
                <span style="font-weight: bold;">• {{ $nombreConcepto }}</span> = 
                <span style="font-weight: bold;">${{ number_format($totalGrupo, 2) }}</span>
            </div>

            <!-- TABLA DE DESGLOSE -->
            <table width="100%" cellpadding="5" cellspacing="0" border="0" style="border-collapse: collapse; font-size: 11px; margin-bottom: 20px;">
                <thead>
                    <tr style="background-color: #000000; color: #ffffff; text-align: center; font-weight: bold;">
                        <th style="border: 1px solid #000000; width: 8%;">Sucursal</th>
                        <th style="border: 1px solid #000000; width: 30%;">Cliente</th>
                        <th style="border: 1px solid #000000; width: 12%;">Referencia</th>
                        <th style="border: 1px solid #000000; width: 15%;">Proveedor</th>
                        <th style="border: 1px solid #000000; width: 12%;">Factura P.</th>
                        <th style="border: 1px solid #000000; width: 11%;">Monto</th>
                        <th style="border: 1px solid #000000; width: 6%;">Moneda</th>
                        <th style="border: 1px solid #000000; width: 6%;">Factura SC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($filas as $fila)
                        <tr style="text-align: center; background-color: #ffffff;">
                            <td style="border: 1px solid #d9d9d9;">{{ $fila['sucursal_cod'] }}</td>
                            <td style="border: 1px solid #d9d9d9; text-align: center;">{{ $fila['cliente'] }}</td>
                            <td style="border: 1px solid #d9d9d9;">{{ $fila['pedimento'] }}</td>
                            <td style="border: 1px solid #d9d9d9;">{{ $fila['proveedor'] }}</td>
                            <td style="border: 1px solid #d9d9d9;">{{ $fila['factura_p'] }}</td>
                            <td style="border: 1px solid #d9d9d9; text-align: right; padding-right: 8px;">
                                $ {{ number_format($fila['monto'], 2) }}
                            </td>
                            <td style="border: 1px solid #d9d9d9;">{{ $fila['moneda'] }}</td>
                            <td style="border: 1px solid #d9d9d9;">{{ $fila['factura_sc'] }}</td>
                        </tr>
                    @endforeach

                    <!-- FILA DE TOTALES EN AZUL CLARO -->
                    <tr style="background-color: #d9e1f2; font-weight: bold; text-align: center;">
                        <td style="border: 1px solid #d9d9d9;" colspan="5"></td>
                        <td style="border: 1px solid #d9d9d9; text-align: right; padding-right: 8px;">
                            $ {{ number_format($totalGrupo, 2) }}
                        </td>
                        <td style="border: 1px solid #d9d9d9;" colspan="2"></td>
                    </tr>
                </tbody>
            </table>

        @endforeach

    @endforeach

</body>
</html>