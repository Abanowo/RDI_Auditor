<?php
namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FletesSheet implements FromCollection, WithHeadings,WithTitle, WithMapping,
WithColumnWidths, ShouldAutoSize, WithColumnFormatting, WithStyles, WithEvents,
WithStrictNullComparison
{
    protected $operaciones;

    public function __construct(Collection $operaciones)
    {
        $this->operaciones = $operaciones;
    }

    /**
     * Define la consulta a la base de datos, incluyendo filtros.
     */

    // El método collection() simplemente devuelve los datos que ya tenemos
    public function collection()
    {
        // Usamos map para transformar cada pedimento y luego filter para limpiar los nulos.
        return $this->operaciones->map(function ($pedimento) {
            // Primero, nos aseguramos de que las relaciones necesarias existan para evitar errores.
            if (!$pedimento->importacion || !$pedimento->importacion->auditorias) {
                if (!$pedimento->exportacion || !$pedimento->exportacion->auditorias) {
                return null;
                }
            }

            // 1. Primero, determinamos de forma segura cuál operación existe
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;

            // Si por alguna razón un pedimento no tiene ni impo ni expo, lo saltamos
            if (!$operacion) {
                return null; // Será eliminado por ->filter() más adelante
            }


            // CAMBIO CLAVE: Usamos firstWhere para obtener un único modelo o null.
            // Esto buscará en la colección 'auditorias' el primer registro
            // donde 'tipo_documento' sea 'Fletes'.
            $facturaFletes = $operacion->auditorias->firstWhere('tipo_documento', 'flete');

            // Si no se encuentra una factura de Fletes para este pedimento, devolvemos null.
            // El método ->filter() posterior se encargará de eliminar esta entrada.
            if (!$facturaFletes) {
                return null;
            }

            // 2. Ahora que sabemos que $operacion existe, accedemos a sus relaciones de forma segura
            $sc = $operacion->auditoriasTotalSC;
            $cliente = $operacion->cliente;

            // Devolvemos el array con los datos listos para el método map() del export.
            // Ahora 'Fletes' será un objeto del modelo Auditoria.
            return
            [
                'pedimento' => $pedimento->num_pedimiento,
                'cliente'   => $cliente,
                'flete'     => $facturaFletes, // ¡Correcto! Ahora es un objeto App\Models\Auditoria
                'sc'        => $sc,
            ];
        })->filter(); // Eliminamos todas las entradas que devolvieron null.

    }

    public function title(): string
    {
        return "Fletes";
    }

    /**
     * Define las cabeceras de las columnas.
     */
    public function headings(): array
    {
        return
        [
            'Fecha', 'Pedimento', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            'Monto SC', 'Monto SC MXN', 'Moneda','Folio Factura', 'Folio SC', 'Estado', 'PDF Factura', 'PDF SC'
        ];
    }

    /**
     * Mapea los datos de cada operación a las columnas del Excel.
     */
    public function map($row): array
    {
        $facturaFletes = $row['flete'] ?? null;
        $sc = $row['sc'] ?? null;
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;

        // Lógica para manejar valores de la SC cuando no existe
        $montoSc = 'N/A';
        $montoScMxn = 'N/A';
        $folioSc = 'N/A';
        $pdfSc = 'Sin PDF!';
        $estado = optional($facturaFletes)->estado;

        if ($sc) {
            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            $montoSc = (float)($montosSc['flete'] ?? 0);
            $montoScMxn = (float)($montosSc['flete_mxn'] ?? 0);
            $folioSc = $sc->folio;

            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }

        } else {
             $estado = 'Sin SC!';
        }

        $monedaConTC = optional($facturaFletes)->moneda_documento;
        if ($monedaConTC === 'USD' && isset($desgloseSc['tipo_cambio'])) {
            $monedaConTC = "USD (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";
        }


        $pdfFactura = optional($facturaFletes)->ruta_pdf
            ? '=HYPERLINK("' . $facturaFletes->ruta_pdf . '", "Acceder PDF")'
            : 'Sin PDF!';

        return
        [
            optional($facturaFletes)->fecha_documento,
            $pedimento,
            $cliente->nombre,
            (float) $facturaFletes->monto_total,
            (float) $facturaFletes->monto_total_mxn,
            $montoSc,
            $montoScMxn,
            $monedaConTC,
            $facturaFletes->folio,
            $folioSc,
            $estado,
            $pdfFactura,
            $pdfSc,
        ];
    }
    /**
     * Define anchos específicos para cada columna.
     */
     public function columnWidths(): array
    {
        return
        [
            'A' => 12, 'B' => 15, 'C' => 30, 'D' => 15, 'E' => 15,
            'F' => 15, 'G' => 15, 'H' => 20, 'I' => 15, 'J' => 15,
            'K' => 18, 'L' => 15, 'M' => 15,
        ];
    }
    /**
     * Define los formatos de número para columnas específicas.
     */
    public function columnFormats(): array
    {
        return
        [
            'D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // Formato #,##0.00
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }

    /**
     * Aplica estilos generales a la hoja.
     */
    public function styles(Worksheet $sheet)
    {
        // Centra todo el contenido de todas las celdas
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setHorizontal('center');

        // Estilo para la cabecera
        return
        [
            1 =>
            [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF244062']],
            ],
        ];
    }

    /**
     * Registra eventos. Usaremos AfterSheet para aplicar estilos condicionales.
     */
    public function registerEvents(): array
    {
        return
        [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();

                // 6. MODIFICACIÓN: La columna de estado ahora es la 'K'
                $statusColumn = 'K';

                // Inmoviliza la fila 1 (los encabezados) para que no se mueva al hacer scroll.
                // 'A2' le dice a Excel que congele todo lo que está por encima y a la izquierda de la celda A2.
                $sheet->freezePane('A2');

                $sheet->setAutoFilter('A1:' . $lastColumn . '1');

                if ($sheet->getHighestRow() >= 2) {

                    foreach ($sheet->getRowIterator(2) as $row) { // Empezamos desde la fila 2

                        $rowIndex = $row->getRowIndex();
                        $estado = $sheet->getCell($statusColumn . $rowIndex)->getValue();
                        $styleArray = [];
                        $styleArray['borders'] =
                        [
                            'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']],
                            'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']]
                        ];

                        //Estilo especial para filas "Sin SC!"
                        if ($estado === 'Sin SC!') {
                            $dataStyle =
                            [
                                'font' => ['color' => ['argb' => 'FF1F497D']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE6F1']],
                            ];
                            $scStyle =
                            [
                                'font' => ['color' => ['argb' => 'FF646464']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']],
                            ];

                            // Aplicamos los estilos a las celdas correspondientes
                            $sheet->getStyle('A'.$rowIndex.':E'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('H'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('K'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('F'.$rowIndex.':G'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('I'.$rowIndex.':K'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('L'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('M'.$rowIndex)->applyFromArray($scStyle);

                        } else {
                            // Lógica de colores que ya teníamos para las demás filas
                            switch ($estado) {
                                case 'Coinciden!':     $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]]); break;
                                case 'Sin Flete!':
                                case 'Pago de menos!': $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); break;
                                case 'Pago de mas!':   $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF974700']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFCC99']]]); break;
                            }
                        }

                        if (!empty($styleArray)) {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        //Subrayado para Hyperlinks
                        foreach (['L', 'M'] as $col) {
                            $cell = $sheet->getCell($col . $rowIndex);
                            if (is_string($cell->getValue()) && str_starts_with($cell->getValue(), '=HYPERLINK')) {
                                $cell->getStyle()->applyFromArray(
                                    ['font' =>
                                        [
                                            'bold' => true,
                                            'underline' => Font::UNDERLINE_SINGLE,
                                        ],
                                    ]
                                );
                            }
                        }
                    }
                }
            },
        ];
    }
}
