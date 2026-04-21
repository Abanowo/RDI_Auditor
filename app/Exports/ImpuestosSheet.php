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

class ImpuestosSheet implements FromCollection, WithHeadings, WithTitle, WithMapping,
WithColumnWidths, ShouldAutoSize, WithColumnFormatting, WithStyles, WithEvents, WithStrictNullComparison
{
    protected $operaciones;
    protected $banco;
    protected $urlSheet;

    public function __construct(Collection $operaciones, $banco = null, $urlSheet = null)
    {
        $this->operaciones = $operaciones;
        $this->banco = $banco;
        $this->urlSheet = $urlSheet;
    }

    public function collection()
    {
        return $this->operaciones->map(function ($pedimentoOrigen) {
            $operacion = $pedimentoOrigen->importacion ?? $pedimentoOrigen->exportacion;
            if (!$operacion) return null;

            $facturaImpuestos = $operacion->auditorias
                ->where('tipo_documento', 'impuestos')
                ->first();

            $numeroPedimentoFinal = $pedimentoOrigen->num_pedimiento;

            if ($facturaImpuestos && $facturaImpuestos->pedimento) {
                $numeroPedimentoFinal = $facturaImpuestos->pedimento->num_pedimiento;
            }

            $sc = $operacion->auditoriasTotalSC;
            $tipo = $pedimentoOrigen->importacion ? 'Importación' : 'Exportación';

            return [
                'tipo' => $tipo,
                'pedimento' => $numeroPedimentoFinal, 
                'cliente' => $operacion->cliente,
                'impuestos' => $facturaImpuestos,
                'sc' => $sc,
            ];
        })->filter();
    }

    public function headings(): array
    {
        $headerReferencia = ($this->banco === 'SANTANDER') ? 'GPC' : 'PDF SC';
        $headerReferencia2 = ($this->banco === 'SANTANDER') ? 'Monto GPC' : 'Monto SC';
        $headerReferencia3 = ($this->banco === 'SANTANDER') ? 'Monto GPC MXN' : 'Monto SC MXN';

        return [
            'Fecha', 'Pedimento', 'Operación', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            $headerReferencia2, $headerReferencia3, 'Moneda', 'Folio SC', 'Estado', 'PDF Factura', $headerReferencia
        ];
    }

    public function map($row): array
    {
        $facturaImpuestos = $row['impuestos'] ?? null;
        $sc = $row['sc'] ?? null;

        $montoSc = 0;
        $montoScMxn = 0;
        $folioSc = 'N/A';
        $linkSc = 'Sin PDF!';
        $estado = optional($facturaImpuestos)->estado;

        if ($sc) {
            $montos = isset($sc->desglose_conceptos['montos']) ? $sc->desglose_conceptos['montos'] : [];
            $montoSc = (float)(isset($montos['impuestos']) ? $montos['impuestos'] : 0);
            $montoScMxn = (float)(isset($montos['impuestos_mxn']) ? $montos['impuestos_mxn'] : 0);
            $folioSc = $sc->folio;
            if ($sc->ruta_pdf) {
                $linkSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }
        }
        
        if ($this->banco === 'SANTANDER') {
            $montoReal = (float) optional($facturaImpuestos)->monto_total;
            $diferencia = (float) optional($facturaImpuestos)->monto_diferencia_sc;

            if ($estado === 'Sin SC!') {
                $montoSc = -1.1;
                $montoScMxn = -1.1;
            } else {
                $montoSc = $montoReal + $diferencia;
                $montoScMxn = $montoSc;
            }

            $folioSc = 'GPC Sheet';
            $linkSc = !empty($this->urlSheet) ? '=HYPERLINK("' . $this->urlSheet . '", "Ver GPC")' : 'Sin Link';

        } else {
            if ($sc) {
                $desgloseSc = $sc->desglose_conceptos;
                $montoSc = (float)($desgloseSc['montos']['impuestos'] ?? 0);
                $montoScMxn = (float)($desgloseSc['montos']['impuestos_mxn'] ?? 0);
                $folioSc = $sc->folio;
                $linkSc = $sc->ruta_pdf ? '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")' : 'Sin PDF!';
            } else {
                $montoSc = -1.1;
                $montoScMxn = -1.1;
                $estado = 'Sin SC!';
            }
        }

        $urlVerFactura = route('documentos.ver', [
            'tipo' => 'impuestos',
            'id' => optional($facturaImpuestos)->id
        ]);
        $pdfFactura = optional($facturaImpuestos)->ruta_pdf
            ? '=HYPERLINK("' . $urlVerFactura . '", "Acceder PDF")'
            : 'Sin PDF!';

        return [
            optional($facturaImpuestos)->fecha_documento,
            $row['pedimento'],
            $row['tipo'] ?? '',
            optional($row['cliente'])->nombre,
            (float) optional($facturaImpuestos)->monto_total,
            (float) optional($facturaImpuestos)->monto_total_mxn,
            $montoSc,
            $montoScMxn,
            optional($facturaImpuestos)->moneda_documento,
            $folioSc,
            $estado,
            $pdfFactura,
            $linkSc, 
        ];
    }

    public function title(): string {
        return "Impuestos";
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 15, 'C' => 15, 'D' => 30, 'E' => 15, 'F' => 15,
            'G' => 15, 'H' => 15, 'I' => 20, 'J' => 15, 'K' => 18,
            'L' => 15, 'M' => 15,
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
                $highestRow = $sheet->getHighestRow();

                $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setHorizontal('center');
                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:'.$lastColumn.'1');

                if ($highestRow < 2) {
                    return;
                }

                $statusColumn = 'K';

                foreach ($sheet->getRowIterator(2, $highestRow) as $row) {
                    $rowIndex = $row->getRowIndex();

                    $cellValue = $sheet->getCell($statusColumn . $rowIndex)->getValue();
                    if (!$cellValue) continue;

                    $estado = $cellValue;
                    $styleArray = ['borders'=>['bottom'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF95B3D7']],'top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF95B3D7']]]];

                    if ($estado === 'Sin SC!') {
                        $sheet->getStyle('A'.$rowIndex.':M'.$rowIndex)->applyFromArray([
                            'font' => ['color' => ['argb' => 'FF646464']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']]
                        ]);
                    } else {
                        $estadoPuro = str_replace('SANTANDER: ', '', $estado);

                        switch ($estadoPuro) {
                            case 'Coinciden!': $styleArray = array_merge($styleArray, ['font'=>['color'=>['argb'=>'FF006100']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFC6EFCE']]]); break;
                            case 'Pago de menos!': $styleArray = array_merge($styleArray, ['font'=>['color'=>['argb'=>'FF9C0006']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFFC7CE']]]); break;
                            case 'Pago de mas!': $styleArray = array_merge($styleArray, ['font'=>['color'=>['argb'=>'FF974700']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFFCC99']]]); break;
                        }
                        $sheet->getStyle('A'.$rowIndex.':'.$lastColumn.$rowIndex)->applyFromArray($styleArray);
                    }

                    foreach (['L', 'M'] as $col) {
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
            },
        ];
    }
}