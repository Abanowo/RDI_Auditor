<template>
    <div class="cc">
        <multiselect
            ref="ms"
            :value="value"
            :options="opciones"
            :multiple="true"
            :taggable="true"
            :searchable="true"
            :close-on-select="false"
            :clear-on-select="true"
            :show-labels="false"
            :placeholder="value.length ? '' : placeholder"
            tag-placeholder="Presiona Enter para agregar este correo"
            open-direction="below"
            class="cc-ms"
            :class="{ 'cc-ms--error': error }"
            @input="cambiar"
            @tag="agregar"
            @search-change="busqueda = $event"
            @close="alCerrar">
            <template slot="noOptions">Escribe un correo y presiona Enter</template>
            <template slot="noResult">Presiona Enter para agregarlo</template>
        </multiselect>
        <small v-if="error" class="cc-error">{{ error }}</small>
        <small v-else-if="ayuda" class="cc-ayuda">{{ ayuda }}</small>
    </div>
</template>

<script>
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

const CORREO = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]{2,}$/;

/**
 * Campo para capturar varios correos con vue-multiselect.
 *
 *   <campo-correos v-model="para" :sugerencias="['compras@cliente.com']" placeholder="Para" />
 *
 * - Escribe un correo y presiona Enter (o coma) para agregarlo como etiqueta.
 * - Se pueden pegar varios a la vez: "a@x.com, b@y.com; c@z.com".
 * - Si sales del campo con un correo escrito, también se agrega.
 * - v-model es un arreglo de strings.
 */
export default {
    name: 'CampoCorreos',

    components: { Multiselect },

    props: {
        value: { type: Array, default: function () { return []; } },
        sugerencias: { type: Array, default: function () { return []; } },
        placeholder: { type: String, default: 'Escribe un correo y presiona Enter' },
        ayuda: { type: String, default: '' },
    },

    data() {
        return {
            busqueda: '',
            error: '',
        };
    },

    computed: {
        /** Sugerencias + correos ya agregados, sin repetir */
        opciones() {
            const vistos = {};
            return this.sugerencias.concat(this.value).filter(function (c) {
                const k = String(c || '').toLowerCase();
                if (!k || vistos[k]) return false;
                vistos[k] = true;
                return true;
            });
        },
    },

    watch: {
        /** Coma o punto y coma mientras escribe = agregar */
        busqueda(texto) {
            if (/[,;]\s*$/.test(texto || '')) {
                this.agregar(texto);
            }
        },
    },

    methods: {
        cambiar(lista) {
            this.error = '';
            this.$emit('input', lista);
        },

        /** Agrega uno o varios correos escritos o pegados */
        agregar(texto) {
            const piezas = String(texto || '').split(/[\s,;]+/).map(function (c) { return c.trim(); }).filter(Boolean);
            const actuales = this.value.slice();
            const invalidos = [];

            piezas.forEach(function (correo) {
                if (!CORREO.test(correo)) {
                    invalidos.push(correo);
                    return;
                }
                const ya = actuales.some(function (c) { return c.toLowerCase() === correo.toLowerCase(); });
                if (!ya) actuales.push(correo.toLowerCase());
            });

            this.error = invalidos.length ? 'Correo no válido: ' + invalidos.join(', ') : '';
            this.busqueda = '';
            // Limpia el texto que quedó escrito dentro del multiselect
            if (this.$refs.ms) this.$refs.ms.search = '';
            this.$emit('input', actuales);
        },

        /** Al salir del campo con texto escrito, se agrega */
        alCerrar() {
            if (this.busqueda && this.busqueda.indexOf('@') !== -1) {
                this.agregar(this.busqueda);
            }
        },
    },
};
</script>

<style scoped>
/* ===================================================================== *
 |  CampoCorreos: varios correos como etiquetas (vue-multiselect)
 * ===================================================================== */
.cc { display: flex; flex-direction: column; gap: 4px; }

.cc-ms { min-height: 42px; font-size: 14px; }
.cc-ms ::v-deep .multiselect__tags { min-height: 42px; padding: 8px 40px 0 10px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
.cc-ms--error ::v-deep .multiselect__tags { border-color: #fca5a5; }
.cc-ms ::v-deep .multiselect__select { height: 40px; }
.cc-ms ::v-deep .multiselect__placeholder { margin-bottom: 7px; padding-left: 2px; font-size: 13px; color: #94a3b8; }
.cc-ms ::v-deep .multiselect__input { margin-bottom: 6px; padding-left: 2px; background: transparent; font-size: 13px; }

/* Cada correo como etiqueta */
.cc-ms ::v-deep .multiselect__tag { margin: 0 6px 6px 0; padding: 4px 24px 4px 9px; border-radius: 4px; background: #2A3A4D; font-size: 12px; font-weight: 600; color: #fff; }
.cc-ms ::v-deep .multiselect__tag-icon { width: 20px; border-radius: 0 4px 4px 0; line-height: 22px; }
.cc-ms ::v-deep .multiselect__tag-icon::after { color: #cbd5e1; }
.cc-ms ::v-deep .multiselect__tag-icon:hover { background: #e74c3c; }
.cc-ms ::v-deep .multiselect__tag-icon:hover::after { color: #fff; }

.cc-ms ::v-deep .multiselect__content-wrapper { z-index: 1080; border-color: #e2e8f0; box-shadow: 0 10px 24px rgba(15, 23, 42, .16); }
.cc-ms ::v-deep .multiselect__option { min-height: 0; padding: 9px 12px; font-size: 13px; }
.cc-ms ::v-deep .multiselect__option--highlight { background: #00C09F; }
.cc-ms ::v-deep .multiselect__option--highlight::after { background: #00C09F; }

.cc-error { font-size: 11px; font-weight: 700; color: #b91c1c; }
.cc-ayuda { font-size: 10px; color: #94a3b8; }
</style>
