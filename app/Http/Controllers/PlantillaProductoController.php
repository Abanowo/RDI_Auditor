<?php

namespace App\Http\Controllers;

use App\Models\PlantillaConcepto;
use App\Models\PlantillaProducto;
use App\Models\Seccion;
use App\Services\CatalogoCotizador;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Generador de plantillas por producto: asocia un producto con los conceptos
 * de cada bloque (Honorarios, Servicio de operación…). No genera PDF: el
 * generador de cotizaciones toma de aquí los conceptos del producto elegido.
 */
class PlantillaProductoController extends Controller
{
    /** GET plantillas  → lista (filtros: q, tipo, sucursal) */
    public function index(Request $request)
    {
        $q = PlantillaProducto::withCount('conceptos')->orderBy('producto')->orderBy('sucursal')->orderBy('tipo_operacion');

        if ($buscar = trim((string) $request->get('q'))) {
            $q->where('producto', 'like', '%' . $buscar . '%');
        }
        if ($tipo = $request->get('tipo')) {
            $q->where('tipo_operacion', $tipo);
        }
        if ($sucursal = $request->get('sucursal')) {
            $q->where('sucursal', $sucursal);
        }

        $nombres = $this->nombresSucursal();

        return response()->json([
            'plantillas' => $q->get()->map(function ($p) use ($nombres) {
                return [
                    'id'             => $p->id,
                    'producto'       => $p->producto,
                    'tipo_operacion' => $p->tipo_operacion,
                    'sucursal'       => $p->sucursal,
                    'sucursal_nombre'=> $p->sucursal ? ($nombres[$p->sucursal] ?? $p->sucursal) : null,
                    'descripcion'    => $p->descripcion,
                    'activo'         => $p->activo,
                    'conceptos'      => $p->conceptos_count,
                    'actualizada'    => optional($p->updated_at)->format('d/m/Y H:i'),
                ];
            })->values(),
            'sucursales' => app(CatalogoCotizador::class)->sucursales(),
        ]);
    }

    /** GET plantillas/nueva  → editor vacío */
    public function nueva()
    {
        return response()->json($this->payload(new PlantillaProducto(['activo' => true])));
    }

    /** GET plantillas/{plantilla}  → editor con sus conceptos */
    public function show(PlantillaProducto $plantilla)
    {
        $plantilla->load('conceptos');

        return response()->json($this->payload($plantilla));
    }

    public function store(Request $request)
    {
        $plantilla = DB::transaction(function () use ($request) {
            $plantilla = new PlantillaProducto();
            $plantilla->user_id = optional(auth()->user())->getAuthIdentifier();
            $this->persistir($plantilla, $this->validar($request));

            return $plantilla;
        });

        return response()->json(['mensaje' => 'Plantilla de ' . $plantilla->producto . ' guardada.', 'plantilla' => $this->payload($plantilla->load('conceptos'))], 201);
    }

    public function update(Request $request, PlantillaProducto $plantilla)
    {
        DB::transaction(function () use ($request, $plantilla) {
            $this->persistir($plantilla, $this->validar($request, $plantilla));
        });

        return response()->json(['mensaje' => 'Plantilla de ' . $plantilla->producto . ' guardada.', 'plantilla' => $this->payload($plantilla->load('conceptos'))]);
    }

    public function destroy(PlantillaProducto $plantilla)
    {
        $plantilla->delete();

        return response()->json(['mensaje' => 'Plantilla eliminada.']);
    }

    /** POST plantillas/{plantilla}/duplicar  → copia para otra sucursal u operación */
    public function duplicar(PlantillaProducto $plantilla)
    {
        $copia = DB::transaction(function () use ($plantilla) {
            $copia = $plantilla->replicate();
            $copia->producto = $plantilla->producto . ' (copia)';
            $copia->save();
            foreach ($plantilla->conceptos as $c) {
                $copia->conceptos()->create(Arr::except($c->toArray(), ['id', 'plantilla_id', 'created_at', 'updated_at']));
            }

            return $copia;
        });

        return response()->json(['mensaje' => 'Plantilla duplicada.', 'id' => $copia->id]);
    }

    /**
     * GET plantillas/para-cotizacion?producto=&tipo=&sucursal=
     * Conceptos de la plantilla que corresponde, agrupados por sección, para el generador.
     */
    public function paraCotizacion(Request $request)
    {
        $plantilla = PlantillaProducto::mejorPara($request->get('producto'), $request->get('tipo'), $request->get('sucursal'));
        if (!$plantilla) {
            return response()->json(['plantilla' => null]);
        }

        $filas = [];
        foreach ($plantilla->conceptos as $c) {
            $filas[$c->seccion_id][] = $this->fila($c);
        }

        return response()->json([
            'plantilla' => [
                'id'             => $plantilla->id,
                'producto'       => $plantilla->producto,
                'tipo_operacion' => $plantilla->tipo_operacion,
                'sucursal'       => $plantilla->sucursal,
            ],
            'conceptos' => $filas,   // { seccion_id: [filas] }
        ]);
    }

    /* ------------------------------------------------------------------ */

    protected function validar(Request $request, PlantillaProducto $actual = null)
    {
        $sucursales = array_keys((array) config('cotizador.sucursales_cotizador'));

        $datos = $request->validate([
            'producto'                       => 'required|string|max:255',
            'tipo_operacion'                 => 'nullable|in:IMPORTACION,EXPORTACION',
            'sucursal'                       => ['nullable', Rule::in($sucursales)],
            'descripcion'                    => 'nullable|string|max:255',
            'activo'                         => 'nullable|boolean',
            'conceptos'                      => 'nullable|array',
            'conceptos.*'                    => 'array',
            'conceptos.*.*.codigo'           => 'nullable|string|max:20',
            'conceptos.*.*.clave_sat'        => 'nullable|string|max:20',
            'conceptos.*.*.descripcion'      => 'required|string|max:255',
            'conceptos.*.*.cantidad'         => 'required|numeric|min:0',
            'conceptos.*.*.precio_usd'       => 'nullable|numeric|min:0',
            'conceptos.*.*.precio_mxn'       => 'nullable|numeric|min:0',
            'conceptos.*.*.al_costo'         => 'nullable|boolean',
            'conceptos.*.*.descripcion_doc'  => ['required', 'string', Rule::in(config('cotizador.descripciones_doc'))],
            'conceptos.*.*.nota'             => 'nullable|string|max:2000',
        ], [], [
            'producto'                  => 'producto',
            'conceptos.*.*.descripcion' => 'descripción del concepto',
        ]);

        // Una sola plantilla por producto + tipo de operación + sucursal
        $repetida = PlantillaProducto::whereRaw('LOWER(TRIM(producto)) = ?', [mb_strtolower(trim($datos['producto']), 'UTF-8')])
            ->where(function ($q) use ($datos) {
                empty($datos['tipo_operacion']) ? $q->whereNull('tipo_operacion') : $q->where('tipo_operacion', $datos['tipo_operacion']);
            })
            ->where(function ($q) use ($datos) {
                empty($datos['sucursal']) ? $q->whereNull('sucursal') : $q->where('sucursal', $datos['sucursal']);
            })
            ->when($actual && $actual->exists, function ($q) use ($actual) { $q->where('id', '<>', $actual->id); })
            ->exists();

        if ($repetida) {
            abort(response()->json([
                'message' => 'Ya existe una plantilla de ' . $datos['producto'] . ' para esa operación y sucursal.',
                'errors'  => ['producto' => ['Ya existe una plantilla de ' . $datos['producto'] . ' para esa operación y sucursal.']],
            ], 422));
        }

        return $datos;
    }

    protected function persistir(PlantillaProducto $plantilla, array $datos)
    {
        $plantilla->fill([
            'producto'       => trim($datos['producto']),
            'tipo_operacion' => Arr::get($datos, 'tipo_operacion') ?: null,
            'sucursal'       => Arr::get($datos, 'sucursal') ?: null,
            'descripcion'    => Arr::get($datos, 'descripcion'),
            'activo'         => array_key_exists('activo', $datos) ? (bool) $datos['activo'] : true,
        ]);
        $plantilla->save();

        $validas = Seccion::pluck('id')->map(function ($id) { return (int) $id; })->all();
        $plantilla->conceptos()->delete();
        $orden = 0;

        foreach ((array) Arr::get($datos, 'conceptos', []) as $seccionId => $filas) {
            if (!in_array((int) $seccionId, $validas, true)) {
                continue;
            }
            foreach ((array) $filas as $f) {
                $usd     = (float) Arr::get($f, 'precio_usd', 0);
                $alCosto = (bool) Arr::get($f, 'al_costo', false);
                $plantilla->conceptos()->create([
                    'seccion_id'      => (int) $seccionId,
                    'codigo'          => Arr::get($f, 'codigo'),
                    'clave_sat'       => Arr::get($f, 'clave_sat') ?: null,
                    'descripcion'     => trim($f['descripcion']),
                    'cantidad'        => (float) $f['cantidad'],
                    'precio_usd'      => $alCosto ? 0 : $usd,
                    'precio_mxn'      => ($alCosto || $usd > 0) ? 0 : (float) Arr::get($f, 'precio_mxn', 0),
                    'al_costo'        => $alCosto,
                    'descripcion_doc' => $f['descripcion_doc'],
                    'nota'            => trim((string) Arr::get($f, 'nota', '')) ?: null,
                    'orden'           => $orden++,
                ]);
            }
        }
    }

    protected function fila(PlantillaConcepto $c)
    {
        return [
            'codigo'          => $c->codigo,
            'clave_sat'       => $c->clave_sat,
            'descripcion'     => $c->descripcion,
            'cantidad'        => $c->cantidad,
            'precio_usd'      => $c->precio_usd,
            'precio_mxn'      => $c->precio_mxn,
            'al_costo'        => $c->al_costo,
            'descripcion_doc' => $c->descripcion_doc,
            'nota'            => $c->nota,
        ];
    }

    /** Estructura que consume PlantillasProductos.vue (mismos bloques y catálogo que el generador) */
    protected function payload(PlantillaProducto $plantilla)
    {
        $catalogos = app(CatalogoCotizador::class);
        $catalogos->asegurar();

        $porSeccion = $plantilla->relationLoaded('conceptos') ? $plantilla->conceptos->groupBy('seccion_id') : collect();

        return [
            'id'          => $plantilla->id,
            'datos'       => [
                'producto'       => $plantilla->producto,
                'tipo_operacion' => $plantilla->tipo_operacion,
                'sucursal'       => $plantilla->sucursal,
                'descripcion'    => $plantilla->descripcion,
                'activo'         => $plantilla->exists ? (bool) $plantilla->activo : true,
            ],
            'secciones'   => Seccion::activas()->with('catalogo')->get()->map(function ($s) use ($porSeccion) {
                return [
                    'id'           => $s->id,
                    'nombre'       => $s->nombre,
                    'color'        => $s->color,
                    'aplica_iva'   => $s->aplica_iva,
                    'sin_sucursal' => in_array($s->nombre, (array) config('cotizador.bloques_sin_sucursal'), true),
                    'catalogo'     => $s->catalogo->map(function ($c) {
                        return $c->only(['id', 'sucursal', 'codigo', 'clave_sat', 'descripcion', 'precio_usd', 'precio_mxn', 'descripcion_doc']);
                    })->values()->all(),
                    'rows'         => $porSeccion->get($s->id, collect())->map(function ($c) {
                        return $this->fila($c);
                    })->values()->all(),
                ];
            })->values()->all(),
            'sucursales'       => $catalogos->sucursales(),
            'descripcionesDoc' => config('cotizador.descripciones_doc'),
            'tipoCambio'       => (float) config('cotizador.tipo_cambio'),
            'iva'              => (float) config('cotizador.iva'),
        ];
    }

    protected function nombresSucursal()
    {
        $n = [];
        foreach (app(CatalogoCotizador::class)->sucursales() as $s) {
            $n[$s['clave']] = $s['nombre'];
        }

        return $n;
    }
}
