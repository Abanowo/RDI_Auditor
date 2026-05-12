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
    protected $tcGlobal = 1.0; // Variable para almacenar el tipo de cambio de rescate

    public function __construct(Collection $operaciones)
    {
        $this->operaciones = $operaciones;

        // Buscamos un Tipo de Cambio válido en TODA la hoja
        foreach ($this->operaciones as $pedimento) {
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;
            if ($operacion && $operacion->auditoriasTotalSC) {
                $sc = $operacion->auditoriasTotalSC;
                $desglose = is_array($sc->desglose_conceptos) ? $sc->desglose_conceptos : json_decode($sc->desglose_conceptos, true);
                if (isset($desglose['tipo_cambio']) && (float)$desglose['tipo_cambio'] > 1) {
                    $this->tcGlobal = (float)$desglose['tipo_cambio'];
                    break; // Al encontrar el primero válido (ej. 18.24), nos detenemos.
                }
            }
        }
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

            $facturaLLC = $operacion->auditorias->firstWhere('tipo_documento', 'llc');

            if (!$facturaLLC) {
                return null;
            }

            $sc = $operacion->auditoriasTotalSC;
            $cliente = $operacion->cliente;
            $tipo = $pedimento->importacion ? 'Importación' : 'Exportación';

            return [
                'tipo' => $tipo,
                'pedimento' => $pedimento->num_pedimiento,
                'cliente' => $cliente,
                'llc' => $facturaLLC, 
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

            $montoUsd = (float) optional($factura)->monto_total;
            $montoMxn = (float) optional($factura)->monto_total_mxn;

            // Determinamos el Tipo de Cambio para esta fila en específico
            $tcFila = 1.0;
            if ($sc) {
                $desgloseSc = is_array($sc->desglose_conceptos) ? $sc->desglose_conceptos : json_decode($sc->desglose_conceptos, true);
                if (isset($desgloseSc['tipo_cambio']) && (float)$desgloseSc['tipo_cambio'] > 1) {
                    $tcFila = (float)$desgloseSc['tipo_cambio'];
                }
            }
            
            // Si la fila no tiene un TC válido, usamos el Global
            if ($tcFila <= 1.0) {
                $tcFila = $this->tcGlobal;
            }

            // Si la base de datos no se actualizó, reparamos la multiplicación de MXN "al vuelo"
            if ($montoUsd > 0 && ($montoMxn == $montoUsd || $montoMxn == 0) && $tcFila > 1.0) {
                $montoMxn = round($montoUsd * $tcFila, 2);
            }

            $totalMontoFactura += $montoUsd;
            $totalMontoFacturaMxn += $montoMxn;

            if ($sc) {
                $desgloseSc = is_array($sc->desglose_conceptos) ? $sc->desglose_conceptos : json_decode($sc->desglose_conceptos, true);
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
                (float) ($row['sc']->desglose_conceptos['montos']['llc'] ?? 0),
                (float) ($row['sc']->desglose_conceptos['montos']['llc_mxn'] ?? 0),
                '', '', '', '', '', '' 
            ];
        }

        $facturaLLC = $row['llc'] ?? null;
        $sc = $row['sc'] ?? null;
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;

        $montoSc = 'N/A';
        $montoScMxn = 'N/A';
        $folioSc = 'N/A';
        $pdfSc = 'Sin PDF!';
        $estado = optional($facturaLLC)->estado;

        $desgloseSc = [];
        $tcFila = 1.0;

        if ($sc) {
            $desgloseSc = is_array($sc->desglose_conceptos) ? $sc->desglose_conceptos : json_decode($sc->desglose_conceptos, true);
            $montosSc = $desgloseSc['montos'] ?? [];
            $montoSc = (float)($montosSc['llc'] ?? 0);
            $montoScMxn = (float)($montosSc['llc_mxn'] ?? 0);
            $folioSc = $sc->folio;

            if (isset($desgloseSc['tipo_cambio']) && (float)$desgloseSc['tipo_cambio'] > 1) {
                $tcFila = (float)$desgloseSc['tipo_cambio'];
            }

            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }
        } else {
            $estado = 'Sin SC!';
        }

        // Si no detectó un TC en su propia fila, hereda el TC Global
        if ($tcFila <= 1.0) {
            $tcFila = $this->tcGlobal;
        }

        $montoUsd = (float) optional($facturaLLC)->monto_total;
        $montoMxn = (float) optional($facturaLLC)->monto_total_mxn;

        // Reparación al vuelo para el Excel si la BD está desactualizada
        if ($montoUsd > 0 && ($montoMxn == $montoUsd || $montoMxn == 0) && $tcFila > 1.0) {
            $montoMxn = round($montoUsd * $tcFila, 2);
        }

        // --- LÓGICA DE MONEDA PROTEGIDA ---
        $monedaBase = optional($facturaLLC)->moneda_documento ?? 'USD';
        $monedaColumna = $monedaBase;
        
        if ($monedaBase === 'USD' || str_contains($monedaBase, 'USD')) {
            // Esto aniquila para siempre la posibilidad de que imprima (1.00 MXN)
            if ($tcFila > 1.0) {
                $monedaColumna = "USD (" . number_format($tcFila, 2) . " MXN)";
            } else {
                $monedaColumna = "USD";
            }
        }

        $pdfFactura = optional($facturaLLC)->ruta_pdf
            ? '=HYPERLINK("' . $facturaLLC->ruta_pdf . '", "Acceder PDF")'
            : 'Sin PDF!';

        return [
            optional($facturaLLC)->fecha_documento,
            $pedimento,
            $row['tipo'] ?? '',
            optional($cliente)->nombre,
            $montoUsd, 
            $montoMxn, 
            $montoSc,
            $montoScMxn,
            $monedaColumna,
            optional($facturaLLC)->folio, 
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
                                case 'Coinciden!': 
                                    $styleArray = array_merge_recursive($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]]); 
                                    break;
                                case 'Pago de menos!': 
                                    $styleArray = array_merge_recursive($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); 
                                    break;
                                case 'Pago de mas!': 
                                    $styleArray = array_merge_recursive($styleArray, ['font' => ['color' => ['argb' => 'FF974700']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFCC99']]]); 
                                    break;
                            }
                        }

                        if (count($styleArray) > 1) {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        foreach (['M', 'N'] as $col) {
                            $cell = $sheet->getCell($col . $rowIndex);
                            $val = $cell->getValue();
                            if (is_string($val) && str_starts_with($val, '=HYPERLINK')) {
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