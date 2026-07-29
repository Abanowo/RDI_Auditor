<template>
  <div class="flex flex-col min-h-screen font-sans text-gray-800 p-4 lg:p-6 custom-datepicker"
    style="background-color: #F4F6F9;">

    <div class="flex flex-wrap items-center gap-4 mb-4">
      <div class="flex bg-white border border-gray-200 rounded p-1 shadow-sm items-center gap-1">
        <button @click="opType = 'TODOS'; buscarDatos()"
          :class="['px-4 py-1.5 text-[11px] font-bold uppercase rounded transition-colors', opType === 'TODOS' ? 'bg-gray-600 text-white shadow' : 'text-gray-500 hover:bg-gray-100']">
          AMBOS
        </button>
        <button @click="opType = 'IMPO'; buscarDatos()"
          :class="['px-4 py-1.5 text-[11px] font-bold uppercase rounded transition-colors', opType === 'IMPO' ? 'bg-blue-600 text-white shadow' : 'text-gray-500 hover:bg-gray-100']">
          IMPO
        </button>
        <button @click="opType = 'EXPO'; buscarDatos()"
          :class="['px-4 py-1.5 text-[11px] font-bold uppercase rounded transition-colors', opType === 'EXPO' ? 'bg-purple-600 text-white shadow' : 'text-gray-500 hover:bg-gray-100']">
          EXPO
        </button>
      </div>
      <div class="flex bg-white border border-gray-200 rounded p-1 shadow-sm items-center px-2 gap-4">
        <button @click="changeSubView('OPERACIONES')"
          :class="['px-4 py-1.5 text-[11px] font-bold uppercase transition-colors', subView === 'OPERACIONES' ? 'text-blue-600' : 'text-blue-400 hover:text-blue-600']">
          OPERACIONES
        </button>
        <button @click="changeSubView('OPERACIONES SIN FACTURAR')"
          :class="['px-4 py-1.5 text-[11px] font-bold uppercase transition-colors', subView === 'OPERACIONES SIN FACTURAR' ? 'text-green-600' : 'text-green-400 hover:text-green-600']">
          SIN FACTURAR
        </button>
      </div>
    </div>

    <!-- BOTONES DE CONCEPTOS (Los que filtran la tabla) -->
    <div class="flex gap-2 mb-4 overflow-x-auto pb-1">
      <button v-for="tab in ['GENERAL', 'MANIOBRAS', 'MUESTRAS', 'FLETE', 'LLC', 'ROJOS']" :key="tab"
        @click="cambiarConcepto(tab)"
        :class="['px-6 py-2 rounded text-[11px] font-bold flex items-center gap-2 transition-all border whitespace-nowrap',
          activeTab === tab ? 'bg-white text-blue-800 border-gray-200 shadow-sm' : 'bg-transparent text-blue-800 border-transparent hover:bg-white/50']">
        <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
          </path>
        </svg>
        {{ tab }}
      </button>
    </div>

    <div v-if="activeTab === 'ROJOS'" class="flex bg-white border border-red-200 rounded p-1 shadow-sm mb-4">
      <button @click="tipoRojo = 'REALES'" :class="['flex-1 py-1.5 text-[11px] font-bold uppercase rounded transition-colors',
        tipoRojo === 'REALES' ? 'bg-red-500 text-white shadow' : 'text-red-500 hover:bg-red-50']">
        REALES
      </button>
      <button @click="tipoRojo = 'ESTRATEGICOS'" :class="['flex-1 py-1.5 text-[11px] font-bold uppercase rounded transition-colors',
        tipoRojo === 'ESTRATEGICOS' ? 'bg-red-500 text-white shadow' : 'text-red-500 hover:bg-red-50']">
        ESTRATEGICOS
      </button>
    </div>

    <div class="p-1.5 flex gap-1.5 mb-6 rounded shadow-sm overflow-x-auto" style="background-color: #D69E2E;">
      <button v-for="loc in locations" :key="loc" @click="activeLocation = loc; buscarDatos()"
        :class="['flex-1 py-1.5 px-2 text-[12px] font-bold capitalize transition-all rounded whitespace-nowrap', activeLocation === loc ? 'shadow-inner' : 'shadow-sm hover:opacity-90']"
        :style="activeLocation === loc ? 'background-color: #2A4460; color: #FFFFFF;' : 'background-color: #FFFFFF; color: #2A4460;'">
        {{ loc === 'TODAS' ? 'Todas' : loc.toLowerCase() }}
      </button>
    </div>

    <div class="flex justify-between items-center mb-6 pb-4 border-b border-teal-200">
      <div>
        <h1 class="text-3xl font-bold text-teal-800">Control de Proveedores</h1>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
      <div v-for="m in metrics" :key="m.view" @click="changeSubView(m.view)"
        :class="['bg-white border p-4 rounded shadow-sm flex items-center gap-4 cursor-pointer transition-all', subView === m.view ? 'border-blue-400 ring-1 ring-blue-400 shadow-md' : 'border-gray-200 hover:border-blue-300']">
        <div class="w-12 h-12 rounded flex items-center justify-center text-white shadow-sm shrink-0"
          :style="{ backgroundColor: m.color }">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" v-html="m.icon"></svg>
        </div>
        <div class="flex flex-col">
          <span class="text-2xl font-black text-gray-800 leading-none">
            <span v-if="isLoading" class="text-sm text-gray-400">Cargando...</span>
            <span v-else>{{ m.count }}</span>
          </span>
          <span class="text-[9px] font-bold text-gray-500 uppercase mt-1">{{ m.label }}</span>
        </div>
      </div>
    </div>

    <div class="bg-white border border-gray-200 rounded shadow-sm mb-6 relative">
      <div v-if="isLoading"
        class="absolute inset-0 bg-white bg-opacity-60 z-10 flex items-center justify-center rounded"></div>

      <div class="p-4 grid grid-cols-1 md:grid-cols-12 gap-x-8 gap-y-4">

        <div class="col-span-1 md:col-span-6 flex flex-col">
          <div class="text-[11px] font-extrabold text-gray-800 mb-3 border-b border-gray-200 pb-1.5">Identificadores
          </div>
          <div class="flex flex-wrap lg:flex-nowrap gap-3">
            <input type="text" v-model="filters.pedimento" @keyup.enter="buscarDatos" placeholder="Pedimento"
              class="w-full lg:w-1/4 h-[34px] bg-white border border-gray-200 rounded px-3 text-xs text-gray-700 placeholder-gray-400 focus:outline-none focus:border-blue-400 transition-colors" />
            <input type="text" v-model="filters.fInte" @keyup.enter="buscarDatos" placeholder="F. Inte/prov"
              class="w-full lg:w-1/4 h-[34px] bg-white border border-gray-200 rounded px-3 text-xs text-gray-700 placeholder-gray-400 focus:outline-none focus:border-blue-400 transition-colors" />

            <div class="w-full lg:w-2/4 custom-multiselect-container">
              <Multiselect v-model="filters.proveedor" :options="providers" placeholder="Proveedor (Todos)"
                :searchable="true" :show-labels="false" class="filtro-multiselect">
              </Multiselect>
            </div>
          </div>
        </div>

        <div class="col-span-1 md:col-span-3 flex flex-col">
          <div class="text-[11px] font-extrabold text-gray-800 mb-3 border-b border-gray-200 pb-1.5">Filtros de Estado
          </div>
          <div class="w-full custom-multiselect-container">
            <Multiselect v-model="filters.status" :options="statusOptions" track-by="value" label="label"
              placeholder="Todos los Status" :searchable="false" :show-labels="false" class="filtro-multiselect">
            </Multiselect>
          </div>
        </div>

        <div class="col-span-1 md:col-span-3 flex flex-col">
          <div class="text-[11px] font-extrabold text-gray-800 mb-3 border-b border-gray-200 pb-1.5">Estado y Fecha
          </div>
          <VueCtkDateTimePicker v-model="filters.fecha" :only-date="true" format="YYYY-MM-DD" formatted="DD/MM/YYYY"
            label="Seleccionar rango o fecha" color="#3182CE" button-color="#3182CE" input-size="sm" />
        </div>
      </div>

      <div
        class="border-t border-gray-100 p-3 px-4 flex flex-col md:flex-row justify-end items-center bg-white rounded-b gap-4 md:gap-0">
        <div class="flex items-center gap-2">
          <button v-if="subView === 'CUENTAS POR PAGAR' || true"
            class="bg-white border hover:bg-blue-50 rounded px-4 py-1.5 text-[11px] font-bold transition-colors mr-2"
            style="border-color: #2E5A88; color: #2E5A88;">
            Orden de pago
          </button>

          <button @click="buscarDatos"
            class="bg-blue-600 hover:bg-blue-700 text-white rounded px-8 py-1.5 text-[12px] font-bold transition-colors shadow-sm flex items-center gap-2">
            <svg v-if="isLoading" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none"
              viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
              </path>
            </svg>
            Buscar
          </button>
          <button @click="limpiarFiltros"
            class="bg-gray-500 hover:bg-gray-600 text-white rounded px-5 py-1.5 text-[12px] font-bold transition-colors shadow-sm">
            Limpiar Filtros
          </button>
        </div>
      </div>
    </div>

    <div class="border rounded py-2 px-4 mb-4 flex justify-center items-center gap-8 text-xs font-bold"
      style="background-color: #EBF5FB; border-color: #BDE0FE; color: #005177;">
      <span>COSTO: {{ totalCosto.toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</span>
      <span class="text-blue-300">|</span>
      <span>VENTA: {{ totalVenta.toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</span>
      <span class="text-blue-300">|</span>
      <span>CONCEPTO VENTA: {{ activeTab }}</span>
      <span class="text-blue-300">|</span>
      <span>GANANCIA: {{ totalGanancia.toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</span>
    </div>

    <div class="flex-1 flex flex-col bg-white rounded shadow-sm border border-gray-200 overflow-hidden mb-4 relative">

      <div v-if="isLoading"
        class="absolute inset-0 bg-white bg-opacity-70 z-10 flex flex-col items-center justify-center">
        <svg class="animate-spin h-10 w-10 text-blue-600 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none"
          viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
          </path>
        </svg>
        <span class="text-blue-800 font-bold text-sm">Consultando operaciones, por favor espera...</span>
      </div>

      <div v-if="subView === 'OPERACIONES' || subView === 'OPERACIONES SIN FACTURAR'" class="overflow-x-auto flex-1">
        <div class="p-4 border-b border-gray-100 bg-gray-50/50">
          <div class="border rounded py-3 text-center font-bold text-sm shadow-sm"
            style="background-color: white; border-color: #009ED9; color: #005177;">
            Monto Costo: {{ totalCosto.toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
          </div>
        </div>
        <table class="w-full text-left text-[11px] border-collapse">
          <thead class="border-y border-gray-200 font-bold uppercase tracking-wider"
            style="background-color: #E4E9F0; color: #4A5568;">
            <tr>
              <th class="px-4 py-3">CLIENTE</th>
              <th class="px-4 py-3">PEDIMENTO</th>
              <th class="px-4 py-3">PROVEEDOR</th>
              <th class="px-4 py-3">F. PROVEEDOR</th>
              <th class="px-4 py-3">MONTO</th>
              <th class="px-4 py-3 text-center">MONEDA</th>
              <th class="px-4 py-3">F. INTACTICS</th>
              <th class="px-4 py-3 text-center">ANT</th>
              <th class="px-4 py-3 text-center">ACCIONES</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 text-gray-700 font-semibold bg-white">
            <tr v-for="row in relevantControlFlete" :key="row.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 truncate max-w-[150px]">{{ row.cliente }}</td>
              <td class="px-4 py-3 text-blue-600 italic font-bold">{{ row.pedimento }}</td>
              <td class="px-4 py-3 uppercase">{{ row.transportista }}</td>
              
              <!-- 👇 Muestra el Folio del Proveedor (fProveedor) -->
              <td class="px-4 py-3 font-bold text-orange-600">{{ row.fProveedor }}</td>
              
              <!-- Muestra el Costo (Monto) -->
              <td class="px-4 py-3 font-bold text-gray-800">$ {{ (Number(row.costo) || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}</td>
              
              <td class="px-4 py-3 text-center">{{ row.moneda || 'MXN' }}</td>
              
              <!-- 👇 Muestra el Folio de SC (fInTactics) -->
              <td class="px-4 py-3 font-bold text-indigo-600">{{ row.fInTactics }}</td>
              
              <td class="px-4 py-3 text-center">
                <button v-if="row.hasAnticipo" @click="toggleAnticipo(row.id, true)"
                  class="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center mx-auto text-white shadow-sm hover:bg-green-600 transition-colors">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                  </svg>
                </button>
                <span v-else class="text-gray-300">--</span>
              </td>
              <td class="px-4 py-3 text-center">
                <!-- Acciones -->
                <div class="flex items-center justify-center gap-2">
                  <button class="p-1 text-blue-400 hover:text-blue-600"><svg class="w-4 h-4" fill="none"
                      stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg></button>
                  <button @click="openEditModal(row)" class="p-1 text-blue-400 hover:text-blue-600"><svg class="w-4 h-4"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                      </path>
                    </svg></button>
                  <button @click="invoiceToInTactics(row)" class="p-1 text-green-500 hover:text-green-700"
                    title="Facturar">
                    <div
                      class="w-5 h-5 rounded-full border-2 border-current flex items-center justify-center font-bold text-[10px]">
                      $</div>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!isLoading && relevantControlFlete.length === 0">
              <td colspan="9" class="px-4 py-8 text-center text-gray-400 font-normal">No hay registros para mostrar con
                los filtros actuales.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else-if="subView === 'ORDENES DE PAGO' || subView === 'ORDENES PAGADAS'" class="overflow-x-auto flex-1">
        <table class="w-full text-left text-[11px] border-collapse">
          <thead class="border-y border-gray-200 font-bold uppercase tracking-wider"
            style="background-color: #E4E9F0; color: #4A5568;">
            <tr>
              <th class="px-4 py-4">OPERACION</th>
              <th class="px-4 py-4">PROVEEDOR</th>
              <th class="px-4 py-4">FACTURAS</th>
              <th class="px-4 py-4">SUB-TOTAL</th>
              <th class="px-4 py-4">IVA</th>
              <th class="px-4 py-4">RETENCION</th>
              <th class="px-4 py-4">TOTAL</th>
              <th class="px-4 py-4">{{ subView === 'ORDENES DE PAGO' ? 'FECHA A PAGARSE' : 'FECHA PAGADA' }}</th>
              <th class="px-4 py-4 text-right">ACCIONES</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 font-semibold bg-white" style="color: #4A5568;">
            <tr v-for="row in (subView === 'ORDENES DE PAGO' ? orderData : paidOrders)" :key="row.id"
              class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-4">{{ row.op || 'OPN260414002' }}</td>
              <td class="px-4 py-4 uppercase">{{ row.proveedor || 'ERBEY GARCIA GARCIA' }}</td>
              <td class="px-4 py-4">{{ row.facturas || '0' }}</td>
              <td class="px-4 py-4">$ {{ (Number(row.subtotal) || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2
              }) }}</td>
              <td class="px-4 py-4">$ {{ (Number(row.iva) || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) }}
              </td>
              <td class="px-4 py-4">$ {{ (Number(row.retencion) || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2
              }) }}</td>
              <td class="px-4 py-4">$ {{ (Number(row.total) || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
              }}</td>
              <td class="px-4 py-4">{{ row.fecha || (subView === 'ORDENES DE PAGO' ? '0000-00-00' : '2026-07-13') }}
              </td>
              <td class="px-4 py-4">
                <div class="flex items-center justify-end gap-2">
                  <button v-if="subView === 'ORDENES DE PAGO'"
                    class="bg-green-500 hover:bg-green-600 text-white rounded px-3 py-1.5 text-[10px] font-bold uppercase flex items-center shadow-sm transition-colors mr-2">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" stroke-width="3"
                      viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                    </svg>AUTORIZAR
                  </button>
                  <button
                    class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                    title="Buscar"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg></button>
                  <button @click="openEmailModal(row)"
                    class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                    title="Correo"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                      </path>
                    </svg></button>
                  <button @click="openDocModal(row)"
                    class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                    title="Documento"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                      </path>
                    </svg></button>
                  <button
                    class="w-7 h-7 rounded-full border border-gray-300 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                    title="Imprimir"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z">
                      </path>
                    </svg></button>
                  <button @click="openEditModal(row)"
                    class="w-7 h-7 rounded-full bg-blue-400 flex items-center justify-center text-white hover:bg-blue-500 shadow-sm"
                    title="Editar"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                      </path>
                    </svg></button>
                </div>
              </td>
            </tr>
            <tr v-if="!isLoading && (subView === 'ORDENES DE PAGO' ? orderData : paidOrders).length === 0">
              <td colspan="9" class="px-4 py-8 text-center text-gray-400 font-normal">No hay registros para mostrar.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="overflow-x-auto flex-1">
        <table class="w-full text-left text-[11px] border-collapse">
          <thead class="border-y border-gray-200 font-bold uppercase tracking-wider"
            style="background-color: #E4E9F0; color: #4A5568;">
            <tr>
              <th class="px-4 py-3">CLIENTE</th>
              <th class="px-4 py-3">OP</th>
              <th class="px-4 py-3">PEDIMENTO</th>
              <th class="px-4 py-3">PROVEEDOR</th>
              <th class="px-4 py-3">COSTO</th>
              <th class="px-4 py-3">VENTA</th>
              <th class="px-4 py-3">GANANCIA</th>
              <th class="px-4 py-3 text-center">ACCIONES</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 font-semibold bg-white" style="color: #4A5568;">
            <tr v-for="row in tableData" :key="row.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3">{{ row.cliente }}</td>
              <td class="px-4 py-3 text-blue-500 italic">{{ row.op }}</td>
              <td class="px-4 py-3">{{ row.pedimento || '--' }}</td>
              <td class="px-4 py-3 uppercase">{{ row.transportista || row.proveedor }}</td>
              <td class="px-4 py-3 font-bold text-gray-700">$ {{ (Number(row.costo) || 0).toLocaleString('en-US',
                { minimumFractionDigits: 0 }) }}</td>
              <td class="px-4 py-3 font-bold text-gray-700">$ {{ (Number(row.venta) || 0).toLocaleString('en-US',
                { minimumFractionDigits: 0 }) }}</td>
              <td class="px-4 py-3 font-bold" style="color: #00C09F;">$ {{ (Number(row.ganancia) ||
                0).toLocaleString('en-US', { minimumFractionDigits: 0 }) }}</td>
              <td class="px-4 py-3 text-center">
                <div class="flex items-center justify-center gap-2">
                  <button class="text-blue-400 hover:text-blue-600 transition-colors" title="Buscar"><svg
                      class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg></button>
                  <button @click="openEditModal(row)" class="text-blue-400 hover:text-blue-600 transition-colors"
                    title="Editar"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                      </path>
                    </svg></button>
                  <button @click="canAuthorize(row) ? authorizeCuenta(row) : showMissingDataAlert()"
                    :class="['w-6 h-6 rounded-full flex items-center justify-center text-white transition-colors', canAuthorize(row) ? 'hover:bg-teal-500 shadow-sm' : 'bg-gray-300 cursor-not-allowed opacity-50']"
                    :style="canAuthorize(row) ? 'background-color: #00C09F;' : ''"
                    :title="canAuthorize(row) ? 'Enviar a Orden de Pago' : 'Faltan datos por llenar'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 8h4.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16"></path>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!isLoading && tableData.length === 0">
              <td colspan="8" class="px-4 py-8 text-center text-gray-400 font-normal">No hay cuentas por pagar
                pendientes.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <ModalEditarOperacion :show="editingRow !== null" :row-data="editForm" @close="closeModal" @save="handleSaveEdit" />
    <ModalNotificacion :show="showEmailModal" :row-data="selectedRow" :shared-files="archivosCompartidos"
      @close="showEmailModal = false" @send="handleSendEmail" />
    <ModalDocumentos :show="showDocModal" :shared-files="archivosCompartidos" @close="handleCloseDocModal" />

  </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';
import ModalEditarOperacion from './ModalEditarOperacion.vue';
import ModalNotificacion from './ModalNotificacion.vue';
import ModalDocumentos from './ModalDocumentos.vue';
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

export default {
  name: 'PaymentControlView',
  components: {
    ModalEditarOperacion,
    ModalNotificacion,
    ModalDocumentos,
    VueCtkDateTimePicker,
    Multiselect
  },
  props: {
    subView: { type: String, default: 'OPERACIONES' },
    isPXCC: { type: Boolean, default: false }
  },
  data() {
    return {
      isLoading: false,
      controlFleteData: [],
      tableData: [],
      orderData: [],
      paidOrders: [],

      searchTerm: '',
      opType: 'TODOS',
      activeTab: 'GENERAL', // El valor por defecto al entrar
      activeLocation: 'NOGALES',
      tipoRojo: 'REALES',
      selectedIds: [],
      editingRow: null,
      showEmailModal: false,
      showDocModal: false,
      selectedRow: null,
      archivosCompartidos: [
        { name: 'PDF521.pdf' },
        { name: '520.pdf' },
        { name: '525.pdf' }
      ],
      filters: {
        fecha: '',
        proveedor: null,
        pedimento: '',
        fInte: '',
        status: null
      },
      editForm: {
        id: '',
        cliente: '',
        op: '',
        costo: 0,
        venta: 0,
        moneda: 'MXN',
        transportista: '',
        fProveedor: '',
        facturaInterna: '',
        comentario: '',
        fecha: '',
        pagadaCliente: false
      },
      locations: ['NOGALES', 'LAREDO', 'TIJUANA', 'MEXICALI', 'MANZANILLO', 'REYNOSA', 'VERACRUZ', 'TODAS'],
      providers: ['ORALIA NAVARRO TERAN', 'ERBEY GARCIA GARCIA', 'TRANSPORTACTICS SA DE CV', 'PEDRO MUÑOZ DURAN'],
      statusOptions: [{ label: 'Sin Facturar', value: 'sin_facturar' }, { label: 'Facturado', value: 'facturado' }]
    };
  },
  computed: {
    totalCosto() {
      return this.relevantControlFlete.reduce((acc, curr) => acc + (Number(curr.costo) || 0), 0);
    },
    totalVenta() {
      return this.relevantControlFlete.reduce((acc, curr) => acc + (Number(curr.venta) || 0), 0);
    },
    totalGanancia() {
      return this.totalVenta - this.totalCosto;
    },

    currentStats() {
      return {
        operaciones: this.controlFleteData.length,
        sinFacturar: this.controlFleteData.filter(f => f.status === 'sin_facturar').length,
        ordenesPago: this.orderData.length,
        cuentasPagar: this.tableData.length
      };
    },

    relevantControlFlete() {
      let filtered = this.controlFleteData;
      if (this.subView === 'OPERACIONES SIN FACTURAR') {
        filtered = filtered.filter(r => r.status === 'sin_facturar');
      }
      return filtered;
    },

    metrics() {
      return [
        { label: 'OPERACIONES', count: this.currentStats.operaciones || 0, color: '#6BA2FF', view: 'OPERACIONES', icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"></path><path d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10"></path><path d="M13 8h4.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16"></path>' },
        { label: 'CUENTAS POR PAGAR', count: this.currentStats.cuentasPagar || 0, color: '#2E5A88', view: 'CUENTAS POR PAGAR', icon: '<path d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>' },
        { label: 'ÓRDENES DE PAGO', count: this.currentStats.ordenesPago || 0, color: '#3A6B9C', view: 'ORDENES DE PAGO', icon: '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>' },
        { label: 'ÓRDENES PAGADAS', count: this.paidOrders.length || 0, color: '#00C09F', view: 'ORDENES PAGADAS', icon: '<path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>' },
        { label: 'OPERACIONES SIN FACTURAR', count: this.currentStats.sinFacturar || 0, color: '#00C09F', view: 'OPERACIONES SIN FACTURAR', icon: '<path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>' }
      ];
    }
  },
  mounted() {
    this.buscarDatos();
  },
  methods: {
    cambiarConcepto(tab) {
      this.activeTab = tab;
      this.buscarDatos();
    },

    async buscarDatos() {
      this.isLoading = true;
      try {
        const params = {
          concepto: this.activeTab,
          tipo_operacion: this.opType,
          sucursal: this.activeLocation,
          pedimento: this.filters.pedimento,
          fInte: this.filters.fInte,
          proveedor: this.filters.proveedor,
          status: this.filters.status ? this.filters.status.value : null,
          fecha: this.filters.fecha
        };

        const response = await axios.get('/control-proveedores', { params });

        this.controlFleteData = response.data.controlFleteData || [];
        this.tableData = response.data.tableData || [];
        this.orderData = response.data.orderData || [];
        this.paidOrders = response.data.paidOrders || [];

      } catch (error) {
        console.error("Error consultando operaciones:", error);
        Swal.fire({
          title: 'Error de Conexión',
          text: 'Hubo un problema al consultar la información con el servidor.',
          icon: 'error',
          confirmButtonColor: '#3182CE'
        });
      } finally {
        this.isLoading = false;
      }
    },

    limpiarFiltros() {
      this.filters = { fecha: '', proveedor: null, pedimento: '', fInte: '', status: null };
      this.opType = 'TODOS';
      this.buscarDatos();
    },

    async handleSaveEdit(updatedData) {
      try {
        const rawId = updatedData.id.replace('ING-', '');
        await axios.put(`/control-proveedores/${rawId}`, updatedData);

        Swal.fire({ title: '¡Éxito!', text: 'Operación actualizada correctamente.', icon: 'success', timer: 1500, showConfirmButton: false });
        this.closeModal();
        this.buscarDatos();
      } catch (error) {
        Swal.fire('Error', 'No se pudo guardar la información', 'error');
      }
    },

    authorizeCuenta(row) {
      Swal.fire({
        title: 'Autorización de Cuenta',
        html: `
          <p class="text-sm text-gray-600 mb-5">Se requiere la autorización de dos coordinadores.</p>
          <div class="flex flex-col gap-4 text-left ml-6">
            <label class="flex items-center gap-3 cursor-pointer">
              <input type="checkbox" id="coord1" class="w-5 h-5 rounded border-gray-300" style="color: #00C09F;">
              <span class="text-sm font-bold text-gray-800">Autorización Coordinador 1</span>
            </label>
            <label class="flex items-center gap-3 cursor-pointer">
              <input type="checkbox" id="coord2" class="w-5 h-5 rounded border-gray-300" style="color: #00C09F;">
              <span class="text-sm font-bold text-gray-800">Autorización Coordinador 2</span>
            </label>
          </div>
        `,
        icon: 'info', showCancelButton: true, confirmButtonColor: '#00C09F', confirmButtonText: 'Autorizar y Enviar', cancelButtonText: 'Cancelar',
        preConfirm: () => {
          if (!document.getElementById('coord1').checked || !document.getElementById('coord2').checked) {
            Swal.showValidationMessage('Ambas casillas deben ser marcadas.');
          }
        }
      }).then(async (res) => {
        if (res.isConfirmed) {
          try {
            const rawId = row.id.replace('ING-', '');
            await axios.post(`/control-proveedores/${rawId}/enviar-orden`);

            Swal.fire({ title: '¡Autorizado!', text: 'Cuenta enviada exitosamente a Órdenes de Pago.', icon: 'success', confirmButtonColor: '#3182CE' });
            this.buscarDatos();
          } catch (error) {
            Swal.fire('Error', 'Hubo un problema al autorizar la cuenta.', 'error');
          }
        }
      });
    },

    changeSubView(view) {
      this.$emit('sub-view-change', view);
    },
    toggleAnticipo(id, isControlFlete) {
      this.$emit('toggle-anticipo', { id, isControlFlete });
    },
    openEditModal(row) {
      this.editForm = { ...row };
      this.editingRow = row;
    },
    openEmailModal(row) {
      this.selectedRow = row;
      this.showEmailModal = true;
    },
    openDocModal(row) {
      this.selectedRow = row;
      this.showDocModal = true;
    },
    closeModal() {
      this.editingRow = null;
    },
    handleCloseDocModal(updatedFiles) {
      this.archivosCompartidos = updatedFiles;
      this.showDocModal = false;
    },
    handleSendEmail() {
      Swal.fire({
        title: '¡Enviado!',
        text: 'Notificación enviada.',
        icon: 'success',
        timer: 1500,
        showConfirmButton: false
      });
    },

    canAuthorize(row) {
      const isMissing = (val) => !val || val === 'N/A' || val === '--' || String(val).trim() === '';
      if (isMissing(row.pedimento)) {
        return false;
      }
      if (isMissing(row.transportista) && isMissing(row.proveedor)) {
        return false;
      }
      if (isMissing(row.fProveedor)) {
        return false;
      }
      if (isMissing(row.fInTactics)) {
        return false;
      }
      return true;
    },
    showMissingDataAlert() {
      Swal.fire({
        title: 'Datos Incompletos',
        text: 'Faltan datos por llenar para poder autorizar.',
        icon: 'warning',
        confirmButtonColor: '#3182CE'
      });
    },
    invoiceToInTactics(row) {
      Swal.fire({
        title: 'Facturar',
        text: `¿Facturar ${row.op}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#00C09F',
        confirmButtonText: 'Sí'
      })
        .then((res) => {
          if (res.isConfirmed) {
            this.$emit('facturar', row.id);
            Swal.fire('Facturado', '', 'success');
          }
        });
    }
  }
};
</script>

<style scoped>
@import '~vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';
@import '~vue-multiselect/dist/vue-multiselect.min.css';

::v-deep .custom-datepicker .field-input {
  min-height: 34px !important;
  height: 34px !important;
  font-size: 0.75rem !important;
  border-radius: 0.25rem !important;
  border-color: #D1D5DB !important;
}

::v-deep .custom-datepicker .field-label {
  display: none !important;
}

::v-deep .filtro-multiselect .multiselect__tags {
  min-height: 34px !important;
  height: 34px !important;
  padding: 4px 40px 0 8px !important;
  border-color: #E5E7EB !important;
  border-radius: 0.25rem !important;
  font-size: 0.75rem !important;
  display: flex;
  align-items: center;
}

::v-deep .filtro-multiselect .multiselect__select {
  height: 32px !important;
  padding: 4px 8px !important;
  top: 1px;
}

::v-deep .filtro-multiselect .multiselect__placeholder {
  color: #9CA3AF !important;
  margin-bottom: 0 !important;
  padding-top: 2px !important;
  font-size: 0.75rem !important;
}

::v-deep .filtro-multiselect .multiselect__input {
  font-size: 0.75rem !important;
  padding: 0 !important;
  margin-bottom: 0 !important;
  background: transparent !important;
}

::v-deep .filtro-multiselect .multiselect__single {
  font-size: 0.75rem !important;
  margin-bottom: 0 !important;
  padding-top: 2px !important;
  color: #6B7280 !important;
}
</style>