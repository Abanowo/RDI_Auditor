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

class ImpuestosSheet implements FromCollection, WithHeadings,WithTitle, WithMapping,
WithColumnWidths, ShouldAutoSize, WithColumnFormatting, WithStyles, WithEvents, WithStrictNullComparison
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
        $data = $this->operaciones->map(function ($pedimento) {
            $operacion = $pedimento->importacion ? $pedimento->importacion : $pedimento->exportacion;

            $sc = $operacion ? $operacion->auditoriasTotalSC : null;

            if (!$sc) {
                $sc = \App\Models\AuditoriaTotalSC::where('pedimento_id', $pedimento->id_pedimiento)->first();
            }

            $facturaImpuestos = \App\Models\Auditoria::where('pedimento_id', $pedimento->id_pedimiento)
                ->where('tipo_documento', 'impuestos')
                ->first();

            // Si no hay ninguno de los dos, descartamos la fila
            if (!$facturaImpuestos && !$sc) {
                return null;
            }

            return [
                'pedimento' => $pedimento->num_pedimiento,
                'cliente'   => ($operacion && $operacion->cliente) ? $operacion->cliente : null,
                'impuestos' => $facturaImpuestos,
                'sc'        => $sc,
            ];
        })->filter();

        // Cálculos con validación de índice isset()
        $totalFactura = $data->sum(function($row) {
            return (isset($row['impuestos']) && $row['impuestos']) ? (float) $row['impuestos']->monto_total_mxn : 0;
        });

        $totalSC = $data->sum(function($row) {
            if (isset($row['sc']) && $row['sc'] && isset($row['sc']->desglose_conceptos['montos']['impuestos_mxn'])) {
                return (float) $row['sc']->desglose_conceptos['montos']['impuestos_mxn'];
            }
            return 0;
        });

        $data->push([
            'pedimento' => 'TOTALES',
            'cliente'   => (object) ['nombre' => ''],
            'impuestos' => (object) [
                'monto_total' => $totalFactura, 
                'monto_total_mxn' => $totalFactura
            ],
            'sc'        => (object) [
                'desglose_conceptos' => [
                    'montos' => [
                        'impuestos_mxn' => $totalSC
                    ]
                ], 
                'folio' => ''
            ]
        ]);

        return $data;
    }

    public function title(): string
    {
        return "Impuestos";
    }

    /**
     * Define las cabeceras de las columnas.
     */
    public function headings(): array
    {
         return
         [
            'Fecha', 'Pedimento', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            'Monto SC', 'Monto SC MXN', 'Moneda', 'Folio SC', 'Estado', 'PDF Factura', 'PDF SC'
        ];
    }

    /**
     * Mapea los datos de cada operación a las columnas del Excel.
     */
    public function map($row): array
    {
        $pedimento = isset($row['pedimento']) ? $row['pedimento'] : '';
        $esTotales = ($pedimento === 'TOTALES');

        if ($esTotales) {
            return [
                '', 'Totales:', '',
                (float) (isset($row['impuestos']) ? $row['impuestos']->monto_total : 0),
                (float) (isset($row['impuestos']) ? $row['impuestos']->monto_total_mxn : 0),
                (float) (isset($row['sc']->desglose_conceptos['montos']['impuestos']) ? $row['sc']->desglose_conceptos['montos']['impuestos'] : 0),
                (float) (isset($row['sc']->desglose_conceptos['montos']['impuestos_mxn']) ? $row['sc']->desglose_conceptos['montos']['impuestos_mxn'] : 0),
                '', '', '', '', ''
            ];
        }

        $facturaImpuestos = isset($row['impuestos']) ? $row['impuestos'] : null;
        $sc = isset($row['sc']) ? $row['sc'] : null;
        $cliente = isset($row['cliente']) ? $row['cliente'] : null;

        // Lógica de SC
        $montoSc = 0; $montoScMxn = 0; $folioSc = 'N/A'; $pdfSc = 'Sin PDF!';
        $estado = $facturaImpuestos ? $facturaImpuestos->estado : 'Sin SC!';

        if ($sc) {
            $montos = isset($sc->desglose_conceptos['montos']) ? $sc->desglose_conceptos['montos'] : [];
            $montoSc = (float)(isset($montos['impuestos']) ? $montos['impuestos'] : 0);
            $montoScMxn = (float)(isset($montos['impuestos_mxn']) ? $montos['impuestos_mxn'] : 0);
            $folioSc = $sc->folio;
            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }
        }

        $urlFactura = route('documentos.ver', [
            'tipo' => 'impuestos',
            'id' => $facturaImpuestos ? $facturaImpuestos->id : 0
        ]);

        return [
            $facturaImpuestos ? $facturaImpuestos->fecha_documento : '',
            $pedimento,
            $cliente ? $cliente->nombre : '',
            (float) ($facturaImpuestos ? $facturaImpuestos->monto_total : 0),
            (float) ($facturaImpuestos ? $facturaImpuestos->monto_total_mxn : 0),
            $montoSc,
            $montoScMxn,
            $facturaImpuestos ? $facturaImpuestos->moneda_documento : 'MXN',
            $folioSc,
            $estado,
            ($facturaImpuestos && $facturaImpuestos->ruta_pdf) ? '=HYPERLINK("' . $urlFactura . '", "Acceder PDF")' : 'Sin PDF!',
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
            'F' => 15, 'G' => 15, 'H' => 20, 'I' => 15, 'J' => 18,
            'K' => 15, 'L' => 15,
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

                // 6. MODIFICACIÓN: La columna de estado ahora es la 'J'
                $statusColumn = 'J';

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
                            $sheet->getStyle('I'.$rowIndex.':J'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('L'.$rowIndex)->applyFromArray($scStyle);

                        } else {
                            // Lógica de colores que ya teníamos para las demás filas
                            switch ($estado) {
                                case 'Coinciden!':     $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]]); break;
                                case 'EXPO':           $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDDEBF7']]]); break;
                                case 'Pago de menos!': $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); break;
                                case 'Pago de mas!':   $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF974700']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFCC99']]]); break;
                            }
                        }

                        if (!empty($styleArray)) {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        //Subrayado para Hyperlinks
                        foreach (['K', 'L'] as $col) {
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
