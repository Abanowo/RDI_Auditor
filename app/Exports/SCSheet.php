<?php
namespace App\Exports;

use Illuminate\Support\Collection;
use Illuminate\Database\Capsule\Manager;
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

class SCSheet implements FromCollection, WithHeadings,WithTitle, WithMapping,
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
        return $this->operaciones->map( function ($pedimento) {
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;

            if (!$operacion) {
                return null; 
            }

            $sc = $operacion->auditoriasTotalSC;
            $cliente = $operacion->cliente;

            if (!$sc) {
                return null;
            }

            $tipo = $pedimento->importacion ? 'Importación' : 'Exportación';

            return [
                'tipo' => $tipo,
                'pedimento' => $pedimento->num_pedimiento,
                'cliente' => $cliente, 
                'sc' => $sc,
            ];
        })->filter();
    }

    public function title(): string
    {
        return "SC AF";
    }

    public function headings(): array
    {
        return [
            'Fecha', 'Pedimento', 'Operación', 'Cliente', 'Saldo SC',
            'Saldo SC MXN', 'Moneda', 'Folio SC', 'PDF SC'
        ];
    }

    public function map($row): array
    {
        $sc = $row['sc'] ?? null;
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;

        $montoSc = 0;
        $montoScMxn = 0;
        $folioSc = '';
        $pdfSc = '';
        $monedaConTC = '';

        if ($sc) {
            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            $montoSc = (float)($montosSc['sc'] ?? 0);
            $montoScMxn = (float)($montosSc['sc_mxn'] ?? 0);
            $folioSc = $sc->folio;
            $nombreCliente = optional($cliente)->nombre;

            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }

            $monedaConTC = $desgloseSc['moneda'] == "MXN" ? $desgloseSc['moneda'] : $desgloseSc['moneda']. " (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";
        } else {
            $nombreCliente = optional($cliente)->nombre;
        }

        $resultado = [
            optional($sc)->fecha_documento,
            $pedimento,
            $row['tipo'] ?? '',
            $nombreCliente,
            $montoSc,
            $montoScMxn,
            $monedaConTC,
            $folioSc,
            $pdfSc,
        ];
        
        return $resultado;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 15, 'C' => 15, 'D' => 30, 'E' => 15,
            'F' => 15, 'G' => 15, 'H' => 15, 'I' => 15,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, 
            'F' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
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

                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:' . $lastColumn . '1');

                if ($sheet->getHighestRow() >= 2) {
                    foreach ($sheet->getRowIterator(2) as $row) { 
                        $rowIndex = $row->getRowIndex();
                        $styleArray = [
                            'borders' => [
                                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']],
                                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']],
                            ],
                            'font' => ['color' => ['argb' => 'FF006100']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDDEBF7']],
                        ];

                        $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        
                        foreach (['I'] as $col) {
                            $cell = $sheet->getCell($col . $rowIndex);
                            if (is_string($cell->getValue()) && str_starts_with($cell->getValue(), '=HYPERLINK')) {
                                $cell->getStyle()->applyFromArray([
                                    'font' => [
                                        'bold' => true,
                                        'underline' => Font::UNDERLINE_SINGLE,
                                    ],
                                ]);
                            }
                        }
                    }
                }
            },
        ];
    }
}