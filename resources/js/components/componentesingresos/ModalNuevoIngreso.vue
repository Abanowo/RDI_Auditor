<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-6 md:p-10">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl flex flex-col overflow-hidden" style="max-height: 90vh;">

      <div class="px-8 py-6 flex justify-between items-center shrink-0 z-10" style="background-color: #2A3A4D;">
        <h3 class="text-white text-2xl font-bold uppercase tracking-wider flex items-center gap-3">
          <!-- Ícono de Nuevo Documento (Agregar) SVG -->
          <svg class="w-7 h-7 shrink-0 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
            </path>
          </svg>
          NUEVO INGRESO CONCILIADO
        </h3>
        <button @click="$emit('close')" class="text-blue-200 hover:text-white transition-colors">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <div class="p-8 overflow-y-auto flex-1 grid grid-cols-2 gap-8" style="min-height: 0;">

        <div class="col-span-1">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">SUCURSAL ORIGEN *</label>
          <multiselect v-model="form.sucursal_origen" :options="opcionesSucursal" placeholder="Seleccione..."
            :show-labels="false" class="text-xl">
          </multiselect>

          <div v-if="sucursalSeleccionada" class="mt-4 flex items-center gap-6">
            <label v-if="esSucursalOtraBase"
              class="inline-flex items-center cursor-pointer bg-blue-100 px-4 py-3 rounded-lg border border-blue-200 hover:bg-blue-200 transition-colors shadow-sm">
              <input v-model="checkTransportactics" type="checkbox"
                class="form-checkbox h-6 w-6 text-blue-600 rounded cursor-pointer">
              <span class="ml-3 text-base text-blue-800 font-extrabold uppercase">Es Transportactics</span>
            </label>

            <label v-if="esSucursalManzanilloBase"
              class="inline-flex items-center cursor-pointer bg-purple-100 px-4 py-3 rounded-lg border border-purple-200 hover:bg-purple-200 transition-colors shadow-sm">
              <input v-model="checkIntshipperts" type="checkbox"
                class="form-checkbox h-6 w-6 text-purple-600 rounded cursor-pointer">
              <span class="ml-3 text-base text-purple-800 font-extrabold uppercase">Es Intshipperts</span>
            </label>
          </div>
        </div>

        <div class="col-span-1">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">BANCO RECEPTOR</label>
          <multiselect v-model="form.banco_receptor" :options="opcionesBanco" placeholder="Seleccione..."
            :show-labels="false" class="text-xl">
          </multiselect>
        </div>

        <div class="col-span-2">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">FECHA DE DEPÓSITO *</label>
          <VueCtkDateTimePicker v-model="form.fecha" format="YYYY-MM-DD" formatted="YYYY-MM-DD" color="#1d4ed8"
            button-color="#1d4ed8" :only-date="true" label="Seleccione la fecha" class="text-xl">
          </VueCtkDateTimePicker>
        </div>

        <div class="col-span-2">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">RAZÓN SOCIAL CLIENTE (Filtra los folios) *</label>
          <multiselect v-model="form.cliente" :options="clientesLocales" track-by="id" label="nombre"
            placeholder="Seleccione o escriba un nuevo cliente y presione Enter..." :show-labels="false"
            :taggable="true" @tag="agregarClienteManual" class="text-xl">
            <template slot="noResult">Presione Enter para agregar como nuevo cliente</template>
          </multiselect>
        </div>

        <div class="col-span-1">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">
            FOLIOS / PEDIMENTOS DISPONIBLES *
          </label>

          <div class="flex w-full min-w-0">
            <multiselect v-model="form.referenciasObj" :options="opcionesReferenciasCombinadas" :multiple="true"
              track-by="label" label="label" :taggable="true" @tag="agregarReferenciaManual"
              placeholder="Escribe o selecciona una opción..." select-label="Presiona Enter para seleccionar"
              tag-placeholder="Presiona Enter para agregar este texto libre"
              class="custom-multiselect text-xl flex-1 min-w-0">
            </multiselect>

            <button @click="buscarYRecalcularPedimento" type="button" title="Calcular montos desde XML/Sheet"
              :disabled="cargandoSheet"
              class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-4 rounded-r transition-colors flex items-center justify-center -ml-1 z-10 shrink-0 disabled:opacity-50">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
              </svg>
            </button>
          </div>
          <span v-if="!form.sucursal_origen" class="text-sm text-red-500 font-bold mt-2 inline-block">
            ⚠️ Selecciona una sucursal para cargar la lista.
          </span>
        </div>

        <div class="col-span-1">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">MONTO DEPÓSITO ($) *</label>
          <input v-model="form.monto_deposito" @input="alCambiarDeposito" type="number" step="0.01" placeholder="0.00"
            class="w-full border border-gray-300 rounded-lg px-5 py-4 text-3xl font-black text-purple-700 focus:outline-none focus:border-gray-400 h-12">
        </div>

        <div class="col-span-2 flex items-center gap-8 mt-2 p-5 bg-gray-100 border border-gray-200 rounded-xl">
          <label class="text-lg font-extrabold text-gray-500 uppercase tracking-wide">
            TIPO DE COMPROBANTE:
          </label>
          <div class="flex gap-8">
            <label class="inline-flex items-center cursor-pointer">
              <input v-model="tiposComprobanteArray" type="checkbox" value="CFDI"
                class="form-checkbox h-6 w-6 focus:ring-teal-500 cursor-pointer rounded" style="color: #00C09F;">
              <span class="ml-3 text-xl text-gray-700 font-bold">CFDI (Factura)</span>
            </label>
            <label class="inline-flex items-center cursor-pointer">
              <input v-model="tiposComprobanteArray" type="checkbox" value="Nota Cargo"
                class="form-checkbox h-6 w-6 focus:ring-teal-500 cursor-pointer rounded" style="color: #00C09F;">
              <span class="ml-3 text-xl text-gray-700 font-bold">Nota Cargo</span>
            </label>
          </div>
        </div>

        <div class="col-span-2 mt-4 border rounded-xl p-6 bg-gray-100" style="border-color: #2A3A4D;">
          <h4 class="text-lg font-extrabold uppercase tracking-wide mb-6" style="color: #2A3A4D;">DESGLOSE:</h4>

          <div class="grid grid-cols-3 gap-6" v-if="esTransportactics">
            <div>
              <label class="block text-base text-gray-700 mb-2">Flete (XML):</label>
              <input v-model="form.flete" type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div>
              <label class="block text-base text-gray-700 mb-2">Pago Proveedor:</label>
              <input v-model="form.pago_proveedor" type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div>
              <label class="block text-base text-emerald-700 mb-2 font-bold">Ganancia:</label>
              <input :value="(parseFloat(form.flete || 0) - parseFloat(form.pago_proveedor || 0)).toFixed(2)" readonly
                type="number"
                class="w-full border border-emerald-200 rounded-lg px-4 py-3 text-xl bg-emerald-100 text-emerald-700 font-bold focus:outline-none cursor-not-allowed">
            </div>
          </div>

          <div class="grid grid-cols-2 gap-6" v-else-if="esIntshipperts">
            <div>
              <label class="block text-base text-gray-700 mb-2">Anticipo:</label>
              <input v-model="form.anticipo" type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div>
              <label class="block text-base text-gray-700 mb-2">ALMAN / Flete:</label>
              <input v-model="form.flete" type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
          </div>

          <div class="grid grid-cols-4 gap-6" v-else-if="esManzanillo">
            <div><label class="block text-base text-gray-700 mb-2">Anticipo:</label><input v-model="form.anticipo"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Garantías:</label><input v-model="form.garantias"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Desglose Naviera:</label><input
                v-model="form.desglose_naviera" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Impuestos:</label><input v-model="form.impuestos"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">ALMAN / Flete:</label><input v-model="form.flete"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Honorarios:</label><input v-model="form.honorarios"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneFacturasHonorarios"
                :title="tieneFacturasHonorarios ? 'Suma de los abonos a las facturas de honorarios' : ''"
                :class="tieneFacturasHonorarios ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
          </div>

          <div class="grid grid-cols-4 gap-6" v-else>
            <div><label class="block text-base text-gray-700 mb-2">Honorarios:</label><input v-model="form.honorarios"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneFacturasHonorarios"
                :title="tieneFacturasHonorarios ? 'Suma de los abonos a las facturas de honorarios' : ''"
                :class="tieneFacturasHonorarios ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Impuestos:</label><input v-model="form.impuestos"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">ECI:</label><input v-model="form.eci" type="number"
                step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Maniobras:</label><input v-model="form.maniobras"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Flete:</label><input v-model="form.flete"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Muestras:</label><input v-model="form.muestras"
                type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">LLC:</label><input v-model="form.llc" type="number"
                step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota"
                :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
          </div>

          <!-- DOCUMENTOS CON PAGOS PARCIALES: NOTAS DE CARGO Y FACTURAS DE HONORARIOS -->
          <div v-if="tieneNotasCargo || tieneFacturasHonorarios"
            class="mt-6 p-5 bg-white border border-gray-200 rounded-xl shadow-sm flex flex-col gap-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h5 class="text-base font-black uppercase tracking-wide text-gray-700">Pagos a cuenta de las facturas</h5>
                <p class="text-sm text-gray-500">El abono es la parte de cada documento que cubre este depósito. Primero se cubre la nota de cargo y después los honorarios.</p>
              </div>
              <button type="button" @click="repartirDepositoEnFacturas"
                class="px-4 py-2 rounded-lg bg-blue-100 text-blue-700 font-bold text-sm hover:bg-blue-200 transition-colors">
                Calcular abono con el depósito
              </button>
            </div>

            <div v-for="seccion in seccionesDocumentos" :key="'sec-' + seccion.llave" class="overflow-x-auto">
              <p class="text-sm font-black uppercase text-gray-600 mb-2">{{ seccion.titulo }}</p>
              <table class="w-full text-base">
                <thead class="text-gray-500 text-sm font-bold uppercase border-b border-gray-200">
                  <tr>
                    <th class="text-left py-2 pr-3">Folio</th>
                    <th class="text-right py-2 px-3">Facturado</th>
                    <th class="text-right py-2 px-3">Pagado antes</th>
                    <th class="text-right py-2 px-3">Pendiente</th>
                    <th class="text-right py-2 px-3">Abono</th>
                    <th class="text-right py-2 pl-3">Queda</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="d in seccion.documentos" :key="seccion.llave + '-' + d.folio" class="border-b border-gray-100">
                    <td class="py-2 pr-3 font-black text-gray-800">{{ d.folio }}</td>
                    <td class="py-2 px-3 text-right">{{ formatearDinero(d.total_facturado) }}</td>
                    <td class="py-2 px-3 text-right text-gray-500">{{ formatearDinero(d.pagado_previo) }}</td>
                    <td class="py-2 px-3 text-right font-bold" :class="d.pendiente > 0 ? 'text-orange-600' : 'text-green-600'">
                      {{ d.pendiente > 0 ? formatearDinero(d.pendiente) : 'Liquidada' }}
                    </td>
                    <td class="py-2 px-3 text-right">
                      <input type="number" step="0.01" min="0" :max="d.pendiente" v-model.number="d.abono"
                        :disabled="d.pendiente <= 0"
                        @input="seccion.alCambiar(false)" @change="seccion.alCambiar(true)"
                        class="w-40 border border-gray-300 rounded-lg px-3 py-2 text-right font-bold focus:outline-none focus:border-gray-800 disabled:bg-gray-100">
                    </td>
                    <td class="py-2 pl-3 text-right font-black" :class="restanteFactura(d) > 0 ? 'text-red-600' : 'text-green-600'">
                      {{ restanteFactura(d) > 0 ? formatearDinero(restanteFactura(d)) : 'Se liquida' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div
            class="col-span-full mt-6 p-5 bg-white border border-gray-200 rounded-xl flex flex-wrap items-center justify-between shadow-sm">
            <div class="flex items-center gap-4 px-3">
              <span class="text-base text-gray-500 font-bold uppercase">Total GPC:</span>
              <span class="text-2xl font-black text-blue-600">
                <strong style="color:#2a5bd7;">{{ formatearDinero(totalGPC) }}</strong>
              </span>
            </div>
            <div class="hidden md:block w-px h-10 bg-gray-200"></div>
            <div class="flex items-center gap-4 px-3">
              <span class="text-base text-gray-500 font-bold uppercase">Total Honorarios:</span>
              <span class="text-2xl font-black" style="color: #00C09F;">
                <strong style="color:#00b786;">{{ formatearDinero(totalHonorarios) }}</strong>
              </span>
            </div>
            <div class="flex items-center gap-4 bg-yellow-100 border border-yellow-200 px-6 py-3 rounded-lg ml-auto">
              <span class="text-base text-yellow-700 font-bold uppercase">Suma Total:</span>
              <span class="text-3xl font-black text-yellow-600">{{ formatearDinero(sumaTotal) }}</span>
            </div>
          </div>

        </div>
      </div>

      <!-- 5. FOOTER: shrink-0 (o flex-none) para que se ancle al fondo -->
      <div class="px-8 py-6 border-t border-gray-200 flex justify-end gap-6 bg-gray-100 shrink-0 z-10">
        <button @click="$emit('close')"
          class="px-8 py-4 rounded-lg bg-gray-200 text-gray-700 text-xl font-bold hover:bg-gray-300 transition-colors shadow-sm">
          Cancelar
        </button>
        <button @click="guardarIngreso" :disabled="isSubmitting"
          :class="['px-8 py-4 rounded-lg text-white text-xl font-bold transition-colors shadow-sm', isSubmitting ? 'bg-gray-400 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700']">
          {{ isSubmitting ? 'Guardando...' : 'Guardar Nuevo Ingreso' }}
        </button>
      </div>

    </div>
  </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';
import VueCtkDateTimePicker from 'vue-ctk-date-time-picker';
import 'vue-ctk-date-time-picker/dist/vue-ctk-date-time-picker.css';

export default {
  name: 'ModalNuevoIngreso',
  components: { Multiselect, VueCtkDateTimePicker },
  props: {
    opcionesSucursal: Array,
    opcionesBanco: Array,
    opcionesCliente: Array
  },
  data() {
    return {
      clientesLocales: [],
      isSubmitting: false,
      depositoDesdeAbonos: false,
      cargandoSheet: false,
      pedimentosSheet: [],
      tiposComprobanteArray: ['CFDI'],
      checkTransportactics: false,
      checkIntshipperts: false,
      form: {
        sucursal_origen: null,
        anticipo: null,
        garantias: null,
        desglose_naviera: null,
        banco_receptor: null,
        fecha: null,
        cliente: null,
        referenciasObj: [],
        operaciones: [],
        pedimento_detectado: null,
        folio_sc: null,
        operacion_id: null,
        operation_type: null,
        monto_deposito: null,
        pago_proveedor: null,
        ganancia: null,
        honorarios: null,
        impuestos: null,
        eci: null,
        maniobras: null,
        flete: null,
        muestras: null,
        llc: null,
        llc_usd: null,
        proveedor_maniobras: null,
        factura_maniobras: null,
        detalle_maniobras: [],
        proveedor_flete: null,
        factura_flete: null,
        proveedor_muestras: null,
        factura_muestras: null,
        proveedor_llc: null,
        factura_llc: null,
        // Pagos parciales
        facturas_honorarios: [],
        notas_cargo: [],
        conceptos_gpc_base: null,
      },
      opcionesReferenciasPredefinidas: [
        'Depósito de garantías',
        'Devolución de garantías',
        'Compensación SPEI',
        'Anticipo por error',
        'Abono préstamo colaborador',
        'Transferencia interna',
        'Préstamos intersucursales',
        'Anticipo cliente',
        'Pendiente desglose',
        'Saldos a favor',
        'Pago devuelto / Pago rechazado'
      ],
    }
  },
  computed: {
    sucursalSeleccionada() {
      const s = this.form.sucursal_origen;
      return typeof s === 'object' && s !== null ? (s.nombre || s.id) : (s || '');
    },
    esSucursalManzanilloBase() {
      return String(this.sucursalSeleccionada).toUpperCase().includes('MANZANILLO');
    },
    esSucursalOtraBase() {
      return this.sucursalSeleccionada && !this.esSucursalManzanilloBase;
    },
    esIntshipperts() {
      const sucursalUpper = String(this.sucursalSeleccionada).toUpperCase();
      return sucursalUpper.includes('INTSHIPPERT') || this.checkIntshipperts;
    },
    esManzanillo() {
      const sucursal = String(this.sucursalSeleccionada).toUpperCase();
      return sucursal.includes('MANZANILLO') && !this.esIntshipperts;
    },
    esTransportactics() {
      const sucursalUpper = String(this.sucursalSeleccionada).toUpperCase();
      return sucursalUpper.includes('TRANSPORTACTIC') || this.checkTransportactics;
    },

    // ==========================================
    // PAGOS PARCIALES
    // ==========================================
    tieneFacturasHonorarios() {
      return !this.esTransportactics && !this.esIntshipperts
        && Array.isArray(this.form.facturas_honorarios) && this.form.facturas_honorarios.length > 0;
    },
    tieneNotasCargo() {
      return !this.esTransportactics && !this.esIntshipperts
        && Array.isArray(this.form.notas_cargo) && this.form.notas_cargo.length > 0;
    },
    // Conceptos que forman la nota de cargo (mismos que suma monto_gpc de cada operación en el backend)
    conceptosNotaCargo() {
      if (this.esTransportactics || this.esIntshipperts) {
        return [];
      }
      if (this.esManzanillo) {
        return ['anticipo', 'garantias', 'desglose_naviera', 'impuestos'];
      }
      return ['impuestos', 'eci', 'maniobras', 'flete', 'muestras', 'llc'];
    },
    tituloConceptoNota() {
      return this.tieneNotasCargo ? 'Se calcula con el abono a la nota de cargo' : '';
    },
    // Tablas de documentos: primero la nota de cargo, después los honorarios
    seccionesDocumentos() {
      const secciones = [];
      if (this.tieneNotasCargo) {
        secciones.push({
          llave: 'nc',
          titulo: 'Nota de cargo (GPC)',
          documentos: this.form.notas_cargo,
          alCambiar: (limitar) => {
            this.recalcularGpcDesdeNotas(limitar);
            this.sincronizarDepositoConAbonos();
          }
        });
      }
      if (this.tieneFacturasHonorarios) {
        secciones.push({
          llave: 'fh',
          titulo: 'Factura de honorarios (CFDI)',
          documentos: this.form.facturas_honorarios,
          alCambiar: (limitar) => {
            this.recalcularHonorariosDesdeFacturas(limitar);
            this.sincronizarDepositoConAbonos();
          }
        });
      }
      return secciones;
    },

    opcionesPedimentos() {
      let sheetArray = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];
      
      if (!this.form.cliente) {
        return sheetArray;
      }

      const nombreC = typeof this.form.cliente === 'object' && this.form.cliente !== null 
        ? this.form.cliente.nombre 
        : this.form.cliente;
      const clienteSeleccionado = String(nombreC || '').toUpperCase().trim();

      return sheetArray.filter(p => {
        if (!p || !p.cliente) return true; 

        const clienteP = String(p.cliente).toUpperCase().trim();
        return clienteP.includes(clienteSeleccionado) || clienteSeleccionado.includes(clienteP);
      });
    },

    totalHonorarios() {
      const flete = Number(this.form.flete) || 0;
      const anticipo = Number(this.form.anticipo) || 0;
      const honorarios = Number(this.form.honorarios) || 0;

      if (this.esTransportactics) {
        return flete;
      }
      if (this.esIntshipperts) {
        return flete + anticipo;
      }
      return honorarios;
    },

    totalGPC() {
      const impuestos = Number(this.form.impuestos) || 0;
      const eci = Number(this.form.eci) || 0;
      const maniobras = Number(this.form.maniobras) || 0;
      const flete = Number(this.form.flete) || 0;
      const muestras = Number(this.form.muestras) || 0;
      const llc = Number(this.form.llc) || 0;
      const anticipo = Number(this.form.anticipo) || 0;
      const garantias = Number(this.form.garantias) || 0;
      const naviera = Number(this.form.desglose_naviera) || 0;

      if (this.esTransportactics) {
        // En Transportactics, el flete es Honorarios; todos los demás gastos forman GPC
        return impuestos + eci + maniobras + muestras + llc + anticipo + garantias + naviera;
      }
      if (this.esIntshipperts) {
        // En Intshipperts, flete + anticipo son Honorarios; el resto suma como GPC
        return impuestos + eci + maniobras + muestras + llc + garantias + naviera;
      }
      if (this.esManzanillo) {
        return impuestos + flete + anticipo + garantias + naviera;
      }

      // Resto de sucursales
      return impuestos + eci + maniobras + flete + muestras + llc;
    },

    sucursalReal() {
      let s = String(this.sucursalSeleccionada).toUpperCase();

      if (this.checkTransportactics && !s.includes('TRANSPORTACTIC')) {
        s = s.replace(' IMPO', '').replace(' EXPO', '').trim() + ' TRANSPORTACTICS';
      } else if (this.checkIntshipperts && !s.includes('INTSHIPPERT')) {
        s = s.replace(' IMPO', '').replace(' EXPO', '').trim() + ' INTSHIPPERTS';
      }

      return s;
    },

    sumaTotal() {
      return this.totalGPC + this.totalHonorarios;
    },

    opcionesReferenciasCombinadas() {
      let mapa = new Map();

      // 1. Agregar opciones predefinidas como objetos estructurados
      this.opcionesReferenciasPredefinidas.forEach(ref => {
        let texto = typeof ref === 'object' ? (ref.label || ref.folio) : String(ref);
        if (texto && !mapa.has(texto)) {
          mapa.set(texto, { label: texto, folio: texto });
        }
      });

      // 2. Extraer fragmentos si vienen de folio_sc o pedimento_detectado
      const extraerYAgregar = (texto) => {
        if (texto && typeof texto === 'string') {
          let fragmentos = texto.split(',').map(item => item.trim()).filter(Boolean);
          fragmentos.forEach(fragmento => {
            if (!mapa.has(fragmento)) {
              mapa.set(fragmento, { label: fragmento, folio: fragmento });
            }
          });
        }
      };

      extraerYAgregar(this.form.folio_sc);
      extraerYAgregar(this.form.pedimento_detectado);
      
      // 3. Agregar pedimentos obtenidos del Sheet
      if (this.opcionesPedimentos && Array.isArray(this.opcionesPedimentos)) {
        this.opcionesPedimentos.forEach(objeto => {
          let texto = typeof objeto === 'object' ? (objeto.label || objeto.folio || objeto.pedimento) : String(objeto);
          if (texto && !mapa.has(texto)) {
            mapa.set(texto, { label: texto, folio: texto, cliente: objeto.cliente || '' });
          }
        });
      }

      // 4. Garantizar referencias seleccionadas
      if (Array.isArray(this.form.referenciasObj)) {
        this.form.referenciasObj.forEach(ref => {
          let textoRef = typeof ref === 'object' ? (ref.label || ref.folio || ref.referencia) : String(ref);
          if (textoRef && !mapa.has(textoRef)) {
            mapa.set(textoRef, { label: textoRef, folio: textoRef });
          }
        });
      }

      return Array.from(mapa.values());
    }
  },
  watch: {
    opcionesCliente: {
      immediate: true,
      handler(newVal) {
        if (newVal) {
          this.clientesLocales = [...newVal];
        }
      }
    },
    'form.sucursal_origen': function (newVal, oldVal) {
      this.checkTransportactics = false;
      this.checkIntshipperts = false;

      // Los documentos encontrados pertenecen a la sucursal anterior
      this.form.facturas_honorarios = [];
      this.form.notas_cargo = [];
      this.form.conceptos_gpc_base = null;

      if (newVal && newVal !== oldVal) {
        this.cargarListaPedimentos();
      }
    },
    checkTransportactics(newVal, oldVal) {
      if (newVal !== oldVal) {
        this.cargarListaPedimentos();
      }
    },
    checkIntshipperts(newVal, oldVal) {
      if (newVal !== oldVal) {
        this.cargarListaPedimentos();
      }
    },
    'form.referenciasObj': function (newVal) {
      if (!this.form.cliente && newVal && newVal.length > 0) {
        // Tomamos la última referencia seleccionada
        const refSeleccionada = newVal[newVal.length - 1];
        if (!refSeleccionada) {
          return;
        }

        const refTexto = typeof refSeleccionada === 'object'
          ? (refSeleccionada.label || refSeleccionada.folio || refSeleccionada.cliente || '')
          : String(refSeleccionada);

        const refUpper = refTexto.toUpperCase().trim();
        const sheetArray = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];

        // 1. Buscar en el listado de pedimentos del Sheet (por coincidencia parcial)
        const dataPedimento = sheetArray.find(p => {
          const pLabel = String(p.label || p.folio || p.pedimento || '').toUpperCase().trim();
          return pLabel !== '' && (pLabel === refUpper || refUpper.includes(pLabel) || pLabel.includes(refUpper));
        });

        if (dataPedimento && dataPedimento.cliente) {
          const nombreClientePedimento = String(dataPedimento.cliente).toUpperCase().trim();
          const clienteEncontrado = this.opcionesCliente.find(c => {
            const nombreOpcion = String(c.nombre).toUpperCase().trim();
            return nombreOpcion.includes(nombreClientePedimento) || nombreClientePedimento.includes(nombreOpcion);
          });

          if (clienteEncontrado) {
            this.form.cliente = clienteEncontrado;
            return;
          }
        }

        // 2. Si no matchó con el Sheet, busca si el nombre de algún cliente está dentro del texto del tag
        const clienteDirecto = this.opcionesCliente.find(c => {
          const nombreOpcion = String(c.nombre).toUpperCase().trim();
          return nombreOpcion.length > 3 && refUpper.includes(nombreOpcion);
        });

        if (clienteDirecto) {
          this.form.cliente = clienteDirecto;
        }
      }
    }
  },
  methods: {
    // Se llama desde el input de abono de cualquiera de las dos tablas
    alCambiarAbono(tipo, limitar) {
      if (tipo === 'nc') {
        this.recalcularGpcDesdeNotas(limitar);
      } else {
        this.recalcularHonorariosDesdeFacturas(limitar);
      }
      this.sincronizarDepositoConAbonos();
    },

    // El depósito se ajusta a lo que suman los abonos (GPC + honorarios)
    sincronizarDepositoConAbonos() {
      this.$set(this.form, 'monto_deposito', Number(this.sumaTotal.toFixed(2)));
    },

    // Se llama solo cuando el usuario escribe en Monto depósito
    alCambiarDeposito() {
      if (this.tieneNotasCargo || this.tieneFacturasHonorarios) {
        this.repartirDepositoEnFacturas();
      }
    },
    obtenerPedimentoLimpio(ref) {
      if (!ref) {
        return '';
      }

      // 1. SI ES UN OBJETO (Multiselect), priorizar propiedad explícita de pedimento si existe
      if (typeof ref === 'object' && ref !== null) {
        if (ref.pedimento) {
          return String(ref.pedimento).trim();
        }
        if (ref.num_pedimiento) {
          return String(ref.num_pedimiento).trim();
        }
        ref = ref.folio || ref.label || ref.value || ref.text || '';
      }

      let str = String(ref).trim().toUpperCase();
      if (!str || str === 'UNDEFINED' || str === 'NULL') {
        return '';
      }

      // 2. PURGA EXPLICITA: Eliminamos folios de LLC, Facturas, Notas de Cargo, F- y SC-
      str = str.replace(/LLC\s*[-_]?\s*\d+/gi, '');          // Quita 'LLC 12345', 'LLC-12345'
      str = str.replace(/FAC(?:TURA)?\s*[-_]?\s*\d+/gi, ''); // Quita 'FACTURA 600123'
      str = str.replace(/F-\d+/gi, '');                      // Quita 'F-12345'
      str = str.replace(/SC\s*[-_]?\s*\d+/gi, '');           // Quita 'SC-12345'

      // 3. BUSQUEDA POR PATENTE + PEDIMENTO (ej: 3739-6000464 o 3711 / 6000464)
      const matchPatente = str.match(/(?:\d{4})\s*[-_/\s]\s*(\d{7})/);
      if (matchPatente) {
        return matchPatente[1]; // Retorna los 7 dígitos del pedimento
      }

      // 4. BÚSQUEDA DE 7 DÍGITOS EXACTOS (Pedimento aislado)
      const match7Digitos = str.match(/\b\d{7}\b/);
      if (match7Digitos) {
        return match7Digitos[0];
      }

      // 5. FALLBACK: Limpieza de guiones tradicional si no es pedimento de 7 dígitos
      let partes = str.split(' - ').map(p => p.trim()).filter(Boolean);
      if (partes.length >= 3) {
        str = partes[1];
      } else if (partes.length === 2) {
        str = partes[0];
      } else {
        str = partes[0] || str;
      }

      if (str.includes('/')) str = str.split('/')[0].trim();

      return str;
    },
    agregarClienteManual(nuevoNombre) {
      const nuevoCliente = {
        id: 'nuevo_' + Date.now(),
        nombre: nuevoNombre.toUpperCase().trim()
      };
      this.clientesLocales.push(nuevoCliente);
      this.form.cliente = nuevoCliente;
    },
    agregarReferenciaManual(nuevaReferencia) {
      // 1. Separar por comas (también acepta punto y coma, saltos de línea y tabuladores)
      const fragmentos = String(nuevaReferencia || '')
        .split(/[,;\n\r\t]+/)
        .map(t => t.trim())
        .filter(Boolean);

      if (fragmentos.length === 0) {
        return;
      }

      // 2. Copia de lo ya seleccionado + set para evitar duplicados
      const actuales = Array.isArray(this.form.referenciasObj) ? [...this.form.referenciasObj] : [];
      const yaSeleccionados = new Set(
        actuales.map(r => String(typeof r === 'object' && r !== null ? (r.label || r.folio) : r).toUpperCase().trim())
      );

      // 3. Agregar cada fragmento como etiqueta independiente
      fragmentos.forEach(texto => {
        const clave = texto.toUpperCase();
        if (yaSeleccionados.has(clave)) {
          return;
        }

        // Si ya existe como opción (p. ej. viene del Sheet con su cliente), reutilizamos ese objeto
        const existente = this.opcionesReferenciasCombinadas.find(
          o => String(o.label).toUpperCase().trim() === clave
        );

        actuales.push(existente || { label: texto, folio: texto });
        yaSeleccionados.add(clave);
      });

      // 4. Asignación única para que el watcher de referenciasObj se dispare una sola vez
      this.form.referenciasObj = actuales;
    },
    formatearDinero(monto) {
      return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 }).format(parseFloat(monto) || 0);
    },

    // ==========================================
    // PAGOS PARCIALES: FACTURAS DE HONORARIOS Y NOTAS DE CARGO
    // ==========================================
    redondear2(valor) {
      return Math.round((Number(valor) || 0) * 100) / 100;
    },

    // Lo que quedará pendiente del documento después de este abono
    restanteFactura(d) {
      return Math.max(0, this.redondear2((Number(d.total_facturado) || 0) - (Number(d.pagado_previo) || 0) - (Number(d.abono) || 0)));
    },

    // Ajusta cada abono entre 0 y lo pendiente
    limitarAbonos(lista) {
      lista.forEach(d => {
        const maximo = Number(d.pendiente) || 0;
        d.abono = this.redondear2(Math.min(Math.max(Number(d.abono) || 0, 0), maximo));
      });
    },

    // Honorarios del ingreso = suma de los abonos a las facturas
    recalcularHonorariosDesdeFacturas(limitar = false) {
      const lista = Array.isArray(this.form.facturas_honorarios) ? this.form.facturas_honorarios : [];
      if (lista.length === 0) {
        return;
      }
      if (limitar) {
        this.limitarAbonos(lista);
      }
      this.$set(this.form, 'honorarios', this.redondear2(lista.reduce((acc, f) => acc + (Number(f.abono) || 0), 0)));
    },

    // Guarda los conceptos de la nota de cargo tal como vienen de la búsqueda (montos completos)
    capturarBaseGpc() {
      if (!this.tieneNotasCargo) {
        this.form.conceptos_gpc_base = null;
        return;
      }
      const base = {};
      this.conceptosNotaCargo.forEach(k => {
        base[k] = Number(this.form[k]) || 0;
      });
      this.form.conceptos_gpc_base = base;
    },

    // Prorratea los conceptos de la nota de cargo según lo abonado (abono / total facturado)
    recalcularGpcDesdeNotas(limitar = false) {
      const notas = Array.isArray(this.form.notas_cargo) ? this.form.notas_cargo : [];
      const base = this.form.conceptos_gpc_base;
      if (notas.length === 0 || !base) {
        return;
      }
      if (limitar) {
        this.limitarAbonos(notas);
      }

      const totalFacturado = notas.reduce((acc, n) => acc + (Number(n.total_facturado) || 0), 0);
      const totalAbono = notas.reduce((acc, n) => acc + (Number(n.abono) || 0), 0);
      const factor = totalFacturado > 0 ? totalAbono / totalFacturado : 0;

      const llaves = this.conceptosNotaCargo;
      let sumaBase = 0;
      let mayor = null;
      llaves.forEach(k => {
        const v = Number(base[k]) || 0;
        sumaBase += v;
        if (mayor === null || v > (Number(base[mayor]) || 0)) {
          mayor = k;
        }
      });

      let suma = 0;
      llaves.forEach(k => {
        const v = this.redondear2((Number(base[k]) || 0) * factor);
        this.$set(this.form, k, v);
        suma += v;
      });

      // El centavo que se pierda en el redondeo se ajusta en el concepto más grande
      const diferencia = this.redondear2(this.redondear2(sumaBase * factor) - suma);
      if (mayor && diferencia !== 0) {
        this.$set(this.form, mayor, this.redondear2((Number(this.form[mayor]) || 0) + diferencia));
      }
    },

    // Reparte el depósito: primero la nota de cargo, después los honorarios
    repartirDepositoEnFacturas() {
      let disponible = Math.max(0, this.redondear2(Number(this.form.monto_deposito) || 0));

      if (this.tieneNotasCargo) {
        this.form.notas_cargo.forEach(n => {
          const abono = Math.min(disponible, Number(n.pendiente) || 0);
          n.abono = this.redondear2(abono);
          disponible = this.redondear2(disponible - abono);
        });
        this.recalcularGpcDesdeNotas();

        // GPC que no forma parte de la nota (p. ej. ALMAN / Flete en Manzanillo)
        const gpcNotas = this.conceptosNotaCargo.reduce((acc, k) => acc + (Number(this.form[k]) || 0), 0);
        disponible = Math.max(0, this.redondear2(disponible - (this.totalGPC - gpcNotas)));
      } else {
        disponible = Math.max(0, this.redondear2(disponible - this.totalGPC));
      }

      if (this.tieneFacturasHonorarios) {
        this.form.facturas_honorarios.forEach(f => {
          const abono = Math.min(disponible, Number(f.pendiente) || 0);
          f.abono = this.redondear2(abono);
          disponible = this.redondear2(disponible - abono);
        });
        this.recalcularHonorariosDesdeFacturas();
      }
    },

    sincronizarDepositoConAbonos() {
      const nuevoDeposito = Number(this.sumaTotal.toFixed(2));
      if (Number(this.form.monto_deposito) === nuevoDeposito) {
        return; // Sin cambio: el watcher no se dispararía y la bandera se quedaría encendida
      }
      this.depositoDesdeAbonos = true;
      this.$set(this.form, 'monto_deposito', nuevoDeposito);
    },

    claveFolio(folio) {
      return String(folio || '').replace(/\D/g, '').replace(/^0+/, '');
    },

    // Documento (factura o nota) que corresponde al folio de una operación
    documentoDeFolio(lista, folio) {
      const clave = this.claveFolio(folio);
      if (!clave || !Array.isArray(lista)) {
        return null;
      }
      return lista.find(d => String(d.folio) === clave) || null;
    },

    // Cuánto se multiplican los conceptos de una operación según el abono a su nota de cargo
    multiplicadorNota(nota) {
      const total = Number(nota.total_facturado) || 0;
      const factor = total > 0 ? (Number(nota.abono) || 0) / total : 0;
      const factorInicial = Number(nota.factor_inicial) || 0;
      return factorInicial > 0 ? factor / factorInicial : factor;
    },

    // Aviso cuando la factura o la nota ya tienen pagos en otros ingresos
    avisoPagosAnteriores(datos) {
      const lineas = [];
      const agregar = (lista, etiqueta) => {
        (lista || []).filter(d => Number(d.pagado_previo) > 0).forEach(d => {
          if (Number(d.pendiente) <= 0) {
            lineas.push(`<b>Factura ${d.folio}:</b> ya está liquidada (${this.formatearDinero(d.total_facturado)}).`);
          } else {
            lineas.push(`<b>${etiqueta} ${d.folio}:</b> anticipo ${this.formatearDinero(d.total_facturado)}, ` +
              `pagado ${this.formatearDinero(d.pagado_previo)}. <b>pendiente ${this.formatearDinero(d.pendiente)}</b>.`);
          }
        });
      };
      agregar(datos.notas_cargo, 'Nota de cargo');
      agregar(datos.facturas_honorarios, 'Factura de honorarios');
      return lineas;
    },

    agregarReferencia(nuevaReferencia) {
      const nuevaEtiqueta = {
        label: nuevaReferencia,
        id: nuevaReferencia
      };
      this.opcionesPedimentos.push(nuevaEtiqueta);
      this.form.referenciasObj.push(nuevaEtiqueta);
    },

    async cargarListaPedimentos() {
      this.cargandoSheet = true;
      try {
        const response = await axios.get('/ingresos-conciliados/listar-pedimentos', {
          params: {
            sucursal: this.sucursalSeleccionada,
            es_transportactics: this.checkTransportactics,
            es_intshipperts: this.esIntshipperts
          }
        });
        this.pedimentosSheet = Array.isArray(response.data) ? response.data : [];
      } catch (error) {
        console.error("No se pudo cargar la lista del Sheet", error);
      } finally {
        this.cargandoSheet = false;
      }
    },

    async buscarYRecalcularPedimento() {
      if (!this.form.sucursal_origen || this.form.referenciasObj.length === 0) {
        return Swal.fire('Atención', 'Selecciona la sucursal y agrega al menos un dato de búsqueda.', 'warning');
      }

      // Extraemos los pedimentos utilizando la función purgada anti-LLC y anti-Facturas
      let pedimentosLimpios = this.form.referenciasObj
        .map(ref => this.obtenerPedimentoLimpio(ref))
        .filter(p => p !== '');

      Swal.fire({
        title: 'Calculando...',
        text: 'Buscando información en el sistema...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
      });

      try {
        const response = await axios.post('/ingresos-conciliados/buscar-sheet', {
          pedimentos: pedimentosLimpios,
          sucursal: this.sucursalReal,
          tipo_comprobante: this.tiposComprobanteArray,
          ingreso_id: this.form.id || null
        });

        const datos = response.data;
        this.$set(this.form, 'metodo_pago', datos.metodo_pago || 'PUE');

        // 🎯 ASIGNACIÓN ESPECÍFICA SEGÚN EL TIPO DE OPERACIÓN
        if (this.esTransportactics) {
          const montoTransportactics = Number(datos.flete || datos.honorarios || 0);
          this.form.flete = montoTransportactics;
          this.form.honorarios = 0;
        } else if (this.esIntshipperts) {
          this.form.flete = Number(datos.flete) || 0;
          this.form.anticipo = Number(datos.anticipo) || 0;
          this.form.honorarios = 0;
        } else {
          this.form.honorarios = datos.honorarios !== undefined ? Number(datos.honorarios) : 0;
          this.form.flete = datos.flete !== undefined ? Number(datos.flete) : 0;
          this.form.anticipo = datos.anticipo !== undefined ? Number(datos.anticipo) : 0;
        }

        this.form.impuestos = datos.impuestos !== undefined ? Number(datos.impuestos) : 0;
        this.form.eci = datos.eci !== undefined ? Number(datos.eci) : 0;
        this.form.maniobras = datos.maniobras !== undefined ? Number(datos.maniobras) : 0;
        this.form.muestras = datos.muestras !== undefined ? Number(datos.muestras) : 0;
        this.form.llc = datos.llc !== undefined ? Number(datos.llc) : 0;
        this.form.llc_usd = datos.llc_usd !== undefined ? Number(datos.llc_usd) : 0;
        this.form.garantias = datos.garantias !== undefined ? Number(datos.garantias) : 0;
        this.form.desglose_naviera = datos.desglose_naviera !== undefined ? Number(datos.desglose_naviera) : 0;

        this.form.proveedor_maniobras = datos.proveedor_maniobras || null;
        this.form.factura_maniobras = datos.factura_maniobras || null;
        this.form.detalle_maniobras = datos.detalle_maniobras || [];
        this.form.proveedor_flete = datos.proveedor_flete || null;
        this.form.factura_flete = datos.factura_flete || null;
        this.form.proveedor_muestras = datos.proveedor_muestras || null;
        this.form.factura_muestras = datos.factura_muestras || null;
        this.form.proveedor_llc = datos.proveedor_llc || null;
        this.form.factura_llc = datos.factura_llc || null;
        this.form.folio_sc = datos.folio_sc || null;
        this.form.operacion_id = datos.operacion_id || null;
        this.form.operation_type = datos.operation_type || null;
        this.form.pedimento_detectado = datos.pedimento_detectado || null;
        this.form.operaciones = datos.operaciones || [];
        this.$set(this.form, 'pago_proveedor', Number(datos.pago_proveedor) || 0);

        // 🎯 PAGOS PARCIALES: notas de cargo (se prorratean los conceptos) y facturas de honorarios
        this.$set(this.form, 'facturas_honorarios', (datos.facturas_honorarios || []).map(f => ({ ...f })));
        this.$set(this.form, 'notas_cargo', (datos.notas_cargo || []).map(n => ({ ...n })));
        this.capturarBaseGpc();
        this.recalcularGpcDesdeNotas();
        this.recalcularHonorariosDesdeFacturas();

        // 🎯 CÁLCULO LIMPIO DEL MONTO DEPÓSITO
        if (this.sumaTotal > 0) {
          this.form.monto_deposito = Number(this.sumaTotal.toFixed(2));
        }

        if (datos.cliente_detectado) {
          const nombreExcel = datos.cliente_detectado.trim().toUpperCase();
          const clienteEncontrado = this.opcionesCliente.find(c => c.nombre.trim().toUpperCase() === nombreExcel);
          if (clienteEncontrado) {
            this.form.cliente = clienteEncontrado;
          }
        }

        // Aviso si la factura o la nota ya tienen pagos en otros ingresos
        const lineasPagos = this.avisoPagosAnteriores(datos);
        if (lineasPagos.length > 0) {
          Swal.fire({
            title: 'Documentos con pagos anteriores',
            html: `<div style="text-align:left">${lineasPagos.join('<br>')}</div>`,
            icon: 'info'
          });
        } else {
          Swal.fire({ title: '¡Datos listos!', text: 'Montos cargados en el formulario.', icon: 'success', timer: 2000, showConfirmButton: false });
        }
      } catch (error) {
        console.error("🔍 ERROR CRUDO:", error);
        let mensajeReal = 'No se pudo conectar con el servidor.';
        if (error.response && error.response.data) {
          mensajeReal = error.response.data.message || error.response.data.error || mensajeReal;
        } else if (error.message) {
          mensajeReal = "Error de Vue/JS: " + error.message;
        }
        Swal.fire({ title: 'Atención', text: mensajeReal, icon: (error.response && error.response.status === 404) ? 'info' : 'warning' });
      }
    },

    async guardarIngreso() {
      if (!this.form.cliente || !this.form.monto_deposito || !this.form.fecha) {
        return Swal.fire('Atención', 'Completa la fecha, cliente y el monto de depósito.', 'warning');
      }

      if (!this.form.referenciasObj || this.form.referenciasObj.length === 0) {
        return Swal.fire('Atención', 'Selecciona o escribe al menos una referencia/pedimento.', 'warning');
      }

      const confirmacion = await Swal.fire({
        title: '¿Guardar cambios?',
        text: "¿Estás seguro de que deseas actualizar este ingreso? Esta acción modificará los montos registrados.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#9ca3af',
        confirmButtonText: 'Sí, guardar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true
      });

      if (!confirmacion.isConfirmed) {
        return;
      }

      this.isSubmitting = true;
      const payload = { ...this.form };

      const tieneDesglose = Number(this.form.flete || 0) > 0 ||
                            Number(this.form.honorarios || 0) > 0 ||
                            Number(this.form.impuestos || 0) > 0 ||
                            Number(this.form.maniobras || 0) > 0 ||
                            Number(this.form.eci || 0) > 0 ||
                            Number(this.form.llc || 0) > 0 ||
                            Number(this.form.muestras || 0) > 0 ||
                            Number(this.form.anticipo || 0) > 0;

      if (!tieneDesglose && Number(this.form.monto_deposito || 0) > 0) {
        if (this.esTransportactics) {
          payload.flete = Number(this.form.monto_deposito);
          payload.honorarios = Number(this.form.monto_deposito);
        } else {
          payload.anticipo = Number(this.form.monto_deposito);
        }
      } else {
        if (this.esTransportactics) {
          const montoIngreso = Number(this.form.flete || 0);
          payload.flete = montoIngreso;
          payload.honorarios = montoIngreso;
        } else if (this.esIntshipperts) {
          payload.honorarios = 0;
          payload.flete = Number(this.form.flete || 0);
          payload.anticipo = Number(this.form.anticipo || 0);
        }
      }

      // 1. Manejo de Cliente
      if (payload.cliente && String(payload.cliente.id).startsWith('nuevo_')) {
        payload.cliente_id = null;
        payload.nuevo_cliente_nombre = payload.cliente.nombre;
      } else if (payload.cliente) {
        payload.cliente_id = payload.cliente.id;
      } else {
        payload.cliente_id = null;
      }

      // Validamos GPC y variables default
      payload.sucursal_origen = this.sucursalReal;
      payload.total_gpc = this.totalGPC;
      payload.anticipo = Number(payload.anticipo || this.form.anticipo || 0);

      delete payload.cliente;
      delete payload._original;
      delete payload.cp;

      // 2. EXTRACCIÓN BLINDADA DE LA COLUMNA 'referencia'
      payload.referencia = this.form.referenciasObj.map(r => {
        if (typeof r === 'object' && r !== null) {
          return r.folio || r.label || r.referencia || r.pedimento || '';
        }
        return String(r);
      }).filter(Boolean).join(', ');

      if (payload.pedimento_detectado && !this.esManzanillo) {
        payload.referencia = payload.pedimento_detectado;
      }

      // 3. CONSTRUCCIÓN DE 'OPERACIONES' PARA LA TABLA PIVOTE (ingreso_operacion)
      if (this.form.referenciasObj && Array.isArray(this.form.referenciasObj) && this.form.referenciasObj.length > 0) {
        const obtenerFolioLimpio = (val) => {
          if (!val) {
            return '';
          }
          let str = String(val).trim().toUpperCase();

          const matchPedimento = str.match(/(?:\d{4}\s*-\s*)?(\d{7})/);
          if (matchPedimento) {
            return matchPedimento[1];
          }

          str = str.replace('F-', '');
          let partes = str.split(' - ');
          if (partes.length >= 3) {
            str = partes[1].trim();
          } else if (partes.length === 2) {
            str = partes[0].trim();
          }
          if (str.includes('/')) {
            str = str.split('/')[0].trim();
          }

          return str;
        };

        const opsBuscadas = Array.isArray(this.form.operaciones) ? this.form.operaciones : [];
        const pedimentosSheet = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];
        const llavesGpc = this.conceptosNotaCargo;

        // Cada factura de honorarios se abona una sola vez aunque cubra varias operaciones
        const foliosConAbono = new Set();

        payload.operaciones = this.form.referenciasObj.map(ref => {
          const textoOriginal = typeof ref === 'object'
            ? (ref.folio || ref.label || ref.referencia || ref.pedimento || '')
            : String(ref);

          const folioLimpio = obtenerFolioLimpio(textoOriginal);
          const textoUpper = String(textoOriginal).toUpperCase().trim();

          const esCoincidencia = (o) => {
            if (!o) {
              return false;
            }
            const oFolioLimpio = obtenerFolioLimpio(o.folio || o.referencia || o.pedimento || o.id);
            const oFolioRaw = String(o.folio || o.referencia || o.label || '').toUpperCase().trim();

            // Pedimento de la operación (7 dígitos), para cuando la referencia es el pedimento
            const oPedimento = (String(o.pedimento || '').match(/\d{7}/) || [])[0] || '';

            return (folioLimpio && oFolioLimpio && (folioLimpio === oFolioLimpio || oFolioLimpio.includes(folioLimpio) || folioLimpio.includes(oFolioLimpio))) ||
              (oFolioRaw && (oFolioRaw === textoUpper || textoUpper.includes(oFolioRaw) || oFolioRaw.includes(textoUpper))) ||
              (oPedimento !== '' && textoUpper.includes(oPedimento));
          };

          let op = opsBuscadas.find(esCoincidencia) || pedimentosSheet.find(esCoincidencia) || {};

          const opId = op.operacion_id || op.id || op.pedimento_id || null;
          const opType = op.operation_type || op.operacion_type || op.type || 'GENERICO';

          let montoCfdi = Number(op.monto_cfdi ?? op.flete ?? op.monto_flete ?? 0);
          let montoGpc = Number(op.monto_gpc ?? op.total_gpc ?? op.gpc ?? 0);

          if (this.esTransportactics) {
            montoCfdi = Number(payload.flete || 0);
            montoGpc = 0;
          } else if (this.esIntshipperts) {
            montoCfdi = Number(payload.flete || 0) + Number(payload.anticipo || 0);
            montoGpc = 0;
          }

          const folioOperacion = op.folio_factura || op.folio;
          const claveFolio = this.claveFolio(folioOperacion);

          // Factura de honorarios: su CFDI es lo abonado (una sola vez por folio)
          let facturaOp = this.documentoDeFolio(this.form.facturas_honorarios, folioOperacion);
          let abonoFactura = null;

          if (facturaOp && !this.esTransportactics && !this.esIntshipperts) {
            abonoFactura = foliosConAbono.has(claveFolio) ? 0 : (Number(facturaOp.abono) || 0);
            foliosConAbono.add(claveFolio);
            montoCfdi = abonoFactura;
          } else {
            facturaOp = null;
          }

          // Nota de cargo: los conceptos y el GPC de la operación se prorratean según lo abonado
          let notaOp = this.documentoDeFolio(this.form.notas_cargo, folioOperacion);
          let multiplicadorGpc = null;

          if (notaOp && !this.esTransportactics && !this.esIntshipperts) {
            multiplicadorGpc = this.multiplicadorNota(notaOp);
            montoGpc = this.redondear2(montoGpc * multiplicadorGpc);
          } else {
            notaOp = null;
          }

          const conceptoOp = (llave) => {
            const valor = op[llave];
            if (multiplicadorGpc === null || !llavesGpc.includes(llave) || valor === undefined || valor === null || valor === '') {
              return valor;
            }
            return this.redondear2(Number(valor) * multiplicadorGpc);
          };

          let pivoteType = opType;
          if (pivoteType === 'GENERICO') {
            if (this.esTransportactics) {
              pivoteType = 'App\\Models\\OperacionTransportactics';
            } else if (this.esIntshipperts) {
              pivoteType = 'App\\Models\\OperacionIntshipperts';
            }
          }

          return {
            id: (opId && !isNaN(opId)) ? Number(opId) : null,
            type: pivoteType,
            folio: textoOriginal,
            referencia: textoOriginal,
            monto_cfdi: montoCfdi,
            monto_gpc: montoGpc,

            // Pagos parciales (factura de honorarios y nota de cargo)
            folio_factura: (facturaOp || notaOp) ? claveFolio : (op.folio_factura || null),
            honorarios_facturado: facturaOp ? (Number(facturaOp.total_facturado) || 0) : (Number(op.honorarios_facturado) || 0),
            gpc_facturado: notaOp ? (Number(notaOp.total_facturado) || 0) : (Number(op.gpc_facturado) || 0),
            
            // Conceptos Desglosados
            honorarios: abonoFactura !== null ? abonoFactura : op.honorarios,
            impuestos: conceptoOp('impuestos'),
            eci: conceptoOp('eci'),
            maniobras: conceptoOp('maniobras'),
            flete: conceptoOp('flete'),
            muestras: conceptoOp('muestras'),
            llc: conceptoOp('llc'),
            llc_usd: op.llc_usd,
            anticipo: conceptoOp('anticipo'),
            garantias: conceptoOp('garantias'),
            desglose_naviera: conceptoOp('desglose_naviera'),
            pago_proveedor: op.pago_proveedor,
            ganancia: op.ganancia,

            // Proveedores y Facturas
            proveedor_maniobras: op.proveedor_maniobras,
            factura_maniobras: op.factura_maniobras,
            detalle_maniobras: op.detalle_maniobras || null,
            proveedor_flete: op.proveedor_flete,
            factura_flete: op.factura_flete,
            proveedor_muestras: op.proveedor_muestras,
            factura_muestras: op.factura_muestras,
            proveedor_llc: op.proveedor_llc,
            factura_llc: op.factura_llc
          };
        });
      } else {
        payload.operaciones = [];
      }

      delete payload.pedimento_detectado;
      delete payload.referenciasObj;
      delete payload.facturas_honorarios;
      delete payload.notas_cargo;
      delete payload.conceptos_gpc_base;

      // 4. Tipo de comprobante
      if (this.tiposComprobanteArray.includes('CFDI') && this.tiposComprobanteArray.includes('Nota Cargo')) {
        payload.tipo_comprobante = 'Ambos';
      } else {
        payload.tipo_comprobante = this.tiposComprobanteArray[0] || 'N/A';
      }

      // 5. Detectar si estamos creando o actualizando
      try {
        let response;
        if (this.form.id) {
          // MODO EDITAR
          response = await axios.put(`/ingresos-conciliados/${this.form.id}`, payload);
        } else {
          // MODO NUEVO
          response = await axios.post(`/ingresos-conciliados`, payload);
        }

        if (response.status === 200 || response.status === 201 || response.data) {
          Swal.fire({
            title: '¡Guardado!',
            text: response.data.message || 'Ingreso registrado correctamente.',
            icon: 'success',
            toast: true,
            position: 'top-end',
            timer: 3000,
            showConfirmButton: false
          });
          this.$emit(this.form.id ? 'ingreso-actualizado' : 'ingreso-guardado');
        }
      } catch (error) {
        console.error("🔍 ERROR CRUDO:", error);
        let mensajeReal = 'Problema al guardar los cambios';

        if (error.response && error.response.data && error.response.data.error) {
          mensajeReal = error.response.data.error;
        } else if (error.response && error.response.data && error.response.data.message) {
          mensajeReal = error.response.data.message;
        }

        Swal.fire('Error', mensajeReal, 'error');
      } finally {
        this.isSubmitting = false;
      }
    }
  }
}
</script>
<style scoped>
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/* Multiselect ocupa solamente el espacio disponible */
:deep(.custom-multiselect) {
    flex: 1 1 0% !important;
    min-width: 0 !important;
    max-width: 100% !important;
}

/* Caja principal */
:deep(.custom-multiselect .multiselect__tags) {
  box-sizing: border-box !important;
  width: 100% !important;
  min-width: 0 !important;
  max-width: 100% !important;
  height: 48px !important;
  min-height: 48px !important;
  max-height: 48px !important; 
  padding: 5px 40px 5px 8px !important;
  border-color: #D1D5DB !important;
  border-radius: 8px 0 0 8px !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  display: flex !important;
  flex-wrap: wrap !important;
  align-items: center !important;
}

/* Personalización del scrollbar interno para las etiquetas */
:deep(.custom-multiselect .multiselect__tags)::-webkit-scrollbar {
  width: 4px;
}
:deep(.custom-multiselect .multiselect__tags)::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

/* Etiqueta seleccionada */
:deep(.custom-multiselect .multiselect__tag) {
    display: inline-flex !important;
    align-items: center !important;

    max-width: calc(100% - 10px) !important;

    font-size: 14px !important;

    background-color: #2A3A4D !important;

    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Texto dentro de la etiqueta */
:deep(.custom-multiselect .multiselect__tag > span) {
    display: block !important;

    min-width: 0 !important;
    max-width: 100% !important;

    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Flecha */
:deep(.custom-multiselect .multiselect__select) {
    width: 35px !important;
    height: 48px !important;
}

/* Input */
:deep(.custom-multiselect .multiselect__input) {
    min-width: 0 !important;
    max-width: 100% !important;

    font-size: 16px !important;
    margin-bottom: 0 !important;
    padding-top: 2px !important;

    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Texto seleccionado */
:deep(.custom-multiselect .multiselect__single) {
    min-width: 0 !important;
    max-width: 100% !important;

    font-size: 16px !important;
    margin-bottom: 0 !important;

    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* IMPORTANTE: permitir que se vea el dropdown */
:deep(.custom-multiselect .multiselect__content-wrapper) {
    position: absolute !important;
    z-index: 9999 !important;

    width: 100% !important;
    max-height: 250px !important;

    overflow-y: auto !important;
    overflow-x: hidden !important;
}

/* Opciones */
:deep(.custom-multiselect .multiselect__option) {
    font-size: 16px !important;
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    line-height: 1.5 !important;
    padding: 12px 16px !important;
}

:deep(.custom-multiselect .multiselect__option--highlight) {
    background-color: #00C09F !important;
}

:deep(.field-input) {
    min-height: 48px !important;
    font-size: 16px !important;
    border-radius: 8px !important;
}
</style>