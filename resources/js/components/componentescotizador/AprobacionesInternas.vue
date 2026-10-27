<template>
    <section class="ai">
        <div class="ai-head">
            <h2>Aprobaciones internas</h2>
            <span class="ai-estado" :class="'ai-estado--' + estadoGeneral">{{ textoEstado }}</span>
        </div>

        <p v-if="!aprobadaCliente" class="ai-nota">
            Inician cuando el cliente aprueba la cotización (desde el correo o marcando "Aprobada por cliente" y guardando).
        </p>

        <div v-else class="ai-grid">
            <div v-for="a in areas" :key="a.area" class="ai-card" :class="'ai-card--' + a.estado">
                <div class="ai-card-top">
                    <b>{{ a.nombre }}</b>
                    <span class="ai-chip" :class="'ai-chip--' + a.estado">{{ etiqueta(a.estado) }}</span>
                </div>

                <p v-if="a.estado !== 'pendiente'" class="ai-quien">
                    {{ a.usuario || '—' }}<template v-if="a.fecha"> · {{ a.fecha }}</template>
                </p>
                <p v-if="a.comentario" class="ai-comentario">“{{ a.comentario }}”</p>

                <template v-if="a.puede">
                    <textarea v-if="abierta === a.area" v-model="comentario" rows="2" class="ai-texto"
                        :placeholder="accion === 'rechazar' ? 'Motivo del rechazo (obligatorio)' : 'Comentario (opcional)'"></textarea>

                    <div class="ai-botones">
                        <template v-if="abierta === a.area">
                            <button type="button" class="ai-btn ai-btn--gris" :disabled="enviando" @click="cancelar">Cancelar</button>
                            <button type="button" class="ai-btn" :class="accion === 'aprobar' ? 'ai-btn--verde' : 'ai-btn--rojo'"
                                :disabled="enviando || (accion === 'rechazar' && !comentario.trim())" @click="enviar(a)">
                                {{ enviando ? 'Guardando…' : (accion === 'aprobar' ? 'Confirmar aprobación' : 'Confirmar rechazo') }}
                            </button>
                        </template>
                        <template v-else>
                            <button v-if="a.estado !== 'rechazada'" type="button" class="ai-btn ai-btn--gris" @click="abrir(a, 'rechazar')">Rechazar</button>
                            <button v-if="a.estado !== 'aprobada'" type="button" class="ai-btn ai-btn--verde" @click="abrir(a, 'aprobar')">Aprobar</button>
                        </template>
                    </div>
                </template>
                <p v-else-if="a.estado === 'pendiente'" class="ai-nota ai-nota--chica">Esperando a {{ a.nombre }}.</p>
            </div>
        </div>
    </section>
</template>

<script>
import axios from 'axios';

/**
 * Aprobaciones internas de la cotización: Dirección, Desarrollo de negocios y Contabilidad.
 *
 *   <aprobaciones-internas :datos="aprobaciones" :cotizacion-id="id" :aprobada-cliente="header.aprobada"
 *       :base-url="baseUrl" @actualizado="(d) => aprobaciones = d" @aviso="aviso" />
 */
export default {
    name: 'AprobacionesInternas',

    props: {
        datos: { type: Object, default: function () { return { estado: 'sin_iniciar', areas: [] }; } },
        cotizacionId: { type: Number, default: null },
        aprobadaCliente: { type: Boolean, default: false },
        baseUrl: { type: String, default: '' },
    },

    data() {
        return { abierta: null, accion: 'aprobar', comentario: '', enviando: false };
    },

    computed: {
        areas() { return this.datos && this.datos.areas ? this.datos.areas : []; },
        estadoGeneral() { return this.aprobadaCliente ? (this.datos.estado === 'sin_iniciar' ? 'pendiente' : this.datos.estado) : 'sin_iniciar'; },
        textoEstado() {
            return {
                sin_iniciar: 'Esperando al cliente',
                pendiente: 'Pendiente (' + this.areas.filter(function (a) { return a.estado === 'aprobada'; }).length + ' de ' + this.areas.length + ')',
                autorizada: 'Autorizada',
                rechazada: 'Rechazada',
            }[this.estadoGeneral];
        },
    },

    methods: {
        etiqueta(estado) {
            return { pendiente: 'Pendiente', aprobada: 'Aprobada', rechazada: 'Rechazada' }[estado] || estado;
        },
        abrir(a, accion) {
            this.abierta = a.area;
            this.accion = accion;
            this.comentario = '';
        },
        cancelar() {
            this.abierta = null;
            this.comentario = '';
        },
        enviar(a) {
            if (!this.cotizacionId) {
                this.$emit('aviso', 'Guarda la cotización antes de registrar aprobaciones.');
                return;
            }
            this.enviando = true;
            axios.post(this.baseUrl + '/cotizaciones/' + this.cotizacionId + '/aprobaciones/' + a.area, {
                accion: this.accion,
                comentario: this.comentario,
            })
                .then((r) => {
                    this.$emit('actualizado', r.data.aprobaciones);
                    this.$emit('aviso', r.data.mensaje);
                    this.cancelar();
                })
                .catch((e) => {
                    const r = e && e.response;
                    let msg = 'No se pudo registrar la aprobación.';
                    if (r && r.data && r.data.errors) msg = Object.values(r.data.errors)[0][0];
                    else if (r && r.data && r.data.message) msg = r.data.message;
                    this.$emit('aviso', msg);
                })
                .then(() => { this.enviando = false; });
        },
    },
};
</script>

<style scoped>
/* ===================================================================== *
 |  AprobacionesInternas: Dirección, Desarrollo de negocios, Contabilidad
 * ===================================================================== */
.ai, .ai *, .ai *::before, .ai *::after { box-sizing: border-box; }
.ai button, .ai textarea { font-family: inherit; }

.ai { margin-bottom: 28px; padding: 18px 20px; border: 1px solid #2A3A4D; border-radius: 12px; background: #f3f4f6; }
.ai-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.ai-head h2 { margin: 0; font-size: 13px !important; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #2A3A4D; }

.ai-estado { padding: 5px 12px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; }
.ai-estado--sin_iniciar { background: #e5e7eb; color: #6b7280; }
.ai-estado--pendiente { background: #fef9c3; color: #a16207; }
.ai-estado--autorizada { background: #dcfce7; color: #166534; }
.ai-estado--rechazada { background: #fee2e2; color: #991b1b; }

.ai-nota { margin: 0; font-size: 13px; color: #6b7280; }
.ai-nota--chica { margin-top: 8px; font-size: 12px; }

.ai-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.ai-card { display: flex; flex-direction: column; padding: 14px; border: 1px solid #e5e7eb; border-left-width: 4px; border-radius: 8px; background: #fff; }
.ai-card--pendiente { border-left-color: #d1d5db; }
.ai-card--aprobada { border-left-color: #38a169; }
.ai-card--rechazada { border-left-color: #e74c3c; }
.ai-card-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.ai-card-top b { font-size: 13px; color: #1f2937; }

.ai-chip { padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
.ai-chip--pendiente { background: #f3f4f6; color: #6b7280; }
.ai-chip--aprobada { background: #dcfce7; color: #166534; }
.ai-chip--rechazada { background: #fee2e2; color: #991b1b; }

.ai-quien { margin: 8px 0 0; font-size: 12px; color: #4b5563; }
.ai-comentario { margin: 6px 0 0; font-size: 12px; font-style: italic; color: #4b5563; }

.ai-texto { width: 100%; margin-top: 10px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical; font-size: 13px; }
.ai-texto:focus { outline: none; border-color: #9ca3af; }

.ai-botones { display: flex; justify-content: flex-end; gap: 8px; margin-top: auto; padding-top: 12px; }
.ai-btn { height: 36px; padding: 0 14px; border: 0; border-radius: 6px; font-size: 12.5px; font-weight: 700; cursor: pointer; box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
.ai-btn:disabled { opacity: .55; cursor: not-allowed; }
.ai-btn--gris { background: #edf2f7; color: #4a5568; }
.ai-btn--gris:hover { background: #e2e8f0; }
.ai-btn--verde { background: #38a169; color: #fff; }
.ai-btn--verde:hover { background: #2f855a; }
.ai-btn--rojo { background: #e74c3c; color: #fff; }
.ai-btn--rojo:hover { background: #c0392b; }

@media (max-width: 900px) { .ai-grid { grid-template-columns: 1fr; } }
</style>
