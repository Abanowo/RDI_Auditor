<template>
    <div v-if="mostrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 p-4 md:p-8">
        <!-- 🔥 CAMBIO: max-w-7xl para mayor anchura y style="height: 90vh;" para mayor altura -->
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-7xl flex flex-col overflow-visible"
            style="height: 90vh;">

            <!-- Encabezado -->
            <div class="bg-[#2A3A4D] px-8 py-5 flex justify-between items-center rounded-t-xl shrink-0">
                <h3 class="text-white font-bold text-xl tracking-wide">✉️ Configurar y Enviar Correo</h3>
                <button @click="cerrar"
                    class="text-gray-400 hover:text-white transition-colors bg-gray-700 hover:bg-gray-600 rounded-full p-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Cuerpo dividido en 2 columnas -->
            <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">

                <!-- COLUMNA IZQUIERDA: Formulario y Destinatarios (Ajustada a 4/12) -->
                <div class="w-full lg:w-4/12 p-8 border-r border-gray-200 flex flex-col overflow-visible">
                    <h4 class="font-extrabold text-gray-800 text-xl mb-3">Destinatarios</h4>
                    <p class="text-base text-gray-600 mb-6">Ingresa los correos y presiona <strong>Enter</strong> para
                        agregarlos a la lista de envío.</p>

                    <div class="flex-1 overflow-visible">
                        <multiselect v-model="listaCorreos" :options="opcionesSugeridas" :multiple="true"
                            :taggable="true" @tag="agregarCorreo" placeholder="Escribe un correo y presiona Enter..."
                            tag-placeholder="Presiona Enter para agregar" select-label="" selected-label="Seleccionado"
                            deselect-label="Remover" class="w-full shadow-sm text-lg">
                            <template slot="noResult">No se encontraron correos.</template>
                            <template slot="noOptions">Escribe un correo para agregarlo.</template>
                        </multiselect>
                    </div>
                </div>

                <!-- COLUMNA DERECHA: Vista Previa del Correo (Ajustada a 8/12 y más espaciosa) -->
                <div class="w-full lg:w-8/12 p-8 bg-gray-50 flex flex-col overflow-y-auto custom-scrollbar">
                    <h4 class="font-extrabold text-gray-800 text-xl mb-6 flex items-center gap-3">
                        <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                            </path>
                        </svg>
                        Vista Previa del Mensaje
                    </h4>

                    <!-- Contenedor del Correo Simulado -->
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col flex-1">
                        <!-- Asunto -->
                        <div class="border-b border-gray-100 p-6 bg-gray-50 rounded-t-xl">
                            <div class="text-sm text-gray-500 font-bold uppercase tracking-wider mb-2">Asunto del correo
                            </div>
                            <div class="text-gray-800 font-black text-lg">
                                Complemento de Pago - {{ previewCliente }}
                            </div>
                        </div>

                        <!-- Cuerpo del mensaje -->
                        <div class="p-8 text-gray-700 text-base leading-relaxed flex-1">
                            <p class="mb-5">Estimado(a) <strong>{{ previewCliente }}</strong>,</p>

                            <p class="mb-6">
                                Por medio de la presente le enviamos adjunto a este correo su <strong>Recibo Electrónico
                                    de Pago
                                    (Complemento)</strong>
                                correspondiente al pago de la referencia <strong>{{ previewFolioSc }}</strong>.
                            </p>

                            <div class="bg-blue-50 border border-blue-100 rounded-lg p-6 mb-8 text-blue-800 text-lg">
                                <ul class="space-y-2">
                                    <li><strong>Folio Interno:</strong> {{ previewFolioComp }}</li>
                                    <li><strong>Fecha de Pago:</strong> {{ previewFecha }}</li>
                                    <li><strong>Monto Total:</strong> ${{ Number(previewMonto).toLocaleString('en-US', {
                                        minimumFractionDigits: 2 }) }}</li>
                                </ul>
                            </div>

                            <p class="mb-2">Si tiene alguna duda o aclaración, no dude en contactarnos.</p>
                            <p class="mt-8 text-gray-500 text-lg">Atentamente,<br><strong class="text-gray-700">El
                                    equipo de {{
                                    nombreEmpresaEmisora }}</strong></p>
                        </div>

                        <!-- Zona de Adjuntos (Archivos Simulados) -->
                        <div class="p-6 border-t border-gray-100 bg-gray-50 rounded-b-xl">
                            <div class="text-sm text-gray-500 font-bold uppercase tracking-wider mb-4">Archivos Adjuntos
                                (2)</div>
                            <div class="flex gap-4">
                                <div
                                    class="flex items-center gap-3 bg-white border border-red-200 px-4 py-3 rounded-lg shadow-sm">
                                    <span class="text-red-500 font-black text-2xl leading-none">PDF</span>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-gray-700 truncate w-40">Complemento_{{
                                            previewNombreArchivo
                                            }}.pdf</span>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-3 bg-white border border-green-200 px-4 py-3 rounded-lg shadow-sm">
                                    <span class="text-green-600 font-black text-2xl leading-none">XML</span>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-gray-700 truncate w-40">Complemento_{{
                                            previewNombreArchivo
                                            }}.xml</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Pie del Modal -->
            <div class="bg-gray-100 px-8 py-5 flex justify-end gap-4 rounded-b-xl shrink-0 border-t border-gray-200">
                <button @click="cerrar"
                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-8 py-3 rounded-lg font-bold text-lg transition">
                    Cancelar
                </button>
                <button @click="enviarCorreos" :disabled="enviando || listaCorreos.length === 0"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-10 py-3 rounded-lg font-black text-lg transition shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center">
                    <svg v-if="enviando" class="animate-spin -ml-1 mr-3 h-6 w-6 text-white" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <svg v-else class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8">
                        </path>
                    </svg>
                    {{ enviando ? 'Enviando Documentos...' : 'Enviar Documentos' }}
                </button>
            </div>

        </div>
    </div>
</template>

<script>
import Swal from 'sweetalert2';
import axios from 'axios';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

export default {
    name: 'ModalEnviarCorreo',
    components: {
        Multiselect
    },
    props: {
        mostrar: { type: Boolean, default: false },
        ingreso: { type: Object, default: () => ({}) }
    },
    data() {
        return {
            listaCorreos: [],
            opcionesSugeridas: [],
            enviando: false
        }
    },
    computed: {
        previewCliente() {
            return (this.ingreso && this.ingreso.cliente) ? this.ingreso.cliente : 'Estimado Cliente';
        },
        previewFolioSc() {
            return (this.ingreso && this.ingreso.folio_sc) ? this.ingreso.folio_sc : 'N/A';
        },
        previewFolioComp() {
            return (this.ingreso && this.ingreso.folio_complemento) ? this.ingreso.folio_complemento : 'Pendiente';
        },
        previewFecha() {
            return (this.ingreso && this.ingreso.fecha) ? this.ingreso.fecha : 'N/A';
        },
        previewMonto() {
            return (this.ingreso && this.ingreso.monto_deposito) ? this.ingreso.monto_deposito : 0;
        },
        previewNombreArchivo() {
            return (this.ingreso && this.ingreso.folio_complemento) ? this.ingreso.folio_complemento : 'folio';
        },
        nombreEmpresaEmisora() {
            if (!this.ingreso || !this.ingreso.sucursal_origen) return 'InTactics';

            const sucursal = this.ingreso.sucursal_origen.toUpperCase();
            if (sucursal.includes('TRANSPORTACTICS')) return 'Transportactics';
            if (sucursal.includes('INTSHIPPERTS')) return 'INTSHIPPERTS';
            return 'InTactics';
        }
    },
    watch: {
        mostrar(val) {
            if (val) {
                this.listaCorreos = [];
                this.opcionesSugeridas = [];
                this.enviando = false;
            }
        }
    },
    methods: {
        agregarCorreo(nuevoCorreo) {
            const email = nuevoCorreo.trim();
            const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!regex.test(email)) {
                Swal.fire('Correo inválido', `"${email}" no es un formato válido.`, 'warning');
                return;
            }

            if (!this.opcionesSugeridas.includes(email)) {
                this.opcionesSugeridas.push(email);
            }

            this.listaCorreos.push(email);
        },
        async enviarCorreos() {
            this.enviando = true;
            try {
                const response = await axios.post(`/ingresos-conciliados/${this.ingreso.id}/complemento/enviar-correo`, {
                    correos: this.listaCorreos
                });

                if (response.data.success) {
                    Swal.fire('¡Enviado!', response.data.message, 'success');
                    this.cerrar();
                }
            } catch (error) {
                let msj = error.response?.data?.error || 'Error al enviar los correos.';
                Swal.fire('Error', msj, 'error');
            } finally {
                this.enviando = false;
            }
        },
        cerrar() {
            this.$emit('cerrar');
        }
    }
}
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 10px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 8px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 8px;
}

/* Ajustes para hacer más grande y legible el multiselect internamente */
:deep(.multiselect__tags) {
    min-height: 50px;
    padding-top: 12px;
    border-radius: 8px;
}

:deep(.multiselect__input) {
    font-size: 16px;
}

:deep(.multiselect__tag) {
    font-size: 14px;
    padding: 6px 28px 6px 12px;
    margin-bottom: 8px;
}

:deep(.multiselect__tag-icon) {
    line-height: 28px;
}
</style>