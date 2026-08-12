<template>
  <transition name="modal">
    <div v-if="show"
      class="fixed inset-0 bg-black bg-opacity-40 backdrop-blur-sm z-[100] flex items-center justify-center p-6 md:p-10">

      <!-- CONTENEDOR PRINCIPAL DEL MODAL (Aumentado a max-w-2xl) -->
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col mx-4">

        <!-- Header Modal -->
        <div class="flex items-center justify-between px-8 py-6 border-b border-gray-200">
          <h2 class="text-2xl font-bold text-gray-800 tracking-wider">Documentos de la Operación</h2>
          <button @click="close" class="text-gray-400 hover:text-gray-900 transition-colors focus:outline-none">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <!-- Body Modal -->
        <div class="p-8 overflow-y-auto text-left space-y-8">
          <div>
            <div
              class="border-2 border-dashed border-gray-300 rounded-xl p-10 flex flex-col items-center justify-center text-gray-400 hover:bg-gray-50 transition-colors cursor-pointer">
              <svg class="w-12 h-12 mb-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                <path d="M5.5 13a3.5 3.5 0 01-.369-6.98 4 4 0 117.759-2.159 4.5 4.5 0 11-1.89 8.923H5.5z"></path>
                <path fill-rule="evenodd"
                  d="M10 12a1 1 0 01-1-1V7.414L7.707 8.707a1 1 0 01-1.414-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 7.414V11a1 1 0 01-1 1z"
                  clip-rule="evenodd"></path>
              </svg>
              <span class="text-lg font-medium text-gray-500">Arrastra tus archivos aquí o haz clic para subir</span>
            </div>
          </div>

          <div class="space-y-3">
            <h3 class="text-base font-bold text-gray-500 uppercase mb-3">Archivos subidos</h3>
            <div v-for="(file, index) in files" :key="index"
              class="flex items-center gap-4 text-base font-medium text-gray-800 p-4 bg-gray-50 rounded-lg border">
              <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                </path>
              </svg>
              <span class="flex-1">{{ file.name }}</span>
              <button @click="removeFile(index)" class="text-[#E74C3C] hover:text-red-700 transition-colors">
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z"
                    clip-rule="evenodd"></path>
                </svg>
              </button>
            </div>
            <div v-if="files.length === 0" class="text-base text-gray-400 italic">No hay archivos.</div>
          </div>
        </div>

        <!-- Footer Modal -->
        <div class="px-8 py-6 border-t border-gray-100 flex justify-end bg-gray-50 rounded-b-xl">
          <button @click="close"
            class="bg-blue-500 hover:bg-blue-600 text-white px-8 py-4 rounded-lg text-lg font-bold transition-colors shadow-sm tracking-wide">
            Guardar y Cerrar
          </button>
        </div>

      </div>
    </div>
  </transition>
</template>

<script>
export default {
  name: 'ModalDocumentos',
  props: {
    show: { type: Boolean, default: false },
    sharedFiles: { type: Array, default: () => [] }
  },
  data() {
    return {
      files: []
    };
  },
  watch: {
    show(val) {
      if (val) this.files = [...this.sharedFiles];
    }
  },
  methods: {
    close() {
      // Al cerrar emitimos los archivos para que los guarde el padre y estén disponibles en el modal de correo
      this.$emit('close', this.files);
    },
    removeFile(index) {
      this.files.splice(index, 1);
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
</style>