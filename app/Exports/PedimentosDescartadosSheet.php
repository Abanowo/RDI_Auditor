<?php

namespace App\Exports; // Asegúrate que el namespace coincida con tu carpeta

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PedimentosDescartadosSheet implements FromCollection, WithTitle, WithHeadings
{
    protected $descartados;

    public function __construct(array $descartados)
    {
        // Convertimos el array simple en una colección de colecciones
        // para que Maatwebsite/Excel pueda imprimirlo en filas
        $this->descartados = collect($descartados)->map(function ($pedimento) {
            return [$pedimento]; // Cada pedimento será una fila
        });
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->descartados;
    }

    /**
     * @return string
     */
    public function title(): string
    {
        // Este será el nombre de la pestaña en Excel
        return 'Pedimentos Descartados';
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        // Esta será la cabecera de la columna A
        return [
            'Pedimentos No Encontrados en la Base de Datos',
        ];
    }
}