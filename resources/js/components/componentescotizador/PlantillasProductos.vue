<template>
    <div v-if="visible" class="pl-overlay" role="dialog" aria-modal="true" aria-label="Plantillas por producto"
        @click.self="cerrar">
        <div class="pl">
            <!-- ===== Encabezado ===== -->
            <header class="pl-top">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="2" />
                    <path d="M3 9h18M9 21V9" />
                </svg>
                <div class="pl-titulo">
                    <h1>{{ tituloVista }}</h1>
                    <p>{{ subtituloVista }}</p>
                </div>
                <button type="button" class="pl-close" title="Cerrar" @click="cerrar">&times;</button>
            </header>

            <main class="pl-body">
                <div v-if="cargando" class="pl-cargando">Cargando…</div>
                <div v-else-if="error" class="pl-alert pl-alert-err">{{ error }}</div>

                <!-- ===== LISTA ===== -->
                <template v-else-if="vista === 'lista'">
                    <div class="pl-toolbar">
                        <input v-model.trim="filtro" class="pl-input" placeholder="Buscar producto…">
                        <button type="button" class="pl-btn pl-btn--verde" @click="nueva">+ Nueva plantilla</button>
                    </div>

                    <table v-if="filtradas.length" class="pl-tabla">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Operación</th>
                                <th>Sucursal</th>
                                <th class="pl-c">Conceptos</th>
                                <th>Actualizada</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in filtradas" :key="p.id">
                                <td><b>{{ p.producto }}</b><small v-if="p.descripcion">{{ p.descripcion }}</small></td>
                                <td><span class="pl-chip" :class="'pl-chip--' + (p.tipo_operacion || 'AMBAS')">{{
                                        nombreTipo(p.tipo_operacion) }}</span></td>
                                <td>{{ p.sucursal_nombre || 'Todas' }}</td>
                                <td class="pl-c">{{ p.conceptos }}</td>
                                <td class="pl-gris">{{ p.actualizada }}</td>
                                <td class="pl-acc">
                                    <button type="button" class="pl-ico" title="Ver y editar" aria-label="Ver plantilla"
                                        @click="ver(p.id)">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" aria-hidden="true">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                            <circle cx="12" cy="12" r="3" />
                                        </svg>
                                    </button>
                                    <button type="button" class="pl-ico pl-ico--rojo" title="Eliminar"
                                        aria-label="Eliminar plantilla" @click="eliminar(p)">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 6h18" />
                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                            <path d="M10 11v6M14 11v6" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="pl-vacio">
                        {{ textoVacio }}
                    </div>
                </template>

                <!-- ===== EDITOR ===== -->
                <template v-else>
                    <div v-if="errores.length" class="pl-alert pl-alert-err">
                        <b>No se guardó la plantilla:</b>
                        <ul>
                            <li v-for="(e, i) in errores" :key="i">{{ e }}</li>
                        </ul>
                    </div>

                    <section class="pl-card">
                        <h2 class="pl-card-title">Producto</h2>
                        <div class="pl-grid">
                            <label class="pl-field pl-field--2"><span>Producto / mercancía *</span>
                                <input v-model="datos.producto" class="pl-input" maxlength="255"
                                    placeholder="Ej. Aguacate Fresco" :disabled="soloLectura"></label>
                            <div class="pl-field"><span>Tipo de operación</span>
                                <div class="pl-op">
                                    <button v-for="t in tipos" :key="t.valor || 'ambas'" type="button"
                                        :disabled="soloLectura" :class="{ 'is-on': datos.tipo_operacion === t.valor }"
                                        @click="datos.tipo_operacion = t.valor">{{ t.nombre }}</button>
                                </div>
                            </div>
                            <div class="pl-field"><span>Sucursal</span>
                                <multiselect :value="sucursalElegida" :options="opcionesSucursal" track-by="clave"
                                    label="nombre" :allow-empty="false" :show-labels="false" placeholder="Todas"
                                    class="pl-ms" :disabled="soloLectura" @input="elegirSucursal" />
                            </div>
                            <label class="pl-field pl-field--4"><span>Descripción (opcional)</span>
                                <input v-model="datos.descripcion" class="pl-input" maxlength="255"
                                    placeholder="Ej. Exportación de aguacate por Nogales con bodega en USA"
                                    :disabled="soloLectura"></label>
                        </div>
                        <p class="pl-ayuda">
                            Al elegir este producto en una cotización se cargan estos conceptos. Se usa la plantilla que
                            coincida
                            con la operación y la sucursal; si no hay, la de "Ambas" / "Todas".
                        </p>
                    </section>

                    <bloque-conceptos v-for="s in secciones" :key="s.id" :seccion="s" :tipo-cambio="tipoCambio"
                        :iva="iva" :descripciones-doc="descripcionesDoc" :sucursal="datos.sucursal || null"
                        :sucursales="sucursales" :solo-lectura="soloLectura" />
                </template>
            </main>

            <footer class="pl-foot">
                <span>{{ textoPie }}</span>
                <div>
                    <template v-if="vista === 'editor'">
                        <button type="button" class="pl-btn pl-btn--gris" :disabled="guardando" @click="volver">Volver a
                            la lista</button>
                        <button v-if="id" type="button" class="pl-btn pl-btn--gris" :disabled="guardando"
                            @click="duplicar({ id: id })">Duplicar</button>
                        <button type="button" class="pl-btn pl-btn--verde" :disabled="guardando" @click="guardar">{{
                            textoGuardar }}</button>
                    </template>
                    <button v-else type="button" class="pl-btn pl-btn--gris" @click="cerrar">Cerrar</button>
                </div>
            </footer>
        </div>

        <transition name="pl-fade">
            <div v-if="toast" class="pl-toast">{{ toast }}</div>
        </transition>
    </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';
import BloqueConceptos from './BloqueConceptos.vue';

let llave = 0;

/**
 * Generador de plantillas: asocia un producto con los conceptos de cada bloque.
 *   <plantillas-productos ref="plantillas" base-url="" @cambio="..." />
 *   this.$refs.plantillas.abrir()
 */
export default {
    name: 'PlantillasProductos',

    components: { BloqueConceptos, Multiselect },

    props: {
        baseUrl: { type: String, default: '' },
    },

    data() {
        return {
            visible: false,
            vista: 'lista',
            cargando: false,
            guardando: false,
            error: '',
            errores: [],
            toast: '',

            plantillas: [],
            filtro: '',
            // Los campos siempre se pueden editar; al guardar se pide confirmación
            soloLectura: false,

            id: null,
            datos: { producto: '', tipo_operacion: null, sucursal: null, descripcion: '', activo: true },
            secciones: [],
            sucursales: [],
            descripcionesDoc: [],
            tipoCambio: 18.16,
            iva: 0.16,
            instantanea: null,

            tipos: [
                { valor: null, nombre: 'Ambas' },
                { valor: 'IMPORTACION', nombre: 'Importación' },
                { valor: 'EXPORTACION', nombre: 'Exportación' },
            ],
        };
    },

    computed: {
        /** Texto del template (fuera de {{ }} para que el formateador del editor no lo parta) */
        tituloVista() {
            if (this.vista !== 'editor') return 'Plantillas por producto';
            return this.id ? 'Plantilla' : 'Nueva plantilla';
        },
        /** Texto del template (fuera de {{ }} para que el formateador del editor no lo parta) */
        subtituloVista() {
            if (this.vista !== 'editor') return 'Conceptos que se cargan al elegir el producto en la cotización';
            return this.datos.producto || 'Producto sin nombre';
        },
        /** Texto del template (fuera de {{ }} para que el formateador del editor no lo parta) */
        textoVacio() {
            return this.plantillas.length ? 'Ningún producto coincide con la búsqueda.' : 'Aún no hay plantillas. Crea la primera con "+ Nueva plantilla".';
        },
        /** Texto del template (fuera de {{ }} para que el formateador del editor no lo parta) */
        textoPie() {
            if (this.vista === 'editor') return this.totalConceptos + (this.totalConceptos === 1 ? ' concepto' : ' conceptos');
            return this.plantillas.length + ' plantillas';
        },
        /** Texto del template (fuera de {{ }} para que el formateador del editor no lo parta) */
        textoGuardar() {
            return this.guardando ? 'Guardando…' : 'Guardar plantilla';
        },
        filtradas() {
            const f = this.filtro.toLowerCase();
            return f ? this.plantillas.filter(function (p) { return p.producto.toLowerCase().indexOf(f) !== -1; }) : this.plantillas;
        },
        opcionesSucursal() {
            return [{ clave: '', nombre: 'Todas' }].concat(this.sucursales.map(function (s) { return { clave: s.clave, nombre: s.nombre }; }));
        },
        sucursalElegida() {
            const clave = this.datos.sucursal || '';
            return this.opcionesSucursal.find(function (s) { return s.clave === clave; }) || this.opcionesSucursal[0];
        },
        totalConceptos() {
            return this.secciones.reduce(function (n, s) { return n + s.rows.length; }, 0);
        },
        hayCambios() {
            return this.vista === 'editor' && this.instantanea !== null && JSON.stringify(this.cuerpo()) !== this.instantanea;
        },
    },

    methods: {
        /* ---------- abrir / cerrar ---------- */
        abrir() {
            this.visible = true;
            this.vista = 'lista';
            this.cargarLista();
        },
        cerrar() {
            if (this.hayCambios && !window.confirm('Hay cambios sin guardar en la plantilla. ¿Cerrar de todos modos?')) return;
            this.visible = false;
            this.$emit('cerrado');
        },
        volver() {
            if (this.hayCambios && !window.confirm('Hay cambios sin guardar. ¿Volver a la lista?')) return;
            this.vista = 'lista';
            this.cargarLista();
        },

        /* ---------- lista ---------- */
        cargarLista() {
            this.cargando = true;
            this.error = '';
            axios.get(this.baseUrl + '/plantillas')
                .then((r) => { this.plantillas = r.data.plantillas || []; })
                .catch((e) => { this.error = 'No se pudieron cargar las plantillas. ' + this.mensaje(e); })
                .then(() => { this.cargando = false; });
        },
        nombreTipo(t) {
            return t === 'IMPORTACION' ? 'Importación' : (t === 'EXPORTACION' ? 'Exportación' : 'Ambas');
        },
        duplicar(p) {
            axios.post(this.baseUrl + '/plantillas/' + p.id + '/duplicar')
                .then((r) => { this.aviso(r.data.mensaje + ' Cámbiale la operación o la sucursal.'); this.editar(r.data.id); })
                .catch((e) => { this.aviso(this.mensaje(e)); });
        },
        eliminar(p) {
            if (!window.confirm('¿Eliminar la plantilla de ' + p.producto + '? Las cotizaciones ya hechas no cambian.')) return;
            axios.delete(this.baseUrl + '/plantillas/' + p.id)
                .then((r) => { this.aviso(r.data.mensaje); this.cargarLista(); this.$emit('cambio'); })
                .catch((e) => { this.aviso(this.mensaje(e)); });
        },

        /* ---------- editor ---------- */
        nueva() {
            this.soloLectura = false;
            this.abrirEditor(this.baseUrl + '/plantillas/nueva');
        },
        /** "Ver" abre la plantilla lista para editar; al guardar se pide confirmación */
        ver(id) {
            this.soloLectura = false;
            this.abrirEditor(this.baseUrl + '/plantillas/' + id);
        },
        editar(id) {
            this.soloLectura = false;
            this.abrirEditor(this.baseUrl + '/plantillas/' + id);
        },


        abrirEditor(url) {
            this.vista = 'editor';
            this.cargando = true;
            this.error = '';
            this.errores = [];
            axios.get(url)
                .then((r) => { this.cargarDatos(r.data); })
                .catch((e) => { this.error = 'No se pudo abrir la plantilla. ' + this.mensaje(e); })
                .then(() => { this.cargando = false; });
        },

        cargarDatos(p) {
            p.secciones.forEach(function (s) {
                s.rows.forEach(function (f) { f._k = 'p' + (++llave); });
            });
            this.id = p.id;
            this.datos = Object.assign({ producto: '', tipo_operacion: null, sucursal: null, descripcion: '', activo: true }, p.datos);
            this.secciones = p.secciones;
            this.sucursales = p.sucursales || [];
            this.descripcionesDoc = p.descripcionesDoc || [];
            this.tipoCambio = p.tipoCambio || 18.16;
            this.iva = p.iva || 0.16;
            this.instantanea = JSON.stringify(this.cuerpo());
        },

        /** Cambiar la sucursal quita los conceptos de los bloques que dependen de ella (códigos distintos) */
        elegirSucursal(opcion) {
            const nueva = opcion && opcion.clave ? opcion.clave : null;
            if (nueva === this.datos.sucursal) return;

            const afectados = this.secciones.filter(function (s) { return !s.sin_sucursal && s.rows.length; });
            const total = afectados.reduce(function (n, s) { return n + s.rows.length; }, 0);
            if (total && !window.confirm('Cada sucursal tiene códigos diferentes. Se quitarán ' + total + ' conceptos de: '
                + afectados.map(function (s) { return s.nombre; }).join(', ') + '. ¿Continuar?')) {
                this.$forceUpdate();
                return;
            }
            afectados.forEach(function (s) { s.rows.splice(0, s.rows.length); });
            this.datos.sucursal = nueva;
        },

        cuerpo() {
            const conceptos = {};
            this.secciones.forEach(function (s) {
                if (!s.rows.length) return;
                conceptos[s.id] = s.rows.map(function (f) {
                    return {
                        codigo: f.codigo,
                        clave_sat: f.clave_sat || null,
                        descripcion: f.descripcion,
                        cantidad: f.cantidad,
                        precio_usd: f.al_costo ? 0 : (parseFloat(f.precio_usd) || 0),
                        precio_mxn: f.al_costo ? 0 : (parseFloat(f.precio_mxn) || 0),
                        al_costo: !!f.al_costo,
                        descripcion_doc: f.descripcion_doc,
                        nota: f.nota || null,
                    };
                });
            });
            return Object.assign({}, this.datos, { conceptos: conceptos });
        },

        /** Pide confirmación (advertencia) y después guarda */
        guardar() {
            if (this.id && !this.hayCambios) {
                this.aviso('No hay cambios por guardar.');
                return;
            }
            const producto = this.datos.producto || 'este producto';
            Swal.fire({
                title: this.id ? '¿Guardar cambios?' : '¿Guardar plantilla?',
                text: this.id
                    ? 'Se modificará la plantilla de ' + producto + '. Las cotizaciones nuevas tomarán los conceptos actualizados; las que ya se hicieron no cambian.'
                    : 'Se creará la plantilla de ' + producto + ' con ' + this.totalConceptos + ' conceptos.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#9ca3af',
                confirmButtonText: 'Sí, guardar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
            }).then((r) => {
                if (r.isConfirmed) this.guardarConfirmado();
            });
        },

        guardarConfirmado() {
            this.guardando = true;
            this.errores = [];
            const peticion = this.id
                ? axios.put(this.baseUrl + '/plantillas/' + this.id, this.cuerpo())
                : axios.post(this.baseUrl + '/plantillas', this.cuerpo());

            peticion
                .then((r) => {
                    this.cargarDatos(r.data.plantilla);
                    this.aviso(r.data.mensaje);
                    this.$emit('cambio');
                })
                .catch((e) => {
                    const resp = e && e.response;
                    if (resp && resp.status === 422 && resp.data && resp.data.errors) {
                        this.errores = Object.keys(resp.data.errors).reduce(function (l, k) { return l.concat(resp.data.errors[k]); }, []);
                    } else {
                        this.errores = [this.mensaje(e)];
                    }
                    this.$el.querySelector('.pl-body').scrollTop = 0;
                })
                .then(() => { this.guardando = false; });
        },

        /* ---------- utilidades ---------- */
        mensaje(e) {
            const r = e && e.response;
            if (r && r.data && r.data.message) return r.data.message;
            return 'No hubo respuesta del servidor.';
        },
        aviso(m) {
            this.toast = m;
            clearTimeout(this._toast);
            this._toast = setTimeout(() => { this.toast = ''; }, 3500);
        },
    },
};
</script>

<style scoped>
/* ===================================================================== *
 |  PlantillasProductos: lista y editor de plantillas por producto.
 |  Misma paleta que el generador; BloqueConceptos usa estas variables.
 * ===================================================================== */
.pl-overlay {
    --navy: #2A3A4D;
    --teal: #00C09F;
    --blue: #1d5fa5;
    --amber: #d4832e;
    --orange: #f7931e;
    --orange-d: #e57f05;
    --green: #2ecc71;
    --green-d: #22a85a;
    --red: #e74c3c;
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
    font-family: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
    font-size: 14px;
    color: var(--ink);
}

.pl-overlay *,
.pl-overlay *::before,
.pl-overlay *::after {
    box-sizing: border-box;
}

.pl-overlay button,
.pl-overlay input {
    font-family: inherit;
}

.pl {
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

.pl-top {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 24px;
    min-height: 60px;
    background: var(--navy);
    color: #fff;
}

.pl-titulo {
    flex: 1;
    min-width: 0;
}

.pl-titulo h1 {
    margin: 0;
    font-size: 16px !important;
    line-height: 1.2 !important;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #fff;
}

.pl-titulo p {
    margin: 3px 0 0;
    font-size: 11px;
    color: #bfdbfe;
}

.pl-close {
    border: 0;
    background: none;
    font-size: 28px !important;
    font-weight: 300;
    line-height: 1 !important;
    color: #bfdbfe;
    cursor: pointer;
}

.pl-close:hover {
    color: #fff;
}

.pl-body {
    flex: 1;
    overflow: auto;
    padding: 24px 32px;
}

.pl-cargando,
.pl-vacio {
    padding: 50px;
    text-align: center;
    color: var(--muted);
    font-weight: 600;
}

.pl-alert {
    margin-bottom: 16px;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
}

.pl-alert ul {
    margin: 6px 0 0;
    padding-left: 18px;
}

.pl-alert-err {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #991b1b;
}

/* Lista */
.pl-toolbar {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}

.pl-toolbar .pl-input {
    flex: 1;
}

.pl-input {
    width: 100%;
    height: 42px;
    padding: 0 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    color: var(--ink);
}

.pl-input:focus {
    outline: none;
    border-color: #9ca3af;
}

.pl-tabla {
    width: 100%;
    border-collapse: collapse;
}

.pl-tabla th {
    padding: 12px 10px;
    border-bottom: 1px solid #e5e7eb;
    background: #f3f4f6;
    text-align: left;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: #6b7280;
}

.pl-tabla td {
    padding: 12px 10px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.pl-tabla td b {
    display: block;
    text-transform: uppercase;
}

.pl-tabla td small {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    color: var(--muted);
}

.pl-c {
    text-align: center !important;
}

.pl-gris {
    font-size: 12px;
    color: var(--muted);
}

.pl-chip {
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}

.pl-chip--AMBAS {
    background: #f3f4f6;
    color: #4b5563;
}

.pl-chip--IMPORTACION {
    background: #eff6ff;
    color: #1d5fa5;
}

.pl-chip--EXPORTACION {
    background: #fdf4ff;
    color: #a21caf;
}

.pl-acc {
    white-space: nowrap;
    text-align: right;
}

.pl-ico {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    margin-left: 6px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background: #fff;
    color: var(--navy);
    cursor: pointer;
}

.pl-ico:hover {
    background: #f3f4f6;
}

.pl-ico--rojo {
    color: var(--red);
}

.pl-ico--rojo:hover {
    border-color: #fecaca;
    background: #fef2f2;
}

.pl-input:disabled {
    background: #f8fafc;
    color: var(--ink);
    cursor: default;
}

.pl-op button:disabled {
    cursor: default;
}

.pl-op button:disabled:not(.is-on) {
    opacity: .55;
}

.pl-lnk {
    margin-left: 12px;
    padding: 0;
    border: 0;
    background: none;
    font-size: 13px;
    font-weight: 700;
    color: var(--navy);
    cursor: pointer;
}

.pl-lnk:hover {
    text-decoration: underline;
}

.pl-lnk--rojo {
    color: var(--red);
}

/* Editor */
.pl-card {
    margin-bottom: 26px;
}

.pl-card-title {
    margin: 0 0 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 13px !important;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--navy);
}

.pl-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px 20px;
}

.pl-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
    margin: 0;
}

.pl-field--2 {
    grid-column: span 2;
}

.pl-field--4 {
    grid-column: span 4;
}

.pl-field>span {
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: #6b7280;
}

.pl-ayuda {
    margin: 14px 0 0;
    font-size: 12px;
    color: var(--muted);
}

.pl-op {
    display: flex;
    gap: 6px;
}

.pl-op button {
    flex: 1;
    height: 42px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    background: #fff;
    font-size: 11.5px;
    font-weight: 800;
    text-transform: uppercase;
    color: #6b7280;
    cursor: pointer;
}

.pl-op button.is-on {
    border-color: var(--teal);
    background: #e6f9f5;
    color: #00806a;
}

.pl-ms {
    min-height: 42px;
}

.pl-ms ::v-deep .multiselect__tags {
    min-height: 42px;
    padding: 11px 40px 0 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
}

.pl-ms ::v-deep .multiselect__select {
    height: 42px;
}

.pl-ms ::v-deep .multiselect__single {
    margin-bottom: 11px;
    padding-left: 0;
    font-size: 14px;
    font-weight: 600;
}

.pl-ms ::v-deep .multiselect__content-wrapper {
    z-index: 1075;
}

.pl-ms ::v-deep .multiselect__option--highlight {
    background: var(--teal);
}

/* Pie y botones (como Cancelar / Guardar Nuevo Ingreso) */
.pl-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 28px;
    border-top: 1px solid #e5e7eb;
    background: #f3f4f6;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: #6b7280;
}

.pl-foot>div {
    display: flex;
    gap: 12px;
}

.pl-btn {
    height: 44px;
    padding: 0 22px;
    border: 0;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
}

.pl-btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.pl-btn--gris {
    background: #edf2f7;
    color: #4a5568;
}

.pl-btn--gris:hover {
    background: #e2e8f0;
}

.pl-btn--verde {
    background: #38a169;
    color: #fff;
}

.pl-btn--verde:hover {
    background: #2f855a;
}

.pl-toast {
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

.pl-fade-enter-active,
.pl-fade-leave-active {
    transition: opacity .2s;
}

.pl-fade-enter,
.pl-fade-leave-to {
    opacity: 0;
}

@media (max-width: 900px) {
    .pl-grid {
        grid-template-columns: 1fr 1fr;
    }

    .pl-field--4 {
        grid-column: span 2;
    }

    .pl-body {
        padding: 18px;
    }
}
</style>

<style>
/* Global: la advertencia de SweetAlert2 se muestra por encima del modal de plantillas */
.swal2-container {
    z-index: 1090 !important;
}
</style>
