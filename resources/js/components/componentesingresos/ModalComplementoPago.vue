<template>
  <div v-if="mostrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-6 md:p-10">

    <div
      class="bg-white rounded-xl shadow-2xl w-full max-w-5xl overflow-hidden flex flex-col max-h-[calc(100vh-4rem)] mx-4">

      <!-- Header -->
      <div class="bg-indigo-700 px-8 py-6 flex justify-between items-center shrink-0">
        <h3 class="text-white font-bold text-2xl tracking-wide">Generar Complemento de Pago (Contpaqi)</h3>
        <button @click="cerrar" class="text-white hover:text-gray-200 transition-colors">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Body -->
      <div class="p-8 overflow-y-auto flex-1 grid grid-cols-1 md:grid-cols-3 gap-8">

        <!-- ========================================== -->
        <!-- DATOS GENERALES (AQUÍ AGREGAMOS BANCO Y FECHA) -->
        <!-- ========================================== -->
        <div class="col-span-1 md:col-span-2">
          <label class="block text-lg font-bold text-gray-700 mb-2 uppercase">Cliente</label>
          <input type="text" :value="ingreso.cliente" disabled
            class="w-full border-gray-300 bg-gray-100 rounded-lg shadow-sm px-5 py-4 text-xl text-gray-700 font-semibold cursor-not-allowed">
        </div>

        <div class="col-span-1">
          <label class="block text-lg font-bold text-gray-700 mb-2 uppercase">Sucursal Origen</label>
          <input type="text" :value="ingreso.sucursal_origen" disabled
            class="w-full border-gray-300 bg-gray-100 rounded-lg shadow-sm px-5 py-4 text-xl text-gray-700 font-semibold cursor-not-allowed">
        </div>

        <div class="col-span-1 md:col-span-2">
          <label class="block text-lg font-bold text-gray-700 mb-2 uppercase">Banco</label>
          <input type="text" :value="ingreso.banco_receptor || 'N/A'" disabled
            class="w-full border-gray-300 bg-gray-100 rounded-lg shadow-sm px-5 py-4 text-xl text-gray-700 font-semibold cursor-not-allowed">
        </div>

        <div class="col-span-1">
          <label class="block text-lg font-bold text-gray-700 mb-2 uppercase">Fecha</label>
          <input type="text" :value="ingreso.fecha || 'N/A'" disabled
            class="w-full border-gray-300 bg-gray-100 rounded-lg shadow-sm px-5 py-4 text-xl text-gray-700 font-semibold cursor-not-allowed">
        </div>

        <!-- SECCIÓN DE MONTOS SEPARADOS -->
        <div
          class="col-span-1 md:col-span-3 border-2 border-indigo-200 bg-indigo-100 rounded-xl p-6 grid grid-cols-1 md:grid-cols-3 gap-6">

          <div>
            <label class="block text-sm font-bold text-indigo-800 mb-2 uppercase tracking-wide">Monto CFDI
              (Honorarios)</label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-xl font-bold text-gray-500">$</span>
              <input type="number" step="0.01" v-model="form.monto_cfdi"
                class="w-full border-indigo-200 rounded-lg shadow-sm pl-10 pr-5 py-4 text-2xl font-black text-indigo-700 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 bg-white">
            </div>
          </div>

          <div>
            <label class="block text-sm font-bold text-pink-700 mb-2 uppercase tracking-wide">Monto GPC (Nota
              Cargo)</label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-xl font-bold text-gray-500">$</span>
              <input type="number" step="0.01" v-model="form.monto_gpc"
                class="w-full border-pink-200 rounded-lg shadow-sm pl-10 pr-5 py-4 text-2xl font-black text-pink-700 outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-500 bg-white">
            </div>
          </div>

          <div>
            <label class="block text-sm font-bold text-gray-600 mb-2 uppercase tracking-wide">Total Depositado</label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-xl font-bold text-gray-500">$</span>
              <input type="number" :value="sumaTotal" disabled
                class="w-full border-gray-300 rounded-lg shadow-sm pl-10 pr-5 py-4 text-2xl font-black text-gray-800 bg-gray-200 cursor-not-allowed">
            </div>
          </div>

        </div>

        <!-- Configuraciones del Complemento -->
        <div class="col-span-1">
          <label class="block text-lg font-bold text-gray-700 mb-2">Método de Pago</label>
          <multiselect v-model="form.metodoPagoObj" :options="opcionesMetodoPago" label="label" track-by="value"
            :searchable="false" :show-labels="false" :allow-empty="false" placeholder="Seleccione..."
            class="custom-multiselect text-xl">
          </multiselect>
        </div>

        <div class="col-span-1">
          <label class="block text-lg font-bold text-gray-700 mb-2">Moneda</label>
          <multiselect v-model="form.monedaObj" :options="opcionesMoneda" label="label" track-by="value"
            :searchable="false" :show-labels="false" :allow-empty="false" class="custom-multiselect text-xl">
          </multiselect>
        </div>

        <div class="col-span-1">
          <label class="block text-lg font-bold text-gray-700 mb-2">Tipo de Cambio</label>
          <input type="number" step="0.01" v-model="form.tipo_cambio"
            class="w-full border-gray-300 rounded-lg shadow-sm px-5 py-4 text-xl outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <div class="col-span-1 md:col-span-3">
          <label class="block text-lg font-bold text-gray-700 mb-2">Forma de Pago</label>
          <multiselect v-model="form.formaPagoObj" :options="formasPago" label="label" track-by="value"
            :searchable="true" :show-labels="false" :allow-empty="false" placeholder="Seleccione..."
            class="custom-multiselect text-xl"></multiselect>
        </div>

        <div class="col-span-1 md:col-span-3">
          <label class="block text-lg font-bold text-gray-700 mb-2">Referencia / Folio SC</label>
          <input type="text" v-model="form.referencia"
            class="w-full border-gray-300 rounded-lg shadow-sm px-5 py-4 text-xl outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        </div>

        <div class="col-span-1 md:col-span-3">
          <label class="block text-lg font-bold text-gray-700 mb-2">Observaciones</label>
          <textarea v-model="form.observaciones" rows="3"
            class="w-full border-gray-300 rounded-lg shadow-sm px-5 py-4 text-xl outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none"></textarea>
        </div>
      </div>

      <!-- Footer -->
      <div class="bg-gray-100 px-8 py-6 flex justify-end gap-4 rounded-b-xl border-t shrink-0">
        <button @click="cerrar"
          class="px-6 py-4 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 font-bold transition-colors text-xl shadow-sm">
          Cancelar
        </button>

        <button @click="enviarComplemento" :disabled="!!ingreso.folio_complemento"
          :class="['px-6 py-4 rounded-lg font-bold transition-colors flex items-center text-xl shadow-sm',
            !!ingreso.folio_complemento ? 'bg-gray-400 text-gray-200 cursor-not-allowed' : 'bg-indigo-600 text-white hover:bg-indigo-700']">
          Generar Complemento
        </button>

        <button @click="timbrarComplemento" :disabled="!ingreso.folio_complemento"
          :class="['px-6 py-4 rounded-lg font-bold transition-colors flex items-center text-xl shadow-sm',
            !ingreso.folio_complemento ? 'bg-gray-400 text-gray-200 cursor-not-allowed' : 'bg-green-600 text-white hover:bg-green-700']">
          Timbrar
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
    mostrar: {
      type: Boolean,
      default: false
    },
    ingreso: {
      type: Object,
      default: () => ({})
    }
  },
  data() {
    return {
      form: {
        monto_cfdi: 0,
        monto_gpc: 0,
        metodoPagoObj: { value: 'PPD', label: 'PPD - Pago en parcialidades o diferido' },
        monedaObj: { value: 'MXN', label: 'MXN - Peso Mexicano' },
        tipo_cambio: 1,
        formaPagoObj: { value: '03', label: '03 - Transferencia electrónica de fondos' },
        referencia: '',
        observaciones: ''
      },
      opcionesMetodoPago: [
        { value: 'PPD', label: 'PPD - Pago en parcialidades o diferido' },
        { value: 'PUE', label: 'PUE - Pago en una sola exhibición' }
      ],
      opcionesMoneda: [
        { value: 'MXN', label: 'MXN - Peso Mexicano' },
        { value: 'USD', label: 'USD - Dólar Estadounidense' }
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
  computed: {
    sumaTotal() {
      return (Number(this.form.monto_cfdi) + Number(this.form.monto_gpc)).toFixed(2);
    }
  },
  watch: {
    ingreso: {
      immediate: true,
      handler(val) {
        if (val && Object.keys(val).length > 0) {
          this.calcularDivision(val);
        }
      }
    }
  },
  methods: {
    calcularDivision(item) {
      const sucursal = String(item.sucursal_origen || '').toUpperCase();
      const nombreCliente = String(item.cliente || '').toUpperCase();

      const isTransportactics = nombreCliente.includes('TRANSPORTACTICS') || sucursal.includes('TRANSPORTACTIC');
      const esManzanilloRow = sucursal.includes('MANZANILLO') || sucursal.includes('INTSHIPPERT');

      let cfdi = 0;
      let gpc = 0;

      if (isTransportactics) {
        cfdi = Number(item.flete) || 0;
        gpc = 0;
      } else if (esManzanilloRow) {
        cfdi = Number(item.honorarios) || 0;
        gpc = (Number(item.anticipo) || 0) + (Number(item.garantias) || 0) +
          (Number(item.desglose_naviera) || 0) + (Number(item.impuestos) || 0) +
          (Number(item.flete) || 0);
      } else {
        cfdi = Number(item.honorarios) || 0;
        gpc = (Number(item.impuestos) || 0) + (Number(item.eci) || 0) +
          (Number(item.maniobras) || 0) + (Number(item.flete) || 0) +
          (Number(item.muestras) || 0) + (Number(item.llc) || 0);
      }

      this.form.monto_cfdi = cfdi.toFixed(2);
      this.form.monto_gpc = gpc.toFixed(2);
      this.form.referencia = item.folio_sc || item.folio_complemento || '';
    },
    cerrar() {
      this.$emit('cerrar');
    },
    enviarComplemento() {
      const payloadLimpio = {
        ingreso_id: this.ingreso.id,
        cliente_id: this.ingreso.cliente_id,
        sucursal: this.ingreso.sucursal_origen,
        moneda: this.form.monedaObj && this.form.monedaObj.value === 'USD' ? 2 : 1,
        tipo_cambio: this.form.tipo_cambio,
        referencia: this.form.referencia,
        observaciones: this.form.observaciones,
        total: this.sumaTotal,
        forma_pago: this.form.formaPagoObj ? this.form.formaPagoObj.value : '',
        metodo_pago: this.form.metodoPagoObj ? this.form.metodoPagoObj.value : 'PPD',
      };

      this.$emit('generar', payloadLimpio);
    },

    async timbrarComplemento() {
      const payloadTimbre = {
        ingreso_id: this.ingreso.id,
        serie: this.ingreso.serie_complemento || 'CP',
        folio: this.ingreso.folio_complemento
      };

      try {
        Swal.fire({
          title: 'Timbrando...',
          text: 'Conectando con el SAT a través de Contpaqi...',
          allowOutsideClick: false,
          didOpen: () => { Swal.showLoading(); }
        });

        const response = await axios.post('/ingresos-conciliados/timbrar-complemento', payloadTimbre);

        if (response.data.success) {
          Swal.fire('¡Éxito!', response.data.message, 'success');

          this.$emit('ingreso-actualizado');
          this.$emit('cerrar');
        }
      } catch (error) {
        console.error("Detalle del error:", error);

        let msjError = 'Error desconocido al timbrar';
        if (error.response && error.response.data && error.response.data.error) {
          msjError = error.response.data.error;
        } else if (error.message) {
          msjError = error.message;
        }

        Swal.fire('Error al Timbrar', msjError, 'error');
      }
    }
  }
};
</script>

<style scoped>
:deep(.custom-multiselect .multiselect__tags) {
  border-color: #D1D5DB;
  border-radius: 8px;
  padding-top: 12px !important;
  padding-left: 16px !important;
  min-height: 56px !important;
  font-size: 16px !important;
}

:deep(.custom-multiselect .multiselect__select) {
  height: 56px !important;
}

:deep(.custom-multiselect.multiselect--active .multiselect__tags) {
  border-color: #4F46E5;
  box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.2);
}

:deep(.custom-multiselect .multiselect__single),
:deep(.custom-multiselect .multiselect__input) {
  font-size: 16px !important;
  margin-bottom: 0px !important;
  padding-top: 4px !important;
}

:deep(.custom-multiselect .multiselect__option) {
  font-size: 16px !important;
  padding: 12px 16px !important;
}

input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
</style>