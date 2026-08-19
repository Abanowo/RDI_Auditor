<template>
  <div class="flex flex-col min-h-screen font-sans text-gray-800 p-8 lg:p-12" style="background-color: #F4F6F9;">

    <!-- ============================================== -->
    <!-- FILTRO POR SUCURSAL                            -->
    <!-- ============================================== -->
    <div class="flex w-full p-3 gap-3 mb-10 rounded-lg shadow-sm" style="background-color: #D69E2E;">
      <button v-for="sucursal in sucursalesBase" :key="sucursal" @click="filtroSucursalActiva = sucursal"
        class="flex-1 py-4 text-xl font-extrabold transition-colors whitespace-nowrap rounded-md"
        :style="filtroSucursalActiva === sucursal ? 'background-color: #2A3A4D; color: #ffffff;' : 'background-color: #ffffff; color: #2A3A4D;'">
        {{ sucursal }}
      </button>
      <button @click="filtroSucursalActiva = 'Todas'"
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
    <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-5 gap-6 mb-10">

      <!-- 1. INGRESOS TOTALES -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 border-l-4 flex justify-between items-center"
        style="border-left-color: #00C09F;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Ingresos Totales</p>
          <p class="text-4xl font-black text-gray-800 mb-2">${{ totalDepositos.toLocaleString('en-US', {
            minimumFractionDigits: 2
          }) }}</p>
          <p class="text-sm font-bold" style="color: #00C09F;">Depósitos registrados</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-teal-50 text-teal-500 flex items-center justify-center shrink-0">
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
          <p class="text-4xl font-black text-purple-700 mb-2">${{ totalHonorarios.toLocaleString('en-US', {
            minimumFractionDigits: 2
          }) }}</p>
          <p class="text-sm font-bold" style="color: #8B5CF6;">Utilidad de la agencia</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-purple-50 text-purple-500 flex items-center justify-center shrink-0">
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
          <p class="text-4xl font-black text-pink-600 mb-2">${{ totalNotaCargo.toLocaleString('en-US', {
            minimumFractionDigits: 2
          }) }}</p>
          <p class="text-sm font-bold" style="color: #EC4899;">Gastos por cuenta de cliente</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-pink-50 text-pink-500 flex items-center justify-center shrink-0">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z">
            </path>
          </svg>
        </div>
      </div>

      <!-- 4. SALDOS APLICADOS -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 border-l-4 flex justify-between items-center"
        style="border-left-color: #ED8936;">
        <div>
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Saldos aplicados</p>
          <p class="text-4xl font-black text-gray-800 mb-2">${{ totalSaldosAplicados.toLocaleString('en-US', {
            minimumFractionDigits: 2
          }) }}</p>
          <p class="text-sm font-bold" style="color: #ED8936;">Historial de aplicaciones</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-orange-50 text-orange-400 flex items-center justify-center shrink-0">
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
          <p class="text-base font-bold text-gray-400 uppercase tracking-wider mb-3">Saldos a Favor</p>
          <p class="text-4xl font-black text-gray-800 mb-2">${{ totalSaldos.toLocaleString('en-US', {
            minimumFractionDigits: 2
          }) }}</p>
          <p class="text-sm font-bold" style="color: #4299E1;">Abonos listos</p>
        </div>
        <div class="w-16 h-16 rounded-xl bg-blue-50 text-blue-400 flex items-center justify-center shrink-0">
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
          :class="['px-8 py-4 rounded-lg text-xl font-bold shadow-sm whitespace-nowrap transition-colors', activeTab === 'ingresos' ? 'bg-blue-500 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50']">
          Ingresos
        </button>
        <button @click="activeTab = 'saldos'"
          :class="['px-8 py-4 rounded-lg text-xl font-bold shadow-sm whitespace-nowrap transition-colors', activeTab === 'saldos' ? 'bg-blue-500 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50']">
          Saldos a Favor (Vigentes)
        </button>
        <button @click="activeTab = 'saldos_aplicados'"
          :class="['px-8 py-4 rounded-lg text-xl font-bold shadow-sm whitespace-nowrap transition-colors', activeTab === 'saldos_aplicados' ? 'bg-blue-500 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50']">
          Saldos Aplicados
        </button>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- TAB 1: CONTENEDOR PRINCIPAL TABLA EXCEL        -->
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
          Insertar Fila
        </button>
      </div>

      <!-- BARRA DE FILTROS INTERNOS DE INGRESOS -->
      <div class="px-8 py-6 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-8">
        <div class="flex items-center gap-3">
          <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
          </svg>
          <span class="text-xl font-bold text-gray-700">Filtros:</span>
        </div>

        <!-- Filtro Cliente -->
        <div class="flex items-center gap-4 z-20 shrink-0" style="min-width: 320px;">
          <span class="text-xl text-gray-600 shrink-0">Cliente:</span>
          <multiselect v-model="filtros.cliente" :options="opcionesFiltroCliente" :show-labels="false"
            placeholder="Todos" class="custom-filter-multiselect w-full"></multiselect>
        </div>

        <!-- Filtro Tipo de Servicio -->
        <div class="flex items-center gap-4 z-10 shrink-0" style="min-width: 240px;">
          <span class="text-xl text-gray-600 shrink-0">Servicio:</span>
          <multiselect v-model="filtros.tipoServicio" :options="opcionesTipoServicio" placeholder="Todos"
            :show-labels="false" :allow-empty="false" class="custom-filter-multiselect w-full">
          </multiselect>
        </div>

        <!-- Filtro Tipo de Operación -->
        <div class="flex items-center gap-4 z-10 shrink-0" style="min-width: 240px;">
          <span class="text-xl text-gray-600 shrink-0">Tipo operación:</span>
          <multiselect v-model="filtros.tipoOperacion" :options="opcionesTipoOperacion" track-by="id" label="label"
            :show-labels="false" :searchable="false" :allow-empty="false" placeholder="Seleccione..."
            class="custom-filter-multiselect w-full">
          </multiselect>
        </div>

        <!-- FILTRO DE TIPO DE COMPROBANTE -->
        <div class="flex items-center gap-4 z-10 shrink-0" style="min-width: 260px;">
          <span class="text-xl text-gray-600 shrink-0">TIPO COMPROBANTE:</span>
          <multiselect v-model="filtros.tipo_comprobante" :options="opcionesComprobante" placeholder="Seleccione..."
            :show-labels="false" :allow-empty="false" @input="cargarIngresos" class="custom-filter-multiselect w-full">
          </multiselect>
        </div>

        <!-- Filtro Fechas -->
        <div class="flex items-center gap-4 z-10 shrink-0" style="min-width: 320px;">
          <span class="text-xl text-gray-600 shrink-0">Fechas:</span>
          <VueCtkDateTimePicker v-model="filtros.rangoFechas" format="YYYY-MM-DD" formatted="YYYY-MM-DD" color="#1d4ed8"
            button-color="#1d4ed8" :only-date="true" :range="true" :no-label="true" input-size="sm"
            class="custom-filter-datepicker w-full" placeholder="Seleccione un rango..."></VueCtkDateTimePicker>
        </div>

        <!-- Limpiar Filtros  -->
        <div class="flex items-center ml-auto">
          <button @click="limpiarFiltros"
            class="flex items-center gap-3 px-8 py-3 bg-gray-200 hover:bg-gray-300 text-gray-600 hover:text-gray-700 text-lg font-extrabold rounded-lg transition-colors shadow-sm">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            Limpiar Filtros
          </button>
        </div>
      </div>

      <div class="overflow-x-auto flex-1 custom-scrollbar" style="padding-bottom: 200px;">
        <table class="w-full text-left text-lg border-collapse whitespace-nowrap" style="min-width: 4200px;">
          <thead
            class="bg-slate-100 text-slate-600 font-extrabold uppercase tracking-wider border-b-4 border-slate-300">
            <tr>
              <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">SUCURSAL</th>
              <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">BANCO</th>
              <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">FECHA</th>
              <th class="px-6 py-5 border-r border-slate-200" style="min-width: 220px; width: 220px;">CLIENTE</th>
              <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">REFERENCIA</th>
              <th class="px-6 py-5 border-r border-slate-200 text-purple-700" style="min-width: 180px; width: 180px;">
                MONTO DEPOSITO</th>

              <!-- ========================================== -->
              <!-- ENCABEZADOS DINÁMICOS SEGÚN VISTA          -->
              <!-- ========================================== -->
              <template v-if="esVistaIntshipperts">
                <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">ANTICIPO</th>
                <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">ALMAN / FLETE
                </th>
              </template>

              <template v-else-if="esVistaTransportactics">
                <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">FLETE (XML)</th>
                <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">PAGO PROVEEDOR
                </th>
                <th class="px-6 py-5 border-r border-slate-200 text-emerald-600"
                  style="min-width: 180px; width: 180px;">GANANCIA</th>
              </template>

              <template v-else>
                <template v-if="!esVistaManzanillo">
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">HONORARIOS
                  </th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">Total GPC
                  </th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">IMPUESTOS</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">ECI</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">MANIOBRAS</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">FLETE</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">MUESTRAS</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">LLC</th>
                </template>

                <template v-else>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">ANTICIPO</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">GARANTÍAS</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">DESGLOSE
                    NAVIERA</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">IMPUESTOS</th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">ALMAN / FLETE
                  </th>
                  <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">HONORARIOS
                  </th>
                </template>
              </template>

              <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">MONTO SC</th>
              <th class="px-6 py-5 border-r border-slate-200" style="min-width: 180px; width: 180px;">DIFERENCIA</th>
              <th class="px-6 py-5 border-r border-slate-200 text-center" style="min-width: 220px; width: 220px;">TIPO
                COMPROBANTE
              </th>
              <th class="px-6 py-5 border-r border-slate-200 text-center" style="min-width: 220px; width: 220px;">
                COMPLEMENTO DE PAGO
              </th>
              <th class="px-6 py-5 border-r border-slate-200 text-center" style="min-width: 180px; width: 180px;">
                ESTATUS</th>
              <th class="px-6 py-5 text-center" style="min-width: 320px; width: 320px;">ACCIÓN</th>
            </tr>
          </thead>
          <tbody class="bg-white text-slate-700 font-semibold">
            <tr v-for="(item, index) in ingresosFiltrados" :key="item.id"
              class="border-b border-slate-200 hover:bg-slate-50 transition-colors">

              <!-- DATOS GENERALES -->
              <td class="px-6 py-4 text-xl align-middle border-r border-slate-100 whitespace-nowrap">{{
                item.sucursal_origen ||
                'N/A' }}</td>
              <td class="px-6 py-4 text-xl align-middle border-r border-slate-100 whitespace-nowrap">{{
                item.banco_receptor ||
                'N/A' }}</td>
              <td class="px-6 py-4 text-xl align-middle border-r border-slate-100">{{ item.fecha }}</td>
              <td class="px-6 py-4 text-xl uppercase align-middle border-r border-slate-100 text-slate-800">{{
                item.cliente }}
              </td>

              <!-- 🔥 CORRECCIÓN DEL BORDE DERECHO APLICADA AQUÍ -->
              <td class="px-6 py-4 text-center font-bold text-indigo-600 text-xl border-r border-slate-100">{{
                item.folio_sc ?
                  item.folio_sc : '--' }}</td>

              <!-- MONTO TOTAL -->
              <td
                class="px-6 py-4 text-right text-purple-700 text-xl font-black align-middle border-r border-slate-100 bg-purple-50/30">
                ${{ Number(item.monto_deposito || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
              </td>

              <!-- ========================================== -->
              <!-- CELDAS DINÁMICAS SEGÚN VISTA               -->
              <!-- ========================================== -->
              <template v-if="esVistaIntshipperts">
                <td
                  class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                  ${{ Number(item.anticipo || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                <td
                  class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-black text-gray-700">
                  ${{ Number(item.flete || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
              </template>

              <template v-else-if="esVistaTransportactics">
                <td
                  class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-black text-gray-700">
                  ${{ Number(item.flete || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                <td
                  class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                  ${{ Number(item.pago_proveedor || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                <td
                  class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-bold text-emerald-600 bg-emerald-50/30">
                  ${{ Number(item.ganancia || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
              </template>

              <template v-else>
                <template v-if="!esVistaManzanillo">
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.honorarios || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-black text-blue-600 bg-blue-50/30">
                    ${{ Number(item.total_gpc || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.impuestos || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.eci || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.maniobras || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.flete || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.muestras || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.llc || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
                  </td>
                </template>

                <template v-else>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.anticipo || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.garantias || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.desglose_naviera || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.impuestos || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.flete || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                  <td
                    class="px-6 py-4 text-xl text-right align-middle border-r border-slate-100 font-medium text-gray-600">
                    ${{ Number(item.honorarios || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
                </template>
              </template>

              <!-- CÁLCULOS Y ESTATUS -->
              <td
                class="px-6 py-4 text-xl text-right font-black text-slate-800 align-middle border-r border-slate-100 bg-slate-50/50">
                ${{ calcularMontoSC(item).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
              </td>
              <td
                :class="['px-6 py-4 text-xl text-right font-black align-middle border-r border-slate-100', calcularDiferencia(item) < 0 ? 'text-red-500 bg-red-50/30' : 'text-slate-400']">
                ${{ calcularDiferencia(item).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
              </td>

              <td class="px-8 py-5 whitespace-nowrap text-center border-r border-slate-100 align-middle">
                <span v-if="item.tipo_comprobante === 'CFDI'"
                  class="px-4 py-2 bg-blue-100 text-blue-700 text-lg font-extrabold rounded-lg border border-blue-200">CFDI</span>
                <span v-else-if="item.tipo_comprobante === 'Nota Cargo'"
                  class="px-4 py-2 bg-purple-100 text-purple-700 text-lg font-extrabold rounded-lg border border-purple-200">NOTA
                  CARGO</span>
                <span v-else
                  class="px-4 py-2 bg-gray-100 text-gray-600 text-lg font-extrabold rounded-lg border border-gray-200">{{
                    item.tipo_comprobante || 'N/A' }}</span>
              </td>
              <td class="px-6 py-4 align-middle border-r border-slate-100 text-center font-bold text-slate-600 text-xl">
                {{ item.folio_complemento || 'SIN COMPLEMENTO' }}
              </td>
              <td class="px-6 py-4 text-center font-bold align-middle border-r border-slate-100 text-lg">
                <span :class="item.estado_envio === 'ENVIADO' ? 'text-green-600' : 'text-orange-500'">{{
                  item.estado_envio ||
                  'PENDIENTE' }}</span>
              </td>

              <!-- ============================================== -->
              <!-- NUEVOS BOTONES DE ACCIÓN (5 en total)          -->
              <!-- ============================================== -->
              <td class="p-4 align-middle border-r border-slate-100">
                <div class="flex items-center justify-center gap-3">

                  <!-- 1. Generar Complemento -->
                  <button @click="generarComplemento(item)"
                    class="text-green-600 hover:text-white bg-green-50 hover:bg-green-500 p-3 rounded-lg transition-colors shadow-sm border border-green-200"
                    title="Generar Complemento de Pago">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                      </path>
                    </svg>
                  </button>

                  <!-- 2. Visualizar Complemento -->
                  <button @click="visualizarComplemento(item)"
                    class="text-blue-600 hover:text-white bg-blue-50 hover:bg-blue-500 p-3 rounded-lg transition-colors shadow-sm border border-blue-200"
                    title="Visualizar Complemento de Pago">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z">
                      </path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                      </path>
                    </svg>
                  </button>

                  <!-- 3. Enviar Complemento -->
                  <button @click="enviarCorreoComplemento(item)"
                    class="text-purple-600 hover:text-white bg-purple-50 hover:bg-purple-500 p-3 rounded-lg transition-colors shadow-sm border border-purple-200"
                    title="Enviar Complemento de Pago">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8">
                      </path>
                    </svg>
                  </button>

                  <!-- 4. Editar Fila -->
                  <button @click="abrirModalEditarIngreso(item)"
                    class="text-indigo-600 hover:text-white bg-indigo-50 hover:bg-indigo-500 p-3 rounded-lg transition-colors shadow-sm border border-indigo-200"
                    title="Editar Registro Completo">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
                      </path>
                    </svg>
                  </button>

                  <!-- 5. Eliminar Fila -->
                  <button @click="eliminarFila(item.id)"
                    class="text-red-500 hover:text-white bg-red-50 hover:bg-red-500 p-3 rounded-lg transition-colors shadow-sm border border-red-100"
                    title="Eliminar Fila">
                    <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                      </path>
                    </svg>
                  </button>

                </div>
              </td>
            </tr>
            <tr v-if="ingresosFiltrados.length === 0">
              <td colspan="19" class="text-center py-16 text-2xl text-slate-400 bg-slate-50 font-medium">
                No se encontraron registros para la sucursal o filtros seleccionados.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- TAB 2: CARTERA DE SALDOS A FAVOR (VIGENTES)    -->
    <!-- ============================================== -->
    <div v-show="activeTab === 'saldos'" class="flex-1 flex flex-col">
      <div class="flex justify-between items-end mb-8">
        <div>
          <h2 class="text-2xl font-black text-[#2A3A4D] uppercase tracking-wide">CARTERA DE SALDOS A FAVOR DE CLIENTES
            (VIGENTES)</h2>
          <p class="text-lg text-gray-500 mt-3">Registros de saldo a favor detectados o notas de crédito que pueden
            aplicarse en despachos futuros</p>
        </div>
        <button @click="abrirModalNuevoSaldo"
          class="bg-green-600 hover:bg-green-700 text-white px-8 py-4 rounded-lg text-xl font-bold flex items-center gap-3 shadow-sm transition-colors">
          <span class="text-3xl leading-none mt-[-2px]">+</span> Registrar Saldo a Favor
        </button>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-[#2A3A4D] overflow-hidden">
        <table class="w-full text-left text-xl">
          <thead
            class="bg-slate-100 text-slate-600 font-extrabold uppercase tracking-wider border-b-4 border-slate-300">
            <tr>
              <th class="px-8 py-5" style="width: 250px;">CLIENTE</th>
              <th class="px-8 py-5" style="width: 200px;">SUCURSAL ORIGEN</th>
              <th class="px-8 py-5 text-center" style="width: 200px;">MONTO DE CRÉDITO</th>
              <th class="px-8 py-5 text-center" style="width: 220px;">FECHA DE DETECCIÓN</th>
              <th class="px-8 py-5">CONCEPTO O JUSTIFICACIÓN</th>
              <th class="px-8 py-5 text-center" style="width: 200px;">ESTATUS</th>
              <th class="px-8 py-5 text-center" style="width: 200px;">ACCIÓN</th>
            </tr>
          </thead>
          <tbody class="text-slate-700 font-medium">
            <tr v-for="(saldo, index) in saldosVigentesFiltrados" :key="saldo.id"
              class="border-b border-slate-100 hover:bg-slate-50 transition-colors">
              <td class="px-8 py-5 font-bold text-slate-800">{{ saldo.cliente }}</td>
              <td class="px-8 py-5 text-slate-500">{{ saldo.sucursal_origen }}</td>
              <td class="px-8 py-5 text-center font-black text-emerald-600">${{
                Number(saldo.monto).toLocaleString('en-US',
                  { minimumFractionDigits: 2 }) }}</td>
              <td class="px-8 py-5 text-center text-slate-500">{{ saldo.fecha_deteccion }}</td>
              <td class="px-8 py-5 text-slate-600">{{ saldo.concepto }}</td>
              <td class="px-8 py-5 text-center">
                <span
                  class="px-6 py-3 bg-emerald-100 text-emerald-700 font-extrabold rounded-full uppercase text-base tracking-widest shadow-sm">VIGENTE</span>
              </td>
              <td class="px-8 py-5 text-center">
                <div class="flex items-center justify-center gap-4">
                  <button @click="editarSaldo(saldo)"
                    class="text-indigo-500 hover:text-white bg-indigo-50 hover:bg-indigo-500 p-3 rounded-lg transition-colors shadow-sm border border-indigo-100"
                    title="Editar Registro">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
                      </path>
                    </svg>
                  </button>

                  <button @click="eliminarSaldo(saldo.id)"
                    class="text-red-500 hover:text-white bg-red-50 hover:bg-red-500 p-3 rounded-lg transition-colors shadow-sm border border-red-100"
                    title="Eliminar">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                      </path>
                    </svg>
                  </button>
                  <button @click="notificarCliente(saldo)"
                    class="w-12 h-12 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600 hover:bg-blue-200 transition-colors shadow-sm"
                    title="Notificar saldo al cliente">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                      </path>
                    </svg>
                  </button>
                  <button @click="aplicarSaldo(saldo.id)"
                    class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-bold text-lg transition-colors shadow-sm ml-2 uppercase tracking-wider">
                    Aplicar
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="saldosVigentesFiltrados.length === 0">
              <td colspan="7" class="text-center py-16 text-xl text-slate-400 bg-slate-50 font-medium">No se encontraron
                saldos vigentes para la sucursal seleccionada.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ============================================== -->
    <!-- TAB 3: SALDOS APLICADOS                        -->
    <!-- ============================================== -->
    <div v-show="activeTab === 'saldos_aplicados'" class="flex-1 flex flex-col">
      <div class="flex justify-between items-end mb-8">
        <div>
          <h2 class="text-2xl font-black text-gray-700 uppercase tracking-wide">HISTORIAL DE SALDOS APLICADOS</h2>
          <p class="text-lg text-gray-500 mt-3">Registros de saldos que ya fueron utilizados o aplicados en despachos
            anteriores</p>
        </div>
      </div>

      <div class="bg-white rounded-xl shadow-sm border border-gray-300 overflow-hidden">
        <table class="w-full text-left text-xl">
          <thead class="bg-slate-50 text-slate-500 font-extrabold uppercase tracking-wider border-b-4 border-slate-200">
            <tr>
              <th class="px-8 py-5" style="width: 250px;">CLIENTE</th>
              <th class="px-8 py-5" style="width: 200px;">SUCURSAL ORIGEN</th>
              <th class="px-8 py-5 text-center" style="width: 200px;">MONTO DE CRÉDITO</th>
              <th class="px-8 py-5 text-center" style="width: 220px;">FECHA DE DETECCIÓN</th>
              <th class="px-8 py-5">CONCEPTO O JUSTIFICACIÓN</th>
              <th class="px-8 py-5 text-center" style="width: 200px;">ESTATUS</th>
              <th class="px-8 py-5 text-center" style="width: 200px;">ACCIÓN</th>
            </tr>
          </thead>
          <tbody class="text-slate-500 font-medium">
            <tr v-for="(saldo, index) in saldosAplicadosFiltrados" :key="saldo.id"
              class="border-b border-slate-100 bg-slate-50/50 hover:bg-slate-100 transition-colors">
              <td class="px-8 py-5 font-bold text-slate-600">{{ saldo.cliente }}</td>
              <td class="px-8 py-5">{{ saldo.sucursal_origen }}</td>
              <td class="px-8 py-5 text-center font-black">${{ Number(saldo.monto).toLocaleString('en-US', {
                minimumFractionDigits: 2
              }) }}</td>
              <td class="px-8 py-5 text-center">{{ saldo.fecha_deteccion }}</td>
              <td class="px-8 py-5">{{ saldo.concepto }}</td>
              <td class="px-8 py-5 text-center">
                <span
                  class="px-6 py-3 bg-slate-200 text-slate-500 font-extrabold rounded-full uppercase text-base tracking-widest shadow-inner">APLICADO</span>
              </td>
              <td class="px-8 py-5 text-center">
                <button @click="reactivarSaldo(saldo.id)"
                  class="bg-white hover:bg-blue-50 text-blue-600 hover:text-blue-700 px-6 py-3 rounded-lg font-extrabold text-lg transition-colors border border-blue-200 shadow-sm uppercase tracking-wider">
                  Reactivar
                </button>
              </td>
            </tr>
            <tr v-if="saldosAplicadosFiltrados.length === 0">
              <td colspan="7" class="text-center py-16 text-xl text-slate-400 bg-slate-50 font-medium">No se encontraron
                saldos aplicados para la sucursal seleccionada.</td>
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

  </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';
import 'vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';

import ModalNuevoIngreso from './ModalNuevoIngreso.vue';
import ModalNuevoSaldoFavor from './ModalNuevoSaldoFavor.vue';
import ModalEditarSaldoFavor from './ModalEditarSaldoFavor.vue';
import ModalEditarIngreso from './ModalEditarIngreso.vue';
import ModalComplementoPago from './ModalComplementoPago.vue';
import ModalVerDocumento from './ModalVerDocumento.vue';

export default {
  name: 'VistaFinanzasIngresos',
  components: {
    ModalNuevoIngreso,
    ModalNuevoSaldoFavor,
    ModalEditarSaldoFavor,
    ModalEditarIngreso,
    ModalComplementoPago,
    ModalVerDocumento,
    Multiselect,
    VueCtkDateTimePicker
  },
  data() {
    return {
      activeTab: 'ingresos',
      showModal: false,
      showModalSaldo: false,
      showModalEditarSaldo: false,
      showModalEditarIngreso: false,
      showModalComplemento: false,

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

      filtros: {
        cliente: null,
        rangoFechas: null,
        tipoOperacion: { id: 'Ambos', label: 'Ambos' },
        tipo_comprobante: 'Todos',
        tipoServicio: 'Todos'
      }
    }
  },
  mounted() {
    this.cargarCatalogos();
    this.cargarIngresos();
    this.cargarSaldos();
  },
  computed: {
    esVistaManzanillo() {
      const sucursal = this.filtroSucursalActiva ? this.filtroSucursalActiva.toUpperCase() : '';
      return sucursal.includes('MANZANILLO') || sucursal.includes('INTSHIPPERT');
    },
    esVistaIntshipperts() {
      return this.filtros.tipoServicio === 'INTSHIPPERTS';
    },
    esVistaTransportactics() {
      return this.filtros.tipoServicio === 'Transportactics';
    },
    ingresosFiltrados() {
      let data = this.ingresosData;

      if (this.filtroSucursalActiva !== 'Todas') {
        const filtroUpper = this.filtroSucursalActiva.toUpperCase();
        data = data.filter(item => item.sucursal_origen && item.sucursal_origen.toUpperCase().includes(filtroUpper));
      }

      if (this.filtros.cliente && this.filtros.cliente !== 'Todos') {
        data = data.filter(item => item.cliente === this.filtros.cliente);
      }

      if (this.filtros.tipoOperacion && this.filtros.tipoOperacion.id !== 'Ambos') {
        const tipo = this.filtros.tipoOperacion.id;
        data = data.filter(item => item.sucursal_origen && item.sucursal_origen.toUpperCase().includes(tipo));
      }

      if (this.filtros.rangoFechas && this.filtros.rangoFechas.start && this.filtros.rangoFechas.end) {
        const start = new Date(this.filtros.rangoFechas.start).getTime();
        const end = new Date(this.filtros.rangoFechas.end).getTime();

        data = data.filter(item => {
          if (!item.fecha) {
            return false;
          }
          const itemDate = new Date(item.fecha).getTime();
          return itemDate >= start && itemDate <= end;
        });
      }

      if (this.filtros.tipoServicio && this.filtros.tipoServicio !== 'Todos') {
        data = data.filter(item => {
          const nombreCliente = String(item.cliente || '').toUpperCase();
          const sucursalOrigen = String(item.sucursal_origen || '').toUpperCase();

          const isIntshipperts = nombreCliente.includes('INTSHIPPERTS') || sucursalOrigen.includes('INTSHIPPERT');
          const isTransportactics = nombreCliente.includes('TRANSPORTACTICS') || sucursalOrigen.includes('TRANSPORTACTIC');

          if (this.filtros.tipoServicio === 'INTSHIPPERTS') {
            return isIntshipperts;
          }
          if (this.filtros.tipoServicio === 'Transportactics') {
            return isTransportactics;
          }

          if (this.filtros.tipoServicio === 'InTactics') {
            return !isIntshipperts && !isTransportactics;
          }

          return true;
        });
      }

      return data;
    },
    saldosVigentesFiltrados() {
      return this.saldosData.filter(s => {
        if (s.estatus !== 'VIGENTE') {
          return false;
        }
        if (this.filtroSucursalActiva === 'Todas') {
          return true;
        }
        return s.sucursal_origen && s.sucursal_origen.toUpperCase().includes(this.filtroSucursalActiva.toUpperCase());
      });
    },
    saldosAplicadosFiltrados() {
      return this.saldosData.filter(s => {
        if (s.estatus !== 'APLICADO') {
          return false;
        }
        if (this.filtroSucursalActiva === 'Todas') {
          return true;
        }
        return s.sucursal_origen && s.sucursal_origen.toUpperCase().includes(this.filtroSucursalActiva.toUpperCase());
      });
    },
    totalDepositos() {
      return this.ingresosFiltrados.reduce((acc, item) => acc + (Number(item.monto_deposito) || 0), 0);
    },
    totalHonorarios() {
      return this.ingresosFiltrados.reduce((acc, item) => acc + (Number(item.honorarios) || 0), 0);
    },
    totalNotaCargo() {
      return this.ingresosFiltrados.reduce((acc, item) => {
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
    totalSaldos() {
      return this.saldosVigentesFiltrados.reduce((acc, item) => acc + (Number(item.monto) || 0), 0);
    },
    totalSaldosAplicados() {
      return this.saldosAplicadosFiltrados.reduce((acc, item) => acc + (Number(item.monto) || 0), 0);
    }
  },
  methods: {
    generarComplemento(item) {
      this.ingresoParaComplemento = item;
      this.showModalComplemento = true;
    },
    visualizarComplemento(item) {
      this.tituloDocumentoActivo = `Complemento - ${item.cliente} (${item.sucursal_origen})`;

      // Armamos la URL apuntando a un nuevo endpoint en Laravel (ej. /ingresos-conciliados/25/complemento/pdf)
      this.urlDocumentoActivo = `/ingresos-conciliados/${item.id}/complemento/pdf`;

      this.showModalVerDocumento = true;
    },
    enviarComplemento(item) {
      Swal.fire({
        title: 'Enviar Complemento',
        text: `Aquí se enviará el correo al cliente: ${item.cliente}`,
        icon: 'info',
        confirmButtonColor: '#00C09F'
      });
    },
    abrirModalEditarIngreso(item) {
      this.ingresoAEditar = item;
      this.showModalEditarIngreso = true;
    },
    onIngresoActualizadoDesdeModal() {
      this.showModalEditarIngreso = false;
      this.cargarIngresos();
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
      } catch (error) {
        console.error("Error cargando catálogos", error);
      }
    },
    formatearDinero(monto) {
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2
      }).format(parseFloat(monto) || 0);
    },
    async cargarIngresos() {
      try {
        const response = await axios.get('/ingresos-conciliados', {
          params: { tipo_comprobante: this.filtros.tipo_comprobante }
        });
        this.ingresosData = response.data.map(item => ({
          ...item,
          _original: { ...item }
        }));
      } catch (error) {
        console.error("Error cargando ingresos", error);
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
    limpiarFiltros() {
      this.filtroSucursalActiva = 'Todas';
      this.filtros = {
        cliente: null,
        rangoFechas: null,
        tipoOperacion: { id: 'Ambos', label: 'Ambos' },
        tipo_comprobante: 'Todos',
        tipoServicio: 'Todos'
      };
      this.cargarIngresos();

    },
    calcularMontoSC(item) {
      const nombreCliente = String(item.cliente || '').toUpperCase();
      const sucursalOrigen = String(item.sucursal_origen || '').toUpperCase();

      const isTransportactics = nombreCliente.includes('TRANSPORTACTICS') || sucursalOrigen.includes('TRANSPORTACTIC');

      if (isTransportactics) {
        return Number(item.flete) || 0;
      }

      const esManzanilloRow = sucursalOrigen.includes('MANZANILLO') || sucursalOrigen.includes('INTSHIPPERT');

      if (esManzanilloRow) {
        return (Number(item.anticipo) || 0) +
          (Number(item.garantias) || 0) +
          (Number(item.desglose_naviera) || 0) +
          (Number(item.impuestos) || 0) +
          (Number(item.flete) || 0) +
          (Number(item.honorarios) || 0);
      }

      return (Number(item.honorarios) || 0) +
        (Number(item.impuestos) || 0) +
        (Number(item.eci) || 0) +
        (Number(item.maniobras) || 0) +
        (Number(item.flete) || 0) +
        (Number(item.muestras) || 0) +
        (Number(item.llc) || 0);
    },
    calcularDiferencia(item) {
      return (Number(item.monto_deposito) || 0) - this.calcularMontoSC(item);
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
    enviarCorreoComplemento(item) {
      Swal.fire({
        title: 'Enviar Complemento',
        text: `¿A qué correo deseas enviar el documento ${item.cliente}?`,
        input: 'email',
        inputPlaceholder: 'correo@ejemplo.com',
        // Opcional: Si en 'item' tienes el correo del cliente, ponlo por defecto
        inputValue: item.correo_cliente || '', 
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Enviar Correo',
        cancelButtonText: 'Cancelar',
        showLoaderOnConfirm: true,
        preConfirm: (correoIngresado) => {
          return axios.post(`/ingresos-conciliados/${item.id}/complemento/enviar-correo`, { 
              correo: correoIngresado,
              sucursal: item.sucursal_origen
          })
            .then(response => {
              return response.data;
            })
            .catch(error => {
              // Si falla, mostramos el error que mandó Laravel
              Swal.showValidationMessage(
                `Fallo el envío: ${error.response?.data?.error || error.message}`
              );
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
      }).then((result) => {
        if (result.isConfirmed && result.value?.success) {
          Swal.fire({
            icon: 'success',
            title: '¡Enviado!',
            text: result.value.message,
            timer: 3000,
            showConfirmButton: false
          });
        }
      });
    },
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