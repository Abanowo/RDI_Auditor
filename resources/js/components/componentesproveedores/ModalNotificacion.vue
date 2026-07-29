<template>
  <transition name="modal">
    <div v-if="show"
      class="fixed inset-0 bg-black bg-opacity-40 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
      <div class="bg-white rounded shadow-2xl w-full max-w-lg overflow-hidden flex flex-col">

        <div class="flex items-center justify-between p-5 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-800">Notification</h2>
          <button @click="close" class="text-gray-600 hover:text-gray-900 transition-colors focus:outline-none">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <div class="p-6 overflow-y-auto text-left space-y-5 custom-multiselect-modal">

          <div>
            <label class="block text-sm text-gray-700 mb-1">Usuarios:</label>
            <Multiselect v-model="form.usuarios" :options="opcionesUsuarios" :multiple="true" :taggable="true"
              @tag="addUsuarioTag" placeholder="Buscar o agregar correos" select-label="Enter para seleccionar"
              deselect-label="Enter para remover" tag-placeholder="Enter para agregar nuevo correo" class="w-full">
            </Multiselect>
          </div>

          <div>
            <label class="block text-sm text-gray-700 mb-1">Contactos Proveedor:</label>
            <Multiselect v-model="form.contactos" :options="opcionesContactos" :multiple="true" :taggable="true"
              @tag="addContactoTag" placeholder="Buscar o agregar correos" select-label="Enter para seleccionar"
              deselect-label="Enter para remover" tag-placeholder="Enter para agregar nuevo correo" class="w-full">
            </Multiselect>
          </div>

          <div>
            <label class="block text-sm text-gray-700 mb-1">Subir comprobante de pago</label>
            <div
              class="border-2 border-dashed border-gray-300 rounded p-8 flex flex-col items-center justify-center text-gray-400 hover:bg-gray-50 transition-colors cursor-pointer">
              <svg class="w-8 h-8 mb-2" fill="currentColor" viewBox="0 0 20 20">
                <path d="M5.5 13a3.5 3.5 0 01-.369-6.98 4 4 0 117.759-2.159 4.5 4.5 0 11-1.89 8.923H5.5z"></path>
                <path fill-rule="evenodd"
                  d="M10 12a1 1 0 01-1-1V7.414L7.707 8.707a1 1 0 01-1.414-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 7.414V11a1 1 0 01-1 1z"
                  clip-rule="evenodd"></path>
              </svg>
              <span class="text-sm">Subir archivo</span>
            </div>
          </div>

          <div class="space-y-1">
            <div v-for="(file, index) in files" :key="index" class="flex items-center gap-2 text-xs text-gray-800">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                </path>
              </svg>
              <span>{{ file.name }}</span>
              <button @click="removeFile(index)" class="text-[#E74C3C] hover:text-red-700 transition-colors">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z"
                    clip-rule="evenodd"></path>
                </svg>
              </button>
            </div>
          </div>

          <div class="space-y-2 pt-2">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" v-model="form.adjuntarFacturas"
                class="w-4 h-4 text-purple-500 border-gray-300 rounded focus:ring-purple-400 cursor-pointer" />
              <span class="text-sm text-gray-700">Adjuntar facturas del proveedor</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" v-model="form.adjuntarComprobante"
                class="w-4 h-4 text-purple-500 border-gray-300 rounded focus:ring-purple-400 cursor-pointer" />
              <span class="text-sm text-gray-700">Adjuntar comprobante de pago</span>
            </label>
          </div>

        </div>

        <div class="p-5 border-t border-gray-100 flex justify-end">
          <button @click="submit"
            class="bg-[#9B8AF2] hover:bg-purple-500 text-white px-5 py-2 rounded text-sm font-semibold transition-colors shadow-sm">
            Enviar Notificacion
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
        usuarios: [], // Ahora son arreglos para soportar multiples tags
        contactos: [],
        adjuntarFacturas: false,
        adjuntarComprobante: false
      },
      // Datos de ejemplo para las listas desplegables (puedes inyectarlos desde el padre o BD)
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
    // Métodos para agregar etiquetas que no estén en las opciones predefinidas
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

/* Ajustes para que el multiselect combine con el estilo del Modal */
::v-deep .custom-multiselect-modal .multiselect__tags {
  border-color: #D1D5DB !important;
  border-radius: 0.375rem !important;
  /* rounded-md */
  font-size: 0.875rem !important;
  /* text-sm */
  min-height: 38px;
  padding-top: 6px;
}

::v-deep .custom-multiselect-modal .multiselect__tag {
  background-color: #9B8AF2 !important;
  /* Etiqueta morada para que combine con el botón */
}

::v-deep .custom-multiselect-modal .multiselect__tag-icon:focus,
::v-deep .custom-multiselect-modal .multiselect__tag-icon:hover {
  background-color: #7B68EE !important;
}

::v-deep .custom-multiselect-modal .multiselect__tag-icon:after {
  color: white !important;
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