<template>
  <!-- Contenedor principal con fondo dinámico y padding reducido -->
  <div
    class="operacion-card w-full shadow-md rounded-lg flex space-x-3 p-2"
    :class="cardBgClass"
  >
    <!-- ================================================== -->
    <!-- COLUMNA IZQUIERDA (Logo, Contador, Tipo) ~15%      -->
    <!-- ================================================== -->
    <div class="w-[15%] flex-shrink-0 flex flex-col">
      <!-- Fila Superior: Contador y Tipo de Operación -->
      <div class="flex items-center space-x-2 mb-1">
        <span
          class="bg-blue-800 text-white font-bold text-xs rounded h-5 w-auto px-2 flex items-center justify-center"
        >
          {{ displayNumber }}
        </span>
        <span
          class="font-bold text-[11px] rounded px-1.5 py-0.5"
          :class="operationTypeClass"
        >
          {{ operationType }}
        </span>
      </div>
      <!-- Logo del Cliente -->
      <div
        class="w-16 h-16 md:w-full md:h-auto md:aspect-square bg-gray-200 rounded-md flex items-center justify-center content-center border border-gray-300 order-1 md:order-2 mr-4 md:mr-0"
        title="Logo del Cliente"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="h-20 w-20 text-gray-400"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0v-4a2 2 0 012-2h6a2 2 0 012 2v4m-6 0h-2"
          />
        </svg>
      </div>
    </div>

    <!-- ================================================== -->
    <!-- COLUMNA DERECHA (Info Principal y Facturas) ~85%   -->
    <!-- ================================================== -->
    <div class="flex-grow flex flex-col space-y-1.5">
      <!-- Info Principal de la Operación con fondo para destacar -->
      <div
        class="inline-block bg-white rounded-md px-3 py-1 shadow-sm"
        :class="cardInformationBgClass"
      >
        <p class="font-bold text-base leading-tight">
          {{ operacion.pedimento }} - {{ operacion.cliente }}
        </p>
        <p class="text-xs">{{ operacion.fecha_edc }}</p>
      </div>

      <!-- Grid de Facturas (5 columnas) -->
      <div class="grid grid-cols-2 sm:grid-cols-1 lg:grid-cols-5 gap-1.5">
        <!-- Bucle para cada tipo de documento -->
        <template v-for="(info, tipo) in operacion.status_botones">
          <div
            :key="tipo"
            class="border rounded-md shadow-sm overflow-hidden flex flex-col bg-white text-[11px]"
            :class="getCardBorderClass(getFacturaAuditoriaStatusText(tipo, info))"
          >
            <!-- Cabecera de la tarjeta de factura -->
            <div
              class="p-1 flex justify-between items-center"
              :class="getCardHeaderBgClass(getFacturaAuditoriaStatusText(tipo, info))"
            >
              <button
                @click="
                  $emit('open-modal', {
                    tipo,
                    info,
                    operacion: operacion,
                    sc: operacion.status_botones.sc.datos,
                  })
                "
                :class="getStatusButtonClass(info.estado)"
                class="h-8 px-2 rounded text-white font-bold transition-all duration-200 shadow"
              >
                {{ tipo.toUpperCase().replace("_", " ") }}
              </button>
              <p class="font-semibold text-gray-800">
                <span class="font-normal text-gray-500">Folio:</span>
                {{ (info.datos && info.datos.folio) || "N/A" }}
              </p>
            </div>
            <!-- Cuerpo de la tarjeta de factura -->
            <div class="p-1 flex justify-between items-center flex-grow">
              <p
                class="font-bold"
                :class="getStatusTextClass(getFacturaStatusText(tipo, info))"
              >
                {{ getFacturaStatusText(tipo, info) }}
              </p>
              <p v-if="info.datos" class="text-gray-400">
                {{ formatDate(info.datos.fecha_documento) }}
              </p>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    operacion: { type: Object, required: true },
    itemIndex: { type: Number, required: true },
    pageFrom: { type: Number, required: true },
  },
  emits: ["open-modal"],
  computed: {
    displayNumber() {
      return this.pageFrom + this.itemIndex;
    },

    operationType() {
      if (this.operacion.tipo_operacion.toLowerCase().includes("import"))
        return "IMPORTACIÓN";
      if (this.operacion.tipo_operacion.toLowerCase().includes("export"))
        return "EXPORTACIÓN";
      return "N/A";
    },

    operationTypeClass() {
      if (this.operationType.startsWith("IMP")) return "bg-blue-100 text-blue-800";
      if (this.operationType.startsWith("EXP")) return "bg-purple-100 text-purple-800";
      return "bg-gray-200 text-gray-800";
    },

    cardOverallState() {
      const statuses = Object.values(this.operacion.status_botones);
      // 3. LÓGICA "SIN SC": Verificamos si hay un error rojo que NO sea 'Sin SC'
      const hasCriticalRed = statuses.some((statusInfo) => {
        const estadoTexto = this.getFacturaStatusText(
          Object.keys(this.operacion.status_botones).find(
            (key) => this.operacion.status_botones[key] === statusInfo
          ),
          statusInfo
        );
        return this.isStatusRed(estadoTexto) && estadoTexto.toLowerCase() !== "sin sc!";
      });

      if (hasCriticalRed) return "rojo";

      const hasPagoDeMas = statuses.some((statusInfo) => {
        const estadoTexto = this.getFacturaStatusText(
          Object.keys(this.operacion.status_botones).find(
            (key) => this.operacion.status_botones[key] === statusInfo
          ),
          statusInfo
        );
        return estadoTexto.toLowerCase() === "pago de mas!";
      });

      if (hasPagoDeMas) return "amarillo";

      // Si no hay errores críticos, verificamos si existe un 'Sin SC' para dejar el fondo blanco
      const hasSinSc = statuses.some((statusInfo) => {
        const estadoTexto = this.getFacturaStatusText(
          Object.keys(this.operacion.status_botones).find(
            (key) => this.operacion.status_botones[key] === statusInfo
          ),
          statusInfo
        );
        return estadoTexto.toLowerCase() === "sin sc!";
      });

      if (hasSinSc) return "neutro"; // Estado neutro para fondo blanco

      return "verde";
    },

    cardBgClass() {
      switch (this.cardOverallState) {
        case "rojo":
          return "bg-red-100";
        case "verde":
          return "bg-green-50";
        case "amarillo":
          return "bg-yellow-50";
        case "neutro":
          return "bg-gray-100"; // Fondo blanco si el estado es 'neutro'
        default:
          return "bg-white";
      }
    },

    cardInformationBgClass() {
      switch (this.cardOverallState) {
        case "rojo":
          return "bg-red-600 bg-opacity-75 text-white";
        case "verde":
          return "bg-green-500 bg-opacity-75 text-white";
        case "amarillo":
          return "bg-yellow-500 bg-opacity-75 text-white";
        case "neutro":
          return "bg-gray-600 bg-opacity-75 text-white";
        default:
          return "bg-white text-gray-600";
      }
    },
  },

  methods: {
    getFacturaStatusText(tipo, info) {
      if (tipo === "sc") {
        return info.datos && info.datos.desglose_conceptos
          ? "SC Encontrada"
          : "No Encontrado";
      }
      return info.datos ? info.datos.estado : "No Encontrado";
    },

    isStatusRed(estado) {
      if (!estado) return false;
      const estadoLower = estado.toLowerCase();
      return (
        estadoLower.includes("pago de menos") ||
        (estadoLower.includes("sin") && !estadoLower.includes("sc")) ||
        estadoLower.includes("intactics")
      );
    },

    isStatusGray(estado) {
      if (!estado) return false;
      const estadoLower = estado.toLowerCase();
      return estadoLower.includes("sin sc") || estadoLower.includes("no encontrado");
    },

    getCardHeaderBgClass(estado) {
      switch (estado) {
        case "verde":
          return "bg-green-50";
        case "amarillo":
          return "bg-yellow-50";
        case "rojo":
          return "bg-red-50";
        default:
          return "bg-gray-100";
      }
    },

    getCardBorderClass(estado) {
      switch (estado) {
        case "verde":
          return "border-green-300";
        case "amarillo":
          return "border-yellow-400";
        case "rojo":
          return "border-red-400";
        default:
          return "border-gray-200";
      }
    },

    getStatusButtonClass(estado) {
      switch (estado) {
        case "verde":
          return "bg-green-500 hover:bg-green-600";
        case "amarillo":
          return "bg-yellow-400 hover:bg-yellow-500";
        case "rojo":
          return "bg-red-500 hover:bg-red-600";
        case "gris":
          return "bg-gray-400 cursor-not-allowed";
        default:
          return "bg-gray-700 hover:bg-gray-800";
      }
    },

    getStatusTextClass(estado) {
      if (!estado) return "text-gray-800";
      const estadoLower = estado.toLowerCase();
      if (estadoLower.includes("coinciden") || estadoLower.includes("encontrada")) {
        return "text-green-700";
      } else if (estadoLower.includes("pago de mas")) {
        return "text-yellow-600";
      } else if (this.isStatusRed(estado) || estadoLower.includes("sin")) {
        return "text-red-700";
      } else if (estadoLower.includes("expo")) {
        return "text-indigo-700";
      } else {
        return "text-gray-800";
      }
    },

    getFacturaAuditoriaStatusText(tipo, info) {
      const estadoTexto = this.getFacturaStatusText(tipo, info);
      const estadoLower = estadoTexto.toLowerCase();
      if (estadoTexto) {
        if (tipo === "sc") {
          if (estadoLower.includes("coinciden") || estadoLower.includes("encontrada")) {
            return "verde";
          } else {
            return "gris";
          }
        } else {
          if (
            estadoLower.includes("coinciden") ||
            estadoLower.includes("encontrada") ||
            estadoLower.includes("expo") ||
            estadoLower.includes("impo")
          ) {
            return "verde";
          } else if (estadoLower.includes("pago de mas")) {
            return "amarillo";
          } else if (this.isStatusRed(estadoTexto)) {
            return "rojo";
          } else {
            return "neutral";
          }
        }
      }
    },

    formatDate(dateString) {
      if (!dateString) return "";
      const date = new Date(dateString);
      const options = { year: "numeric", month: "2-digit", day: "2-digit" };
      return date.toLocaleDateString("es-MX", options);
    },
  },
};
</script>
