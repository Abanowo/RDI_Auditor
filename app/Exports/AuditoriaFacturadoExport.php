<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AuditoriaFacturadoExport implements WithMultipleSheets
{
    protected $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    /**
     * @return array
     */
    public function sheets(): array
    {
        // Aquí definimos qué clase controlará cada hoja del Excel
        return [
            'SC' => new SCSheet($this->filters),
            'Impuestos' => new ImpuestosSheet($this->filters),
            'Fletes' => new FletesSheet($this->filters),
            'LLC' => new LlcSheet($this->filters),
            'Pagos_derecho' => new PagosDerechoSheet($this->filters),
            // ... y así sucesivamente para las demás hojas */
        ];
    }
}
