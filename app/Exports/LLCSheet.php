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

class LLCSheet implements FromCollection, WithHeadings,WithTitle, WithMapping,
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
        $data = $this->operaciones->map(function ($pedimento) {
            if (!$pedimento->importacion || !$pedimento->importacion->auditorias) {
                if (!$pedimento->exportacion || !$pedimento->exportacion->auditorias) {
                    return null;
                }
            }
            
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;

            if (!$operacion) {
                return null; 
            }

            $facturaLLCs = $operacion->auditorias->firstWhere('tipo_documento', 'llc');

            if (!$facturaLLCs) {
                return null;
            }

            $sc = $operacion->auditoriasTotalSC;
            $cliente = $operacion->cliente;
            $tipo = $pedimento->importacion ? 'Importación' : 'Exportación';

            return [
                'tipo' => $tipo,
                'pedimento' => $pedimento->num_pedimiento,
                'cliente' => $cliente,
                'llc' => $facturaLLCs, 
                'sc' => $sc,
            ];
        })->filter(); 

        $totalMontoFactura = 0;
        $totalMontoFacturaMxn = 0;
        $totalMontoSc = 0;
        $totalMontoScMxn = 0;

        foreach ($data as $row) {
            $factura = $row['llc'] ?? null;
            $sc = $row['sc'] ?? null;

            $totalMontoFactura += (float) optional($factura)->monto_total;
            $totalMontoFacturaMxn += (float) optional($factura)->monto_total_mxn;

            if ($sc) {
                $desgloseSc = $sc->desglose_conceptos;
                $totalMontoSc += (float)($desgloseSc['montos']['llc'] ?? 0);
                $totalMontoScMxn += (float)($desgloseSc['montos']['llc_mxn'] ?? 0);
            }
        }

        $data->push([
            'tipo' => '',
            'pedimento' => 'TOTALES',
            'cliente' => (object) ['nombre' => ''],
            'llc' => (object) [
                'monto_total' => $totalMontoFactura,
                'monto_total_mxn' => $totalMontoFacturaMxn,
            ],
            'sc' => (object) [
                'desglose_conceptos' => [
                    'montos' => [
                        'llc' => $totalMontoSc,
                        'llc_mxn' => $totalMontoScMxn,
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
        return "LLC";
    }

    public function headings(): array
    {
        return [
            'Fecha', 'Pedimento', 'Operación', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            'Monto SC', 'Monto SC MXN', 'Moneda','Folio Factura', 'Folio SC', 'Estado', 'PDF Factura', 'PDF SC'
        ];
    }

    public function map($row): array
    {
        $esTotales = isset($row['pedimento']) && $row['pedimento'] === 'TOTALES';

        if ($esTotales) {
            return [
                '', 
                'Totales',
                '',
                '', 
                (float) optional($row['llc'])->monto_total,
                (float) optional($row['llc'])->monto_total_mxn,
                (float) optional($row['sc'])->desglose_conceptos['montos']['llc'] ?? 0,
                (float) optional($row['sc'])->desglose_conceptos['montos']['llc_mxn'] ?? 0,
                '', '', '', '', '', '' 
            ];
        }

        $facturaLLCs = $row['llc'] ?? null;
        $sc = $row['sc'] ?? null;
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;

        $montoSc = 'N/A';
        $montoScMxn = 'N/A';
        $folioSc = 'N/A';
        $pdfSc = 'Sin PDF!';
        $estado = optional($facturaLLCs)->estado;

        if ($sc) {
            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            $montoSc = (float)($montosSc['llc'] ?? 0);
            $montoScMxn = (float)($montosSc['llc_mxn'] ?? 0);
            $folioSc = $sc->folio;

            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }
        } else {
            $estado = 'Sin SC!';
        }

        $monedaConTC = optional($facturaLLCs)->moneda_documento;
        if ($monedaConTC === 'USD' && isset($desgloseSc['tipo_cambio'])) {
            $monedaConTC = "USD (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";
        }

        $pdfFactura = optional($facturaLLCs)->ruta_pdf
            ? '=HYPERLINK("' . $facturaLLCs->ruta_pdf . '", "Acceder PDF")'
            : 'Sin PDF!';

        return [
            optional($facturaLLCs)->fecha_documento,
            $pedimento,
            $row['tipo'] ?? '',
            optional($cliente)->nombre,
            (float) optional($facturaLLCs)->monto_total, 
            (float) optional($facturaLLCs)->monto_total_mxn, 
            $montoSc,
            $montoScMxn,
            $monedaConTC,
            optional($facturaLLCs)->folio, 
            $folioSc,
            $estado,
            $pdfFactura,
            $pdfSc,
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 15, 'C' => 15, 'D' => 30, 'E' => 15, 'F' => 15,
            'G' => 15, 'H' => 15, 'I' => 20, 'J' => 15, 'K' => 15,
            'L' => 18, 'M' => 15, 'N' => 15,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
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

                $statusColumn = 'L';

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
                            $dataStyle = [
                                'font' => ['color' => ['argb' => 'FF1F497D']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE6F1']],
                            ];
                            $scStyle = [
                                'font' => ['color' => ['argb' => 'FF646464']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']],
                            ];
                            
                            $sheet->getStyle('A'.$rowIndex.':F'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('I'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('L'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('G'.$rowIndex.':H'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('J'.$rowIndex.':L'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('M'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('N'.$rowIndex)->applyFromArray($scStyle);

                        } else {
                            switch ($estado) {
                                case 'Coinciden!': $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]]); break;
                                case 'Pago de menos!': $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); break;
                                case 'Pago de mas!': $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF974700']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFCC99']]]); break;
                            }
                        }

                        if (!empty($styleArray)) {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        foreach (['M', 'N'] as $col) {
                            $cell = $sheet->getCell($col . $rowIndex);
                            if (is_string($cell->getValue()) && str_starts_with($cell->getValue(), '=HYPERLINK')) {
                                $cell->getStyle()->applyFromArray([
                                    'font' => ['bold' => true, 'underline' => Font::UNDERLINE_SINGLE],
                                ]);
                            }
                        }
                    }
                }
            },
        ];
    }
}