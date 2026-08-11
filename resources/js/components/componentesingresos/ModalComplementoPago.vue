<template>
  <div v-if="mostrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl overflow-hidden">
      
      <!-- HEADER -->
      <div class="bg-indigo-700 px-6 py-4 flex justify-between items-center">
        <h3 class="text-white font-bold text-lg">Generar Complemento de Pago (Contpaqi)</h3>
        <button @click="cerrar" class="text-white hover:text-gray-200">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
      </div>

      <!-- BODY -->
      <div class="p-6 grid grid-cols-2 gap-4">
        
        <!-- Cliente (Autollenado) -->
        <div class="col-span-2">
          <label class="block text-sm font-bold text-gray-700">Cliente</label>
          <input type="text" :value="ingreso.cliente" disabled class="w-full mt-1 border-gray-300 bg-gray-100 rounded-md shadow-sm p-2">
        </div>

        <!-- Sucursal (Autollenado) -->
        <div class="col-span-1">
          <label class="block text-sm font-bold text-gray-700">Sucursal</label>
          <!-- 🔥 CORRECCIÓN: Se cambió a sucursal_origen -->
          <input type="text" :value="ingreso.sucursal_origen" disabled class="w-full mt-1 border-gray-300 bg-gray-100 rounded-md shadow-sm p-2">
        </div>

        <div class="col-span-1">
          <label class="block text-sm font-bold text-gray-700">Total a Pagar</label>
          <input type="number" v-model="form.total" class="w-full mt-1 border-gray-300 rounded-md shadow-sm p-2 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <!-- 🔥 NUEVO: Moneda con Vue Multiselect -->
        <div class="col-span-1">
          <label class="block text-sm font-bold text-gray-700">Moneda</label>
          <multiselect 
            v-model="form.monedaObj" 
            :options="opcionesMoneda" 
            label="label" 
            track-by="value"
            :searchable="false" 
            :show-labels="false" 
            :allow-empty="false"
            class="mt-1 custom-multiselect">
          </multiselect>
        </div>

        <div class="col-span-1">
          <label class="block text-sm font-bold text-gray-700">Tipo de Cambio</label>
          <input type="number" step="0.01" v-model="form.tipo_cambio" class="w-full mt-1 border-gray-300 rounded-md shadow-sm p-2 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <!-- 🔥 NUEVO: Forma de Pago con Vue Multiselect -->
        <div class="col-span-1">
          <label class="block text-sm font-bold text-gray-700">Forma de Pago</label>
          <multiselect 
            v-model="form.formaPagoObj" 
            :options="formasPago" 
            label="label" 
            track-by="value"
            :searchable="true" 
            :show-labels="false" 
            :allow-empty="false"
            placeholder="Seleccione..."
            class="mt-1 custom-multiselect">
          </multiselect>
        </div>

        <!-- 🔥 NUEVO: Método de Pago con Vue Multiselect -->
        <div class="col-span-1">
          <label class="block text-sm font-bold text-gray-700">Método de Pago</label>
          <multiselect 
            v-model="form.metodoPagoObj" 
            :options="opcionesMetodoPago" 
            label="label" 
            track-by="value"
            :searchable="false" 
            :show-labels="false" 
            :allow-empty="false"
            class="mt-1 custom-multiselect">
          </multiselect>
        </div>

        <div class="col-span-2">
          <label class="block text-sm font-bold text-gray-700">Referencia</label>
          <input type="text" v-model="form.referencia" class="w-full mt-1 border-gray-300 rounded-md shadow-sm p-2 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <div class="col-span-2">
          <label class="block text-sm font-bold text-gray-700">Observaciones</label>
          <textarea v-model="form.observaciones" rows="2" class="w-full mt-1 border-gray-300 rounded-md shadow-sm p-2 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"></textarea>
        </div>

      </div>

      <!-- FOOTER -->
      <div class="bg-gray-50 px-6 py-4 flex justify-end gap-3 rounded-b-lg border-t">
        <button @click="cerrar" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 font-bold transition">Cancelar</button>
        <button @click="enviarComplemento" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 font-bold transition flex items-center">
          Generar Complemento
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

export default {
  name: 'ModalComplementoPago',
  components: {
    Multiselect
  },
  props: {
    mostrar: Boolean,
    ingreso: Object // Recibe el registro de la tabla principal
  },
  data() {
    return {
      form: {
        tipo_cambio: 1.00,
        referencia: "",
        observaciones: "",
        total: 0,
        // Variables para los Vue Multiselect (guardan el objeto completo de la opción)
        monedaObj: { value: 1, label: "Peso Mexicano (MXN)" },
        formaPagoObj: { value: "03", label: "03 - Transferencia Electrónica" },
        metodoPagoObj: { value: "PPD", label: "Pago en parcialidades o diferido (PPD)" }
      },
      opcionesMoneda: [
        { value: 1, label: "Peso Mexicano (MXN)" },
        { value: 2, label: "Dólar (USD)" }
      ],
      opcionesMetodoPago: [
        { value: "PUE", label: "Pago en una sola exhibición (PUE)" },
        { value: "PPD", label: "Pago en parcialidades o diferido (PPD)" }
      ],
      formasPago: [
        { value: "01", label: "01 - Efectivo" },
        { value: "02", label: "02 - Cheque Nominativo" },
        { value: "03", label: "03 - Transferencia Electrónica" },
        { value: "04", label: "04 - Tarjeta de crédito" },
        { value: "05", label: "05 - Monedero electrónico" },
        { value: "06", label: "06 - Dinero electrónico" },
        { value: "08", label: "08 - Vales de despensa" },
        { value: "12", label: "12 - Dación en pago" },
        { value: "13", label: "13 - Pago por subrogación" },
        { value: "14", label: "14 - Pago por consignación" },
        { value: "15", label: "15 - Condonación" },
        { value: "17", label: "17 - Compensación" },
        { value: "23", label: "23 - Novación" },
        { value: "24", label: "24 - Confusión" },
        { value: "25", label: "25 - Remisión de deuda" },
        { value: "26", label: "26 - Prescripción o caducidad" },
        { value: "27", label: "27 - A satisfacción del acreedor" },
        { value: "28", label: "28 - Tarjeta de débito" },
        { value: "29", label: "29 - Tarjeta de servicios" },
        { value: "30", label: "30 - Aplicación de anticipos" },
        { value: "31", label: "31 - Intermediario pagos" }
      ]
    };
  },
  watch: {
    // Cuando el modal se abre, precargamos los datos del ingreso seleccionado
    mostrar(newVal) {
      if (newVal && this.ingreso) {
        this.form.total = this.ingreso.monto_deposito || 0;
        this.form.referencia = this.ingreso.referencia || "";
        this.form.observaciones = `Complemento generado el ${new Date().toLocaleDateString()}`;
        
        // Reiniciar selects a sus valores por defecto al abrir
        this.form.monedaObj = { value: 1, label: "Peso Mexicano (MXN)" };
        this.form.formaPagoObj = { value: "03", label: "03 - Transferencia Electrónica" };
        this.form.metodoPagoObj = { value: "PPD", label: "Pago en parcialidades o diferido (PPD)" };
      }
    }
  },
  methods: {
    cerrar() {
      this.$emit('cerrar');
    },
    async enviarComplemento() {
      if (!this.form.referencia || !this.form.total) {
        Swal.fire('Atención', 'El total y la referencia son obligatorios.', 'warning');
        return;
      }

      // Preparamos el payload extrayendo los ".value" de los objetos del Multiselect
      const payload = {
        ingreso_id: this.ingreso.id,
        cliente_id: this.ingreso.cliente_id,
        // Usamos sucursal_origen que es como viene de la DB
        sucursal: this.ingreso.sucursal_origen, 
        moneda: this.form.monedaObj.value,
        tipo_cambio: this.form.tipo_cambio,
        referencia: this.form.referencia,
        observaciones: this.form.observaciones,
        total: this.form.total,
        forma_pago: this.form.formaPagoObj.value,
        metodo_pago: this.form.metodoPagoObj.value
      };

      try {
        Swal.fire({ title: 'Procesando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        // Petición a nuestro propio backend (Laravel)
        const response = await axios.post('/complementos-pago/generar', payload);

        console.log("Respuesta de Contpaqi:", response.data);
        
        Swal.fire('¡Éxito!', 'El complemento de pago fue enviado a Contpaqi correctamente.', 'success');
        this.cerrar();

      } catch (error) {
        console.error(error);
        const msj = error.response?.data?.error || 'Error al conectar con el servidor.';
        Swal.fire('Error', msj, 'error');
      }
    }
  }
}
</script>

<style scoped>
/* Estilos adicionales para que el Multiselect haga match con los inputs de Tailwind */
:deep(.custom-multiselect .multiselect__tags) {
  border-color: #D1D5DB;
  border-radius: 0.375rem;
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
  min-height: 42px;
}
:deep(.custom-multiselect .multiselect__select) {
  height: 42px;
}
:deep(.custom-multiselect.multiselect--active .multiselect__tags) {
  border-color: #6366F1; /* Color índigo al hacer focus */
  box-shadow: 0 0 0 1px #6366F1;
}
</style>