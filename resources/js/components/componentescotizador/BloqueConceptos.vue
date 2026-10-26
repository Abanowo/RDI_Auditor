<template>
    <section class="cz-block" :class="'cz-block--' + seccion.color">
        <div class="cz-block-head">
            <button type="button" class="cz-plus" title="Agregar concepto" aria-label="Agregar concepto"
                @click="agregar">
                <svg viewBox="0 0 12 12" width="9" height="9" aria-hidden="true">
                    <path d="M6 1.5v9M1.5 6h9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
            <h2>{{ seccion.nombre }}</h2>
            <button type="button" class="cz-add" @click="agregar">+ Agregar row</button>
        </div>

        <div v-if="!seccion.rows.length" class="cz-empty">
            Sin conceptos asignados. Haz clic en + para agregar uno.
        </div>

        <template v-else>
            <div class="cz-scroll">
                <table class="cz-table">
                    <thead>
                        <tr>
                            <th>Acción</th>
                            <th>Predefinidos</th>
                            <th>Código</th>
                            <th>Descripción del concepto</th>
                            <th>Cant.</th>
                            <th>Dólar ($US)</th>
                            <th>Pesos ($MX)</th>
                            <th>Descripción doc</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(fila, i) in seccion.rows" :key="fila._k">
                            <td>
                                <button type="button" class="cz-x" title="Quitar concepto"
                                    @click="quitar(i)">&#10005;</button>
                            </td>
                            <td>
                                <multiselect :value="null" :options="opcionesCombo"
                                    :group-values="agrupado ? 'conceptos' : undefined"
                                    :group-label="agrupado ? 'etiqueta' : undefined" :group-select="false" track-by="id"
                                    :custom-label="etiqueta" placeholder="-- Cargar --" :show-labels="false"
                                    :title="tituloCombo" open-direction="below" :max-height="280"
                                    class="cz-ms cz-ms--cargar" @select="cargar(fila, $event)">
                                    <template slot="noResult">Sin coincidencias</template>
                                    <template slot="noOptions">{{ avisoCombo || 'Sin conceptos' }}</template>
                                </multiselect>
                            </td>
                            <td><input v-model="fila.codigo" class="cz-in cz-code" maxlength="20"
                                    :title="fila.clave_sat ? 'Clave SAT ' + fila.clave_sat : ''"></td>
                            <td><input v-model="fila.descripcion" class="cz-in cz-desc" maxlength="255"></td>
                            <td><input v-model="fila.cantidad" type="number" step="0.01" min="0" class="cz-in cz-qty">
                            </td>
                            <td>
                                <div class="cz-money cz-money--usd"><em>$US</em>
                                    <input v-model="fila.precio_usd" type="number" step="0.01" min="0">
                                </div>
                            </td>
                            <td>
                                <div class="cz-money cz-money--mxn" :class="{ 'is-calc': usd(fila) }"><em>$MX</em>
                                    <input v-if="usd(fila)" :value="corto(importe(fila))" readonly
                                        title="Cantidad × dólares × tipo de cambio">
                                    <input v-else v-model="fila.precio_mxn" type="number" step="0.01" min="0">
                                </div>
                            </td>
                            <td>
                                <multiselect v-model="fila.descripcion_doc" :options="descripcionesDoc"
                                    :allow-empty="false" :searchable="false" :show-labels="false"
                                    placeholder="Seleccione..." open-direction="below" class="cz-ms cz-ms--doc">
                                </multiselect>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="cz-block-foot">
                <span>Subtotal: <b>${{ fmt(subtotal) }} MXN</b></span>
                <template v-if="seccion.aplica_iva">
                    <span>IVA ({{ Math.round(iva * 100) }}%): <b>${{ fmt(ivaBloque) }} MXN</b></span>
                    <span class="cz-block-total">Total block: <b>${{ fmt(total) }} MXN</b></span>
                </template>
            </div>
        </template>
    </section>
</template>

<script>
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

let llave = 0;

export default {
    name: 'BloqueConceptos',

    components: { Multiselect },

    props: {
        seccion: { type: Object, required: true },
        tipoCambio: { type: [Number, String], required: true },
        iva: { type: Number, default: 0.16 },
        descripcionesDoc: { type: Array, required: true },
        // Clave de la sucursal del puerto (ej. 'NOGALES'); null = mostrar todas agrupadas
        sucursal: { type: String, default: null },
        sucursales: { type: Array, default: function () { return []; } },
    },

    computed: {
        /* Reglas de cálculo (las mismas que App\Services\CotizacionCalculator en el servidor) */
        subtotal() {
            return this.r2(this.seccion.rows.reduce((suma, fila) => suma + this.importe(fila), 0));
        },
        ivaBloque() {
            return this.seccion.aplica_iva ? this.r2(this.subtotal * this.iva) : 0;
        },
        total() {
            return this.r2(this.subtotal + this.ivaBloque);
        },

        /**
         * Prefijos de cada sucursal, el más largo primero (NOG1 antes que NOG, NL antes que N,
         * ZLO antes que Z), para saber de qué sucursal es un código: N101 → Nogales, NL101 → Laredo.
         */
        prefijos() {
            const lista = [];
            this.sucursales.forEach(function (s) {
                (s.prefijos || []).forEach(function (p) {
                    lista.push({ prefijo: String(p).toUpperCase(), clave: s.clave });
                });
            });
            return lista.sort(function (a, b) { return b.prefijo.length - a.prefijo.length; });
        },

        /**
         * Conceptos del combo. Con sucursal elegida, SOLO los códigos con su prefijo
         * (Nogales: N, NOG, NOG1). Sin sucursal, todos agrupados por sucursal.
         */
        grupos() {
            const catalogo = this.seccion.catalogo || [];

            // Impuestos, LLC y Certificado: códigos sin sucursal, se muestran siempre
            if (this.seccion.sin_sucursal) {
                return [{ clave: 'unico', etiqueta: null, conceptos: catalogo }];
            }

            if (this.sucursal) {
                const suc = this.sucursal;
                return [{ clave: 'unico', etiqueta: null, conceptos: catalogo.filter((c) => this.sucursalDe(c) === suc) }];
            }

            const grupos = [];
            this.sucursales.forEach((s) => {
                const lista = catalogo.filter((c) => this.sucursalDe(c) === s.clave);
                if (lista.length) {
                    grupos.push({ clave: s.clave, etiqueta: s.nombre, conceptos: lista });
                }
            });
            const sinSucursal = catalogo.filter((c) => !this.sucursalDe(c));
            if (sinSucursal.length) {
                grupos.push({ clave: 'sin-sucursal', etiqueta: 'Sin sucursal', conceptos: sinSucursal });
            }
            return grupos;
        },

        /** ¿El combo va agrupado por sucursal? (solo cuando no hay sucursal elegida) */
        agrupado() {
            return this.grupos.length > 1 || (this.grupos.length === 1 && !!this.grupos[0].etiqueta);
        },

        /** Opciones para vue-multiselect: lista plana o grupos { etiqueta, conceptos } */
        opcionesCombo() {
            if (this.agrupado) {
                return this.grupos;
            }
            return this.grupos.length ? this.grupos[0].conceptos : [];
        },

        /** Mensaje dentro del combo cuando no hay conceptos que mostrar o se quitó el filtro. */
        avisoCombo() {
            const catalogo = this.seccion.catalogo || [];
            if (!catalogo.length) {
                return '(Este bloque no tiene conceptos predefinidos)';
            }
            if (this.sucursal && !this.seccion.sin_sucursal && !catalogo.some((c) => this.sucursalDe(c) === this.sucursal)) {
                const s = this.sucursales.find((x) => x.clave === this.sucursal);
                return '(Sin códigos de ' + (s ? s.nombre : this.sucursal) + ' en este bloque)';
            }
            return '';
        },

        tituloCombo() {
            if (this.seccion.sin_sucursal) return 'Códigos de este bloque (no dependen de la sucursal)';
            if (!this.sucursal) return 'Elige la sucursal en la información general para ver solo sus códigos';
            const s = this.sucursales.find((x) => x.clave === this.sucursal);
            return 'Códigos de ' + (s ? s.nombre : this.sucursal);
        },
    },

    watch: {
        total: {
            immediate: true,
            handler(valor) { this.$emit('total', { id: this.seccion.id, total: valor }); },
        },
    },

    methods: {
        r2(n) { return Math.round((Number(n) + Number.EPSILON) * 100) / 100; },
        num(v) { const n = parseFloat(v); return isNaN(n) ? 0 : n; },
        fmt(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        /** Pesos calculados como en el diseño: 908, 90.8, 27113.4 */
        corto(n) { return String(Math.round(Number(n) * 100) / 100); },
        /** ¿El concepto se cobra en dólares? */
        usd(fila) { return this.num(fila.precio_usd) > 0; },
        /** Dólares: cantidad × USD × tipo de cambio. Pesos: cantidad × MXN. */
        importe(fila) {
            const cantidad = this.num(fila.cantidad);
            if (this.usd(fila)) return this.r2(cantidad * this.num(fila.precio_usd) * this.num(this.tipoCambio));
            return this.r2(cantidad * this.num(fila.precio_mxn));
        },
        // "N110 · CONECTIVIDAD" (el código primero para buscarlo tecleando en el combo)
        etiqueta(c) { return (c.codigo ? c.codigo + ' · ' : '') + c.descripcion; },

        /** Sucursal de un concepto por el prefijo de su código; null si no coincide con ninguno. */
        sucursalDe(c) {
            const codigo = String(c.codigo || '').toUpperCase().trim();
            for (let i = 0; i < this.prefijos.length; i++) {
                const p = this.prefijos[i];
                if (codigo.indexOf(p.prefijo) === 0 && /^\d*$/.test(codigo.slice(p.prefijo.length))) {
                    return p.clave;
                }
            }
            return null;
        },

        agregar() {
            this.seccion.rows.push({
                _k: 'n' + (++llave),
                codigo: '',
                clave_sat: '',
                descripcion: '',
                cantidad: 1,
                precio_usd: 0,
                precio_mxn: 0,
                descripcion_doc: this.descripcionesDoc[0],
            });
        },

        quitar(i) {
            this.seccion.rows.splice(i, 1);
        },

        /** Concepto elegido en el combo "-- Cargar --" (vue-multiselect @select). */
        cargar(fila, c) {
            if (c && c.id !== undefined) {
                fila.codigo = c.codigo || '';
                fila.clave_sat = c.clave_sat || '';
                fila.descripcion = c.descripcion;
                fila.precio_usd = c.precio_usd;
                fila.precio_mxn = c.precio_mxn;
                fila.descripcion_doc = c.descripcion_doc;
            }
        },
    },
};
</script>

<style scoped>
/* ===================================================================== *
 |  BloqueConceptos: un bloque (Honorarios, Servicio de operación…)
 |  Los colores (--blue, --orange…) vienen de CotizacionGenerador.vue.
 * ===================================================================== */

/* Medidas y fuente iguales a las del modal (el estilo scoped del padre no llega aquí) */
.cz-block,
.cz-block *,
.cz-block *::before,
.cz-block *::after {
    box-sizing: border-box;
}

.cz-block button,
.cz-block input,
.cz-block textarea {
    font-family: inherit;
}

/* ---------- Contenedor ---------- */
.cz-block {
    margin-bottom: 26px;
    overflow: visible;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .06);
}

.cz-block> :last-child {
    border-radius: 0 0 11px 11px;
}

/* ---------- Encabezado (compacto) ---------- */
.cz-block-head {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 40px;
    padding: 7px 16px;
    border-radius: 11px 11px 0 0;
    background: var(--blue);
    color: #fff;
}

.cz-block--naranja .cz-block-head {
    background: var(--amber);
}

.cz-block-head h2 {
    flex: 1;
    margin: 0;
    font-size: 11.5px !important;
    font-weight: 800;
    line-height: 1.2 !important;
    letter-spacing: .02em;
    text-transform: uppercase;
    color: #fff;
}

.cz-plus {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: none;
    width: 18px;
    height: 18px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    line-height: 0;
    background: rgba(0, 0, 0, .25);
    color: #fff;
    cursor: pointer;
}

.cz-plus svg {
    display: block;
}

.cz-plus:hover {
    background: rgba(0, 0, 0, .4);
}

.cz-add {
    display: inline-flex;
    align-items: center;
    height: 24px;
    padding: 0 10px !important;
    border: 0;
    border-radius: 4px;
    background: rgba(255, 255, 255, .2);
    font-size: 9.5px !important;
    font-weight: 800;
    line-height: 1 !important;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #fff !important;
    cursor: pointer;
}

.cz-add:hover {
    background: rgba(255, 255, 255, .3);
}

.cz-empty {
    padding: 26px;
    background: #f8fafc;
    text-align: center;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--soft);
}

/* ---------- Tabla ---------- */
.cz-scroll {
    overflow: visible;
}

.cz-table {
    width: 100%;
    border-collapse: collapse;
}

.cz-table th {
    padding: 12px 8px 10px;
    border-bottom: 1px solid var(--line);
    background: #fff;
    text-align: left;
    white-space: nowrap;
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .02em;
    text-transform: uppercase;
    color: #475569;
}

.cz-table td {
    padding: 8px;
    border-bottom: 1px solid #edf1f6;
}

.cz-table th:first-child,
.cz-table td:first-child {
    width: 54px;
    padding-left: 10px;
    text-align: center;
}

.cz-x {
    padding: 4px;
    border: 0;
    background: none;
    font-size: 17px;
    font-weight: 300;
    color: var(--red) !important;
    cursor: pointer;
}

.cz-in {
    height: 26px;
    padding: 0 8px;
    border: 1px solid var(--line);
    border-radius: 4px;
    background: #fff;
    font-size: 12px !important;
    font-weight: 700;
    color: var(--ink);
}

.cz-in:focus {
    outline: none;
    border-color: var(--orange);
}

.cz-code {
    width: 66px;
    text-align: center;
}

.cz-desc {
    width: 100%;
    min-width: 230px;
    padding: 0 9px;
    background: var(--field);
}

.cz-qty {
    width: 56px;
    text-align: center;
}

.cz-money {
    display: flex;
    align-items: center;
    height: 26px;
    padding: 0 7px;
    border: 1px solid var(--line);
    border-radius: 4px;
    background: #fff;
}

.cz-money--usd {
    width: 76px;
}

.cz-money--mxn {
    width: 96px;
}

.cz-money:focus-within {
    border-color: var(--orange);
}

.cz-money em {
    margin-right: 4px;
    font-style: normal;
    font-size: 8.5px;
    font-weight: 700;
    color: var(--soft);
}

.cz-money input {
    width: 100%;
    padding: 0;
    border: 0;
    background: none;
    text-align: right;
    font-size: 13px !important;
    font-weight: 800;
    color: var(--ink);
}

.cz-money input:focus {
    outline: none;
}

.cz-money.is-calc {
    border-color: #eef1f5;
    background: #eef1f5;
}

.cz-money.is-calc input {
    color: var(--soft) !important;
}

/* "-- Cargar --" (vue-multiselect) naranja y compacto */
.cz-ms--cargar {
    width: 116px;
    min-height: 26px;
}

.cz-ms--cargar ::v-deep .multiselect__tags {
    min-height: 26px;
    padding: 5px 24px 0 6px;
    border: 1px solid var(--orange-d);
    border-radius: 4px;
    background: var(--orange);
    text-align: center;
}

.cz-ms--cargar ::v-deep .multiselect__placeholder,
.cz-ms--cargar ::v-deep .multiselect__input,
.cz-ms--cargar ::v-deep .multiselect__single {
    margin-bottom: 5px;
    padding: 0;
    background: transparent;
    text-align: center;
    font-size: 10.5px;
    font-weight: 800;
    color: #fff;
}

.cz-ms--cargar ::v-deep .multiselect__input::placeholder {
    color: rgba(255, 255, 255, .85);
}

.cz-ms--cargar ::v-deep .multiselect__select {
    width: 24px;
    height: 24px;
    padding: 2px 4px;
}

.cz-ms--cargar ::v-deep .multiselect__select::before {
    border-color: #fff transparent transparent;
}

.cz-ms--cargar ::v-deep .multiselect__content-wrapper {
    z-index: 1075;
    width: 430px;
    text-align: left;
    border-color: var(--line);
    box-shadow: 0 10px 24px rgba(15, 23, 42, .16);
}

/* "Descripción doc" (vue-multiselect) blanco con flecha */
.cz-ms--doc {
    width: 126px;
    min-height: 26px;
}

.cz-ms--doc ::v-deep .multiselect__tags {
    min-height: 26px;
    padding: 5px 24px 0 8px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    background: #fff;
    text-align: center;
}

.cz-ms--doc ::v-deep .multiselect__single {
    margin-bottom: 5px;
    padding: 0;
    background: transparent;
    font-size: 12px;
    font-weight: 600;
    color: var(--ink);
}

.cz-ms--doc ::v-deep .multiselect__select {
    width: 24px;
    height: 24px;
    padding: 2px 4px;
}

.cz-ms--doc ::v-deep .multiselect__select::before {
    border-color: var(--ink) transparent transparent;
}

.cz-ms--doc ::v-deep .multiselect__content-wrapper {
    z-index: 1075;
    width: 170px;
}

/* Opciones de los dos combos */
.cz-ms--cargar ::v-deep .multiselect__option,
.cz-ms--doc ::v-deep .multiselect__option {
    min-height: 0;
    padding: 9px 12px;
    font-size: 12.5px;
    line-height: 1.35;
    white-space: normal;
}

.cz-ms--cargar ::v-deep .multiselect__option--highlight,
.cz-ms--doc ::v-deep .multiselect__option--highlight {
    background: #00C09F;
}

.cz-ms--cargar ::v-deep .multiselect__option--group {
    background: #eef2f7;
    color: #2A3A4D;
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
}

/* ---------- Pie del bloque ---------- */
.cz-block-foot {
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    padding: 11px 24px;
    border-top: 1px solid #e5e7eb;
    background: #f3f4f6;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .01em;
    text-transform: uppercase;
    color: var(--muted);
}

.cz-block-foot b {
    font-weight: 800;
    color: #0f172a;
}

.cz-block-total,
.cz-block-total b {
    color: var(--blue);
}

/* En pantallas angostas la tabla se desplaza */
@media (max-width: 1100px) {
    .cz-scroll {
        overflow-x: auto;
    }

    .cz-table {
        min-width: 960px;
    }
}
</style>
