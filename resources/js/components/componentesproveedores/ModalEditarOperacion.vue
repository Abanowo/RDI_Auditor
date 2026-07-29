<template>
  <transition name="modal">
    <div v-if="show" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
      <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl overflow-hidden">
        
        <div class="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 class="text-xl font-bold text-gray-800">Editar Operación</h2>
          <button @click="close" class="text-gray-400 hover:text-gray-600 transition-colors focus:outline-none">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>
        
        <div class="p-6 max-h-[80vh] overflow-y-auto text-left space-y-5 custom-datepicker">
          
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-[10px] font-bold text-gray-500 mb-1 uppercase">Factura Interna</label>
              <input type="text" v-model="localForm.facturaInterna" class="w-full h-[34px] border border-gray-300 rounded px-3 text-xs focus:outline-none focus:border-blue-400 transition-colors" />
            </div>
            <div>
              <label class="block text-[10px] font-bold text-gray-500 mb-1 uppercase">Factura Proveedor</label>
              <input type="text" v-model="localForm.fProveedor" class="w-full h-[34px] border border-gray-300 rounded px-3 text-xs focus:outline-none focus:border-blue-400 transition-colors" />
            </div>
            <div>
              <label class="block text-[10px] font-bold text-gray-500 mb-1 uppercase">Transportista</label>
              <input type="text" v-model="localForm.transportista" class="w-full h-[34px] border border-gray-300 rounded px-3 text-xs focus:outline-none focus:border-blue-400 transition-colors" />
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
            <div>
              <label class="block text-[10px] font-bold text-gray-500 mb-1 uppercase">Fecha</label>
              <VueCtkDateTimePicker 
                v-model="localForm.fecha" 
                :only-date="true" 
                format="YYYY-MM-DD" 
                formatted="DD/MM/YYYY" 
                label="Seleccionar fecha"
                color="#3182CE"
                button-color="#3182CE"
                input-size="sm"
              />
            </div>
            <div class="pb-2">
              <label class="flex items-center gap-2 cursor-pointer w-max group">
                <input type="checkbox" v-model="localForm.pagadaCliente" class="w-4 h-4 text-blue-500 border-gray-300 rounded focus:ring-blue-400 cursor-pointer" />
                <span class="text-xs font-bold text-gray-700 group-hover:text-blue-600 transition-colors uppercase">Pagada por el cliente</span>
              </label>
            </div>
          </div>
          
          <div>
            <label class="block text-[10px] font-bold text-gray-500 mb-1 uppercase">Comentarios</label>
            <textarea v-model="localForm.comentario" rows="3" placeholder="Escriba aquí los comentarios u observaciones..." class="w-full border border-gray-300 rounded p-3 text-xs text-gray-700 focus:outline-none focus:border-blue-400 resize-none transition-colors placeholder-gray-400"></textarea>
          </div>
          
          <div class="flex justify-end gap-3 pt-4 mt-2 border-t border-gray-100">
            <button @click="close" class="px-6 py-2 rounded text-xs font-bold text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">CANCELAR</button>
            <button @click="save" class="px-8 py-2 rounded text-xs font-bold text-white bg-green-500 hover:bg-green-600 shadow-sm transition-colors">GUARDAR</button>
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

/* Ajustes para igualarlo a tus inputs de Tailwind (34px) */
::v-deep .custom-datepicker .field-input {
  min-height: 34px !important;
  height: 34px !important;
  font-size: 0.75rem !important; /* text-xs */
  border-radius: 0.25rem !important; /* rounded */
  border-color: #D1D5DB !important; /* border-gray-300 */
}
::v-deep .custom-datepicker .field-label {
  display: none !important; /* Ocultamos el label flotante del componente porque ya tenemos uno arriba */
}
</style>