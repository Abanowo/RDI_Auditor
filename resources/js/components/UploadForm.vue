<template>
  <div class="min-h-screen bg-theme-light flex items-center justify-center p-4">
    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-lg">
      <h1 class="text-2xl font-bold text-center text-theme-dark mb-6">
        Procesador de Cuentas
      </h1>

      <div class="space-y-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"
            >Estado de Cuenta (PDF o XLSX)</label
          >
          <div
            class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md"
            @change="handleFileUpload"
          >
            <div class="space-y-1 text-center">
              <svg
                class="mx-auto h-12 w-12 text-gray-400"
                stroke="currentColor"
                fill="none"
                viewBox="0 0 48 48"
                aria-hidden="true"
              >
                <path
                  d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              <div class="flex text-sm text-gray-600">
                <label
                  v-if="selectedFile"
                  class="relative cursor-pointer bg-white rounded-md font-medium text-green-600 font-semibold hover:opacity-80 focus-within:outline-none"
                >
                  <span>Sube un archivo</span>
                  <input
                    id="file-upload"
                    name="file-upload"
                    type="file"
                    class="sr-only"
                    @change="handleFileUpload"
                  />
                </label>

                <label
                  v-else
                  for="file-upload"
                  class="relative cursor-pointer bg-white rounded-md font-medium text-theme-primary hover:opacity-80 focus-within:outline-none"
                >
                  <span>Sube un archivo</span>
                  <input
                    id="file-upload"
                    name="file-upload"
                    type="file"
                    class="sr-only"
                    @change="handleFileUpload"
                  />
                </label>
                <p class="pl-1">o arrástralo aquí</p>
              </div>
              <p class="text-xs text-gray-500">PDF, XLSX hasta 10MB</p>
            </div>
          </div>
          <p v-if="selectedFile" class="mt-2 text-sm text-green-600 font-semibold">
            Archivo seleccionado: {{ selectedFile.name }}
          </p>
        </div>

        <div>
          <label for="bank" class="block text-sm font-medium text-gray-700">Banco</label>
          <select
            id="bank"
            v-model="selectedBank"
            class="mt-1 block w-full pl-3 pr-10 py-2 text-base focus:outline-none focus:ring-2 focus:ring-theme-primary sm:text-sm rounded-md transition-all duration-300"
            :class="selectedBank ? 'border-green-500 border-2' : 'border-gray-300'"
          >
            <option value="" disabled>Selecciona un banco</option>
            <option v-for="bank in filteredBanks" :key="bank.value" :value="bank.value">
              {{ bank.text }}
            </option>
          </select>
        </div>

        <div>
          <label for="sede" class="block text-sm font-medium text-gray-700">Sede</label>
          <select
            id="sede"
            v-model="selectedSede"
            class="mt-1 block w-full pl-3 pr-10 py-2 text-base focus:outline-none focus:ring-2 focus:ring-theme-primary sm:text-sm rounded-md transition-all duration-300"
            :class="selectedSede ? 'border-green-500 border-2' : 'border-gray-300'"
          >
            <option value="" disabled>Selecciona una sede</option>
            <option v-for="sede in sedes" :key="sede.value" :value="sede.value">
              {{ sede.text }}
            </option>
          </select>
        </div>

        <div class="pt-4">
          <button
            type="button"
            @click="submitForm"
            :disabled="!isFormValid"
            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:bg-gray-400 disabled:cursor-not-allowed transition-all duration-300"
            :class="
              isFormValid
                ? 'bg-green-500 hover:bg-green-600 focus:ring-green-500'
                : 'bg-theme-primary'
            "
          >
            Siguiente Paso
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      // 1. Datos para almacenar las selecciones del usuario
      selectedFile: null,
      selectedBank: "",
      selectedSede: "",

      // 2. Opciones para los dropdowns (podrían venir de Laravel en el futuro)
      allBanks: [
        { text: "BBVA", value: "BBVA" },
        { text: "Santander", value: "SANTANDER" },
        { text: "Externo (XLSX)", value: "EXTERNO" },
      ],
      sedes: [
        { text: "Nogales", value: "NOG" },
        { text: "Tijuana", value: "TIJ" },
        { text: "Laredo", value: "NL" },
        { text: "Reynosa", value: "REY" },
        { text: "Mexicali", value: "MXL" },
        { text: "Manzanillo", value: "ZLO" },
      ],

      // 3. Para manejar errores y notificaciones
      errorMessage: "",
      isLoading: false,
    };
  },

  computed: {
    /**
     * Esta propiedad computada devuelve una lista de bancos
     * que depende de la sede seleccionada.
     */

    isFormValid() {
      // Esta función recalcula automáticamente su valor
      // cada vez que una de estas 3 variables cambia.
      return this.selectedFile && this.selectedBank && this.selectedSede;
    },
    filteredBanks() {
      // Si la sede seleccionada es Tijuana ('TIJ')...
      if (this.selectedSede === "TIJ") {
        // ...devolvemos la lista completa de bancos.
        return this.allBanks;
      } else {
        // ...de lo contrario, devolvemos la lista de bancos
        // filtrada para excluir la opción 'EXTERNO'.
        return this.allBanks.filter((bank) => bank.value !== "EXTERNO");
      }
    },
  },
  watch: {
    /**
     * Este 'observador' se activa cada vez que 'selectedSede' cambia.
     */
    selectedSede(newSede, oldSede) {
      // Verificamos si el banco que estaba seleccionado ('this.selectedBank')
      // existe en la nueva lista de bancos filtrados.
      const isCurrentBankValid = this.filteredBanks.some(
        (bank) => bank.value === this.selectedBank
      );

      // Si el banco actual ya no es una opción válida...
      if (!isCurrentBankValid) {
        // ...limpiamos la selección para forzar al usuario a elegir de nuevo.
        this.selectedBank = "";
      }
    },
  },
  methods: {
    // 5. Método para manejar la carga del archivo
    handleFileUpload(event) {
      // Obtenemos el primer archivo seleccionado
      this.selectedFile = event.target.files[0];
      this.errorMessage = ""; // Limpiamos errores previos
    },

    // 6. Método que se ejecutará al hacer clic en el botón
    submitForm() {
      if (!this.isFormValid) return; // Doble chequeo de seguridad

      this.isLoading = true; // Mostramos un estado de carga (opcional)
      console.log("Enviando los siguientes datos al backend:");
      console.log("Archivo:", this.selectedFile.name);
      console.log("Banco:", this.selectedBank);
      console.log("Sede:", this.selectedSede);

      // Aquí es donde en el futuro haremos la llamada a Laravel con Axios
      // Por ahora, simularemos una navegación
      alert("¡Formulario válido! Navegando a la siguiente página...");
      // window.location.href = '/siguiente-paso';
      this.isLoading = false;
    },
  },
};
</script>
