<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-6 md:p-10">

        <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl flex flex-col overflow-hidden"
            style="max-height: 90vh;">

            <div class="px-8 py-5 flex-none flex justify-between items-center z-10" style="background-color: #2A3A4D;">
                <h3 class="text-white text-2xl font-bold uppercase tracking-wider flex items-center gap-3">
                    <svg class="w-7 h-7 shrink-0 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
                        </path>
                    </svg>
                    EDITAR INGRESO CONCILIADO
                </h3>
                <button @click="$emit('close')" class="text-blue-200 hover:text-white transition-colors">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

  

            <div class="p-8 overflow-y-auto flex-1 grid grid-cols-2 gap-8" style="min-height: 0;">

        <!-- Igual al modal de nuevo ingreso -->
        <div class="col-span-1">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">SUCURSAL ORIGEN *</label>
          <multiselect v-model="form.sucursal_origen" :options="opcionesSucursal" placeholder="Seleccione..."
            :show-labels="false" class="text-xl">
          </multiselect>

          <div v-if="sucursalSeleccionada" class="mt-4 flex items-center gap-6">
            <label v-if="esSucursalOtraBase" class="inline-flex items-center cursor-pointer bg-blue-100 px-4 py-3 rounded-lg border border-blue-200 hover:bg-blue-200 transition-colors shadow-sm">
              <input v-model="checkTransportactics" type="checkbox" class="form-checkbox h-6 w-6 text-blue-600 rounded cursor-pointer">
              <span class="ml-3 text-base text-blue-800 font-extrabold uppercase">Es Transportactics</span>
            </label>
            <label v-if="esSucursalManzanilloBase" class="inline-flex items-center cursor-pointer bg-purple-100 px-4 py-3 rounded-lg border border-purple-200 hover:bg-purple-200 transition-colors shadow-sm">
              <input v-model="checkIntshipperts" type="checkbox" class="form-checkbox h-6 w-6 text-purple-600 rounded cursor-pointer">
              <span class="ml-3 text-base text-purple-800 font-extrabold uppercase">Es Intshipperts</span>
            </label>
          </div>
        </div>

        <div class="col-span-1">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">BANCO RECEPTOR</label>
          <multiselect v-model="form.banco_receptor" :options="opcionesBanco" placeholder="Seleccione..."
            :show-labels="false" class="text-xl"></multiselect>
        </div>

        <div class="col-span-2">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">FECHA DE DEPÓSITO *</label>
          <VueCtkDateTimePicker v-model="form.fecha" format="YYYY-MM-DD" formatted="YYYY-MM-DD" color="#1d4ed8" button-color="#1d4ed8" :only-date="true" label="Seleccione la fecha" class="text-xl"></VueCtkDateTimePicker>
        </div>

        <div class="col-span-2">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">RAZÓN SOCIAL CLIENTE (Filtra los folios) *</label>
          <multiselect v-model="form.cliente" :options="clientesLocales" track-by="id" label="nombre" placeholder="Seleccione o escriba un nuevo cliente y presione Enter..." :show-labels="false" :taggable="true" @tag="agregarClienteManual" class="text-xl">
            <template slot="noResult">Presione Enter para agregar como nuevo cliente</template>
          </multiselect>
        </div>

                <div class="col-span-1">
                    <label class="block text-base font-bold text-gray-500 uppercase mb-2">PEDIMENTOS / FOLIOS
                        DISPONIBLES *</label>
                    <div class="flex w-full min-w-0">
                        <multiselect v-model="form.referenciasObj" :options="opcionesReferenciasCombinadas"
                            :multiple="true" :taggable="true" @tag="agregarReferenciaManual" track-by="label"
                            label="label" :loading="cargandoSheet" :disabled="!form.sucursal_origen"
                            placeholder="Seleccione folios o escriba..." select-label="Presiona Enter para seleccionar"
                            tag-placeholder="Presiona Enter para agregar este texto libre"
                            class="w-full min-w-0 flex-1 text-xl custom-multiselect">
                            <template slot="noResult">No se encontraron folios ni referencias para este
                                cliente</template>
                        </multiselect>

                        <button @click="buscarYRecalcularPedimento" type="button"
                            title="Calcular montos desde XML/Sheet" :disabled="cargandoSheet"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-4 rounded-r transition-colors flex items-center justify-center -ml-1 z-10 shrink-0 disabled:opacity-50">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </div>
                    <span v-if="!form.sucursal_origen" class="text-sm text-red-500 font-bold mt-2 inline-block">⚠️
                        Selecciona una sucursal para cargar la lista.</span>
                </div>

        <div class="col-span-1">
          <label class="block text-base font-bold text-gray-500 uppercase mb-2">MONTO DEPÓSITO ($) *</label>
            <input v-model="form.monto_deposito" @input="alCambiarDeposito" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-5 py-4 text-3xl font-black text-purple-700 focus:outline-none focus:border-gray-400">
        </div>

        <div class="col-span-2 flex items-center gap-8 mt-2 p-5 bg-gray-100 border border-gray-200 rounded-xl">
          <label class="text-lg font-extrabold text-gray-500 uppercase tracking-wide">TIPO DE COMPROBANTE:</label>
          <div class="flex gap-8">
            <label class="inline-flex items-center cursor-pointer">
              <input v-model="tiposComprobanteArray" type="checkbox" value="CFDI" class="form-checkbox h-6 w-6 focus:ring-teal-500 cursor-pointer rounded" style="color: #00C09F;">
              <span class="ml-3 text-xl text-gray-700 font-bold">CFDI (Factura)</span>
            </label>
            <label class="inline-flex items-center cursor-pointer">
              <input v-model="tiposComprobanteArray" type="checkbox" value="Nota Cargo" class="form-checkbox h-6 w-6 focus:ring-teal-500 cursor-pointer rounded" style="color: #00C09F;">
              <span class="ml-3 text-xl text-gray-700 font-bold">Nota Cargo</span>
            </label>
          </div>
        </div>

        <div class="col-span-2 mt-4 border rounded-xl p-6 bg-gray-100" style="border-color: #2A3A4D;">
          <h4 class="text-lg font-extrabold uppercase tracking-wide mb-6" style="color: #2A3A4D;">DESGLOSE:</h4>

          <div class="grid grid-cols-3 gap-6" v-if="esTransportactics">
            <div><label class="block text-base text-gray-500 mb-2">Flete (XML):</label><input v-model="form.flete" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Pago Proveedor:</label><input v-model="form.pago_proveedor" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-emerald-600 mb-2 font-bold">Ganancia:</label><input :value="(parseFloat(form.flete || 0) - parseFloat(form.pago_proveedor || 0)).toFixed(2)" readonly type="number" class="w-full border border-emerald-200 rounded-lg px-4 py-3 text-xl bg-emerald-100 text-emerald-700 font-bold focus:outline-none cursor-not-allowed"></div>
          </div>

          <div class="grid grid-cols-2 gap-6" v-else-if="esIntshipperts">
            <div><label class="block text-base text-gray-500 mb-2">Anticipo:</label><input v-model="form.anticipo" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">ALMAN / Flete:</label><input v-model="form.flete" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
          </div>

          <div class="grid grid-cols-4 gap-6" v-else-if="esManzanillo">
            <div><label class="block text-base text-gray-500 mb-2">Anticipo:</label><input v-model="form.anticipo" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Garantías:</label><input v-model="form.garantias" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Desglose Naviera:</label><input v-model="form.desglose_naviera" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Impuestos:</label><input v-model="form.impuestos" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">ALMAN / Flete:</label><input v-model="form.flete" type="number" step="0.01" placeholder="0.00" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Honorarios:</label><input v-model="form.honorarios" type="number" step="0.01" placeholder="0.00" :readonly="tieneFacturasHonorarios" :title="tieneFacturasHonorarios ? 'Suma de los abonos a las facturas de honorarios' : ''" :class="tieneFacturasHonorarios ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
          </div>

          <div class="grid grid-cols-4 gap-6" v-else>
            <div><label class="block text-base text-gray-500 mb-2">Honorarios:</label><input v-model="form.honorarios" type="number" step="0.01" placeholder="0.00" :readonly="tieneFacturasHonorarios" :title="tieneFacturasHonorarios ? 'Suma de los abonos a las facturas de honorarios' : ''" :class="tieneFacturasHonorarios ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Impuestos:</label><input v-model="form.impuestos" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">ECI:</label><input v-model="form.eci" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Maniobras:</label><input v-model="form.maniobras" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Flete:</label><input v-model="form.flete" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">Muestras:</label><input v-model="form.muestras" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
            <div><label class="block text-base text-gray-500 mb-2">LLC:</label><input v-model="form.llc" type="number" step="0.01" placeholder="0.00" :readonly="tieneNotasCargo" :title="tituloConceptoNota" :class="tieneNotasCargo ? 'bg-gray-100 cursor-not-allowed' : ''" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-xl focus:outline-none focus:border-gray-800"></div>
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
                    <th class="text-right py-2 px-3">Pagado en otros ingresos</th>
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

          <div class="col-span-full mt-6 p-5 bg-white border border-gray-200 rounded-xl flex flex-wrap items-center justify-between shadow-sm">
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

      <div class="px-8 py-6 border-t border-gray-200 flex justify-end gap-6 bg-gray-100 shrink-0">
        <button @click="$emit('close')" class="px-8 py-4 rounded-lg bg-gray-200 text-gray-700 text-xl font-bold hover:bg-gray-300 transition-colors shadow-sm">Cancelar</button>
        <button @click="guardarIngreso" :disabled="isSubmitting" :class="['px-8 py-4 rounded-lg text-white text-xl font-bold transition-colors shadow-sm', isSubmitting ? 'bg-gray-400 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700']">
          {{ isSubmitting ? 'Guardando...' : 'Guardar Cambios' }}
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
    name: 'ModalEditarIngreso',
    components: { Multiselect, VueCtkDateTimePicker },
    props: {
        ingresoBase: { type: Object, required: true },
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
            tiposComprobanteArray: [],
            checkTransportactics: false,
            checkIntshipperts: false,
            form: {
                sucursal_origen: null,
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
                anticipo: null,
                garantias: null,
                desglose_naviera: null,
                proveedor_maniobras: null,
                factura_maniobras: null,
                detalle_maniobras: [],
                proveedor_flete: null,
                factura_flete: null,
                proveedor_muestras: null,
                factura_muestras: null,
                proveedor_llc: null,
                factura_llc: null,
                // Pagos parciales (null = aún no se cargan desde el servidor)
                facturas_honorarios: null,
                notas_cargo: null,
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
        sucursalReal() {
            let s = String(this.sucursalSeleccionada).toUpperCase();

            if (this.checkTransportactics && !s.includes('TRANSPORTACTIC')) {
                s = s.replace(' IMPO', '').replace(' EXPO', '').trim() + ' TRANSPORTACTICS';
            } else if (this.checkIntshipperts && !s.includes('INTSHIPPERT')) {
                s = s.replace(' IMPO', '').replace(' EXPO', '').trim() + ' INTSHIPPERTS';
            }

            return s;
        },
        esIntshipperts() {
            const sucursalUpper = String(this.sucursalSeleccionada).toUpperCase();
            return sucursalUpper.includes('INTSHIPPERT') || this.checkIntshipperts;
        },
        esTransportactics() {
            const sucursalUpper = String(this.sucursalSeleccionada).toUpperCase();
            return sucursalUpper.includes('TRANSPORTACTIC') || this.checkTransportactics;
        },
        esManzanillo() {
            const sucursalUpper = String(this.sucursalSeleccionada).toUpperCase();
            return sucursalUpper.includes('MANZANILLO') && !this.esIntshipperts;
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
                    alCambiar: (limitar) => this.recalcularGpcDesdeNotas(limitar)
                });
            }
            if (this.tieneFacturasHonorarios) {
                secciones.push({
                    llave: 'fh',
                    titulo: 'Factura de honorarios (CFDI)',
                    documentos: this.form.facturas_honorarios,
                    alCambiar: (limitar) => this.recalcularHonorariosDesdeFacturas(limitar)
                });
            }
            return secciones;
        },

        opcionesPedimentos() {
            let sheetArray = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];
            let filtrados = [];

            if (!this.form.cliente) {
                filtrados = sheetArray;
            } else {
                const nombreC = typeof this.form.cliente === 'object' && this.form.cliente !== null ? this.form.cliente.nombre : this.form.cliente;
                const clienteSeleccionado = String(nombreC).toUpperCase().trim();

                filtrados = sheetArray.filter(p => {
                    if (!p.cliente) return true;
                    const clienteP = String(p.cliente).toUpperCase().trim();
                    return clienteP.includes(clienteSeleccionado) || clienteSeleccionado.includes(clienteP);
                });
            }

            const seleccionados = this.form.referenciasObj || [];
            const combinados = [...seleccionados, ...filtrados];

            const mapaUnicos = new Map();
            combinados.forEach(item => {
                if (item) {
                    let textoLabel = item.label || item.folio || item.pedimento || String(item);
                    let newItem = typeof item === 'string'
                        ? { label: textoLabel, folio: textoLabel, pedimento: textoLabel }
                        : { ...item, label: textoLabel };

                    if (textoLabel && !mapaUnicos.has(textoLabel)) {
                        mapaUnicos.set(textoLabel, newItem);
                    }
                }
            });

            return Array.from(mapaUnicos.values());
        },

        totalHonorarios() {
            return parseFloat(this.form.honorarios) || 0;
        },

        totalGPC() {
            if (this.esTransportactics || this.esIntshipperts) {
                return 0;
            }
            if (this.esManzanillo) {
                return (parseFloat(this.form.impuestos) || 0) +
                    (parseFloat(this.form.flete) || 0) +
                    (parseFloat(this.form.anticipo) || 0) +
                    (parseFloat(this.form.garantias) || 0) +
                    (parseFloat(this.form.desglose_naviera) || 0);
            }
            return (parseFloat(this.form.impuestos) || 0) +
                (parseFloat(this.form.eci) || 0) +
                (parseFloat(this.form.maniobras) || 0) +
                (parseFloat(this.form.flete) || 0) +
                (parseFloat(this.form.muestras) || 0) +
                (parseFloat(this.form.llc) || 0);
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
                if (newVal && newVal.length > 0 && this.form.cliente && typeof this.form.cliente === 'string') {
                    const clienteUpper = this.form.cliente.toUpperCase().trim();
                    const encontrado = newVal.find(c => String(c.nombre).toUpperCase().trim() === clienteUpper);
                    if (encontrado) {
                        this.form.cliente = encontrado;
                    }
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
        // 🎯 Mantiene los honorarios actualizados en tiempo real si el usuario teclea en las casillas
        'form.flete': function(newVal) {
            if (this.esTransportactics) {
                this.form.honorarios = Number(newVal) || 0;
            } else if (this.esIntshipperts) {
                this.form.honorarios = (Number(newVal) || 0) + (Number(this.form.anticipo) || 0);
            }
        },
        'form.anticipo': function(newVal) {
            if (this.esIntshipperts) {
                this.form.honorarios = (Number(this.form.flete) || 0) + (Number(newVal) || 0);
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
    mounted() {
        this.form = { ...this.form, ...this.ingresoBase, facturas_honorarios: null, notas_cargo: null, conceptos_gpc_base: null };

        const sucursalBaseStr = String(this.form.sucursal_origen || '').toUpperCase();
        if (sucursalBaseStr.includes('TRANSPORTACTIC')) {
            this.checkTransportactics = true;
        } else if (sucursalBaseStr.includes('INTSHIPPERT')) {
            this.checkIntshipperts = true;
        }

        // 🎯 Llenar casillas al abrir el modal (garantiza el color verde en Total Honorarios)
        if (this.checkTransportactics) {
            this.form.flete = Number(this.ingresoBase.flete) || 0;
            this.form.honorarios = this.form.flete; 
        } else if (this.checkIntshipperts) {
            this.form.flete = Number(this.ingresoBase.flete) || 0;
            this.form.anticipo = Number(this.ingresoBase.anticipo) || 0;
            this.form.honorarios = this.form.flete + this.form.anticipo;
        }

        if (this.form.cliente) {
            const nombreClienteStr = typeof this.form.cliente === 'object' ? this.form.cliente.nombre : this.form.cliente;
            const clienteUpper = String(nombreClienteStr).toUpperCase().trim();

            const clienteEncontrado = this.opcionesCliente.find(c => String(c.nombre).toUpperCase().trim() === clienteUpper);

            if (clienteEncontrado) {
                this.form.cliente = clienteEncontrado;
            } else if (typeof this.form.cliente === 'string') {
                this.form.cliente = { id: this.form.cliente_id || null, nombre: this.form.cliente };
            }
        }

        const clienteActual = typeof this.form.cliente === 'object' && this.form.cliente !== null
            ? String(this.form.cliente.nombre).toUpperCase()
            : '';

        if (this.form.referencia && String(this.form.referencia).trim() !== '') {
            this.form.referenciasObj = String(this.form.referencia).split(',').map(r => {
                let val = r.trim();
                return { folio: val, pedimento: val, label: val, cliente: clienteActual };
            }).filter(r => r.folio !== '');
        } else if (Array.isArray(this.form.operaciones) && this.form.operaciones.length > 0) {
            // Fallback: Si 'referencia' en la tabla principal estaba vacío, leemos desde la pivote 'operaciones'
            this.form.referenciasObj = this.form.operaciones.map(op => {
                let val = String(op.referencia || op.folio_asignado || op.folio || '').trim();
                return { folio: val, pedimento: val, label: val, cliente: clienteActual };
            }).filter(r => r.folio !== '');
        } else {
            this.form.referenciasObj = [];
        }

        if (this.form.tipo_comprobante === 'Ambos') {
            this.tiposComprobanteArray = ['CFDI', 'Nota Cargo'];
        } else if (this.form.tipo_comprobante === 'CFDI' || this.form.tipo_comprobante === 'Nota Cargo') {
            this.tiposComprobanteArray = [this.form.tipo_comprobante];
        } else {
            this.tiposComprobanteArray = [];
        }

        if (this.form.sucursal_origen) {
            this.cargarListaPedimentos();
        }

        // Facturas de honorarios y notas de cargo que abona este ingreso (pagos parciales)
        this.cargarFacturasHonorarios();
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
        formatearDinero(monto) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 }).format(parseFloat(monto) || 0);
        },

        // ==========================================
        // PAGOS PARCIALES: FACTURAS DE HONORARIOS Y NOTAS DE CARGO
        // ==========================================
        async cargarFacturasHonorarios() {
            if (!this.form.id) {
                return;
            }
            try {
                const response = await axios.get(`/ingresos-conciliados/${this.form.id}/facturas-honorarios`);
                const data = response.data || {};
                const facturas = Array.isArray(data) ? data : (data.facturas || []);
                const notas = Array.isArray(data) ? [] : (data.notas || []);

                this.$set(this.form, 'facturas_honorarios', facturas);

                // factor_inicial: qué parte de la nota ya está reflejada en los montos guardados de este ingreso
                this.$set(this.form, 'notas_cargo', notas.map(n => {
                    const total = Number(n.total_facturado) || 0;
                    return { ...n, factor_inicial: total > 0 ? (Number(n.abono) || 0) / total : 0 };
                }));

                this.capturarBaseGpcDesdeGuardado();
            } catch (error) {
                console.error('No se pudieron cargar las facturas de honorarios y notas de cargo', error);
                this.$set(this.form, 'facturas_honorarios', null);
                this.$set(this.form, 'notas_cargo', null);
                this.form.conceptos_gpc_base = null;
            }
        },

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

        // Conceptos de la nota tal como vienen de una búsqueda nueva (montos completos)
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

        // Al abrir sin buscar: reconstruye los montos completos a partir de lo guardado (ya prorrateado)
        capturarBaseGpcDesdeGuardado() {
            if (!this.tieneNotasCargo) {
                this.form.conceptos_gpc_base = null;
                return;
            }
            const notas = this.form.notas_cargo;
            const totalFacturado = notas.reduce((acc, n) => acc + (Number(n.total_facturado) || 0), 0);
            const totalAbono = notas.reduce((acc, n) => acc + (Number(n.abono) || 0), 0);
            const factorGuardado = totalFacturado > 0 ? totalAbono / totalFacturado : 0;

            const base = {};
            this.conceptosNotaCargo.forEach(k => {
                const valor = Number(this.form[k]) || 0;
                base[k] = factorGuardado > 0 ? this.redondear2(valor / factorGuardado) : valor;
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

        // Cuánto se multiplican los conceptos de una operación según el abono a su nota de cargo.
        // Si la nota se cargó de lo guardado, los montos de la operación ya traen el abono anterior.
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
                        lineas.push(`<b>La factura ${d.folio}:</b> ya está liquidada (${this.formatearDinero(d.total_facturado)}).`);
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

        agregarReferencia(newTag) {
            const clienteActual = typeof this.form.cliente === 'object' && this.form.cliente !== null
                ? this.form.cliente.nombre
                : (this.form.cliente || '');

            const nuevoFolio = { label: newTag, folio: newTag, pedimento: newTag, cliente: String(clienteActual).toUpperCase() };
            this.form.referenciasObj.push(nuevoFolio);
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

            // Cliente actual, para conservar la misma estructura que arma mounted()
            const clienteActual = typeof this.form.cliente === 'object' && this.form.cliente !== null
                ? String(this.form.cliente.nombre || '').toUpperCase()
                : String(this.form.cliente || '').toUpperCase();

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

                // Si ya existe como opción (p. ej. viene del Sheet), reutilizamos ese objeto
                const existente = this.opcionesReferenciasCombinadas.find(
                    o => String(o.label).toUpperCase().trim() === clave
                );

                actuales.push(existente || { label: texto, folio: texto, pedimento: texto, cliente: clienteActual });
                yaSeleccionados.add(clave);
            });

            // 4. Asignación única para que el watcher de referenciasObj se dispare una sola vez
            this.form.referenciasObj = actuales;
        },

        async cargarListaPedimentos() {
            if (this.cargandoSheet) {
                return;
            }

            this.cargandoSheet = true;
            try {
                const response = await axios.get('/ingresos-conciliados/listar-pedimentos', {
                    params: {
                        sucursal: this.sucursalSeleccionada,
                        es_transportactics: this.esTransportactics,
                        es_intshipperts: this.esIntshipperts
                    }
                });

                let dataResult = response.data;

                if (dataResult && typeof dataResult === 'object' && !Array.isArray(dataResult)) {
                    dataResult = dataResult.data || dataResult.pedimentos || Object.values(dataResult);
                }

                this.pedimentosSheet = Array.isArray(dataResult) ? dataResult : [];

            } catch (error) {
                console.error("No se pudo cargar la lista", error);
                this.pedimentosSheet = [];
            } finally {
                this.cargandoSheet = false;
            }
        },

        async buscarYRecalcularPedimento() {
            if (!this.form.sucursal_origen || this.form.referenciasObj.length === 0) {
                return Swal.fire('Atención', 'Selecciona la sucursal y agrega al menos un folio.', 'warning');
            }

            // Lógica de extracción inteligente para la Base de Datos
            let pedimentosLimpios = this.form.referenciasObj.map(ref => {
                if (!ref) return '';

                let texto = '';
                if (typeof ref === 'string') {
                    texto = ref;
                } else if (typeof ref === 'object') {
                    texto = ref.folio || ref.label || ref.value || ref.text || '';
                }

                texto = String(texto).trim().toUpperCase();
                if (!texto || texto === 'UNDEFINED') return '';

                // Búsqueda inteligente de Pedimentos de 7 dígitos
                const matchPedimento = texto.match(/(?:\d{4}\s*-\s*)?(\d{7})/);
                if (matchPedimento) {
                    return matchPedimento[1];
                }

                let partes = texto.split(' - ');
                if (partes.length >= 3) {
                    texto = partes[1].trim();
                } else if (partes.length === 2) {
                    texto = partes[0].trim();
                }

                if (texto.includes('/')) {
                    texto = texto.split('/')[0].trim();
                }

                return texto;
            }).filter(r => r !== '');

            Swal.fire({
                title: 'Calculando...',
                text: 'Buscando folios en el sistema...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const response = await axios.post('/ingresos-conciliados/buscar-sheet', {
                    pedimentos: pedimentosLimpios,
                    sucursal: this.sucursalReal,
                    tipo_comprobante: this.tiposComprobanteArray,
                    // Para que los abonos de este mismo ingreso no cuenten como "pagado antes"
                    ingreso_id: this.form.id || null
                });

                const datos = response.data;

                this.$set(this.form, 'metodo_pago', datos.metodo_pago || 'PUE');

                if (this.esTransportactics) {
                    const serverFlete = Number(datos.flete || datos.honorarios || 0);
                    if (serverFlete > 0) this.$set(this.form, 'flete', serverFlete);
                    
                    this.$set(this.form, 'honorarios', Number(this.form.flete) || 0);
                } else if (this.esIntshipperts) {
                    const serverFlete = Number(datos.flete) || 0;
                    const serverAnticipo = Number(datos.anticipo) || 0;

                    if (serverFlete > 0) this.$set(this.form, 'flete', serverFlete);
                    if (serverAnticipo > 0) this.$set(this.form, 'anticipo', serverAnticipo);
                    
                    this.$set(this.form, 'honorarios', (Number(this.form.flete) || 0) + (Number(this.form.anticipo) || 0));
                } else {
                    let honorariosSum = Number(datos.honorarios || 0);
                    
                    if (datos.operaciones && datos.operaciones.length > 0) {
                        const sumCfdiOps = datos.operaciones.reduce((acc, op) => acc + (Number(op.monto_cfdi) || 0), 0);
                        if (sumCfdiOps > honorariosSum) {
                            honorariosSum = sumCfdiOps;
                        }
                    }

                    this.$set(this.form, 'honorarios', honorariosSum);
                    if (datos.flete !== undefined) this.$set(this.form, 'flete', Number(datos.flete));
                    if (datos.anticipo !== undefined) this.$set(this.form, 'anticipo', Number(datos.anticipo));
                }

                this.$set(this.form, 'impuestos', Number(datos.impuestos) || 0);
                this.$set(this.form, 'eci', Number(datos.eci) || 0);
                this.$set(this.form, 'maniobras', Number(datos.maniobras) || 0);
                this.$set(this.form, 'muestras', Number(datos.muestras) || 0);
                this.$set(this.form, 'llc', Number(datos.llc) || 0);
                this.$set(this.form, 'llc_usd', Number(datos.llc_usd) || 0);
                this.$set(this.form, 'garantias', Number(datos.garantias) || 0);
                this.$set(this.form, 'desglose_naviera', Number(datos.desglose_naviera) || 0);
                this.$set(this.form, 'proveedor_maniobras', datos.proveedor_maniobras || null);
                this.$set(this.form, 'factura_maniobras', datos.factura_maniobras || null);
                this.$set(this.form, 'detalle_maniobras', datos.detalle_maniobras || []);
                this.$set(this.form, 'proveedor_flete', datos.proveedor_flete || null);
                this.$set(this.form, 'factura_flete', datos.factura_flete || null);
                this.$set(this.form, 'proveedor_muestras', datos.proveedor_muestras || null);
                this.$set(this.form, 'factura_muestras', datos.factura_muestras || null);
                this.$set(this.form, 'proveedor_llc', datos.proveedor_llc || null);
                this.$set(this.form, 'factura_llc', datos.factura_llc || null);
                this.$set(this.form, 'folio_sc', datos.folio_sc || null);
                this.$set(this.form, 'operacion_id', datos.operacion_id || null);
                this.$set(this.form, 'operation_type', datos.operation_type || null);
                this.$set(this.form, 'pedimento_detectado', datos.pedimento_detectado || null);
                this.$set(this.form, 'operaciones', datos.operaciones || []);
                this.$set(this.form, 'pago_proveedor', Number(datos.pago_proveedor) || 0);

                // 🎯 PAGOS PARCIALES: notas de cargo (se prorratean los conceptos) y facturas de honorarios
                this.$set(this.form, 'facturas_honorarios', (datos.facturas_honorarios || []).map(f => ({ ...f })));
                this.$set(this.form, 'notas_cargo', (datos.notas_cargo || []).map(n => ({ ...n })));
                this.capturarBaseGpc();
                this.recalcularGpcDesdeNotas();
                this.recalcularHonorariosDesdeFacturas();

                // Recálculo del depósito
                if (this.sumaTotal > 0) {
                    this.$set(this.form, 'monto_deposito', Number(this.sumaTotal.toFixed(2)));
                }

                if (datos.cliente_detectado) {
                    const nombreExcel = datos.cliente_detectado.trim().toUpperCase();
                    const clienteEncontrado = this.opcionesCliente.find(c => c.nombre.trim().toUpperCase() === nombreExcel);
                    if (clienteEncontrado) {
                        this.$set(this.form, 'cliente', clienteEncontrado);
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
                    Swal.fire({
                        title: '¡Datos listos!',
                        text: 'Montos y operaciones actualizados.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
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

            // Extrae únicamente el primer segmento (Folio) eliminando la nomenclatura completa
            const extraerFolioLimpio = (val) => {
                if (!val) return '';
                let texto = typeof val === 'object' ? (val.folio || val.label || val.referencia || val.pedimento || '') : String(val);
                texto = texto.trim();

                // Si viene en formato "FOLIO - PEDIMENTO - CLIENTE", toma solo el FOLIO
                if (texto.includes(' - ')) {
                    texto = texto.split(' - ')[0].trim();
                }

                // Si viene con diagonal "FOLIO/PEDIMENTO"
                if (texto.includes('/')) {
                    texto = texto.split('/')[0].trim();
                }

                return texto.replace(/^F-\s*/i, '').replace(/^TR:\s*/i, '');
            };

            // 2. EXTRACCIÓN LIMPIA DE LA COLUMNA 'referencia'
            payload.referencia = this.form.referenciasObj.map(r => {
                return typeof r === 'object' ? (r.label || r.folio || r.referencia || r.pedimento || '') : String(r);
            }).filter(Boolean).join(', ');

            payload.folio_sc = payload.referencia;
            payload.pedimento_detectado = payload.referencia;

            // 3. CONSTRUCCIÓN DE 'OPERACIONES' PARA LA TABLA PIVOTE (ingreso_operacion)
            if (this.form.referenciasObj && Array.isArray(this.form.referenciasObj) && this.form.referenciasObj.length > 0) {
                const opsBuscadas = Array.isArray(this.form.operaciones) ? this.form.operaciones : [];
                const pedimentosSheet = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];
                const llavesGpc = this.conceptosNotaCargo;

                const totalOps = this.form.referenciasObj.length;

                // Cada factura de honorarios se abona una sola vez aunque cubra varias operaciones
                const foliosConAbono = new Set();

                payload.operaciones = this.form.referenciasObj.map(ref => {
                    const textoOriginal = typeof ref === 'object'
                        ? (ref.folio || ref.label || ref.referencia || ref.pedimento || '')
                        : String(ref);

                    const folioLimpio = extraerFolioLimpio(textoOriginal);
                    const textoUpper = String(textoOriginal).toUpperCase().trim();

                    const esCoincidencia = (o) => {
                        if (!o) {
                            return false;
                        }
                        const oFolioLimpio = extraerFolioLimpio(o.folio || o.referencia || o.pedimento || o.id);
                        const oFolioRaw = String(o.folio || o.referencia || o.label || '').toUpperCase().trim();

                        // Pedimento de la operación (7 dígitos), para cuando la referencia es el pedimento
                        const oPedimento = (String(o.pedimento || '').match(/\d{7}/) || [])[0] || '';

                        return (folioLimpio && oFolioLimpio && (folioLimpio === oFolioLimpio || oFolioLimpio.includes(folioLimpio) || folioLimpio.includes(oFolioLimpio))) ||
                            (oFolioRaw && (oFolioRaw === textoUpper || textoUpper.includes(oFolioRaw) || oFolioRaw.includes(textoUpper))) ||
                            (oPedimento !== '' && textoUpper.includes(oPedimento));
                    };

                    let op = opsBuscadas.find(esCoincidencia) || pedimentosSheet.find(esCoincidencia) || {};

                    // Si la operación viene de la tabla pivote como relación, sus columnas pueden estar en op.pivot
                    const pivote = (op && op.pivot) ? op.pivot : {};
                    const folioFacturaGuardado = op.folio_factura || pivote.folio_factura || null;
                    const honorariosFacturadoGuardado = Number(op.honorarios_facturado ?? pivote.honorarios_facturado ?? 0) || 0;
                    const gpcFacturadoGuardado = Number(op.gpc_facturado ?? pivote.gpc_facturado ?? 0) || 0;
                    const montoGpcOperacion = Number(op.monto_gpc ?? pivote.monto_gpc ?? 0) || 0;

                    const opId = op.operacion_id || op.id || op.pedimento_id || null;
                    const opType = op.operation_type || op.operacion_type || op.type || 'GENERICO';

                    let montoCfdi = Number(op.monto_cfdi ?? op.flete ?? op.monto_flete ?? 0);
                    let montoGpc = Number(op.monto_gpc ?? op.total_gpc ?? op.gpc ?? 0);

                    // Reajuste para evitar registro de GPC en tabla pivote para estas entidades
                    if (this.esTransportactics) {
                        montoCfdi = Number(this.form.flete || 0);
                        montoGpc = 0;
                    } else if (this.esIntshipperts) {
                        montoCfdi = (Number(this.form.flete) || 0) + (Number(this.form.anticipo) || 0);
                        montoGpc = 0;
                    } else {
                        // Si es otro y estaba en 0, toma lo del formulario general
                        if (montoCfdi === 0) {
                            montoCfdi = Number(this.form.flete || this.form.honorarios || 0);
                        }
                        if (montoGpc === 0) {
                            montoGpc = Number(this.totalGPC || 0);
                        }
                    }

                    const folioOperacion = folioFacturaGuardado || op.folio;
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
                        montoGpc = this.redondear2(montoGpcOperacion * multiplicadorGpc);
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

                    // Determinar el modelo dinámico
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
                        folio_factura: (facturaOp || notaOp) ? claveFolio : folioFacturaGuardado,
                        honorarios_facturado: facturaOp ? (Number(facturaOp.total_facturado) || 0) : honorariosFacturadoGuardado,
                        gpc_facturado: notaOp ? (Number(notaOp.total_facturado) || 0) : gpcFacturadoGuardado,

                        honorarios: abonoFactura !== null ? abonoFactura : (op.honorarios ?? (totalOps === 1 ? Number(this.form.honorarios || 0) : 0)),
                        impuestos: conceptoOp('impuestos') ?? (totalOps === 1 ? Number(this.form.impuestos || 0) : 0),
                        eci: conceptoOp('eci') ?? (totalOps === 1 ? Number(this.form.eci || 0) : 0),
                        maniobras: conceptoOp('maniobras') ?? (totalOps === 1 ? Number(this.form.maniobras || 0) : 0),
                        flete: conceptoOp('flete') ?? (totalOps === 1 ? Number(this.form.flete || 0) : 0),
                        muestras: conceptoOp('muestras') ?? (totalOps === 1 ? Number(this.form.muestras || 0) : 0),
                        llc: conceptoOp('llc') ?? (totalOps === 1 ? Number(this.form.llc || 0) : 0),
                        llc_usd: op.llc_usd ?? (totalOps === 1 ? Number(this.form.llc_usd || 0) : 0),
                        anticipo: conceptoOp('anticipo') ?? (totalOps === 1 ? Number(this.form.anticipo || 0) : 0),
                        garantias: conceptoOp('garantias') ?? (totalOps === 1 ? Number(this.form.garantias || 0) : 0),
                        desglose_naviera: conceptoOp('desglose_naviera') ?? (totalOps === 1 ? Number(this.form.desglose_naviera || 0) : 0),
                        pago_proveedor: op.pago_proveedor ?? (totalOps === 1 ? Number(this.form.pago_proveedor || 0) : 0),
                        ganancia: op.ganancia ?? (totalOps === 1 ? Number(this.form.ganancia || 0) : 0),
                        proveedor_maniobras: op.proveedor_maniobras ?? null,
                        factura_maniobras: op.factura_maniobras ?? null,
                        detalle_maniobras: op.detalle_maniobras ?? (totalOps === 1 ? (this.form.detalle_maniobras || null) : null),
                        proveedor_flete: op.proveedor_flete ?? null,
                        factura_flete: op.factura_flete ?? null,
                        proveedor_muestras: op.proveedor_muestras ?? null,
                        factura_muestras: op.factura_muestras ?? null,
                        proveedor_llc: op.proveedor_llc ?? null,
                        factura_llc: op.factura_llc ?? null
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