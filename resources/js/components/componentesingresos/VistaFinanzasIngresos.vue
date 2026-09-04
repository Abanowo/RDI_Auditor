<template>
  <div class="flex flex-col min-h-screen font-sans text-gray-800 p-8 lg:p-12" style="background-color: #F4F6F9;">

    <!-- ============================================== -->
    <!-- FILTRO POR SUCURSAL                            -->
    <!-- ============================================== -->
    <div class="flex w-full p-3 gap-3 mb-10 rounded-lg shadow-sm" style="background-color: #D69E2E;">
      <button v-for="sucursal in sucursalesBase" :key="sucursal" @click="cambiarSucursal(sucursal)"
        class="flex-1 py-4 text-xl font-extrabold transition-colors whitespace-nowrap rounded-md"
        :style="filtroSucursalActiva === sucursal ? 'background-color: #2A3A4D; color: #ffffff;' : 'background-color: #ffffff; color: #2A3A4D;'">
        {{ sucursal }}
      </button>
      <button @click="cambiarSucursal('Todas')"
        class="flex-1 py-4 text-xl font-extrabold transition-colors whitespace-nowrap rounded-md"
        :style="filtroSucursalActiva === 'Todas' ? 'background-color: #2A3A4D; color: #ffffff;' : 'background-color: #ffffff; color: #2A3A4D;'">
        Todas
      </button>
    </div>

    <!-- ENCABEZADO Y MENÚ SUPERIOR -->
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-10 gap-6">
      <div>
        <div class="flex items-center gap-4">
          <h1 class="text-5xl font-bold text-gray-800 tracking-tight">Registro de Ingresos</h1>
        </div>
      </div>
    </div>

    <!-- TARJETAS DE INDICADORES (KPIs) -->
    <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-6 mb-10">

      <!-- 1. INGRESOS TOTALES -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 border-l-4 flex justify-between items-center"
        style="border-left-color: #00C09F;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Ingresos Totales</p>
          <p class="text-4xl font-black text-gray-800 mb-2">{{ formatearDinero(totalDepositos) }}</p>
          <p class="text-sm font-bold" style="color: #00C09F;">Depósitos registrados</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-teal-100 text-teal-500 flex items-center justify-center shrink-0">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
            </path>
          </svg>
        </div>
      </div>

      <!-- 2. TOTAL CFDI (HONORARIOS) -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 border-l-4 flex justify-between items-center"
        style="border-left-color: #8B5CF6;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Total CFDI (Honorarios)</p>
          <p class="text-4xl font-black text-purple-700 mb-2">{{ formatearDinero(totalHonorarios) }}</p>
          <p class="text-sm font-bold" style="color: #8B5CF6;">Utilidad de la agencia</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-purple-100 text-purple-500 flex items-center justify-center shrink-0">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
            </path>
          </svg>
        </div>
      </div>

      <!-- 3. TOTAL NOTA CARGO (GPC) -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 border-l-4 flex justify-between items-center"
        style="border-left-color: #EC4899;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Total Nota Cargo (GPC)</p>
          <p class="text-4xl font-black text-pink-600 mb-2">{{ formatearDinero(totalNotaCargo) }}</p>
          <p class="text-sm font-bold" style="color: #EC4899;">Gastos por cuenta de cliente</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-pink-100 text-pink-500 flex items-center justify-center shrink-0">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z">
            </path>
          </svg>
        </div>
      </div>

      <!-- 4. SALDOS APLICADOS -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 border-l-4 flex justify-between items-center"
        style="border-left-color: #ED8936;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Saldos aplicados</p>
          <p class="text-4xl font-black text-gray-800 mb-2">{{ formatearDinero(totalSaldosAplicados) }}</p>
          <p class="text-sm font-bold" style="color: #ED8936;">Historial de aplicaciones</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-orange-100 text-orange-400 flex items-center justify-center shrink-0">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
            </path>
          </svg>
        </div>
      </div>

      <!-- 5. SALDOS A FAVOR -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 border-l-4 flex justify-between items-center"
        style="border-left-color: #4299E1;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Saldos a Favor del Cliente</p>
          <p class="text-4xl font-black text-gray-800 mb-2">{{ formatearDinero(totalSaldos) }}</p>
          <p class="text-sm font-bold" style="color: #4299E1;">Abonos listos</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-blue-100 text-blue-400 flex items-center justify-center shrink-0">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
            </path>
          </svg>
        </div>
      </div>

      <!-- 6. SALDOS EN CONTRA -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 border-l-4 flex justify-between items-center"
        style="border-left-color: #F54927;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Saldos en Contra del Cliente</p>
          <p class="text-4xl font-black text-gray-800 mb-2">{{ formatearDinero(totalSaldosEnContra) }}</p> 
        </div>
        <div class="w-16 h-16 rounded-xl bg-red-100 text-red-400 flex items-center justify-center shrink-0">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
            </path>
          </svg>
        </div>
      </div>
    </div>

    <!-- PESTAÑAS SECUNDARIAS -->
    <div class="flex flex-col lg:flex-row justify-between items-center mb-8 gap-6 border-b border-gray-300 pb-8">
      <div class="flex gap-4 w-full lg:w-auto overflow-x-auto">
        <button @click="activeTab = 'ingresos'"
          :class="['px-8 py-4 rounded-lg text-xl font-bold shadow-sm whitespace-nowrap transition-colors', activeTab === 'ingresos' ? 'bg-blue-500 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-100']">
          Ingresos
        </button>
        <button @click="activeTab = 'saldos'"
          :class="['px-6 py-3 rounded-lg text-base font-bold shadow-sm whitespace-nowrap transition-colors', activeTab === 'saldos' ? 'bg-blue-500 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-100']">
          Saldos a Favor y en Contra
          <br>del Cliente (Vigentes)
        </button>
        <button @click="activeTab = 'saldos_aplicados'"
          :class="['px-8 py-4 rounded-lg text-xl font-bold shadow-sm whitespace-nowrap transition-colors', activeTab === 'saldos_aplicados' ? 'bg-blue-500 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-100']">
          Saldos Aplicados
        </button>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- TAB 1: CONTENEDOR PRINCIPAL INGRESOS (CARDS)   -->
    <!-- ============================================== -->
    <div v-show="activeTab === 'ingresos'"
      class="bg-white rounded-xl shadow-sm border border-gray-200 flex-1 flex flex-col overflow-visible">
      <div class="p-8 border-b border-gray-200 flex justify-between items-center">
        <div>
          <h2 class="text-2xl font-extrabold text-gray-700 tracking-wide flex items-center gap-4">
            <span class="w-4 h-4 rounded-full bg-gray-300"></span>
            PLANILLA DE INGRESOS CONCILIADOS
          </h2>
          <p class="text-lg text-gray-500 mt-3">Control de desglose financiero directo. Los montos se actualizan y
            calculan automáticamente.</p>
        </div>
        <button @click="showModal = true"
          class="bg-green-600 hover:bg-green-700 text-white px-8 py-4 rounded-lg text-xl font-bold flex items-center gap-3 shadow-sm transition-colors">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Registrar Ingreso
        </button>
      </div>

      <!-- BARRA DE FILTROS INTERNOS DE INGRESOS -->
      <div class="bg-gray-100 border border-gray-200 rounded-xl p-5 mb-6 mx-8 mt-8 flex flex-col gap-5 shadow-sm">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2 text-gray-700">
            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z">
              </path>
            </svg>
            <span class="text-lg font-black uppercase tracking-wider">Filtros</span>
          </div>
          <button type="button" @click="limpiarFiltros"
            class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-5 rounded-lg flex items-center gap-2 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            Limpiar Filtros
          </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8 gap-4 pt-1">
          <div class="flex flex-col"><label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Cliente:</label>
            <multiselect v-model="filtros.cliente" :options="opcionesFiltroCliente" placeholder="Todos" 
              :searchable="true" :show-labels="false" @input="aplicarFiltrosYBuscar" class="custom-filter-multiselect"></multiselect>
          </div>
          <div class="flex flex-col"><label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Servicio:</label>
            <multiselect v-model="filtros.tipoServicio" :options="opcionesTipoServicio" placeholder="Todos"
              :searchable="true" :show-labels="false" @input="aplicarFiltrosYBuscar" class="custom-filter-multiselect"></multiselect>
          </div>
          <div class="flex flex-col"><label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Tipo
              Operación:</label>
            <multiselect v-model="filtros.tipoOperacion" :options="opcionesTipoOperacion" track-by="id" label="label"
              placeholder="Ambos" :searchable="false" :show-labels="false" @input="aplicarFiltrosYBuscar" class="custom-filter-multiselect"></multiselect>
          </div>
          <div class="flex flex-col"><label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Tipo
              Comprobante:</label>
            <multiselect v-model="filtros.tipo_comprobante" :options="opcionesComprobante" placeholder="Todos"
              :searchable="false" :show-labels="false" @input="aplicarFiltrosYBuscar" class="custom-filter-multiselect"></multiselect>
          </div>
          <div class="flex flex-col"><label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Fechas:</label>
            <VueCtkDateTimePicker v-model="filtros.rangoFechas" format="YYYY-MM-DD" formatted="YYYY-MM-DD"
              color="#1d4ed8" button-color="#1d4ed8" :range="true" label="Select date & time"
              @validate="aplicarFiltrosYBuscar" class="custom-filter-datepicker"></VueCtkDateTimePicker>
          </div>
          <div class="flex flex-col"><label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Envío de
              Comp.:</label>
            <multiselect v-model="filtros.estado_envio" :options="opcionesEstadoEnvio" track-by="id" label="label"
              placeholder="Todos" :searchable="false" :show-labels="false" @input="aplicarFiltrosYBuscar" class="custom-filter-multiselect"></multiselect>
          </div>
          <div class="flex flex-col">
            <label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Folio SC / Factura:</label>
            <multiselect v-model="filtros.folio_factura" :options="opcionesFolioFactura" placeholder="Todos"
              :searchable="true" :show-labels="false" @input="aplicarFiltrosYBuscar" class="custom-filter-multiselect"></multiselect>
          </div>
          <div class="flex flex-col">
            <label class="text-xs font-bold text-gray-500 uppercase mb-1 whitespace-nowrap">Folio Complemento:</label>
            <multiselect v-model="filtros.folio_complemento" :options="opcionesFolioComplemento" placeholder="Todos"
              :searchable="true" :show-labels="false" @input="aplicarFiltrosYBuscar" class="custom-filter-multiselect"></multiselect>
          </div>
        </div>
      </div>

      <!-- CONTENEDOR DE TARJETAS (SOLO DATOS DE LA PÁGINA ACTUAL) -->
      <div
        class="flex-1 overflow-y-auto custom-scrollbar px-6 pb-6 bg-gray-50 pt-4 rounded-b-xl flex flex-col relative">

        <!-- Indicador de carga -->
        <div v-if="cargandoRegistros"
          class="absolute inset-0 bg-white/50 z-10 flex flex-col items-center justify-center">
          <svg class="animate-spin h-10 w-10 text-indigo-600 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none"
            viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
            </path>
          </svg>
          <span class="text-sm font-bold text-gray-500">Cargando registros...</span>
        </div>

        <IngresoCard v-for="item in ingresosData" :key="item.id" :item="item" @generar="generarComplemento"
          @visualizar="visualizarComplemento" @enviar="enviarCorreoComplemento" @editar="abrirModalEditarIngreso"
          @eliminar="eliminarFila" @ver-desglose="mostrarDesgloseManzanillo" />

        <div v-if="!cargandoRegistros && ingresosData.length === 0"
          class="text-center py-16 text-lg text-gray-500 bg-white rounded-xl shadow-sm border border-gray-200 font-bold mt-4">
          No se encontraron registros de ingresos para estos filtros.
        </div>

        <div v-if="totalPages > 1" class="mt-auto pt-8 pb-4 flex justify-center">
          <div class="flex items-center gap-1 bg-white p-2 rounded-md shadow-sm border border-gray-200">

            <button @click="paginaAnterior" :disabled="currentPage === 1"
              class="px-3 py-1.5 border border-gray-200 bg-white text-gray-500 hover:bg-gray-50 rounded text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
              « Anterior
            </button>

            <template v-for="(page, index) in visiblePages">
              <span v-if="page === '...'" :key="'dots-' + index"
                class="px-3 py-1.5 text-gray-400 font-medium text-sm">...</span>
              <button v-else :key="'page-' + index" @click="gotoPage(page)"
                :class="['px-3 py-1.5 border rounded text-sm font-medium transition-colors min-w-[36px] text-center',
                  currentPage === page ? 'bg-blue-50 border-blue-200 text-blue-700 font-bold' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50']">
                {{ page }}
              </button>
            </template>

            <button @click="siguientePagina" :disabled="currentPage === totalPages"
              class="px-3 py-1.5 border border-gray-200 bg-white text-gray-500 hover:bg-gray-50 rounded text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
              Siguiente »
            </button>

          </div>
        </div>
        <div v-if="totalRegistros > 0" class="text-center text-xs font-bold text-gray-400 mt-2">
          Mostrando {{ ingresosData.length }} de {{ totalRegistros }} registros encontrados
        </div>

      </div>
    </div>

    <!-- ============================================== -->
    <!-- TAB 2: CARTERA DE SALDOS A FAVOR (VIGENTES)    -->
    <!-- ============================================== -->
    <div v-show="activeTab === 'saldos'" class="flex-1 flex flex-col">
      <div class="flex justify-between items-end mb-6">
        <div>
          <h2 class="text-2xl font-black text-gray-800 uppercase tracking-wide">CARTERA DE SALDOS A FAVOR DE CLIENTES
            (VIGENTES)</h2>
          <p class="text-lg text-gray-500 mt-3">Registros de saldo a favor detectados o notas de crédito que pueden
            aplicarse en despachos futuros</p>
        </div>
        <button @click="abrirModalNuevoSaldo"
          class="bg-green-600 hover:bg-green-700 text-white px-8 py-4 rounded-lg text-xl font-bold flex items-center gap-3 shadow-sm transition-colors">
          <span class="text-3xl leading-none mt-[-2px]">+</span> Registrar Saldo a Favor
        </button>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-2">
        <table class="w-full text-left whitespace-nowrap min-w-max">
          <thead class="bg-gray-50 text-gray-800 text-sm font-black uppercase tracking-wider border-b border-gray-200">
            <tr>
              <th class="px-6 py-5" style="width: 250px;">CLIENTE</th>
              <th class="px-6 py-5" style="width: 200px;">SUCURSAL ORIGEN</th>
              <th class="px-6 py-5 text-center" style="width: 200px;">MONTO DE CRÉDITO</th>
              <th class="px-6 py-5 text-center" style="width: 220px;">FECHA DE DETECCIÓN</th>
              <th class="px-6 py-5">CONCEPTO O JUSTIFICACIÓN</th>
              <th class="px-6 py-5 text-center" style="width: 140px;">ESTATUS</th>
              <th class="px-6 py-5 text-right" style="width: 280px;">ACCIÓN</th>
            </tr>
          </thead>
          <tbody class="text-gray-700 font-medium text-base">
            <tr v-for="saldo in saldosVigentesFiltrados" :key="saldo.id"
              class="border-b border-gray-100 hover:bg-gray-50 transition-colors bg-white">
              <td class="px-6 py-5 font-bold text-gray-900">{{ saldo.cliente }}</td>
              <td class="px-6 py-5 text-gray-600">{{ saldo.sucursal_origen }}</td>
              <td class="px-6 py-5 text-center font-black text-gray-900">{{ formatearDinero(saldo.monto) }}</td>
              <td class="px-6 py-5 text-center text-gray-600">{{ saldo.fecha_deteccion }}</td>
              <td class="px-6 py-5 text-gray-600">{{ saldo.concepto }}</td>
              <td class="px-6 py-5 text-center">
                <span
                  class="px-4 py-1.5 bg-white border border-gray-200 text-gray-800 font-black rounded-full uppercase text-xs tracking-widest shadow-sm">VIGENTE</span>
              </td>
              <td class="px-6 py-5 text-right">
                <div class="flex items-center justify-end gap-2">
                  <button @click="editarSaldo(saldo)"
                    class="text-indigo-600 bg-indigo-100 hover:bg-indigo-200 p-2.5 rounded-lg transition-colors"
                    title="Editar">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
                      </path>
                    </svg>
                  </button>
                  <button @click="eliminarSaldo(saldo.id)"
                    class="text-red-500 bg-red-100 hover:bg-red-200 p-2.5 rounded-lg transition-colors"
                    title="Eliminar">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                      </path>
                    </svg>
                  </button>
                  <button @click="notificarCliente(saldo)"
                    class="text-blue-500 bg-blue-100 hover:bg-blue-200 p-2.5 rounded-lg transition-colors"
                    title="Notificar">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                      </path>
                    </svg>
                  </button>
                  <button @click="aplicarSaldo(saldo.id)"
                    class="bg-green-600 hover:bg-green-700 text-white px-5 py-2.5 rounded-lg font-bold text-sm transition-colors uppercase tracking-wider shadow-sm ml-2">APLICAR</button>
                </div>
              </td>
            </tr>
            <tr v-if="saldosVigentesFiltrados.length === 0">
              <td colspan="7" class="text-center py-16 text-lg text-gray-400 bg-gray-50 font-medium">No se encontraron
                saldos vigentes.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- TAB 3: SALDOS APLICADOS                        -->
    <!-- ============================================== -->
    <div v-show="activeTab === 'saldos_aplicados'" class="flex-1 flex flex-col">
      <div class="flex justify-between items-end mb-6">
        <div>
          <h2 class="text-2xl font-black text-gray-700 uppercase tracking-wide">HISTORIAL DE SALDOS APLICADOS</h2>
          <p class="text-lg text-gray-500 mt-3">Registros de saldos que ya fueron utilizados o aplicados en despachos
            anteriores</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-2">
        <table class="w-full text-left whitespace-nowrap min-w-max">
          <thead class="bg-gray-50 text-gray-800 text-sm font-black uppercase tracking-wider border-b border-gray-200">
            <tr>
              <th class="px-6 py-5" style="width: 250px;">CLIENTE</th>
              <th class="px-6 py-5" style="width: 200px;">SUCURSAL ORIGEN</th>
              <th class="px-6 py-5 text-center" style="width: 200px;">MONTO DE CRÉDITO</th>
              <th class="px-6 py-5 text-center" style="width: 220px;">FECHA DE DETECCIÓN</th>
              <th class="px-6 py-5">CONCEPTO O JUSTIFICACIÓN</th>
              <th class="px-6 py-5 text-center" style="width: 140px;">ESTATUS</th>
              <th class="px-6 py-5 text-right" style="width: 200px;">ACCIÓN</th>
            </tr>
          </thead>
          <tbody class="text-gray-700 font-medium text-base">
            <tr v-for="saldo in saldosAplicadosFiltrados" :key="saldo.id"
              class="border-b border-gray-200 bg-gray-100 hover:bg-gray-200 transition-colors">
              <td class="px-6 py-5 font-bold text-gray-600">{{ saldo.cliente }}</td>
              <td class="px-6 py-5 text-gray-500">{{ saldo.sucursal_origen }}</td>
              <td class="px-6 py-5 text-center font-black text-gray-600">{{ formatearDinero(saldo.monto) }}</td>
              <td class="px-6 py-5 text-center text-gray-500">{{ saldo.fecha_deteccion }}</td>
              <td class="px-6 py-5 text-gray-500">{{ saldo.concepto }}</td>
              <td class="px-6 py-5 text-center">
                <span
                  class="px-4 py-1.5 bg-gray-200 border border-gray-300 text-gray-500 font-black rounded-full uppercase text-xs tracking-widest shadow-inner">APLICADO</span>
              </td>
              <td class="px-6 py-5 text-right">
                <button @click="reactivarSaldo(saldo.id)"
                  class="bg-white hover:bg-blue-100 text-blue-600 hover:text-blue-700 px-5 py-2.5 rounded-lg font-bold text-sm transition-colors border border-blue-200 shadow-sm uppercase tracking-wider">
                  Reactivar
                </button>
              </td>
            </tr>
            <tr v-if="saldosAplicadosFiltrados.length === 0">
              <td colspan="7" class="text-center py-16 text-lg text-gray-400 bg-gray-100 font-medium">No se encontraron
                saldos aplicados.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- MODALES                                        -->
    <!-- ============================================== -->
    <ModalNuevoIngreso v-if="showModal" :opciones-sucursal="opcionesSucursal" :opciones-banco="opcionesBanco"
      :opciones-cliente="opcionesClienteObj" @close="showModal = false" @ingreso-guardado="onIngresoGuardado" />

    <ModalNuevoSaldoFavor v-if="showModalSaldo" :opciones-cliente="opcionesClienteObj"
      :opciones-sucursal="opcionesSucursal" @close="showModalSaldo = false" @saldo-guardado="onSaldoGuardado" />

    <ModalEditarSaldoFavor v-if="showModalEditarSaldo" :opciones-cliente="opcionesClienteObj"
      :opciones-sucursal="opcionesSucursal" :saldo-base="saldoAEditar" @close="showModalEditarSaldo = false"
      @saldo-actualizado="onSaldoActualizado" />

    <ModalEditarIngreso v-if="showModalEditarIngreso" :ingreso-base="ingresoAEditar"
      :opciones-sucursal="opcionesSucursal" :opciones-banco="opcionesBanco" :opciones-cliente="opcionesClienteObj"
      @close="showModalEditarIngreso = false" @ingreso-actualizado="onIngresoActualizadoDesdeModal" />

    <ModalComplementoPago :mostrar="showModalComplemento" :ingreso="ingresoParaComplemento"
      @cerrar="showModalComplemento = false" @generar="procesarComplementoBackend" />

    <ModalVerDocumento :mostrar="showModalVerDocumento" :url-pdf="urlDocumentoActivo" :titulo="tituloDocumentoActivo"
      @cerrar="showModalVerDocumento = false" />

    <ModalEnviarCorreo :mostrar="showModalEnviarCorreo" :ingreso="ingresoParaCorreo"
      @cerrar="showModalEnviarCorreo = false" />

  </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';
import 'vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';
import IngresoCard from './IngresoCard.vue';
import ModalNuevoIngreso from './ModalNuevoIngreso.vue';
import ModalNuevoSaldoFavor from './ModalNuevoSaldoFavor.vue';
import ModalEditarSaldoFavor from './ModalEditarSaldoFavor.vue';
import ModalEditarIngreso from './ModalEditarIngreso.vue';
import ModalComplementoPago from './ModalComplementoPago.vue';
import ModalVerDocumento from './ModalVerDocumento.vue';
import ModalEnviarCorreo from './ModalEnviarCorreo.vue';

export default {
  name: 'VistaFinanzasIngresos',
  components: {
    IngresoCard,
    ModalNuevoIngreso,
    ModalNuevoSaldoFavor,
    ModalEditarSaldoFavor,
    ModalEditarIngreso,
    ModalComplementoPago,
    ModalVerDocumento,
    ModalEnviarCorreo,
    Multiselect,
    VueCtkDateTimePicker
  },
  data() {
    return {
      usuario: window.UsuarioActual || {},

      activeTab: 'ingresos',
      cargandoRegistros: false,
      showModal: false,
      showModalSaldo: false,
      showModalEditarSaldo: false,
      showModalEditarIngreso: false,
      showModalComplemento: false,

      showModalEnviarCorreo: false,
      ingresoParaCorreo: null,

      ingresoAEditar: null,
      saldoAEditar: null,
      ingresoParaComplemento: null,

      showModalVerDocumento: false,
      urlDocumentoActivo: '',
      tituloDocumentoActivo: '',

      ingresosData: [],
      saldosData: [],

      sucursalesBase: [],
      filtroSucursalActiva: 'Todas',

      opcionesSucursal: [],
      opcionesBanco: [],
      opcionesFiltroCliente: [],
      opcionesClienteObj: [],

      opcionesComprobante: ['Todos', 'Ambos', 'CFDI', 'Nota Cargo'],
      opcionesTipoServicio: ['Todos', 'InTactics', 'INTSHIPPERTS', 'Transportactics'],

      opcionesTipoOperacion: [
        { id: 'Ambos', label: 'Ambos' },
        { id: 'IMPO', label: 'Importación (IMPO)' },
        { id: 'EXPO', label: 'Exportación (EXPO)' }
      ],

      opcionesEstadoEnvio: [
        { id: '', label: 'Todos' },
        { id: 'ENVIADO', label: 'Enviados' },
        { id: 'PENDIENTE', label: 'Pendientes' }
      ],

      opcionesFolioFactura: [],
      opcionesFolioComplemento: [],

      filtros: {
        cliente: 'Todos',
        rangoFechas: null,
        tipoOperacion: { id: 'Ambos', label: 'Ambos' },
        tipo_comprobante: 'Todos',
        tipoServicio: 'Todos',
        estado_envio: { id: '', label: 'Todos' },
        folio_factura: 'Todos',
        folio_complemento: 'Todos'
      },

      // Datos de Paginación del Backend
      currentPage: 1,
      itemsPerPage: 10,
      totalRegistros: 0,

      // Totales KPIs del Backend (Fallback a suma local)
      kpisTotales: {
        depositos: null,
        honorarios: null,
        notaCargo: null
      }
    }
  },
  mounted() {
    this.cargarCatalogos();
    this.cargarIngresos();
    this.cargarSaldos();
    this.obtenerUsuarioActual();
  },
  computed: {
    // Paginación Matemáticas
    totalPages() {
      return Math.ceil(this.totalRegistros / this.itemsPerPage) || 1;
    },
    visiblePages() {
      const total = this.totalPages;
      const current = this.currentPage;

      if (total <= 12) {
        return Array.from({ length: total }, (_, i) => i + 1);
      }

      if (current <= 6) {
        return [1, 2, 3, 4, 5, 6, 7, 8, '...', total - 1, total];
      }

      if (current >= total - 5) {
        return [1, 2, '...', total - 7, total - 6, total - 5, total - 4, total - 3, total - 2, total - 1, total];
      }

      return [1, 2, '...', current - 2, current - 1, current, current + 1, current + 2, '...', total - 1, total];
    },

    // KPIs
    totalDepositos() {
      if (this.kpisTotales.depositos !== null) {
        return this.kpisTotales.depositos;
      }
      const lista = Array.isArray(this.ingresosData) ? this.ingresosData : [];
      return lista.reduce((acc, item) => acc + (Number(item.monto_deposito) || 0), 0);
    },
    totalHonorarios() {
      if (this.kpisTotales.honorarios !== null) {
        return this.kpisTotales.honorarios;
      }
      const lista = Array.isArray(this.ingresosData) ? this.ingresosData : [];
      return lista.reduce((acc, item) => acc + (Number(item.honorarios) || 0), 0);
    },
    totalNotaCargo() {
      if (this.kpisTotales.notaCargo !== null) {
        return this.kpisTotales.notaCargo;
      }
      const lista = Array.isArray(this.ingresosData) ? this.ingresosData : [];
      return lista.reduce((acc, item) => {
        const sucursal = item.sucursal_origen ? item.sucursal_origen.toUpperCase() : '';
        const esManzanilloRow = sucursal.includes('MANZANILLO') || sucursal.includes('INTSHIPPERT');
        if (esManzanilloRow) {
          return acc + (Number(item.impuestos) || 0) + (Number(item.flete) || 0) +
            (Number(item.anticipo) || 0) + (Number(item.garantias) || 0) + (Number(item.desglose_naviera) || 0);
        }
        const gpc = (Number(item.impuestos) || 0) + (Number(item.eci) || 0) +
          (Number(item.maniobras) || 0) + (Number(item.flete) || 0) +
          (Number(item.muestras) || 0) + (Number(item.llc) || 0);
        return acc + gpc;
      }, 0);
    },

    saldosVigentesFiltrados() {
      const lista = Array.isArray(this.saldosData) ? this.saldosData : [];
      return lista.filter(s => {
        if (s.estatus !== 'VIGENTE') {
          return false;
        }
        
        // Excluimos saldos en contra si la BD los marca en un campo tipo/concepto o monto negativo
        const tipo = String(s.tipo || s.concepto || '').toUpperCase();
        if (tipo.includes('CONTRA') || Number(s.monto) < 0) {
          return false;
        }

        if (this.filtroSucursalActiva === 'Todas') {
          return true;
        }
        return s.sucursal_origen && s.sucursal_origen.toUpperCase().includes(this.filtroSucursalActiva.toUpperCase());
      });
    },

    saldosEnContraVigentesFiltrados() {
      const lista = Array.isArray(this.saldosData) ? this.saldosData : [];
      return lista.filter(s => {
        if (s.estatus !== 'VIGENTE') {
          return false;
        }
        
        // Detectamos si es un saldo en contra (por campo tipo, texto del concepto o monto negativo)
        const tipo = String(s.tipo || s.concepto || '').toUpperCase();
        const esEnContra = tipo.includes('CONTRA') || Number(s.monto) < 0;
        if (!esEnContra) {
          return false;
        }

        if (this.filtroSucursalActiva === 'Todas') {
          return true;
        }
        return s.sucursal_origen && s.sucursal_origen.toUpperCase().includes(this.filtroSucursalActiva.toUpperCase());
      });
    },

    saldosAplicadosFiltrados() {
      const lista = Array.isArray(this.saldosData) ? this.saldosData : [];
      return lista.filter(s => {
        if (s.estatus !== 'APLICADO') {
          return false;
        }
        if (this.filtroSucursalActiva === 'Todas') {
          return true;
        }
        return s.sucursal_origen && s.sucursal_origen.toUpperCase().includes(this.filtroSucursalActiva.toUpperCase());
      });
    },

    // CÁLCULOS SEGUROS
    totalSaldos() {
      const lista = Array.isArray(this.saldosVigentesFiltrados) ? this.saldosVigentesFiltrados : [];
      return lista.reduce((acc, item) => acc + Math.abs(Number(item.monto) || 0), 0);
    },

    totalSaldosEnContra() {
      const lista = Array.isArray(this.saldosEnContraVigentesFiltrados) ? this.saldosEnContraVigentesFiltrados : [];
      return lista.reduce((acc, item) => acc + Math.abs(Number(item.monto) || 0), 0);
    },

    totalSaldosAplicados() {
      const lista = Array.isArray(this.saldosAplicadosFiltrados) ? this.saldosAplicadosFiltrados : [];
      return lista.reduce((acc, item) => acc + Math.abs(Number(item.monto) || 0), 0);
    }
  },
  methods: {
    // Cuando el usuario usa el buscador/filtros manuales
    aplicarFiltrosYBuscar() {
      this.currentPage = 1;
      this.cargarIngresos();
    },
    cambiarSucursal(sucursal) {
      this.filtroSucursalActiva = sucursal;
      this.currentPage = 1;
      this.cargarIngresos();
    },

    // Controles Paginación
    paginaAnterior() {
      if (this.currentPage > 1) {
        this.currentPage--;
        this.cargarIngresos();
      }
    },
    siguientePagina() {
      if (this.currentPage < this.totalPages) {
        this.currentPage++;
        this.cargarIngresos();
      }
    },
    gotoPage(page) {
      this.currentPage = page;
      this.cargarIngresos();
    },

    async cargarIngresos() {
      this.cargandoRegistros = true;
      try {
        // Empaquetamos TODOS los filtros y la página al Backend
        const params = {
          page: this.currentPage,
          per_page: this.itemsPerPage,
          sucursal: this.filtroSucursalActiva,
          cliente: this.filtros.cliente,
          tipo_servicio: this.filtros.tipoServicio,
          tipo_operacion: this.filtros.tipoOperacion.id,
          tipo_comprobante: this.filtros.tipo_comprobante,
          estado_envio: this.filtros.estado_envio.id,
          fecha_inicio: this.filtros.rangoFechas ? this.filtros.rangoFechas.start : null,
          fecha_fin: this.filtros.rangoFechas ? this.filtros.rangoFechas.end : null,
          folio_factura: this.filtros.folio_factura,
          folio_complemento: this.filtros.folio_complemento
        };

        const response = await axios.get('/ingresos-conciliados', { params });

        // Evaluamos si el backend ya usa paginate() o si sigue mandando el array crudo
        if (response.data.data !== undefined) {
          // El backend ya fue actualizado con ->paginate()
          this.ingresosData = response.data.data;
          this.totalRegistros = response.data.total;

          // Si el backend envía los totales (kpis), los asignamos.
          if (response.data.kpis) {
            this.kpisTotales.depositos = response.data.kpis.depositos;
            this.kpisTotales.honorarios = response.data.kpis.honorarios;
            this.kpisTotales.notaCargo = response.data.kpis.notaCargo;
          }
        } else {
          // Fallback (Si el Backend aún no se actualiza, usamos el array crudo y simulamos)
          this.ingresosData = response.data.slice(0, 10);
          this.totalRegistros = response.data.length;
          this.kpisTotales.depositos = null; // Fuerza a sumar locamente
        }
      } catch (error) {
        console.error("Error cargando ingresos", error);
      } finally {
        this.cargandoRegistros = false;
      }
    },

    limpiarFiltros() {
      this.filtroSucursalActiva = 'Todas';
      this.filtros = {
        cliente: 'Todos',
        rangoFechas: null,
        tipoOperacion: { id: 'Ambos', label: 'Ambos' },
        tipo_comprobante: 'Todos',
        tipoServicio: 'Todos',
        estado_envio: { id: '', label: 'Todos' },
        folio_factura: 'Todos',
        folio_complemento: 'Todos'
      };
      this.currentPage = 1;
      this.cargarIngresos();
    },

    formatearDinero(monto) {
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2
      }).format(parseFloat(monto) || 0);
    },

    // Resto de métodos de Modales y Acciones
    generarComplemento(item) {
      this.ingresoParaComplemento = item;
      this.showModalComplemento = true;
    },
    visualizarComplemento(item) {
      this.tituloDocumentoActivo = `Complemento - ${item.cliente} (${item.sucursal_origen})`;
      this.urlDocumentoActivo = `/ingresos-conciliados/${item.id}/complemento/pdf`;
      this.showModalVerDocumento = true;
    },
    enviarCorreoComplemento(item) {
      this.ingresoParaCorreo = item;
      this.showModalEnviarCorreo = true;
    },
    abrirModalEditarIngreso(item) {
      this.ingresoAEditar = item;
      this.showModalEditarIngreso = true;
    },
    onIngresoActualizadoDesdeModal() {
      this.showModalEditarIngreso = false;
      this.cargarIngresos();
      this.cargarSaldos();
    },
    async cargarCatalogos() {
      try {
        const response = await axios.get('/ingresos-conciliados/opciones');
        this.opcionesSucursal = response.data.sucursales;

        if (response.data.sucursalesBase) {
          this.sucursalesBase = response.data.sucursalesBase.filter(sucursal => {
            const nombre = String(sucursal).toUpperCase();
            return !nombre.includes('INTSHIPPERT') && !nombre.includes('TRANSPORTACTIC');
          });
        } else {
          this.sucursalesBase = [];
        }

        this.opcionesBanco = response.data.bancos;
        this.opcionesClienteObj = response.data.clientes;
        const nombresClientes = response.data.clientes.map(c => c.nombre);
        this.opcionesFiltroCliente = ['Todos', ...nombresClientes];
        this.opcionesFolioFactura = ['Todos', ...(response.data.foliosFactura || [])];
        this.opcionesFolioComplemento = ['Todos', ...(response.data.foliosComplemento || [])];
      } catch (error) {
        console.error("Error cargando catálogos", error);
      }
    },
    async cargarSaldos() {
      try {
        const response = await axios.get('/saldos-favor');
        this.saldosData = response.data;
      } catch (error) {
        console.error("Error cargando saldos a favor", error);
      }
    },
    abrirModalNuevoSaldo() {
      this.showModalSaldo = true;
    },
    editarSaldo(saldo) {
      this.saldoAEditar = saldo;
      this.showModalEditarSaldo = true;
    },
    async notificarCliente(row) {
      const clienteEncontrado = this.opcionesClienteObj.find(c => c.nombre === row.cliente);
      const correosSugeridos = clienteEncontrado ? (clienteEncontrado.email || clienteEncontrado.correo || '') : '';

      const { value: correosDestino, isConfirmed } = await Swal.fire({
        title: 'Confirmar Destinatarios',
        html: `
          <p class="text-lg text-gray-600 mb-4" style="font-family: sans-serif;">
            Se enviará el aviso de saldo a favor de <b>$${parseFloat(row.monto).toLocaleString('en-US', { minimumFractionDigits: 2 })}</b> para <b>${row.cliente}</b>.
          </p>
          <div class="text-left" style="font-family: sans-serif;">
            <label class="block text-base font-bold text-gray-700 uppercase mb-2">Correos a notificar (separados por coma):</label>
            <input id="swal-input-correos" class="swal2-input" style="width: 100%; max-width: 100%; margin: 0; font-size: 16px;" value="${correosSugeridos}" placeholder="ejemplo@correo.com, contabilidad@correo.com">
          </div>
        `,
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#002060',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, enviar correo',
        cancelButtonText: 'Cancelar',
        preConfirm: () => {
          const input = document.getElementById('swal-input-correos').value;
          if (!input || input.trim() === '') {
            Swal.showValidationMessage('Debes ingresar al menos un correo electrónico');
          }
          return input;
        }
      });

      if (isConfirmed && correosDestino) {
        try {
          Swal.fire({
            title: 'Enviando correo...',
            text: 'Por favor espera un momento.',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
          });

          await axios.post(`/saldos-favor/${row.id}/notificar`, {
            correos: correosDestino
          });

          Swal.fire({
            title: '¡Enviado!',
            text: 'La notificación fue enviada correctamente a los destinatarios.',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
          });

        } catch (error) {
          console.error("Error al enviar el correo:", error);
          Swal.fire('Error', 'Hubo un problema al intentar enviar el correo. Verifica tu configuración.', 'error');
        }
      }
    },
    async eliminarSaldo(id) {
      if (confirm('¿Estás seguro de ELIMINAR permanentemente este saldo a favor?')) {
        try {
          await axios.delete(`/saldos-favor/${id}`);
          this.cargarSaldos();
        } catch (error) {
          Swal.fire('Error', 'No se pudo eliminar el saldo', 'error');
        }
      }
    },
    async aplicarSaldo(id) {
      if (confirm('¿Estás seguro de marcar este saldo como APLICADO?')) {
        try {
          await axios.put(`/saldos-favor/${id}/aplicar`);
          this.cargarSaldos();
        } catch (error) {
          Swal.fire('Error', 'No se pudo actualizar el estatus', 'error');
        }
      }
    },
    async reactivarSaldo(id) {
      if (confirm('¿Estás seguro de REACTIVAR este saldo y dejarlo como VIGENTE?')) {
        try {
          await axios.put(`/saldos-favor/${id}/reactivar`);
          this.cargarSaldos();
        } catch (error) {
          Swal.fire('Error', 'No se pudo reactivar el saldo', 'error');
        }
      }
    },
    onSaldoGuardado() {
      this.showModalSaldo = false;
      this.cargarSaldos();
    },
    onSaldoActualizado() {
      this.showModalEditarSaldo = false;
      this.cargarSaldos();
      this.cargarSaldos();
    },
    onIngresoGuardado() {
      this.showModal = false;
      this.cargarIngresos();
    },
    async eliminarFila(id) {
      const result = await Swal.fire({
        title: '¿Seguro deseas continuar?',
        text: 'Se eliminará de forma permanente esta fila. ¿Seguro deseas continuar?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#00C09F',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
      });
      if (result.isConfirmed) {
        try {
          await axios.delete(`/ingresos-conciliados/${id}`);
          this.cargarIngresos();
        } catch (error) {
          Swal.fire('Error', 'No se pudo eliminar la fila', 'error');
        }
      }
    },
    async procesarComplementoBackend(payloadLimpio) {
      try {
        Swal.fire({
          title: 'Generando Complemento...',
          text: 'Conectando con Contpaqi y saldando factura. Por favor, espera.',
          allowOutsideClick: false,
          didOpen: () => { Swal.showLoading(); }
        });

        // Asegúrate de que esta URL coincida exactamente con la que configuraste en tu routes/api.php o web.php
        const response = await axios.post('/ingresos-conciliados/generar-complemento', payloadLimpio);

        if (response.data.success) {
          // El backend responderá con success y saldado (booleano)
          Swal.fire({
            title: '¡Proceso Terminado!',
            text: response.data.message,
            icon: response.data.saldado ? 'success' : 'warning'
          });

          this.cargarIngresos(); // Actualizamos la tabla
        }
      } catch (error) {
        console.error("Error al generar complemento:", error);
        let mensajeError = 'Hubo un problema al comunicarse con el servidor.';

        if (error.response && error.response.data && error.response.data.error) {
          mensajeError = error.response.data.error;
        }

        Swal.fire('Atención', mensajeError, 'error');
      }
    },
    async obtenerUsuarioActual() {
      try {
        // En Laravel normalmente la ruta '/api/user' o '/user' devuelve al auth()->user()
        // Ajusta la ruta si en tu sistema utilizan otra (ej. '/perfil-usuario-actual')
        const response = await axios.get('/api/user'); 
        this.usuario = response.data;
      } catch (error) {
        console.warn("No se pudo obtener el usuario para los permisos", error);
      }
    },
    mostrarDesgloseManzanillo(item) {
      if (!item.operaciones || item.operaciones.length === 0) {
        return Swal.fire('Atención', 'No hay operaciones o desgloses registrados para este ingreso.', 'info');
      }

      let filasHtml = item.operaciones.map(op => {
        // Lee el anticipo y la referencia sin importar la estructura de la respuesta
        const anticipoVal = op.anticipo !== undefined 
          ? parseFloat(op.anticipo || 0) 
          : (op.pivot ? parseFloat(op.pivot.anticipo || 0) : 0);
          
        const refVal = op.referencia || (op.pivot ? op.pivot.referencia : null) || op.folio || 'N/A';

        return `
          <tr>
            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left; font-weight: bold;">
               ${refVal}
            </td>
            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right; color: #00C09F; font-weight: 900;">
               $${anticipoVal.toLocaleString('en-US', {minimumFractionDigits: 2})}
            </td>
          </tr>
        `;
      }).join('');

      Swal.fire({
        title: 'Desglose de Anticipos',
        html: `
          <div style="font-family: Arial, sans-serif;">
            <p style="color: #666; margin-bottom: 15px; text-align: left;">
              Cliente: <b>${item.cliente?.nombre || item.cliente}</b><br>
              Total Anticipo Global: <b>$${parseFloat(item.anticipo || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</b>
            </p>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
              <thead style="background: #f8f9fa;">
                <tr>
                  <th style="padding: 10px; text-align: left;">Contenedor / Referencia</th>
                  <th style="padding: 10px; text-align: right;">Anticipo Asignado</th>
                </tr>
              </thead>
              <tbody>
                ${filasHtml}
              </tbody>
            </table>
          </div>
        `,
        confirmButtonColor: '#2A3A4D',
        confirmButtonText: 'Cerrar',
        width: '600px'
      });
    }
  }
}
</script>

<style scoped>
/* ============================================== */
/* ESTILOS DEL SCROLLBAR PERSONALIZADO            */
/* ============================================== */
.custom-scrollbar::-webkit-scrollbar {
  height: 14px;
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 8px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 8px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

/* ============================================== */
/* QUITAR FLECHAS DE LOS INPUTS TIPO NÚMERO       */
/* ============================================== */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

/* ============================================== */
/* ESTILOS INTERNOS DE LA TABLA Y FILTROS         */
/* ============================================== */
:deep(.custom-table-multiselect .multiselect__tags) {
  border: 1px solid transparent !important;
  min-height: 48px !important;
  padding: 10px 40px 0 16px !important;
  font-size: 16px !important;
  border-radius: 8px;
  background-color: transparent;
  transition: border-color 0.2s;
}

:deep(.custom-table-multiselect:hover .multiselect__tags) {
  border-color: #D1D5DB !important;
  background-color: white;
}

:deep(.custom-table-multiselect .multiselect__select) {
  height: 48px !important;
  padding: 0;
  top: 0;
}

:deep(.custom-table-multiselect .multiselect__single) {
  margin-bottom: 0;
  font-size: 16px !important;
  background-color: transparent;
  text-overflow: ellipsis;
  overflow: hidden;
  white-space: nowrap;
}

:deep(.custom-table-datepicker .field-input) {
  min-height: 48px !important;
  height: 48px !important;
  min-width: 180px !important;
  font-size: 16px !important;
  padding: 0 16px !important;
  border: 1px solid transparent !important;
  border-radius: 8px;
  background-color: transparent !important;
  transition: border-color 0.2s;
}

:deep(.custom-table-datepicker:hover .field-input) {
  border-color: #D1D5DB !important;
  background-color: white !important;
}

:deep(.custom-table-datepicker .field-clear-button) {
  display: none !important;
}

/* ============================================== */
/* ESTILOS DE LA BARRA DE FILTROS SUPERIOR        */
/* ============================================== */
:deep(.custom-filter-multiselect .multiselect__tags) {
  min-height: 48px !important;
  padding-top: 12px !important;
  font-size: 16px !important;
  border-radius: 8px;
  border-color: #D1D5DB;
}

:deep(.custom-filter-multiselect .multiselect__select) {
  height: 48px !important;
  top: 0;
}

:deep(.custom-filter-datepicker .field-input) {
  min-height: 48px !important;
  height: 48px !important;
  font-size: 16px !important;
  border-radius: 8px;
  border-color: #D1D5DB !important;
}

:deep(.custom-filter-datepicker .field-clear-button) {
  display: none !important;
}

/* ============================================== */
/* FIX DEFINITIVO: POPUPS Y MENÚS DESPLEGABLES    */
/* ============================================== */

td,
th {
  overflow: visible !important;
}

:deep(.datetimepicker) {
  position: absolute !important;
  width: 320px !important;
  min-width: 320px !important;
  left: 0 !important;
  right: auto !important;
  z-index: 99999 !important;
}

:deep(.multiselect__content-wrapper) {
  position: absolute !important;
  width: auto !important;
  min-width: max-content !important;
  left: 0 !important;
  right: auto !important;
  z-index: 99999 !important;
  overflow-x: hidden !important;
}

:deep(.multiselect__option) {
  white-space: nowrap !important;
  display: block !important;
  padding-right: 20px !important;
  font-size: 16px !important;
}

:deep(.multiselect__content) {
  width: 100% !important;
  min-width: max-content !important;
  display: block !important;
}
</style>