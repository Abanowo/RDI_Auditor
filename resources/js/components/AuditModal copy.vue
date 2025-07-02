<template>
  <div
    v-if="show"
    @click.self="close"
    class="fixed inset-0 bg-black bg-opacity-60 z-50 flex justify-center items-center p-4"
  >
    <div
      class="bg-white rounded-lg shadow-2xl w-full max-w-6xl flex flex-col"
      style="height: 90vh"
    >
      <div class="flex justify-between items-center p-4 border-b flex-shrink-0">
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
        <button @click="close" class="text-gray-400 hover:text-gray-800 text-3xl">
          &times;
        </button>
      </div>

      <div class="flex-grow p-6 overflow-y-auto">
        <div v-if="isScAudit" class="flex flex-col lg:flex-row gap-6">
          <div class="w-full lg:w-1/2 space-y-4">
            <div class="bg-gray-50 p-4 rounded-lg">
              <p class="text-sm font-medium text-gray-500">Folio Factura SC</p>
              <p class="text-2xl font-semibold text-theme-dark">{{ scMainData.folio }}</p>
            </div>

            <div class="bg-blue-50 p-4 rounded-lg">
              <p class="text-sm font-medium text-blue-600">Saldo Total</p>
              <p class="text-2xl font-semibold text-blue-800">
                {{ scMainData.total | currency }}
                <span class="text-base font-normal">{{
                  auditData.factura.desglose_conceptos.moneda
                }}</span>
              </p>
            </div>
            <div class="bg-green-50 p-4 rounded-lg">
              <p class="text-sm font-medium text-green-600">Saldo Total (MXN)</p>
              <p class="text-2xl font-semibold text-green-800">
                {{ scMainData.total_mxn | currency }}
                <span class="text-base font-normal">MXN</span>
              </p>
            </div>

            <div class="border rounded-lg">
              <button
                @click="isDesgloseVisible = !isDesgloseVisible"
                class="w-full flex justify-between items-center p-3 font-bold text-left"
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
                    class="bg-white p-3 rounded shadow"
                  >
                    <p class="font-bold text-theme-secondary">{{ item.nombre }}</p>
                    <p class="text-sm">
                      <span class="font-semibold">Monto:</span>
                      {{ item.monto | currency }}
                    </p>
                    <p class="text-sm">
                      <span class="font-semibold">Monto MXN:</span>
                      {{ item.monto_mxn | currency }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div class="border rounded-lg">
              <button
                @click="isFacturasVisible = !isFacturasVisible"
                class="w-full flex justify-between items-center p-3 font-bold text-left"
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
                    class="p-3 rounded shadow transition-all"
                    :class="factura.encontrado ? 'bg-white' : 'bg-gray-200 opacity-70'"
                  >
                    <p
                      class="font-bold"
                      :class="
                        factura.encontrado ? 'text-theme-secondary' : 'text-gray-500'
                      "
                    >
                      {{ factura.nombre }}
                    </p>
                    <p class="text-sm">
                      <span class="font-semibold">Monto:</span>
                      {{ factura.monto | currency }}
                    </p>
                    <p class="text-sm">
                      <span class="font-semibold">Monto MXN:</span>
                      {{ factura.monto_mxn | currency }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="w-full lg:w-1/2 border rounded-md min-h-[500px] flex flex-col">
            <div
              v-if="isPdfLoading"
              class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center"
            >
              <div class="flex items-center space-x-2 text-gray-500">
                <div
                  class="w-6 h-6 border-2 border-t-theme-primary border-gray-200 rounded-full animate-spin"
                ></div>
                <span>Cargando PDF...</span>
              </div>
            </div>

            <div class="flex justify-evenly items-center my-4">
              <h4 class="font-bold text-lg">Visor de Documento</h4>
              <a
                v-if="pdfUrl"
                :href="pdfUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center space-x-2 px-3 py-1 text-xs font-semibold text-theme-primary bg-blue-50 hover:bg-blue-100 rounded-full transition"
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

            <iframe
              v-if="pdfUrl"
              :src="pdfUrl"
              class="w-full h-full flex-grow"
              :class="{ invisible: isPdfLoading }"
              @load="isPdfLoading = false"
            >
            </iframe>

            <p v-else class="text-center p-10 text-gray-500">
              No hay un PDF para mostrar.
            </p>
          </div>
        </div>

        <div v-else class="flex flex-col lg:flex-row gap-6">
          <div class="w-1/2 space-y-4">
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm font-medium text-gray-500">Folio Factura</p>
              <p class="text-lg">
                {{
                  auditData.factura
                    ? auditData.factura.folio
                      ? auditData.factura.folio
                      : "N/A"
                    : "N/A"
                }}
              </p>
            </div>
            <div class="bg-gray-50 p-3 rounded">
              <p class="text-sm font-medium text-gray-500">Monto Factura (MXN)</p>
              <p class="text-lg">
                {{
                  auditData.factura
                    ? formatPrice(auditData.factura.monto_total_mxn)
                    : "N/A"
                }}
              </p>
            </div>
            <div class="bg-blue-50 p-3 rounded">
              <p class="text-sm font-medium text-blue-500">Monto Esperado en SC (MXN)</p>
              <p class="text-lg">
                {{
                  auditData.sc
                    ? formatPrice(auditData.sc.monto_esperado)
                    : "SC no generada"
                }}
              </p>
            </div>
          </div>
          <div class="w-1/2 border rounded-md flex flex-col">
            <div
              v-if="isPdfLoading"
              class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center"
            >
              <div class="flex items-center space-x-2 text-gray-500">
                <div
                  class="w-6 h-6 border-2 border-t-theme-primary border-gray-200 rounded-full animate-spin"
                ></div>
                <span>Cargando PDF...</span>
              </div>
            </div>

            <div class="flex justify-evenly items-center my-4">
              <h4 class="font-bold text-lg">Visor de Documento</h4>
              <a
                v-if="pdfUrl"
                :href="pdfUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center space-x-2 px-3 py-1 text-xs font-semibold text-theme-primary bg-blue-50 hover:bg-blue-100 rounded-full transition"
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

            <iframe
              v-if="pdfUrl"
              :src="pdfUrl"
              class="w-full h-full flex-grow"
              :class="{ invisible: isPdfLoading }"
              @load="isPdfLoading = false"
            >
            </iframe>

            <p v-else class="text-center p-10 text-gray-500">
              No hay un PDF para mostrar.
            </p>
          </div>
        </div>
      </div>

      <div class="p-4 border-t text-right flex-shrink-0">
        <button @click="close" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">
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
      isPdfLoading: false, //Para la carga del PDF
    };
  },
  computed: {
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
        folio: factura?.folio_documento || "N/A",
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
    montoEsperadoSc() {
      if (this.isScAudit) return {};
      // Reutilizamos la info que ya pasábamos
      return {
        mxn: this.auditData.sc?.monto_esperado,
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
      return parseFloat(this.montoFactura.mxn) === parseFloat(this.montoEsperadoSc.mxn);
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
    pdfUrl() {
      // Primero, determinamos cuál es el registro de la factura que estamos viendo
      const factura = this.auditData?.factura;

      // Si no hay factura o no tiene ruta_pdf, no hacemos nada.
      if (!factura || !factura.ruta_pdf) {
        return null;
      }

      // Determinamos el 'tipo' basado en si es una auditoría de SC o no
      const tipo = this.isScAudit ? "sc" : "auditoria";
      const id = factura.id;

      // Construimos la URL con los parámetros que nuestro controlador espera
      return `/documentos/ver?tipo=${tipo}&id=${id}`;
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
              monto: factura.monto_total | currency,
              monto_mxn: factura.monto_total_mxn | currency,
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
    pdfUrl(newUrl, oldUrl) {
      if (newUrl) {
        this.isPdfLoading = true;
      }
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
    formatPdfUrl(path) {
      // Esta función es un EJEMPLO. Necesitarás una ruta real en Laravel para servir los PDFs.
      // Por ejemplo, si el id de la auditoría es 1380...
      const fileId = this.auditData.factura.id;
      return `/documentos/ver/${fileId}`; // ...la URL debería ser algo como esto.
    },
  },
};
</script>
