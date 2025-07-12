<template>
  <div class="p-4 bg-white rounded-lg shadow-md mb-6 border">
    <div class="grid grid-cols-1 lg:grid-cols-3 xl:grid-cols-4 gap-x-6 gap-y-4">
      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Identificadores</legend>
        <div class="space-y-3">
          <div class="flex space-x-2">
            <input
              type="text"
              v-model="filters.pedimento"
              placeholder="Pedimento"
              class="block w-full py-2 border-gray-300 rounded-md shadow-sm text-sm"
            />
          </div>
          <div class="flex space-x-2">
            <input
              type="text"
              v-model="filters.folio"
              placeholder="Folio de factura"
              class="block py-2 w-full border-gray-300 rounded-md shadow-sm text-sm"
            />
            <select
              v-model="filters.folio_tipo_documento"
              class="block py-2 w-2/3 border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Cualquier Tipo</option>
              <option value="sc">SC</option>
              <option value="flete">Flete</option>
              <option value="llc">LLC</option>
            </select>
          </div>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Estados/Cliente</legend>
        <div class="space-y-3">
          <div class="flex space-x-2">
            <select
              v-model="filters.estado"
              class="block py-2 w-full border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Todos los Estados</option>
              <optgroup label="Correctos">
                <option>Coinciden!</option>
                <option>SC Encontrada</option>
                <option>EXPO</option>
              </optgroup>
              <optgroup label="Para auditar">
                <option>Pago de mas!</option>
                <option>Pago de menos!</option>
                <option>Sin Flete!</option>
                <option>Sin SC!</option>
              </optgroup>
              <optgroup label="Pago de derecho">
                <option>Normal</option>
                <option>Segundo Pago</option>
                <option>Medio Pago</option>
                <option>Intactics</option>
              </optgroup>
            </select>
            <select
              v-model="filters.estado_tipo_documento"
              class="block py-2 w-2/3 border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Cualquier Tipo</option>
              <option value="sc">SC</option>
              <option value="impuestos">Impuestos</option>
              <option value="flete">Flete</option>
              <option value="llc">LLC</option>
              <option value="pago_derecho">Pago Derecho</option>
            </select>
          </div>

          <div class="flex space-x-2">
            <div
              class="block py-2 w-full border-gray-300 rounded-md shadow-sm text-sm bg-gray-100 p-2 text-center text-gray-400"
            >
              <v-select
                v-model="filters.cliente_id"
                :options="clienteOptions"
                label="nombre"
                :reduce="(cliente) => cliente.id"
                placeholder="Buscar Cliente..."
                class="w-full"
              ></v-select>
            </div>
          </div>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Periodo</legend>
        <div class="space-y-3">
          <select
            v-model="selectedPeriod"
            class="block w-full border-gray-300 rounded-md shadow-sm text-sm"
          >
            <option value="custom">Personalizado</option>
            <option value="today">Hoy</option>
            <option value="this_month">Este Mes</option>
            <option value="last_month">Mes Anterior</option>
            <option value="this_year">Este Año</option>
          </select>
          <div class="flex items-center space-x-2">
            <input
              type="date"
              v-model="filters.fecha_inicio"
              class="block w-full border-gray-300 rounded-md shadow-sm text-sm"
            />
            <span class="text-gray-500">-</span>
            <input
              type="date"
              ref="fecha_fin"
              v-model="filters.fecha_fin"
              class="block w-full border-gray-300 rounded-md shadow-sm text-sm"
            />
          </div>
          <select
            v-model="filters.fecha_tipo_documento"
            class="block w-full border-gray-300 rounded-md shadow-sm text-sm"
          >
            <option value="">Cualquier Tipo</option>
            <option value="sc">SC</option>
            <option value="impuestos">Impuestos</option>
            <option value="flete">Flete</option>
            <option value="llc">LLC</option>
            <option value="pago_derecho">Pago Derecho</option>
          </select>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Acciones</legend>
        <div class="h-full flex flex-col justify-end space-y-2">
          <div class="flex items-center space-x-2">
            <a
              :href="exportUrl"
              target="_blank"
              class="w-full text-center bg-green-600 text-white py-2 px-4 rounded-md shadow-sm hover:bg-green-700"
            >
              Exportar
            </a>
            <button
              @click="search"
              class="w-full bg-theme-primary text-white py-2 px-4 rounded-md shadow-sm hover:opacity-90"
            >
              Buscar
            </button>
            <button
              @click="clear"
              class="w-1/2 bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300"
            >
              Limpiar
            </button>
          </div>
        </div>
      </fieldset>
    </div>
  </div>
</template>

<script>
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';

export default {
    components: {
        vSelect
    },
    props: {
    clientes: {
      type: Array,
      default: () => []
    }
  },
  data() {
    return {
      filters: {
        //SECCION 1: Identificadores universales
        pedimento: "",
        operacion_id: "",

        //SECCION 2: Identificadores de factura
        folio: "",
        folio_tipo_documento: "",

        //SECCION 3: Estados
        estado: "",
        estado_tipo_documento: "",

        //SECCION 4: Periodo de fecha
        fecha_inicio: "",
        fecha_fin: "",
        fecha_tipo_documento: "",

        //SECCION 5: Involucrados
        cliente_id: "",
      },
      // Para el selector de periodos
      selectedPeriod: "custom",
    };
  },

  computed: {
     // vue-select funciona mejor con un array de objetos
    clienteOptions() {
      return this.clientes;
    },

    exportUrl() {
      // Tomamos los filtros activos y los convertimos a un query string
      const params = new URLSearchParams(this.filters).toString();
      return `/auditoria/exportar?${params}`;
    },
  },
  watch: {
    // Observador para el selector de periodos
    selectedPeriod(newPeriod) {
      this.setPeriod(newPeriod);
    },
  },
  methods: {
    search() {
      // Avisa al componente padre que se aplicaron los filtros
      this.$emit("apply-filters", this.filters);
    },
    clear() {
      // Limpia todos los filtros y el selector de periodo
      Object.keys(this.filters).forEach((key) => (this.filters[key] = ""));
      this.selectedPeriod = "custom";
      this.search();
    },
    setPeriod(period) {
      const today = new Date();
      let startDate = new Date();
      let endDate = new Date();

      if (period === "custom") return;

      switch (period) {
        case "today":
          startDate = today;
          endDate = today;
          break;
        case "this_month":
          startDate = new Date(today.getFullYear(), today.getMonth(), 1);
          endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
          break;
        case "last_month":
          startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          endDate = new Date(today.getFullYear(), today.getMonth(), 0);
          break;
        case "this_year":
          startDate = new Date(today.getFullYear(), 0, 1);
          endDate = new Date(today.getFullYear(), 11, 31);
          break;
      }
      // Formateamos las fechas a YYYY-MM-DD para los inputs
      this.filters.fecha_inicio = startDate.toISOString().split("T")[0];
      this.filters.fecha_fin = endDate.toISOString().split("T")[0];
    },
  },
};
</script>
<style>
/* Estilos para que vue-select se vea bien con Tailwind */
.vs__dropdown-toggle {
    @apply block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm;
}
.vs__selected {
    @apply text-sm;
}
.vs__search {
    @apply text-sm;
}
</style>
