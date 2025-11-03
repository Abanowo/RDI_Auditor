<?php

namespace App\Exports;

use App\Models\Importacion;
use App\Models\Pedimento;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use App\Exports\PedimentosDescartadosSheet;
use App\Exports\SCSheet; 
use App\Exports\ImpuestosSheet;
use App\Exports\FletesSheet;
use App\Exports\LLCSheet;
use App\Exports\PagosDerechoSheet;

class AuditoriaFacturadoExport implements WithMultipleSheets
{
    protected $operaciones;
    protected $descartados;

    /**
     * @param Collection $operaciones
     * @param array|null $pedimentosDescartadosArray
     */
    public function __construct(Collection $operaciones, $pedimentosDescartadosArray = null)
    {
        $this->operaciones = $operaciones;

        if ($pedimentosDescartadosArray) {
            $this->descartados = array_keys($pedimentosDescartadosArray);
        } else {
            $this->descartados = [];
        }
    }

    public function sheets(): array
    {
        $sheets = [
            'SC' => new SCSheet($this->operaciones),
            'Impuestos' => new ImpuestosSheet($this->operaciones),
            'Fletes' => new FletesSheet($this->operaciones),
            'LLC' => new LLCSheet($this->operaciones),
            'Pagos_derecho' => new PagosDerechoSheet($this->operaciones),
        ];

        if (!empty($this->descartados)) {
            $sheets['Descartados'] = new PedimentosDescartadosSheet($this->descartados);
        }

        return $sheets;
    }
}