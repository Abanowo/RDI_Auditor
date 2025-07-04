<?php

namespace App\Http\Controllers;

// En app/Http/Controllers/AuditController.php
use App\Models\Operacion;
use Illuminate\Http\Request;
use App\Exports\AuditoriaFacturadoExport;
use Maatwebsite\Excel\Facades\Excel;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        if ($request->wantsJson()) {

            // Empezamos con el constructor de consultas, sin ejecutarlo todavía
            $query = Operacion::query();

            // --- AQUÍ APLICAMOS LOS FILTROS DINÁMICAMENTE ---
            // SECCIÓN 1: Identificadores Universales
            // Filtro por Pedimento
            $query->when($request->input('pedimento'), function ($q, $val) {return $q->where('pedimento', 'like', "%{$val}%");});

            // Filtro por Operacion ID
            $query->when($request->input('operacion_id'), function ($q, $val){return $q->where('id', $val);});


            // SECCIÓN 2: Identificadores de Factura (Folio)
            // Filtro por Folio (AHORA BUSCA TAMBIÉN EN LA SC)
            $query->when($request->input('folio'), function ($q, $folio) use ($request) {
                return $q->where(function ($query) use ($folio, $request) {
                    // Busca en las facturas sueltas (auditorias)
                    $query->whereHas('auditorias', function ($subQuery) use ($folio, $request) {
                        $subQuery->where('folio', 'like', "%{$folio}%");
                        // ¡CORRECCIÓN! Usamos $subQuery->when() para que se aplique dentro de la misma búsqueda
                        $subQuery->when($request->input('folio_tipo_documento'), function ($q_inner, $tipo) {
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
            $query->when($request->input('estado'), function ($q, $estado) use ($request) {
                $tipo_documento = $request->input('estado_tipo_documento');

                // --- CASO ESPECIAL: Si estamos buscando por la existencia de la SC ---
                if ($estado === 'SC Encontrada') {
                    // Primero, aseguramos que la operación SÍ tiene una SC asociada.
                    // Esto busca registros en la tabla 'auditorias_totales_sc'.
                    $q->whereHas('auditoriasTotalSc');

                    // Luego, si ADEMÁS se especificó un tipo de documento (ej. 'llc')...
                    if ($tipo_documento) {
                        // ...aseguramos que la operación TAMBIÉN tenga esa factura en la tabla 'auditorias'.
                        $q->whereHas('auditorias', function ($subQuery) use ($tipo_documento) {
                            $subQuery->where('tipo_documento', $tipo_documento);
                        });
                    }
                    return $q;
                }

                // --- CASO NORMAL: Para todos los demás estados que sí existen en la tabla 'auditorias' ---
                return $q->whereHas('auditorias', function ($subQuery) use ($estado, $tipo_documento) {
                    $subQuery->where('estado', $estado);
                    // Si se especifica un tipo, se añade a la condición del estado.
                    if ($tipo_documento) {
                        $subQuery->where('tipo_documento', $tipo_documento);
                    }
                });
            });



            // SECCIÓN 4: Periodo de Fecha
            //Filtro por Fecha inicio
            $query->when($request->input('fecha_inicio'), function ($q, $fecha_inicio) use ($request) {
                //Filtro por Fecha final
                $fecha_fin = $request->input('fecha_fin', $fecha_inicio); // Si no hay fecha fin, busca solo en la fecha de inicio
                //Filtro por Tipo documento - Fecha
                $tipo_documento = $request->input('fecha_tipo_documento');

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

            // --- FIN DE LOS FILTROS ---
            // Ahora sí, ejecutamos la consulta ya filtrada y paginada
            $operaciones = $query->with(['auditorias', 'auditoriasTotalSC'])
                                ->latest()
                                ->paginate(15)
                                ->withQueryString(); // ¡Importante! Mantiene los filtros en los links de paginación

            // La transformación sigue igual
            $operaciones->getCollection()->transform(function ($operacion) {
                return $this->transformarOperacion($operacion);
            });

            return response()->json($operaciones);
        }

    }

    private function transformarOperacion($operacion)
    {
        $auditorias = $operacion->auditorias;
        $sc = $operacion->auditoriasTotalSC;

        $status_botones = [];

        // 1. Lógica exclusiva para el botón de la SC
        $status_botones['sc']['estado'] = $sc ? 'verde' : 'gris'; // Verde si existe, Amarillo si no
        $status_botones['sc']['datos'] = $sc;

        // 2. Lógica para las demás facturas
        //    Ahora su estado es independiente de si existe la SC o no.
        $tipos_a_auditar = ['impuestos', 'flete', 'llc', 'pago_derecho'];

        foreach ($tipos_a_auditar as $tipo)
        {
            $facturas = $operacion->auditorias->where('tipo_documento', $tipo);

            if ($facturas->isEmpty()) {
                $status_botones[$tipo]['estado'] = 'gris';
                $status_botones[$tipo]['datos'] = null;
            } else {
                $facturaPrincipal = $facturas->first();
                $status_botones[$tipo]['estado'] = 'verde'; // Asumimos verde si se encuentra

                // AQUÍ ESTÁ EL AJUSTE: usamos values()->all() para un array limpio
                $status_botones[$tipo]['datos'] = $facturas->count() > 1 ? $facturas->values()->all() : $facturaPrincipal;

                if (str_contains(optional($facturaPrincipal)->ruta_pdf, 'No encontrado')) {
                    $status_botones[$tipo]['estado'] = 'rojo';
                }
            }
        }

        return [
            'id' => $operacion->id,
            'pedimento' => $operacion->pedimento,
            'fecha_edc' => optional($auditorias->where('tipo_documento', 'impuestos')->first())->fecha_documento,
            'status_botones' => $status_botones,
        ];
    }

    public function exportarFacturado(Request $request)
    {
        // Regla de negocio: si no hay filtros, usar el mes actual por defecto.
        $filters = $request->query();
        $hasActiveFilters = !empty(array_filter($filters));

        if (!$hasActiveFilters) {
            $filters['fecha_inicio'] = now()->addMonths(-1)->toDateString();
            $filters['fecha_fin'] = now()->toDateString();
        }

        // Creamos el nombre del archivo dinámicamente
        $fecha = now()->format('dmY');
        $fileName = "RDI_NOG{$fecha}.xlsx";

        // Le pasamos la responsabilidad a nuestra clase de exportación
        return Excel::download(new AuditoriaFacturadoExport($filters), $fileName);
    }

}
