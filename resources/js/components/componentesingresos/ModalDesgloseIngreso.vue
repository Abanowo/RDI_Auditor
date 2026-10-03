<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4"
    style="background-color: rgba(42, 58, 77, 0.55);" @mousedown.self="intentarCerrar">

    <div class="bg-white rounded-xl shadow-2xl w-xl flex flex-col overflow-hidden"
      style="max-height: 96vh; max-width: 1760px;" role="dialog" aria-modal="true" aria-labelledby="titulo-desglose">

      <!-- ============================================== -->
      <!-- ENCABEZADO                                     -->
      <!-- ============================================== -->
      <div class="px-8 py-5 flex items-start justify-between gap-6 shrink-0" style="background-color: #2A3A4D;">
        <div class="min-w-0">
          <h2 id="titulo-desglose" class="text-2xl font-black text-white">Desglose por operación</h2>
          <template v-if="ingreso">
            <p class="text-xl font-bold text-gray-200 mt-1 truncate">{{ ingreso.cliente_nombre || 'Cliente sin nombre' }}</p>
            <div class="flex flex-wrap items-center gap-3 mt-3 text-base">
              <span class="px-3 py-1 rounded font-black text-gray-900" style="background-color: #D69E2E;">
                {{ ingreso.sucursal_origen || 'N/A' }}
              </span>
              <span class="text-gray-300 font-semibold">{{ ingreso.fecha }}</span>
              <span class="text-gray-300 font-semibold">Banco: <span class="text-white">{{ ingreso.banco_receptor || 'N/E' }}</span></span>
              <span class="text-gray-300 font-semibold">Ref: <span class="text-white">{{ ingreso.folio_sc || ingreso.referencia || 'N/A' }}</span></span>
            </div>
          </template>
        </div>
        <button type="button" @click="intentarCerrar"
          class="text-gray-300 hover:text-white hover:bg-white/10 p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-white"
          title="Cerrar">
          <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- ============================================== -->
      <!-- ESTADO DE CARGA / ERROR                        -->
      <!-- ============================================== -->
      <div v-if="cargando" class="flex-1 flex flex-col items-center justify-center py-24">
        <svg class="animate-spin h-10 w-10 text-indigo-600 mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span class="text-lg font-bold text-gray-500">Cargando desglose...</span>
      </div>

      <div v-else-if="errorCarga" class="flex-1 flex flex-col items-center justify-center py-20 px-8 text-center">
        <p class="text-xl font-bold text-red-600 mb-2">No se pudo cargar el desglose.</p>
        <p class="text-lg text-gray-500 mb-6">{{ errorCarga }}</p>
        <button type="button" @click="cargar"
          class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-bold transition-colors">
          Volver a intentar
        </button>
      </div>

      <template v-else>
        <!-- ============================================== -->
        <!-- RESUMEN                                        -->
        <!-- ============================================== -->
        <div class="px-8 pt-6 pb-4 shrink-0">
          <div v-if="bloqueado"
            class="mb-5 px-5 py-3 rounded-lg border border-orange-200 bg-orange-50 text-orange-800 font-semibold text-lg">
            Este ingreso ya fue enviado o timbrado. Puedes consultar el desglose, pero no modificarlo.
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="px-5 py-4 bg-blue-50 border border-blue-200 rounded-lg">
              <p class="text-base font-bold text-blue-700 mb-1">Depósito</p>
              <p class="text-2xl font-black text-blue-800">{{ formatearDinero(deposito) }}</p>
            </div>
            <div class="px-5 py-4 bg-gray-50 border border-gray-200 rounded-lg">
              <p class="text-base font-bold text-gray-500 mb-1">Total del desglose</p>
              <p class="text-2xl font-black text-gray-800">{{ formatearDinero(totalDesglose) }}</p>
            </div>
            <div class="px-5 py-4 border rounded-lg" :class="claseDiferencia.caja">
              <p class="text-base font-bold mb-1" :class="claseDiferencia.etiqueta">
                Diferencia {{ diferencia > 0 ? '(saldo a favor)' : (diferencia < 0 ? '(saldo en contra)' : '') }}
              </p>
              <p class="text-2xl font-black" :class="claseDiferencia.monto">{{ formatearDinero(diferencia) }}</p>
            </div>
          </div>

          <!-- PESTAÑAS (solo aplica a ingresos con proveedores) -->
          <div v-if="tieneProveedores" class="flex gap-2 mt-6 border-b border-gray-200" role="tablist">
            <button type="button" role="tab" :aria-selected="vistaActiva === 'montos'" @click="vista = 'montos'"
              class="px-5 py-2.5 font-bold text-lg border-b-2 -mb-px transition-colors focus:outline-none"
              :class="vistaActiva === 'montos' ? 'border-blue-500 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700'">
              Montos por operación
            </button>
            <button type="button" role="tab" :aria-selected="vistaActiva === 'proveedores'" @click="vista = 'proveedores'"
              class="px-5 py-2.5 font-bold text-lg border-b-2 -mb-px transition-colors focus:outline-none flex items-center gap-2"
              :class="vistaActiva === 'proveedores' ? 'border-blue-500 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700'">
              Pagos a proveedores
              <span class="px-2 py-0.5 rounded-full text-sm font-black"
                :class="hayPendientes ? 'bg-orange-100 text-orange-700' : 'bg-gray-200 text-gray-600'">
                {{ lineasProveedor.length }}
              </span>
            </button>
          </div>
        </div>

        <!-- ============================================== -->
        <!-- TABLA DE OPERACIONES                           -->
        <!-- ============================================== -->
        <div class="flex-1 overflow-auto px-8 pb-4">
          <div v-show="vistaActiva === 'montos'">
          <div v-if="filas.length === 0"
            class="text-center py-14 px-6 bg-gray-50 border border-dashed border-gray-300 rounded-xl">
            <p class="text-xl font-bold text-gray-600 mb-1">Este ingreso no tiene operaciones registradas.</p>
            <p class="text-lg text-gray-500 mb-5" v-if="!bloqueado">Agrega una fila para capturar el desglose manualmente.</p>
            <button v-if="!bloqueado" type="button" @click="agregarFila"
              class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-bold transition-colors">
              Agregar operación
            </button>
          </div>

          <div v-else class="border border-gray-200 rounded-xl overflow-x-auto">
            <table class="w-full text-left">
              <thead class="bg-gray-50 text-gray-700 text-base font-black border-b border-gray-200">
                <tr>
                  <th class="px-4 py-3 min-w-[220px]">Referencia</th>
                  <th v-for="concepto in conceptos" :key="'th-' + concepto.key" class="px-3 py-3 text-right min-w-[140px]">
                    {{ concepto.label }}
                  </th>
                  <th v-if="mostrarGpc" class="px-4 py-3 text-right min-w-[140px]">Total GPC</th>
                  <th class="px-4 py-3 text-right w-[110px]"><span class="sr-only">Acciones</span></th>
                </tr>
              </thead>

              <tbody class="text-gray-700 text-lg">
                <template v-for="(fila, index) in filas">
                  <tr :key="'fila-' + fila._uid" class="border-b border-gray-100 align-top">
                    <td class="px-4 py-3">
                      <input type="text" v-model="fila.referencia" :disabled="bloqueado"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md font-bold text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-600"
                        placeholder="Pedimento o contenedor">
                      <p class="text-sm font-semibold text-gray-400 mt-1.5">{{ etiquetaOperacion(fila) }}</p>
                    </td>

                    <td v-for="concepto in conceptos" :key="'td-' + fila._uid + '-' + concepto.key" class="px-3 py-3">
                      <input type="number" step="0.01" v-model.number="fila[concepto.key]" :disabled="bloqueado"
                        class="w-full px-3 py-2 border rounded-md text-right font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-600"
                        :class="aNumero(fila[concepto.key]) !== 0 ? 'border-green-300 bg-green-50 text-green-800' : 'border-gray-300'">
                    </td>

                    <td v-if="mostrarGpc" class="px-4 py-3 text-right">
                      <span class="inline-block py-2 font-black text-gray-800">{{ formatearDinero(totalGpcFila(fila)) }}</span>
                    </td>

                    <td class="px-4 py-3 text-right whitespace-nowrap">
                      <button v-if="tieneProveedores" type="button" @click="alternarDetalle(fila._uid)"
                        class="p-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="detalleAbierto(fila._uid) ? 'bg-indigo-500 text-white' : 'text-indigo-600 bg-indigo-100 hover:bg-indigo-200'"
                        :title="detalleAbierto(fila._uid) ? 'Ocultar proveedores y facturas' : 'Ver proveedores y facturas'">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                      </button>
                      <button v-if="!bloqueado" type="button" @click="eliminarFila(index)"
                        class="p-2 rounded-lg text-red-500 bg-red-100 hover:bg-red-500 hover:text-white transition-colors ml-1 focus:outline-none focus:ring-2 focus:ring-red-400"
                        title="Quitar operación">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                      </button>
                    </td>
                  </tr>

                  <!-- DETALLE DE PROVEEDORES Y FACTURAS -->
                  <tr v-if="tieneProveedores && detalleAbierto(fila._uid)" :key="'detalle-' + fila._uid"
                    class="bg-indigo-50/40 border-b border-gray-200">
                    <td :colspan="totalColumnas" class="px-4 py-4">
                      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                        <div v-for="prov in proveedores" :key="'prov-' + fila._uid + '-' + prov.concepto"
                          class="bg-white border border-gray-200 rounded-lg p-3">
                          <p class="text-base font-black text-gray-700 mb-2">{{ prov.concepto }}</p>
                          <label class="block text-sm font-bold text-gray-500 mb-1">Proveedor</label>
                          <input type="text" v-model="fila[prov.proveedor]" :disabled="bloqueado"
                            class="w-full px-3 py-1.5 mb-2 border border-gray-300 rounded-md text-base focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100">
                          <label class="block text-sm font-bold text-gray-500 mb-1">Factura</label>
                          <input type="text" v-model="fila[prov.factura]" :disabled="bloqueado"
                            class="w-full px-3 py-1.5 mb-2 border border-gray-300 rounded-md text-base focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100">
                          <template v-if="prov.montoUsd">
                            <label class="block text-sm font-bold text-blue-700 mb-1">Monto en dólares (USD)</label>
                            <input type="number" step="0.01" v-model.number="fila[prov.montoUsd]" :disabled="bloqueado"
                              class="w-full px-3 py-1.5 mb-2 border border-blue-300 bg-blue-50 rounded-md text-base text-right font-bold focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100">
                          </template>
                          <label class="block text-sm font-bold text-gray-500 mb-1">Estatus de pago</label>
                          <select v-model="fila[prov.estatus]" :disabled="bloqueado"
                            class="w-full px-3 py-1.5 border rounded-md text-base font-bold focus:outline-none focus:ring-2 focus:ring-blue-500"
                            :class="claseEstatus(fila[prov.estatus])">
                            <option v-for="opcion in estatusPago" :key="'det-' + opcion.value" :value="opcion.value">{{ opcion.label }}</option>
                          </select>
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>

              <tfoot class="text-lg">
                <tr class="bg-gray-100 border-t-2 border-gray-300">
                  <td class="px-4 py-3 font-black text-gray-800">Suma del desglose</td>
                  <td v-for="concepto in conceptos" :key="'suma-' + concepto.key" class="px-3 py-3 text-right font-black text-gray-800">
                    {{ formatearDinero(sumas[concepto.key]) }}
                  </td>
                  <td v-if="mostrarGpc" class="px-4 py-3 text-right font-black text-gray-800">{{ formatearDinero(sumaGpc) }}</td>
                  <td></td>
                </tr>
                <tr class="bg-white">
                  <td class="px-4 py-3 font-bold text-gray-500">Registrado en el ingreso</td>
                  <td v-for="concepto in conceptos" :key="'reg-' + concepto.key" class="px-3 py-3 text-right font-bold"
                    :class="coincideConIngreso(concepto) ? 'text-gray-500' : 'text-red-600'"
                    :title="coincideConIngreso(concepto) ? '' : 'No coincide con la suma del desglose'">
                    {{ formatearDinero(registradoEnIngreso(concepto)) }}
                  </td>
                  <td v-if="mostrarGpc" class="px-4 py-3 text-right font-bold"
                    :class="Math.abs(aNumero(ingreso.total_gpc) - sumaGpc) < 0.01 ? 'text-gray-500' : 'text-red-600'">
                    {{ formatearDinero(ingreso.total_gpc) }}
                  </td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <button v-if="!bloqueado && filas.length > 0" type="button" @click="agregarFila"
            class="mt-4 text-green-700 bg-green-100 hover:bg-green-200 px-5 py-2.5 rounded-lg font-bold flex items-center gap-2 transition-colors">
            <span class="text-xl leading-none">+</span> Agregar operación
          </button>
          </div>

          <!-- ============================================== -->
          <!-- PAGOS A PROVEEDORES                            -->
          <!-- ============================================== -->
          <div v-show="vistaActiva === 'proveedores'">
            <div v-if="filas.length === 0"
              class="text-center py-14 px-6 bg-gray-50 border border-dashed border-gray-300 rounded-xl">
              <p class="text-xl font-bold text-gray-600 mb-1">Este ingreso no tiene operaciones registradas.</p>
              <p class="text-lg text-gray-500">Agrega una operación desde "Montos por operación".</p>
            </div>

            <template v-else>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                <div class="px-5 py-4 bg-gray-50 border border-gray-200 rounded-lg">
                  <p class="text-base font-bold text-gray-500 mb-1">Total a proveedores</p>
                  <p v-for="t in totalesPorMoneda" :key="'tt-' + t.moneda" class="text-2xl font-black text-gray-800">
                    {{ formatearDinero(t.total) }} <span class="text-lg text-gray-500">{{ t.moneda }}</span>
                  </p>
                  <p v-if="totalesPorMoneda.length === 0" class="text-2xl font-black text-gray-400">{{ formatearDinero(0) }}</p>
                </div>
                <div class="px-5 py-4 bg-orange-50 border border-orange-200 rounded-lg">
                  <p class="text-base font-bold text-orange-700 mb-1">Pendiente de pago</p>
                  <p v-for="t in totalesPorMoneda" :key="'tp-' + t.moneda" class="text-2xl font-black text-orange-800">
                    {{ formatearDinero(t.pendiente) }} <span class="text-lg text-orange-600">{{ t.moneda }}</span>
                  </p>
                  <p v-if="totalesPorMoneda.length === 0" class="text-2xl font-black text-orange-300">{{ formatearDinero(0) }}</p>
                </div>
                <div class="px-5 py-4 bg-green-50 border border-green-200 rounded-lg">
                  <p class="text-base font-bold text-green-700 mb-1">Pagado</p>
                  <p v-for="t in totalesPorMoneda" :key="'tg-' + t.moneda" class="text-2xl font-black text-green-800">
                    {{ formatearDinero(t.pagado) }} <span class="text-lg text-green-600">{{ t.moneda }}</span>
                  </p>
                  <p v-if="totalesPorMoneda.length === 0" class="text-2xl font-black text-green-300">{{ formatearDinero(0) }}</p>
                </div>
              </div>

              <div class="border border-gray-200 rounded-xl overflow-x-auto">
                <table class="w-full text-left text-lg">
                  <thead class="text-white text-base font-bold" style="background-color: #2A3A4D;">
                    <tr>
                      <th class="px-3 py-3 text-center">Sucursal</th>
                      <th class="px-3 py-3 text-center min-w-[180px]">Cliente</th>
                      <th class="px-3 py-3 text-center">Pedimento</th>
                      <th class="px-3 py-3 text-center">PXCC</th>
                      <th class="px-3 py-3 text-center min-w-[160px]">Proveedor</th>
                      <th class="px-3 py-3 text-center min-w-[150px]">Factura P.</th>
                      <th class="px-3 py-3 text-center min-w-[160px]">Monto</th>
                      <th class="px-3 py-3 text-center">Moneda</th>
                      <th class="px-3 py-3 text-center">Factura SC</th>
                      <th class="px-3 py-3 text-center min-w-[170px]">Estatus</th>
                    </tr>
                  </thead>
                  <template v-for="(fila, indiceFila) in filas">
                    <tbody :key="'grupo-' + fila._uid" class="text-gray-800"
                      :class="indiceFila > 0 ? 'border-t-4 border-gray-200' : ''">
                      <tr v-for="(prov, indiceConcepto) in proveedores" :key="fila._uid + '-' + prov.monto"
                        class="border-b border-gray-100"
                        :class="lineaVacia(fila, prov) ? 'bg-gray-50/60' : 'bg-white'">

                        <!-- Datos de la operación (una sola vez por bloque, como en el Sheet) -->
                        <td v-if="indiceConcepto === 0" :rowspan="proveedores.length"
                          class="px-3 py-2 text-center font-semibold align-middle border-r border-gray-100">{{ sucursalCorta }}</td>
                        <td v-if="indiceConcepto === 0" :rowspan="proveedores.length"
                          class="px-3 py-2 text-center font-bold align-middle border-r border-gray-100">{{ ingreso.cliente_nombre }}</td>
                        <td v-if="indiceConcepto === 0" :rowspan="proveedores.length"
                          class="px-3 py-2 text-center font-semibold align-middle border-r border-gray-100">{{ fila.pedimento || '—' }}</td>

                        <td class="px-3 py-2 text-center font-bold uppercase"
                          :class="lineaVacia(fila, prov) ? 'text-gray-400' : 'text-gray-800'">{{ prov.concepto }}</td>
                        <td class="px-3 py-1.5">
                          <input type="text" v-model="fila[prov.proveedor]" :disabled="bloqueado"
                            class="w-full min-w-[190px] px-2 py-1.5 border border-gray-300 rounded-md text-center uppercase focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:border-transparent">
                        </td>
                        <td class="px-3 py-1.5">
                          <input type="text" v-model="fila[prov.factura]" :disabled="bloqueado"
                            class="w-full min-w-[150px] px-2 py-1.5 border border-gray-300 rounded-md text-center focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:border-transparent">
                        </td>
                        <td class="px-3 py-1.5">
                          <!-- LLC: se paga en dólares (columna K del Sheet) -->
                          <template v-if="prov.montoUsd">
                            <input type="number" step="0.01" v-model.number="fila[prov.montoUsd]" :disabled="bloqueado"
                              class="w-full min-w-[150px] px-2 py-1.5 border border-blue-300 bg-blue-50 rounded-md text-right font-bold text-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:border-transparent">
                            <p v-if="aNumero(fila[prov.monto]) !== 0" class="text-sm font-semibold text-gray-500 text-right mt-1">
                              Equivale a {{ formatearDinero(fila[prov.monto]) }} {{ moneda }}
                            </p>
                          </template>
                          <input v-else type="number" step="0.01" v-model.number="fila[prov.monto]" :disabled="bloqueado"
                            class="w-full min-w-[150px] px-2 py-1.5 border border-gray-300 rounded-md text-right font-bold focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:border-transparent">
                        </td>
                        <td class="px-3 py-2 text-center">
                          <span v-if="prov.monedaFija" class="px-2 py-0.5 rounded bg-blue-100 text-blue-800 font-bold">{{ monedaLinea({ fila, prov }) }}</span>
                          <span v-else class="font-semibold">{{ monedaLinea({ fila, prov }) }}</span>
                        </td>

                        <td v-if="indiceConcepto === 0" :rowspan="proveedores.length"
                          class="px-3 py-2 text-center align-middle border-l border-gray-100">
                          <span class="inline-block px-3 py-1 rounded bg-green-100 text-green-800 font-bold">{{ folioScDeFila(fila) }}</span>
                        </td>

                        <td class="px-3 py-1.5">
                          <select v-model="fila[prov.estatus]" :disabled="bloqueado"
                            class="w-full min-w-[190px] px-2 py-1.5 border rounded-md font-bold text-center focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:cursor-not-allowed"
                            :class="claseEstatus(fila[prov.estatus])">
                            <option v-for="opcion in estatusPago" :key="'est-' + opcion.value" :value="opcion.value">{{ opcion.label }}</option>
                          </select>
                        </td>
                      </tr>
                    </tbody>
                  </template>
                  <tfoot>
                    <tr v-for="sub in subtotalesProveedor" :key="'sub-' + sub.llave" class="bg-blue-50 border-b border-blue-100">
                      <td colspan="6" class="px-3 py-2 text-right font-bold text-gray-700">{{ sub.proveedor }}</td>
                      <td class="px-3 py-2 text-right font-black text-gray-800">{{ formatearDinero(sub.total) }}</td>
                      <td class="px-3 py-2 text-center font-semibold text-gray-600">{{ sub.moneda }}</td>
                      <td colspan="2" class="px-3 py-2 text-base font-semibold text-orange-700">
                        <span v-if="sub.pendiente > 0">Pendiente: {{ formatearDinero(sub.pendiente) }}</span>
                        <span v-else class="text-green-700">Liquidado</span>
                      </td>
                    </tr>
                    <tr v-for="(t, i) in totalesPorMoneda" :key="'total-' + t.moneda" class="bg-gray-100"
                      :class="i === 0 ? 'border-t-2 border-gray-300' : ''">
                      <td colspan="6" class="px-3 py-3 text-right font-black text-gray-800">Total a pagar al proveedor ({{ t.moneda }})</td>
                      <td class="px-3 py-3 text-right font-black text-gray-900">{{ formatearDinero(t.total) }}</td>
                      <td class="px-3 py-3 text-center font-bold text-gray-700">{{ t.moneda }}</td>
                      <td colspan="2"></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </template>
          </div>
        </div>

        <!-- ============================================== -->
        <!-- PIE                                            -->
        <!-- ============================================== -->
        <div class="px-8 py-5 border-t border-gray-200 bg-gray-50 flex flex-col lg:flex-row lg:items-center justify-between gap-4 shrink-0">
          <label v-if="!bloqueado" class="flex items-start gap-3 cursor-pointer select-none max-w-2xl">
            <input type="checkbox" v-model="recalcularTotales" class="mt-1 w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span>
              <span class="block text-lg font-bold text-gray-700">Actualizar los totales del ingreso con la suma del desglose</span>
              <span class="block text-base text-gray-500">También se recalcula el saldo a favor o en contra del cliente.</span>
            </span>
          </label>
          <span v-else></span>

          <div class="flex items-center gap-3 justify-end">
            <button type="button" @click="intentarCerrar"
              class="px-6 py-3 rounded-lg font-bold text-gray-700 bg-white border border-gray-300 hover:bg-gray-100 transition-colors">
              {{ bloqueado ? 'Cerrar' : 'Cancelar' }}
            </button>
            <button v-if="!bloqueado" type="button" @click="guardar" :disabled="guardando || !hayCambios"
              class="px-6 py-3 rounded-lg font-bold text-white transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
              style="background-color: #2A3A4D;">
              <svg v-if="guardando" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              {{ guardando ? 'Guardando...' : 'Guardar cambios' }}
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';

// Columnas visibles por tipo de ingreso. "campoIngreso" indica la columna equivalente en ingresos_conciliados.
const CONCEPTOS_POR_TIPO = {
  manzanillo: [
    { key: 'anticipo', label: 'Anticipo' },
    { key: 'garantias', label: 'Garantías' },
    { key: 'desglose_naviera', label: 'Desg. naviera' },
    { key: 'impuestos', label: 'Impuestos' },
    { key: 'flete', label: 'Alm / Flete' },
    { key: 'monto_cfdi', label: 'Honorarios', campoIngreso: 'honorarios' }
  ],
  intshipperts: [
    { key: 'anticipo', label: 'Anticipo' },
    { key: 'flete', label: 'Alman / Flete' },
    { key: 'monto_cfdi', label: 'Facturado', campoIngreso: 'honorarios' }
  ],
  transportactics: [
    { key: 'flete', label: 'Flete (XML)' },
    { key: 'pago_proveedor', label: 'Pago proveedor' },
    { key: 'ganancia', label: 'Ganancia' }
  ],
  general: [
    { key: 'monto_cfdi', label: 'Honorarios', campoIngreso: 'honorarios' },
    { key: 'impuestos', label: 'Impuestos' },
    { key: 'eci', label: 'ECI' },
    { key: 'maniobras', label: 'Maniobras' },
    { key: 'flete', label: 'Flete' },
    { key: 'muestras', label: 'Muestras' },
    { key: 'llc', label: 'LLC' }
  ]
};

// Conceptos que forman el Total GPC (misma regla que store() en el controlador)
const CONCEPTOS_GPC = {
  manzanillo: ['anticipo', 'garantias', 'desglose_naviera', 'impuestos', 'flete'],
  intshipperts: ['anticipo', 'garantias', 'desglose_naviera', 'impuestos', 'flete'],
  transportactics: [],
  general: ['impuestos', 'eci', 'maniobras', 'flete', 'muestras', 'llc']
};

const CAMPOS_NUMERICOS = [
  'monto_cfdi', 'anticipo', 'impuestos', 'eci', 'maniobras', 'flete', 'muestras',
  'llc', 'garantias', 'desglose_naviera', 'pago_proveedor', 'ganancia',
  'monto_llc_usd'
];

// Conceptos de la pestaña "Pagos a proveedores", en el mismo orden que el Google Sheet
const PROVEEDORES = [
  { concepto: 'Impuestos', monto: 'impuestos', proveedor: 'proveedor_impuestos', factura: 'factura_impuestos', estatus: 'estatus_pago_impuestos' },
  { concepto: 'ECI', monto: 'eci', proveedor: 'proveedor_eci', factura: 'factura_eci', estatus: 'estatus_pago_eci' },
  { concepto: 'Maniobras', monto: 'maniobras', proveedor: 'proveedor_maniobras', factura: 'factura_maniobras', estatus: 'estatus_pago_maniobras' },
  { concepto: 'Flete', monto: 'flete', proveedor: 'proveedor_flete', factura: 'factura_flete', estatus: 'estatus_pago_flete' },
  { concepto: 'Muestras', monto: 'muestras', proveedor: 'proveedor_muestras', factura: 'factura_muestras', estatus: 'estatus_pago_muestras' },
  { concepto: 'LLC', monto: 'llc', proveedor: 'proveedor_llc', factura: 'factura_llc', estatus: 'estatus_pago_llc', montoUsd: 'monto_llc_usd', monedaFija: 'USD' }
];

// '' = pendiente (se guarda como NULL en la base de datos)
const ESTATUS_PAGO = [
  { value: '', label: 'Pendiente' },
  { value: 'PAGADO', label: 'Pagado' },
  { value: 'PAGADO POR ANT', label: 'Pagado por ANT' }
];

const CAMPOS_TEXTO = PROVEEDORES.reduce((acc, p) => acc.concat([p.proveedor, p.factura, p.estatus]), []);

let contadorUid = 0;

export default {
  name: 'ModalDesgloseIngreso',
  props: {
    ingresoId: {
      type: [Number, String],
      required: true
    }
  },
  data() {
    return {
      cargando: true,
      guardando: false,
      errorCarga: '',
      ingreso: null,
      tipo: 'general',
      bloqueado: false,
      filas: [],
      snapshot: '',
      recalcularTotales: true,
      detallesAbiertos: [],
      proveedores: PROVEEDORES,
      estatusPago: ESTATUS_PAGO,
      vista: 'montos',
      moneda: 'MXN',
      overflowOriginal: ''
    };
  },
  computed: {
    conceptos() {
      return CONCEPTOS_POR_TIPO[this.tipo] || CONCEPTOS_POR_TIPO.general;
    },
    conceptosGpc() {
      return CONCEPTOS_GPC[this.tipo] || CONCEPTOS_GPC.general;
    },
    mostrarGpc() {
      return this.tipo !== 'transportactics';
    },
    tieneProveedores() {
      return this.tipo === 'general';
    },
    vistaActiva() {
      return this.tieneProveedores ? this.vista : 'montos';
    },
    sucursalCorta() {
      const sucursal = String((this.ingreso && this.ingreso.sucursal_origen) || '').toUpperCase();
      const abreviaturas = { NOGALES: 'NOG', LAREDO: 'NLD', TIJUANA: 'TIJ', MEXICALI: 'MXL', MANZANILLO: 'ZLO' };
      const ciudad = Object.keys(abreviaturas).find(c => sucursal.includes(c));
      return ciudad ? abreviaturas[ciudad] : (sucursal || 'N/A');
    },
    // Una línea por cada concepto de cada operación (todas, como en el Sheet)
    lineasProveedor() {
      if (!this.tieneProveedores) {
        return [];
      }
      const lineas = [];
      this.filas.forEach(fila => {
        PROVEEDORES.forEach(prov => {
          lineas.push({ key: fila._uid + '-' + prov.monto, fila, prov });
        });
      });
      return lineas;
    },
    // Totales separados por moneda (la LLC siempre se paga en dólares)
    totalesPorMoneda() {
      const grupos = {};
      this.lineasProveedor.forEach(linea => {
        const monto = this.montoLinea(linea);
        if (monto === 0) {
          return;
        }
        const moneda = this.monedaLinea(linea);
        if (!grupos[moneda]) {
          grupos[moneda] = { moneda, total: 0, pendiente: 0, pagado: 0 };
        }
        grupos[moneda].total = this.redondear(grupos[moneda].total + monto);
        if (linea.fila[linea.prov.estatus]) {
          grupos[moneda].pagado = this.redondear(grupos[moneda].pagado + monto);
        } else {
          grupos[moneda].pendiente = this.redondear(grupos[moneda].pendiente + monto);
        }
      });
      // MXN primero, después USD
      return Object.values(grupos).sort((a, b) => (a.moneda === 'MXN' ? -1 : 1) - (b.moneda === 'MXN' ? -1 : 1));
    },
    hayPendientes() {
      return this.totalesPorMoneda.some(t => t.pendiente > 0);
    },
    subtotalesProveedor() {
      const grupos = {};
      this.lineasProveedor.forEach(linea => {
        const monto = this.montoLinea(linea);
        if (monto === 0) {
          return;
        }
        const nombre = String(linea.fila[linea.prov.proveedor] || '').trim().toUpperCase() || 'Sin proveedor';
        const moneda = this.monedaLinea(linea);
        const llave = nombre + '|' + moneda;
        if (!grupos[llave]) {
          grupos[llave] = { llave, proveedor: nombre, moneda, total: 0, pendiente: 0 };
        }
        grupos[llave].total = this.redondear(grupos[llave].total + monto);
        if (!linea.fila[linea.prov.estatus]) {
          grupos[llave].pendiente = this.redondear(grupos[llave].pendiente + monto);
        }
      });
      return Object.values(grupos);
    },
    sumas() {
      const resultado = {};
      CAMPOS_NUMERICOS.forEach(campo => {
        resultado[campo] = this.redondear(this.filas.reduce((acc, f) => acc + this.aNumero(f[campo]), 0));
      });
      return resultado;
    },
    sumaGpc() {
      return this.redondear(this.conceptosGpc.reduce((acc, campo) => acc + this.sumas[campo], 0));
    },
    deposito() {
      return this.ingreso ? this.aNumero(this.ingreso.monto_deposito) : 0;
    },
    totalDesglose() {
      if (this.tipo === 'transportactics') {
        return this.sumas.flete;
      }
      return this.redondear(this.sumaGpc + this.sumas.monto_cfdi);
    },
    diferencia() {
      return this.redondear(this.deposito - this.totalDesglose);
    },
    claseDiferencia() {
      if (this.diferencia < 0) {
        return { caja: 'bg-red-50 border-red-200', etiqueta: 'text-red-600', monto: 'text-red-700' };
      }
      if (this.diferencia > 0) {
        return { caja: 'bg-yellow-50 border-yellow-200', etiqueta: 'text-yellow-700', monto: 'text-yellow-800' };
      }
      return { caja: 'bg-green-50 border-green-200', etiqueta: 'text-green-600', monto: 'text-green-700' };
    },
    hayCambios() {
      return JSON.stringify(this.serializar()) !== this.snapshot;
    }
  },
  mounted() {
    this.overflowOriginal = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', this.onKeydown);
    this.cargar();
  },
  beforeDestroy() {
    document.body.style.overflow = this.overflowOriginal;
    document.removeEventListener('keydown', this.onKeydown);
  },
  methods: {
    async cargar() {
      this.cargando = true;
      this.errorCarga = '';
      try {
        const response = await axios.get(`/ingresos-conciliados/${this.ingresoId}/desglose`);
        this.ingreso = response.data.ingreso;
        this.tipo = response.data.tipo || 'general';
        this.bloqueado = !!response.data.bloqueado;
        this.moneda = response.data.moneda || 'MXN';
        this.filas = (response.data.filas || []).map(raw => this.normalizarFila(raw));
        this.detallesAbiertos = [];
        this.snapshot = JSON.stringify(this.serializar());
      } catch (error) {
        console.error('Error cargando desglose', error);
        this.errorCarga = (error.response && error.response.data && error.response.data.error)
          ? error.response.data.error
          : 'Revisa tu conexión e inténtalo de nuevo.';
      } finally {
        this.cargando = false;
      }
    },

    normalizarFila(raw) {
      const fila = {
        _uid: ++contadorUid,
        referencia_original: raw ? raw.referencia : null,
        referencia: raw ? (raw.referencia || '') : '',
        operacion_id: raw ? raw.operacion_id : null,
        operacion_type: raw ? (raw.operacion_type || 'GENERICO') : 'GENERICO',
        pedimento: raw ? (raw.pedimento || '') : ''
      };
      CAMPOS_NUMERICOS.forEach(campo => {
        fila[campo] = raw ? this.aNumero(raw[campo]) : 0;
      });
      CAMPOS_TEXTO.forEach(campo => {
        fila[campo] = raw && raw[campo] ? String(raw[campo]) : '';
      });
      return fila;
    },

    serializar() {
      return this.filas.map(f => {
        const salida = {
          referencia_original: f.referencia_original || null,
          referencia: String(f.referencia || '').trim()
        };
        CAMPOS_NUMERICOS.forEach(campo => {
          salida[campo] = this.redondear(this.aNumero(f[campo]));
        });
        CAMPOS_TEXTO.forEach(campo => {
          salida[campo] = f[campo] ? String(f[campo]).trim() : '';
        });
        return salida;
      });
    },

    agregarFila() {
      const nueva = this.normalizarFila(null);
      this.filas.push(nueva);
    },

    async eliminarFila(index) {
      const fila = this.filas[index];
      const tieneMontos = CAMPOS_NUMERICOS.some(campo => this.aNumero(fila[campo]) !== 0);

      if (tieneMontos || fila.referencia_original) {
        const result = await Swal.fire({
          title: '¿Quitar esta operación?',
          text: `Se quitará "${fila.referencia || 'sin referencia'}" del desglose al guardar los cambios.`,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#DC2626',
          cancelButtonColor: '#9CA3AF',
          confirmButtonText: 'Sí, quitar',
          cancelButtonText: 'Cancelar'
        });
        if (!result.isConfirmed) {
          return;
        }
      }

      this.detallesAbiertos = this.detallesAbiertos.filter(uid => uid !== fila._uid);
      this.filas.splice(index, 1);
    },

    alternarDetalle(uid) {
      if (this.detallesAbiertos.includes(uid)) {
        this.detallesAbiertos = this.detallesAbiertos.filter(u => u !== uid);
      } else {
        this.detallesAbiertos.push(uid);
      }
    },

    detalleAbierto(uid) {
      return this.detallesAbiertos.includes(uid);
    },

    etiquetaOperacion(fila) {
      const tipo = String(fila.operacion_type || '').toUpperCase();
      if (tipo.includes('IMPORTACION')) {
        return `Importación #${fila.operacion_id}`;
      }
      if (tipo.includes('EXPORTACION')) {
        return `Exportación #${fila.operacion_id}`;
      }
      return fila.referencia_original ? 'Sin operación vinculada' : 'Captura manual (nueva)';
    },

    // La LLC se paga en dólares; el resto en la moneda del ingreso
    monedaLinea(linea) {
      return linea.prov.monedaFija || this.moneda;
    },

    // Monto a pagar: para la LLC es el monto en dólares, para los demás el monto del concepto
    montoLinea(linea) {
      const campo = linea.prov.montoUsd || linea.prov.monto;
      return this.aNumero(linea.fila[campo]);
    },

    lineaVacia(fila, prov) {
      const sinMonto = this.montoLinea({ fila, prov }) === 0 && this.aNumero(fila[prov.monto]) === 0;
      const sinProveedor = String(fila[prov.proveedor] || '').trim() === '';
      return sinMonto && sinProveedor;
    },

    // La referencia suele venir como "FOLIO SC - PEDIMENTO - CLIENTE"; se muestra solo el folio
    folioScDeFila(fila) {
      const texto = String(fila.referencia || '').trim();
      if (!texto) {
        return '—';
      }
      return texto.split(' - ')[0].trim();
    },

    claseEstatus(estatus) {
      if (estatus === 'PAGADO POR ANT') {
        return 'bg-yellow-300 border-yellow-400 text-gray-900';
      }
      if (estatus === 'PAGADO') {
        return 'bg-green-100 border-green-300 text-green-800';
      }
      return 'bg-white border-gray-300 text-gray-600';
    },

    totalGpcFila(fila) {
      return this.redondear(this.conceptosGpc.reduce((acc, campo) => acc + this.aNumero(fila[campo]), 0));
    },

    registradoEnIngreso(concepto) {
      if (!this.ingreso) {
        return 0;
      }
      const campo = concepto.campoIngreso || concepto.key;
      return this.aNumero(this.ingreso[campo]);
    },

    coincideConIngreso(concepto) {
      return Math.abs(this.registradoEnIngreso(concepto) - this.sumas[concepto.key]) < 0.01;
    },

    async guardar() {
      const datos = this.serializar();

      if (datos.some(f => f.referencia === '')) {
        Swal.fire('Falta información', 'Todas las operaciones necesitan una referencia (pedimento o contenedor).', 'warning');
        return;
      }

      const referencias = datos.map(f => f.referencia.toUpperCase());
      const repetidas = referencias.filter((ref, i) => referencias.indexOf(ref) !== i);
      if (repetidas.length > 0) {
        Swal.fire('Referencias repetidas', `La referencia "${repetidas[0]}" aparece más de una vez. Cada operación debe tener una referencia distinta.`, 'warning');
        return;
      }

      this.guardando = true;
      try {
        const response = await axios.put(`/ingresos-conciliados/${this.ingresoId}/desglose`, {
          filas: datos,
          recalcular_totales: this.recalcularTotales
        });

        Swal.fire({
          title: 'Desglose guardado',
          text: response.data.message || 'Los cambios se guardaron correctamente.',
          icon: 'success',
          toast: true,
          position: 'top-end',
          timer: 2500,
          showConfirmButton: false
        });

        this.$emit('desglose-actualizado', response.data);
      } catch (error) {
        console.error('Error guardando desglose', error);
        let mensaje = 'No se pudo guardar el desglose.';
        if (error.response && error.response.data) {
          if (error.response.data.error) {
            mensaje = error.response.data.error;
          } else if (error.response.data.errors) {
            const primerError = Object.values(error.response.data.errors)[0];
            mensaje = Array.isArray(primerError) ? primerError[0] : String(primerError);
          }
        }
        Swal.fire('No se guardaron los cambios', mensaje, 'error');
      } finally {
        this.guardando = false;
      }
    },

    async intentarCerrar() {
      if (this.guardando) {
        return;
      }
      if (!this.bloqueado && !this.cargando && this.hayCambios) {
        const result = await Swal.fire({
          title: '¿Descartar los cambios?',
          text: 'Hay cambios en el desglose que no se han guardado.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#DC2626',
          cancelButtonColor: '#2A3A4D',
          confirmButtonText: 'Descartar',
          cancelButtonText: 'Seguir editando'
        });
        if (!result.isConfirmed) {
          return;
        }
      }
      this.$emit('close');
    },

    onKeydown(evento) {
      if (evento.key === 'Escape' && !Swal.isVisible()) {
        this.intentarCerrar();
      }
    },

    aNumero(valor) {
      if (valor === null || valor === undefined || valor === '') {
        return 0;
      }
      if (typeof valor === 'number') {
        return isNaN(valor) ? 0 : valor;
      }
      const limpio = String(valor).replace(/[$,\s]/g, '');
      const numero = parseFloat(limpio);
      return isNaN(numero) ? 0 : numero;
    },

    redondear(valor) {
      return Math.round((Number(valor) || 0) * 100) / 100;
    },

    formatearDinero(monto) {
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2
      }).format(parseFloat(monto) || 0);
    }
  }
};
</script>

<style scoped>
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

input[type=number] {
  -moz-appearance: textfield;
}
</style>