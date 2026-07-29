<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <!-- 1. Quitamos la clase 'overflow-hidden' de este div -->
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md flex flex-col">

      <!-- Header Modal (2. Agregamos 'rounded-t-lg' para mantener las esquinas redondas arriba) -->
      <div class="bg-blue-600 rounded-t-lg px-4 py-3 flex justify-between items-center">
        <h3 class="text-white text-xs font-bold uppercase tracking-wider">REGISTRAR SALDO A FAVOR</h3>
        <button @click="$emit('close')" class="text-gray-400 hover:text-white transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Body Modal -->
      <div class="p-6 grid grid-cols-2 gap-4">
        <!-- CLIENTE -->
        <div class="col-span-2">
          <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">RAZÓN SOCIAL CLIENTE</label>
          <multiselect v-model="form.cliente" :options="opcionesCliente" track-by="id" label="nombre"
            placeholder="Seleccione..." :show-labels="false" class="text-sm"></multiselect>
        </div>

        <!-- MONTO DE ABONO -->
        <div class="col-span-1">
          <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">MONTO DE ABONO ($)</label>
          <input v-model="form.monto" type="number" step="0.01" placeholder="0.00"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm text-gray-700 focus:outline-none focus:border-slate-800">
        </div>

        <!-- SUCURSAL -->
        <div class="col-span-1">
          <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">SUCURSAL ORIGEN</label>
          <multiselect v-model="form.sucursal" :options="opcionesSucursal" placeholder="Seleccione..."
            :show-labels="false" class="text-sm"></multiselect>
        </div>

        <!-- FECHA DE DETECCIÓN -->
        <div class="col-span-2">
          <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">FECHA DE DETECCIÓN</label>
          <!-- 3. Agregamos position="bottom" al componente -->
          <VueCtkDateTimePicker v-model="form.fecha_deteccion" format="YYYY-MM-DD" formatted="YYYY-MM-DD"
            color="#1d4ed8" button-color="#1d4ed8" :only-date="true" label="Seleccione la fecha" class="text-sm"
            position="bottom">
          </VueCtkDateTimePicker>
        </div>

        <!-- CONCEPTO O CAUSA -->
        <div class="col-span-2">
          <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">CONCEPTO O CAUSA</label>
          <textarea v-model="form.concepto" rows="3" placeholder="Ej. Pago duplicado..."
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm text-gray-700 focus:outline-none focus:border-slate-800 resize-none"></textarea>
        </div>
      </div>

      <!-- Footer Modal (4. Agregamos 'rounded-b-lg' para mantener las esquinas redondas abajo) -->
      <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3 bg-gray-50 rounded-b-lg">
        <button @click="$emit('close')"
          class="px-5 py-2 rounded bg-gray-100 text-gray-700 text-sm font-bold hover:bg-gray-200 transition-colors">Salir</button>
        <button @click="guardarSaldo" :disabled="isSubmitting"
          :class="['px-5 py-2 rounded text-white text-sm font-bold transition-colors', isSubmitting ? 'bg-gray-400 cursor-not-allowed' : 'bg-blue-700 hover:bg-blue-800']">
          {{ isSubmitting ? 'Guardando...' : 'Registrar' }}
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
  name: 'ModalNuevoSaldoFavor',
  components: { Multiselect, VueCtkDateTimePicker },
  props: {
    opcionesCliente: Array,
    opcionesSucursal: Array
  },
  data() {
    return {
      isSubmitting: false,
      form: { cliente: null, monto: null, sucursal: null, fecha_deteccion: null, concepto: '' }
    }
  },
  methods: {
    async guardarSaldo() {
      if (!this.form.cliente || !this.form.monto || !this.form.sucursal || !this.form.fecha_deteccion) {
        Swal.fire('Atención', 'Por favor completa cliente, sucursal, fecha y monto', 'warning');
        return;
      }
      this.isSubmitting = true;
      const payload = { ...this.form };
      payload.cliente_id = payload.cliente.id;
      delete payload.cliente;

      try {
        const response = await axios.post('/saldos-favor', payload);
        if (response.data.success) {
          Swal.fire({ title: '¡Éxito!', text: 'Saldo registrado', icon: 'success', toast: true, position: 'top-end', timer: 3000, showConfirmButton: false });
          this.$emit('saldo-guardado');
        }
      } catch (error) {
        Swal.fire('Error', 'No se pudo guardar el registro', 'error');
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
  background-color: #1e293b !important;
}
</style>