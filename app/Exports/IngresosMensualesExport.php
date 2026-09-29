<?php

namespace App\Exports;

use App\Models\IngresoConciliado;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\Exportable;

class IngresosMensualesExport implements WithMultipleSheets
{
    use Exportable;

    protected $fechaInicio;
    protected $fechaFin;

    public function __construct($fechaInicio, $fechaFin)
    {
        $this->fechaInicio = $fechaInicio;
        $this->fechaFin = $fechaFin;
    }

    public function sheets(): array
    {
        $sheets = [];

        // Consultamos todos los ingresos del mes
        $ingresos = IngresoConciliado::with('cliente')
            ->whereDate('fecha', '>=', $this->fechaInicio)
            ->whereDate('fecha', '<=', $this->fechaFin)
            ->orderBy('fecha', 'asc')
            ->get();

        // 1. Agrupamos por SUCURSAL BASE (ej. 'NOGALES' en lugar de 'NOGALES IMPO')
        $ingresosPorSucursalBase = $ingresos->groupBy(function ($item) {
            $sucursalRaw = strtoupper(trim($item->sucursal_origen ?: 'SIN SUCURSAL'));
            // Quitamos la terminación IMPO/EXPO para obtener la ciudad real
            return trim(str_replace([' IMPO', ' EXPO'], '', $sucursalRaw));
        });

        foreach ($ingresosPorSucursalBase as $sucursalBase => $ingresosSucursal) {
            
            // 2. Dentro de esa Sucursal Base, agrupamos por TIPO DE OPERACIÓN
            $porTipoOperacion = $ingresosSucursal->groupBy(function ($item) {
                $sucursalRaw = strtoupper(trim($item->sucursal_origen ?: 'SIN SUCURSAL'));
                if (str_contains($sucursalRaw, 'IMPO')) {
                    return 'IMPORTACIÓN';
                }
                if (str_contains($sucursalRaw, 'EXPO')) {
                    return 'EXPORTACIÓN';
                }
                return 'GENERAL';
            });

            // Creamos la pestaña de la Sucursal Base enviando la data segmentada por operación
            $sheets[] = new SucursalIngresosExport($sucursalBase, $porTipoOperacion, $this->fechaInicio, $this->fechaFin);
        }

        return $sheets;
    }
}