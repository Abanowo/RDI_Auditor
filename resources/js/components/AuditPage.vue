<template>
  <div class="p-4 sm:p-8 bg-gray-100 min-h-screen">
    <!-- SECCIÓN DE SELECCIÓN DE SUCURSAL Y OPERACIÓN -->
    <div class="space-y-4">
      <!-- Fila de Sucursales -->
      <div class="bg-yellow-600 p-6 rounded-lg shadow">
        <div class="flex flex-row justify-center gap-2">
          <button
            v-for="sucursal in sucursales"
            :key="sucursal.id"
            @click="selectSucursal(sucursal)"
            class="btn-sucursal"
            :class="{ active: selectedSucursal && selectedSucursal.id === sucursal.id }"
          >
            {{ sucursal.nombre }}
          </button>
          <button
            @click="selectSucursal({ id: 'todos', nombre: 'Todas' })"
            class="btn-sucursal"
            :class="{ active: selectedSucursal && selectedSucursal.id === 'todos' }"
          >
            Todas
          </button>
        </div>
      </div>

      <!-- Fila de Tipos de Operación (solo visible si se ha seleccionado una sucursal) -->
      <div v-if="selectedSucursal" class="my-6">
        <div class="w-full md:w-2/5 flex gap-4 my-6">
          <!-- Botón Importación -->
          <button
            @click="selectOperationType('importacion')"
            class="btn-operation bg-white flex-1"
            :class="{ active: selectedOperationType === 'importacion' }"
          >
            <div class="flex items-center">
              <div
                class="w-12 h-12 mr-4 bg-green-300 rounded flex items-center justify-center"
              >
                <svg
                  class="w-6 h-6 text-white"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path d="M12 22V11" />
                  <path
                    d="M12 11H8.5C7.67 11 7 11.67 7 12.5V14.5C7 15.33 7.67 16 8.5 16H12"
                  />
                  <path
                    d="M12 11H15.5C16.33 11 17 11.67 17 12.5V14.5C17 15.33 16.33 16 15.5 16H12"
                  />
                  <path d="M2 16H7" />
                  <path d="M17 16H22" />
                  <path d="M15 8L12 11L9 8" />
                  <path d="M12 11V3" />
                  <path d="M2 16.5H4.95C5.23 16.5 5.45 16.72 5.45 17V22" />
                  <path d="M22 16.5H19.05C18.77 16.5 18.55 16.72 18.55 17V22" />
                </svg>
              </div>

              <div class="text-left">
                <span class="font-bold text-lg whitespace-nowrap">Importación</span>
              </div>
            </div>
            <span class="text-2xl font-bold">{{ operationCounts.importacion }}</span>
          </button>

          <!-- Botón Exportación -->
          <button
            @click="selectOperationType('exportacion')"
            class="btn-operation bg-white flex-1"
            :class="{ active: selectedOperationType === 'exportacion' }"
          >
            <div class="flex items-center">
              <div
                class="w-12 h-12 mr-4 bg-yellow-500 rounded flex items-center justify-center"
              >
                <svg
                  class="w-6 h-6 text-white"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                >
                  <path d="M12 22V16" />
                  <path
                    d="M12 16H8.5C7.67 16 7 15.33 7 14.5V12.5C7 11.67 7.67 11 8.5 11H12"
                  />
                  <path
                    d="M12 16H15.5C16.33 16 17 15.33 17 14.5V12.5C17 11.67 16.33 11 15.5 11H12"
                  />
                  <path d="M2 11H7" />
                  <path d="M17 11H22" />
                  <path d="M15 6L12 3L9 6" />
                  <path d="M12 3V11" />
                  <path d="M2 16.5H4.95C5.23 16.5 5.45 16.72 5.45 17V22" />
                  <path d="M22 16.5H19.05C18.77 16.5 18.55 16.72 18.55 17V22" />
                </svg>
              </div>

              <div class="text-left">
                <span class="font-bold text-lg whitespace-nowrap">Exportación</span>
              </div>
            </div>
            <span class="text-2xl font-bold">{{ operationCounts.exportacion }}</span>
          </button>

          <!-- Botón Ambas -->
          <button
            @click="selectOperationType('todos')"
            class="btn-operation bg-white flex-1"
            :class="{ active: selectedOperationType === 'todos' }"
          >
            <div class="flex items-center">
              <div
                class="w-12 h-12 mr-4 bg-indigo-500 rounded flex items-center justify-center"
              >
                <svg
                  class="w-8 h-8 text-white"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"
                  />
                  <path d="m3.3 8 8.7 5 8.7-5" />
                  <path d="M12 22.1V13" />
                </svg>
              </div>

              <div class="text-left">
                <span class="font-bold text-lg whitespace-nowrap">Todas</span>
              </div>
            </div>
            <span class="text-2xl font-bold">{{ operationCounts.todos }}</span>
          </button>
        </div>

        <div class="justify-end md:w-2/5 flex gap-4 my-6"></div>
      </div>
    </div>

    <!-- SECCION DE PANEL DE AUDITORIAS -->
    <div v-if="selectionComplete">
      <div class="flex justify-between items-center my-6">
        <h1 class="text-3xl font-bold text-theme-dark">
          Auditoría de Pago por Cuenta del Cliente
        </h1>

        <!-- Fila de boton de Exportacion (Archivos) y Subida de Estados de cuenta -->
        <div class="flex items-center space-x-4">
          <!-- Botón Exportar reporte  -->
          <button
            @click="exportUrl"
            class="w-64 bg-green-600 text-white text-center font-bold py-4 px-4 rounded-md shadow-sm hover:opacity-90 flex items-center justify-center"
          >
            <svg
              class="w-8 h-8 mr-4"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke="currentColor"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M19 10V4a1 1 0 0 0-1-1H9.914a1 1 0 0 0-.707.293L5.293 7.207A1 1 0 0 0 5 7.914V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2M10 3v4a1 1 0 0 1-1 1H5m5 6h9m0 0-2-2m2 2-2 2"
              />
            </svg>
            <div class="text-left">
              <span class="font-bold text-lg">Exportar reporte</span>
            </div>
          </button>

          <!-- Botón Subir estados de cuenta  -->
          <button
            class="w-72 bg-blue-600 text-white font-bold py-4 px-4 rounded-lg shadow hover:opacity-90 flex items-center justify-center"
            @click="isImportModalVisible = true"
          >
            <svg
              class="w-8 h-8 mr-4"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke="currentColor"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M15 17h3a3 3 0 0 0 0-6h-.025a5.56 5.56 0 0 0 .025-.5A5.5 5.5 0 0 0 7.207 9.021C7.137 9.017 7.071 9 7 9a4 4 0 1 0 0 8h2.167M12 19v-9m0 0-2 2m2-2 2 2"
              />
            </svg>
            <div class="text-left">
              <span class="font-bold text-lg">Subir estados de cuenta</span>
            </div>
          </button>
        </div>
      </div>
      <!-- Fila de botones de contador y porcentaje de efectividad -->
      <div class="grid lg:grid-cols-5 sm:grid-cols-1 md:grid-cols-2 gap-4 my-4">

        <!-- Botón Balanceados -->
        <button class="btn-operation bg-white focus:ring-blue-500"
        :class="{'ring-2 ring-blue-600': buttonStatusFilter === 'Saldados!'}"
        @click="fetchOperacionesBotonesContadores(auditCounts.balanceados.label)"
        >
          <div class="flex items-center">
            <div
              class="w-12 h-12 mr-4 bg-green-600 rounded flex text-white items-center justify-center"
            >
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M10 3v4a1 1 0 0 1-1 1H5m4 6 2 2 4-4m4-8v16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7.914a1 1 0 0 1 .293-.707l3.914-3.914A1 1 0 0 1 9.914 3H18a1 1 0 0 1 1 1Z"
                />
              </svg>
            </div>
            <div class="text-left">
              <span class="font-bold text-lg">Saldados</span>
            </div>
          </div>
          <div class="flex flex-col items-end">

            <span class="text-xl font-bold">
                {{ Math.abs(auditCounts.balanceados.delta_sum ? auditCounts.balanceados.delta_sum : 0) | currency  }}
                <span class="text-lg font-semibold">MXN</span>
            </span>

            <div class="px-2 py-1 rounded bg-gray-300 text-blue-800 text-s font-medium mt-1">
                Facturas: {{ auditCounts.balanceados.value }} ({{ auditCounts.balanceados.percentage }}%)
            </div>

          </div>
        </button>

        <!-- Botón Pago de menos -->
        <button class="btn-operation bg-white focus:ring-blue-500"
        :class="{'ring-2 ring-blue-600': buttonStatusFilter === 'Pago de menos!'}"
        @click="fetchOperacionesBotonesContadores(auditCounts.pago_menos.label)">

        <div class="flex items-center">
            <div class="w-12 h-12 mr-4 bg-red-700 rounded flex text-white items-center justify-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4.5V19a1 1 0 0 0 1 1h15M7 10l4 4 4-4 5 5m0 0h-3.207M20 15v-3.207" />
                </svg>
            </div>
            <div class="text-left">
                <span class="font-bold text-lg">Pagos de menos</span>
            </div>
        </div>

        <div class="flex flex-col items-end">

            <span class="text-xl font-bold">
               - {{ Math.abs(auditCounts.pago_menos.delta_sum ? auditCounts.pago_menos.delta_sum : 0) | currency  }}
                <span class="text-lg font-semibold">MXN</span>
            </span>

            <div class="px-2 py-1 rounded bg-gray-300 text-blue-800 text-s font-medium mt-1">
                Facturas: {{ auditCounts.pago_menos.value }} ({{ auditCounts.pago_menos.percentage }}%)
            </div>

        </div>
        </button>

        <!-- Botón Pago de mas -->
        <button class="btn-operation bg-white focus:ring-blue-500"
        :class="{'ring-2 ring-blue-600': buttonStatusFilter === 'Pago de mas!'}"
        @click="fetchOperacionesBotonesContadores(auditCounts.pago_mas.label)"
        >
          <div class="flex items-center">
            <div
              class="w-12 h-12 mr-4 bg-yellow-500 rounded flex text-white items-center justify-center"
            >
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M4 4.5V19a1 1 0 0 0 1 1h15M7 14l4-4 4 4 5-5m0 0h-3.207M20 9v3.207"
                />
              </svg>
            </div>

            <div class="text-left">
              <span class="font-bold text-lg">Pagos de más</span>
            </div>
          </div>
          <div class="flex flex-col items-end">

            <span class="text-xl font-bold">
                + {{ Math.abs(auditCounts.pago_mas.delta_sum ? auditCounts.pago_mas.delta_sum : 0) | currency  }}
                <span class="text-lg font-semibold">MXN</span>
            </span>

            <div class="px-2 py-1 rounded bg-gray-300 text-blue-800 text-s font-medium mt-1">
                Facturas: {{ auditCounts.pago_mas.value }} ({{ auditCounts.pago_mas.percentage }}%)
            </div>

          </div>
        </button>

        <!-- Botón No facturado -->
        <button class="btn-operation bg-white focus:ring-blue-500"
        :class="{'ring-2 ring-blue-600': buttonStatusFilter === 'Sin SC!'}"
        @click="fetchOperacionesBotonesContadores(auditCounts.no_facturados.label)"
        >
          <div class="flex items-center">
            <div
              class="w-12 h-12 mr-4 bg-gray-600 rounded flex text-white items-center justify-center"
            >
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M10 3v4a1 1 0 0 1-1 1H5m8 7.5 2.5 2.5M19 4v16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7.914a1 1 0 0 1 .293-.707l3.914-3.914A1 1 0 0 1 9.914 3H18a1 1 0 0 1 1 1Zm-5 9.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0Z"
                />
              </svg>
            </div>

            <div class="text-left">
              <span class="font-bold text-lg">No facturados</span>
            </div>
          </div>
           <div class="flex flex-col items-end">

            <span class="text-xl font-bold">
                +/- {{ Math.abs(auditCounts.no_facturados.delta_sum ? auditCounts.no_facturados.delta_sum : 0) | currency }}
                <span class="text-lg font-semibold">MXN</span>
            </span>

            <div class="px-2 py-1 rounded bg-gray-300 text-blue-800 text-s font-medium mt-1">
                Facturas: {{ auditCounts.no_facturados.value }} ({{ auditCounts.no_facturados.percentage }}%)
            </div>

          </div>
        </button>

        <!-- Botón Totales -->
        <button class="btn-operation bg-white"
        @click="fetchOperacionesBotonesContadores(null)"
        >
          <div class="flex items-center">
            <div
              class="w-12 h-12 mr-4 bg-blue-900 rounded flex text-white items-center justify-center"
            >

              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  fill-rule="evenodd"
                  d="M9 8h6m-6 4h6m-6 4h6M6 3v18l2-2 2 2 2-2 2 2 2-2 2 2V3l-2 2-2-2-2 2-2-2-2 2-2-2Z"
                  clip-rule="evenodd"
                />
              </svg>
            </div>

            <div class="text-left">
              <span class="font-bold text-lg">Total de auditorias</span>
            </div>
          </div>
           <span class="text-2xl font-bold">
            {{ auditTotalCount.total }}
        </span>
        </button>
      </div>
      <ImportModal
        :show="isImportModalVisible"
        @close="isImportModalVisible = false"
        @trigger-update="fetchOperaciones"
      />

      <FilterBar
        ref="filterBar"
        :selected-sucursal="selectedSucursal"
        :clientes="clientes"
        @apply-filters="handleFilters"
        :initial-filters="activeFilters"
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
      operationCounts: { importacion: 0, exportacion: 0, todos: 0 }, // Para guardar los conteos de las operaciones de Importacion, Exportacion, y Ambas
      auditCounts: { balanceados: 0, pago_menos: 0, pago_mas: 0, no_facturados: 0 }, // Para guardar los conteos de los pagos Balanceados, Pago de menos, Pago de mas y No facturados
      auditTotalCount: {total: 0, ocupoActualizarContadores: true},
      selectedSucursal: null,
      selectedOperationType: null,
      operaciones: [], // Aquí guardaremos la lista que viene en la llave "data" del JSON
      pagination: {}, // Aquí guardaremos el resto de la info (links, total, etc.)
      clientes: [],
      isLoading: false, // Un "extra" para mostrar un mensaje de "Cargando..."
      isModalVisible: false, // VARIABLE DE VISIBILIDAD - Modal
      modalAuditData: null, // VARIABLE PARA DATOS - Modal
      isImportModalVisible: false, // VARIABLE PARA VISIBILIDAD DE IMPORTACION - Modal
      // 3. Guardaremos el estado de los filtros aquí
      activeFilters: {},
      //Cambio por Filtro botones
      buttonStatusFilter: null, // NUEVA VARIABLE: null = inactivo, 'string' = activo, Su uso es para guardar el estado de los botones filtro, y decide si se utiliza el estado del boton o si se utiliza el estado de filterBar
    };
  },
  computed: {
    selectionComplete() {
      return this.selectedSucursal && this.selectedOperationType;
    },

    finalFilters() {
      console.log("--- SE ESTÁ CALCULANDO finalFilters ---");

      // Usamos JSON.stringify para obtener una "foto" del estado en este instante
      console.log(
        "El valor de this.activeFilters es:",
        JSON.parse(JSON.stringify(this.activeFilters))
      );

      if (!this.selectionComplete) {
        console.log("selectionComplete es false, devolviendo objeto vacío.");
        return {};
      }

      // 1. Construye los filtros base con lo que venga del FilterBar
        const baseFilters = {
            ...this.activeFilters,
            sucursal_id: this.selectedSucursal.id,
            operation_type: this.selectedOperationType,
        };

        // 2. ¡LÓGICA CLAVE! Si hay un filtro de botón activo, este SOBREESCRIBE el estado.
        if (this.buttonStatusFilter) {  //Cambio por Filtro botones
            baseFilters.estado = this.buttonStatusFilter;
        }

      console.log("Resultado final de finalFilters:", JSON.parse(JSON.stringify(baseFilters)));
      console.log("--------------------------------------");

      return baseFilters;
    },
  },
  // ...
  async mounted() {
    try {
      this.isLoading = true;

      // 1. Obtenemos las sucursales primero, es un dato esencial
      const response = await axios.get("/auditoria/sucursales");
      this.sucursales = response.data;

      // 2. Intentamos inicializar el estado a partir de la URL de forma separada
      this.initializeStateFromUrl();

      // 3. Verificamos qué se inicializó y actuamos en consecuencia
      if (this.selectedSucursal) {
        // Si hay sucursal, siempre cargamos los conteos de operación
        this.fetchOperationCounts(this.selectedSucursal.id);

        if (this.selectedOperationType) {
          // Si también hay tipo de operación, cargamos todo lo demás
          this.fetchClientes();
          this.fetchOperaciones(this.activeFilters.page || 1);
        } else {
          // Si solo hay sucursal, dejamos de cargar y esperamos al usuario
          this.isLoading = false;
        }
      } else {
        // Si no hay ni sucursal en la URL, no hacemos nada más
        this.isLoading = false;
      }
    } catch (error) {
      console.error("Error crítico durante el montaje:", error);
      this.isLoading = false;
    }
  },
  methods: {
    /**
     * MÉTODO MODIFICADO: Lee la URL y establece cualquier estado que encuentre.
     */
    initializeStateFromUrl() {
      const urlParams = new URLSearchParams(window.location.search);
      const filtersFromUrl = Object.fromEntries(urlParams.entries());
      this.activeFilters = filtersFromUrl; // Guardamos todos los filtros de la URL

      // Intenta establecer la sucursal si existe el parámetro
      const sucursalId = filtersFromUrl.sucursal_id;
      if (sucursalId) {
        const foundSucursal =
          this.sucursales.find(s => String(s.id) === sucursalId) ||
          (sucursalId === 'todos' ? { id: 'todos', nombre: 'Todas' } : null);
        if (foundSucursal) {
          this.selectedSucursal = foundSucursal;
        }
      }

      // Intenta establecer el tipo de operación si existe el parámetro
      const operationType = filtersFromUrl.operation_type;
      if (operationType) {
        this.selectedOperationType = operationType;
      }
    },
    //Con el proposito de quitarle el (-) a el numero negativo
    absoluteValue(number) {
      return Math.abs(number);
    },
    exportUrl() {
      // Tomamos los filtros activos y los convertimos a un query string
      const params = new URLSearchParams(this.finalFilters).toString();
      const urlParams = new URLSearchParams(window.location.search).toString();

      console.log(params);
      console.log(urlParams);
      const finalParams = params;

      window.open(`/auditoria/exportar?${finalParams}`, "_blank");
    },

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
      //this.selectedOperationType = null; // Resetea la operación para forzar una nueva selección
      if(this.selectedOperationType !== null){
        this.selectOperationType(this.selectedOperationType);
      }
      this.fetchOperationCounts(sucursal.id); // Llama al nuevo método para obtener los conteos de Importacion/Exportacion o Ambos.
    },

    // Nuevo método para obtener los conteos de SC
    fetchOperationCounts(sucursalId) {
      axios
        .get("/auditoria/conteo-sc-diario", { params: { sucursal_id: sucursalId } })
        .then((response) => {
          this.operationCounts = response.data;
        })
        .catch((error) => {
          console.error("Error al obtener conteo de SCs:", error);
          this.operationCounts = { importacion: 0, exportacion: 0, todos: 0 };
        });
    },

    selectOperationType(type, updateUrl = true) {
      this.selectedOperationType = type;

      // Esperamos a que Vue renderice el FilterBar después de que 'selectionComplete' se vuelva true
      this.$nextTick(() => {
        // 1. Ahora que el ref existe, llamamos al nuevo método para obtener los filtros
        const barFilters = this.$refs.filterBar.getFilters();

        // 2. Guardamos los filtros en el estado del padre
        this.activeFilters = barFilters;

        // 3. Actualizamos la URL con TODOS los filtros unificados
        const params = new URLSearchParams(this.finalFilters);
        window.history.pushState({}, "", `?${params.toString()}`);

        console.log(this.selectedSucursal);
        console.log(this.selectedOperationType);
        // 4. Llamamos a la API
        this.fetchClientes();
        this.fetchOperaciones(1);
        //this.fetchAuditCounts(this.selectedSucursal.id, this.selectedOperationType);
      });
    },

    resetSelection() {
      this.selectedSucursal = null;
      this.selectedOperationType = null;
      this.operaciones = [];

      this.operationCounts = {
        importacion: 0,
        exportacion: 0,
        todos: 0
      },

      this.auditCounts = {
          balanceados: 0,
          pago_menos: 0,
          pago_mas: 0,
          no_facturados: 0,
      },
      this.auditTotalCount = {total: 0, ocupoActualizarContadores: true},
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
      // Cambio por Filtro botones
      // Se vuelve nulo por default para reiniciar y volver a contemplar los filtros de FilterBar
      this.buttonStatusFilter = null;

      // Aplica los filtros que vienen del FilterBar
      this.activeFilters = filtersFromBar;

      // Actualizamos la URL con los nuevos filtros
      const params = new URLSearchParams(this.finalFilters);
      window.history.pushState({}, "", `?${params.toString()}`);

      // Hacemos la búsqueda (siempre a la página 1)
      this.fetchOperaciones(1);
    },

    setInitialFilters(initialFilters) {
      // Fusiona los filtros que vienen de la URL con el estado por defecto
      // para asegurar que todas las llaves existan y los campos se actualicen.
      this.filters = { ...this.initialFilters, ...initialFilters };
    },

    fetchOperaciones(page = 1) {
      if (!this.selectedSucursal || !this.selectedOperationType) return;

      this.isLoading = true;

      // 1. Construimos los parámetros combinando los filtros finales con la página
      const finalParams = {
        ...this.finalFilters,
        page: page,
      };
      // --- AÑADE ESTA LÍNEA DE DEPURECIÓN AQUÍ ---
      //alert("Datos que se enviarán a la API:\n\n" + JSON.stringify(finalParams, null, 2));
      // ---------------------------------------------

      // Hacemos la llamada a la API siempre al mismo endpoint base
      axios
        .get("/auditoria", { params: finalParams })
        .then((response) => {
          // Si response.data.data existe y es un array, úsalo; si no, usa []
          this.operaciones = Array.isArray(response.data.data) ? response.data.data : [];
          this.pagination = response.data;
          this.isLoading = false;

            // El arreglo de datos que viene de la API (para contadores y porcentajes)
            const statsFromApi = response.data.meta.conteos.stats || [];

            // Crea un nuevo objeto temporal para guardar los conteos actualizados
            const newCounts = {};

            // Itera sobre el ARREGLO de la API usando forEach
            statsFromApi.forEach(stat => {
                // Para cada estadística, usa su 'key' para crear una propiedad
                // en nuestro nuevo objeto y asígnale su 'value'.
                // Ejemplo: newCounts['pago_mas'] = (Objeto);
                newCounts[stat.key] = stat;
            });

            // Finalmente, reemplaza el objeto antiguo con el nuevo ya actualizado
            if(this.auditTotalCount.ocupoActualizarContadores) {
                this.auditCounts = newCounts;
                this.auditTotalCount.total = response.data.meta.conteos.total;
            }



          console.log("Response: ", response);
          console.log("Response.data: ", response.data);
          console.log("Response.data.links: ", response.data.links);
          console.log("Response.data.meta.conteos: ", response.data.meta.conteos);
          console.log("auditCounts: ", this.auditCounts);
          // Esta parte es crucial y ahora funcionará correctamente
          const activeLink = response?.data?.links?.find((link) => link.active);
          if (activeLink && activeLink.url) {
            // La URL de Laravel ahora sí contendrá todos los filtros,
            // porque la petición que le llegó era completa.
            const fullUrl = new URL(activeLink.url);
            window.history.pushState({}, "", `${fullUrl.pathname}${fullUrl.search}`);
          }
        })
        .catch((error) => {
          console.error("Error al obtener las operaciones:", error);
          this.operaciones = []; // asegúrate de vaciarla si hay error
          this.isLoading = false;
          this.auditTotalCount.ocupoActualizarContadores = true;
        });
    },

    // Metodo para conservar filtros despues de haber presionado un boton de filtros con conteo.
    //Cambio por Filtro botones
    fetchOperacionesBotonesContadores(estado) {
        // 1. Activa el modo de filtro por botón, guardando el estado deseado
        this.buttonStatusFilter = estado;

        // 2. Llama a la búsqueda principal (siempre a la página 1)
        this.fetchOperaciones(1);
    }

  },
};
</script>

<style scoped>
.btn-selector {
  @apply bg-white text-theme-dark font-semibold py-3 px-6 rounded-lg shadow border border-gray-200 hover:bg-blue-900 hover:text-white transition-colors duration-200;
}

.btn-sucursal {
  @apply w-full text-center py-2 px-4 rounded-md font-semibold text-gray-700 bg-white border border-gray-300 transition-colors duration-200 hover:bg-gray-200;
}
.btn-sucursal.active {
  @apply bg-blue-900 text-white border-blue-900;
}

.btn-operation {
  @apply flex items-center justify-between p-4 rounded-lg border border-gray-300 transition-all duration-200 hover:shadow-lg hover:border-blue-500;
}
.btn-operation.active {
  @apply bg-blue-900 text-white border-blue-900 shadow-lg;
}
</style>
