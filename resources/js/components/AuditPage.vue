<template>
  <div class="p-8 bg-theme-light min-h-screen">
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

    <FilterBar @apply-filters="handleFilters" />
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
</template>

<script>
import OperationItem from "../components/OperationItem.vue"; // ¡Importante! Importa el componente hijo.
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
      operaciones: [], // Aquí guardaremos la lista que viene en la llave "data" del JSON
      pagination: {}, // Aquí guardaremos el resto de la info (links, total, etc.)
      isLoading: true, // Un "extra" para mostrar un mensaje de "Cargando..."
      isModalVisible: false, // VARIABLE DE VISIBILIDAD - Modal
      modalAuditData: null, // VARIABLE PARA DATOS - Modal
      isImportModalVisible: false, // VARIABLE PARA VISIBILIDAD DE IMPORTACION - Modal
      // 3. Guardaremos el estado de los filtros aquí
      activeFilters: {},
    };
  },
  mounted() {
    // --- NUEVA LÓGICA AQUÍ ---
    // 1. Leemos la URL actual del navegador
    const urlParams = new URLSearchParams(window.location.search);
    const page = urlParams.get("page"); // Buscamos si existe el parámetro 'page'

    // 2. Construimos la URL inicial para la API
    // Si hay un parámetro 'page', lo usamos. Si no, cargamos la página por defecto.
    const initialUrl = page ? `/auditoria?page=${page}` : "/auditoria";

    // 3. Llamamos a la API con la URL correcta
    this.fetchOperaciones(initialUrl);
  },
  methods: {
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
    //Este método se activa cuando FilterBar emite el evento
    handleFilters(filters) {
      // Guardamos los filtros y pedimos la página 1 con esos filtros
      this.activeFilters = filters;
      this.fetchOperaciones();
    },

    fetchOperaciones(url = "/auditoria") {
      // El parámetro 'url' es crucial
      if (!url) return;
      this.isLoading = true;
      const cleanParams = this.cleanFilters(this.activeFilters);
      // Axios permite pasar los filtros como un objeto 'params'
      axios
        .get(url, { params: cleanParams })
        .then((response) => {
          this.operaciones = response.data.data;
          this.pagination = response.data; // La paginación ya viene filtrada desde Laravel
          this.isLoading = false;
          // No necesitamos history.pushState aquí porque withQueryString() ya construye la URL correcta
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
