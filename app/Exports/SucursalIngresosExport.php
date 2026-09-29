<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class SucursalIngresosExport implements FromView, WithTitle, ShouldAutoSize
{
    protected $sucursalBase;
    protected $porTipoOperacion;
    protected $fechaInicio;
    protected $fechaFin;

    public function __construct($sucursalBase, $porTipoOperacion, $fechaInicio, $fechaFin)
    {
        $this->sucursalBase = $sucursalBase;
        $this->porTipoOperacion = $porTipoOperacion;
        $this->fechaInicio = $fechaInicio;
        $this->fechaFin = $fechaFin;
    }

    public function view(): View
    {
        return view('hoja_sucursal', [
            'sucursal' => $this->sucursalBase,
            'porTipoOperacion' => $this->porTipoOperacion,
            'fechaInicio' => $this->fechaInicio,
            'fechaFin' => $this->fechaFin,
        ]);
    }

    public function title(): string
    {
        $cleanTitle = str_replace(['\\', '/', '?', '*', ':', '[', ']'], '', $this->sucursalBase);
        return substr($cleanTitle, 0, 30) ?: 'Sucursal';
    }
}