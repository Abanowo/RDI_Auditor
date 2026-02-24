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
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ManiobraSheet implements FromCollection, WithHeadings, WithTitle, WithMapping,
WithColumnWidths, ShouldAutoSize, WithColumnFormatting, WithStyles, WithEvents,
WithStrictNullComparison
{
    protected $operaciones;

    public function __construct(Collection $operaciones)
    {
        $this->operaciones = $operaciones;
    }

    public function collection()
    {
        $data = $this->operaciones->flatMap(function ($pedimento) {
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;
            if (!$operacion) return null; 

            $maniobras = $operacion->auditorias->where('tipo_documento', 'maniobras');
            if ($maniobras->isEmpty()) return null;

            $sc = $operacion->auditoriasTotalSC;
            $cliente = $operacion->cliente;

            return $maniobras->map(function ($maniobra) use ($pedimento, $sc, $cliente) {
                return [
                    'pedimento' => $pedimento->num_pedimiento,
                    'cliente'   => $cliente ?? null,
                    'maniobra'  => $maniobra,
                    'sc'        => $sc,
                ];
            });
        });

        // Totales
        $totalMontoFactura = 0;
        $totalMontoFacturaMxn = 0;
        $totalMontoSc = 0;
        $totalMontoScMxn = 0;

        foreach ($data as $row) {
            $factura = $row['maniobra'] ?? null;
            $sc = $row['sc'] ?? null;

            $totalMontoFactura += (float) optional($factura)->monto_total;
            $totalMontoFacturaMxn += (float) optional($factura)->monto_total_mxn;

            if ($sc) {
                $desgloseSc = $sc->desglose_conceptos;
                $totalMontoSc += (float)($desgloseSc['montos']['maniobras'] ?? 0);
                $totalMontoScMxn += (float)($desgloseSc['montos']['maniobras_mxn'] ?? 0);
            }
        }

        $data->push([
            'pedimento' => 'TOTALES',
            'cliente'   => (object) ['nombre' => ''],
            'maniobra'  => (object) [
                'monto_total' => $totalMontoFactura,
                'monto_total_mxn' => $totalMontoFacturaMxn,
            ],
            'sc' => (object) [
                'desglose_conceptos' => [
                    'montos' => [
                        'maniobras' => $totalMontoSc,
                        'maniobras_mxn' => $totalMontoScMxn,
                    ]
                ],
                'folio' => '',
                'ruta_pdf' => null
            ]
        ]);

        return $data;
    }

    public function title(): string
    {
        return "Maniobras";
    }

    public function headings(): array
    {
        return [
            'Fecha', 'Pedimento', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            'Monto SC', 'Monto SC MXN', 'Moneda', 'Folio SC', 'Estado', 'PDF Factura', 'PDF SC'
        ];
    }

    public function map($row): array
    {
        $esTotales = isset($row['pedimento']) && $row['pedimento'] === 'TOTALES';

        if ($esTotales) {
            return [
                '', 'Totales:', '',
                (float) optional($row['maniobra'])->monto_total,
                (float) optional($row['maniobra'])->monto_total_mxn,
                (float) optional($row['sc'])->desglose_conceptos['montos']['maniobras'] ?? 0,
                (float) optional($row['sc'])->desglose_conceptos['montos']['maniobras_mxn'] ?? 0,
                '', '', '', '', ''
            ];
        }

        $facturaManiobra = $row['maniobra'];
        $sc = $row['sc'];
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;

        $montoSc = 'N/A';
        $montoScMxn = 'N/A';
        $folioSc = 'N/A';
        $pdfSc = 'Sin PDF!';
        $estado = optional($facturaManiobra)->estado;

        if ($sc) {
            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            
            $montoSc = (float)($montosSc['maniobras'] ?? 0);
            $montoScMxn = (float)($montosSc['maniobras_mxn'] ?? 0);
            
            $folioSc = $sc->folio;
            if ($sc->ruta_pdf) $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
        } else {
             $estado = 'Sin SC!';
        }

        $monedaConTC = optional($facturaManiobra)->moneda_documento;
        if ($monedaConTC === 'USD' && isset($desgloseSc['tipo_cambio'])) {
            $monedaConTC = "USD (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";
        }

        $pdfFactura = optional($facturaManiobra)->ruta_pdf
            ? '=HYPERLINK("' . $facturaManiobra->ruta_pdf . '", "Acceder PDF")'
            : 'Sin PDF!';

        return [
            optional($facturaManiobra)->fecha_documento,
            $pedimento,
            optional($cliente)->nombre,
            (float) optional($facturaManiobra)->monto_total,
            (float) optional($facturaManiobra)->monto_total_mxn,
            $montoSc,
            $montoScMxn,
            $monedaConTC,
            $folioSc,
            $estado,
            $pdfFactura,
            $pdfSc,
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 15, 'C' => 30, 'D' => 15, 'E' => 15,
            'F' => 15, 'G' => 15, 'H' => 20, 'I' => 15, 'J' => 18,
            'K' => 15, 'L' => 15,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setHorizontal('center');
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF244062']],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();
                $statusColumn = 'J';

                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:' . $lastColumn . '1');

                if ($sheet->getHighestRow() >= 2) {
                    foreach ($sheet->getRowIterator(2) as $row) { 
                        $rowIndex = $row->getRowIndex();
                        $estado = $sheet->getCell($statusColumn . $rowIndex)->getValue();
                        $styleArray = [];
                        
                        $styleArray['borders'] = [
                            'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']],
                            'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']]
                        ];

                        if ($estado === 'Sin SC!') {
                            $dataStyle = ['font' => ['color' => ['argb' => 'FF1F497D']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE6F1']]];
                            $scStyle = ['font' => ['color' => ['argb' => 'FF646464']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']]];
                            
                            $sheet->getStyle('A'.$rowIndex.':E'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('H'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('K'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('F'.$rowIndex.':G'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('I'.$rowIndex.':J'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('L'.$rowIndex)->applyFromArray($scStyle);

                        } else {
                            switch ($estado) {
                                case 'Coinciden!': 
                                case 'Normal':     
                                    $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEBF1DE']]]); 
                                    break;
                                case 'Pago de mas!':
                                case 'Pago de menos!':
                                case 'Sin Maniobras!':
                                    $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); 
                                    break;
                            }
                        }

                        if (!empty($styleArray)) {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        foreach (['K', 'L'] as $col) {
                            $cell = $sheet->getCell($col . $rowIndex);
                            if (is_string($cell->getValue()) && str_starts_with($cell->getValue(), '=HYPERLINK')) {
                                $cell->getStyle()->applyFromArray(['font' => ['bold' => true, 'underline' => Font::UNDERLINE_SINGLE]]);
                            }
                        }
                    }
                }
            },
        ];
    }
}