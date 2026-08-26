<template>
  <div v-if="mostrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 p-4 md:p-8">

    <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl flex flex-col overflow-hidden" style="height: 90vh;">

      <!-- Header -->
      <div class="px-6 py-4 flex justify-between items-center shrink-0 shadow-md z-10" style="background-color: #2A3A4D;">
        <h3 class="text-white font-bold text-xl tracking-wide">
          <span class="text-gray-400 mr-2">📄 Visualizando:</span> {{ titulo }}
        </h3>
        <button @click="cerrar"
          class="text-gray-400 hover:text-white transition-colors bg-gray-700 hover:bg-gray-600 rounded-full p-2">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Body / Visor PDF -->
      <div class="flex-1 bg-gray-200 relative w-full flex flex-col">

        <!-- Pantalla de carga mientras el PDF se descarga del backend -->
        <div v-if="cargando" class="absolute inset-0 flex flex-col items-center justify-center bg-gray-100 z-10">
          <svg class="animate-spin -ml-1 mr-3 h-12 w-12 text-indigo-600 mb-4" xmlns="http://www.w3.org/2000/svg"
            fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
            </path>
          </svg>
          <span class="text-gray-500 font-bold text-lg animate-pulse">Obteniendo documento...</span>
        </div>

        <!-- El visor nativo del navegador -->
        <iframe v-show="!cargando" :src="urlPdf" class="w-full flex-1 border-0" @load="cargando = false"></iframe>

      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'ModalVerDocumento',
  props: {
    mostrar: { type: Boolean, default: false },
    urlPdf: { type: String, required: true },
    titulo: { type: String, default: 'Documento' }
  },
  data() {
    return {
      cargando: true
    }
  },
  watch: {
    // Si la URL cambia, volvemos a poner la pantalla de carga
    urlPdf() {
      this.cargando = true;
    },
    mostrar(nuevoValor) {
      if (nuevoValor) {
        this.cargando = true;
      }
    }
  },
  methods: {
    cerrar() {
      this.$emit('cerrar');
    }
  }
}
</script>