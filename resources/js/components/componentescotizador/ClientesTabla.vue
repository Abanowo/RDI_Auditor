<template>
    <div class="ct">
        <!-- ===== Filtros ===== -->
        <div class="ct-filtros">
            <div>
                <label for="ct-q">Nombre</label>
                <div class="ct-in"><i class="fas fa-search"></i>
                    <input id="ct-q" v-model.trim="filtros.q" placeholder="Buscar por nombre" autocomplete="off"
                        @input="buscarConPausa">
                </div>
            </div>
            <div>
                <label for="ct-vend">Vendedor</label>
                <div class="ct-in"><i class="fas fa-user-tie"></i>
                    <select id="ct-vend" v-model="filtros.vendedor" @change="buscar">
                        <option value="">Todos los vendedores</option>
                        <option v-for="v in opciones.vendedores" :key="v.id" :value="String(v.id)">{{ v.nombre }}
                        </option>
                    </select>
                </div>
            </div>
            <div>
                <label for="ct-suc">Sucursal</label>
                <div class="ct-in"><i class="fas fa-map-marker-alt"></i>
                    <select id="ct-suc" v-model="filtros.sucursal" @change="buscar">
                        <option value="">Todas las sucursales</option>
                        <option v-for="s in opciones.sucursales" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="ct-est">Seguimiento</label>
                <div class="ct-in"><i class="fas fa-flag"></i>
                    <select id="ct-est" v-model="filtros.estatus" @change="buscar">
                        <option value="">Todos los estados</option>
                        <option v-for="s in opciones.estatus" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
            </div>
            <div class="ct-fecha">
                <label>Fecha</label>
                <div class="ct-in"><i class="fas fa-calendar-alt"></i>
                    <input v-model="filtros.desde" :type="filtros.desde ? 'date' : 'text'" placeholder="Desde"
                        aria-label="Desde" @focus="$event.target.type = 'date'" @change="buscar">
                </div>
                <div class="ct-in"><i class="fas fa-calendar-alt"></i>
                    <input v-model="filtros.hasta" :type="filtros.hasta ? 'date' : 'text'" placeholder="Hasta"
                        aria-label="Hasta" @focus="$event.target.type = 'date'" @change="buscar">
                </div>
            </div>
        </div>

        <!-- ===== Barra ===== -->
        <div class="ct-barra">
            <b><i class="fas fa-building"></i> EMPRESAS: {{ meta.total }}</b>
            <a v-if="rutas.crear" :href="rutas.crear" class="ct-btn ct-btn--verde"><i class="fas fa-plus"></i> Crear
                registro</a>
            <a :href="urlExportar" class="ct-btn ct-btn--azul"><i class="fas fa-file-excel"></i> Exportar</a>
        </div>

        <!-- ===== Tabla ===== -->
        <div class="ct-scroll" :class="{ 'is-cargando': cargando }">
            <table class="ct-tabla">
                <thead>
                    <tr>
                        <th class="ct-centro">#</th>
                        <th><i class="fas fa-building"></i> Nombre</th>
                        <th><i class="fas fa-chart-line"></i> Progreso</th>
                        <th><i class="fas fa-map-marker-alt"></i> Sucursal</th>
                        <th><i class="fas fa-industry"></i> Sector</th>
                        <th><i class="fas fa-flag"></i> Estatus</th>
                        <th><i class="fas fa-user-tie"></i> Vendedor</th>
                        <th><i class="fas fa-cogs"></i> Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="error">
                        <td colspan="8" class="ct-vacio ct-error">{{ error }}</td>
                    </tr>
                    <tr v-else-if="!cargando && !empresas.length">
                        <td colspan="8" class="ct-vacio">Ninguna empresa coincide con los filtros. Cambia o borra alguno
                            para ver más resultados.</td>
                    </tr>

                    <tr v-for="(e, i) in empresas" :key="e.id">
                        <td class="ct-centro"><span class="ct-n">{{ meta.from + i }}</span></td>
                        <td>
                            <div class="ct-nombre">
                                <div class="ct-logo">
                                    <img v-if="e.logo_url && !logosRotos[e.id]" :src="e.logo_url" alt="" loading="lazy"
                                        @error="$set(logosRotos, e.id, true)">
                                    <i v-else class="fas fa-building"></i>
                                </div>
                                <span>{{ e.nombre }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="ct-etapas">
                                <span v-for="(et, j) in e.etapas" :key="j" class="ct-etapa"
                                    :class="'ct-etapa--' + et.estado" :title="et.titulo + ': ' + estados[et.estado]">
                                    <i :class="et.icono"></i>
                                </span>
                            </div>
                            <div class="ct-prog" :class="'ct-c-' + e.progreso_color">
                                <div class="ct-prog-txt"><span>Progreso</span><b>{{ e.progreso }}%</b></div>
                                <div class="ct-prog-bar">
                                    <div :style="{ width: e.progreso + '%' }"></div>
                                </div>
                            </div>
                        </td>
                        <td class="ct-suc">{{ e.sucursal || '—' }}</td>
                        <td class="ct-sector">{{ e.sector || '—' }}</td>
                        <td>
                            <span v-if="e.estatus" class="ct-pill" :class="'ct-pill--' + e.estatus_color">{{ e.estatus
                            }}</span>
                            <template v-else>—</template>
                        </td>
                        <td>
                            <div v-if="e.vendedor_linea1 || e.vendedor_linea2" class="ct-vend">
                                <div class="ct-av">
                                    <img v-if="e.vendedor_avatar_url" :src="e.vendedor_avatar_url" alt="">
                                    <span v-else>{{ e.vendedor_iniciales }}</span>
                                </div>
                                <div>{{ e.vendedor_linea1 }}<small>{{ e.vendedor_linea2 }}</small></div>
                            </div>
                            <template v-else>—</template>
                        </td>
                        <td>
                            <div class="ct-acc">
                                <button type="button" class="a-cot"
                                    :title="e.cot_total ? 'Abrir cotización' : 'Crear cotización'"
                                    @click="abrirCotizacion(e)"><i class="fas fa-file-alt"></i></button>
                                <a v-if="rutas.seguimiento" :href="ruta('seguimiento', e.id)" class="a-seg"
                                    title="Seguimiento"><i class="fas fa-comments"></i></a>
                                <a v-if="rutas.editar" :href="ruta('editar', e.id)" class="a-edit" title="Editar"><i
                                        class="fas fa-pencil-alt"></i></a>
                                <button v-if="rutas.eliminar" type="button" class="a-del" title="Eliminar"
                                    @click="eliminar(e)"><i class="fas fa-times-circle"></i></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ===== Paginación ===== -->
        <div v-if="meta.last_page > 1" class="ct-pag">
            <span>Mostrando {{ meta.from }}–{{ meta.to }} de {{ meta.total }}</span>
            <div>
                <button type="button" :disabled="meta.current_page <= 1" @click="irA(meta.current_page - 1)">&lsaquo;
                    Anterior</button>
                <span>Página {{ meta.current_page }} de {{ meta.last_page }}</span>
                <button type="button" :disabled="meta.current_page >= meta.last_page"
                    @click="irA(meta.current_page + 1)">Siguiente &rsaquo;</button>
            </div>
        </div>

        <cotizacion-generador ref="generador" :base-url="baseUrl" @guardada="huboCambios = true"
            @cerrado="alCerrarGenerador" />
    </div>
</template>

<script>
import http, { mensajesError } from './http';
import CotizacionGenerador from './CotizacionGenerador.vue';

function leerUrl() {
    const p = new URLSearchParams(window.location.search);
    return {
        filtros: {
            q: p.get('q') || '',
            vendedor: p.get('vendedor') || '',
            sucursal: p.get('sucursal') || '',
            estatus: p.get('estatus') || '',
            desde: p.get('desde') || '',
            hasta: p.get('hasta') || '',
        },
        pagina: parseInt(p.get('page') || '1', 10) || 1,
    };
}

/**
 * Tabla de clientes (tabla "empresas").
 *   <clientes-tabla base-url="{{ url('/') }}" :por-pagina="25"></clientes-tabla>
 * Los filtros se reflejan en la URL para poder compartir o recargar la búsqueda.
 */
export default {
    name: 'ClientesTabla',

    components: { CotizacionGenerador },

    props: {
        baseUrl: { type: String, default: '' },
        porPagina: { type: Number, default: 25 },
    },

    data() {
        const inicial = leerUrl();
        return {
            filtros: inicial.filtros,
            pagina: inicial.pagina,
            empresas: [],
            meta: { total: 0, current_page: 1, last_page: 1, from: 1, to: 0 },
            opciones: { vendedores: [], sucursales: [], estatus: [] },
            rutas: {},
            cargando: false,
            error: '',
            logosRotos: {},
            huboCambios: false,
            temporizador: null,
            estados: { completa: 'completa', proceso: 'en proceso', pendiente: 'pendiente' },
        };
    },

    computed: {
        urlExportar() {
            const qs = this.queryString(false);
            return this.baseUrl + '/clientes/exportar' + (qs ? '?' + qs : '');
        },
    },

    created() {
        this.cargar();
    },

    methods: {
        queryString(conPagina) {
            const p = new URLSearchParams();
            Object.keys(this.filtros).forEach((k) => {
                if (this.filtros[k]) {
                    p.set(k, this.filtros[k]);
                }
            });
            if (conPagina && this.pagina > 1) {
                p.set('page', this.pagina);
            }
            return p.toString();
        },

        cargar() {
            this.cargando = true;
            this.error = '';

            const qs = this.queryString(true);
            window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));

            const params = Object.assign({}, this.filtros, { page: this.pagina, por_pagina: this.porPagina });

            return http.get(this.baseUrl + '/clientes/datos', { params: params })
                .then((r) => {
                    this.empresas = r.data.data;
                    this.meta = r.data.meta;
                    this.opciones = r.data.opciones;
                    this.rutas = r.data.rutas;
                })
                .catch((e) => {
                    this.empresas = [];
                    this.error = 'No se pudo cargar la lista de empresas. ' + mensajesError(e)[0];
                })
                .then(() => { this.cargando = false; });
        },

        buscar() {
            this.pagina = 1;
            this.cargar();
        },

        buscarConPausa() {
            clearTimeout(this.temporizador);
            this.temporizador = setTimeout(this.buscar, 450);
        },

        irA(pagina) {
            if (pagina < 1 || pagina > this.meta.last_page) {
                return;
            }
            this.pagina = pagina;
            this.cargar();
        },

        ruta(accion, id) {
            const plantilla = this.rutas[accion];
            return plantilla ? plantilla.replace('__ID__', id) : null;
        },

        abrirCotizacion(empresa) {
            this.huboCambios = false;
            this.$refs.generador.abrirEmpresa(empresa.id);
        },

        alCerrarGenerador() {
            // Actualiza el ícono de Cotización y el progreso sin recargar la página
            if (this.huboCambios) {
                this.cargar();
            }
        },

        eliminar(empresa) {
            if (!window.confirm('¿Eliminar ' + empresa.nombre + '?')) {
                return;
            }
            http.delete(this.ruta('eliminar', empresa.id))
                .then(() => { this.cargar(); })
                .catch((e) => { window.alert('No se pudo eliminar ' + empresa.nombre + '. ' + mensajesError(e)[0]); });
        },
    },
};
</script>

<style>
.ct {
    --ct-navy: #16325c;
    --ct-blue: #2f6db5;
    --ct-line: #edf1f6;
    --ct-muted: #8a96a8;
    --ct-rojo: #e8173f;
    --ct-ambar: #f2b705;
    --ct-verde: #17c08a;
    color: #1f2d3d;
    background: #fff;
    border: 1px solid var(--ct-line);
    border-radius: 4px;
}

.ct *,
.ct *::before,
.ct *::after {
    box-sizing: border-box;
}

.ct :focus-visible {
    outline: 2px solid var(--ct-blue);
    outline-offset: 2px;
}

.ct-filtros {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr .5fr .5fr;
    gap: 10px;
    padding: 18px 15px;
}

.ct-filtros label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #2d3e50;
    margin-bottom: 5px;
}

.ct-fecha {
    grid-column: span 2;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}

.ct-fecha label {
    grid-column: span 2;
    margin-bottom: 0 !important;
}

.ct-in {
    position: relative;
}

.ct-in i {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #b8c2cf;
    font-size: 13px;
    pointer-events: none;
}

.ct-in input,
.ct-in select {
    width: 100%;
    height: 33px;
    padding: 0 10px 0 28px;
    border: 1px solid #cfd6df;
    border-radius: 3px;
    background: #fff;
    font-size: 13px;
    color: #2d3e50;
}

.ct-in input::placeholder {
    color: #b8c2cf;
}

.ct-in input:focus,
.ct-in select:focus {
    outline: none;
    border-color: var(--ct-blue);
    box-shadow: 0 0 0 2px rgba(47, 109, 181, .14);
}

.ct-barra {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 15px;
    background: linear-gradient(90deg, #2f80d0, #2a5591);
    color: #fff;
}

.ct-barra b {
    flex: 1;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .03em;
}

.ct-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 24px;
    padding: 0 14px;
    border-radius: 2px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    color: #fff !important;
    text-decoration: none !important;
}

.ct-btn:hover {
    filter: brightness(1.08);
}

.ct-btn--verde {
    background: #2ebf6b;
}

.ct-btn--azul {
    background: #3d99f0;
}

.ct-scroll {
    overflow-x: auto;
    transition: opacity .15s;
}

.ct-scroll.is-cargando {
    opacity: .55;
    pointer-events: none;
}

.ct-tabla {
    width: 100%;
    border-collapse: collapse;
    min-width: 1180px;
    margin: 0;
}

.ct-tabla thead th {
    background: #eaf6fd;
    color: var(--ct-navy);
    font-size: 13px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .03em;
    padding: 18px 10px;
    text-align: left;
    white-space: nowrap;
    border: 0;
}

.ct-tabla thead th i {
    margin-right: 4px;
}

.ct-tabla td {
    padding: 14px 10px;
    border-top: 0;
    border-bottom: 1px solid var(--ct-line);
    vertical-align: middle;
    font-size: 14px;
}

.ct-tabla tbody tr:hover {
    background: #fafcff;
}

.ct-centro {
    text-align: center !important;
}

.ct-n {
    display: inline-grid;
    place-items: center;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 10px;
    background: #eaf2fb;
    font-size: 12px;
    font-weight: 700;
    color: var(--ct-navy);
}

.ct-nombre {
    display: flex;
    align-items: center;
    gap: 14px;
}

.ct-nombre span {
    font-weight: 600;
    text-transform: uppercase;
    max-width: 170px;
    line-height: 1.45;
}

.ct-logo {
    width: 76px;
    height: 50px;
    flex: none;
    display: grid;
    place-items: center;
    background: #fff;
    border-radius: 3px;
    box-shadow: 0 1px 5px rgba(31, 45, 61, .18);
    overflow: hidden;
}

.ct-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.ct-logo i {
    color: #5aa7e6;
    font-size: 20px;
}

.ct-etapas {
    display: flex;
    gap: 4px;
    margin-bottom: 12px;
}

.ct-etapa {
    width: 42px;
    height: 29px;
    display: grid;
    place-items: center;
    border-radius: 3px;
    color: #fff;
    font-size: 13px;
}

.ct-etapa--pendiente {
    background: var(--ct-rojo);
}

.ct-etapa--proceso {
    background: var(--ct-ambar);
}

.ct-etapa--completa {
    background: var(--ct-verde);
}

.ct-prog {
    width: 420px;
    max-width: 100%;
}

.ct-prog-txt {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: var(--ct-muted);
    margin-bottom: 4px;
}

.ct-prog-bar {
    height: 6px;
    border-radius: 3px;
    background: #eef1f5;
    overflow: hidden;
}

.ct-prog-bar div {
    height: 100%;
    border-radius: 3px;
    transition: width .3s;
}

.ct-c-rojo b {
    color: #b4123b;
}

.ct-c-rojo .ct-prog-bar div {
    background: linear-gradient(90deg, #ff6b6b, #e2384d);
}

.ct-c-ambar b {
    color: #8a6a00;
}

.ct-c-ambar .ct-prog-bar div {
    background: linear-gradient(90deg, #f6e27a, #e9c43c);
}

.ct-c-verde b {
    color: #14713f;
}

.ct-c-verde .ct-prog-bar div {
    background: linear-gradient(90deg, #5bd18b, #2fae63);
}

.ct-suc {
    color: #2f5fa0;
}

.ct-sector {
    color: #5b3cc4;
}

.ct-pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 14px;
}

.ct-pill--rojo {
    background: #fdecef;
    color: #b4123b;
}

.ct-pill--ambar {
    background: #fdf8e3;
    color: #8a6a00;
}

.ct-pill--verde {
    background: #e8f8ef;
    color: #14713f;
}

.ct-pill--gris {
    background: #f1f3f6;
    color: #5c6878;
}

.ct-vend {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ct-av {
    position: relative;
    width: 30px;
    height: 30px;
    flex: none;
}

.ct-av img,
.ct-av span {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    display: grid;
    place-items: center;
    background: #dde7f3;
    color: var(--ct-navy);
    font-size: 11px;
    font-weight: 800;
    border: 1px solid #cfd6df;
}

.ct-av::after {
    content: "";
    position: absolute;
    right: -1px;
    bottom: 0;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #28c76f;
    border: 2px solid #fff;
}

.ct-vend div {
    line-height: 1.6;
}

.ct-vend small {
    display: block;
    color: var(--ct-muted);
    font-size: 14px;
}

.ct-acc {
    display: flex;
    align-items: center;
    gap: 14px;
    white-space: nowrap;
}

.ct-acc a,
.ct-acc button {
    border: 0;
    background: none;
    padding: 0;
    font-size: 19px;
    line-height: 1;
    cursor: pointer;
    transition: transform .1s;
}

.ct-acc a:hover,
.ct-acc button:hover {
    transform: scale(1.12);
}

.ct-acc .a-cot {
    color: #2f80d0;
}

.ct-acc .a-seg {
    color: #1f9d55;
}

.ct-acc .a-edit {
    color: #e5a32b;
}

.ct-acc .a-del {
    color: #e8173f;
}

.ct-vacio {
    padding: 40px !important;
    text-align: center;
    color: var(--ct-muted);
}

.ct-error {
    color: #b4123b;
}

.ct-pag {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 15px;
    font-size: 13px;
    color: var(--ct-muted);
}

.ct-pag>div {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ct-pag button {
    height: 30px;
    padding: 0 12px;
    border: 1px solid #cfd6df;
    border-radius: 3px;
    background: #fff;
    color: var(--ct-navy);
    font-weight: 600;
    cursor: pointer;
}

.ct-pag button:disabled {
    opacity: .45;
    cursor: not-allowed;
}

@media (max-width: 1100px) {
    .ct-filtros {
        grid-template-columns: 1fr 1fr;
    }
}

@media (prefers-reduced-motion: reduce) {

    .ct-scroll,
    .ct-prog-bar div,
    .ct-acc a,
    .ct-acc button {
        transition: none;
    }
}
</style>
