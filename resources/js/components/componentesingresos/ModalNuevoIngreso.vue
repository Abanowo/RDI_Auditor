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
          <input v-model="form.monto_deposito" type="number" step="0.01" placeholder="0.00"
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
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Garantías:</label><input v-model="form.garantias"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Desglose Naviera:</label><input
                v-model="form.desglose_naviera" type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Impuestos:</label><input v-model="form.impuestos"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">ALMAN / Flete:</label><input v-model="form.flete"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Honorarios:</label><input v-model="form.honorarios"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
          </div>

          <div class="grid grid-cols-4 gap-6" v-else>
            <div><label class="block text-base text-gray-700 mb-2">Honorarios:</label><input v-model="form.honorarios"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Impuestos:</label><input v-model="form.impuestos"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">ECI:</label><input v-model="form.eci" type="number"
                step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Maniobras:</label><input v-model="form.maniobras"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Flete:</label><input v-model="form.flete"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">Muestras:</label><input v-model="form.muestras"
                type="number" step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
            <div><label class="block text-base text-gray-700 mb-2">LLC:</label><input v-model="form.llc" type="number"
                step="0.01" placeholder="0.00"
                class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800">
            </div>
          </div>

          <div
            class="col-span-full mt-6 p-5 bg-white border border-gray-200 rounded-xl flex flex-wrap items-center justify-between shadow-sm">
            <div class="flex items-center gap-4 px-3">
              <span class="text-base text-gray-500 font-bold uppercase">Total GPC:</span>
              <span class="text-2xl font-black text-blue-600">{{ formatearDinero(totalGPC) }}</span>
            </div>
            <div class="hidden md:block w-px h-10 bg-gray-200"></div>
            <div class="flex items-center gap-4 px-3">
              <span class="text-base text-gray-500 font-bold uppercase">Total Honorarios:</span>
              <span class="text-2xl font-black" style="color: #00C09F;">{{ formatearDinero(form.honorarios) }}</span>
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
        proveedor_maniobras: null,
        factura_maniobras: null,
        proveedor_flete: null,
        factura_flete: null,
        proveedor_muestras: null,
        factura_muestras: null,
        proveedor_llc: null,
        factura_llc: null,
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
      const cliente = typeof this.form.cliente === 'object' && this.form.cliente !== null
        ? this.form.cliente.nombre
        : this.form.cliente;
      const clienteUpper = String(cliente || '').toUpperCase();

      return sucursalUpper.includes('INTSHIPPERT') || clienteUpper.includes('INTSHIPPERT') || this.checkIntshipperts;
    },
    esManzanillo() {
      const sucursal = String(this.sucursalSeleccionada).toUpperCase();
      return sucursal.includes('MANZANILLO') && !this.esIntshipperts;
    },
    esTransportactics() {
      const sucursalUpper = String(this.sucursalSeleccionada).toUpperCase();
      const cliente = typeof this.form.cliente === 'object' && this.form.cliente !== null
        ? this.form.cliente.nombre
        : this.form.cliente;
      const clienteUpper = String(cliente || '').toUpperCase();

      return sucursalUpper.includes('TRANSPORTACTIC') || clienteUpper.includes('TRANSPORTACTIC') || this.checkTransportactics;
    },

    opcionesPedimentos() {
      if (!this.form.cliente) {
        return this.pedimentosSheet;
      }
      const clienteSeleccionado = this.form.cliente.nombre.toUpperCase().trim();
      return this.pedimentosSheet.filter(p => {
        if (!p.cliente) return false;
        return p.cliente.includes(clienteSeleccionado) || clienteSeleccionado.includes(p.cliente);
      });
    },
    totalGPC() {
      if (this.esTransportactics) {
        return parseFloat(this.form.flete) || 0;
      }
      if (this.esIntshipperts) {
        return (parseFloat(this.form.anticipo) || 0) + (parseFloat(this.form.flete) || 0);
      }
      if (this.esManzanillo) {
        return (parseFloat(this.form.impuestos) || 0) +
          (parseFloat(this.form.flete) || 0) +
          (parseFloat(this.form.anticipo) || 0) +
          (parseFloat(this.form.garantias) || 0) +
          (parseFloat(this.form.desglose_naviera) || 0);
      }
      return (parseFloat(this.form.impuestos) || 0) + (parseFloat(this.form.eci) || 0) +
        (parseFloat(this.form.maniobras) || 0) + (parseFloat(this.form.flete) || 0) +
        (parseFloat(this.form.muestras) || 0) + (parseFloat(this.form.llc) || 0);
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
      return this.totalGPC + (parseFloat(this.form.honorarios) || 0);
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

      // 4. Garantizar que las referencias ya seleccionadas por el usuario no desaparezcan
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
    agregarClienteManual(nuevoNombre) {
      const nuevoCliente = {
        id: 'nuevo_' + Date.now(),
        nombre: nuevoNombre.toUpperCase().trim()
      };
      this.clientesLocales.push(nuevoCliente);
      this.form.cliente = nuevoCliente;
    },
    agregarReferenciaManual(nuevaReferencia) {
      const texto = String(nuevaReferencia).trim();
      if (!texto) {
        return;
      }

      // 1. Lo agregamos al arreglo base si no existe
      if (!this.opcionesReferenciasPredefinidas.includes(texto)) {
        this.opcionesReferenciasPredefinidas.push(texto);
      }

      // 2. Lo agregamos al formulario formateado como objeto
      if (!this.form.referenciasObj) {
        this.form.referenciasObj = [];
      }

      const nuevoObj = { label: texto, folio: texto };
      this.form.referenciasObj.push(nuevoObj);
    },
    formatearDinero(monto) {
      return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 }).format(parseFloat(monto) || 0);
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

      // Lógica de extracción inteligente para la Base de Datos
      let pedimentosLimpios = this.form.referenciasObj.map(ref => {
        if (!ref) {
          return '';
        }

        // 1. Extraemos el texto crudo según el tipo de dato que contenga ref
        let texto = '';
        if (typeof ref === 'string') {
          texto = ref;
        } else if (typeof ref === 'object') {
          texto = ref.folio || ref.label || ref.value || ref.text || '';
        }

        texto = String(texto).trim();
        if (!texto || texto === 'undefined') {
          return '';
        }

        // 2. Desarmamos el formato "3739 - 6000464 - SURTIDORA"
        let partes = texto.split(' - ');
        
        if (partes.length >= 3) {
          // El pedimento está en la segunda posición (índice 1)
          texto = partes[1].trim(); 
        } else if (partes.length === 2) {
          // El pedimento está en la primera posición (índice 0)
          texto = partes[0].trim();
        }

        // 3. Regla de la diagonal: si viene "6000464/6000495", toma "6000464"
        if (texto.includes('/')) {
          texto = texto.split('/')[0].trim();
        }

        return texto;
      }).filter(r => r !== '' && r !== 'undefined');

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
          tipo_comprobante: this.tiposComprobanteArray
        });

        const datos = response.data;
        this.$set(this.form, 'metodo_pago', datos.metodo_pago || 'PUE');

        this.form.honorarios = datos.honorarios !== undefined ? Number(datos.honorarios) : 0;
        this.form.impuestos = datos.impuestos !== undefined ? Number(datos.impuestos) : 0;
        this.form.eci = datos.eci !== undefined ? Number(datos.eci) : 0;
        this.form.maniobras = datos.maniobras !== undefined ? Number(datos.maniobras) : 0;
        this.form.flete = datos.flete !== undefined ? Number(datos.flete) : 0;
        this.form.muestras = datos.muestras !== undefined ? Number(datos.muestras) : 0;
        this.form.llc = datos.llc !== undefined ? Number(datos.llc) : 0;
        this.form.anticipo = datos.anticipo !== undefined ? Number(datos.anticipo) : 0;
        this.form.garantias = datos.garantias !== undefined ? Number(datos.garantias) : 0;
        this.form.desglose_naviera = datos.desglose_naviera !== undefined ? Number(datos.desglose_naviera) : 0;

        this.form.proveedor_maniobras = datos.proveedor_maniobras || null;
        this.form.factura_maniobras = datos.factura_maniobras || null;
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

        const sumatoriaTotal = this.form.honorarios + this.form.impuestos + this.form.eci +
          this.form.maniobras + this.form.flete + this.form.muestras + this.form.llc +
          this.form.anticipo + this.form.garantias + this.form.desglose_naviera;

        if (sumatoriaTotal > 0) {
          this.form.monto_deposito = Number(sumatoriaTotal.toFixed(2));
        }

        if (datos.cliente_detectado) {
          const nombreExcel = datos.cliente_detectado.trim().toUpperCase();
          const clienteEncontrado = this.opcionesCliente.find(c => c.nombre.trim().toUpperCase() === nombreExcel);
          if (clienteEncontrado) {
            this.form.cliente = clienteEncontrado;
          }
        }

        Swal.fire({ title: '¡Datos listos!', text: 'Montos cargados en el formulario.', icon: 'success', timer: 2000, showConfirmButton: false });
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

      this.isSubmitting = true;
      const payload = { ...this.form };

      // 1. Manejo de Cliente
      if (String(payload.cliente.id).startsWith('nuevo_')) {
        payload.cliente_id = null;
        payload.nuevo_cliente_nombre = payload.cliente.nombre;
      } else {
        payload.cliente_id = payload.cliente.id;
      }
      delete payload.cliente;

      payload.total_gpc = this.totalGPC;
      payload.anticipo = Number(this.form.anticipo || 0); // 🔥 Forzamos envío numérico del anticipo

      // 2. Construcción de string para la columna principal 'referencia'
      if (this.form.referenciasObj && Array.isArray(this.form.referenciasObj)) {
        payload.referencia = this.form.referenciasObj.map(r => r.folio || r.label || r).join(', ');
      } else {
        payload.referencia = '';
      }

      if (payload.pedimento_detectado) {
        payload.referencia = payload.pedimento_detectado;
      }

      // CONSTRUCCIÓN DE 'OPERACIONES' PARA LA TABLA PIVOTE (ingreso_operacion)
      // Mapeamos cada referencia de la lista para enviarla como elemento de la tabla pivote
      if (this.form.referenciasObj && Array.isArray(this.form.referenciasObj) && this.form.referenciasObj.length > 0) {
        
        // Función auxiliar para extraer el número de folio limpio (ej: "68302 - 6001853 - CARNICOS DM" -> "6001853")
        const obtenerFolioLimpio = (val) => {
          if (!val) return '';
          let str = String(val).trim().replace('F-', '');
          let partes = str.split(' - ');
          if (partes.length >= 3) str = partes[1].trim();
          else if (partes.length === 2) str = partes[0].trim();
          if (str.includes('/')) str = str.split('/')[0].trim();
          return str.toUpperCase();
        };

        const opsBuscadas = Array.isArray(this.form.operaciones) ? this.form.operaciones : [];
        const pedimentosSheet = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];

        payload.operaciones = this.form.referenciasObj.map(ref => {
          const textoOriginal = typeof ref === 'object' 
            ? (ref.folio || ref.label || ref.referencia || ref.pedimento || '') 
            : String(ref);
          
          const folioLimpio = obtenerFolioLimpio(textoOriginal);
          const textoUpper = String(textoOriginal).toUpperCase().trim();

          // Buscamos coincidencia en form.operaciones o en pedimentosSheet
          const esCoincidencia = (o) => {
            if (!o) return false;
            const oFolioLimpio = obtenerFolioLimpio(o.folio || o.referencia || o.pedimento || o.id);
            const oFolioRaw = String(o.folio || o.referencia || o.label || '').toUpperCase().trim();
            
            return (folioLimpio && oFolioLimpio && (folioLimpio === oFolioLimpio || oFolioLimpio.includes(folioLimpio) || folioLimpio.includes(oFolioLimpio))) ||
                   (oFolioRaw && (oFolioRaw === textoUpper || textoUpper.includes(oFolioRaw) || oFolioRaw.includes(textoUpper)));
          };

          let op = opsBuscadas.find(esCoincidencia) || pedimentosSheet.find(esCoincidencia);

          // Extraemos ID, TYPE, MONTO_CFDI y MONTO_GPC reales
          const opId = op ? (op.operacion_id || op.id || op.pedimento_id || null) : null;
          const opType = op ? (op.operation_type || op.operacion_type || op.type || 'GENERICO') : 'GENERICO';
          
          // Fallback de montos: lee desde la operación o desde los totales del formulario
          const montoCfdi = op ? Number(op.monto_cfdi ?? op.flete ?? op.monto_flete ?? 0) : Number(this.form.flete || 0);
          const montoGpc = op ? Number(op.monto_gpc ?? op.total_gpc ?? op.gpc ?? 0) : Number(this.totalGPC || 0);

          return {
            id: (opId && !isNaN(opId)) ? Number(opId) : null,
            type: opType !== 'GENERICO' ? opType : (this.esTransportactics ? 'App\\Models\\OperacionTransportactics' : 'GENERICO'),
            folio: textoOriginal,
            referencia: textoOriginal,
            monto_cfdi: montoCfdi,
            monto_gpc: montoGpc
          };
        });
      } else {
        payload.operaciones = [];
      }

      // 4. Formateo de Sucursal
      let sucursalGuardar = typeof payload.sucursal_origen === 'object' && payload.sucursal_origen !== null
        ? (payload.sucursal_origen.nombre || payload.sucursal_origen.id)
        : payload.sucursal_origen;

      if (this.checkTransportactics && !String(sucursalGuardar).toUpperCase().includes('TRANSPORTACTIC')) {
        sucursalGuardar += ' TRANSPORTACTICS';
      } else if (this.checkIntshipperts && !String(sucursalGuardar).toUpperCase().includes('INTSHIPPERT')) {
        sucursalGuardar += ' INTSHIPPERTS';
      }

      payload.sucursal_origen = sucursalGuardar;

      // Limpieza de auxiliares
      delete payload.pedimento_detectado;
      delete payload.referenciasObj;

      // 5. Tipo de comprobante
      if (this.tiposComprobanteArray && this.tiposComprobanteArray.includes('CFDI') && this.tiposComprobanteArray.includes('Nota Cargo')) {
        payload.tipo_comprobante = 'Ambos';
      } else if (this.tiposComprobanteArray) {
        payload.tipo_comprobante = this.tiposComprobanteArray[0] || 'N/A';
      } else {
        payload.tipo_comprobante = 'N/A';
      }

      // 6. Envío al Servidor
      try {
        const response = await axios.post(`/ingresos-conciliados`, payload);

        Swal.fire({
          title: '¡Guardado!',
          text: response.data.message || 'Ingreso registrado correctamente.',
          icon: 'success',
          toast: true,
          position: 'top-end',
          timer: 3000,
          showConfirmButton: false
        });

        this.$emit('ingreso-guardado');

      } catch (error) {
        console.error("🔍 ERROR CRUDO:", error);
        let mensajeReal = 'No se pudo conectar con el servidor.';

        if (error.response && error.response.data) {
          mensajeReal = error.response.data.message || error.response.data.error || mensajeReal;
        } else if (error.message) {
          mensajeReal = "Error de Vue/JS: " + error.message;
        }

        Swal.fire({
          title: 'Atención',
          text: mensajeReal,
          icon: 'warning'
        });
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