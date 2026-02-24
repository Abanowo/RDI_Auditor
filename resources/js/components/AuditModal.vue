<template>
  <div
    v-if="show"
    @click.self="close"
    class="fixed inset-0 bg-black bg-opacity-60 z-50 flex justify-center items-center p-4 transition-opacity"
  >
    <div
      class="bg-gray-50 rounded-lg shadow-2xl w-11/12 max-w-7xl flex flex-col overflow-hidden relative"
      style="height: 90vh"
    >
      <div class="flex justify-between items-center p-4 border-b bg-white flex-shrink-0">
        <h3 class="text-xl font-bold text-theme-dark">
          Detalle de Auditoría:
          <span class="text-theme-primary"
            >{{ auditTitle.toUpperCase() }} -
            {{
              scMainData.pedimento
                ? scMainData.pedimento
                : auditData.operacion
                ? auditData.operacion.pedimento
                  ? auditData.operacion.pedimento
                  : "N/A"
                : "N/A"
            }}</span
          >
        </h3>
        <button @click="close" class="text-gray-400 hover:text-gray-800 text-3xl transition-colors">
          &times;
        </button>
      </div>

      <div class="flex-grow p-4 md:p-6 overflow-y-auto">
        <div v-if="isScAudit" class="grid grid-cols-1 lg:grid-cols-12 gap-6 h-full">
          <div class="lg:col-span-5 flex flex-col space-y-4">
            <div class="bg-white border border-gray-200 p-4 rounded-lg shadow-sm">
              <p class="text-sm font-medium text-gray-500">Folio Factura SC</p>
              <p class="text-2xl font-semibold text-theme-dark">{{ scMainData.folio }}</p>
            </div>

            <div class="bg-blue-50 border border-blue-100 p-4 rounded-lg shadow-sm">
              <p class="text-sm font-medium text-blue-600">Saldo Total</p>
              <p class="text-2xl font-semibold text-blue-800">
                {{ scMainData.total | currency }}
                <span class="text-base font-normal">{{
                  auditData.factura.desglose_conceptos.moneda
                }}</span>
              </p>
            </div>
            <div class="bg-green-50 border border-green-100 p-4 rounded-lg shadow-sm">
              <p class="text-sm font-medium text-green-600">Saldo Total (MXN)</p>
              <p class="text-2xl font-semibold text-green-800">
                {{ scMainData.total_mxn | currency }}
                <span class="text-base font-normal">MXN</span>
              </p>
            </div>

            <div class="border border-gray-200 bg-white rounded-lg shadow-sm mt-2">
              <button
                @click="isDesgloseVisible = !isDesgloseVisible"
                class="w-full flex justify-between items-center p-3 font-bold text-left hover:bg-gray-50 rounded-t-lg transition-colors"
              >
                <span>Montos de Facturas - SC</span>
                <span
                  :class="{ 'transform rotate-180': isDesgloseVisible }"
                  class="transition-transform duration-300"
                  >&#9660;</span
                >
              </button>
              <div v-if="isDesgloseVisible" class="p-4 border-t bg-gray-50">
                <div class="grid grid-cols-2 gap-3">
                  <div
                    v-for="item in desgloseMontos"
                    :key="item.nombre"
                    class="bg-white p-3 rounded border shadow-sm"
                  >
                    <p class="font-bold text-theme-secondary text-xs">{{ item.nombre }}</p>
                    <p class="text-sm">
                      <span class="font-semibold text-xs text-gray-500">Monto:</span>
                      {{ item.monto | currency }}
                    </p>
                    <p class="text-sm">
                      <span class="font-semibold text-xs text-gray-500">Monto MXN:</span> 
                      {{ item.monto_mxn | currency }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div class="border border-gray-200 bg-white rounded-lg shadow-sm">
              <button
                @click="isFacturasVisible = !isFacturasVisible"
                class="w-full flex justify-between items-center p-3 font-bold text-left hover:bg-gray-50 rounded-t-lg transition-colors"
              >
                <span>Montos de Facturas - Externas</span>
                <span
                  :class="{ 'transform rotate-180': isFacturasVisible }"
                  class="transition-transform duration-300"
                  >&#9660;</span
                >
              </button>
              <div v-if="isFacturasVisible" class="p-4 border-t bg-gray-50">
                <div class="grid grid-cols-2 gap-3">
                  <div
                    v-for="factura in facturasRealesData"
                    :key="factura.nombre"
                    class="p-3 rounded border shadow-sm transition-all"
                    :class="factura.encontrado ? 'bg-white' : 'bg-gray-200 opacity-70'"
                  >
                    <p
                      class="font-bold text-xs" 
                      :class="
                        factura.encontrado ? 'text-theme-secondary' : 'text-gray-500'
                      "
                    >
                      {{ factura.nombre }}
                    </p>
                    <p class="text-sm">
                      <span class="font-semibold text-xs text-gray-500">Monto:</span>
                      {{ factura.monto | currency }}
                    </p>
                    <p class="text-sm">
                      <span class="font-semibold text-xs text-gray-500">Monto MXN:</span> 
                      {{ factura.monto_mxn | currency }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="lg:col-span-7 bg-white border border-gray-200 rounded-lg shadow-sm flex flex-col h-full min-h-[500px]">
            <div class="flex justify-between items-center p-3 border-b bg-gray-50 rounded-t-lg">
              <h4 class="font-bold text-gray-800 text-lg">Visor de Documento</h4>
              <a
                :href="documentUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center space-x-2 px-3 py-1 text-xs font-semibold text-theme-primary bg-blue-100 hover:bg-blue-200 rounded-full transition"
              >
                <span>Abrir en nueva pestaña</span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="h-4 w-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                  />
                </svg>
              </a>
            </div>

            <div v-if="isDocumentLoading" class="text-center p-10 text-gray-500 flex-grow flex items-center justify-center">
              <p>Cargando documento...</p>
            </div>

            <!-- ✅ LÓGICA DEL VISOR CORREGIDA -->
            <div v-else class="flex-grow relative bg-gray-500 rounded-b-lg overflow-hidden">
              <!-- Caso 1: El documento es un PDF, lo mostramos en el iframe -->
              <iframe
                v-if="documentType === 'pdf'"
                :src="documentUrl"
                class="w-full h-full border-0 absolute inset-0"
              ></iframe>
              <p
                v-else-if="documentType === 'xlsx'"
                class="text-center p-10 text-white mt-10"
              >
                No se puede visualizar este formato de estado de cuenta.
              </p>

              <!-- Caso 3: Cualquier otro caso (no hay doc, formato desconocido, etc.) -->
              <p v-else class="text-center p-10 text-white mt-10">
                No hay un documento para mostrar o el formato no es soportado.
              </p>
            </div>
          </div>
        </div>

        <div v-else class="grid grid-cols-1 lg:grid-cols-12 gap-6 h-full">
          <div class="lg:col-span-5 flex flex-col space-y-4">
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
              <div class="bg-white border border-gray-200 p-4 rounded-lg shadow-sm">
                <h4 class="font-bold text-gray-700 mb-3 border-b pb-1">Datos Generales</h4>
                <div class="space-y-3 text-sm">
                  <div>
                    <p class="font-semibold text-xs text-gray-500 uppercase tracking-wide">Fecha de Creación</p>
                    <p class="text-gray-800">{{ datosGeneralesFactura.fecha_creacion }}</p>
                  </div>
                  <div>
                    <p class="font-semibold text-xs text-gray-500 uppercase tracking-wide">Fecha del Documento</p>
                    <p class="text-gray-800">{{ datosGeneralesFactura.fecha_documento }}</p>
                  </div>
                  <div>
                    <p class="font-semibold text-xs text-gray-500 uppercase tracking-wide">Folio</p>
                    <p class="text-gray-800">{{ datosGeneralesFactura.folio }}</p>
                  </div>
                  <div>
                    <p class="font-semibold text-xs text-gray-500 uppercase tracking-wide">Estado</p>
                    <p :class="getEstadoClass(datosGeneralesFactura.estado)">
                      {{ datosGeneralesFactura.estado }}
                    </p>
                  </div>
                </div>
              </div>
              <div class="bg-white border border-gray-200 p-4 rounded-lg shadow-sm relative flex flex-col">
                <div class="flex justify-between items-center mb-3 border-b pb-1">
                  <h4 class="font-bold text-gray-700">Auditoría de Montos</h4>
                  <span
                  v-if="auditData.sc.tipo_cambio !== 'N/A'"
                    class="text-xs font-bold text-blue-800 bg-blue-100 px-2 py-0.5 rounded-full"
                  >
                    TC: {{ auditData.sc.tipo_cambio }}
                  </span>
                </div>
                <div class="flex flex-col space-y-3 flex-grow justify-center">
                  <div class="bg-gray-50 p-3 rounded border flex justify-between items-center">
                    <div>
                      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Monto Factura</p>
                      <p class="text-lg font-bold text-gray-800">
                        {{ montoFactura.original | currency }}
                        <span class="text-sm font-normal">{{ 
                          montoFactura.moneda
                          }}</span>
                      </p>
                      <p class="text-xs text-gray-500">
                        ({{ montoFactura.mxn | currency }} MXN)
                      </p>
                    </div>
                  </div>
                  <div class="bg-blue-50 border border-blue-100 p-3 rounded flex justify-between items-center relative">
                    <div>
                      <p class="text-xs font-semibold text-blue-600 uppercase tracking-wide">Monto Esperado en SC</p>
                      <p class="text-lg font-bold text-blue-900">
                        {{ montoEsperadoSc.original | currency }}
                        <span class="text-sm font-normal">{{
                          montoEsperadoSc.moneda
                        }}</span>
                      </p>
                      <p class="text-xs text-blue-600">
                        ({{ montoEsperadoSc.mxn | currency }} MXN)
                      </p>
                    </div>
                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                      <div
                        v-if="montosCoinciden"
                        class="w-8 h-8 bg-green-100 border border-green-200 rounded-full flex items-center justify-center shadow-sm"
                        title="Coinciden"
                      >
                        <svg
                          class="w-5 h-5 text-green-600"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M5 13l4 4L19 7"
                            ></path>
                        </svg>
                      </div>
                      <div
                        v-else
                        class="w-8 h-8 bg-red-100 border border-red-200 rounded-full flex items-center justify-center shadow-sm"
                        title="No coinciden"
                      >
                        <svg
                          class="w-5 h-5 text-red-600"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                          ></path>
                        </svg>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div
            class="lg:col-span-7 bg-white border border-gray-200 rounded-lg shadow-sm flex flex-col h-full min-h-[500px]">
            <div class="flex justify-between items-center p-3 border-b bg-gray-50 rounded-t-lg">
              <h4 class="font-bold text-gray-800 text-lg">Visor de Documento</h4>
              <a
                :href="documentUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center space-x-2 px-3 py-1 text-xs font-semibold text-theme-primary bg-blue-100 hover:bg-blue-200 rounded-full transition"
              >
                <span>Abrir en nueva pestaña</span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="h-4 w-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"
                  />
                </svg>
              </a>
            </div>

            <div v-if="isDocumentLoading"
              class="text-center p-10 text-gray-500 flex-grow flex items-center justify-center">
              <p>Cargando documento...</p>
            </div>

            <!-- ✅ LÓGICA DEL VISOR CORREGIDA -->
            <div v-else class="flex-grow relative bg-gray-500 rounded-b-lg overflow-hidden">
              <!-- Caso 1: El documento es un PDF, lo mostramos en el iframe -->
              <iframe
                v-if="documentType === 'pdf'"
                :src="documentUrl"
                class="w-full h-full border-0 absolute inset-0"
              ></iframe>

              <!-- Caso 2: El documento es un XLSX, mostramos el mensaje personalizado -->
              <p
                v-else-if="documentType === 'xlsx'"
                class="text-center p-10 text-white mt-10"
              >
                No se puede visualizar este formato de estado de cuenta.
              </p>

              <!-- Caso 3: Cualquier otro caso (no hay doc, formato desconocido, etc.) -->
              <p v-else class="text-center p-10 text-white mt-10">
                No hay un documento para mostrar o el formato no es soportado.
              </p>
            </div>
          </div>
        </div>
      </div>

      <div class="p-4 border-t bg-gray-100 text-right flex-shrink-0">
        <button @click="close"
          class="px-6 py-2 bg-white border border-gray-300 font-semibold text-gray-700 rounded shadow-sm hover:bg-gray-50 transition-colors">
          Cerrar
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { isLength } from "lodash";
import { countBy } from "lodash";
import { round } from "lodash";

export default {
  props: {
    show: { type: Boolean, default: false },
    auditData: { type: Object, default: () => null },
  },
  data() {
    return {
      isDesgloseVisible: false, // Para el primer desplegable (Montos SC)
      isFacturasVisible: false, // Para el NUEVO desplegable (Facturas Reales)
      isDocumentLoading: false, //Para la carga del PDF o del XLSX
      documentType: null, // NUEVO: para almacenar 'pdf', 'xlsx', etc.
    };
  },
  computed: {
    /**
     * Construye la URL para el visor de Microsoft Office Online.
     * Es importante codificar la URL del documento para que funcione correctamente.
     */
    excelViewerUrl() {
      if (this.documentType !== "xlsx" || !this.documentUrl) {
        return "";
      }
      // Construimos la URL para el visor de Office Online
      const encodedUrl = encodeURIComponent(window.location.origin + this.documentUrl);
      return `https://view.officeapps.live.com/op/embed.aspx?src=${encodedUrl}`;
    },

    // 1. ¿Estamos auditando una SC? (Devuelve true/false)
    isScAudit() {
      // Usamos 'optional chaining' (?.) para evitar errores si algo es null
      return this.auditData?.tipo === "sc";
    },

    // 2. Título dinámico para el modal
    auditTitle() {
      return this.auditData ? this.auditData.tipo : "";
    },

    // 3. Preparamos los datos principales de la SC para mostrarlos fácilmente
    scMainData() {
      if (!this.isScAudit) return {};
      const factura = this.auditData.factura;
      const pedimento = this.auditData.operacion?.pedimento;
      const montos = factura?.desglose_conceptos?.montos || {};

      return {
        folio: factura?.folio || "N/A",
        pedimento: pedimento,
        total: montos.sc,
        total_mxn: montos.sc_mxn,
      };
    },
    /**
     * Prepara los datos generales de la factura que estamos viendo.
     */
    datosGeneralesFactura() {
      if (this.isScAudit || !this.auditData?.factura) return {};
      const factura = this.auditData.factura;
      return {
        // Formateamos las fechas para que sean más legibles
        fecha_creacion: new Date(factura.created_at).toLocaleDateString("es-MX"),
        fecha_documento: factura.fecha_documento,
        folio: factura.folio || "N/A",
        estado: factura.estado || "N/A",
      };
    },

    /**
     * NUEVA: Prepara los montos de la factura actual.
     */
    montoFactura() {
      if (this.isScAudit || !this.auditData?.factura) return {};
      return {
        original: this.auditData.factura.monto_total,
        mxn: this.auditData.factura.monto_total_mxn,
        moneda: this.auditData.factura.moneda_documento,
      };
    },

    /**
     * NUEVA: Prepara el monto esperado que viene de la SC.
     */
    // Dentro de computed: { ... } en AuditModal.vue
    montoEsperadoSc() {
      if (this.isScAudit || !this.auditData?.sc) return {};

      const tipoFactura = this.auditData.tipo.replace(/(\s*#\d*)/g, "");
      const montos = this.auditData.sc.montos_esperados;

      return {
        tipo: tipoFactura,
        original: montos[tipoFactura] || -1,
        mxn: montos[`${tipoFactura}_mxn`] || -1,
        moneda: this.auditData.sc.moneda_original || this.auditData.sc.desglose_conceptos?.moneda || 'MXN',
      };
    },

    /**
     * NUEVA: Compara si los montos en MXN coinciden. Devuelve true o false.
     */
    montosCoinciden() {
      if (
        !this.montoFactura?.mxn ||
        !this.montoEsperadoSc?.mxn ||
        this.montoEsperadoSc.mxn === "N/A"
      ) {
        return false;
      }
      // Comparamos los valores numéricos
      return parseFloat(this.montoEsperadoSc.mxn) >= parseFloat(this.montoFactura.mxn);
    },

    // 4. Creamos un array limpio para iterar y mostrar los montos desglosados
    desgloseMontos() {
      if (!this.isScAudit) return [];

      const montos = this.auditData.factura.desglose_conceptos?.montos || {};

      // Lista de conceptos que queremos mostrar en las mini-cartas
      const conceptos = [
        "impuestos",
        "flete",
        "llc",
        "pago_derecho",
        "maniobras",
        "muestras",
        "termo",
        "rojos",
      ];

      return conceptos
        .filter((concepto) => montos.hasOwnProperty(concepto)) // Nos aseguramos que el monto exista
        .map((concepto) => ({
          nombre: concepto.charAt(0).toUpperCase() + concepto.slice(1), // Capitalizamos el nombre
          monto: montos[concepto],
          monto_mxn: montos[`${concepto}_mxn`], // Buscamos su contraparte _mxn
        }));
    },

    // 5. El visor de PDF
    documentUrl() {
      // Primero, determinamos cuál es el registro de la factura que estamos viendo
      const factura = this.auditData?.factura;

      // Si no hay factura o no tiene ruta_pdf, no hacemos nada.
      if (!factura || !factura.ruta_pdf) {
        return null;
      }

      // Obtenemos el tipo y el ID
      const tipo = this.auditData?.tipo.replace(/(\s*#\d*)/g, ""); // Limpiamos el tipo (ej. 'pago_derecho #1' -> 'pago_derecho')
      const id = factura.id;

      // Lógica condicional para manejar los dos tipos de documentos
      if (tipo === "impuestos") {
        // Para 'impuestos', usamos la ruta que busca el archivo local por ID, como antes.
        return `/documentos/ver/${tipo}/${id}`;
      } else {
        // Para todos los demás (flete, llc, etc.), que son URLs externas,
        // usamos una nueva ruta 'proxy' para evitar problemas de CORS.
        // Nos aseguramos de que la ruta_pdf exista.
        if (!factura.ruta_pdf) return null;
        // Codificamos la URL externa para pasarla de forma segura como un parámetro.
        return `/documentos/proxy?url=${encodeURIComponent(factura.ruta_pdf)}`;
      }
    },

    /**
     * NUEVA PROPIEDAD COMPUTADA: Prepara los datos de las facturas reales encontradas.
     */
    facturasRealesData() {
      if (!this.isScAudit) return [];

      const statusBotones = this.auditData.operacion?.status_botones || {};
      const resultado = [];
      const conceptos = [
        "impuestos",
        "flete",
        "llc",
        "pago_derecho",
        "maniobras",
        "muestras",
        "termo",
        "rojos",
      ];

      conceptos.forEach((tipo) => {
        const info = statusBotones[tipo];
        const datos = info?.datos;

        if (Array.isArray(datos)) {
          // Caso especial para múltiples facturas (pago_derecho)
          datos.forEach((factura, index) => {
            resultado.push({
              nombre: `${this.capitalize(tipo).replace("_", " ")} #${index + 1}`,
              monto: factura.monto_total,
              monto_mxn: factura.monto_total_mxn,
              encontrado: true,
            });
          });
        } else if (datos) {
          // Caso para una factura única encontrada
          resultado.push({
            nombre: this.capitalize(tipo).replace("_", " "),
            monto: datos.monto_total,
            monto_mxn: datos.monto_total_mxn,
            encontrado: true,
          });
        } else {
          // Caso para una factura no encontrada
          resultado.push({
            nombre: this.capitalize(tipo).replace("_", " "),
            monto: "N/A",
            monto_mxn: "N/A",
            encontrado: false,
          });
        }
      });
      return resultado;
    },
  },
  watch: {
    // <-- AÑADE TODA ESTA SECCIÓN
    /**
     * Observa si la URL del PDF cambia.
     * Si cambia, activamos el estado de carga.
     */
    documentUrl: {
      // Hacemos el handler asíncrono para poder usar await
      async handler(newUrl) {
        // Si no hay URL, limpiamos todo y salimos.
        if (!newUrl) {
          this.documentType = null;
          this.isDocumentLoading = false;
          return;
        }

        this.isDocumentLoading = true;
        this.documentType = null; // Reiniciamos el tipo de documento

        try {
          // Construimos la URL para pedir solo la información del tipo de archivo
          const infoUrl = newUrl.includes("?")
            ? `${newUrl}&info=true`
            : `${newUrl}?info=true`;

          const response = await fetch(infoUrl);
          if (!response.ok) {
            throw new Error("No se pudo obtener la información del documento.");
          }

          const data = await response.json();
          // Asignamos el tipo de archivo que nos devolvió Laravel ('pdf', 'xlsx', etc.)
          this.documentType = data.tipo_archivo;
        } catch (error) {
          console.error("Error al verificar el tipo de documento:", error);
          this.documentType = "unknown"; // Marcamos como desconocido si hay un error
        } finally {
          // Al final, independientemente del resultado, quitamos el estado de carga.
          this.isDocumentLoading = false;
        }
      },
      immediate: true, // Descomenta si quieres que se ejecute al montar el componente
    },
  },
  methods: {
    formatPrice(value) {
      let val = (value / 1).toFixed(2);
      return val.toString();
    },
    close() {
      // Avisa al componente padre que debe cerrar el modal
      this.$emit("close");
      this.isDesgloseVisible = false;
      this.isFacturasVisible = false; // Reseteamos también el nuevo desplegable
    },
    capitalize(s) {
      if (typeof s !== "string") return "";
      return s.charAt(0).toUpperCase() + s.slice(1);
    },
    /**
     * NUEVO MÉTODO: Devuelve la clase de color correcta
     * para el texto del estado de la factura.
     */
    getEstadoClass(estado) {
      if (!estado) {
        return "text-gray-900 font-bold"; // Caso por defecto si no hay estado
      }

      const estadoLower = estado.toString().toLowerCase();

      if (estadoLower === "coinciden!") {
        return "text-green-600 font-bold"; // Verde
      } else if (estadoLower === "pago de mas!") {
        return "text-yellow-600 font-bold"; // Naranja
      } else if (estadoLower === "pago de menos!" || estadoLower.includes("sin")) {
        return "text-red-600 font-bold"; // Rojo
      } else {
        return "text-gray-900 font-bold"; // Negro y en negritas para cualquier otro caso
      }
    },
  },
};
</script>
