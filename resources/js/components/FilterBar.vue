<template>
  <div class="p-4 bg-white rounded-lg shadow-md mb-6 border">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Identificadores</legend>
        <div class="space-y-2">
          <input
            type="text"
            v-model="filters.pedimento"
            placeholder="Pedimento"
            class="block w-full py-2 border-gray-300 rounded-md shadow-sm text-sm"
          />
          <div class="flex space-x-2 pt-3">
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
        <div class="space-y-2">
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
                <option>IMPO</option>
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
          <v-select
            v-model="filters.cliente_id"
            :options="clienteOptions"
            label="nombre"
            :reduce="(cliente) => cliente.id"
            placeholder="Buscar Cliente..."
            class="w-full pt-3"
          ></v-select>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Periodo</legend>
        <div class="space-y-2">
          <vue-ctk-date-time-picker
            v-model="dateRange"
            label="Selecciona un rango"
            formatted="YYYY-MM-DD"
            format="YYYY-MM-DD"
            :range="true"
            :no-time="true"
            :button-color="'#b47500'"
            :shortcuts="[
              { key: 'thisWeek', label: 'Esta Semana' },
              { key: 'lastWeek', label: 'Semana Pasada' },
              { key: 'thisMonth', label: 'Este Mes' },
              { key: 'lastMonth', label: 'Mes Pasado' },
            ]"
          />
          <div class="block pt-3">
            <select
              v-model="filters.fecha_tipo_documento"
              class="block w-full py-2 border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Cualquier Tipo</option>
              <option value="sc">SC</option>
              <option value="impuestos">Impuestos</option>
              <option value="flete">Flete</option>
              <option value="llc">LLC</option>
              <option value="pago_derecho">Pago Derecho</option>
            </select>
          </div>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Acciones</legend>
        <div class="h-full flex flex-col space-y-4">
          <div>
            <v-select
              ref="reporteSelect"
              :options="tareasCompletadas"
              placeholder="Seleccione un reporte..."
              class="w-full"
              :filterable="false"
              @option:selected="limpiarSeleccionReporte"
            >
              <template #selected-option-container>
                <div class="text-sm text-gray-500">Seleccione un reporte...</div>
              </template>

              <template
                #option="{
                  id,
                  nombre_archivo,
                  banco,
                  sucursal,
                  created_at,
                  ruta_reporte_impuestos,
                  ruta_reporte_impuestos_pendientes,
                }"
              >
                <div class="py-2 px-3">
                  <p class="font-bold text-base truncate" :title="nombre_archivo">
                    {{ nombre_archivo }}
                  </p>
                  <div class="flex justify-between text-xs mt-1">
                    <span>{{ sucursal }}</span>
                    <span>{{ banco }}</span>
                    <span>{{ formatRelativeDate(created_at) }}</span>
                  </div>
                  <div class="flex space-x-4 text-sm mt-2 pt-2 border-t">
                    <a
                      v-if="ruta_reporte_impuestos"
                      :href="getDownloadUrl(id, 'facturado')"
                      @click.stop
                      target="_blank"
                      class="font-medium hover:underline"
                      >Reporte - Facturados</a
                    >
                    <a
                      v-if="ruta_reporte_impuestos_pendientes"
                      :href="getDownloadUrl(id, 'pendiente')"
                      @click.stop
                      target="_blank"
                      class="font-medium hover:underline"
                      >Reporte - Pendientes</a
                    >
                  </div>
                </div>
              </template>

              <template #no-options>
                No hay reportes recientes para esta sucursal.
              </template>
            </v-select>
          </div>

          <div class="flex space-x-2 mt-auto">
            <button
              @click="search"
              class="w-full bg-blue-700 text-white py-2 px-4 rounded-md shadow-sm hover:opacity-90 font-semibold flex items-center justify-center"
            >
              <span class="font-semibold whitespace-nowrap">Filtrar operaciones</span>
              <svg
                class="w-5 h-5 ml-2 flex-shrink-0"
                aria-hidden="true"
                xmlns="http://www.w3.org/2000/svg"
                width="24"
                height="24"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-linecap="round"
                stroke-width="2"
              >
                <path d="m21 21-3.5-3.5M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />
              </svg>
            </button>
            <button
              v-if="hasActiveFilters"
              @click="clear"
              class="w-full bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 text-sm"
            >
              Limpiar filtros
            </button>
          </div>
        </div>
      </fieldset>
    </div>
  </div>
</template>

<script>
// Para formatear la fecha (ej. "hace 2 días"), instala date-fns: npm install date-fns
import { formatDistanceToNow, parseISO } from "date-fns";
import { es } from "date-fns/locale";

import VueCtkDateTimePicker from "vue-ctk-date-time-picker";
import "vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css";

import vSelect from "vue-select";
import "vue-select/dist/vue-select.css";

export default {
  components: {
    vSelect,
    VueCtkDateTimePicker,
  },
  props: {
    clientes: { type: Array, default: () => [] },
    selectedSucursal: { type: Object, default: null },
  },
  data() {
    // --- Lógica para calcular las fechas ---

    // 1. Obtenemos la fecha de hoy
    const fechaFin = new Date();

    // 2. Creamos una nueva fecha para el inicio y le restamos un mes
    const fechaInicio = new Date();
    //fechaInicio.setDate(fechaFin.getDate() - 1);

    // 3. (Opcional pero recomendado) Formateamos las fechas a YYYY-MM-DD
    //    Este formato es el estándar para los inputs de tipo 'date' en HTML.
    const formatearFecha = (fecha) => fecha.toISOString().split("T")[0];

    //Guardamos el estado inicial de los filtros
    const initialFiltersState = {
      pedimento: "",
      operacion_id: "",
      folio: "",
      folio_tipo_documento: "",
      estado: "",
      estado_tipo_documento: "",
      fecha_inicio: formatearFecha(fechaInicio),
      fecha_fin: formatearFecha(fechaFin),
      fecha_tipo_documento: "",
      cliente_id: "",
    };
    return {
      tareasCompletadas: [],
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
        fecha_inicio: formatearFecha(fechaInicio),
        fecha_fin: formatearFecha(fechaFin),
        fecha_tipo_documento: "",

        //SECCION 5: Involucrados
        cliente_id: "",
      },
      // Creamos una copia inmutable para comparar después
      initialFilters: Object.freeze(initialFiltersState),
    };
  },

  computed: {
    /**
     * Devuelve 'true' si algún filtro ha sido modificado
     * con respecto a su estado inicial.
     */
    hasActiveFilters() {
      // Comparamos el objeto de filtros actual con el inicial.
      // JSON.stringify es una forma sencilla y efectiva de hacer una comparación profunda.
      return JSON.stringify(this.filters) !== JSON.stringify(this.initialFilters);
    },

    // Se obtienen los clientes de la base de datos
    // vue-select funciona mejor con un array de objetos
    clienteOptions() {
      return this.clientes;
    },

    /**
     * Esta propiedad computada actúa como un puente (getter/setter)
     * para el v-model del date-time-picker.
     */
    dateRange: {
      get() {
        // GET: Devuelve el rango en el formato que el picker espera.
        return {
          start: this.filters.fecha_inicio,
          end: this.filters.fecha_fin,
        };
      },
      set(newValue) {
        // SET: Se activa cuando el usuario cambia la fecha en el picker.
        // Actualiza tus filtros y cambia el selector a 'Personalizado'.
        this.filters.fecha_inicio = newValue.start;
        this.filters.fecha_fin = newValue.end;
        this.selectedPeriod = "custom";
      },
    },
  },
  watch: {
    // ESTE ES EL DISPARADOR: Se ejecuta cuando la prop 'selectedSucursal' cambia.
    selectedSucursal: {
      handler(newSucursal) {
        // Verificamos que la nueva sucursal sea válida antes de llamar a la API
        if (newSucursal && newSucursal.id) {
          console.log(`Sucursal cambiada a: ${newSucursal.nombre}. Buscando tareas...`); // <-- Para depurar
          this.fetchTareasCompletadas(newSucursal.id);
        } else {
          this.tareasCompletadas = []; // Limpia la lista si no hay sucursal
        }
      },
      immediate: true, // 'immediate: true' hace que se ejecute una vez cuando el componente se carga por primera vez.
    },
    // Observador para el selector de periodos
    selectedPeriod(newPeriod) {
      this.setPeriod(newPeriod);
    },
  },
  methods: {
    // Método que llama al backend
    fetchTareasCompletadas(sucursalId) {
      axios
        .get("/auditoria/tareas-completadas", { params: { sucursal_id: sucursalId } })
        .then((response) => {
          this.tareasCompletadas = response.data;
        })
        .catch((error) => {
          console.error("Error al obtener tareas completadas:", error);
          this.tareasCompletadas = [];
        });
    },
    // Genera la URL de descarga correcta
    getDownloadUrl(tareaId, tipoReporte) {
      // Asegúrate que esta ruta coincida con la que definiste en tu archivo de rutas para el DocumentoController
      return `/documentos/reporte-auditoria/${tareaId}/${tipoReporte}`;
    },
    // Formatea la fecha
    formatRelativeDate(dateString) {
      if (!dateString) return "";
      try {
        return formatDistanceToNow(parseISO(dateString), { addSuffix: true, locale: es });
      } catch (e) {
        return dateString;
      }
    },
    // Reinicia la selección del dropdown
    limpiarSeleccionReporte() {
      setTimeout(() => {
        if (this.$refs.reporteSelect) {
          this.$refs.reporteSelect.clearSelection();
        }
      }, 50);
    },
    getFilters() {
      return this.filters;
    },

    setInitialFilters(initialFilters) {
      // Esta lógica no funciona aquí porque 'this.filters' y
      // 'this.initialFilters' no existen en AuditPage.vue
      this.filters = { ...this.initialFilters, ...initialFilters };
    },
    /**
     * El método search sigue siendo útil para la acción principal del botón.
     * Todavía emite los filtros para que la tabla principal se actualice.
     */
    search() {
      // Avisa al componente padre que se aplicaron los filtros
      this.$emit("apply-filters", this.filters);
    },
    clear() {
      // Limpia todos los filtros y el selector de periodo
      this.filters = { ...this.initialFilters };
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
<!--  ✅ ESTILOS CORREGIDOS PARA VUE-SELECT -->
<style>
/* Anulamos los estilos por defecto de vue-select.
  Usamos selectores un poco más específicos para asegurar que nuestras reglas ganen.
*/

/* Define el color de fondo azul para la opción resaltada */
.vs__dropdown-option--highlight {
  background-color: #273792; /* Un azul de Tailwind (blue-600) */
  color: white;
}

/* Asegura que TODO el texto dentro de la opción resaltada sea blanco.
  El selector '*' significa "cualquier elemento hijo".
*/
.vs__dropdown-option--highlight,
.vs__dropdown-option--highlight * {
  color: white;
}

/* Asegura que los enlaces también sean blancos y se subrayen al pasar el cursor
  para mantener la interactividad visible.
*/
.vs__dropdown-option--highlight a {
  color: white;
  text-decoration-color: white;
}
.vs__dropdown-option--highlight a:hover {
  text-decoration: underline;
}

/* Estilos generales para que el select se vea bien */
.vs__dropdown-toggle {
  @apply block w-full py-1 px-1 border border-gray-300 bg-white rounded-md shadow-sm;
}
.vs__search::placeholder {
  color: #6b7280;
}

/* Convierte el contenedor principal en un flexbox */
.vs__dropdown-toggle {
  @apply flex items-center justify-between;
}

/* Permite que el contenedor de la selección crezca para empujar la flecha */
.vs__selected-options {
  @apply flex-grow;
}

/* Contenedor de los íconos (flecha y 'x' de limpiar) */
.vs__actions {
  /* Alinea la flecha verticalmente si es necesario */
  @apply self-center;
}
</style>
