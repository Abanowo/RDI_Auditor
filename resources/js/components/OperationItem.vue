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
      <!-- Info Principal de la Operación con fondo para destacar -->
      <div class="inline-block bg-white rounded-md px-3 py-1 shadow-sm">
        <p class="font-bold text-base leading-tight">
          {{ operacion.pedimento }} - {{ operacion.cliente }}
        </p>
        <p class="text-xs text-gray-600 flex items-center">
          {{ operacion.fecha_edc }}
        </p>
      </div>

      <!-- Grid de Facturas (5 columnas) -->
      <div class="grid grid-cols-2 sm:grid-cols-1 lg:grid-cols-5 gap-1.5">
        <!-- Bucle para cada tipo de documento -->
        <template v-for="(info, tipo) in operacion.status_botones">
          <div
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

                <a 
                  v-if="esManzanillo && tipo === 'sc'"
                  href="https://docs.google.com/spreadsheets/d/1zHUYpViLZyu_KPkNCUEx37WjoK0lVt7F0bC1B9Jo8s0"
                  target="_blank"
                  class="bg-green-600 hover:bg-green-700 text-white font-bold h-8 px-1.5 rounded flex items-center justify-center shadow transition-colors text-[9px] no-underline"
                  title="Ver GPC"
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
              <p v-if="tipo !== 'sc'"
              :class="getStatusDiferenciaTextClass(info)">
                 {{ formatCurrency(getFacturaDiferenciaText(info)) }}
              </p>
              <p v-if="info.datos" class="text-gray-400">
                {{ info.datos.fecha_documento }}
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
  emits: ["open-modal", "preview-pdf"],
  
  computed: {
    esManzanillo() {
      if (!this.operacion) return false;
      const suc = this.operacion.sucursal || '';
      const sucId = this.operacion.sucursal_id;
      const sucStr = String(suc).toUpperCase().trim();
      return sucStr === 'ZLO' || sucStr === 'MANZANILLO' || sucId == 5;
    },

    displayNumber() {
      return this.pageFrom + this.itemIndex;
    },
    //Para el label de Importacion o Exportacion de la esquina superior izquierda
    operationType() {
      const tipo = this.operacion.tipo_operacion || "";
      if (tipo.toLowerCase().includes("import")) 
        return "IMPORTACIÓN";
      if (tipo.toLowerCase().includes("export"))
        return "EXPORTACIÓN";
      return "N/A";
    },

    operationTypeClass() {
      const tipo = this.operacion.tipo_operacion || "";
      if (tipo.toLowerCase().includes("import")) return "bg-blue-100 text-blue-800";
      if (tipo.toLowerCase().includes("export")) return "bg-purple-100 text-purple-800";
      return "bg-gray-200 text-gray-800";
    },

    cardOverallState() {
      if (!this.operacion.status_botones) return "verde";
      const statuses = Object.values(this.operacion.status_botones);
      // 3. LÓGICA "SIN SC": Verificamos si hay un error rojo que NO sea 'Sin SC'
      const hasCriticalRed = statuses.some((statusInfo) => {
        const key = Object.keys(this.operacion.status_botones).find(k => this.operacion.status_botones[k] === statusInfo);
        const estadoTexto = this.getFacturaStatusText(key, statusInfo);
        // MODIFICADO: Si es Manzanillo, 'Sin SC!' no cuenta como error crítico
        if (this.esManzanillo && String(estadoTexto).toLowerCase() === "sin sc!") return false;
        
        return this.isStatusRed(estadoTexto) && String(estadoTexto).toLowerCase() !== "sin sc!";
      });

      if (hasCriticalRed) return "rojo";

      const hasPagoDeMas = statuses.some((statusInfo) => {
        const key = Object.keys(this.operacion.status_botones).find(k => this.operacion.status_botones[k] === statusInfo);
        const estadoTexto = this.getFacturaStatusText(key, statusInfo);
        return String(estadoTexto).toLowerCase() === "pago de mas!";
      });

      if (hasPagoDeMas) return "amarillo";

      // Si no hay errores, retornamos verde por defecto
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
       if(value === null || value === undefined || value === "N/A" || value === "") return value;
       if (typeof value === 'string' && (value.includes('MXN') || value.includes('='))) return value;
       const number = parseFloat(value);
       if (isNaN(number)) return value;
       return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 }).format(number);
    },

    getFacturaStatusText(tipo, info) {
      // 1. Caso especial IMPUESTOS
      if (tipo === "impuestos") {
        if (info.estado === "rojo" && !info.datos) return "Sin operacion!";
        
        // Si es Manzanillo y dice 'Sin SC!', lo cambiamos a 'GPC Validado' (o lo ocultamos)
        // porque asumimos que la validación se hace en el Sheets.
        if (this.esManzanillo && info.datos && info.datos.estado === "Sin SC!") {
           // Aquí verificamos si la diferencia es 0 para decir 'Coinciden!' aunque no haya SC física.
           const diferencia = parseFloat(info.datos.monto_diferencia_sc);
           if (Math.abs(diferencia) < 0.1) return "Coinciden!"; 
           // Si hay diferencia, mostramos el estado normal (Pago de mas/menos)
           return info.datos.estado;
        }
      }

      // 2. Caso especial SC
      if (tipo === "sc") {
        // En Manzanillo, si no hay SC física (info.datos es null), mostramos 'Ver GPC' o similar en vez de 'No Encontrado'
        if (this.esManzanillo && !info.datos) return "Ver GPC";
        return info.datos && info.datos.desglose_conceptos ? "SC Encontrada" : "No Encontrado";
      }

      // 3. Resto de casos
      return info.datos ? info.datos.estado : "No Encontrado";
    },


    isStatusRed(estado) {
      if (!estado) return false;
      const estadoLower = String(estado).toLowerCase();
      
      // Excepción Manzanillo: 'Sin SC!' o 'Ver GPC' NO es rojo
      if (this.esManzanillo && (estadoLower === "sin sc!" || estadoLower === "ver gpc")) return false;

      return (
        estadoLower.includes("pago de menos") ||
        (estadoLower.includes("sin") && !estadoLower.includes("sc")) ||
        estadoLower.includes("intactics") ||
        (estadoLower.includes("no encontrado") && !this.esManzanillo) // En Manzanillo, 'No encontrado' en SC no es rojo crítico
      );
    },

    getCardHeaderBgClass(estadoColor) {
      switch (estadoColor) {
        case "verde":
          return "bg-green-100";
        case "amarillo":
          return "bg-yellow-100";
        case "rojo":
          return "bg-red-100";
        default:
          return "bg-gray-100";
      }
    },

    getStatusButtonClass(estado) {
      // Ajuste visual para el botón de la tarjeta
      if (this.esManzanillo && estado === 'rojo') {
         // Si es Manzanillo y está rojo por 'Sin SC', lo volvemos verde o gris
         return "bg-gray-500 hover:bg-gray-600"; 
      }
      
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
      const estadoLower = String(estado).toLowerCase();
      if (estadoLower.includes("coinciden") || estadoLower.includes("encontrada") || estadoLower.includes("ver gpc")) {
        return "text-green-700";
      } else if (estadoLower.includes("pago de mas")) {
        return "text-yellow-600";
      } else if (this.isStatusRed(estado) || estadoLower.includes("sin")) {
        return "text-red-700";
      } else if (estadoLower.includes("expo") || estadoLower.includes("impo")) {
        return "text-indigo-700";
      } else {
        return "text-gray-800";
      }
    },

    getStatusDiferenciaTextClass(info) {
      if (!info) return "text-gray-800";
      const valorDiferencia = info.datos?.monto_diferencia_sc ?? "N/A";
      const estadoLower = info.datos?.estado?.toLowerCase() ?? "N/A";

      if(estadoLower === "N/A" || String(valorDiferencia) === "N/A") return "text-gray-800";

      // Si es Manzanillo y la diferencia es 0, es verde aunque diga 'Sin SC'
      if (this.esManzanillo && Math.abs(parseFloat(valorDiferencia)) < 0.1) return "text-green-700 font-bold";

      if (estadoLower.includes("sin sc")) {
        return "text-gray-800 font-bold";
      } else if (valorDiferencia < 0) {
        return "text-red-700 font-bold";
      } else {
        return "text-green-700 font-bold";
      }
    },

    getFacturaDiferenciaText(info) {
      if (!info) return "N/A";
      const valorDiferencia = info.datos?.monto_diferencia_sc ?? "N/A";
      const estadoLower = info.datos?.estado?.toLowerCase() ?? "N/A";
      if (estadoLower === "expo" || estadoLower === "impo") {
        return "";
      }
      if(estadoLower === "N/A" || String(valorDiferencia) === "N/A") return "N/A";

      if (estadoLower.includes("sin sc")) {
        return "+/- " + valorDiferencia + " MXN";
      } else if (valorDiferencia < 0) {
        return valorDiferencia + " MXN";
      } else if (valorDiferencia == 0) {
        return  "=" + valorDiferencia + " MXN";
      } else if (valorDiferencia > 0) {
        return "+" + valorDiferencia + " MXN";
      }
      return valorDiferencia;
    },

    getFacturaAuditoriaStatusText(tipo, info) {
      const estadoTexto = this.getFacturaStatusText(tipo, info);
      const estadoLower = String(estadoTexto).toLowerCase();
      if (estadoTexto) {
        if (tipo === "sc") {
          return (estadoLower.includes("coinciden") || estadoLower.includes("encontrada") || estadoLower.includes("ver gpc")) ? "verde" : "gris";
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
  },
};
</script>