<template>
    <div v-if="visible" class="cz-overlay" role="dialog" aria-modal="true"
        aria-label="Generador de cotización comercial" @click.self="cerrar">
        <div class="cz">
            <!-- ===== Encabezado ===== -->
            <header class="cz-top">
                <div class="cz-brand">
                    <div class="cz-icon">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                            <path d="M14 3v5h5" />
                            <path d="M12 11v6" />
                            <path d="m9 14 3 3 3-3" />
                        </svg>
                    </div>
                    <div>
                        <h1>Generador de cotización comercial</h1>
                        <p :title="folio ? 'Folio ' + folio : 'Cotización nueva'">Prospecto/cliente: <b>{{
                                header.cliente || '—' }}</b></p>
                    </div>
                </div>
                <nav class="cz-tabs">
                    <button type="button" :class="{ 'is-active': tab === 'editar' }" @click="tab = 'editar'">Editar
                        conceptos</button>
                    <button type="button" :class="{ 'is-active': tab === 'preview' }" :disabled="cargando"
                        @click="verPreview">Vista previa (PDF)</button>
                </nav>
                <button type="button" class="cz-close" title="Cerrar generador" @click="cerrar">&times;</button>
            </header>

            <!-- ===== Cuerpo ===== -->
            <main class="cz-body">
                <div v-if="cargando" class="cz-cargando">Cargando cotización…</div>
                <div v-else-if="errorCarga" class="cz-alert cz-alert-err">{{ errorCarga }}</div>

                <template v-else>
                    <div v-for="(a, i) in avisosServidor" :key="'av' + i" class="cz-alert cz-alert-warn">{{ a }}</div>

                    <!-- Respuesta del cliente desde el enlace del correo -->
                    <div v-if="respuesta.estado === 'aprobada' && respuesta.por" class="cz-alert cz-alert-ok">
                        <b>✓ Aprobada por el cliente:</b> {{ respuesta.por }} · {{ respuesta.fecha }}
                        <div v-if="respuesta.comentario" class="cz-alert-nota">“{{ respuesta.comentario }}”</div>
                    </div>
                    <div v-else-if="respuesta.estado === 'cambios'" class="cz-alert cz-alert-warn">
                        <b>✎ El cliente solicitó cambios:</b> {{ respuesta.por }} · {{ respuesta.fecha }}
                        <div v-if="respuesta.comentario" class="cz-alert-nota">“{{ respuesta.comentario }}”</div>
                        <div class="cz-alert-nota">Haz los ajustes y vuelve a enviarla: el cliente recibirá un enlace
                            nuevo.</div>
                    </div>
                    <div v-else-if="respuesta.enviada" class="cz-alert cz-alert-info">
                        Enviada al cliente el {{ respuesta.enviada }}. Esperando su aprobación desde el correo.
                    </div>

                    <div v-if="errores.length" class="cz-alert cz-alert-err">
                        <b>No se guardó la cotización. Corrige lo siguiente:</b>
                        <ul>
                            <li v-for="(e, i) in errores" :key="i">{{ e }}</li>
                        </ul>
                    </div>

                    <!-- EDITOR -->
                    <div v-show="tab === 'editar'">
                        <section class="cz-card">
                            <h2 class="cz-card-title">Información general de la cotización</h2>
                            <div class="cz-grid">
                                <label class="cz-field"><span>Prospecto / cliente</span>
                                    <input v-model="header.cliente" maxlength="255"></label>
                                <label class="cz-field"><span>Representante / contacto</span>
                                    <input v-model="header.contacto" maxlength="255"></label>
                                <div class="cz-field cz-field--2"><span>Fecha de cotización — válida hasta</span>
                                    <div class="cz-fecha-wrap">
                                        <VueCtkDateTimePicker v-model="rangoVigencia" format="YYYY-MM-DD"
                                            formatted="DD/MM/YYYY" color="#1d4ed8" button-color="#1d4ed8" :range="true"
                                            :no-label="true" :no-clear-button="true" label="Seleccionar rango de fechas"
                                            @validate="aplicarVigencia" class="cz-fecha" />
                                        <svg class="cz-fecha-ico" viewBox="0 0 24 24" width="15" height="15" fill="none"
                                            stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                                            stroke-linejoin="round" aria-hidden="true">
                                            <rect x="3" y="4" width="18" height="18" rx="2" />
                                            <path d="M16 2v4M8 2v4M3 10h18" />
                                        </svg>
                                    </div>
                                </div>

                                <div class="cz-field" :title="textoSucursal"><span>Puerto de entrada/salida</span>
                                    <multiselect :value="header.puerto || null" :options="opcionesSucursal"
                                        :group-values="sucursalesAgrupadas ? 'sucursales' : undefined"
                                        :group-label="sucursalesAgrupadas ? 'tipo' : undefined" :group-select="false"
                                        placeholder="Seleccione..." :show-labels="false" open-direction="below"
                                        class="cz-ms" @input="elegirSucursal">
                                        <template slot="noResult">Sin coincidencias</template>
                                    </multiselect>
                                </div>
                                <div class="cz-field"><span>Tipo de operacion:</span>
                                    <div class="cz-op">
                                        <button type="button"
                                            :class="{ 'is-imp': header.tipo_operacion === 'IMPORTACION' }"
                                            @click="cambiarTipo('IMPORTACION')"><i></i>Importación</button>
                                        <button type="button"
                                            :class="{ 'is-exp': header.tipo_operacion === 'EXPORTACION' }"
                                            @click="cambiarTipo('EXPORTACION')"><i></i>Exportación</button>
                                    </div>
                                </div>
                                <label class="cz-field"><span>Producto / mercancía</span>
                                    <input v-model="header.producto" maxlength="255"></label>
                                <label class="cz-field"
                                    :title="usuario.nombre ? (usuario.simulado ? 'Sesión simulada: ' : 'Sesión: ') + usuario.nombre : ''"><span>Vendedor
                                        / atentamente</span>
                                    <input v-model="header.vendedor" maxlength="255"></label>
                            </div>
                        </section>

                        <aprobaciones-internas v-if="id" :datos="aprobaciones" :cotizacion-id="id"
                            :aprobada-cliente="!!header.aprobada" :base-url="baseUrl"
                            @actualizado="(d) => { aprobaciones = d; }" @aviso="aviso" />

                        <div v-if="!secciones.length" class="cz-alert cz-alert-err">
                            <b>No hay bloques de conceptos configurados.</b>
                            La tabla <code>cotizacion_secciones</code> está vacía. En la terminal del proyecto ejecuta
                            <code>php artisan db:seed --class=CotizadorSeeder</code> y vuelve a abrir el generador.
                        </div>

                        <bloque-conceptos v-for="s in secciones" :key="s.id" :seccion="s" @total="guardarTotal"
                            :tipo-cambio="header.tipo_cambio" :iva="iva" :descripciones-doc="descripcionesDoc"
                            :sucursal="sucursalActiva" :sucursales="sucursales" />

                        <div v-if="!terminos.length && !id" class="cz-alert cz-alert-warn">
                            No se cargaron términos y condiciones por defecto (tabla <code>terminos_catalogo</code>
                            vacía).
                            Se llenan con el mismo comando: <code>php artisan db:seed --class=CotizadorSeeder</code>.
                        </div>

                        <terminos-condiciones :terminos="terminos" :cotizacion-id="id" :base-url="baseUrl"
                            @aviso="aviso" />

                        <section class="cz-grand">
                            <div><small>Cotización</small><b>Total estimada:</b></div>
                            <strong>${{ fmt(total) }} MXN</strong>
                        </section>
                    </div>

                    <!-- VISTA PREVIA -->
                    <div v-show="tab === 'preview'" class="cz-preview">
                        <div class="cz-preview-bar">
                            <div>
                                <h3>Vista previa de hojas PDF</h3>
                                <p>Consulte la diagramación y el formato físico de las hojas para impresión o guardado
                                    digital.</p>
                            </div>
                            <button type="button" class="b-orange b-upper" @click="abrirEnvio">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <path d="m22 2-7 20-4-9-9-4z" />
                                    <path d="M22 2 11 13" />
                                </svg>
                                Enviar cotización
                            </button>
                            <button type="button" class="b-green" :disabled="descargando" @click="descargarPdf">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <path d="M12 3v12" />
                                    <path d="m7 10 5 5 5-5" />
                                    <path d="M5 21h14" />
                                </svg>
                                {{ descargando ? 'Generando PDF…' : 'Descargar PDF' }}
                            </button>
                            <button type="button" class="b-ghost-dark" :disabled="generandoPdf"
                                title="Abrir el PDF en otra pestaña para imprimir" @click="imprimirPdf">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <path d="M6 9V2h12v7" />
                                    <rect x="6" y="14" width="12" height="8" />
                                    <path
                                        d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                                </svg>
                                {{ generandoPdf ? 'Abriendo…' : 'Imprimir' }}
                            </button>
                        </div>
                        <div v-if="cargandoPreview" class="cz-cargando">Generando vista previa…</div>
                        <iframe v-else class="cz-frame" :srcdoc="previewHtml"
                            title="Vista previa de la cotización"></iframe>
                    </div>
                </template>
            </main>

            <!-- ===== Pie ===== -->
            <footer class="cz-foot">
                <span>{{ razonSocial }}</span>
                <div>
                    <button type="button" class="b-ghost" @click="cerrar">Cerrar Generador</button>
                    <button type="button" class="b-orange" :disabled="guardando || cargando || !!errorCarga"
                        @click="guardar">
                        {{ guardando ? 'Guardando…' : 'Guardar Cotización' }}
                    </button>
                </div>
            </footer>
        </div>

        <!-- ===== Envío por correo ===== -->
        <div v-if="envio.abierto" class="cz-modal" @click.self="envio.abierto = false">
            <div class="cz-modal-box">
                <h3>Enviar cotización por correo</h3>
                <p class="cz-muted">
                    Se adjunta el PDF <b>{{ nombrePdf }}</b>.
                    <template v-if="!id || hayCambios">Antes de enviar se guardarán los cambios.</template>
                </p>
                <div class="cz-field"><span>Para</span>
                    <campo-correos v-model="envio.para" placeholder="cliente@empresa.com"
                        ayuda="Escribe un correo y presiona Enter. Puedes pegar varios separados por coma." />
                </div>
                <div class="cz-field"><span>CC</span>
                    <campo-correos v-model="envio.cc" :sugerencias="usuario.email ? [usuario.email] : []"
                        placeholder="Con copia a…" />
                </div>
                <label class="cz-field"><span>Asunto</span><input v-model="envio.asunto" type="text"
                        maxlength="200"></label>
                <label class="cz-field">
                    <span>Mensaje
                        <button v-if="envio.mensaje !== mensajePorDefecto" type="button" class="cz-link"
                            @click="envio.mensaje = mensajePorDefecto">Restablecer mensaje</button>
                    </span>
                    <textarea v-model="envio.mensaje" rows="11"></textarea>
                    <small class="cz-hint">Tu firma se agrega al final del correo.</small>
                </label>
                <p v-if="envio.resultado" class="cz-send-msg" :class="{ 'is-err': envio.error }">{{ envio.resultado }}
                </p>
                <p v-if="envio.enlace && !envio.error" class="cz-muted cz-enlace">
                    Enlace de aprobación: <a :href="envio.enlace" target="_blank" rel="noopener">{{ envio.enlace }}</a>
                </p>
                <div class="cz-modal-btns">
                    <button type="button" class="b-ghost b-ghost--light" @click="envio.abierto = false">{{ envio.enviado
                        ?
                        'Cerrar' : 'Cancelar' }}</button>
                    <button type="button" class="b-orange" :disabled="envio.enviando || !envio.para.length"
                        @click="enviarCorreo">
                        {{ envio.enviando ? 'Enviando…' : (envio.enviado ? 'Enviar de nuevo' : 'Enviar') }}
                    </button>
                </div>
            </div>
        </div>

        <transition name="cz-fade">
            <div v-if="toast" class="cz-toast">{{ toast }}</div>
        </transition>
    </div>
</template>

<script>
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';
import 'vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';
import axios from 'axios';
import BloqueConceptos from './BloqueConceptos.vue';
import CampoCorreos from './CampoCorreos.vue';
import AprobacionesInternas from './AprobacionesInternas.vue';
import TerminosCondiciones from './TerminosCondiciones.vue';

let llave = 0;

function encabezadoVacio() {
    return {
        cliente: '', contacto: '', fecha: '', valida_hasta: '', puerto: '',
        tipo_operacion: 'EXPORTACION', producto: '', vendedor: '', tipo_cambio: 0, aprobada: false,
    };
}

/**
 * Modal del generador de cotizaciones.
 *
 *   <cotizacion-generador ref="generador" base-url="..." @guardada="..." />
 *   this.$refs.generador.abrirEmpresa(empresaId)       // última cotización o nueva con sus datos
 *   this.$refs.generador.abrirEmpresa(empresaId, true) // siempre nueva
 *   this.$refs.generador.abrirCotizacion(id)
 *   this.$refs.generador.nueva()
 */
export default {
    name: 'CotizacionGenerador',

    components: { BloqueConceptos, TerminosCondiciones, CampoCorreos, AprobacionesInternas, Multiselect, VueCtkDateTimePicker },

    props: {
        baseUrl: { type: String, default: '' },
        razonSocial: { type: String, default: 'LOGÍSTICA INTERNACIONAL INTACTICS S.C.' },
    },

    data() {
        return {
            visible: false,
            cargando: false,
            errorCarga: '',
            guardando: false,
            tab: 'editar',

            id: null,
            folio: null,
            empresaId: null,
            header: encabezadoVacio(),
            secciones: [],
            terminos: [],
            iva: 0.16,
            descripcionesDoc: [],
            sucursales: [],
            avisosServidor: [],
            terminosDefault: {},
            mensajeCorreo: '',
            usuario: { nombre: null, email: null, simulado: false },
            // Dirección / Desarrollo de negocios / Contabilidad
            aprobaciones: { estado: 'sin_iniciar', areas: [] },
            // Aprobación del cliente desde el correo: pendiente | aprobada | cambios
            respuesta: { estado: 'pendiente', por: null, fecha: null, comentario: null, enviada: null },
            // { start: fecha, end: valida_hasta } del VueCtkDateTimePicker en modo rango
            rangoVigencia: null,
            // Total con IVA de cada bloque, por id de sección (lo emite BloqueConceptos)
            totalesBloque: {},

            errores: [],
            instantanea: null,
            previewHtml: '',
            cargandoPreview: false,
            generandoPdf: false,
            descargando: false,
            envio: { abierto: false, enlace: '', para: [], cc: [], asunto: '', ultimoAsunto: '', mensaje: '', ultimoDefecto: '', enviando: false, enviado: false, resultado: '', error: false },
            toast: '',
        };
    },

    computed: {
        /** Total estimado: suma de los totales que reporta cada BloqueConceptos (con IVA) */
        total() {
            const totales = this.totalesBloque;
            const suma = this.secciones.reduce(function (acc, s) { return acc + (totales[s.id] || 0); }, 0);
            return Math.round((suma + Number.EPSILON) * 100) / 100;
        },
        /** Sucursal del puerto escrito ("Nogales", "nuevo laredo"…) para filtrar los conceptos. */
        sucursalActiva() {
            const quitar = function (t) {
                return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().trim();
            };
            const puerto = quitar(this.header.puerto);
            if (!puerto) return null;
            const s = this.sucursales.find(function (x) { return quitar(x.clave) === puerto || quitar(x.nombre) === puerto; });
            return s ? s.clave : null;
        },
        /** Sucursales agrupadas por tipo (Frontera / Marítimo) si la tabla lo trae. */
        gruposSucursal() {
            const grupos = [];
            this.sucursales.forEach(function (s) {
                const tipo = s.tipo || '';
                let g = grupos.find(function (x) { return x.tipo === tipo; });
                if (!g) { g = { tipo: tipo, sucursales: [] }; grupos.push(g); }
                g.sucursales.push(s);
            });
            return grupos;
        },
        /** ¿La tabla de sucursales trae tipo (Frontera / Marítimo)? Entonces se agrupan. */
        sucursalesAgrupadas() {
            return this.gruposSucursal.some(function (g) { return !!g.tipo; });
        },

        /** Opciones de vue-multiselect: nombres de sucursal, agrupados por tipo si aplica. */
        opcionesSucursal() {
            const puerto = this.header.puerto;
            const conocida = !puerto || !!this.sucursalActiva;

            if (this.sucursalesAgrupadas) {
                const grupos = this.gruposSucursal.map(function (g) {
                    return { tipo: g.tipo || 'Otras', sucursales: g.sucursales.map(function (s) { return s.nombre; }) };
                });
                // Valor guardado antes que no es una sucursal del catálogo
                if (!conocida) grupos.push({ tipo: 'Guardado', sucursales: [puerto] });
                return grupos;
            }

            const lista = this.sucursales.map(function (s) { return s.nombre; });
            if (!conocida) lista.push(puerto);
            return lista;
        },

        textoSucursal() {
            if (this.sucursalActiva) {
                const s = this.sucursales.find((x) => x.clave === this.sucursalActiva);
                if (s && s.con_codigos === false) return s.nombre + ': sin códigos propios, solo generales';
                return 'Códigos de ' + (s ? s.nombre : this.sucursalActiva);
            }
            if (!this.sucursales.length) return 'No se encontraron sucursales en la base de datos';
            return 'Elige la sucursal para filtrar los códigos';
        },
        /** Mensaje del correo con la operación y la sucursal de esta cotización. */
        mensajePorDefecto() {
            const operacion = this.header.tipo_operacion === 'IMPORTACION' ? 'importación' : 'exportación';
            return this.mensajeCorreo
                .split('{operacion}').join(operacion)
                .split('{puerto}').join(this.header.puerto || 'Nogales');
        },
        asuntoPorDefecto() {
            const cliente = String(this.header.cliente || '').trim().toUpperCase();
            return 'COTIZACION DE SERVICIOS INTACTICS' + (cliente ? ' / ' + cliente : '');
        },
        nombrePdf() {
            const limpiar = function (t) {
                return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            };
            // El folio ya trae el cliente: COTN26102026001-asimex-global.pdf
            if (this.folio) return this.folio + '.pdf';
            return 'cotizacion-borrador-' + (limpiar(this.header.cliente) || 'cliente') + '.pdf';
        },
        hayCambios() {
            return this.instantanea !== null && JSON.stringify(this.cuerpo()) !== this.instantanea;
        },
    },

    watch: {
        rangoVigencia(valor) {
            if (!valor) {
                this.header.fecha = '';
                this.header.valida_hasta = '';
                return;
            }
            if (valor.start) this.header.fecha = valor.start;
            this.header.valida_hasta = valor.end || '';
        },
    },

    beforeDestroy() {
        document.removeEventListener('keydown', this.alTeclear);
        document.body.classList.remove('cz-sin-scroll');
    },

    methods: {
        fmt(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        num(v) { const n = parseFloat(v); return isNaN(n) ? 0 : n; },
        guardarTotal(e) { this.$set(this.totalesBloque, e.id, e.total); },

        /** Rango validado: inicio = fecha de cotización, fin = válida hasta */
        aplicarVigencia() {
            const r = this.rangoVigencia || {};
            this.header.fecha = r.start || '';
            this.header.valida_hasta = r.end || '';
        },

        /**
         * Cambio de sucursal: cada sucursal tiene códigos diferentes (N, T, NL, M, Z…),
         * así que se quitan los conceptos capturados en los bloques que dependen de la sucursal.
         * Impuestos, LLC y Certificado (sin_sucursal) se conservan.
         */
        elegirSucursal(valor) {
            const nuevo = valor || '';
            if (nuevo === this.header.puerto) {
                return;
            }

            const afectados = this.secciones.filter(function (s) { return !s.sin_sucursal && s.rows.length; });
            const total = afectados.reduce(function (n, s) { return n + s.rows.length; }, 0);

            if (total) {
                const bloques = afectados.map(function (s) { return s.nombre; }).join(', ');
                const pregunta = 'Cada sucursal tiene códigos diferentes. Al cambiar a ' + (nuevo || 'ninguna sucursal')
                    + ' se quitarán ' + total + (total === 1 ? ' concepto' : ' conceptos') + ' de: ' + bloques + '.\n\n¿Continuar?';

                if (!window.confirm(pregunta)) {
                    this.$forceUpdate();   // el select vuelve a mostrar la sucursal anterior
                    return;
                }

                afectados.forEach(function (s) { s.rows.splice(0, s.rows.length); });
                this.aviso('Se quitaron ' + total + (total === 1 ? ' concepto' : ' conceptos') + ' de la sucursal anterior.');
            }

            this.header.puerto = nuevo;
        },

        /* ---------- abrir / cerrar ---------- */
        abrirEmpresa(empresaId, forzarNueva) {
            const url = this.baseUrl + '/cotizaciones/empresa/' + empresaId + (forzarNueva ? '?nueva=1' : '');
            this.abrir(url);
        },
        abrirCotizacion(id) {
            this.abrir(this.baseUrl + '/cotizaciones/' + id);
        },
        nueva(empresaId) {
            this.abrir(this.baseUrl + '/cotizaciones/nueva' + (empresaId ? '?empresa_id=' + empresaId : ''));
        },

        abrir(url) {
            this.visible = true;
            this.cargando = true;
            this.errorCarga = '';
            this.errores = [];
            this.tab = 'editar';
            this.instantanea = null;
            document.body.classList.add('cz-sin-scroll');
            document.addEventListener('keydown', this.alTeclear);

            axios.get(url)
                .then((r) => { this.cargarDatos(this.exigirJson(r, 'secciones')); })
                .catch((e) => { this.errorCarga = 'No se pudo abrir la cotización. ' + this.mensajesError(e)[0]; })
                .then(() => { this.cargando = false; });
        },

        cerrar() {
            if (this.hayCambios && !window.confirm('Hay cambios sin guardar. ¿Cerrar el generador de todos modos?')) {
                return;
            }
            this.visible = false;
            this.envio.abierto = false;
            document.body.classList.remove('cz-sin-scroll');
            document.removeEventListener('keydown', this.alTeclear);
            this.$emit('cerrado');
        },

        alTeclear(e) {
            if (e.key !== 'Escape') return;
            if (this.envio.abierto) this.envio.abierto = false;
            else this.cerrar();
        },

        cargarDatos(p) {
            p.secciones.forEach(function (s) {
                s.rows.forEach(function (fila) { fila._k = 'c' + (++llave); });
            });
            p.terminos.forEach(function (t) {
                t._k = 'c' + (++llave);
                t._estado = '';
                t._ocupado = false;
            });

            this.id = p.id;
            this.folio = p.folio;
            this.empresaId = p.empresa_id;
            this.header = Object.assign(encabezadoVacio(), p.header);
            this.rangoVigencia = this.header.fecha
                ? { start: this.header.fecha, end: this.header.valida_hasta || null }
                : null;
            this.secciones = p.secciones;
            this.terminos = p.terminos;
            this.iva = p.iva;
            this.descripcionesDoc = p.descripcionesDoc;
            this.sucursales = p.sucursales || [];
            this.avisosServidor = p.avisos || [];
            this.terminosDefault = p.terminosDefault || {};
            this.mensajeCorreo = p.mensajeCorreo || '';
            this.usuario = Object.assign({ nombre: null, email: null, simulado: false }, p.usuario);
            this.aprobaciones = p.aprobaciones || { estado: 'sin_iniciar', areas: [] };
            this.respuesta = Object.assign({ estado: 'pendiente', por: null, fecha: null, comentario: null, enviada: null }, p.respuesta);
            // Cotización nueva: el vendedor es quien la está haciendo
            if (!p.id && !this.header.vendedor && this.usuario.nombre) {
                this.header.vendedor = this.usuario.nombre;
            }
            this.previewHtml = '';
            this.instantanea = JSON.stringify(this.cuerpo());
        },

        /**
         * Importación / Exportación: cambia también los términos y condiciones por los del tipo.
         * Si los términos ya se editaron, pregunta antes de reemplazarlos.
         */
        cambiarTipo(tipo) {
            if (this.header.tipo_operacion === tipo) {
                return;
            }
            const anterior = this.header.tipo_operacion;
            this.header.tipo_operacion = tipo;

            const nuevos = this.terminosDefault[tipo];
            if (!nuevos || !nuevos.length) {
                return;
            }

            const limpiar = function (lista) {
                return lista.map(function (t) { return String(t || '').trim(); }).filter(Boolean);
            };
            const actuales = limpiar(this.terminos.map(function (t) { return t.texto; }));
            const previos = limpiar(this.terminosDefault[anterior] || []);
            const sinCambios = !actuales.length || JSON.stringify(actuales) === JSON.stringify(previos);
            const nombre = tipo === 'IMPORTACION' ? 'importación' : 'exportación';

            if (!sinCambios && !window.confirm('Los términos y condiciones tienen cambios. ¿Reemplazarlos por los de ' + nombre + '?')) {
                return;
            }

            this.terminos = nuevos.map(function (texto) {
                return { _k: 'c' + (++llave), id: null, texto: texto, _estado: '', _ocupado: false };
            });
            this.aviso('Se cargaron los términos y condiciones de ' + nombre + '.');
        },

        /** Lo que se manda al servidor (mismo formato que CotizacionRequest). */
        cuerpo() {
            const num = this.num;
            const conceptos = {};
            this.secciones.forEach(function (s) {
                if (!s.rows.length) return;
                conceptos[s.id] = s.rows.map(function (f) {
                    return {
                        codigo: f.codigo,
                        clave_sat: f.clave_sat || null,
                        descripcion: f.descripcion,
                        cantidad: f.cantidad,
                        precio_usd: num(f.precio_usd),
                        precio_mxn: num(f.precio_usd) > 0 ? 0 : num(f.precio_mxn),
                        descripcion_doc: f.descripcion_doc,
                    };
                });
            });

            return Object.assign({}, this.header, {
                empresa_id: this.empresaId,
                conceptos: conceptos,
                terminos: this.terminos.map(function (t) { return { texto: t.texto }; }),
            });
        },

        /* ---------- guardar ---------- */
        /** Guarda la cotización. Devuelve una promesa que resuelve true si se guardó. */
        guardar() {
            this.guardando = true;
            this.errores = [];

            const datos = this.cuerpo();
            const peticion = this.id
                ? axios.put(this.baseUrl + '/cotizaciones/' + this.id, datos)
                : axios.post(this.baseUrl + '/cotizaciones', datos);

            return peticion
                .then((r) => {
                    this.exigirJson(r, 'cotizacion');
                    this.cargarDatos(r.data.cotizacion);
                    this.aviso(r.data.mensaje);
                    this.$emit('guardada', r.data.cotizacion);
                    return true;
                })
                .catch((e) => {
                    this.errores = this.mensajesError(e);
                    this.tab = 'editar';
                    this.$el.querySelector('.cz-body').scrollTop = 0;
                    return false;
                })
                .then((ok) => { this.guardando = false; return ok; });
        },

        /* ---------- vista previa / PDF ---------- */
        verPreview() {
            this.tab = 'preview';
            this.cargandoPreview = true;
            axios.post(this.baseUrl + '/cotizaciones/preview', this.cuerpo(), { responseType: 'text' })
                .then((r) => { this.previewHtml = r.data; })
                .catch((e) => { this.previewHtml = '<p style="font-family:Arial;padding:24px;color:#991b1b">' + this.mensajesError(e)[0] + '</p>'; })
                .then(() => { this.cargandoPreview = false; });
        },

        /** Abre el PDF en otra pestaña para imprimir. */
        imprimirPdf() {
            // La ventana se abre antes de la petición para que el navegador no la bloquee
            const ventana = window.open('', '_blank');
            this.generandoPdf = true;
            axios.post(this.baseUrl + '/cotizaciones/pdf-borrador', this.cuerpo(), { responseType: 'blob' })
                .catch(this.leerErrorBlob)
                .then((r) => {
                    const url = URL.createObjectURL(new Blob([r.data], { type: 'application/pdf' }));
                    if (ventana) ventana.location = url; else window.location = url;
                    setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
                })
                .catch((e) => {
                    if (ventana) ventana.close();
                    this.aviso(this.mensajesError(e)[0]);
                })
                .then(() => { this.generandoPdf = false; });
        },

        /** Descarga el PDF de lo que está en pantalla (aunque no se haya guardado). */
        descargarPdf() {
            this.descargando = true;
            axios.post(this.baseUrl + '/cotizaciones/pdf-borrador?descargar=1', this.cuerpo(), { responseType: 'blob' })
                .catch(this.leerErrorBlob)
                .then((r) => {
                    const enlace = document.createElement('a');
                    enlace.href = URL.createObjectURL(new Blob([r.data], { type: 'application/pdf' }));
                    enlace.download = this.nombrePdf;
                    document.body.appendChild(enlace);
                    enlace.click();
                    enlace.remove();
                    setTimeout(function () { URL.revokeObjectURL(enlace.href); }, 60000);
                    this.aviso('PDF descargado: ' + this.nombrePdf);
                })
                .catch((e) => { this.aviso(this.mensajesError(e)[0]); })
                .then(() => { this.descargando = false; });
        },

        /* ---------- correo ---------- */
        abrirEnvio() {
            // "COTIZACION DE SERVICIOS INTACTICS / MENITA COMERCIAL"
            const asunto = this.asuntoPorDefecto;
            const asuntoEditado = this.envio.asunto && this.envio.asunto !== this.envio.ultimoAsunto;
            // Mensaje por defecto ya escrito; si se había editado en esta cotización, se respeta
            const editado = this.envio.mensaje && this.envio.mensaje !== this.envio.ultimoDefecto;
            Object.assign(this.envio, {
                abierto: true,
                enviado: false,
                resultado: '',
                error: false,
                asunto: asuntoEditado ? this.envio.asunto : asunto,
                ultimoAsunto: asunto,
                mensaje: editado ? this.envio.mensaje : this.mensajePorDefecto,
                ultimoDefecto: this.mensajePorDefecto,
            });
        },

        /** Guarda primero si hay cambios (o si es nueva) y luego envía el PDF guardado. */
        enviarCorreo() {
            this.envio.enviando = true;
            this.envio.resultado = '';

            const listo = (!this.id || this.hayCambios) ? this.guardar() : Promise.resolve(true);

            listo
                .then((guardada) => {
                    if (!guardada) {
                        const error = new Error('no-guardada');
                        error.response = { data: { message: 'No se pudo guardar la cotización; revisa los errores marcados en el editor.' } };
                        throw error;
                    }
                    return axios.post(this.baseUrl + '/cotizaciones/' + this.id + '/enviar', {
                        para: this.envio.para.join(', '),
                        cc: this.envio.cc.join(', '),
                        asunto: this.envio.asunto,
                        mensaje: this.envio.mensaje,
                    });
                })
                .then((r) => {
                    this.envio.error = false;
                    this.envio.enviado = true;
                    this.envio.resultado = r.data.mensaje + (r.data.aprobacion ? ' El cliente puede aprobarla desde el botón del correo.' : '');
                    this.envio.enlace = r.data.aprobacion || '';
                    this.respuesta.enviada = r.data.enviada_at;
                    this.$emit('guardada', { id: this.id, enviada_at: r.data.enviada_at });
                })
                .catch((e) => { this.envio.error = true; this.envio.resultado = this.mensajesError(e)[0]; })
                .then(() => { this.envio.enviando = false; });
        },

        aviso(mensaje) {
            this.toast = mensaje;
            clearTimeout(this._toast);
            this._toast = setTimeout(() => { this.toast = ''; }, 3500);
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

        /** En peticiones de PDF (responseType 'blob') el error llega como Blob: lo convierte a JSON. */
        leerErrorBlob(error) {
            const r = error && error.response;
            if (!r || typeof Blob === 'undefined' || !(r.data instanceof Blob)) {
                return Promise.reject(error);
            }
            return r.data.text().then(function (texto) {
                try { r.data = JSON.parse(texto); } catch (e) { r.data = { message: 'Error ' + r.status + ' al generar el PDF.' }; }
                return Promise.reject(error);
            });
        },
    },
};
</script>

<style>
/* Global: fuente del modal y bloqueo de scroll de la página mientras está abierto */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap');

body.cz-sin-scroll {
    overflow: hidden;
}
</style>

<style scoped>
/* ===================================================================== *
 |  CotizacionGenerador: modal, encabezado, información general,
 |  vista previa, pie y ventana de envío por correo.
 |  Paleta igual a ModalNuevoIngreso: azul pizarra #2A3A4D, verde agua #00C09F,
 |  grises de Tailwind (gray-100 … gray-700) y verde-600 para guardar.
 |  BloqueConceptos.vue y TerminosCondiciones.vue heredan estas variables.
 * ===================================================================== */

/* ---------- Paleta ---------- */
.cz-overlay {
    --navy: #2A3A4D;
    /* encabezados (bg de NUEVO INGRESO CONCILIADO) */
    --teal: #00C09F;
    /* acentos, opción resaltada, checks */
    --teal-d: #00a88b;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-700: #374151;
    --save: #16a34a;
    /* verde-600: Guardar */
    --save-d: #15803d;
    --red: #e74c3c;

    /* Colores de los bloques de conceptos y términos (diseño original, sin cambios) */
    --blue: #1d5fa5;
    /* bloques azules (con IVA) y total del bloque */
    --amber: #d4832e;
    /* bloques naranjas (sin IVA) */
    --orange: #f7931e;
    /* "-- Cargar --" */
    --orange-d: #e57f05;
    --green: #2ecc71;
    --green-d: #22a85a;
    --ink: #1e293b;
    --muted: #64748b;
    --soft: #94a3b8;
    --line: #e2e8f0;
    --field: #f5f7fa;

    position: fixed;
    inset: 0;
    z-index: 1060;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(0, 0, 0, .5);
    color: var(--ink);
    font-family: 'Inter', 'Plus Jakarta Sans', 'Segoe UI', Roboto, Arial, sans-serif;
    font-size: 14px;
    -webkit-font-smoothing: antialiased;
}

.cz-overlay *,
.cz-overlay *::before,
.cz-overlay *::after {
    box-sizing: border-box;
}

.cz-overlay button,
.cz-overlay input,
.cz-overlay select,
.cz-overlay textarea {
    font: inherit;
    color: inherit;
}

.cz-overlay :focus-visible {
    outline: 2px solid var(--teal);
    outline-offset: 2px;
}

.cz {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 1156px;
    height: 100%;
    max-height: 90vh;
    overflow: hidden;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, .25);
}

/* ---------- Encabezado ---------- */
.cz-top {
    display: flex;
    align-items: center;
    gap: 16px;
    min-height: 60px;
    padding: 12px 24px;
    background: var(--navy);
}

.cz-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.cz-icon {
    display: grid;
    place-items: center;
    flex: none;
    width: 28px;
    height: 28px;
    color: #fff;
}

.cz-icon svg {
    width: 22px;
    height: 22px;
}

.cz-brand h1 {
    margin: 0;
    font-size: 16px !important;
    line-height: 1.2 !important;
    font-weight: 800;
    letter-spacing: .04em;
    color: #fff;
    text-transform: uppercase;
}

.cz-brand p {
    margin: 3px 0 0;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .03em;
    color: #bfdbfe;
    text-transform: uppercase;
}

.cz-brand p b {
    color: #fff;
}

.cz-tabs {
    display: flex;
    gap: 3px;
    padding: 3px;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 8px;
    background: rgba(255, 255, 255, .06);
}

.cz-tabs button {
    height: 30px;
    padding: 0 14px !important;
    border: 0;
    border-radius: 6px;
    background: none;
    font-size: 10px !important;
    font-weight: 800;
    line-height: 1 !important;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #cbd5e1;
    cursor: pointer;
}

.cz-tabs button:hover {
    color: #fff;
}

.cz-tabs button.is-active {
    background: var(--teal);
    color: #fff;
}

.cz-close {
    padding: 0 2px;
    border: 0;
    background: none;
    font-size: 28px !important;
    font-weight: 300;
    line-height: 1 !important;
    color: #bfdbfe;
    cursor: pointer;
}

.cz-close:hover {
    color: #fff;
}

/* ---------- Cuerpo ---------- */
.cz-body {
    flex: 1;
    overflow: auto;
    padding: 28px 32px 32px;
    background: #fff;
}

.cz-cargando {
    padding: 60px;
    text-align: center;
    font-weight: 600;
    color: var(--gray-500);
}

.cz-alert {
    margin-bottom: 16px;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
}

.cz-alert ul {
    margin: 6px 0 0;
    padding-left: 18px;
}

.cz-alert-err {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.cz-alert-warn {
    background: #fefce8;
    color: #854d0e;
    border: 1px solid #fde68a;
}

.cz-alert-ok {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.cz-alert-info {
    background: #f8fafc;
    color: #2A3A4D;
    border: 1px solid #e5e7eb;
}

.cz-alert-nota {
    margin-top: 4px;
    font-size: 12.5px;
    opacity: .9;
}

.cz-alert code {
    padding: 1px 5px;
    border-radius: 3px;
    background: rgba(0, 0, 0, .06);
    font-size: 12px;
}

/* ---------- Información general ---------- */
.cz-card {
    margin-bottom: 28px;
    padding: 0;
    border: 0;
    background: transparent;
}

.cz-card-title {
    margin: 0 0 18px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--gray-200);
    font-size: 13px !important;
    font-weight: 800;
    letter-spacing: .04em;
    color: var(--navy);
    text-transform: uppercase;
}

.cz-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px 20px;
}

.cz-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
    margin: 0;
}

.cz-field--2 {
    grid-column: span 2;
}

.cz-field>span {
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .03em;
    color: var(--gray-500);
    text-transform: uppercase;
}

.cz-green-label {
    color: var(--gray-500) !important;
}

.cz-field input:not([type=checkbox]),
.cz-field textarea {
    width: 100%;
    height: 42px;
    padding: 0 14px;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    background: #fff;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
}

.cz-field input::placeholder,
.cz-field textarea::placeholder {
    color: var(--gray-400);
    font-weight: 500;
}

.cz-field textarea {
    height: auto;
    padding: 10px 14px;
    line-height: 1.5;
    resize: vertical;
}

.cz-field input:focus,
.cz-field textarea:focus {
    outline: none;
    border-color: var(--gray-400);
}

/* Puerto (vue-multiselect) igual que en ModalNuevoIngreso */
.cz-ms {
    min-height: 42px;
    font-size: 14px;
}

.cz-ms ::v-deep .multiselect__tags {
    min-height: 42px;
    padding: 11px 40px 0 14px;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    background: #fff;
}

.cz-ms ::v-deep .multiselect__select {
    width: 38px;
    height: 42px;
}

.cz-ms ::v-deep .multiselect__single,
.cz-ms ::v-deep .multiselect__input,
.cz-ms ::v-deep .multiselect__placeholder {
    margin-bottom: 11px;
    padding-left: 0;
    background: transparent;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cz-ms ::v-deep .multiselect__placeholder {
    font-weight: 500;
    color: var(--gray-400);
}

.cz-ms ::v-deep .multiselect__content-wrapper {
    z-index: 1075;
    min-width: 100%;
    border-color: var(--gray-300);
    box-shadow: 0 10px 24px rgba(0, 0, 0, .12);
}

.cz-ms ::v-deep .multiselect__option {
    min-height: 0;
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.4;
    white-space: normal;
}

.cz-ms ::v-deep .multiselect__option--highlight {
    background: var(--teal);
}

.cz-ms ::v-deep .multiselect__option--group {
    background: var(--gray-100);
    color: var(--navy);
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}

/* Rango de fechas (VueCtkDateTimePicker) */
.cz-fecha-wrap {
    position: relative;
}

.cz-fecha-ico {
    position: absolute;
    top: 50%;
    right: 14px;
    transform: translateY(-50%);
    color: var(--gray-400);
    pointer-events: none;
}

.cz-fecha ::v-deep .field-input {
    height: 42px !important;
    min-height: 42px !important;
    padding: 0 40px 0 14px !important;
    border: 1px solid var(--gray-300) !important;
    border-radius: 8px !important;
    background: #fff !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    color: var(--ink) !important;
}

.cz-fecha ::v-deep .datepicker {
    z-index: 1075 !important;
}

/* Tipo de operación */
.cz-op {
    display: flex;
    gap: 10px;
}

.cz-op button {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 42px;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    background: #fff;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: var(--gray-500);
    cursor: pointer;
}

.cz-op button:hover {
    background: var(--gray-100);
}

.cz-op i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--gray-300);
}

.cz-op button.is-exp,
.cz-op button.is-imp {
    border-color: var(--teal);
    background: #e6f9f5;
    color: #00806a;
}

.cz-op button.is-exp i,
.cz-op button.is-imp i {
    background: var(--teal);
}

/* Aprobación por cliente (como "Tipo de comprobante") */
.cz-approve {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 42px;
    margin: 0;
    padding: 0 14px;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: var(--gray-100);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
    color: var(--gray-700);
    cursor: pointer;
}

.cz-approve input {
    width: 18px;
    height: 18px;
    margin: 0;
    accent-color: var(--teal);
    cursor: pointer;
}

.cz-approve.is-on {
    border-color: var(--gray-200);
    background: var(--gray-100);
    color: var(--gray-700);
}

/* ---------- Total estimado (como "Suma total") ---------- */
.cz-grand {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 18px 22px;
    border: 1px solid var(--navy);
    border-radius: 12px;
    background: var(--gray-100);
}

.cz-grand small {
    display: block;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--gray-500);
}

.cz-grand b {
    font-size: 15px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: var(--navy);
}

.cz-grand strong {
    padding: 10px 22px;
    border: 1px solid #fef08a;
    border-radius: 8px;
    background: #fef9c3;
    font-size: 26px;
    font-weight: 900;
    letter-spacing: .01em;
    color: #ca8a04;
}

/* ---------- Vista previa ---------- */
.cz-preview-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0 0 24px;
    padding: 14px 18px;
    border-radius: 10px;
    background: var(--navy);
    color: #fff;
}

.cz-preview-bar>div {
    flex: 1;
}

.cz-preview-bar h3 {
    margin: 0;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: #fff;
}

.cz-preview-bar p {
    max-width: 340px;
    margin: 3px 0 0;
    font-size: 11px;
    color: #bfdbfe;
}

.cz-preview-bar button {
    min-width: 150px;
}

.cz-frame {
    display: block;
    width: 100%;
    height: 1100px;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: var(--gray-100);
}

/* ---------- Botones ---------- */
.b-orange,
.b-green,
.b-ghost {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 44px;
    padding: 0 24px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: background-color .15s;
}

/* Acción principal: verde-600 (Guardar / Enviar) */
.b-orange {
    background: var(--save);
    color: #fff;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
}

.b-orange:hover {
    background: var(--save-d);
}

/* "Enviar cotización" en la barra de vista previa */
.b-upper {
    background: var(--teal);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
}

.b-upper:hover {
    background: var(--teal-d);
}

.b-green {
    background: var(--save);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
}

.b-green:hover {
    background: var(--save-d);
}

/* Secundario: gris como "Cancelar" */
.b-ghost {
    background: var(--gray-200);
    color: var(--gray-700);
    box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
}

.b-ghost:hover {
    background: var(--gray-300);
}

.b-ghost--light {
    background: var(--gray-200);
    color: var(--gray-700);
}

.b-ghost-dark {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 44px;
    padding: 0 16px;
    border: 1px solid rgba(255, 255, 255, .35);
    border-radius: 8px;
    background: transparent;
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    cursor: pointer;
}

.b-ghost-dark:hover {
    border-color: #fff;
}

.cz-overlay button:disabled {
    opacity: .55;
    cursor: not-allowed;
}

/* ---------- Pie (como el de ModalNuevoIngreso) ---------- */
.cz-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 28px;
    border-top: 1px solid var(--gray-200);
    background: var(--gray-100);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: var(--gray-500);
}

.cz-foot>div {
    display: flex;
    gap: 14px;
}

/* Botones del pie: iguales a "Cancelar" y "Guardar Nuevo Ingreso" */
.cz-foot .b-ghost,
.cz-foot .b-orange {
    height: 48px;
    padding: 0 24px;
    border: 0;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 700;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
}

.cz-foot .b-ghost {
    background: #edf2f7;
    color: #4a5568;
}

.cz-foot .b-ghost:hover {
    background: #e2e8f0;
}

.cz-foot .b-orange {
    background: #38a169;
    color: #fff;
}

.cz-foot .b-orange:hover {
    background: #2f855a;
}

/* ---------- Ventana "Enviar cotización" y aviso ---------- */
.cz-modal {
    position: fixed;
    inset: 0;
    z-index: 1070;
    display: grid;
    place-items: center;
    padding: 20px;
    background: rgba(0, 0, 0, .5);
}

.cz-modal-box {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
    max-width: 520px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    padding: 0 24px 24px;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, .25);
}

.cz-modal-box h3 {
    margin: 0 -24px 4px;
    padding: 16px 24px;
    background: var(--navy);
    font-size: 15px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #fff;
}

.cz-muted {
    margin: 0;
    font-size: 13px;
    color: var(--gray-500);
}

.cz-hint {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--gray-400);
}

.cz-link {
    margin-left: 8px;
    padding: 0;
    border: 0;
    background: none;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    text-decoration: underline;
    color: var(--teal-d);
    cursor: pointer;
}

.cz-modal-btns {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin: 6px -24px -24px;
    padding: 16px 24px;
    border-top: 1px solid var(--gray-200);
    background: var(--gray-100);
}

.cz-send-msg {
    margin: 0;
    padding: 10px 12px;
    border-radius: 8px;
    background: #ecfdf5;
    font-size: 13px;
    color: #065f46;
}

.cz-enlace {
    word-break: break-all;
}

.cz-enlace a {
    color: var(--teal-d);
    font-weight: 700;
}

.cz-send-msg.is-err {
    background: #fef2f2;
    color: #991b1b;
}

.cz-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1080;
    padding: 12px 18px;
    border-radius: 8px;
    background: var(--navy);
    box-shadow: 0 10px 24px rgba(0, 0, 0, .2);
    font-size: 13px;
    font-weight: 600;
    color: #fff;
}

.cz-fade-enter-active,
.cz-fade-leave-active {
    transition: opacity .2s;
}

.cz-fade-enter,
.cz-fade-leave-to {
    opacity: 0;
}

/* ---------- Pantallas angostas ---------- */
@media (max-width: 900px) {
    .cz-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .cz-body {
        padding: 18px;
    }

    .cz-preview-bar {
        flex-wrap: wrap;
    }

    .cz-top {
        flex-wrap: wrap;
    }

    .cz-overlay {
        padding: 0;
    }

    .cz {
        max-height: none;
        border-radius: 0;
    }
}

@media (max-width: 560px) {
    .cz-grid {
        grid-template-columns: 1fr;
    }

    .cz-field--2 {
        grid-column: span 1;
    }

    .cz-foot {
        flex-direction: column;
        gap: 10px;
    }
}

@media (prefers-reduced-motion: reduce) {

    .cz-fade-enter-active,
    .cz-fade-leave-active {
        transition: none;
    }
}
</style>