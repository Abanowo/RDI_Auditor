<?php

namespace App\Http\Controllers;

use App\Http\Requests\CotizacionRequest;
use App\Mail\CotizacionMail;
use App\Models\CotizacionAprobacion;
use App\Models\Cotizacion;
use App\Models\CotizacionConcepto;
use App\Models\CotizacionTermino;
use App\Models\Seccion;
use App\Models\TerminoCatalogo;
use App\Models\User;
use App\Services\CotizacionCalculator;
use App\Services\EmpresasQuery;
use App\Services\CatalogoCotizador;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CotizacionController extends Controller
{

    /** @var CotizacionCalculator */
    protected $calc;

    /** @var EmpresasQuery */
    protected $empresas;

    protected $camposEncabezado = [
        'cliente',
        'contacto',
        'fecha',
        'valida_hasta',
        'puerto',
        'tipo_operacion',
        'producto',
        'vendedor',
        'tipo_cambio',
        'empresa_id',
    ];

    public function __construct(CotizacionCalculator $calc, EmpresasQuery $empresas)
    {
        $this->calc     = $calc;
        $this->empresas = $empresas;
    }

    /**
     * Si los catálogos están vacíos (no se corrió el seeder), los llena
     * con los bloques, conceptos predefinidos y términos por defecto.
     */
    protected function asegurarCatalogos()
    {
        app(CatalogoCotizador::class)->asegurar();
    }

    /* ------------------------------------------------------------------ *
     |  Aprobación por el cliente desde el correo (rutas públicas, sin login)
     * ------------------------------------------------------------------ */

    /**
     * GET cotizacion/aprobar/{token}  → página para aprobar o solicitar cambios
     */
    public function aprobacion($token)
    {
        $cotizacion = $this->cotizacionPorToken($token);
        if (!$cotizacion) {
            return response()->view('cotizacion_aprobacion', ['estado' => 'invalido', 'cotizacion' => null], 404);
        }

        $cotizacion->load('conceptos', 'terminos');
        $estado = $cotizacion->estadoRespuesta();
        if ($estado === 'pendiente' && $cotizacion->tokenVencido()) {
            $estado = 'vencido';
        }

        return view('cotizacion_aprobacion', [
            'estado'     => $estado,
            'cotizacion' => $cotizacion,
            'resumen'    => $this->calc->resumen($cotizacion, Seccion::activas()->get()),
            'token'      => $token,
        ]);
    }

    /**
     * POST cotizacion/aprobar/{token}  → accion = aprobar | cambios
     */
    public function responderAprobacion(Request $request, $token)
    {
        $cotizacion = $this->cotizacionPorToken($token);
        if (!$cotizacion) {
            return response()->view('cotizacion_aprobacion', ['estado' => 'invalido', 'cotizacion' => null], 404);
        }
        if ($cotizacion->tokenVencido() && !$cotizacion->aprobada) {
            return redirect('/cotizacion/aprobar/' . $token);
        }

        $datos = $request->validate([
            'accion'     => 'required|in:aprobar,cambios',
            'nombre'     => 'required|string|max:150',
            'comentario' => 'nullable|string|max:3000|required_if:accion,cambios',
        ], [
            'nombre.required'        => 'Escribe tu nombre.',
            'comentario.required_if' => 'Cuéntanos qué cambios necesitas.',
        ]);

        if ($datos['accion'] === 'aprobar' && !$request->boolean('acepto')) {
            return back()->withInput()->withErrors(['acepto' => 'Confirma que aceptas la cotización y sus términos.']);
        }

        if (!$cotizacion->aprobada) {
            if ($datos['accion'] === 'aprobar') {
                $cotizacion->aprobada               = true;
                $cotizacion->aprobada_at            = now();
                $cotizacion->aprobada_por           = $datos['nombre'];
                $cotizacion->aprobada_ip            = $request->ip();
                $cotizacion->cambios_solicitados_at = null;
            } else {
                $cotizacion->cambios_solicitados_at = now();
                $cotizacion->aprobada_por           = $datos['nombre'];
            }
            $cotizacion->respuesta_comentario = Arr::get($datos, 'comentario');
            $cotizacion->save();

            $this->avisarRespuesta($cotizacion);

            if ($cotizacion->aprobada) {
                $cotizacion->iniciarAprobacionesInternas();
            }
        }

        return redirect('/cotizacion/aprobar/' . $token);
    }

    /**
     * GET cotizacion/aprobar/{token}/pdf  → el cliente descarga su cotización
     */
    public function pdfAprobacion($token)
    {
        $cotizacion = $this->cotizacionPorToken($token);
        abort_if(!$cotizacion, 404);
        $cotizacion->load('conceptos', 'terminos');

        try {
            return $this->generarPdf($cotizacion)->stream($cotizacion->nombreArchivo());
        } catch (\Throwable $e) {
            abort(500, $e->getMessage());
        }
    }

    protected function cotizacionPorToken($token)
    {
        if (!Schema::hasColumn('cotizaciones', 'token_aprobacion') || strlen((string) $token) < 32) {
            return null;
        }

        return Cotizacion::where('token_aprobacion', $token)->first();
    }

    /** Avisa al coordinador que hizo la cotización (o a comercialización) que el cliente respondió. */
    protected function avisarRespuesta(Cotizacion $cotizacion)
    {
        $coordinador = $cotizacion->user_id ? User::find($cotizacion->user_id) : null;
        $destino     = $coordinador && $coordinador->email
            ? $coordinador->email
            : strtolower((string) config('cotizador.empresa.email'));

        try {
            Mail::to($destino)->send(new \App\Mail\CotizacionRespuestaMail($cotizacion));
        } catch (\Throwable $e) {
            report($e);   // la respuesta del cliente ya quedó guardada aunque falle el aviso
        }
    }

    /* ------------------------------------------------------------------ *
     |  Aprobaciones internas: Dirección, Desarrollo de negocios, Contabilidad
     * ------------------------------------------------------------------ */

    /**
     * POST cotizaciones/{cotizacion}/aprobaciones/{area}   accion = aprobar | rechazar
     */
    public function responderAprobacionInterna(Request $request, Cotizacion $cotizacion, $area)
    {
        $areas = (array) config('cotizador.aprobaciones_internas');
        if (!isset($areas[$area])) {
            return response()->json(['message' => 'Área de aprobación no válida.'], 422);
        }
        if (!$cotizacion->aprobada) {
            return response()->json(['message' => 'Primero debe aprobarla el cliente.'], 422);
        }
        if (!$this->puedeAprobar($area)) {
            return response()->json(['message' => 'Tu usuario no puede aprobar por ' . $areas[$area]['nombre'] . '.'], 403);
        }

        $datos = $request->validate([
            'accion'     => 'required|in:aprobar,rechazar',
            'comentario' => 'nullable|string|max:3000|required_if:accion,rechazar',
        ], ['comentario.required_if' => 'Escribe el motivo del rechazo.']);

        $cotizacion->iniciarAprobacionesInternas();
        $aprobacion = CotizacionAprobacion::where('cotizacion_id', $cotizacion->id)->where('area', $area)->firstOrFail();

        $aprobacion->update([
            'estado'         => $datos['accion'] === 'aprobar' ? 'aprobada' : 'rechazada',
            'usuario_id'     => $this->idUsuario(),
            'usuario_nombre' => $this->nombreUsuario(),
            'comentario'     => Arr::get($datos, 'comentario'),
            'respondida_at'  => now(),
        ]);
        // Las aprobaciones internas se llevan solo dentro del módulo (sin correos)
        $despues = $cotizacion->estadoInterno();

        $mensajes = [
            'autorizada' => 'Cotización AUTORIZADA: aprobaron las tres áreas.',
            'rechazada'  => 'Registraste el rechazo de ' . $areas[$area]['nombre'] . '.',
            'pendiente'  => 'Aprobación de ' . $areas[$area]['nombre'] . ' registrada.',
        ];

        return response()->json([
            'mensaje'      => $mensajes[$despues] ?? 'Listo.',
            'aprobaciones' => $this->datosAprobaciones($cotizacion),
        ]);
    }

    /**
     * Agrega a cada empresa de la tabla el estado de las aprobaciones internas de su última cotización.
     * Se consulta todo de una vez para no hacer una consulta por renglón.
     */
    protected function conAprobacionesInternas($empresas)
    {
        $ids   = $empresas->pluck('cotizacion_id')->filter()->values()->all();
        $areas = (array) config('cotizador.aprobaciones_internas');
        $hay   = !empty($ids) && Schema::hasTable('cotizacion_aprobaciones');

        $cotizaciones = $hay ? Cotizacion::whereIn('id', $ids)->get(['id', 'folio', 'aprobada'])->keyBy('id') : collect();
        $registros    = $hay ? CotizacionAprobacion::whereIn('cotizacion_id', $ids)->get()->groupBy('cotizacion_id') : collect();

        return $empresas->map(function ($e) use ($cotizaciones, $registros, $areas) {
            $cot = $e['cotizacion_id'] ? $cotizaciones->get($e['cotizacion_id']) : null;
            $porArea = $cot ? $registros->get($cot->id, collect())->keyBy('area') : collect();

            $lista = [];
            foreach ($areas as $clave => $cfg) {
                $a = $porArea->get($clave);
                $lista[] = [
                    'area'       => $clave,
                    'nombre'     => $cfg['nombre'],
                    'corto'      => isset($cfg['corto']) ? $cfg['corto'] : mb_strtoupper(mb_substr($cfg['nombre'], 0, 1, 'UTF-8'), 'UTF-8'),
                    'estado'     => $a ? $a->estado : 'pendiente',
                    'usuario'    => $a ? $a->usuario_nombre : null,
                    'comentario' => $a ? $a->comentario : null,
                    'fecha'      => $a && $a->respondida_at ? $a->respondida_at->format('d/m/Y H:i') : null,
                    'puede'      => $this->puedeAprobar($clave),
                ];
            }

            $estados = collect($lista)->pluck('estado');
            $estado  = 'sin_iniciar';
            if ($cot && $cot->aprobada) {
                $estado = $estados->contains('rechazada') ? 'rechazada'
                    : ($estados->every(function ($x) {
                        return $x === 'aprobada';
                    }) ? 'autorizada' : 'pendiente');
            }

            $e['folio']        = $cot ? $cot->folio : null;
            $e['aprobada_cli'] = $cot ? (bool) $cot->aprobada : false;
            $e['aprobaciones'] = ['estado' => $estado, 'areas' => $lista];

            // Cuadro de Cotización en Progreso:
            //   verde    = aprobada por el cliente Y autorizada por Dirección, Desarrollo de negocios y Contabilidad
            //   amarillo = hay cotización, pero falta alguna aprobación
            //   rojo     = sin cotización
            if (!empty($e['etapas'])) {
                foreach ($e['etapas'] as $i => $etapa) {
                    if (isset($etapa['clave']) && $etapa['clave'] === 'cotizacion') {
                        $e['etapas'][$i]['estado'] = $estado === 'autorizada'
                            ? 'completa'
                            : ($e['cot_total'] > 0 ? 'proceso' : 'pendiente');
                    }
                }
                // Si el progreso se calcula con las etapas (sin columna "progreso"), se recalcula
                if (!config('cotizador.clientes.columnas.progreso')) {
                    $puntos = 0;
                    foreach ($e['etapas'] as $etapa) {
                        $puntos += $etapa['estado'] === 'completa' ? 1 : ($etapa['estado'] === 'proceso' ? 0.5 : 0);
                    }
                    $e['progreso']       = (int) round($puntos / count($e['etapas']) * 100);
                    $e['progreso_color'] = $e['progreso'] >= 70 ? 'verde' : ($e['progreso'] >= 30 ? 'ambar' : 'rojo');
                }
            }

            return $e;
        });
    }

    /** ¿El usuario actual puede aprobar por esta área? (lista vacía en la configuración = cualquiera) */
    protected function puedeAprobar($area)
    {
        $permitidos = (array) config('cotizador.aprobaciones_internas.' . $area . '.usuarios');
        if (empty($permitidos)) {
            return true;
        }

        return in_array((int) $this->idUsuario(), array_map('intval', $permitidos), true);
    }

    /** Lo que pinta AprobacionesInternas.vue */
    protected function datosAprobaciones(Cotizacion $cotizacion)
    {
        $areas = (array) config('cotizador.aprobaciones_internas');
        if (!$cotizacion->exists || !Schema::hasTable('cotizacion_aprobaciones')) {
            return ['estado' => 'sin_iniciar', 'areas' => []];
        }

        $registros = $cotizacion->aprobacionesInternas()->get()->keyBy('area');
        $lista = [];
        foreach ($areas as $clave => $cfg) {
            $a = $registros->get($clave);
            $lista[] = [
                'area'       => $clave,
                'nombre'     => $cfg['nombre'],
                'estado'     => $a ? $a->estado : 'pendiente',
                'usuario'    => $a ? $a->usuario_nombre : null,
                'comentario' => $a ? $a->comentario : null,
                'fecha'      => $a && $a->respondida_at ? $a->respondida_at->format('d/m/Y H:i') : null,
                'puede'      => $this->puedeAprobar($clave),
            ];
        }

        return ['estado' => $cotizacion->estadoInterno(), 'areas' => $lista];
    }

    /* ------------------------------------------------------------------ *
     |  Tabla de clientes (simulación ClientesDemo.vue, tabla "empresas")
     * ------------------------------------------------------------------ */

    /**
     * GET clientes/datos  → datos del componente Vue <clientes-demo>
     * Filtros: q, vendedor, sucursal, estatus, desde, hasta, page, por_pagina
     */
    public function clientes(Request $request)
    {
        $consulta  = $this->empresas;
        $porPagina = (int) $request->get('por_pagina', config('cotizador.clientes.por_pagina', 25));
        $porPagina = max(5, min(100, $porPagina));
        $paginador = $consulta->paginar($consulta->filtros($request), $porPagina);

        $empresas = collect($paginador->items())->map(function ($e) {
            return [
                'id'                  => $e->id,
                'nombre'              => $e->nombre,
                'logo_url'            => $e->logo_url,
                'sucursal'            => $e->sucursal,
                'sector'              => $e->sector,
                'estatus'             => $e->estatus,
                'estatus_color'       => $e->estatus_color,
                'progreso'            => $e->progreso,
                'progreso_color'      => $e->progreso_color,
                'etapas'              => $e->etapas,
                'vendedor_linea1'     => $e->vendedor_linea1,
                'vendedor_linea2'     => $e->vendedor_linea2,
                'vendedor_iniciales'  => $e->vendedor_iniciales,
                'vendedor_avatar_url' => $e->vendedor_avatar_url,
                'cot_total'           => (int) $e->cot_total,
                'cot_aprobada'        => (bool) $e->cot_aprobada,
                'cotizacion_id'       => isset($e->cot_ultima) && $e->cot_ultima ? (int) $e->cot_ultima : null,
            ];
        });

        // Aprobaciones internas de la última cotización de cada empresa (botones de la tabla)
        $empresas = $this->conAprobacionesInternas($empresas);

        // Rutas de Vue Router / API configuradas; el componente reemplaza {id}
        $rutas = [];
        foreach ((array) config('cotizador.clientes.rutas') as $accion => $ruta) {
            $rutas[$accion] = $ruta ?: null;
        }

        return response()->json([
            'data' => $empresas,
            'meta' => [
                'total'        => $paginador->total(),
                'current_page' => $paginador->currentPage(),
                'last_page'    => $paginador->lastPage(),
                'from'         => $paginador->firstItem() ?: 0,
                'to'           => $paginador->lastItem() ?: 0,
            ],
            'opciones' => $consulta->opciones(),
            'rutas'    => $rutas,
            'avisos'   => $consulta->avisos(),
        ]);
    }

    /**
     * Botón "Exportar": CSV (se abre directo en Excel) con los filtros aplicados.
     */
    public function exportarClientes(Request $request)
    {
        $consulta = $this->empresas;
        $filtros  = $consulta->filtros($request);
        $etapas  = config('cotizador.clientes.etapas');
        $archivo = 'empresas-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($consulta, $filtros, $etapas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel respete los acentos

            $encabezado = ['#', 'Nombre', 'Sucursal', 'Sector', 'Estatus', 'Vendedor', 'Progreso %'];
            foreach ($etapas as $etapa) {
                $encabezado[] = $etapa['titulo'];
            }
            $encabezado[] = 'Cotizaciones';
            fputcsv($out, $encabezado);

            $estados = ['completa' => 'Completa', 'proceso' => 'En proceso', 'pendiente' => 'Pendiente'];
            $n = 0;

            foreach ($consulta->consulta($filtros)->cursor() as $fila) {
                $e = $consulta->decorar($fila);
                $linea = [++$n, $e->nombre, $e->sucursal, $e->sector, $e->estatus, $e->vendedor_completo, $e->progreso];
                foreach ($e->etapas as $etapa) {
                    $linea[] = $estados[$etapa['estado']];
                }
                $linea[] = $e->cot_total;
                fputcsv($out, $linea);
            }

            fclose($out);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------------ *
     |  Datos para el componente Vue <cotizacion-generador>
     * ------------------------------------------------------------------ */

    /**
     * GET cotizaciones/nueva?empresa_id=  → cotización vacía (prellenada con la empresa)
     */
    public function nueva(Request $request)
    {
        return response()->json($this->payload($this->nuevaCotizacion($request->get('empresa_id'))));
    }

    /**
     * GET cotizaciones/empresa/{empresa}  → la última cotización de la empresa,
     * o una nueva con sus datos si no tiene (o si viene ?nueva=1).
     */
    public function paraEmpresa(Request $request, $empresa)
    {
        $ultima = Cotizacion::where('empresa_id', $empresa)->orderByDesc('id')->first();

        if ($ultima && !$request->boolean('nueva')) {
            $ultima->load('conceptos', 'terminos');

            return response()->json($this->payload($ultima));
        }

        return response()->json($this->payload($this->nuevaCotizacion($empresa)));
    }

    /**
     * GET cotizaciones/{cotizacion}
     */
    public function show(Cotizacion $cotizacion)
    {
        $cotizacion->load('conceptos', 'terminos');

        return response()->json($this->payload($cotizacion));
    }

    /* ------------------------------------------------------------------ *
     |  Guardar / eliminar (JSON)
     * ------------------------------------------------------------------ */

    public function store(CotizacionRequest $request)
    {
        $cotizacion = DB::transaction(function () use ($request) {
            $cotizacion = new Cotizacion();
            $cotizacion->user_id = $this->idUsuario();
            $this->persistir($cotizacion, $request->validated(), $request->boolean('aprobada'));

            return $cotizacion;
        });

        return response()->json([
            'mensaje'    => 'Cotización ' . $cotizacion->folio . ' guardada.',
            'cotizacion' => $this->payload($cotizacion),
        ], 201);
    }

    public function update(CotizacionRequest $request, Cotizacion $cotizacion)
    {
        DB::transaction(function () use ($request, $cotizacion) {
            $this->persistir($cotizacion, $request->validated(), $request->boolean('aprobada'));
        });

        return response()->json([
            'mensaje'    => 'Cotización ' . $cotizacion->folio . ' guardada.',
            'cotizacion' => $this->payload($cotizacion),
        ]);
    }

    public function destroy(Cotizacion $cotizacion)
    {
        $cotizacion->delete();

        return response()->json(['mensaje' => 'Cotización eliminada.']);
    }

    /* ------------------------------------------------------------------ *
     |  Vista previa / PDF / envío
     * ------------------------------------------------------------------ */

    /**
     * Vista previa en HTML con lo que hay en el formulario (aunque no esté guardado).
     * Se carga dentro del iframe de la pestaña "Vista previa (PDF)".
     */
    public function preview(Request $request)
    {
        $validator = $this->validadorBorrador($request);

        if ($validator->fails()) {
            return response($this->htmlErrores($validator->errors()->all()));
        }

        $cotizacion = $this->construirBorrador($validator->validated(), $request->boolean('aprobada'));

        return view('cotizacion_pdf', $this->datosPdf($cotizacion, 'html'));
    }

    /**
     * PDF del borrador actual del formulario (botón "Imprimir / guardar como PDF").
     */
    public function pdfBorrador(Request $request)
    {
        $validator = $this->validadorBorrador($request);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Corrige los datos de la cotización antes de generar el PDF.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $cotizacion = $this->construirBorrador($validator->validated(), $request->boolean('aprobada'));

        try {
            $pdf = $this->generarPdf($cotizacion);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        // ?descargar=1 → archivo para guardar; sin parámetro → se abre en el navegador (imprimir)
        return $request->boolean('descargar')
            ? $pdf->download($cotizacion->nombreArchivo())
            : $pdf->stream($cotizacion->nombreArchivo());
    }

    /**
     * PDF de una cotización guardada. ?descargar=1 fuerza la descarga.
     */
    public function pdf(Request $request, Cotizacion $cotizacion)
    {
        $cotizacion->load('conceptos', 'terminos');
        $pdf = $this->generarPdf($cotizacion);

        return $request->boolean('descargar')
            ? $pdf->download($cotizacion->nombreArchivo())
            : $pdf->stream($cotizacion->nombreArchivo());
    }

    public function enviar(Request $request, Cotizacion $cotizacion)
    {
        $datos = $request->validate([
            'para'    => 'required|string|max:500',
            'cc'      => 'nullable|string|max:500',
            'asunto'  => 'nullable|string|max:200',
            'mensaje' => 'nullable|string|max:3000',
        ], [], ['para' => 'para']);

        $para = $this->listaCorreos(Arr::get($datos, 'para'));
        $cc   = $this->listaCorreos(Arr::get($datos, 'cc'));

        if (!empty($para['invalidos']) || !empty($cc['invalidos'])) {
            return response()->json([
                'message' => 'Revisa estos correos: ' . implode(', ', array_merge($para['invalidos'], $cc['invalidos'])) . '.',
            ], 422);
        }
        if (empty($para['validos'])) {
            return response()->json(['message' => 'Escribe al menos un correo en "Para".'], 422);
        }

        $cotizacion->load('conceptos', 'terminos');

        // Enlace para que el cliente apruebe desde el correo (uno nuevo en cada envío).
        // Si ya estaba aprobada, el enlace muestra la confirmación de la aprobación.
        $conAprobacion = Schema::hasColumn('cotizaciones', 'token_aprobacion');
        if ($conAprobacion) {
            $cotizacion->generarTokenAprobacion();
            $cotizacion->save();
        }

        try {
            $binario = $this->generarPdf($cotizacion)->output();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        try {
            $correo = Mail::to($para['validos']);
            if (!empty($cc['validos'])) {
                $correo->cc($cc['validos']);
            }
            $correo->send(new CotizacionMail(
                $cotizacion,
                $binario,
                Arr::get($datos, 'mensaje'),
                Arr::get($datos, 'asunto'),
                $this->remitente()
            ));
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo enviar el correo. Revisa la configuración MAIL_ del archivo .env. Detalle: ' . $e->getMessage(),
            ], 500);
        }

        $cotizacion->enviada_at = now();
        $cotizacion->save();

        $mensaje = 'Cotización enviada a ' . implode(', ', $para['validos']) . '.';
        if (config('mail.default') === 'log') {
            $mensaje .= ' (MAIL_MAILER=log: el correo se guardó en storage/logs, no se envió de verdad.)';
        }

        if (!$conAprobacion) {
            $mensaje .= ' (Sin botón de aprobación: falta correr "php artisan migrate" para la migración 2026_10_26_000004.)';
        }

        return response()->json([
            'ok'          => true,
            'mensaje'     => $mensaje,
            'enviada_at'  => $cotizacion->enviada_at->format('Y-m-d H:i'),
            'aprobacion'  => $conAprobacion ? $cotizacion->urlAprobacion() : null,
        ]);
    }

    /* ------------------------------------------------------------------ *
     |  Términos y condiciones (botones Guardar / Eliminar, por AJAX)
     * ------------------------------------------------------------------ */

    public function terminoStore(Request $request, Cotizacion $cotizacion)
    {
        $datos = $request->validate(['texto' => 'required|string|max:3000']);

        $termino = $cotizacion->terminos()->create([
            'texto' => trim($datos['texto']),
            'orden' => (int) $cotizacion->terminos()->max('orden') + 1,
        ]);

        return response()->json(['id' => $termino->id, 'texto' => $termino->texto]);
    }

    public function terminoUpdate(Request $request, CotizacionTermino $termino)
    {
        $datos = $request->validate(['texto' => 'required|string|max:3000']);

        $termino->update(['texto' => trim($datos['texto'])]);

        return response()->json(['id' => $termino->id, 'texto' => $termino->texto]);
    }

    public function terminoDestroy(CotizacionTermino $termino)
    {
        $termino->delete();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ *
     |  Internos
     * ------------------------------------------------------------------ */

    protected function persistir(Cotizacion $cotizacion, array $datos, $aprobada)
    {
        $datos = $this->conTipoCambio($datos, $cotizacion);

        $cotizacion->fill(Arr::only($datos, $this->camposEncabezado));

        $cotizacion->aprobada = (bool) $aprobada;
        if ($cotizacion->aprobada && !$cotizacion->aprobada_at) {
            $cotizacion->aprobada_at = now();
        }
        if (!$cotizacion->aprobada) {
            $cotizacion->aprobada_at = null;
        }

        $cotizacion->save();

        // Se reemplazan conceptos y términos por lo que viene en el formulario
        $cotizacion->conceptos()->delete();
        $cotizacion->conceptos()->saveMany($this->conceptosDesdeDatos($datos, $cotizacion->tipo_cambio));

        $cotizacion->terminos()->delete();
        $cotizacion->terminos()->saveMany($this->terminosDesdeDatos($datos));

        $cotizacion->load('conceptos', 'terminos');
        $cotizacion->total_mxn = $this->calc->resumen($cotizacion, Seccion::activas()->get())['total'];
        $cotizacion->save();

        // Si se marcó "Aprobada por cliente" en el generador, inician las aprobaciones internas
        if ($cotizacion->aprobada) {
            $cotizacion->iniciarAprobacionesInternas();
        }
    }

    protected function construirBorrador(array $datos, $aprobada)
    {
        $datos = $this->conTipoCambio($datos);

        $cotizacion = new Cotizacion(Arr::only($datos, $this->camposEncabezado));
        $cotizacion->aprobada = (bool) $aprobada;

        $cotizacion->setRelation('conceptos', $this->conceptosDesdeDatos($datos, $cotizacion->tipo_cambio));
        $cotizacion->setRelation('terminos', $this->terminosDesdeDatos($datos));

        return $cotizacion;
    }

    protected function conceptosDesdeDatos(array $datos, $tipoCambio)
    {
        $seccionesValidas = Seccion::pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        $conceptos = collect();
        $orden     = 0;

        foreach ((array) Arr::get($datos, 'conceptos', []) as $seccionId => $filas) {
            if (!in_array((int) $seccionId, $seccionesValidas, true)) {
                continue;
            }

            foreach ((array) $filas as $fila) {
                $cantidad = (float) Arr::get($fila, 'cantidad', 0);
                $usd      = (float) Arr::get($fila, 'precio_usd', 0);
                $mxn      = $usd > 0 ? 0.0 : (float) Arr::get($fila, 'precio_mxn', 0);

                $conceptos->push(new CotizacionConcepto([
                    'seccion_id'      => (int) $seccionId,
                    'codigo'          => Arr::get($fila, 'codigo'),
                    'clave_sat'       => Arr::get($fila, 'clave_sat') ?: null,
                    'descripcion'     => mb_strtoupper(trim($fila['descripcion']), 'UTF-8'),
                    'cantidad'        => $cantidad,
                    'precio_usd'      => $usd,
                    'precio_mxn'      => $mxn,
                    'importe_mxn'     => $this->calc->importe($cantidad, $usd, $mxn, $tipoCambio),
                    'descripcion_doc' => $fila['descripcion_doc'],
                    'orden'           => $orden++,
                ]));
            }
        }

        return $conceptos;
    }

    protected function terminosDesdeDatos(array $datos)
    {
        $terminos = collect();
        $orden    = 0;

        foreach ((array) Arr::get($datos, 'terminos', []) as $termino) {
            $texto = trim((string) Arr::get($termino, 'texto', ''));
            if ($texto === '') {
                continue;
            }
            $terminos->push(new CotizacionTermino(['texto' => $texto, 'orden' => $orden++]));
        }

        return $terminos;
    }

    protected function validadorBorrador(Request $request)
    {
        $reglas = (new CotizacionRequest())->rules();

        return Validator::make($request->all(), $reglas, [], (new CotizacionRequest())->attributes());
    }

    protected function htmlErrores(array $errores)
    {
        $items = '';
        foreach ($errores as $error) {
            $items .= '<li>' . e($error) . '</li>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head>'
            . '<body style="font-family:Arial,sans-serif;padding:32px;color:#7f1d1d;background:#fff5f5">'
            . '<h3 style="margin-top:0">Corrige estos datos para generar la vista previa:</h3>'
            . '<ul>' . $items . '</ul></body></html>';
    }

    /* ------------------------------------------------------------------ *
     |  Usuario que hace la cotización
     * ------------------------------------------------------------------ */

    /**
     * Usuario que hace la cotización: en producción el autenticado,
     * en desarrollo se simula el login con el id_usuario 3.
     */
    protected function usuarioActual()
    {
        if (app()->environment('production')) {
            $remitente = auth()->user();
        } else {
            $remitente = User::find(1);
        }

        return $remitente;
    }

    /** id_usuario del usuario actual */
    protected function idUsuario()
    {
        $usuario = $this->usuarioActual();

        return $usuario ? $usuario->getAuthIdentifier() : null;
    }

    /** "Fernanda Rabago" */
    protected function nombreUsuario()
    {
        $usuario = $this->usuarioActual();
        if (!$usuario) {
            return null;
        }
        $nombre = trim($usuario->nombre . ' ' . $usuario->apellidos);

        return $nombre !== '' ? $nombre : null;
    }

    /**
     * Coordinador que envía el correo (usuario en sesión): nombre, correo y firma.
     */
    protected function remitente()
    {
        $remitente = $this->usuarioActual();
        if (!$remitente) {
            return [];
        }
        $usuario = $remitente;

        $cfg   = (array) config('cotizador.firma_correo');
        $id    = $remitente->getAuthIdentifier();
        $datos = ['nombre' => trim($remitente->nombre . ' ' . $remitente->apellidos), 'email' => $remitente->email];

        // 1. Columna signature_image del usuario
        $valor = !empty($cfg['columna']) ? trim((string) data_get($usuario, $cfg['columna'], '')) : '';
        if ($valor !== '') {
            $firma = $this->resolverFirma($valor);
            if ($firma) {
                return $datos + $firma;
            }
        }

        // 2. public/images/firmas/{id_usuario}.png
        if (!empty($cfg['carpeta']) && $id) {
            foreach (['png', 'jpg', 'jpeg'] as $ext) {
                $ruta = public_path(trim($cfg['carpeta'], '/\\') . DIRECTORY_SEPARATOR . $id . '.' . $ext);
                if (is_file($ruta)) {
                    return $datos + ['firma_ruta' => $ruta];
                }
            }
        }

        return $datos;
    }

    /**
     * signature_image puede venir como:
     *  - imagen en base64 ("data:image/png;base64,..." o solo el base64)
     *  - URL (https://...)
     *  - ruta: "firmas/3.png", "storage/firmas/3.png", "images/firmas/3.png", ruta absoluta…
     */
    protected function resolverFirma($valor)
    {
        // data:image/png;base64,....
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $valor, $m)) {
            $binario = base64_decode(preg_replace('/\s+/', '', $m[2]), true);

            return $binario ? ['firma_datos' => $binario, 'firma_mime' => strtolower($m[1])] : null;
        }

        if (preg_match('#^https?://#i', $valor)) {
            return ['firma_url' => $valor];
        }

        $relativa  = ltrim(str_replace('\\', '/', $valor), '/');
        $sinPrefijo = preg_replace('#^(public/|storage/)#', '', $relativa);
        $candidatas = [
            $valor,
            public_path($relativa),
            storage_path('app/public/' . $sinPrefijo),
            storage_path('app/' . $relativa),
            storage_path('app/' . $sinPrefijo),
        ];
        foreach ($candidatas as $ruta) {
            if (@is_file($ruta)) {
                return ['firma_ruta' => $ruta];
            }
        }

        // Base64 sin encabezado (PNG empieza con iVBOR, JPG con /9j/)
        if (strlen($valor) > 200 && preg_match('#^(iVBOR|/9j/)#', $valor)) {
            $binario = base64_decode(preg_replace('/\s+/', '', $valor), true);
            if ($binario) {
                return ['firma_datos' => $binario, 'firma_mime' => strpos($valor, 'iVBOR') === 0 ? 'image/png' : 'image/jpeg'];
            }
        }

        return null;
    }

    /** "a@x.com, b@y.com; c@z.com" → ['validos' => [...], 'invalidos' => [...]] */
    protected function listaCorreos($texto)
    {
        $validos = [];
        $invalidos = [];
        foreach (preg_split('/[,;\s]+/', (string) $texto) as $correo) {
            $correo = trim($correo);
            if ($correo === '') {
                continue;
            }
            if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $validos[] = $correo;
            } else {
                $invalidos[] = $correo;
            }
        }

        return ['validos' => array_values(array_unique($validos)), 'invalidos' => $invalidos];
    }

    protected function generarPdf(Cotizacion $cotizacion)
    {
        if (!app()->bound('dompdf.wrapper')) {
            throw new \RuntimeException(
                'Falta instalar el generador de PDF. En la terminal del proyecto ejecuta: '
                    . 'composer require barryvdh/laravel-dompdf:^0.9.0'
            );
        }

        return app('dompdf.wrapper')
            ->loadView('cotizacion_pdf', $this->datosPdf($cotizacion, 'pdf'))
            ->setPaper('letter', 'portrait');
    }

    protected function datosPdf(Cotizacion $cotizacion, $modo)
    {
        $empresa = config('cotizador.empresa');
        $logo    = null;
        $ruta    = public_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $empresa['logo']));

        // El logo va incrustado (base64) para que dompdf no tenga que descargarlo
        if (is_file($ruta)) {
            $tipos = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
            $ext   = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
            $mime  = isset($tipos[$ext]) ? $tipos[$ext] : 'image/png';
            $logo  = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($ruta));
        }

        return [
            'cotizacion' => $cotizacion,
            'resumen'    => $this->calc->resumen($cotizacion, Seccion::activas()->get()),
            'empresa'    => $empresa,
            'logo'       => $logo,
            'modo'       => $modo,
        ];
    }

    /**
     * El tipo de cambio no se captura en el modal: una cotización guardada conserva
     * el suyo y una nueva toma el de config('cotizador.tipo_cambio').
     */
    protected function conTipoCambio(array $datos, Cotizacion $cotizacion = null)
    {
        if (empty($datos['tipo_cambio']) || (float) $datos['tipo_cambio'] <= 0) {
            $datos['tipo_cambio'] = ($cotizacion && $cotizacion->tipo_cambio)
                ? $cotizacion->tipo_cambio
                : (float) config('cotizador.tipo_cambio');
        }

        return $datos;
    }

    protected function nuevaCotizacion($empresaId = null)
    {
        $this->asegurarCatalogos();

        $cotizacion = new Cotizacion([
            'fecha'          => now(),
            'valida_hasta'   => now()->endOfYear(),
            'tipo_operacion' => 'EXPORTACION',
            'tipo_cambio'    => config('cotizador.tipo_cambio'),
            'vendedor'       => $this->nombreUsuario(),
        ]);

        // Datos tomados de la tabla "empresas"
        if ($empresaId && ($empresa = $this->empresas->encontrar($empresaId))) {
            $cotizacion->fill(array_filter([
                'empresa_id' => $empresa->id,
                'cliente'    => $empresa->nombre,
                'contacto'   => $empresa->contacto,
                'puerto'     => $empresa->sucursal,
                // Con origen "cotizacion" el vendedor es quien la está haciendo (usuario en sesión)
                'vendedor'   => $this->empresas->usaVendedorDeCotizacion() ? null : $empresa->vendedor_completo,
            ], function ($v) {
                return $v !== null && $v !== '';
            }));
        }

        $cotizacion->setRelation('conceptos', collect());
        // Términos de importación o exportación según el tipo de operación
        $terminos = app(CatalogoCotizador::class)->terminos($cotizacion->tipo_operacion);
        $cotizacion->setRelation('terminos', collect($terminos)->map(function ($texto) {
            return new CotizacionTermino(['texto' => $texto]);
        }));

        return $cotizacion;
    }

    /**
     * Estructura que consume el componente Vue.
     */
    protected function payload(Cotizacion $cotizacion)
    {
        $this->asegurarCatalogos();

        $secciones  = Seccion::activas()->with('catalogo')->get();
        $porSeccion = $cotizacion->conceptos->groupBy('seccion_id');

        return [
            'id'         => $cotizacion->id,
            'folio'      => $cotizacion->folio,
            'empresa_id' => $cotizacion->empresa_id,
            'header'     => [
                'cliente'        => $cotizacion->cliente,
                'contacto'       => $cotizacion->contacto,
                'fecha'          => $cotizacion->fecha ? $cotizacion->fecha->format('Y-m-d') : null,
                'valida_hasta'   => $cotizacion->valida_hasta ? $cotizacion->valida_hasta->format('Y-m-d') : null,
                'puerto'         => $cotizacion->puerto,
                'tipo_operacion' => $cotizacion->tipo_operacion,
                'producto'       => $cotizacion->producto,
                'vendedor'       => $cotizacion->vendedor,
                'tipo_cambio'    => (float) $cotizacion->tipo_cambio,
                'aprobada'       => (bool) $cotizacion->aprobada,
            ],
            'secciones' => $secciones->map(function ($seccion) use ($porSeccion) {
                return [
                    'id'         => $seccion->id,
                    'nombre'     => $seccion->nombre,
                    'color'      => $seccion->color,
                    'aplica_iva' => $seccion->aplica_iva,
                    // Impuestos, LLC y Certificado: sus códigos no dependen de la sucursal
                    'sin_sucursal' => in_array($seccion->nombre, (array) config('cotizador.bloques_sin_sucursal'), true),
                    'catalogo'   => $seccion->catalogo->map(function ($c) {
                        return $c->only(['id', 'sucursal', 'codigo', 'clave_sat', 'descripcion', 'precio_usd', 'precio_mxn', 'descripcion_doc']);
                    })->values()->all(),
                    'rows'       => $porSeccion->get($seccion->id, collect())->map(function ($c) {
                        return $c->only(['codigo', 'clave_sat', 'descripcion', 'cantidad', 'precio_usd', 'precio_mxn', 'descripcion_doc']);
                    })->values()->all(),
                ];
            })->values()->all(),
            'terminos' => $cotizacion->terminos->map(function ($t) {
                return ['id' => $t->id, 'texto' => $t->texto];
            })->values()->all(),
            'sucursales'       => app(CatalogoCotizador::class)->sucursales(),
            'avisos'           => app(CatalogoCotizador::class)->avisos(),
            'aprobaciones'     => $this->datosAprobaciones($cotizacion),
            'respuesta'        => [
                'estado'     => $cotizacion->exists ? $cotizacion->estadoRespuesta() : 'pendiente',
                'por'        => $cotizacion->aprobada_por,
                'fecha'      => optional($cotizacion->aprobada ? $cotizacion->aprobada_at : $cotizacion->cambios_solicitados_at)->format('d/m/Y H:i'),
                'comentario' => $cotizacion->respuesta_comentario,
                'enviada'    => optional($cotizacion->enviada_at)->format('d/m/Y H:i'),
            ],
            'terminosDefault'  => app(CatalogoCotizador::class)->terminos(),
            'mensajeCorreo'    => (string) config('cotizador.mensaje_correo'),
            'usuario'          => [
                'nombre'   => $this->nombreUsuario(),
                'email'    => optional($this->usuarioActual())->email,
                'simulado' => !app()->environment('production'),
            ],
            'iva'              => (float) config('cotizador.iva'),
            'descripcionesDoc' => config('cotizador.descripciones_doc'),
        ];
    }
}
