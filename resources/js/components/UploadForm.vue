<template>
  <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-lg">
    <div class="space-y-6">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1"
          >Estado de Cuenta (PDF o XLSX)</label
        >
        <div
          class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-dashed rounded-md transition-colors duration-200"
          :class="zonaClase"
          @dragover.prevent="arrastrando = true"
          @dragleave.prevent="arrastrando = false"
          @drop.prevent="handleDrop"
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
                for="file-upload"
                class="relative cursor-pointer bg-white rounded-md font-medium hover:opacity-80 focus-within:outline-none"
                :class="selectedFile ? 'text-green-600' : 'text-theme-primary'"
              >
                <span>{{ selectedFile ? "Cambiar archivo" : "Sube un archivo" }}</span>
                <input
                  id="file-upload"
                  name="file-upload"
                  type="file"
                  class="sr-only"
                  ref="fileInput"
                  :accept="acceptedFileTypes"
                  :disabled="isLoading"
                  @change="handleFileUpload"
                />
              </label>
              <p class="pl-1">o arrástralo aquí</p>
            </div>
            <p class="text-xs text-gray-500">PDF, XLSX hasta 10MB</p>
          </div>
        </div>

        <p v-if="selectedFile && !errorMessage" class="mt-2 text-sm text-green-600 font-semibold">
          Archivo seleccionado: {{ selectedFile.name }}
        </p>

        <!-- Aviso: archivo incorrecto (imagen, dañado, otro banco, formato distinto...) -->
        <div
          v-if="errorMessage"
          class="mt-3 flex items-start gap-2 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"
          role="alert"
        >
          <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
            />
          </svg>
          <div>
            <p class="font-semibold">El estado de cuenta no se pudo procesar</p>
            <p>{{ errorMessage }}</p>
          </div>
        </div>
      </div>

      <div>
        <label for="sucursal" class="block text-sm font-medium text-gray-700"
          >Sucursal</label
        >
        <select
          id="sucursal"
          v-model="selectedSucursal"
          :disabled="isLoading"
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
          :disabled="isLoading"
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
          :disabled="!isFormValid || isLoading"
          class="w-full flex justify-center items-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:bg-gray-400 disabled:cursor-not-allowed transition-all duration-300"
          :class="
            isFormValid
              ? 'bg-green-500 hover:bg-green-600 focus:ring-green-500'
              : 'bg-theme-primary'
          "
        >
          <svg
            v-if="isLoading"
            class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          {{ isLoading ? "Revisando archivo..." : "Siguiente Paso" }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  emits: ["upload-success"],
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
      arrastrando: false,
    };
  },

  computed: {
    /**
     * Esta propiedad computada devuelve una lista de bancos
     * que depende de la sucursal seleccionada.
     */

    isFormValid() {
      return !!(this.selectedFile && this.selectedBank && this.selectedSucursal);
    },
    filteredBanks() {
      // Al devolver 'this.allBanks' directamente, la opción 'EXTERNO' 
      // estará disponible para todas las sucursales sin restricciones.
      return this.allBanks;
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
    zonaClase() {
      if (this.errorMessage) return "border-red-400 bg-red-50";
      if (this.arrastrando) return "border-green-500 bg-green-50";
      if (this.selectedFile) return "border-green-400";
      return "border-gray-300";
    },
  },
  watch: {
    /**
     * Este 'observador' se activa cada vez que 'selectedSucursal' cambia.
     */
    selectedSucursal() {
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
      if (!this.selectedFile) {
        return;
      }
      // ...y NO es válido para el nuevo banco seleccionado...
      if (!this.isFileValid(this.selectedFile)) {
        this.errorMessage = `El archivo ${this.selectedFile.name} no es un ${this.fileTypeHint}. Por favor, sube un archivo válido.`;
        this.limpiarArchivo();
      } else {
        this.errorMessage = ""; 
      }
    },
  },
  methods: {
    // 5. Método para manejar la carga del archivo
    handleFileUpload(event) {
      this.asignarArchivo(event.target.files[0], event.target);
    },

    handleDrop(event) {
      this.arrastrando = false;
      if (this.isLoading) return;
      const file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
      this.asignarArchivo(file, null);
    },

    asignarArchivo(file, input) {
      this.errorMessage = "";

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
        if (input) input.value = "";
      }
    },

    limpiarArchivo() {
      this.selectedFile = null;
      if (this.$refs.fileInput) {
        this.$refs.fileInput.value = "";
      }
    },
     // NUEVO: Método reutilizable para validar el archivo
    isFileValid(file) {
      const nombre = (file.name || "").toLowerCase();
      const isExcel = file.type === "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" || nombre.endsWith(".xlsx");
      const isPdf = file.type === "application/pdf" || nombre.endsWith(".pdf");

      return this.selectedBank === "EXTERNO" ? isExcel : isPdf;
    },

    // Mensaje claro a partir de la respuesta del servidor
    mensajeDeError(error) {
      const r = error && error.response;

      if (!r) {
        return "No se pudo comunicar con el servidor. Revisa tu conexión e inténtalo de nuevo.";
      }

      const data = r.data || {};

      // 422: validación (PDF como imagen, dañado, con contraseña, otro banco, formato incorrecto)
      if (r.status === 422) {
        const errores = data.errors || {};
        const primero = Object.values(errores)[0];
        return (errores.estado_de_cuenta && errores.estado_de_cuenta[0])
          || (Array.isArray(primero) ? primero[0] : null)
          || data.message
          || "El estado de cuenta no tiene el formato correcto.";
      }

      if (r.status === 413) {
        return "El archivo es demasiado grande. El máximo es 10 MB.";
      }

      if (r.status === 419) {
        return "Tu sesión expiró. Recarga la página e inténtalo de nuevo.";
      }

      return data.error || data.message || "Ocurrió un error inesperado al subir el estado de cuenta.";
    },

    submitForm() {
      if (!this.isFormValid || this.isLoading) return;

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
          this.errorMessage = this.mensajeDeError(error);

          // El archivo rechazado se quita para que el usuario elija el correcto
          if (error.response && error.response.status === 422) {
            this.limpiarArchivo();
          } else {
            console.error("Error al subir el estado de cuenta:", error);
          }

          alert(this.errorMessage);
        });
    },
  },
};
</script>
