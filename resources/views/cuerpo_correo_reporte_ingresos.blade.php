<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ingresos</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; margin: 0; padding: 20px;">

    <!-- CABECERA PRINCIPAL -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">
        <tr>
            <td style="vertical-align: top;">
                <h2 style="color: #002060; margin: 0 0 15px 0; font-size: 22px;">Reporte de Ingresos</h2>
                <span style="font-size: 14px;">Fecha: </span> 
                <strong style="background-color: yellow; padding: 2px 5px; font-size: 14px;">{{ \Carbon\Carbon::parse($fecha)->format('d/m/Y') }}</strong>
            </td>
            <td align="right" style="vertical-align: top;">
                <img src="{{ $message->embed(public_path('images/InTactics - Azul.png')) }}" alt="InTactics" width="150" style="display: block;">
            </td>
        </tr>
    </table>

    <p style="text-align: center; font-size: 13px; margin-bottom: 30px;">
        A continuación se comparte el detalle consolidado de las sucursales: <strong>{{ strtoupper($sucursalesNombres) }}</strong>
    </p>

    <!-- INICIO DE LOS BUCLES POR SUCURSAL -->
    @foreach($ingresosAgrupados as $sucursal => $bancos)
        
        @php
            $grupoSucursalStr = strtoupper($sucursal);
            $esManzanillo = str_contains($grupoSucursalStr, 'MANZANILLO');
        @endphp

        <!-- BARRA SUCURSAL -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 15px;">
            <tr>
                <td style="background-color: #002060; color: #ffffff; text-align: center; font-weight: bold; font-size: 12px; padding: 8px;">
                    SUCURSAL: {{ $grupoSucursalStr }}
                </td>
            </tr>
        </table>

        <!-- RESUMEN DE TOTALES POR BANCO -->
        @foreach($bancos as $banco => $ingresos)
            @php $totalBanco = $ingresos->sum('monto_deposito'); @endphp
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 10px;">
                <tr>
                    <td align="center">
                        <table cellpadding="12" cellspacing="0" border="0" style="background-color: #ffffff; border: 1px solid #e0e0e0; width: 300px; border-left: 4px solid #002060;">
                            <tr>
                                <td align="center">
                                    <div style="font-size: 10px; color: #666; font-weight: bold; margin-bottom: 4px; text-transform: uppercase;">CUENTA: {{ strtoupper($banco ?: 'SIN ASIGNAR') }}</div>
                                    <div style="font-size: 18px; color: #002060; font-weight: bold;">${{ number_format($totalBanco, 2) }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        @endforeach

        <br> <!-- Espacio antes de los desgloses -->

        <!-- AGRUPACIÓN POR EMPRESA (JUNTANDO TODOS LOS BANCOS PARA EVITAR TABLAS SEPARADAS) -->
        @php
            $ingresosInTactics = collect();
            $ingresosIntshipperts = collect();
            $ingresosTransportactics = collect();

            // Desempaquetamos todos los ingresos de todos los bancos en sus respectivos servicios
            foreach($bancos as $banco => $ingresos) {
                foreach($ingresos as $ingreso) {
                    
                    // Guardamos el nombre del banco dentro del ingreso para mostrarlo en la tabla
                    $ingreso->nombre_banco = $banco ?: 'SIN ASIGNAR';

                    $nombreCliente = strtoupper($ingreso->cliente ? $ingreso->cliente->nombre : '');
                    $sucursalOrigen = strtoupper($ingreso->sucursal_origen ?? '');
                    
                    if (str_contains($grupoSucursalStr, 'INTSHIPPERT') || str_contains($nombreCliente, 'INTSHIPPERTS') || str_contains($sucursalOrigen, 'INTSHIPPERT')) {
                        $ingresosIntshipperts->push($ingreso);
                    } elseif (str_contains($grupoSucursalStr, 'TRANSPORTACTIC') || str_contains($nombreCliente, 'TRANSPORTACTICS') || str_contains($sucursalOrigen, 'TRANSPORTACTIC')) {
                        $ingresosTransportactics->push($ingreso);
                    } else {
                        $ingresosInTactics->push($ingreso); 
                    }
                }
            }

            $gruposEmpresa = [
                'InTactics' => $ingresosInTactics,
                'INTSHIPPERTS' => $ingresosIntshipperts,
                'Transportactics' => $ingresosTransportactics,
            ];
        @endphp

        @foreach($gruposEmpresa as $nombreEmpresa => $ingresosGrupo)
            @if($ingresosGrupo->isNotEmpty())
                
                @php
                    $rutaLogo = '';
                    if ($nombreEmpresa == 'InTactics') {
                        $rutaLogo = 'images/InTactics - Azul.png';
                    } elseif ($nombreEmpresa == 'INTSHIPPERTS') {
                        $rutaLogo = 'images/Intshipperts.png';
                    } elseif ($nombreEmpresa == 'Transportactics') {
                        $rutaLogo = 'images/Transportactics.png';
                    }
                @endphp

                <!-- TÍTULO DE LA COMPAÑÍA Y SU LOGO -->
                <table width="100%" cellpadding="12" cellspacing="0" border="0" style="margin-top: 10px; margin-bottom: 12px; background-color: #f0f4f8; border-left: 5px solid #002060;">
                    <tr>
                        <td style="vertical-align: middle;">
                            <span style="font-size: 10px; color: #555; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;">Desglose por Servicio</span><br>
                            <h3 style="color: #002060; font-size: 18px; margin: 4px 0 0 0; text-transform: uppercase;">
                                {{ $nombreEmpresa }}
                            </h3>
                        </td>
                        @if($rutaLogo !== '')
                            <td align="right" style="vertical-align: middle; width: 130px;">
                                <img src="{{ $message->embed(public_path($rutaLogo)) }}" alt="{{ $nombreEmpresa }}" width="110" style="display: block;">
                            </td>
                        @endif
                    </tr>
                </table>

                <!-- TABLA ÚNICA DE DESGLOSE POR EMPRESA -->
                <table width="100%" cellpadding="8" cellspacing="0" border="0" style="font-size: 10px; margin-bottom: 40px; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #002060; color: #ffffff; text-align: center;">
                            
                            <!-- Unificamos Fecha y Cuenta para no hacer la tabla tan ancha -->
                            <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">FECHA / CUENTA</th>
                            <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">TOTAL</th>
                            <th style="font-weight: bold; text-align: left; border-right: 1px solid #1a3a7a;">CLIENTE</th>
                            <th style="font-weight: bold; text-align: left; border-right: 1px solid #1a3a7a;">REFERENCIA</th>
                            
                            <!-- ========================================== -->
                            <!-- 1. COLUMNAS EXCLUSIVAS DE INTSHIPPERTS -->
                            <!-- ========================================== -->
                            @if($nombreEmpresa == 'INTSHIPPERTS')
                                <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">ANTICIPO</th>
                                <th style="font-weight: bold;">ALMAN / FLETE</th>
                            
                            <!-- ========================================== -->
                            <!-- 2. COLUMNAS EXCLUSIVAS DE TRANSPORTACTICS -->
                            <!-- ========================================== -->
                            @elseif($nombreEmpresa == 'Transportactics')
                                <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">FLETE (XML)</th>
                                <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">PAGO PROVEEDOR</th>
                                <th style="font-weight: bold; border-right: 1px solid #1a3a7a; background-color: #28a745; color: white;">GANANCIA</th>
                                <th style="font-weight: bold; background-color: #d9534f; color: white;">DIFERENCIA</th>
                            
                            <!-- ========================================== -->
                            <!-- 3. COLUMNAS GENERALES PARA INTACTICS -->
                            <!-- ========================================== -->
                            @else
                                @if($esManzanillo)
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">ANTICIPO</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">GARANTÍAS</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">NAVIERA</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">IMPUES</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">FLETE</th>
                                    <th style="font-weight: bold;">HONOR</th>
                                @else
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">HONOR</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">IMPUES</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">ECI</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">MANIOB</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">FLETE</th>
                                    <th style="font-weight: bold; border-right: 1px solid #1a3a7a;">MUEST</th>
                                    <th style="font-weight: bold;">LLC</th>
                                @endif
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ingresosGrupo as $index => $ingreso)
                            <tr style="background-color: {{ $index % 2 == 0 ? '#ffffff' : '#f9f9f9' }}; text-align: center; border-bottom: 1px solid #eee;">
                                
                                <td style="color: #555;">
                                    {{ \Carbon\Carbon::parse($ingreso->fecha)->format('d/m/Y') }}<br>
                                    <span style="font-size: 8.5px; color: #888; font-weight: bold; text-transform: uppercase;">{{ $ingreso->nombre_banco }}</span>
                                </td>
                                
                                <td style="font-weight: bold; color: #333;">$ {{ number_format($ingreso->monto_deposito, 2) }}</td>
                                <td style="text-align: left; color: #555;">{{ $ingreso->cliente ? $ingreso->cliente->nombre : '--' }}</td>
                                <td style="text-align: left; color: #555;">{{ $ingreso->folio_sc ?: '--' }}</td>
                                
                                <!-- ========================================== -->
                                <!-- 1. CELDAS EXCLUSIVAS DE INTSHIPPERTS -->
                                <!-- ========================================== -->
                                @if($nombreEmpresa == 'INTSHIPPERTS')
                                    <td style="color: #666;">{{ $ingreso->anticipo > 0 ? '$ ' . number_format($ingreso->anticipo, 2) : '$-' }}</td>
                                    <td style="color: #666; font-weight: bold;">{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                                
                                <!-- ========================================== -->
                                <!-- 2. CELDAS EXCLUSIVAS DE TRANSPORTACTICS -->
                                <!-- ========================================== -->
                                @elseif($nombreEmpresa == 'Transportactics')
                                    <td style="color: #666;">{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                                    <td style="color: #666;">{{ $ingreso->pago_proveedor > 0 ? '$ ' . number_format($ingreso->pago_proveedor, 2) : '$-' }}</td>
                                    <td style="color: #28a745; font-weight: bold;">{{ $ingreso->ganancia != 0 ? '$ ' . number_format($ingreso->ganancia, 2) : '$-' }}</td>
                                    
                                    @php
                                        $diferencia = $ingreso->monto_deposito - $ingreso->flete;
                                    @endphp
                                    <td style="font-weight: bold; color: {{ $diferencia != 0 ? '#d9534f' : '#5cb85c' }};">
                                        @if($diferencia != 0)
                                            $ {{ number_format($diferencia, 2) }}
                                        @else
                                            OK
                                        @endif
                                    </td>
                                    
                                <!-- ========================================== -->
                                <!-- 3. CELDAS GENERALES PARA INTACTICS -->
                                <!-- ========================================== -->
                                @else
                                    @if($esManzanillo)
                                        <td style="color: #666;">{{ $ingreso->anticipo > 0 ? '$ ' . number_format($ingreso->anticipo, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->garantias > 0 ? '$ ' . number_format($ingreso->garantias, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->desglose_naviera > 0 ? '$ ' . number_format($ingreso->desglose_naviera, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->impuestos > 0 ? '$ ' . number_format($ingreso->impuestos, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->honorarios > 0 ? '$ ' . number_format($ingreso->honorarios, 2) : '$-' }}</td>
                                    @else
                                        <td style="color: #666;">{{ $ingreso->honorarios > 0 ? '$ ' . number_format($ingreso->honorarios, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->impuestos > 0 ? '$ ' . number_format($ingreso->impuestos, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->eci > 0 ? '$ ' . number_format($ingreso->eci, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->maniobras > 0 ? '$ ' . number_format($ingreso->maniobras, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->muestras > 0 ? '$ ' . number_format($ingreso->muestras, 2) : '$-' }}</td>
                                        <td style="color: #666;">{{ $ingreso->llc > 0 ? '$ ' . number_format($ingreso->llc, 2) : '$-' }}</td>
                                    @endif
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach

    @endforeach

</body>
</html>