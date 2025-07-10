<?php

namespace App\Exports;
use App\Models\Importacion;
use App\Models\Pedimento;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AuditoriaFacturadoExport implements WithMultipleSheets
{
    protected $operaciones;
    public function __construct(Collection $operaciones)
        {
            $this->operaciones = $operaciones;
        }

    public function sheets(): array
        {
            // Aquí definimos qué clase controlará cada hoja del Excel
            return [
                'SC' => new SCSheet($this->operaciones),
                'Impuestos' => new ImpuestosSheet($this->operaciones),
                'Fletes' => new FletesSheet($this->operaciones),
                'LLC' => new LlcSheet($this->operaciones),
                'Pagos_derecho' => new PagosDerechoSheet($this->operaciones),
                // ... y así sucesivamente para las demás hojas */
            ];
        }
}
