<template>
  <!-- Contenedor principal con fondo dinámico y padding reducido -->
  <div
    class="operacion-card relative w-full shadow-md rounded-lg flex space-x-3 p-2 transition-all duration-300 hover:shadow-lg"
    :class="cardBgClass"
  >
    <!-- ================================================== -->
    <!-- COLUMNA IZQUIERDA (Logo, Contador, Tipo) ~15%      -->
    <!-- ================================================== -->
    <div class="w-[15%] flex-shrink-0 flex flex-col">
      <!-- Fila Superior: Contador y Tipo de Operación -->
      <div class="flex items-center space-x-2 mb-1">
        <span
          class="bg-blue-800 text-white font-bold text-xs rounded h-5 w-auto px-2 flex items-center justify-center shadow-sm"
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
        class="relative group w-16 h-16 md:w-full md:h-auto md:aspect-square bg-gray-200 rounded-md flex items-center justify-center content-center border border-gray-300 order-1 md:order-2 mr-4 md:mr-0 overflow-hidden"
        title="Logo del Cliente"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="h-20 w-20 text-gray-400 group-hover:opacity-30 transition-opacity duration-200"
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

        <div
          class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 cursor-pointer bg-black/5"
          @click.stop="$emit('preview-pdf', operacion)"
        >
          <div class="bg-white p-1.5 rounded-full shadow-md hover:scale-110 transition-transform">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <!-- ================================================== -->
    <!-- COLUMNA DERECHA (Info Principal y Facturas) ~85%   -->
    <!-- ================================================== -->
    <div class="flex-grow flex flex-col space-y-1.5">
      <!-- Info Principal de la Operación -->
      <div class="flex items-start justify-between gap-2">
        <div class="inline-block bg-white rounded-md px-3 py-1 shadow-sm">
          <p class="font-bold text-base leading-tight">
            {{ operacion.pedimento }} - {{ operacion.cliente }}
          </p>
          <p class="text-xs text-gray-600 flex items-center">
            {{ operacion.fecha_edc }}
          </p>
        </div>

        <!-- Acciones de GPC: solo para sucursales que tienen GPC (Manzanillo, Nogales, Laredo, Tijuana, Mexicali) -->
        <div v-if="tieneGPC" class="flex items-center space-x-1 flex-shrink-0">
          <!-- Botón "Llenar GPC": el backend decide el formato (ZLO o multi-sucursal) según la sucursal -->
          <button
            @click.stop="!fillingGpc && $emit('fill-gpc', operacion)"
            :disabled="fillingGpc"
            class="text-white font-bold py-1 px-3 rounded shadow transition-colors flex items-center space-x-1 text-xs uppercase"
            :class="fillingGpc ? 'bg-green-400 cursor-wait' : 'bg-green-600 hover:bg-green-700'"
            :title="'Llenar GPC directamente (' + gpcDestino + ')'"
          >
            <!-- Spinner mientras se envía -->
            <svg v-if="fillingGpc" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            <!-- Ícono de enviar -->
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            <span>{{ fillingGpc ? 'Enviando...' : 'Llenar GPC' }}</span>
          </button>

          <!-- Enlace al GPC de la sucursal -->
          <a
            :href="gpcUrl"
            target="_blank"
            rel="noopener"
            @click.stop
            class="bg-white border border-green-600 text-green-700 hover:bg-green-50 font-bold py-1 px-2 rounded shadow-sm transition-colors text-xs uppercase no-underline"
            :title="'Abrir GPC (' + gpcDestino + ')'"
          >
            Ver GPC
          </a>
        </div>
      </div>

      <!-- Grid de Facturas (6 columnas) -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-1.5">
        <!-- Bucle para cada tipo de documento -->
        <div
          v-for="(info, tipo) in operacion.status_botones"
          :key="tipo"
          class="border rounded-md shadow-sm overflow-hidden flex flex-col bg-white text-[11px]"
        >
          <!-- Cabecera de la tarjeta de factura -->
          <div
            class="p-1 flex justify-between items-center"
            :class="getCardHeaderBgClass(getFacturaAuditoriaStatusText(tipo, info))"
          >
            <div class="flex items-center space-x-1 overflow-hidden">
              <button
                @click="
                  $emit('open-modal', {
                    tipo,
                    info,
                    operacion: operacion,
                    sc: operacion.status_botones.sc ? operacion.status_botones.sc.datos : null,
                  })
                "
                :class="getStatusButtonClass(info.estado)"
                class="h-8 px-2 rounded text-white font-bold transition-all duration-200 shadow uppercase"
              >
                {{ tipo.replace("_", " ") }}
              </button>

              <!-- Acceso rápido al GPC desde la tarjeta SC (todas las sucursales con GPC) -->
              <a
                v-if="tieneGPC && tipo === 'sc'"
                :href="gpcUrl"
                target="_blank"
                rel="noopener"
                class="bg-green-600 hover:bg-green-700 text-white font-bold h-8 px-1.5 rounded flex items-center justify-center shadow transition-colors text-[9px] no-underline"
                :title="'Ver GPC (' + gpcDestino + ')'"
              >
                GPC
              </a>
            </div>

            <p class="font-semibold text-gray-800 truncate ml-1 text-right flex-grow">
              <span class="font-normal text-gray-500 text-[9px]">Folio:</span>
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
            <p v-if="tipo !== 'sc'" :class="getStatusDiferenciaTextClass(info)">
              {{ formatCurrency(getFacturaDiferenciaText(info)) }}
            </p>
            <p v-if="info.datos" class="text-gray-400">
              {{ info.datos.fecha_documento }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
const GPC_ZLO_URL = "https://docs.google.com/spreadsheets/d/18-5okzV-vw35V0Ugjn5KjNcWgHyZ9Qfc6pf5w4VU-2I";
const GPC_MULTI_URL = "https://docs.google.com/spreadsheets/d/17hBoRx5u5jxi2hqHiC98zX-3lqF0w5tTkHBzElCHwDM";

const GPC_POR_SUCURSAL = {
  1: { nombre: "NOGALES", url: GPC_MULTI_URL, multi: true },
  2: { nombre: "TIJUANA", url: GPC_MULTI_URL, multi: true },
  3: { nombre: "LAREDO", url: GPC_MULTI_URL, multi: true },
  4: { nombre: "MEXICALI", url: GPC_MULTI_URL, multi: true },
  5: { nombre: "ZLO", url: GPC_ZLO_URL, multi: false },
};

export default {
  props: {
    operacion: { type: Object, required: true },
    itemIndex: { type: Number, required: true },
    pageFrom: { type: Number, required: true },
    // El padre lo pone en true mientras espera la respuesta de "Llenar GPC"
    fillingGpc: { type: Boolean, default: false },
  },
  emits: ["open-modal", "preview-pdf", "fill-gpc"],

  computed: {
    esManzanillo() {
      if (!this.operacion) {
        return false;
      }
      const suc = this.operacion.sucursal || "";
      const sucId = this.operacion.sucursal_id;
      const sucStr = String(suc).toUpperCase().trim();
      return sucStr === "ZLO" || sucStr === "MANZANILLO" || sucId == 5;
    },

    // Configuración del GPC de la sucursal de esta operación (o null si no tiene)
    gpcSucursal() {
      if (!this.operacion || !this.operacion.id) {
        return null;
      }
      if (this.esManzanillo) {
        return GPC_POR_SUCURSAL[5];
      }
      return GPC_POR_SUCURSAL[Number(this.operacion.sucursal_id)] || null;
    },

    tieneGPC() {
      return this.gpcSucursal !== null;
    },

    gpcUrl() {
      return this.gpcSucursal ? this.gpcSucursal.url : "#";
    },

    // Texto de destino: "ZLO" o "NOGALES IMPO" / "LAREDO EXPO"...
    gpcDestino() {
      if (!this.gpcSucursal) {
        return "";
      }
      if (!this.gpcSucursal.multi) {
        return this.gpcSucursal.nombre;
      }
      const tipo = (this.operacion.tipo_operacion || "").toLowerCase().includes("export") ? "EXPO" : "IMPO";
      return this.gpcSucursal.nombre + " " + tipo;
    },

    displayNumber() {
      return this.pageFrom + this.itemIndex;
    },

    // Label de Importación o Exportación de la esquina superior izquierda
    operationType() {
      const tipo = this.operacion.tipo_operacion || "";
      if (tipo.toLowerCase().includes("import")) {
        return "IMPORTACIÓN";
      }
      if (tipo.toLowerCase().includes("export")) {
        return "EXPORTACIÓN";
      }
      return "N/A";
    },

    operationTypeClass() {
      const tipo = this.operacion.tipo_operacion || "";
      if (tipo.toLowerCase().includes("import")) {
        return "bg-blue-100 text-blue-800";
      }
      if (tipo.toLowerCase().includes("export")) {
        return "bg-purple-100 text-purple-800";
      }
      return "bg-gray-200 text-gray-800";
    },

    cardOverallState() {
      if (!this.operacion.status_botones) return "verde";
      const entradas = Object.entries(this.operacion.status_botones);

      // Error rojo que NO sea 'Sin SC' (en Manzanillo 'Sin SC!' no es crítico)
      const hasCriticalRed = entradas.some(([key, statusInfo]) => {
        const estadoTexto = this.getFacturaStatusText(key, statusInfo);
        if (this.esManzanillo && String(estadoTexto).toLowerCase() === "sin sc!") {
          return false;
        }
        return this.isStatusRed(estadoTexto) && String(estadoTexto).toLowerCase() !== "sin sc!";
      });

      if (hasCriticalRed) {
        return "rojo";
      }

      const hasPagoDeMas = entradas.some(([key, statusInfo]) => {
        const estadoTexto = this.getFacturaStatusText(key, statusInfo);
        return String(estadoTexto).toLowerCase() === "pago de mas!";
      });

      if (hasPagoDeMas) {
        return "amarillo";
      }

      return "verde";
    },

    cardBgClass() {
      switch (this.cardOverallState) {
        case "rojo": return "border-2 border-red-600 bg-white";
        case "amarillo": return "border-2 border-yellow-500 bg-white";
        default: return "bg-white";
      }
    },
  },

  methods: {
    formatCurrency(value) {
      if (value === null || value === undefined || value === "N/A" || value === "") {
        return value;
      }
      if (typeof value === "string" && (value.includes("MXN") || value.includes("="))) {
        return value;
      }
      const number = parseFloat(value);
      if (isNaN(number)) {
        return value;
      }
      return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN", minimumFractionDigits: 2 }).format(number);
    },

    getFacturaStatusText(tipo, info) {
      // 1. Caso especial IMPUESTOS
      if (tipo === "impuestos") {
        if (info.estado === "rojo" && !info.datos) {
          return "Sin operacion!";
        }

        // En Manzanillo la validación se hace en el GPC: si la diferencia es 0, se considera 'Coinciden!'
        if (this.esManzanillo && info.datos && info.datos.estado === "Sin SC!") {
          const diferencia = parseFloat(info.datos.monto_diferencia_sc);
          if (Math.abs(diferencia) < 0.1) {
            return "Coinciden!";
          }
          return info.datos.estado;
        }
      }

      // 2. Caso especial SC
      if (tipo === "sc") {
        if (this.esManzanillo && !info.datos) {
          return "Ver GPC";
        }
        return info.datos && info.datos.desglose_conceptos ? "SC Encontrada" : "No Encontrado";
      }

      // 3. Resto de casos
      return info.datos ? info.datos.estado : "No Encontrado";
    },

    isStatusRed(estado) {
      if (!estado) {
        return false;
      }
      const estadoLower = String(estado).toLowerCase();

      // Excepción Manzanillo: 'Sin SC!' o 'Ver GPC' NO es rojo
      if (this.esManzanillo && (estadoLower === "sin sc!" || estadoLower === "ver gpc")) {
        return false;
      }

      return (
        estadoLower.includes("pago de menos") ||
        (estadoLower.includes("sin") && !estadoLower.includes("sc")) ||
        estadoLower.includes("intactics") ||
        (estadoLower.includes("no encontrado") && !this.esManzanillo)
      );
    },

    getCardHeaderBgClass(estadoColor) {
      switch (estadoColor) {
        case "verde": return "bg-green-100";
        case "amarillo": return "bg-yellow-100";
        case "rojo": return "bg-red-100";
        default: return "bg-gray-100";
      }
    },

    getStatusButtonClass(estado) {
      // En Manzanillo el rojo por 'Sin SC' se muestra gris
      if (this.esManzanillo && estado === "rojo") {
        return "bg-gray-500 hover:bg-gray-600";
      }

      switch (estado) {
        case "verde": return "bg-green-500 hover:bg-green-600";
        case "amarillo": return "bg-yellow-400 hover:bg-yellow-500";
        case "rojo": return "bg-red-500 hover:bg-red-600";
        case "gris": return "bg-gray-400 cursor-not-allowed";
        default: return "bg-gray-700 hover:bg-gray-800";
      }
    },

    getStatusTextClass(estado) {
      if (!estado) return "text-gray-800";
      const estadoLower = String(estado).toLowerCase();
      if (estadoLower.includes("coinciden") || estadoLower.includes("encontrada") || estadoLower.includes("ver gpc")) {
        return "text-green-700";
      } else if (estadoLower.includes("pago de mas")) {
        return "text-yellow-600";
      } else if (this.isStatusRed(estado) || estadoLower.includes("sin")) {
        return "text-red-700";
      } else if (estadoLower.includes("expo") || estadoLower.includes("impo")) {
        return "text-indigo-700";
      }
      return "text-gray-800";
    },

    getStatusDiferenciaTextClass(info) {
      if (!info) {
        return "text-gray-800";
      }
      const valorDiferencia = info.datos?.monto_diferencia_sc ?? "N/A";
      const estadoLower = info.datos?.estado?.toLowerCase() ?? "N/A";

      if (estadoLower === "N/A" || String(valorDiferencia) === "N/A") {
        return "text-gray-800";
      }

      // En Manzanillo, diferencia 0 es verde aunque diga 'Sin SC'
      if (this.esManzanillo && Math.abs(parseFloat(valorDiferencia)) < 0.1) {
        return "text-green-700 font-bold";
      }

      if (estadoLower.includes("sin sc")) {
        return "text-gray-800 font-bold";
      } else if (valorDiferencia < 0) {
        return "text-red-700 font-bold";
      }
      return "text-green-700 font-bold";
    },

    getFacturaDiferenciaText(info) {
      if (!info) {
        return "N/A";
      }
      const valorDiferencia = info.datos?.monto_diferencia_sc ?? "N/A";
      const estadoLower = info.datos?.estado?.toLowerCase() ?? "N/A";
      if (estadoLower === "expo" || estadoLower === "impo") {
        return "";
      }
      if (estadoLower === "N/A" || String(valorDiferencia) === "N/A") {
        return "N/A";
      }

      if (estadoLower.includes("sin sc")) {
        return "+/- " + valorDiferencia + " MXN";
      } else if (valorDiferencia < 0) {
        return valorDiferencia + " MXN";
      } else if (valorDiferencia == 0) {
        return "=" + valorDiferencia + " MXN";
      } else if (valorDiferencia > 0) {
        return "+" + valorDiferencia + " MXN";
      }
      return valorDiferencia;
    },

    getFacturaAuditoriaStatusText(tipo, info) {
      const estadoTexto = this.getFacturaStatusText(tipo, info);
      const estadoLower = String(estadoTexto).toLowerCase();
      if (!estadoTexto) {
        return "neutral";
      }

      if (tipo === "sc") {
        return (estadoLower.includes("coinciden") || estadoLower.includes("encontrada") || estadoLower.includes("ver gpc")) ? "verde" : "gris";
      }

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
      }
      return "neutral";
    },
  },
};
</script>