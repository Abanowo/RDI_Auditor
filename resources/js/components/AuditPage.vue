<template>
  <div class="p-8 bg-theme-light min-h-screen">
    <div v-if="!selectedSucursal" class="bg-white p-6 rounded-lg shadow text-center">
      <div class="flex flex-wrap justify-center gap-4">
        <button
          v-for="sucursal in sucursales"
          :key="sucursal.id"
          @click="selectSucursal(sucursal)"
          class="btn-selector"
        >
          {{ sucursal.nombre }}
        </button>
        <button
          @click="selectSucursal({ id: 'todos', nombre: 'Todas' })"
          class="btn-selector"
        >
          Todas
        </button>
      </div>
    </div>

    <div
      v-else-if="!selectedOperationType"
      class="bg-white p-6 rounded-lg shadow text-center"
    >
      <div class="mb-4">
        <button @click="resetSelection" class="text-sm text-blue-600 hover:underline">
          &larr; Cambiar Sucursal
        </button>
      </div>
      <h2 class="text-xl font-semibold mb-4">
        Sucursal: <span class="text-theme-primary">{{ selectedSucursal.nombre }}</span>
      </h2>
      <p class="mb-4">Ahora, selecciona el tipo de operación:</p>
      <div class="flex justify-center gap-4">
        <button @click="selectOperationType('importacion')" class="btn-selector">
          Importación
        </button>
        <button @click="selectOperationType('exportacion')" class="btn-selector">
          Exportación
        </button>
        <button @click="selectOperationType('todos')" class="btn-selector">Ambas</button>
      </div>
    </div>

    <div v-else>
      <div
        class="flex items-center gap-4 mb-4 bg-orange-100 p-3 rounded-lg border border-orange-300"
      >
        <span class="font-semibold">Filtros Activos:</span>
        <span class="bg-white px-3 py-1 rounded-full text-sm shadow-sm"
          >Sucursal: <strong>{{ selectedSucursal.nombre }}</strong></span
        >
        <span class="bg-white px-3 py-1 rounded-full text-sm shadow-sm"
          >Operación:
          <strong class="capitalize">{{ selectedOperationType }}</strong></span
        >
        <button
          @click="resetSelection"
          class="ml-auto text-sm text-blue-600 hover:underline"
        >
          Cambiar Selección
        </button>
      </div>

      <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-theme-dark">
          Panel de Auditoría de Operaciones
        </h1>
        <button
          @click="isImportModalVisible = true"
          class="bg-theme-primary text-white font-bold py-2 px-4 rounded-lg shadow hover:opacity-90"
        >
          Subir Montos
        </button>
      </div>

      <ImportModal :show="isImportModalVisible" @close="isImportModalVisible = false" />

      <FilterBar
        :selected-sucursal="selectedSucursal"
        :clientes="clientes"
        @apply-filters="handleFilters"
      />
      <div v-if="isLoading" class="text-center text-gray-500 mt-10">
        <p>Cargando operaciones...</p>
      </div>

      <div v-if="!isLoading" class="space-y-4">
        <OperationItem
          v-for="(operacion, index) in operaciones"
          :key="operacion.id"
          :operacion="operacion"
          :item-index="index"
          :page-from="pagination.from"
          @open-modal="openModal"
        />
      </div>

      <div
        v-if="!isLoading && operaciones.length === 0"
        class="text-center text-gray-500 mt-10 bg-white p-8 rounded-lg shadow"
      >
        <p class="font-semibold">No se encontraron operaciones</p>
        <p class="text-sm">
          Intenta ajustar los filtros o límpialos para ver todos los registros.
        </p>
      </div>

      <div class="mt-8">
        <Pagination
          v-if="pagination.links"
          :links="pagination.links"
          @change-page="fetchOperaciones"
        />
      </div>
      <AuditModal
        :show="isModalVisible"
        :audit-data="modalAuditData"
        @close="isModalVisible = false"
      />
    </div>
  </div>
</template>

<script>
import OperationItem from "../components/OperationItem.vue"; // ¡Importante! Importa el componente hijo
import Pagination from "../components/Pagination.vue";
import AuditModal from "../components/AuditModal.vue";
import FilterBar from "../components/FilterBar.vue";
import ImportModal from "./ImportModal.vue";
export default {
  components: {
    OperationItem, // Registra el componente para poder usarlo en el template
    Pagination,
    AuditModal,
    FilterBar,
    ImportModal,
  },
  data() {
    return {
      sucursales: [],
      selectedSucursal: null,
      selectedOperationType: null,
      operaciones: [], // Aquí guardaremos la lista que viene en la llave "data" del JSON
      pagination: {}, // Aquí guardaremos el resto de la info (links, total, etc.)
      clientes: [],
      isLoading: true, // Un "extra" para mostrar un mensaje de "Cargando..."
      isModalVisible: false, // VARIABLE DE VISIBILIDAD - Modal
      modalAuditData: null, // VARIABLE PARA DATOS - Modal
      isImportModalVisible: false, // VARIABLE PARA VISIBILIDAD DE IMPORTACION - Modal
      // 3. Guardaremos el estado de los filtros aquí
      activeFilters: {},
    };
  },
  computed: {
    selectionComplete() {
      return this.selectedSucursal && this.selectedOperationType;
    },
    // Combina los filtros base (sucursal, tipo) con los de la FilterBar
    finalFilters() {
      if (!this.selectionComplete) return {};
      return {
        ...this.activeFilters,
        sucursal_id: this.selectedSucursal.id,
        operation_type: this.selectedOperationType,
      };
    },
  },
  // ...
  async mounted() {
    // Usamos un bloque try/catch para capturar cualquier error crítico durante la carga.
    try {
      this.isLoading = true; // Mostramos el spinner de carga desde el inicio

      // 1. Esperamos a que la lista de sucursales se cargue por completo.
      const response = await axios.get("/auditoria/sucursales");
      this.sucursales = response.data;

      // 2. Ahora que tenemos las sucursales, procesamos la URL.
      const urlParams = new URLSearchParams(window.location.search);
      const sucursalId = urlParams.get("sucursal_id");
      const operationType = urlParams.get("operation_type");
      const page = urlParams.get("page");

      // 3. Verificamos si la URL contiene los filtros para saltar la selección.
      if (sucursalId && operationType) {
        let foundSucursal = this.sucursales.find((s) => s.id == sucursalId);
        if (!foundSucursal && sucursalId === "todos") {
          foundSucursal = { id: "todos", nombre: "Todas" };
        }

        if (foundSucursal) {
          // Asignamos el estado directamente
          this.selectedSucursal = foundSucursal;
          this.selectedOperationType = operationType;

          // Construimos la URL inicial para la paginación
          const initialUrl = page ? `/auditoria?page=${page}` : "/auditoria";

          // Disparamos las llamadas para obtener los datos del panel.
          // Usamos Promise.all para que se ejecuten en paralelo y sea más rápido.
          await Promise.all([this.fetchClientes(), this.fetchOperaciones(initialUrl)]);
        } else {
          // Si la sucursal de la URL no es válida, no hacemos nada y dejamos que se muestre la selección.
          this.isLoading = false;
        }
      } else {
        // Si no hay filtros en la URL, simplemente dejamos de cargar.
        this.isLoading = false;
      }
    } catch (error) {
      console.error("Error crítico durante el montaje del componente:", error);
      this.isLoading = false; // Ocultamos el spinner en caso de error
      // Opcional: podrías mostrar un mensaje de error al usuario.
    }
  },
  methods: {
    fetchSucursales() {
      axios
        .get("/auditoria/sucursales")
        .then((response) => {
          this.sucursales = response.data;
        })
        .catch((error) => console.error("Error al obtener sucursales:", error));
    },
    fetchClientes() {
      axios
        .get("/auditoria/clientes", {
          params: {
            sucursal_id: this.selectedSucursal.id,
            operation_type: this.selectedOperationType,
          },
        })
        .then((response) => {
          this.clientes = response.data;
        })
        .catch((error) => console.error("Error al obtener clientes:", error));
    },
    selectSucursal(sucursal) {
      this.selectedSucursal = sucursal;
    },
    selectOperationType(type, updateUrl = true) {
      this.selectedOperationType = type;

      // Solo actualizamos la URL si la función fue llamada sin el segundo
      // parámetro (lo que significa que fue un clic del usuario) o si es explícitamente true.
      if (updateUrl) {
        const params = new URLSearchParams();
        params.set("sucursal_id", this.selectedSucursal.id);
        params.set("operation_type", this.selectedOperationType);

        // history.pushState cambia la URL sin recargar la página
        window.history.pushState({}, "", `?${params.toString()}`);
      }

      // Cargamos los datos necesarios para el panel
      this.fetchClientes();
      this.fetchOperaciones();
    },
    resetSelection() {
      this.selectedSucursal = null;
      this.selectedOperationType = null;
      this.operaciones = [];
      this.pagination = {};
      this.activeFilters = {};

      // Limpiamos la URL al resetear
      window.history.pushState({}, "", window.location.pathname);
    },
    openModal(payload) {
      // 'payload' es el objeto que nos llega desde OperationItem
      // Contiene: { tipo, info, operacion, sc }
      // Datos de la factura SC
      const scData = payload && payload.sc;
      const desglose = scData && scData.desglose_conceptos;
      const montos = desglose && desglose.montos;

      console.log("Abriendo modal con:", payload);

      // Estructuramos los datos que el modal necesita
      this.modalAuditData = {
        tipo: payload && payload.tipo,
        operacion: payload && payload.operacion,
        factura: payload && payload.info && payload.info.datos, // Datos de la factura específica (flete, llc, etc.)
        sc: {
          // Sacamos el monto esperado del desglose de la SC
          montos_esperados: montos || {},
          tipo_cambio: desglose ? desglose.tipo_cambio : "N/A",
          moneda_original: desglose ? desglose.moneda : "",
        },
      };
      this.isModalVisible = true;
    },

    // Este método recibe los filtros de FilterBar y los guarda
    handleFilters(filtersFromBar) {
      this.activeFilters = filtersFromBar;
      this.fetchOperaciones();
    },

    // En AuditPage.vue -> methods

    fetchOperaciones(url = "/auditoria") {
      // La guarda inicial está perfecta.
      if (!this.selectedSucursal || !this.selectedOperationType || !url) return;

      this.isLoading = true;
      const cleanParams = this.cleanFilters(this.finalFilters);

      axios
        .get(url, { params: cleanParams })
        .then((response) => {
          this.operaciones = response.data.data;
          this.pagination = response.data;
          this.isLoading = false;

          // --- LÓGICA CORREGIDA ---
          // 1. Buscamos el enlace de la página ACTIVA que nos devuelve Laravel.
          const activeLink = response.data.links.find((link) => link.active);

          // 2. Si encontramos ese enlace y tiene una URL, la usamos para actualizar
          //    la barra de direcciones. Esta URL ya contiene TODOS los parámetros.
          if (activeLink && activeLink.url) {
            window.history.pushState({}, "", activeLink.url);
          }
        })
        .catch((error) => {
          console.error("Error al obtener las operaciones:", error);
          this.isLoading = false;
        });
    },
    cleanFilters(filters) {
      const cleaned = {};
      for (const key in filters) {
        // Solo añadimos la llave al nuevo objeto si su valor no es nulo ni vacío.
        if (filters[key] !== null && filters[key] !== "") {
          cleaned[key] = filters[key];
        }
      }
      return cleaned;
    },
  },
};
</script>

<style scoped>
.btn-selector {
  @apply bg-white text-theme-dark font-semibold py-3 px-6 rounded-lg shadow border border-gray-200 hover:bg-theme-primary hover:text-white transition-colors duration-200;
}
</style>
