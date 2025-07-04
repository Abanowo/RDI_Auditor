<?php
namespace App\Exports;

use App\Models\Operacion;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\FromQuery;
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

class FletesSheet implements FromQuery, WithHeadings,WithTitle, WithMapping,
WithColumnWidths, ShouldAutoSize, WithColumnFormatting, WithStyles, WithEvents,
WithStrictNullComparison
{
    protected $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    /**
     * Define la consulta a la base de datos, incluyendo filtros.
     */
    public function query()
    {
        $query = Operacion::query();

        // Aquí replicamos la misma lógica de filtros de tu AuditController
        // Aplicamos los filtros recibidos
            $filters = $this->filters;
        // --- AQUÍ APLICAMOS LOS FILTROS DINÁMICAMENTE ---
            // SECCIÓN 1: Identificadores Universales
            // Filtro por Pedimento
            $query->whereHas('auditorias', function ($q) { $q->where('tipo_documento', 'flete'); });
            $query->when($filters['pedimento'], function ($q, $val) {return $q->where('pedimento', 'like', "%{$val}%");});

            // Filtro por Operacion ID
            $query->when($filters['operacion_id'], function ($q, $val){return $q->where('id', $val);});


            // SECCIÓN 2: Identificadores de Factura (Folio)
            // Filtro por Folio (AHORA BUSCA TAMBIÉN EN LA SC)
            $query->when($filters['folio'], function ($q, $folio) use ($filters) {
                return $q->where(function ($query) use ($folio, $filters) {
                    // Busca en las facturas sueltas (auditorias)
                    $query->whereHas('auditorias', function ($subQuery) use ($folio, $filters) {
                        $subQuery->where('folio', 'like', "%{$folio}%");
                        // ¡CORRECCIÓN! Usamos $subQuery->when() para que se aplique dentro de la misma búsqueda
                        $subQuery->when($filters['folio_tipo_documento'], function ($q_inner, $tipo) {
                            return $q_inner->where('tipo_documento', $tipo);
                        });
                    })
                    // O busca en la factura maestra (auditorias_totales_sc)
                    ->orWhereHas('auditoriasTotalSC', function ($subQuery) use ($folio) {
                        $subQuery->where('folio_documento', $folio);
                    });
                });
            });

            //Por Operacion ID
            /* $query->when($request->input('operacion_id'), function ($q, $num_operacion) {
                return $q->whereHas('auditorias', function ($subQuery) use ($num_operacion) {
                    $subQuery->where('operacion_id', $num_operacion);
                });
            }); */
            // SECCIÓN 3: Estados
            // Filtro por Estado (también busca en la tabla relacionada)
            $query->when($filters['estado'], function ($q, $estado) use ($filters) {
                return $q->whereHas('auditorias', function ($subQuery) use ($estado, $filters) {
                    $subQuery->where('estado', $estado);
                    // Filtro por Tipo de documento - Estado (también busca en la tabla relacionada)
                    // Si se especifica un tipo, se añade a la condición del estado
                    // ¡CORRECCIÓN! Usamos $subQuery->when() para que se aplique dentro de la misma búsqueda
                    $subQuery->when($filters['estado_tipo_documento'], function ($q_inner, $tipo) {
                        return $q_inner->where('tipo_documento', $tipo);
                    });
                });
            });


            // SECCIÓN 4: Periodo de Fecha
            //Filtro por Fecha inicio
            $query->when($filters['fecha_inicio'], function ($q, $fecha_inicio) use ($filters) {
                //Filtro por Fecha final
                $fecha_fin = $filters['fecha_fin'] ?? $fecha_inicio; // Si no hay fecha fin, busca solo en la fecha de inicio
                //Filtro por Tipo documento - Fecha
                $tipo_documento = $filters['fecha_tipo_documento'];

                return $q->whereHas('auditorias', function ($subQuery) use ($fecha_inicio, $fecha_fin, $tipo_documento) {
                    $subQuery->whereBetween('fecha_documento', [$fecha_inicio, $fecha_fin]);
                    if ($tipo_documento) {
                        $subQuery->where('tipo_documento', $tipo_documento);
                    }
                });
            });

            // SECCIÓN 5: Involucrados (Placeholders para el futuro)
            // $query->when($request->input('cliente_id'), fn($q, $val) => $q->where('cliente_id', $val));
            // $query->when($request->input('operador_id'), fn($q, $val) => $q->where('operador_id', $val));


        return $query->with(['auditorias', 'auditoriasTotalSC']);
    }

    public function title(): string
    {
        return "Fletes";
    }

    /**
     * Define las cabeceras de las columnas.
     */
    public function headings(): array
    {
         return [
            'Fecha', 'Pedimento', 'Cliente', 'Monto Factura', 'Monto Factura MXN',
            'Monto SC', 'Monto SC MXN', 'Moneda','Folio Factura', 'Folio SC', 'Estado', 'PDF Factura', 'PDF SC'
        ];
    }

    /**
     * Mapea los datos de cada operación a las columnas del Excel.
     */
    public function map($operacion): array
    {
        $facturaFletes = $operacion->auditorias->firstWhere('tipo_documento', 'flete');
        $sc = $operacion->auditoriasTotalSc;

        // Lógica para manejar valores de la SC cuando no existe
        $montoSc = 'N/A';
        $montoScMxn = 'N/A';
        $folioSc = 'N/A';
        $pdfSc = 'Sin PDF!';
        $estado = optional($facturaFletes)->estado;

        if ($sc) {
            $pedimento = $operacion->pedimento;
            $desgloseSc = $sc->desglose_conceptos;
            $montosSc = $desgloseSc['montos'] ?? [];
            $montoSc = (float)($montosSc['flete'] ?? 0);
            $montoScMxn = (float)($montosSc['flete_mxn'] ?? 0);
            $folioSc = $sc->folio_documento;
            if ($sc->ruta_pdf) {
                $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
            }
        } else {
             $estado = 'Sin SC!';
        }

        $monedaConTC = optional($facturaFletes)->moneda_documento;
        if ($monedaConTC === 'USD' && isset($desgloseSc['tipo_cambio'])) {
            $monedaConTC = "USD (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";
        }

        $pdfFactura = optional($facturaFletes)->ruta_pdf
            ? '=HYPERLINK("' . optional($facturaFletes)->ruta_pdf . '", "Acceder PDF")'
            : 'Sin PDF!';

        return [
            optional($facturaFletes)->fecha_documento,
            $operacion->pedimento,
            'CLIENTE PLACEHOLDER',
            (float) $facturaFletes->monto_total,
            (float) $facturaFletes->monto_total_mxn,
            $montoSc,
            $montoScMxn,
            $monedaConTC,
            $facturaFletes->folio,
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
            'F' => 15, 'G' => 15, 'H' => 20, 'I' => 15, 'J' => 15,
            'K' => 18, 'L' => 15, 'M' => 15,
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

                // 6. MODIFICACIÓN: La columna de estado ahora es la 'K'
                $statusColumn = 'K';

                // Inmoviliza la fila 1 (los encabezados) para que no se mueva al hacer scroll.
                // 'A2' le dice a Excel que congele todo lo que está por encima y a la izquierda de la celda A2.
                $sheet->freezePane('A2');

                $sheet->setAutoFilter('A1:' . $lastColumn . '1');

                if ($sheet->getHighestRow() >= 2)
                {
                    foreach ($sheet->getRowIterator(2) as $row)
                    { // Empezamos desde la fila 2
                        $rowIndex = $row->getRowIndex();
                        $estado = $sheet->getCell($statusColumn . $rowIndex)->getValue();
                        $styleArray = [];
                        $styleArray['borders'] =
                                    [
                                    'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']],
                                    'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF95B3D7']]
                                    ];

                        //Estilo especial para filas "Sin SC!"
                        if ($estado === 'Sin SC!')
                        {
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
                            $sheet->getStyle('I'.$rowIndex.':K'.$rowIndex)->applyFromArray($scStyle);
                            $sheet->getStyle('M'.$rowIndex)->applyFromArray($scStyle);

                        }
                        else
                        {
                            // Lógica de colores que ya teníamos para las demás filas
                            switch ($estado)
                            {
                                case 'Coinciden!':     $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF006100']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFC6EFCE']]]); break;
                                case 'Sin Flete!':
                                case 'Pago de menos!': $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF9C0006']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']]]); break;
                                case 'Pago de mas!':   $styleArray = array_merge($styleArray, ['font' => ['color' => ['argb' => 'FF974700']],'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFCC99']]]); break;
                            }
                        }

                        if (!empty($styleArray))
                        {
                            $sheet->getStyle('A' . $rowIndex . ':' . $lastColumn . $rowIndex)->applyFromArray($styleArray);
                        }

                        //Subrayado para Hyperlinks
                        foreach (['L', 'M'] as $col)
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
