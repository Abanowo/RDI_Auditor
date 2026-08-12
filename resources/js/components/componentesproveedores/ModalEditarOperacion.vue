<template>
  <transition name="modal">
    <div v-if="show" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-[100] flex items-center justify-center p-6 md:p-10">
      
      <!-- CONTENEDOR PRINCIPAL DEL MODAL (Aumentado a max-w-4xl) -->
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl overflow-hidden">
        
        <!-- Header -->
        <div class="flex items-center justify-between px-8 py-6 border-b border-gray-200">
          <h2 class="text-2xl font-bold text-gray-800 tracking-wider">Editar Operación</h2>
          <button @click="close" class="text-gray-400 hover:text-gray-600 transition-colors focus:outline-none">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
        
        <!-- Body -->
        <div class="p-8 max-h-[80vh] overflow-y-auto text-left space-y-8 custom-datepicker">
          
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label class="block text-sm font-bold text-gray-500 mb-2 uppercase tracking-wide">Factura Interna</label>
              <input type="text" v-model="localForm.facturaInterna" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-lg focus:outline-none focus:border-blue-500 transition-colors" />
            </div>
            <div>
              <label class="block text-sm font-bold text-gray-500 mb-2 uppercase tracking-wide">Factura Proveedor</label>
              <input type="text" v-model="localForm.fProveedor" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-lg focus:outline-none focus:border-blue-500 transition-colors" />
            </div>
            <div>
              <label class="block text-sm font-bold text-gray-500 mb-2 uppercase tracking-wide">Transportista</label>
              <input type="text" v-model="localForm.transportista" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-lg focus:outline-none focus:border-blue-500 transition-colors" />
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-end">
            <div>
              <label class="block text-sm font-bold text-gray-500 mb-2 uppercase tracking-wide">Fecha</label>
              <VueCtkDateTimePicker 
                v-model="localForm.fecha" 
                :only-date="true" 
                format="YYYY-MM-DD" 
                formatted="DD/MM/YYYY" 
                label="Seleccionar fecha"
                color="#3182CE"
                button-color="#3182CE"
              />
            </div>
            <div class="pb-3">
              <label class="flex items-center gap-3 cursor-pointer w-max group">
                <input type="checkbox" v-model="localForm.pagadaCliente" class="w-6 h-6 text-blue-500 border-gray-300 rounded focus:ring-blue-400 cursor-pointer" />
                <span class="text-base font-bold text-gray-700 group-hover:text-blue-600 transition-colors uppercase tracking-wide">Pagada por el cliente</span>
              </label>
            </div>
          </div>
          
          <div>
            <label class="block text-sm font-bold text-gray-500 mb-2 uppercase tracking-wide">Comentarios</label>
            <textarea v-model="localForm.comentario" rows="4" placeholder="Escriba aquí los comentarios u observaciones..." class="w-full border border-gray-300 rounded-lg p-4 text-lg text-gray-700 focus:outline-none focus:border-blue-500 resize-none transition-colors placeholder-gray-400"></textarea>
          </div>
          
          <div class="flex justify-end gap-4 pt-6 mt-4 border-t border-gray-100">
            <button @click="close" class="px-8 py-3 rounded-lg text-lg font-bold text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors shadow-sm">CANCELAR</button>
            <button @click="save" class="px-10 py-3 rounded-lg text-lg font-bold text-white bg-green-500 hover:bg-green-600 shadow-sm transition-colors">GUARDAR</button>
          </div>
        </div>

      </div>
    </div>
  </transition>
</template>

<script>
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';

export default {
  name: 'ModalEditarOperacion',
  components: {
    VueCtkDateTimePicker
  },
  props: {
    show: {
      type: Boolean,
      default: false
    },
    rowData: {
      type: Object,
      default: () => ({})
    }
  },
  data() {
    return {
      localForm: { ...this.rowData }
    };
  },
  watch: {
    rowData: {
      handler(newValue) {
        this.localForm = { 
          fecha: '',
          comentario: '',
          pagadaCliente: false,
          ...newValue 
        };
      },
      deep: true,
      immediate: true
    }
  },
  methods: {
    close() {
      this.$emit('close');
    },
    save() {
      this.$emit('save', this.localForm);
    }
  }
};
</script>

<style scoped>
@import '~vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';

.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease-in-out; }
.modal-enter, .modal-leave-to { opacity: 0; }

/* ============================================== */
/* AJUSTES PARA EL DATE-TIME PICKER               */
/* ============================================== */
::v-deep .custom-datepicker .field-input {
  min-height: 48px !important;
  height: 48px !important;
  font-size: 16px !important; 
  border-radius: 8px !important; 
  border-color: #D1D5DB !important; 
  padding-left: 16px !important;
}

::v-deep .custom-datepicker .field-label {
  display: none !important; 
}

::v-deep .custom-datepicker .field-clear-button {
  display: none !important;
}
</style>