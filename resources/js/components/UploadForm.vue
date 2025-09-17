<template>
  <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-lg">
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
                class="relative cursor-pointer bg-white rounded-md font-medium text-green-600 hover:opacity-80 focus-within:outline-none"
              >
                <span>Sube un archivo</span>
                <input
                  id="file-upload"
                  name="file-upload"
                  type="file"
                  class="sr-only"
                  ref="fileInput"
                  :accept="acceptedFileTypes"
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
                  ref="fileInput"
                  :accept="acceptedFileTypes"
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
        <label for="sucursal" class="block text-sm font-medium text-gray-700"
          >Sucursal</label
        >
        <select
          id="sucursal"
          v-model="selectedSucursal"
          class="mt-1 block w-full border-2 border-gray-300 pl-3 pr-10 py-2 text-base focus:outline-none focus:ring-2  sm:text-sm rounded-md transition-all duration-300"
          :class="selectedSucursal ? 'border-green-500 border-2' : 'border-gray-300'"
        >
          <option value="" disabled>Selecciona una sucursal</option>
          <option
            v-for="sucursal in sucursales"
            :key="sucursal.value"
            :value="sucursal.value"
          >
            {{ sucursal.text }}
          </option>
        </select>
      </div>

      <div>
        <label for="bank" class="block text-sm font-medium text-gray-700">Banco</label>
        <select
          id="bank"
          v-model="selectedBank"
          class="mt-1 block w-full border-2 border-gray-300 pl-3 pr-10 py-2 text-base focus:outline-none focus:ring-2 sm:text-sm rounded-md transition-all duration-300"
          :class="selectedBank ? 'border-green-500 border-2' : 'border-gray-300'"
        >
          <option value="" disabled>Selecciona un banco</option>
          <option v-for="bank in filteredBanks" :key="bank.value" :value="bank.value">
            {{ bank.text }}
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
</template>

<script>
export default {
  data() {
    return {
      // 1. Datos para almacenar las selecciones del usuario
      selectedFile: null,
      selectedBank: "",
      selectedSucursal: "",

      // 2. Opciones para los dropdowns (podrían venir de Laravel en el futuro)
      allBanks: [
        { text: "BBVA", value: "BBVA" },
        { text: "Santander", value: "SANTANDER" },
        { text: "Externo (XLSX)", value: "EXTERNO" },
      ],
      sucursales: [
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
     * que depende de la sucursal seleccionada.
     */

    isFormValid() {
      // Esta función recalcula automáticamente su valor
      // cada vez que una de estas 3 variables cambia.
      return this.selectedFile && this.selectedBank && this.selectedSucursal;
    },
    filteredBanks() {
      // Si la sucursal seleccionada es Tijuana ('TIJ')...
      if (this.selectedSucursal === "TIJ") {
        // ...devolvemos la lista completa de bancos.
        return this.allBanks;
      } else {
        // ...de lo contrario, devolvemos la lista de bancos
        // filtrada para excluir la opción 'EXTERNO'.
        return this.allBanks.filter((bank) => bank.value !== "EXTERNO");
      }
    },
    // NUEVO: Propiedad computada para el atributo 'accept' del input
    acceptedFileTypes() {
      if (this.selectedBank === "EXTERNO") {
        // Devuelve las extensiones y tipos MIME para Excel
        return ".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
      }
      // Por default o para otros bancos, solo PDF
      return ".pdf,application/pdf";
    },
    // NUEVO: Propiedad computada para el texto de ayuda
    fileTypeHint() {
      return this.selectedBank === "EXTERNO" ? "XLSX" : "PDF";
    },
  },
  watch: {
    /**
     * Este 'observador' se activa cada vez que 'selectedSucursal' cambia.
     */
    selectedSucursal(newSucursal, oldSucursal) {
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

    // NUEVO: Observador para re-validar el archivo cuando el banco cambia
    selectedBank() {
        // Si ya hay un archivo seleccionado...
      if (this.selectedFile) {
        // ...y NO es válido para el nuevo banco seleccionado...
        if (!this.isFileValid(this.selectedFile)) {
           this.errorMessage = `El archivo ${this.selectedFile.name} no es un ${this.fileTypeHint}. Por favor, sube un archivo válido.`;
          // ...lo limpiamos.
          this.selectedFile = null;
          this.$refs.fileInput.value = ''; // Limpia el input de archivo
        } else {
             this.errorMessage = "";
        }
      }
    },
  },
  methods: {
    // 5. Método para manejar la carga del archivo
    handleFileUpload(event) {
      // Obtenemos el primer archivo seleccionado
      this.errorMessage = ""; // Limpiamos errores previos
      const file = event.target.files[0];

      if (!file) {
          this.selectedFile = null;
          return;
      }

      // Validamos el archivo antes de asignarlo
      if (this.isFileValid(file)) {
        this.selectedFile = file;
      } else {
        // Si no es válido, mostramos un error y limpiamos todo
        this.errorMessage = `Tipo de archivo incorrecto. Por favor, sube un ${this.fileTypeHint}.`;
        this.selectedFile = null;
        event.target.value = ''; // Limpia el input para poder subir el mismo archivo (corregido) de nuevo
      }
    },
     // NUEVO: Método reutilizable para validar el archivo
    isFileValid(file) {
        const isExcel = file.type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || file.name.endsWith('.xlsx');
        const isPdf = file.type === 'application/pdf' || file.name.endsWith('.pdf');

        if(this.selectedBank === 'EXTERNO') {
            return isExcel;
        }

        return isPdf;
    },


    // 6. Método que se ejecutará al hacer clic en el botón
    submitForm() {
      if (!this.isFormValid) return;

      this.isLoading = true;
      this.errorMessage = ""; // Limpiamos errores anteriores

      // 1. Creamos un objeto FormData, especial para enviar archivos
      const formData = new FormData();

      // 2. Añadimos los datos del formulario al objeto
      formData.append("estado_de_cuenta", this.selectedFile);
      formData.append("banco", this.selectedBank);
      formData.append("sucursal", this.selectedSucursal);

      // 3. Hacemos la petición POST con Axios a nuestra nueva ruta
      axios
        .post("/importar-estado-de-cuenta", formData, {
          headers: {
            "Content-Type": "multipart/form-data", // Header importante para archivos
          },
        })
        .then((response) => {
          // Si todo fue bien...
          this.isLoading = false;
          //ACA TAMBIEN CAMBIA LA RUTA SI ES NECESARIO
          //axios.post("/auditoria/ejecutar-comando");

          alert(response.data.message); // Mostramos el mensaje de éxito
          // 2. Extraemos el valor del parámetro 'page'.
          this.$emit('upload-success');
        })
        .catch((error) => {
          // Si algo falló en el backend...
          this.isLoading = false;
          // Mostramos el error que nos devolvió el controlador
          this.errorMessage =
            error.response?.data?.error || "Ocurrió un error inesperado.";
          alert(this.errorMessage);
        });
    },
  },
};
</script>
