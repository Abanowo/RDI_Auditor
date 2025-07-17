<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class LecturaEstadoCuentaExcel implements ToCollection
{

    /**
     * @var \Illuminate\Support\Collection
     */
    private $processedData;

    /**
     * @var array|null
     */
    private $headerMap = null;

    /**
    * @param Collection $collection
    */
    public function __construct()
    {
        $this->processedData = new Collection();
    }

    /**
    * @param Collection $rows
    */
    public function collection(Collection $rows)
    {
        $dataRows = $rows;

        // Esta lógica solo se ejecuta una vez (para el primer trozo de datos).
        if (is_null($this->headerMap)) {
            $firstRow = $rows->first();
            // Normalizamos la primera fila para una comparación segura (minúsculas y sin espacios)
            $potentialHeaders = collect($firstRow)->map(function ($cell) { return trim(strtolower($cell ?? '')); } )->all();

            // Buscamos los índices de nuestros encabezados
            $pedimentoIndex = array_search('pedimento', $potentialHeaders);
            $fechaIndex = array_search('fecha de pago', $potentialHeaders);
            $descargoIndex = array_search('descargo', $potentialHeaders);

            // Si encontramos todos los encabezados...
            if ($pedimentoIndex !== false && $fechaIndex !== false && $descargoIndex !== false) {
                // ...guardamos sus posiciones.
                $this->headerMap = [
                    'pedimento' => $pedimentoIndex,
                    'fecha_pago' => $fechaIndex,
                    'descargo' => $descargoIndex,
                ];
                // Y nos saltamos la primera fila porque es un encabezado.
                $dataRows = $rows->slice(1);
            } else {
                // Si no, asumimos que las columnas son A, B y C (índices 0, 1, 2).
                $this->headerMap = [
                    'pedimento' => 0,
                    'fecha_pago' => 1,
                    'descargo' => 2,
                ];
                // Y procesamos todas las filas, incluyendo la primera.
            }
        }

        // Mapeamos las filas de datos usando el mapa de columnas que ya determinamos.
        $mappedRows = $dataRows->map(function ($row) {
            // Usamos el mapa para obtener los datos de la columna correcta.
            $pedimento = $row[$this->headerMap['pedimento']] ?? null;

            // Si no hay pedimento, descartamos la fila.
            if (!$pedimento) {
                return null;
            }

            if (!preg_match('/\d{7}/', $pedimento)) {
                return null;
            }

            $fecha = $row[$this->headerMap['fecha_pago']] ?? null;
            $descargo = $row[$this->headerMap['descargo']] ?? null;

            // Volvemos a hacer la conversión manual de fecha, por si es un número de serie.
            if (is_numeric($fecha)) {
                $fecha = Date::excelToDateTimeObject($fecha)->format('Y-m-d');
            }

            return [
                'pedimento'     => $pedimento,
                'fecha_str'     => $fecha,
                'cargo_str'     => $descargo,
            ];
        })->filter(); // El ->filter() sin argumentos elimina las filas nulas.

        // Usamos merge para acumular resultados en caso de que el archivo se lea en trozos.
        $this->processedData = $this->processedData->merge($mappedRows);
    }


    /**
     * Método para obtener los datos ya procesados.
     * @return Collection
     */
    public function getProcessedData(): Collection
    {
        return $this->processedData;
    }
}
