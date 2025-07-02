<template>
  <div class="operacion-card bg-white p-4 shadow-md rounded-lg flex flex-col space-y-3">
    <div class="flex justify-between items-center">
      <div class="flex items-center space-x-3">
        <span
          class="bg-blue-800 text-white font-bold text-sm rounded h-8 w-auto px-3 flex items-center justify-center flex-shrink-0"
        >
          {{ displayNumber }}
        </span>
        <div>
          <p class="font-bold text-lg text-theme-dark">{{ operacion.pedimento }}</p>
          <p class="text-sm text-gray-500">{{ operacion.fecha_edc }}</p>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 pt-2">
      <template v-for="(info, tipo) in operacion.status_botones">
        <template v-if="Array.isArray(info.datos)">
          <div
            v-for="(factura, index) in info.datos"
            :key="`${tipo}-${factura.id}`"
            class="border rounded-lg shadow-sm overflow-hidden flex flex-col"
            :class="getCardBorderClass(info.estado)"
          >
            <div
              class="p-2 flex justify-between items-center"
              :class="getCardBgClass(info.estado)"
            >
              <button
                @click="
                  $emit('open-modal', {
                    tipo: `${tipo} #${index + 1}`,
                    info: { datos: factura, estado: info.estado },
                    operacion: operacion,
                    sc: operacion.status_botones.sc.datos,
                  })
                "
                :class="getStatusButtonClass(info.estado)"
                class="h-6 px-2 rounded text-white text-xs font-bold transition-all duration-200 shadow"
              >
                {{ tipo.toUpperCase().replace("_", " ") }} #{{ index + 1 }}
              </button>
              <div class="text-right">
                <p class="text-xs text-gray-500">Folio</p>
                <p class="text-sm font-semibold text-gray-800">
                  {{ factura.folio || "N/A" }}
                </p>
              </div>
            </div>
            <div class="p-2 bg-white flex-grow">
              <p class="text-xs text-gray-400">Estado de Auditoría</p>
              <p class="text-sm" :class="getStatusTextClass(factura.estado)">
                {{ factura.estado }}
              </p>
            </div>
          </div>
        </template>

        <div
          v-else
          :key="tipo"
          class="border rounded-lg shadow-sm overflow-hidden flex flex-col"
          :class="getCardBorderClass(info.estado)"
        >
          <div
            class="p-2 flex justify-between items-center"
            :class="getCardBgClass(info.estado)"
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
              class="h-6 px-2 rounded text-white text-xs font-bold transition-all duration-200 shadow"
            >
              {{ tipo.toUpperCase().replace("_", " ") }}
            </button>
            <div class="text-right">
              <p class="text-xs text-gray-500">Folio</p>
              <p class="text-sm font-semibold text-gray-800">
                {{
                  (info.datos && (info.datos.folio_documento || info.datos.folio)) ||
                  "N/A"
                }}
              </p>
            </div>
          </div>
          <div class="p-2 bg-white flex-grow">
            <p class="text-xs text-gray-400">Estado de Auditoría</p>
            <p
              v-if="tipo === 'sc'"
              class="text-sm"
              :class="
                getStatusTextClass(
                  info.datos && info.datos.desglose_conceptos
                    ? 'SC Encontrada'
                    : 'No Encontrado'
                )
              "
            >
              {{
                info.datos && info.datos.desglose_conceptos
                  ? "SC Encontrada"
                  : "No Encontrado"
              }}
            </p>
            <p
              v-else
              class="text-sm"
              :class="
                getStatusTextClass(info.datos ? info.datos.estado : 'No Encontrado')
              "
            >
              {{ info.datos ? info.datos.estado : "No Encontrado" }}
            </p>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
export default {
  // Aquí le decimos a Vue que este componente espera recibir un objeto llamado 'operacion'.
  props: {
    operacion: {
      type: Object, // El tipo de dato que esperamos es un objeto
      required: true, // Indicamos que es un dato obligatorio
    },
    itemIndex: { type: Number, required: true },
    pageFrom: { type: Number, required: true },
  },
  emits: ["open-modal"], // <-- Declara el evento que este componente puede emitir
  computed: {
    displayNumber() {
      // El número inicial de la página + el índice del elemento actual
      return this.pageFrom + this.itemIndex;
    },
  },
  methods: {
    // En el script de OperationItem.vue -> methods

    /**
     * Devuelve las clases para el FONDO de la sección superior de la tarjeta.
     */
    getCardBgClass(estado) {
      switch (estado) {
        case "verde":
          return "bg-green-50";
        case "amarillo":
          return "bg-yellow-50";
        case "rojo":
          return "bg-red-50";
        case "gris":
          return "bg-gray-100";
        default:
          return "bg-gray-100";
      }
    },
    /**
     * Devuelve las clases para el BORDE de la tarjeta completa.
     */
    getCardBorderClass(estado) {
      switch (estado) {
        case "verde":
          return "border-green-200";
        case "amarillo":
          return "border-yellow-300";
        case "rojo":
          return "border-red-300";
        case "gris":
          return "border-gray-200";
        default:
          return "border-gray-200";
      }
    },
    /**
     * Devuelve las clases de color de FONDO para el botón.
     */
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
    /**
     * Devuelve las clases de color de TEXTO para el estado.
     */
    getStatusTextClass(estado) {
      if (!estado) return "text-gray-800 font-bold";

      const estadoLower = estado.toLowerCase();
      if (estadoLower === "coinciden!" || estadoLower === "sc encontrada") {
        return "text-green-600 font-bold";
      } else if (estadoLower === "pago de mas!") {
        return "text-yellow-500 font-bold";
      } else if (estadoLower === "expo") {
        return "text-indigo-600 font-bold";
      } else if (
        estadoLower === "pago de menos!" ||
        estadoLower.includes("sin") ||
        estadoLower === "intactics"
      ) {
        return "text-red-600 font-bold";
      } else {
        return "text-gray-800 font-bold";
      }
    },

    abrirModal(tipo, info) {
      // Avisa a la página principal que abra el modal
      // this.$emit('abrir-modal', { tipo, info });
      console.log(`Abrir modal para ${tipo}`, info);
    },
  },
};
</script>
