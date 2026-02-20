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

    public function collection()
    {
        // Renombramos la variable a $pedimentoOrigen para entender que es el del PDF (Padre)
        return $this->operaciones->map(function ($pedimentoOrigen) {
            
            $operacion = $pedimentoOrigen->importacion ?? $pedimentoOrigen->exportacion;
            if (!$operacion) return null;

            // 1. Buscamos la auditoría de impuestos vinculada a esta operación
            // IMPORTANTE: Quitamos cualquier filtro de ID, solo buscamos por tipo 'impuestos'
            $facturaImpuestos = $operacion->auditorias
                ->where('tipo_documento', 'impuestos')
                ->first();

            // 2. LÓGICA DE REEMPLAZO DEL PEDIMENTO
            // Por defecto, usamos el número que viene del Estado de Cuenta (PDF)
            $numeroPedimentoFinal = $pedimentoOrigen->num_pedimiento;

            // PERO, si existe una auditoría y esa auditoría apunta a un pedimento diferente...
            if ($facturaImpuestos && $facturaImpuestos->pedimento) {
                // ...¡Usamos el número del pedimento R1 (del Excel)!
                $numeroPedimentoFinal = $facturaImpuestos->pedimento->num_pedimiento;
            }

            $sc = $operacion->auditoriasTotalSC;

             // Devolvemos el array con los datos listos para el método map() del export.
            return [
                'pedimento' => $numeroPedimentoFinal, // Aquí pasamos el corregido
                'cliente'   => $operacion->cliente,
                'impuestos' => $facturaImpuestos,
                'sc'        => $sc,
            ];
        })->filter();
    }

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
        $facturaImpuestos = $row['impuestos'] ?? null;
        $sc = $row['sc'] ?? null;
        
        // 1. Inicializar variables con valores por defecto para evitar errores de "Undefined variable"
        $montoSc = 0;
        $montoScMxn = 0;
        $folioSc = 'N/A';
        $linkSc = 'Sin PDF!';
        $estado = optional($facturaImpuestos)->estado;

        // 2. Lógica de asignación por Banco
        if ($this->banco === 'SANTANDER') {
            // Caso Santander: Reconstruimos el monto esperado (Monto Banco + Diferencia)
            $montoReal = (float) optional($facturaImpuestos)->monto_total;
            $diferencia = (float) optional($facturaImpuestos)->monto_diferencia_sc;
            
            // Si el estado es "Sin SC!", mantenemos el -1.1 para auditoría, de lo contrario sumamos
            if ($estado === 'Sin SC!') {
                $montoSc = -1.1;
                $montoScMxn = -1.1;
            } else {
                $montoSc = $montoReal + $diferencia;
                $montoScMxn = $montoSc;
            }
            
            $folioSc = 'GPC Sheet';
            // Definimos el link al Google Sheets
            $linkSc = !empty($this->urlSheet) ? '=HYPERLINK("' . $this->urlSheet . '", "Ver GPC")' : 'Sin Link';

        } else {
            // Caso BBVA / Otros: Usamos la factura SC física si existe
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

        // Link de la factura (PDF del Banco)
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
            optional($row['cliente'])->nombre,
            (float) optional($facturaImpuestos)->monto_total,
            (float) optional($facturaImpuestos)->monto_total_mxn,
            $montoSc,
            $montoScMxn,
            optional($facturaImpuestos)->moneda_documento,
            $folioSc,
            $estado,
            $pdfFactura,
            $linkSc, // Ya no marcará error porque está definida al inicio del método
        ];
    }

    public function title(): string {
        return "Impuestos";
    }

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
                
                // 1. Obtener la última fila con datos
                $highestRow = $sheet->getHighestRow();

                // Estilos fijos para encabezados (Fila 1) - Esto siempre se ejecuta
                $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setHorizontal('center');
                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:'.$lastColumn.'1');

                // 2. VALIDACIÓN DE SEGURIDAD:
                // Si la última fila es menor a 2 (es decir, solo hay encabezados), 
                // DETENEMOS la ejecución aquí para evitar el error.
                if ($highestRow < 2) {
                    return; 
                }

                // 3. El resto del código se ejecuta solo si hay datos (Fila 2 en adelante)
                $statusColumn = 'J';

                foreach ($sheet->getRowIterator(2, $highestRow) as $row) {
                    $rowIndex = $row->getRowIndex();
                    
                    // Doble verificación: asegurarse de que la celda de estado existe
                    $cellValue = $sheet->getCell($statusColumn . $rowIndex)->getValue();
                    if (!$cellValue) continue;

                    $estado = $cellValue;
                    $styleArray = ['borders'=>['bottom'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF95B3D7']],'top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['argb'=>'FF95B3D7']]]];

                    if ($estado === 'Sin SC!') {
                        $sheet->getStyle('A'.$rowIndex.':L'.$rowIndex)->applyFromArray([
                            'font' => ['color' => ['argb' => 'FF646464']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']]
                        ]);
                    } else {
                        // Limpiamos el prefijo si viniera sucio (opcional)
                        $estadoPuro = str_replace('SANTANDER: ', '', $estado);
                        
                        switch ($estadoPuro) {
                            case 'Coinciden!':     $styleArray = array_merge($styleArray, ['font'=>['color'=>['argb'=>'FF006100']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFC6EFCE']]]); break;
                            case 'Pago de menos!': $styleArray = array_merge($styleArray, ['font'=>['color'=>['argb'=>'FF9C0006']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFFC7CE']]]); break;
                            case 'Pago de mas!':   $styleArray = array_merge($styleArray, ['font'=>['color'=>['argb'=>'FF974700']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FFFFCC99']]]); break;
                        }
                        $sheet->getStyle('A'.$rowIndex.':'.$lastColumn.$rowIndex)->applyFromArray($styleArray);
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
            },
        ];
    }
}