<template>
  <transition name="modal">
    <div v-if="show"
      class="fixed inset-0 bg-black bg-opacity-40 backdrop-blur-sm z-[100] flex items-center justify-center p-6 md:p-10">
      
      <!-- CONTENEDOR PRINCIPAL DEL MODAL (Aumentado a max-w-2xl) -->
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col mx-4">

        <!-- Header Modal -->
        <div class="flex items-center justify-between px-8 py-6 border-b border-gray-200">
          <h2 class="text-2xl font-bold text-gray-800 tracking-wider">Notificación</h2>
          <button @click="close" class="text-gray-400 hover:text-gray-900 transition-colors focus:outline-none">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <!-- Body Modal -->
        <div class="p-8 overflow-y-auto text-left space-y-8 custom-multiselect-modal">

          <div>
            <label class="block text-lg font-bold text-gray-700 mb-2">Usuarios:</label>
            <Multiselect v-model="form.usuarios" :options="opcionesUsuarios" :multiple="true" :taggable="true"
              @tag="addUsuarioTag" placeholder="Buscar o agregar correos" select-label="Enter para seleccionar"
              deselect-label="Enter para remover" tag-placeholder="Enter para agregar nuevo correo" class="w-full text-lg">
            </Multiselect>
          </div>

          <div>
            <label class="block text-lg font-bold text-gray-700 mb-2">Contactos Proveedor:</label>
            <Multiselect v-model="form.contactos" :options="opcionesContactos" :multiple="true" :taggable="true"
              @tag="addContactoTag" placeholder="Buscar o agregar correos" select-label="Enter para seleccionar"
              deselect-label="Enter para remover" tag-placeholder="Enter para agregar nuevo correo" class="w-full text-lg">
            </Multiselect>
          </div>

          <div>
            <label class="block text-lg font-bold text-gray-700 mb-2">Subir comprobante de pago</label>
            <div
              class="border-2 border-dashed border-gray-300 rounded-xl p-10 flex flex-col items-center justify-center text-gray-400 hover:bg-gray-50 transition-colors cursor-pointer">
              <svg class="w-12 h-12 mb-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                <path d="M5.5 13a3.5 3.5 0 01-.369-6.98 4 4 0 117.759-2.159 4.5 4.5 0 11-1.89 8.923H5.5z"></path>
                <path fill-rule="evenodd"
                  d="M10 12a1 1 0 01-1-1V7.414L7.707 8.707a1 1 0 01-1.414-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 7.414V11a1 1 0 01-1 1z"
                  clip-rule="evenodd"></path>
              </svg>
              <span class="text-lg font-medium text-gray-500">Subir archivo</span>
            </div>
          </div>

          <div class="space-y-3">
            <div v-for="(file, index) in files" :key="index" class="flex items-center gap-3 text-base font-medium text-gray-800">
              <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                </path>
              </svg>
              <span>{{ file.name }}</span>
              <button @click="removeFile(index)" class="text-[#E74C3C] hover:text-red-700 transition-colors ml-2">
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z"
                    clip-rule="evenodd"></path>
                </svg>
              </button>
            </div>
          </div>

          <div class="space-y-4 pt-4">
            <label class="flex items-center gap-4 cursor-pointer">
              <input type="checkbox" v-model="form.adjuntarFacturas"
                class="w-6 h-6 text-purple-500 border-gray-300 rounded focus:ring-purple-400 cursor-pointer" />
              <span class="text-lg font-medium text-gray-700">Adjuntar facturas del proveedor</span>
            </label>
            <label class="flex items-center gap-4 cursor-pointer">
              <input type="checkbox" v-model="form.adjuntarComprobante"
                class="w-6 h-6 text-purple-500 border-gray-300 rounded focus:ring-purple-400 cursor-pointer" />
              <span class="text-lg font-medium text-gray-700">Adjuntar comprobante de pago</span>
            </label>
          </div>

        </div>

        <!-- Footer Modal -->
        <div class="px-8 py-6 border-t border-gray-100 flex justify-end bg-gray-50 rounded-b-xl">
          <button @click="submit"
            class="bg-[#9B8AF2] hover:bg-purple-500 text-white px-8 py-4 rounded-lg text-lg font-bold transition-colors shadow-sm tracking-wide">
            Enviar Notificación
          </button>
        </div>

      </div>
    </div>
  </transition>
</template>

<script>
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

export default {
  name: 'ModalNotificacion',
  components: {
    Multiselect
  },
  props: {
    show: { type: Boolean, default: false },
    rowData: { type: Object, default: () => ({}) },
    sharedFiles: { type: Array, default: () => [] }
  },
  data() {
    return {
      form: {
        usuarios: [], 
        contactos: [],
        adjuntarFacturas: false,
        adjuntarComprobante: false
      },
      opcionesUsuarios: ['admin@empresa.com', 'finanzas@empresa.com', 'gerencia@empresa.com'],
      opcionesContactos: ['ventas@proveedor.com', 'cobranza@proveedor.com'],
      files: []
    };
  },
  watch: {
    show(val) {
      if (val) {
        this.form = { usuarios: [], contactos: [], adjuntarFacturas: false, adjuntarComprobante: false };
        this.files = this.sharedFiles.length > 0 ? [...this.sharedFiles] : [
          { name: 'PDF521.pdf' }, { name: '520.pdf' }, { name: '525.pdf' }
        ];
      }
    }
  },
  methods: {
    close() {
      this.$emit('close');
    },
    removeFile(index) {
      this.files.splice(index, 1);
    },
    addUsuarioTag(newTag) {
      this.opcionesUsuarios.push(newTag);
      this.form.usuarios.push(newTag);
    },
    addContactoTag(newTag) {
      this.opcionesContactos.push(newTag);
      this.form.contactos.push(newTag);
    },
    submit() {
      this.$emit('send', { form: this.form, files: this.files });
      this.close();
    }
  }
};
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease-in-out;
}

.modal-enter,
.modal-leave-to {
  opacity: 0;
}

/* ============================================== */
/* AJUSTES PARA QUE EL MULTISELECT RESPETE LA ESCALA */
/* ============================================== */
::v-deep .custom-multiselect-modal .multiselect__tags {
  border-color: #D1D5DB !important;
  border-radius: 0.5rem !important; 
  font-size: 16px !important;
  min-height: 48px !important;
  padding-top: 10px !important;
  padding-left: 12px !important;
}

::v-deep .custom-multiselect-modal .multiselect__select {
  height: 48px !important;
}

::v-deep .custom-multiselect-modal .multiselect__single,
::v-deep .custom-multiselect-modal .multiselect__input {
  font-size: 16px !important;
  margin-bottom: 0px !important;
  padding-top: 2px !important;
}

::v-deep .custom-multiselect-modal .multiselect__tag {
  background-color: #9B8AF2 !important;
  font-size: 14px !important;
  margin-top: 2px !important;
}

::v-deep .custom-multiselect-modal .multiselect__tag-icon:focus,
::v-deep .custom-multiselect-modal .multiselect__tag-icon:hover {
  background-color: #7B68EE !important;
}

::v-deep .custom-multiselect-modal .multiselect__tag-icon:after {
  color: white !important;
}

::v-deep .custom-multiselect-modal .multiselect__option {
  font-size: 16px !important;
  padding: 12px 16px !important;
}

::v-deep .custom-multiselect-modal .multiselect__option--highlight {
  background: #9B8AF2 !important;
  outline: none;
  color: white;
}

::v-deep .custom-multiselect-modal .multiselect__option--highlight:after {
  background: #9B8AF2 !important;
  color: white;
}
</style>