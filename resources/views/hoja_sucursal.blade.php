<table>
    <!-- CABECERA PRINCIPAL PARA EXCEL -->
    <tr>
        <td colspan="4"><b style="color: #002060; font-size: 16px;">Reporte Mensual de Ingresos</b></td>
    </tr>
    <tr>
        <td colspan="4">Del: <b>{{ \Carbon\Carbon::parse($fechaInicio)->format('d/m/Y') }}</b> al <b>{{ \Carbon\Carbon::parse($fechaFin)->format('d/m/Y') }}</b></td>
    </tr>
    <tr><td colspan="10"></td></tr>

    <!-- BARRA SUCURSAL (Hoja) -->
    <tr>
        <td colspan="10" style="background-color: #002060; color: #ffffff; text-align: center; font-weight: bold; font-size: 14px;">
            SUCURSAL: {{ strtoupper($sucursal) }}
        </td>
    </tr>
</table>

<!-- BUCLE POR TIPO DE OPERACION (IMPO / EXPO) DENTRO DE LA MISMA HOJA -->
@foreach($porTipoOperacion as $tipoOp => $ingresosTipo)
    @php
        $grupoSucursalStr = strtoupper($sucursal);
        $esManzanillo = str_contains($grupoSucursalStr, 'MANZANILLO');

        // Agrupamos por banco SOLO los de este tipo de operación
        $bancos = $ingresosTipo->groupBy(function ($item) {
            return strtoupper(trim($item->banco_receptor ?: 'SIN ASIGNAR'));
        });

        $ingresosInTactics = collect();
        $ingresosIntshipperts = collect();
        $ingresosTransportactics = collect();

        foreach($bancos as $banco => $ingresosBanco) {
            foreach($ingresosBanco as $ingreso) {
                $ingreso->nombre_banco = $banco ?: 'SIN ASIGNAR';

                $nombreClienteStr = 'SIN CLIENTE';
                $sucursalOrigen = strtoupper($ingreso->sucursal_origen ?? '');
                
                if (!empty($ingreso->cliente_id)) {
                    $modeloCliente = $ingreso->relationLoaded('cliente') ? $ingreso->getRelation('cliente') : null;
                    if ($modeloCliente && isset($modeloCliente->nombre)) {
                        $nombreClienteStr = $modeloCliente->nombre;
                    }
                } else {
                    $textoColumna = $ingreso->getRawOriginal('cliente');
                    if (!empty($textoColumna)) {
                        $nombreClienteStr = $textoColumna;
                    }
                }

                $ingreso->nombre_cliente_calculado = strtoupper($nombreClienteStr);
                
                if (str_contains($grupoSucursalStr, 'INTSHIPPERT') || str_contains($ingreso->nombre_cliente_calculado, 'INTSHIPPERTS') || str_contains($sucursalOrigen, 'INTSHIPPERT')) {
                    $ingresosIntshipperts->push($ingreso);
                } elseif (str_contains($grupoSucursalStr, 'TRANSPORTACTIC') || str_contains($ingreso->nombre_cliente_calculado, 'TRANSPORTACTICS') || str_contains($sucursalOrigen, 'TRANSPORTACTIC')) {
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

    <table>
        <tr><td colspan="10"></td></tr>
        
        <!-- BARRA DIVISORIA DEL TIPO DE OPERACIÓN -->
        <tr>
            <td colspan="10" style="background-color: #ffc000; color: #002060; text-align: center; font-weight: bold; font-size: 13px;">
                OPERACIÓN: {{ $tipoOp }}
            </td>
        </tr>

        <!-- RESUMEN DE TOTALES POR BANCO (Solo para esta operación) -->
        @foreach($bancos as $banco => $ingresosBanco)
            @php $totalBanco = $ingresosBanco->sum('monto_deposito'); @endphp
            <tr>
                <td colspan="2" style="font-weight: bold; background-color: #f0f4f8;">CUENTA: {{ strtoupper($banco ?: 'SIN ASIGNAR') }}</td>
                <td colspan="2" style="color: #002060; font-weight: bold; background-color: #f0f4f8;">${{ number_format($totalBanco, 2) }}</td>
            </tr>
        @endforeach
    </table>

    <!-- AGRUPACIÓN POR EMPRESA PARA ESTA OPERACIÓN -->
    @foreach($gruposEmpresa as $nombreEmpresa => $ingresosGrupo)
        @if($ingresosGrupo->isNotEmpty())
            <table>
                <tr><td colspan="10"></td></tr>
                
                <tr>
                    <td colspan="10" style="background-color: #d9e1f2; font-weight: bold; font-size: 12px; color: #002060; text-align: center;">
                        DESGLOSE POR SERVICIO: {{ strtoupper($nombreEmpresa) }}
                    </td>
                </tr>

                <tr style="background-color: #002060; color: #ffffff; font-weight: bold; text-align: center;">
                    <th>FECHA / CUENTA</th>
                    <th>TOTAL</th>
                    <th>CLIENTE</th>
                    <th>REFERENCIA</th>
                    
                    @if($nombreEmpresa == 'INTSHIPPERTS')
                        <th>ANTICIPO</th>
                        <th>ALMAN / FLETE</th>
                    @elseif($nombreEmpresa == 'Transportactics')
                        <th>FLETE (XML)</th>
                        <th>PAGO PROVEEDOR</th>
                        <th style="background-color: #28a745; color: #ffffff;">GANANCIA</th>
                        <th style="background-color: #d9534f; color: #ffffff;">DIFERENCIA</th>
                    @else
                        @if($esManzanillo)
                            <th>ANTICIPO</th>
                            <th>GARANTÍAS</th>
                            <th>NAVIERA</th>
                            <th>IMPUES</th>
                            <th>FLETE</th>
                            <th>HONOR</th>
                        @else
                            <th>HONOR</th>
                            <th>IMPUES</th>
                            <th>ECI</th>
                            <th>MANIOB</th>
                            <th>FLETE</th>
                            <th>MUEST</th>
                            <th>LLC</th>
                        @endif
                    @endif
                </tr>

                @foreach($ingresosGrupo as $index => $ingreso)
                    <tr style="background-color: {{ $index % 2 == 0 ? '#ffffff' : '#f9f9f9' }}; text-align: center;">
                        
                        <td>{{ \Carbon\Carbon::parse($ingreso->fecha)->format('d/m/Y') }} - {{ $ingreso->nombre_banco }}</td>
                        <td style="font-weight: bold;">$ {{ number_format($ingreso->monto_deposito, 2) }}</td>
                        <td style="text-align: left;">{{ $ingreso->nombre_cliente_calculado }}</td>
                        <td style="text-align: left;">{{ $ingreso->folio_sc ?: '--' }}</td>
                        
                        @if($nombreEmpresa == 'INTSHIPPERTS')
                            <td>{{ $ingreso->anticipo > 0 ? '$ ' . number_format($ingreso->anticipo, 2) : '$-' }}</td>
                            <td>{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                        
                        @elseif($nombreEmpresa == 'Transportactics')
                            <td>{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                            <td>{{ $ingreso->pago_proveedor > 0 ? '$ ' . number_format($ingreso->pago_proveedor, 2) : '$-' }}</td>
                            <td style="color: #28a745; font-weight: bold;">{{ $ingreso->ganancia != 0 ? '$ ' . number_format($ingreso->ganancia, 2) : '$-' }}</td>
                            
                            @php $diferencia = $ingreso->monto_deposito - $ingreso->flete; @endphp
                            <td style="font-weight: bold; color: {{ $diferencia != 0 ? '#d9534f' : '#5cb85c' }};">
                                {{ $diferencia != 0 ? '$ ' . number_format($diferencia, 2) : 'OK' }}
                            </td>
                            
                        @else
                            @if($esManzanillo)
                                <td>{{ $ingreso->anticipo > 0 ? '$ ' . number_format($ingreso->anticipo, 2) : '$-' }}</td>
                                <td>{{ $ingreso->garantias > 0 ? '$ ' . number_format($ingreso->garantias, 2) : '$-' }}</td>
                                <td>{{ $ingreso->desglose_naviera > 0 ? '$ ' . number_format($ingreso->desglose_naviera, 2) : '$-' }}</td>
                                <td>{{ $ingreso->impuestos > 0 ? '$ ' . number_format($ingreso->impuestos, 2) : '$-' }}</td>
                                <td>{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                                <td>{{ $ingreso->honorarios > 0 ? '$ ' . number_format($ingreso->honorarios, 2) : '$-' }}</td>
                            @else
                                <td>{{ $ingreso->honorarios > 0 ? '$ ' . number_format($ingreso->honorarios, 2) : '$-' }}</td>
                                <td>{{ $ingreso->impuestos > 0 ? '$ ' . number_format($ingreso->impuestos, 2) : '$-' }}</td>
                                <td>{{ $ingreso->eci > 0 ? '$ ' . number_format($ingreso->eci, 2) : '$-' }}</td>
                                <td>{{ $ingreso->maniobras > 0 ? '$ ' . number_format($ingreso->maniobras, 2) : '$-' }}</td>
                                <td>{{ $ingreso->flete > 0 ? '$ ' . number_format($ingreso->flete, 2) : '$-' }}</td>
                                <td>{{ $ingreso->muestras > 0 ? '$ ' . number_format($ingreso->muestras, 2) : '$-' }}</td>
                                <td>{{ $ingreso->llc > 0 ? '$ ' . number_format($ingreso->llc, 2) : '$-' }}</td>
                            @endif
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

@endforeach