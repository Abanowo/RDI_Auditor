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

class MuestraSheet implements FromCollection, WithHeadings, WithTitle, WithMapping,
WithColumnWidths, ShouldAutoSize, WithColumnFormatting, WithStyles, WithEvents,
WithStrictNullComparison
{
    protected $operaciones;

    public function __construct(Collection $operaciones)
    {
        $this->operaciones = $operaciones;
    }

    /**
     * Define la consulta a la base de datos, extrayendo las facturas de muestras.
     */
    public function collection()
    {
        // Usamos flatMap para "desenrollar" las auditorías de muestras
        $data = $this->operaciones->flatMap(function ($pedimento) {
            
            // 1. Determinamos de forma segura cuál operación existe
            $operacion = $pedimento->importacion ?? $pedimento->exportacion;

            if (!$operacion) {
                return null; 
            }

            // Filtramos las auditorías que sean exclusivamente de muestras
            $muestras = $operacion->auditorias->where('tipo_documento', 'muestras');

            // Si no hay muestras, saltamos
            if ($muestras->isEmpty()) {
                return null;
            }

            // 2. Accedemos a las relaciones
            $sc = $operacion->auditoriasTotalSC;
            $cliente = $operacion->cliente;

            // Creamos una fila por cada factura de muestras encontrada
            return $muestras->map(function ($muestra) use ($pedimento, $sc, $cliente) {
                return [
                    'pedimento' => $pedimento->num_pedimiento,
                    'cliente'   => $cliente ?? null,
                    'muestra'   => $muestra,
                    'sc'        => $sc,
                ];
            });
        });

        // --- Calcular sumatorias ---
        $totalMontoFactura = 0;
        $totalMontoFacturaMxn = 0;
        $totalMontoSc = 0;
        $totalMontoScMxn = 0;

        foreach ($data as $row) {
            $factura = $row['muestra'] ?? null;
            $sc = $row['sc'] ?? null;

            $totalMontoFactura += (float) optional($factura)->monto_total;
            $totalMontoFacturaMxn += (float) optional($factura)->monto_total_mxn;

            if ($sc) {
                $desgloseSc = $sc->desglose_conceptos;
                $totalMontoSc += (float)($desgloseSc['montos']['muestras'] ?? 0);
                $totalMontoScMxn += (float)($desgloseSc['montos']['muestras_mxn'] ?? 0);
            }
        }

        // --- Agregar fila de Totales ---
        $data->push([
            'pedimento' => 'TOTALES',
            'cliente'   => (object) ['nombre' => ''],
            'muestra'   => (object) [
                'monto_total' => $totalMontoFactura,
                'monto_total_mxn' => $totalMontoFacturaMxn,
            ],
            'sc' => (object) [
                'desglose_conceptos' => [
                    'montos' => [
                        'muestras' => $totalMontoSc,
                        'muestras_mxn' => $totalMontoScMxn,
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
        return "Muestras";
    }

    /**
     * Define las cabeceras de las columnas (Idéntico a Pagos de Derecho).
     */
    public function headings(): array
    {
        return [
            'Fecha', 'Pedimento', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            'Monto SC', 'Monto SC MXN', 'Moneda', 'Folio SC', 'Estado', 'PDF Factura', 'PDF SC'
        ];
    }

    /**
     * Mapea los datos de cada operación a las columnas del Excel.
     */
    public function map($row): array
    {
        $esTotales = isset($row['pedimento']) && $row['pedimento'] === 'TOTALES';

        if ($esTotales) {
            return [
                '', // Fecha
                'Totales:', // Pedimento
                '', // Cliente
                (float) optional($row['muestra'])->monto_total,
                (float) optional($row['muestra'])->monto_total_mxn,
                (float) optional($row['sc'])->desglose_conceptos['montos']['muestras'] ?? 0,
                (float) optional($row['sc'])->desglose_conceptos['montos']['muestras_mxn'] ?? 0,
                '', '', '', '', '' // resto de columnas vacías
            ];
        }

        $facturaMuestra = $row['muestra'];
        $sc = $row['sc'];
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;

        // Lógica para manejar valores de la SC cuando no existe
        $montoSc = 'N/A';
        $montoScMxn = 'N/A';
        $folioSc = 'N/A';
        $pdfSc = 'Sin PDF!';
        $estado = optional($facturaMuestra)->estado;

        if ($sc) {
            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            
            // Evaluamos si el monto de la SC existe o viene en -1
            $montoSc = (float)($montosSc['muestras'] ?? 0);
            $montoScMxn = (float)($montosSc['muestras_mxn'] ?? 0);
            
            $folioSc = $sc->folio;

            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }
        } else {
             $estado = 'Sin SC!';
        }

        // Lógica para mostrar la moneda y el TC si es necesario
        $monedaConTC = optional($facturaMuestra)->moneda_documento;
        if ($monedaConTC === 'USD' && isset($desgloseSc['tipo_cambio'])) {
            $monedaConTC = "USD (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";
        }

        // Link al PDF de la factura
        $pdfFactura = optional($facturaMuestra)->ruta_pdf
            ? '=HYPERLINK("' . $facturaMuestra->ruta_pdf . '", "Acceder PDF")'
            : 'Sin PDF!';

        return [
            optional($facturaMuestra)->fecha_documento,
            $pedimento,
            optional($cliente)->nombre,
            (float) optional($facturaMuestra)->monto_total,
            (float) optional($facturaMuestra)->monto_total_mxn,
            $montoSc,
            $montoScMxn,
            $monedaConTC,
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
        return [
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
        return [
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

        // Estilo exacto para la cabecera
        return [
            1 => [
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
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();

                // La columna de estado es la 'J'
                $statusColumn = 'J';

                // Inmoviliza la fila 1 (los encabezados)
                $sheet->freezePane('A2');

                // Autofiltro
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

                        // Estilo especial para filas "Sin SC!"
                        if ($estado === 'Sin SC!') {
                            $dataStyle = [
                                'font' => ['color' => ['argb' => 'FF1F497D']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE6F1']],
                            ];
                            $scStyle = [
                                'font' => ['color' => ['argb' => 'FF646464']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']],
                            ];
                            
                            $sheet->getStyle('A'.$rowIndex.':E'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('H'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('K'.$rowIndex)->applyFromArray($dataStyle);
                            
                            $sheet->getStyle('F'.$rowIndex.':G'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('I'.$rowIndex.':J'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('L'.$rowIndex)->applyFromArray($scStyle);

                        } else {
                             // Lógica de colores según el estado arrojado por compararMontos_Muestras()
                            switch ($estado) {
                                case 'Coinciden!': 
                                case 'Normal':     
                                    $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEBF1DE']]]); 
                                    break;
                                    
                                case 'Pago de mas!':
                                case 'Pago de menos!':
                                case 'Sin Muestras!':
                                    $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); 
                                    break;
                            }
                        }

                        if (!empty($styleArray)) {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        // Subrayado para Hyperlinks en columnas K y L (PDFs)
                        foreach (['K', 'L'] as $col) {
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