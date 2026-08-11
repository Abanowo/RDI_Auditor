<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl overflow-hidden flex flex-col">

            <!-- Header Modal -->
            <div class="bg-blue-600 px-4 py-3 flex justify-between items-center">
                <h3 class="text-white text-xs font-bold uppercase tracking-wider">EDITAR SALDO A FAVOR</h3>
                <button @click="$emit('close')" class="text-blue-200 hover:text-white transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Body Modal -->
            <div class="p-6 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">RAZÓN SOCIAL CLIENTE</label>
                    <multiselect v-model="form.cliente" :options="opcionesCliente" track-by="id" label="nombre"
                        :show-labels="false" class="text-sm"></multiselect>
                </div>
                <div class="col-span-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">MONTO DE ABONO ($)</label>
                    <input v-model="form.monto" type="number" step="0.01"
                        class="w-full border border-gray-300 rounded px-3 py-2 text-sm text-gray-700 focus:outline-none focus:border-blue-600">
                </div>
                <div class="col-span-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">SUCURSAL ORIGEN</label>
                    <multiselect v-model="form.sucursal" :options="opcionesSucursal" :show-labels="false"
                        class="text-sm"></multiselect>
                </div>
                <div class="col-span-2">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">FECHA DE DETECCIÓN</label>
                    <VueCtkDateTimePicker v-model="form.fecha_deteccion" format="YYYY-MM-DD" formatted="YYYY-MM-DD"
                        color="#1d4ed8" button-color="#1d4ed8" :only-date="true" :no-label="true" class="text-sm">
                    </VueCtkDateTimePicker>
                </div>
                <div class="col-span-2">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">CONCEPTO O CAUSA</label>
                    <textarea v-model="form.concepto" rows="3"
                        class="w-full border border-gray-300 rounded px-3 py-2 text-sm text-gray-700 focus:outline-none focus:border-blue-600 resize-none"></textarea>
                </div>
            </div>

            <!-- Footer Modal -->
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3 bg-gray-50">
                <button @click="$emit('close')"
                    class="px-5 py-2 rounded bg-gray-100 text-gray-700 text-sm font-bold hover:bg-gray-200 transition-colors">Salir</button>
                <button @click="actualizarSaldo" :disabled="isSubmitting"
                    :class="['px-5 py-2 rounded text-white text-sm font-bold transition-colors', isSubmitting ? 'bg-gray-400 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700']">
                    {{ isSubmitting ? 'Actualizando...' : 'Guardar Cambios' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';
import 'vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';
import Swal from 'sweetalert2';

export default {
    name: 'ModalEditarSaldoFavor',
    components: { Multiselect, VueCtkDateTimePicker },
    props: {
        opcionesCliente: Array,
        opcionesSucursal: Array,
        saldoBase: Object // Recibe la info a editar
    },
    data() {
        return {
            isSubmitting: false,
            form: { id: null, cliente: null, monto: null, sucursal: null, fecha_deteccion: null, concepto: '' }
        }
    },
    mounted() {
        // Al abrir el modal, poblamos el formulario con los datos que nos mandó la tabla
        if (this.saldoBase) {
            const clienteEncontrado = this.opcionesCliente.find(c => c.nombre === this.saldoBase.cliente) || null;
            this.form = {
                id: this.saldoBase.id,
                cliente: clienteEncontrado,
                monto: this.saldoBase.monto,
                sucursal: this.saldoBase.sucursal_origen,
                fecha_deteccion: this.saldoBase.fecha_deteccion,
                concepto: this.saldoBase.concepto
            };
        }
    },
    methods: {
        async actualizarSaldo() {
            if (!this.form.cliente || !this.form.monto || !this.form.sucursal || !this.form.fecha_deteccion) {
                Swal.fire('Atención', 'Por favor completa todos los campos requeridos', 'warning');
                return;
            }
            this.isSubmitting = true;
            const payload = { ...this.form };
            payload.cliente_id = payload.cliente.id;
            delete payload.cliente;

            try {
                const response = await axios.put(`/saldos-favor/${this.form.id}`, payload);
                if (response.data.success) {
                    Swal.fire({ title: '¡Éxito!', text: 'Saldo actualizado correctamente', icon: 'success', toast: true, position: 'top-end', timer: 3000, showConfirmButton: false });
                    this.$emit('saldo-actualizado');
                }
            } catch (error) {
                Swal.fire('Error', 'Ocurrió un problema al actualizar', 'error');
            } finally {
                this.isSubmitting = false;
            }
        }
    }
}
</script>

<style scoped>
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

:deep(.multiselect__tags) {
    border-color: #D1D5DB !important;
    padding-top: 6px !important;
    min-height: 38px !important;
}

:deep(.multiselect__tag) {
    background-color: #2563eb !important;
}
</style>