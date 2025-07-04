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

class SCSheet implements FromQuery, WithHeadings,WithTitle, WithMapping,
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
            $query->whereHas('auditoriasTotalSC');
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


        return $query->with(['auditoriasTotalSC']);
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
    public function map($operacion): array
    {
        $sc = $operacion->auditoriasTotalSc;

        $pedimento = $operacion->pedimento;
        $desgloseSc = $sc->desglose_conceptos;
        $montosSc = $desgloseSc['montos'] ?? [];
        $montoSc = (float)($montosSc['sc'] ?? 0);
        $montoScMxn = (float)($montosSc['sc_mxn'] ?? 0);
        $folioSc = $sc->folio_documento;
        if ($sc->ruta_pdf) {
            $pdfSc = '=HYPERLINK("' . $sc->ruta_pdf . '", "Acceder PDF")';
        }

        $monedaConTC = $desgloseSc['moneda'] == "MXN" ? $desgloseSc['moneda'] : $desgloseSc['moneda']. " (" . number_format($desgloseSc['tipo_cambio'], 2) . " MXN)";

        return [
            $sc->fecha_documento,
            $operacion->pedimento,
            'CLIENTE PLACEHOLDER',
            $montoSc,
            $montoScMxn,
            $monedaConTC,
            $folioSc,
            $pdfSc,
        ];
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
