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
                <label>Vendedor</label>
                <multiselect :value="vendedorElegido" :options="opciones.vendedores" track-by="id" label="nombre"
                    placeholder="Todos los vendedores" :show-labels="false" class="ct-ms"
                    @input="(v) => filtrar('vendedor', v ? String(v.id) : '')">
                    <template slot="noResult">Sin coincidencias</template>
                    <template slot="noOptions">Aún no hay vendedores con cotizaciones</template>
                </multiselect>
            </div>
            <div>
                <label>Sucursal</label>
                <multiselect :value="filtros.sucursal || null" :options="opciones.sucursales"
                    placeholder="Todas las sucursales" :show-labels="false" class="ct-ms"
                    @input="(v) => filtrar('sucursal', v || '')">
                    <template slot="noResult">Sin coincidencias</template>
                    <template slot="noOptions">Sin sucursales</template>
                </multiselect>
            </div>
            <div>
                <label>Seguimiento</label>
                <multiselect :value="filtros.estatus || null" :options="opciones.estatus"
                    placeholder="Todos los estados" :show-labels="false" class="ct-ms"
                    @input="(v) => filtrar('estatus', v || '')">
                    <template slot="noResult">Sin coincidencias</template>
                    <template slot="noOptions">Sin estados</template>
                </multiselect>
            </div>
            <div class="ct-fecha">
                <label>Fecha</label>
                <VueCtkDateTimePicker :value="filtros.desde || null" format="YYYY-MM-DD" formatted="DD/MM/YYYY"
                    :only-date="true" :no-label="true" locale="es" color="#2f6db5" button-color="#2f6db5" label="Desde"
                    :max-date="filtros.hasta || undefined" class="ct-dp" @input="(v) => filtrar('desde', v || '')" />
                <VueCtkDateTimePicker :value="filtros.hasta || null" format="YYYY-MM-DD" formatted="DD/MM/YYYY"
                    :only-date="true" :no-label="true" locale="es" color="#2f6db5" button-color="#2f6db5" label="Hasta"
                    :min-date="filtros.desde || undefined" class="ct-dp" @input="(v) => filtrar('hasta', v || '')" />
            </div>
        </div>

        <!-- ===== Barra ===== -->
        <div class="ct-barra">
            <b><i class="fas fa-building"></i> EMPRESAS: {{ meta.total }}</b>
            <button type="button" class="ct-btn ct-btn--naranja" @click="nuevaCotizacion">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                    <path d="M14 3v5h5" />
                    <path d="M12 11v6M9 14h6" />
                </svg>
                Generar cotización
            </button>
            <a v-if="rutas.crear" :href="ruta('crear')" class="ct-btn ct-btn--verde" @click.prevent="ir('crear')"><i
                    class="fas fa-plus"></i> Crear registro</a>
            <a :href="urlExportar" class="ct-btn ct-btn--azul"><i class="fas fa-file-excel"></i> Exportar</a>
        </div>

        <div v-if="avisos.length" class="ct-avisos">
            <b>Columnas de config/cotizador.php que no existen en tu base de datos (se ignoraron):</b>
            {{ avisos.join(', ') }}
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
                            para
                            ver más resultados.</td>
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
                                <template v-for="(et, j) in e.etapas">
                                    <!-- Etapa Cotización: abre el modal del generador -->
                                    <button v-if="et.clave === 'cotizacion'" :key="j" type="button"
                                        class="ct-etapa ct-etapa--boton" :class="'ct-etapa--' + et.estado"
                                        :title="(e.cot_total ? 'Abrir cotización' : 'Generar cotización') + ' (' + (et.estado === 'completa' ? 'aprobada por el cliente y autorizada' : (et.estado === 'proceso' ? 'faltan aprobaciones' : 'sin cotización')) + ')'"
                                        :aria-label="e.cot_total ? 'Abrir cotización de ' + e.nombre : 'Generar cotización para ' + e.nombre"
                                        @click="abrirCotizacion(e)">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none"
                                            stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                                            stroke-linejoin="round" aria-hidden="true">
                                            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                                            <path d="M14 3v5h5" />
                                            <path d="M9 13h6M9 17h4" />
                                        </svg>
                                        <span v-if="e.cot_total" class="ct-cot-n">{{ e.cot_total }}</span>
                                    </button>
                                    <span v-else :key="j" class="ct-etapa" :class="'ct-etapa--' + et.estado"
                                        :title="et.titulo + ': ' + estados[et.estado]">
                                        <i :class="et.icono"></i>
                                    </span>
                                </template>
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
                                <a v-if="rutas.seguimiento" :href="ruta('seguimiento', e.id)" class="a-seg"
                                    title="Seguimiento" @click.prevent="ir('seguimiento', e.id)"><i
                                        class="fas fa-comments"></i></a>
                                <a v-if="rutas.editar" :href="ruta('editar', e.id)" class="a-edit" title="Editar"
                                    @click.prevent="ir('editar', e.id)"><i class="fas fa-pencil-alt"></i></a>
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
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';
import 'vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';
import axios from 'axios';
import CotizacionGenerador from './CotizacionGenerador.vue';

function leerUrl(route) {
    // Con Vue Router se lee de $route.query; sin router, de la barra de direcciones
    let leer;
    if (route) {
        leer = function (k) { return route.query[k] ? String(route.query[k]) : ''; };
    } else {
        const p = new URLSearchParams(window.location.search);
        leer = function (k) { return p.get(k) || ''; };
    }
    return {
        filtros: {
            q: leer('q'),
            vendedor: leer('vendedor'),
            sucursal: leer('sucursal'),
            estatus: leer('estatus'),
            desde: leer('desde'),
            hasta: leer('hasta'),
        },
        pagina: parseInt(leer('page') || '1', 10) || 1,
    };
}

/**
 * ClientesDemo: vista de prueba de la tabla de clientes (tabla "empresas").
 *   En app.js:  Vue.component('lista-auditorias', require('./components/componentescotizador/ClientesDemo.vue').default);
 *   Funciona con o sin Vue Router (sin router, los filtros se guardan con history.replaceState).
 * Los filtros se reflejan en la URL para poder compartir o recargar la búsqueda.
 */
export default {
    name: 'ClientesDemo',

    components: { CotizacionGenerador, Multiselect, VueCtkDateTimePicker },

    props: {
        baseUrl: { type: String, default: '' },
        porPagina: { type: Number, default: 25 },
    },

    data() {
        const inicial = leerUrl(this.$route);
        return {
            filtros: inicial.filtros,
            pagina: inicial.pagina,
            empresas: [],
            meta: { total: 0, current_page: 1, last_page: 1, from: 1, to: 0 },
            opciones: { vendedores: [], sucursales: [], estatus: [] },
            rutas: {},
            avisos: [],
            cargando: false,
            error: '',
            logosRotos: {},
            huboCambios: false,
            temporizador: null,
            estados: { completa: 'completa', proceso: 'en proceso', pendiente: 'pendiente' },
        };
    },

    computed: {
        vendedorElegido() {
            const id = this.filtros.vendedor;
            return id ? (this.opciones.vendedores.find(function (v) { return String(v.id) === String(id); }) || null) : null;
        },
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
                if (this.filtros[k]) p.set(k, this.filtros[k]);
            });
            if (conPagina && this.pagina > 1) p.set('page', this.pagina);
            return p.toString();
        },

        cargar() {
            this.cargando = true;
            this.error = '';

            this.reflejarEnUrl();

            const params = Object.assign({}, this.filtros, { page: this.pagina, por_pagina: this.porPagina });

            return axios.get(this.baseUrl + '/clientes/datos', { params: params })
                .then((r) => {
                    const d = this.exigirJson(r, 'data');
                    this.empresas = Array.isArray(d.data) ? d.data : [];
                    this.meta = Object.assign({ total: 0, current_page: 1, last_page: 1, from: 1, to: 0 }, d.meta);
                    this.opciones = Object.assign({ vendedores: [], sucursales: [], estatus: [] }, d.opciones);
                    this.rutas = d.rutas || {};
                    this.avisos = Array.isArray(d.avisos) ? d.avisos : [];
                    if (this.avisos.length) console.warn('[ClientesDemo] Columnas no encontradas:', this.avisos);
                })
                .catch((e) => {
                    console.error('[ClientesDemo] Error al cargar /clientes/datos:', e);
                    this.empresas = [];
                    this.error = 'No se pudo cargar la lista de empresas. ' + this.mensajesError(e)[0];
                })
                .then(() => { this.cargando = false; });
        },

        reflejarEnUrl() {
            const query = {};
            Object.keys(this.filtros).forEach((k) => { if (this.filtros[k]) query[k] = this.filtros[k]; });
            if (this.pagina > 1) query.page = String(this.pagina);

            if (this.$router) {
                // Evita el error "NavigationDuplicated" cuando la query no cambió
                if (JSON.stringify(query) !== JSON.stringify(this.$route.query)) {
                    this.$router.replace({ query: query }).catch(function () { });
                }
                return;
            }
            const qs = this.queryString(true);
            window.history.replaceState(window.history.state, '', window.location.pathname + (qs ? '?' + qs : ''));
        },


        /** Cambio en un multiselect de los filtros */
        filtrar(campo, valor) {
            if (this.filtros[campo] === valor) {
                return;
            }
            this.filtros[campo] = valor;
            this.buscar();
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
            if (pagina < 1 || pagina > this.meta.last_page) return;
            this.pagina = pagina;
            this.cargar();
        },

        ruta(accion, id) {
            const plantilla = this.rutas[accion];
            if (!plantilla) return null;
            return id === undefined ? plantilla : plantilla.replace('{id}', id);
        },

        ir(accion, id) {
            const destino = this.ruta(accion, id);
            if (this.$router) this.$router.push(destino).catch(function () { });
            else window.location.href = this.baseUrl + destino;
        },

        nuevaCotizacion() {
            // Cotización sin empresa ligada (prospecto que aún no está en "empresas")
            this.huboCambios = false;
            this.$refs.generador.nueva();
        },

        abrirCotizacion(empresa) {
            this.huboCambios = false;
            this.$refs.generador.abrirEmpresa(empresa.id);
        },

        alCerrarGenerador() {
            // Actualiza el ícono de Cotización y el progreso sin recargar la página
            if (this.huboCambios) this.cargar();
        },

        eliminar(empresa) {
            if (!window.confirm('¿Eliminar ' + empresa.nombre + '?')) return;
            axios.delete(this.baseUrl + this.ruta('eliminar', empresa.id))
                .then(() => { this.cargar(); })
                .catch((e) => { window.alert('No se pudo eliminar ' + empresa.nombre + '. ' + this.mensajesError(e)[0]); });
        },

        /* ---------- Manejo de respuestas del servidor ---------- */

        /** Convierte un error de axios en una lista de mensajes para mostrar. */
        mensajesError(error) {
            if (error && error.message === 'respuesta-no-json') {
                return ['La ruta ' + error.url + ' no devolvió datos JSON. Revisa que las rutas del cotizador estén '
                    + 'en routes/web.php ANTES del comodín /{any} y ejecuta "php artisan route:clear".'];
            }
            const r = error && error.response;
            if (r && r.status === 422 && r.data && r.data.errors) {
                return Object.keys(r.data.errors).reduce(function (lista, campo) {
                    return lista.concat(r.data.errors[campo]);
                }, []);
            }
            if (r && r.status === 419) return ['La sesión expiró. Recarga la página e intenta de nuevo.'];
            if (r && r.status === 403) return ['No tienes permiso para esta acción.'];
            if (r && r.data && r.data.message) return [r.data.message];
            return ['No hubo respuesta del servidor. Revisa tu conexión e intenta de nuevo.'];
        },

        /**
         * Verifica que el servidor respondió JSON con el campo esperado. Si la ruta no existe,
         * el comodín /{any} de web.php responde la vista "welcome" (HTML con status 200).
         */
        exigirJson(respuesta, campo) {
            const d = respuesta && respuesta.data;
            if (!d || typeof d !== 'object' || !(campo in d)) {
                const error = new Error('respuesta-no-json');
                error.url = respuesta && respuesta.config ? respuesta.config.url : '';
                throw error;
            }
            return d;
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

.ct-ms {
    min-height: 33px;
    font-size: 13px;
}

.ct-ms .multiselect__tags {
    min-height: 33px;
    padding: 6px 34px 0 10px;
    border: 1px solid #cfd6df;
    border-radius: 3px;
}

.ct-ms .multiselect__select {
    height: 31px;
}

.ct-ms .multiselect__single,
.ct-ms .multiselect__input,
.ct-ms .multiselect__placeholder {
    margin-bottom: 6px;
    padding-left: 0;
    font-size: 13px;
    color: #2d3e50;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ct-ms .multiselect__placeholder {
    color: #8a96a8;
}

.ct-ms .multiselect__content-wrapper {
    z-index: 50;
}

.ct-ms .multiselect__option {
    font-size: 13px;
    padding: 9px 12px;
    min-height: 0;
    line-height: 1.35;
    white-space: normal;
}

.ct-ms .multiselect__option--highlight {
    background: #00C09F;
}

.ct-dp .field-input {
    height: 33px !important;
    min-height: 33px !important;
    padding-top: 0 !important;
    border: 1px solid #cfd6df !important;
    border-radius: 3px !important;
    font-size: 13px !important;
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

.ct-btn--naranja {
    background: #f7931e;
    border: 0;
    cursor: pointer;
    font-family: inherit;
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

.ct-etapa--boton {
    position: relative;
    border: 0;
    padding: 0;
    cursor: pointer;
    box-shadow: 0 0 0 2px #fff, 0 0 0 3px rgba(47, 109, 181, .55);
    transition: transform .1s, box-shadow .1s;
}

.ct-etapa--boton:hover {
    transform: translateY(-1px);
    box-shadow: 0 0 0 2px #fff, 0 0 0 3px #2f6db5, 0 3px 6px rgba(31, 45, 61, .25);
}

.ct-cot-n {
    position: absolute;
    top: -7px;
    right: -7px;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 8px;
    background: #16325c;
    color: #fff;
    font-size: 9px;
    font-weight: 800;
    line-height: 15px;
    text-align: center;
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

.ct-acc .a-seg {
    color: #1f9d55;
}

.ct-acc .a-edit {
    color: #e5a32b;
}

.ct-acc .a-del {
    color: #e8173f;
}

.ct-avisos {
    padding: 10px 15px;
    background: #fff8e1;
    border-bottom: 1px solid #f3e0a0;
    color: #7a5b00;
    font-size: 13px;
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
