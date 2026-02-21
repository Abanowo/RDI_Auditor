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
use App\Exports\MuestraSheet;

class AuditoriaFacturadoExport implements WithMultipleSheets
{
    protected $operaciones;
    protected $descartados;
    protected $banco;
    protected $urlSheet;

    /**
     * @param Collection $operaciones
     * @param array|null $pedimentosDescartadosArray
     * @param string|null $banco
     * @param string|null $urlSheet
     */
    public function __construct(Collection $operaciones, $pedimentosDescartadosArray = null, $banco = null, $urlSheet = null)
    {
        $this->operaciones = $operaciones;
        
        // Asignamos las variables a la clase
        $this->banco = $banco;
        $this->urlSheet = $urlSheet;

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
            'Impuestos' => new ImpuestosSheet($this->operaciones, $this->banco, $this->urlSheet),
            'Fletes' => new FletesSheet($this->operaciones),
            'LLC' => new LLCSheet($this->operaciones),
            'Pagos_derecho' => new PagosDerechoSheet($this->operaciones),
            'Muestras' => new MuestraSheet($this->operaciones),
        ];

        if (!empty($this->descartados)) {
            $sheets['Descartados'] = new PedimentosDescartadosSheet($this->descartados);
        }

        return $sheets;
    }
}