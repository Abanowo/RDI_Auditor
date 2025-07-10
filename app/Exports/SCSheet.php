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

    /**
     * Define la consulta a la base de datos, incluyendo filtros.
     */

    // El método collection() simplemente devuelve los datos que ya tenemos
    public function collection()
    {
        // Filtramos para quedarnos solo con las operaciones que tienen SC
        return $this->operaciones->map( function ($pedimento) {
            $sc = $pedimento->importacion->auditoriasTotalSC;
            if (!$sc) { return []; }
            return
            [
                'pedimento' => $pedimento->num_pedimiento,
                'cliente'   => $pedimento->importacion->cliente,
                'sc'        => $sc,
            ];
        })->filter();
    }

    public function title(): string
    {
        return "SC AF";
    }

    /**
     * Define las cabeceras de las columnas.
     */
    public function headings(): array
    {
         return [
            'Fecha', 'Pedimento', 'Cliente', 'Saldo SC',
            'Saldo SC MXN', 'Moneda', 'Folio SC', 'PDF SC'
        ];
    }

    /**
     * Mapea los datos de cada operación a las columnas del Excel.
     */
    public function map($row): array
    {
        $sc = $row['sc'] ?? null;
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;
        if ($sc) {

            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            $montoSc = (float)($montosSc['sc'] ?? 0);
            $montoScMxn = (float)($montosSc['sc_mxn'] ?? 0);
            $folioSc = $sc->folio_documento;
            $nombreCliente = $cliente->nombre;

            $urlSC = route('documentos.ver', [
            'tipo' => 'sc',
            'id' => $sc->id
            ]);

            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $urlSC . '", "Acceder PDF")';
            }

            $monedaConTC = $desgloseSc['moneda'] == "MXN" ? $desgloseSc['moneda'] : $desgloseSc['moneda']. " (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";
        }

        $resultado =
        [
            $sc->fecha_documento,
            $pedimento,
            $nombreCliente,
            $montoSc,
            $montoScMxn,
            $monedaConTC,
            $folioSc,
            $pdfSc,
        ];
        return $resultado;
    }
    /**
     * Define anchos específicos para cada columna.
     */
     public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 15, 'C' => 30, 'D' => 15,
            'E' => 15, 'F' => 15, 'G' => 15, 'H' => 15,
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

                // Inmoviliza la fila 1 (los encabezados) para que no se mueva al hacer scroll.
                // 'A2' le dice a Excel que congele todo lo que está por encima y a la izquierda de la celda A2.
                $sheet->freezePane('A2');

                $sheet->setAutoFilter('A1:' . $lastColumn . '1');

                if ($sheet->getHighestRow() >= 2)
                {
                    foreach ($sheet->getRowIterator(2) as $row)
                    { // Empezamos desde la fila 2
                        $rowIndex = $row->getRowIndex();
                        $styleArray=
                        [
                            'borders' =>
                                [
                                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']],
                                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']],
                                ],
                                'font' => ['color' => ['argb' => 'FF006100']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDDEBF7']],
                        ];

                        $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        //Subrayado para Hyperlinks
                        foreach (['H'] as $col)
                        {
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
