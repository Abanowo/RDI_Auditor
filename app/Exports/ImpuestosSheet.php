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

class ImpuestosSheet implements FromCollection, WithHeadings, WithTitle, WithMapping,
WithColumnWidths, ShouldAutoSize, WithColumnFormatting, WithStyles, WithEvents, WithStrictNullComparison
{
    protected $operaciones;
    protected $banco;    // Propiedad para identificar si es Santander
    protected $urlSheet; // Propiedad para el link de GPC

    /**
     * @param Collection $operaciones
     * @param string|null $banco
     * @param string|null $urlSheet
     */
    public function __construct(Collection $operaciones, $banco = null, $urlSheet = null)
    {
        $this->operaciones = $operaciones;
        $this->banco = $banco;
        $this->urlSheet = $urlSheet;
    }

    /**
     * Define la consulta a la base de datos, incluyendo filtros.
     */
    public function collection()
    {
        // Usamos map para transformar cada pedimento y luego filter para limpiar los nulos.
        $data = $this->operaciones->map(function ($pedimento) {
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

            // Usamos firstWhere para obtener un único modelo o null.
            // Esto buscará en la colección 'auditorias' el primer registro
            // donde 'tipo_documento' sea 'impuestos'.
            $facturaImpuestos = $operacion->auditorias->firstWhere('tipo_documento', 'impuestos');

            // Si no se encuentra una factura de impuestos para este pedimento, devolvemos null.
            if (!$facturaImpuestos) {
                return null;
            }

            // Obtenemos la factura SC (factura maestra) asociada.
            $sc = $operacion->auditoriasTotalSC;
            $cliente = $operacion->cliente;

            // Devolvemos el array con los datos listos para el método map() del export.
            return [
                'pedimento' => $pedimento->num_pedimiento,
                'cliente'   => $cliente,
                'impuestos' => $facturaImpuestos,
                'sc'        => $sc,
            ];
        })->filter(); // Eliminamos todas las entradas que devolvieron null.


        // --- Calcular sumatorias ---
        $totalMontoFactura = 0;
        $totalMontoFacturaMxn = 0;
        $totalMontoSc = 0;
        $totalMontoScMxn = 0;

        foreach ($data as $row) {
            $factura = $row['impuestos'] ?? null;
            $sc = $row['sc'] ?? null;

            $totalMontoFactura += (float) optional($factura)->monto_total;
            $totalMontoFacturaMxn += (float) optional($factura)->monto_total_mxn;

            if ($sc) {
                $desgloseSc = $sc->desglose_conceptos;
                $totalMontoSc += (float)($desgloseSc['montos']['impuestos'] ?? 0);
                $totalMontoScMxn += (float)($desgloseSc['montos']['impuestos_mxn'] ?? 0);
            }
        }

        $data->push([
            'pedimento' => 'TOTALES',
            'cliente'   => (object) ['nombre' => ''],
            'impuestos' => (object) [
                'monto_total' => $totalMontoFactura,
                'monto_total_mxn' => $totalMontoFacturaMxn,
            ],
            'sc' => (object) [
                'desglose_conceptos' => [
                    'montos' => [
                        'impuestos' => $totalMontoSc,
                        'impuestos_mxn' => $totalMontoScMxn,
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
        return "Impuestos";
    }

    /**
     * Define las cabeceras de las columnas.
     */
    public function headings(): array
    {
        // LÓGICA DE ENCABEZADO DINÁMICO
        // Si es Santander, la última columna se llama "GPC", si no, "PDF SC".
        $headerReferencia = ($this->banco === 'SANTANDER') ? 'GPC' : 'PDF SC';

        return [
            'Fecha', 'Pedimento', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            'Monto SC', 'Monto SC MXN', 'Moneda', 'Folio SC', 'Estado', 'PDF Factura', $headerReferencia
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
                '', 'Totales:', '',
                (float) optional($row['impuestos'])->monto_total,
                (float) optional($row['impuestos'])->monto_total_mxn,
                (float) optional($row['sc'])->desglose_conceptos['montos']['impuestos'] ?? 0,
                (float) optional($row['sc'])->desglose_conceptos['montos']['impuestos_mxn'] ?? 0,
                '', '', '', '', ''
            ];
        }

        $facturaImpuestos = $row['impuestos'] ?? null;
        $sc = $row['sc'] ?? null;
        $cliente = $row['cliente'] ?? null;
        $pedimento = $row['pedimento'] ?? null;

        // Valores por defecto
        $montoSc = 'N/A';
        $montoScMxn = 'N/A';
        $folioSc = 'N/A';
        $pdfSc = 'Sin PDF!';
        
        // IMPORTANTE: Tomamos el estado directamente de la auditoría calculada
        $estado = optional($facturaImpuestos)->estado ?? 'Sin SC!';

        if ($sc) {
            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            $montoSc = (float)($montosSc['impuestos'] ?? 0);
            $montoScMxn = (float)($montosSc['impuestos_mxn'] ?? 0);
            $folioSc = $sc->folio;
            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }
        } 

        // Ajuste específico para Santander
        if ($this->banco === 'SANTANDER') {
            if (!empty($this->urlSheet)) {
                $pdfSc = '=HYPERLINK("' . $this->urlSheet . '", "Ver GPC")';
            }
            // Si el estado es "Sin SC!" pero sabemos que es Santander, verificamos la diferencia
            if ($estado === 'Sin SC!' && optional($facturaImpuestos)->monto_diferencia_sc != 0) {
                // Esto significa que sí hubo match pero algo falló en la relación, 
                // intentamos mantener el estado original de la DB.
                $estado = optional($facturaImpuestos)->estado;
            }
        }

        $urlFactura = route('documentos.ver', [
            'tipo' => 'impuestos',
            'id' => $facturaImpuestos->id
        ]);

        $pdfFactura = optional($facturaImpuestos)->ruta_pdf
            ? '=HYPERLINK("' . $urlFactura . '", "Acceder PDF")'
            : 'Sin PDF!';

        return [
            optional($facturaImpuestos)->fecha_documento,
            $pedimento,
            optional($cliente)->nombre,
            (float) optional($facturaImpuestos)->monto_total,
            (float) optional($facturaImpuestos)->monto_total_mxn,
            $montoSc,
            $montoScMxn,
            optional($facturaImpuestos)->moneda_documento,
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

                // La columna de estado es la 'J'
                $statusColumn = 'J';

                // Inmoviliza la fila 1 (los encabezados)
                $sheet->freezePane('A2');

                $sheet->setAutoFilter('A1:' . $lastColumn . '1');

                if ($sheet->getHighestRow() >= 2) {

                    foreach ($sheet->getRowIterator(2) as $row) { // Empezamos desde la fila 2

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
                            // Aplicamos los estilos a las celdas correspondientes
                            $sheet->getStyle('A'.$rowIndex.':E'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('H'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('K'.$rowIndex)->applyFromArray($dataStyle);
                            $sheet->getStyle('F'.$rowIndex.':G'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('I'.$rowIndex.':J'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('L'.$rowIndex)->applyFromArray($scStyle);

                        } else {
                            // Limpiamos posible prefijo de Santander para detectar el color
                            $estadoPuro = str_replace('SANTANDER: ', '', $estado);

                            switch ($estadoPuro) {
                                case 'Coinciden!':     $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]]); break;
                                case 'Pago de menos!': $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); break;
                                case 'Pago de mas!':   $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF974700']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFCC99']]]); break;
                            }
                        }

                        if (!empty($styleArray)) {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        // Subrayado para Hyperlinks (Columnas K y L)
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