<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consulta de la tabla "empresas" usando los nombres de columna de
 * config('cotizador.clientes'). La usan ClientesDemo.vue,
 * la exportación y el generador de cotizaciones (para prellenar datos).
 */
class EmpresasQuery
{
    protected $cfg;

    /** Columnas configuradas que no existen en la tabla (se ignoran y se avisan) */
    protected $faltantes = [];

    public function __construct()
    {
        $cfg = config('cotizador.clientes');

        if (!is_array($cfg) || empty($cfg['tabla'])) {
            throw new \RuntimeException(
                'Falta la sección "clientes" en config/cotizador.php. Copia la versión más reciente '
                    . 'de ese archivo y ejecuta "php artisan config:clear".'
            );
        }

        // Valores por defecto para que una llave faltante no rompa la consulta
        $this->cfg = array_merge([
            'llave'           => 'id',
            'soft_delete'     => false,
            'por_pagina'      => 25,
            'logo_url'        => 'storage/',
            'columnas'        => [],
            'vendedores'      => [],
            'etapas'          => [],
            'estatus_colores' => [],
            'rutas'           => [],
        ], $cfg);

        $this->validarColumnas();
    }

    /**
     * Compara la configuración contra las columnas reales de "empresas" y "usuario".
     * Lo que no exista se desactiva (en vez de romper la consulta) y se reporta en avisos().
     */
    protected function validarColumnas()
    {
        $tabla   = $this->cfg['tabla'];
        $existen = array_map('strtolower', Schema::getColumnListing($tabla));

        if (empty($existen)) {
            throw new \RuntimeException('No existe la tabla "' . $tabla . '" (config/cotizador.php → clientes.tabla).');
        }

        if (!in_array(strtolower($this->cfg['llave']), $existen, true)) {
            throw new \RuntimeException(
                'La tabla "' . $tabla . '" no tiene la columna "' . $this->cfg['llave'] . '". '
                    . 'Pon en clientes.llave el nombre de su llave primaria. Columnas encontradas: '
                    . implode(', ', Schema::getColumnListing($tabla))
            );
        }

        foreach ((array) $this->cfg['columnas'] as $clave => $columna) {
            if ($columna && !in_array(strtolower($columna), $existen, true)) {
                $this->faltantes[] = $tabla . '.' . $columna;
                $this->cfg['columnas'][$clave] = null;
            }
        }

        foreach ($this->cfg['etapas'] as $i => $etapa) {
            if (!empty($etapa['columna']) && !in_array(strtolower($etapa['columna']), $existen, true)) {
                $this->faltantes[] = $tabla . '.' . $etapa['columna'];
                $this->cfg['etapas'][$i]['columna'] = null;   // la etapa se muestra como pendiente
            }
        }

        // Tabla de usuarios (vendedor)
        $v = $this->cfg['vendedores'];
        if (!empty($v['tabla'])) {
            $deUsuario = array_map('strtolower', Schema::getColumnListing($v['tabla']));
            if (empty($deUsuario)) {
                $this->faltantes[] = 'tabla ' . $v['tabla'];
                $this->cfg['vendedores']['tabla'] = null;
            } else {
                foreach (['llave', 'nombre', 'apellidos', 'avatar'] as $campo) {
                    if (!empty($v[$campo]) && !in_array(strtolower($v[$campo]), $deUsuario, true)) {
                        $this->faltantes[] = $v['tabla'] . '.' . $v[$campo];
                        $this->cfg['vendedores'][$campo] = null;
                    }
                }
                if (empty($this->cfg['vendedores']['llave']) || empty($this->cfg['vendedores']['nombre'])) {
                    $this->cfg['vendedores']['tabla'] = null;
                }
            }
        }
    }

    /** Columnas de config/cotizador.php que no se encontraron en la base de datos. */
    public function avisos()
    {
        return array_values(array_unique($this->faltantes));
    }

    public function config($clave, $defecto = null)
    {
        return Arr::get($this->cfg, $clave, $defecto);
    }

    public function col($clave)
    {
        return Arr::get($this->cfg, 'columnas.' . $clave);
    }

    public function filtros(Request $request)
    {
        return [
            'q'        => trim((string) $request->get('q', '')),
            'vendedor' => $request->get('vendedor'),
            'sucursal' => $request->get('sucursal'),
            'estatus'  => $request->get('estatus'),
            'desde'    => $request->get('desde'),
            'hasta'    => $request->get('hasta'),
        ];
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    public function consulta(array $f = [])
    {
        $tabla  = $this->cfg['tabla'];
        $pk     = $this->cfg['llave'];
        $q      = DB::table($tabla . ' as e');
        $select = ['e.' . $pk . ' as id'];

        foreach (['nombre', 'logo', 'sucursal', 'sector', 'estatus', 'contacto', 'progreso', 'fecha'] as $clave) {
            if ($columna = $this->col($clave)) {
                $select[] = 'e.' . $columna . ' as ' . $clave;
            }
        }

        foreach ($this->cfg['etapas'] as $etapa) {
            if (!empty($etapa['columna'])) {
                $select[] = 'e.' . $etapa['columna'] . ' as etapa_' . $etapa['clave'];
            }
        }

        // Vendedor
        if ($this->usaTablaVendedores()) {
            $fk = $this->col('vendedor_id');
            $v  = $this->cfg['vendedores'];
            $q->leftJoin($v['tabla'] . ' as v', 'v.' . $v['llave'], '=', 'e.' . $fk);
            $select[] = 'e.' . $fk . ' as vendedor_id';
            $select[] = 'v.' . $v['nombre'] . ' as vendedor_nombre';
            if (!empty($v['apellidos'])) {
                $select[] = 'v.' . $v['apellidos'] . ' as vendedor_apellidos';
            }
            if (!empty($v['avatar'])) {
                $select[] = 'v.' . $v['avatar'] . ' as vendedor_avatar';
            }
        } elseif ($c = $this->col('vendedor')) {
            // El nombre del vendedor está escrito directo en "empresas"
            $select[] = 'e.' . $c . ' as vendedor_nombre';
        }

        // Resumen de cotizaciones por empresa (etapa "Cotización" y vendedor)
        $cotizaciones = DB::table('cotizaciones')
            ->selectRaw('empresa_id, COUNT(*) as cot_total, MAX(aprobada) as cot_aprobada, MAX(id) as cot_ultima')
            ->whereNull('deleted_at')
            ->whereNotNull('empresa_id')
            ->groupBy('empresa_id');

        $q->leftJoinSub($cotizaciones, 'c', 'c.empresa_id', '=', 'e.' . $pk);
        $select[] = DB::raw('COALESCE(c.cot_total, 0) as cot_total');
        $select[] = DB::raw('COALESCE(c.cot_aprobada, 0) as cot_aprobada');
        $select[] = 'c.cot_ultima as cot_ultima';   // última cotización (aprobaciones internas)

        // Vendedor = usuario que hizo la última cotización de la empresa
        if ($this->usaVendedorDeCotizacion()) {
            $v = $this->cfg['vendedores'];
            $q->leftJoin('cotizaciones as cu', 'cu.id', '=', 'c.cot_ultima')
                ->leftJoin($v['tabla'] . ' as v', 'v.' . $this->llaveUsuario(), '=', 'cu.user_id');
            $select[] = 'cu.user_id as vendedor_id';
            $select[] = 'v.' . $v['nombre'] . ' as vendedor_nombre';
            if (!empty($v['apellidos'])) {
                $select[] = 'v.' . $v['apellidos'] . ' as vendedor_apellidos';
            }
            if (!empty($v['avatar'])) {
                $select[] = 'v.' . $v['avatar'] . ' as vendedor_avatar';
            }
        }

        $q->select($select);

        if ($this->config('soft_delete')) {
            $q->whereNull('e.deleted_at');
        }

        // Filtros
        if (!empty($f['q']) && ($c = $this->col('nombre'))) {
            $q->where('e.' . $c, 'like', '%' . $f['q'] . '%');
        }
        if (!empty($f['vendedor'])) {
            if ($this->usaVendedorDeCotizacion()) {
                $q->where('cu.user_id', $f['vendedor']);
            } elseif ($this->usaTablaVendedores()) {
                $q->where('e.' . $this->col('vendedor_id'), $f['vendedor']);
            } elseif ($c = $this->col('vendedor')) {
                $q->where('e.' . $c, $f['vendedor']);
            }
        }
        if (!empty($f['sucursal']) && ($c = $this->col('sucursal'))) {
            $q->where('e.' . $c, $f['sucursal']);
        }
        if (!empty($f['estatus']) && ($c = $this->col('estatus'))) {
            $q->where('e.' . $c, $f['estatus']);
        }
        if (!empty($f['desde']) && ($c = $this->col('fecha'))) {
            $q->whereDate('e.' . $c, '>=', $f['desde']);
        }
        if (!empty($f['hasta']) && ($c = $this->col('fecha'))) {
            $q->whereDate('e.' . $c, '<=', $f['hasta']);
        }

        $q->orderBy('e.' . ($this->col('nombre') ?: $pk));

        return $q;
    }

    public function paginar(array $filtros, $porPagina = null)
    {
        $paginador = $this->consulta($filtros)
            ->paginate($porPagina ?: $this->config('por_pagina', 25))
            ->withQueryString();

        $self = $this;
        $paginador->getCollection()->transform(function ($fila) use ($self) {
            return $self->decorar($fila);
        });

        return $paginador;
    }

    public function encontrar($id)
    {
        $fila = $this->consulta()->where('e.' . $this->cfg['llave'], $id)->first();

        return $fila ? $this->decorar($fila) : null;
    }

    /** Opciones de los combos de filtros */
    public function opciones()
    {
        $tabla = $this->cfg['tabla'];
        $base  = function () use ($tabla) {
            $q = DB::table($tabla);
            if ($this->config('soft_delete')) {
                $q->whereNull('deleted_at');
            }
            return $q;
        };

        $distintos = function ($clave) use ($base) {
            $c = $this->col($clave);
            if (!$c) {
                return collect();
            }
            return $base()->whereNotNull($c)->where($c, '<>', '')->distinct()->orderBy($c)->pluck($c);
        };

        $vendedores = collect();
        if ($this->usaVendedorDeCotizacion()) {
            // Usuarios que han hecho al menos una cotización ligada a una empresa
            $v = $this->cfg['vendedores'];
            $vendedores = DB::table($v['tabla'])
                ->whereIn($this->llaveUsuario(), DB::table('cotizaciones')
                    ->select('user_id')
                    ->whereNotNull('user_id')
                    ->whereNotNull('empresa_id')
                    ->whereNull('deleted_at'))
                ->orderBy($v['nombre'])
                ->get([$this->llaveUsuario() . ' as id', $v['nombre'] . ' as nombre']);
        } elseif ($this->usaTablaVendedores()) {
            $fk = $this->col('vendedor_id');
            $v  = $this->cfg['vendedores'];
            $vendedores = DB::table($v['tabla'])
                ->whereIn($v['llave'], $base()->select($fk)->whereNotNull($fk))
                ->orderBy($v['nombre'])
                ->get([$v['llave'] . ' as id', $v['nombre'] . ' as nombre']);
        } elseif ($this->col('vendedor')) {
            $vendedores = $distintos('vendedor')->map(function ($nombre) {
                return ['id' => $nombre, 'nombre' => $nombre];
            })->values();
        }

        return [
            'vendedores' => $vendedores,
            'sucursales' => $distintos('sucursal'),
            'estatus'    => $distintos('estatus'),
        ];
    }

    /**
     * Agrega a la fila los datos ya listos para la vista.
     */
    public function decorar($e)
    {
        $etapas = [];
        $puntos = 0;

        foreach ($this->cfg['etapas'] as $etapa) {
            if ($etapa['clave'] === 'cotizacion' && empty($etapa['columna'])) {
                $estado = $e->cot_aprobada ? 'completa' : ($e->cot_total > 0 ? 'proceso' : 'pendiente');
            } elseif (!empty($etapa['columna'])) {
                $prop   = 'etapa_' . $etapa['clave'];
                $estado = $this->estadoEtapa(isset($e->$prop) ? $e->$prop : null, Arr::get($etapa, 'tipo', 'booleano'));
            } else {
                $estado = 'pendiente';
            }

            $puntos += $estado === 'completa' ? 1 : ($estado === 'proceso' ? 0.5 : 0);
            $etapas[] = ['clave' => $etapa['clave'], 'titulo' => $etapa['titulo'], 'icono' => $etapa['icono'], 'estado' => $estado];
        }

        if (isset($e->progreso) && $e->progreso !== null && $e->progreso !== '') {
            $progreso = (int) round((float) $e->progreso);
        } else {
            $progreso = count($etapas) ? (int) round($puntos / count($etapas) * 100) : 0;
        }
        $e->progreso       = max(0, min(100, $progreso));
        $e->progreso_color = $e->progreso >= 70 ? 'verde' : ($e->progreso >= 30 ? 'ambar' : 'rojo');
        $e->etapas         = $etapas;

        // Logo
        $e->logo_url = $this->url(isset($e->logo) ? $e->logo : null);

        // Estatus
        $estatus = isset($e->estatus) ? trim((string) $e->estatus) : '';
        $e->estatus       = $estatus;
        $e->estatus_color = Arr::get($this->cfg['estatus_colores'], mb_strtolower($estatus, 'UTF-8'), 'gris');

        // Vendedor en dos líneas: "Ricardo Arturo" / "Olivera Guerrero"
        $nombre    = trim((string) (isset($e->vendedor_nombre) ? $e->vendedor_nombre : ''));
        $apellidos = trim((string) (isset($e->vendedor_apellidos) ? $e->vendedor_apellidos : ''));
        if ($apellidos === '' && $nombre !== '') {
            $partes = preg_split('/\s+/', $nombre);
            if (count($partes) > 2) {
                $apellidos = implode(' ', array_slice($partes, -2));
                $nombre    = implode(' ', array_slice($partes, 0, -2));
            } elseif (count($partes) === 2) {
                list($nombre, $apellidos) = $partes;
            }
        }
        $e->vendedor_linea1   = $nombre;
        $e->vendedor_linea2   = $apellidos;
        $e->vendedor_completo = trim($nombre . ' ' . $apellidos);
        $e->vendedor_iniciales = mb_strtoupper(
            mb_substr($nombre, 0, 1, 'UTF-8') . mb_substr($apellidos, 0, 1, 'UTF-8'),
            'UTF-8'
        );
        $e->vendedor_avatar_url = $this->url(isset($e->vendedor_avatar) ? $e->vendedor_avatar : null);

        foreach (['nombre', 'sucursal', 'sector', 'contacto'] as $campo) {
            if (!isset($e->$campo)) {
                $e->$campo = null;
            }
        }

        return $e;
    }

    public function usaVendedorDeCotizacion()
    {
        $v = $this->cfg['vendedores'];

        return Arr::get($v, 'origen', 'cotizacion') === 'cotizacion' && !empty($v['tabla']) && !empty($v['nombre']);
    }

    protected function usaTablaVendedores()
    {
        return Arr::get($this->cfg['vendedores'], 'origen') === 'empresa'
            && $this->col('vendedor_id')
            && !empty($this->cfg['vendedores']['tabla']);
    }

    protected function llaveUsuario()
    {
        return Arr::get($this->cfg['vendedores'], 'llave', 'id') ?: 'id';
    }


    protected function estadoEtapa($valor, $tipo)
    {
        if ($tipo === 'tres') {
            $n = (int) $valor;
            return $n >= 2 ? 'completa' : ($n === 1 ? 'proceso' : 'pendiente');
        }

        $v = mb_strtolower(trim((string) $valor), 'UTF-8');

        return in_array($v, ['1', 'true', 'si', 'sí', 'completo', 'completa'], true) ? 'completa' : 'pendiente';
    }

    protected function url($ruta)
    {
        $ruta = trim((string) $ruta);
        if ($ruta === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $ruta) || strpos($ruta, 'data:') === 0) {
            return $ruta;
        }

        return asset(rtrim($this->config('logo_url', ''), '/') . '/' . ltrim($ruta, '/'));
    }
}
