<template>
  <div v-if="mostrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 md:p-8">

    <div class="bg-white rounded-xl shadow-2xl w-full max-w-7xl overflow-hidden flex flex-col max-h-[calc(100vh-3rem)]">

      <!-- ENCABEZADO -->
      <div class="px-8 py-6 flex justify-between items-center shrink-0" style="background-color: #2A3A4D;">
        <div>
          <h3 class="text-white font-bold text-2xl tracking-wide flex items-center gap-3">
            <svg class="w-7 h-7 shrink-0 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
              </path>
            </svg>
            Complemento de Pago (REP)
          </h3>
          <p class="text-gray-300 text-base mt-1">Genera el archivo TXT para importarlo en CONTPAQi con SyncCOM.</p>
        </div>
        <button type="button" @click="cerrar" class="text-white hover:text-gray-200 transition-colors" title="Cerrar">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- CUERPO -->
      <div class="p-8 overflow-y-auto flex-1 flex flex-col gap-8">

        <!-- Aviso PUE -->
        <div v-if="esPUE"
          class="px-5 py-4 rounded-lg border border-orange-200 bg-orange-50 text-orange-800 text-lg font-semibold">
          Este ingreso es PUE. El complemento de pago solo aplica a facturas PPD.
        </div>

        <!-- DATOS DEL INGRESO -->
        <section class="grid grid-cols-1 md:grid-cols-4 gap-6">
          <div class="md:col-span-2">
            <label class="block text-base font-bold text-gray-500 mb-2 uppercase">Cliente</label>
            <input type="text" :value="ingreso.cliente" disabled
              class="w-full border-gray-300 bg-gray-100 rounded-lg px-5 py-3 text-xl text-gray-700 font-semibold cursor-not-allowed">
          </div>
          <div>
            <label class="block text-base font-bold text-gray-500 mb-2 uppercase">Sucursal</label>
            <input type="text" :value="ingreso.sucursal_origen" disabled
              class="w-full border-gray-300 bg-gray-100 rounded-lg px-5 py-3 text-xl text-gray-700 font-semibold cursor-not-allowed">
          </div>
          <div>
            <label class="block text-base font-bold text-gray-500 mb-2 uppercase">Fecha de pago</label>
            <input type="text" :value="ingreso.fecha || 'N/A'" disabled
              class="w-full border-gray-300 bg-gray-100 rounded-lg px-5 py-3 text-xl text-gray-700 font-semibold cursor-not-allowed">
          </div>
          <div class="md:col-span-2">
            <label class="block text-base font-bold text-gray-500 mb-2 uppercase">Banco receptor</label>
            <input type="text" :value="ingreso.banco_receptor || 'N/A'" disabled
              class="w-full border-gray-300 bg-gray-100 rounded-lg px-5 py-3 text-xl text-gray-700 font-semibold cursor-not-allowed">
          </div>
          <div>
            <label class="block text-base font-bold text-gray-500 mb-2 uppercase">Moneda</label>
            <input type="text" :value="esDolares ? 'USD - Dólar' : 'MXN - Peso mexicano'" disabled
              class="w-full border-gray-300 bg-gray-100 rounded-lg px-5 py-3 text-xl text-gray-700 font-semibold cursor-not-allowed">
          </div>
          <div>
            <label class="block text-base font-bold text-gray-500 mb-2 uppercase">Tipo de cambio</label>
            <input type="text" :value="buscandoTC ? 'Consultando DOF...' : form.tipo_cambio" disabled
              class="w-full border-gray-300 bg-gray-100 rounded-lg px-5 py-3 text-xl text-gray-700 font-semibold cursor-not-allowed">
          </div>
        </section>

        <!-- OPCIONES DEL REP -->
        <section class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
          <div class="md:col-span-3">
            <label class="block text-base font-bold text-gray-700 mb-2 uppercase">Forma de pago</label>
            <multiselect v-model="form.formaPagoObj" :options="formasPago" label="label" track-by="value"
              :searchable="true" :show-labels="false" :allow-empty="false" placeholder="Seleccione..."
              class="custom-multiselect"></multiselect>
          </div>
          <div>
            <label class="block text-base font-bold text-gray-700 mb-2 uppercase">Parcialidad</label>
            <input type="number" min="1" step="1" v-model.number="form.parcialidad"
              class="w-full border border-gray-300 rounded-lg px-5 py-3 text-xl outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
              title="1 si es el primer pago de la factura; 2, 3... si ya tuvo pagos anteriores">
          </div>
        </section>

        <!-- VISTA PREVIA -->
        <section class="border border-gray-200 rounded-xl overflow-hidden">
          <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
            <h4 class="text-xl font-black text-gray-800">Vista previa del REP</h4>
            <button type="button" @click="cargarVistaPrevia" :disabled="cargandoVista"
              class="px-4 py-2 rounded-lg text-base font-bold text-indigo-700 bg-indigo-100 hover:bg-indigo-200 transition-colors disabled:opacity-50">
              {{ cargandoVista ? 'Cargando...' : 'Actualizar' }}
            </button>
          </div>

          <!-- Cargando -->
          <div v-if="cargandoVista" class="py-14 flex flex-col items-center justify-center">
            <svg class="animate-spin h-10 w-10 text-indigo-600 mb-3" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="text-lg font-bold text-gray-500">Leyendo datos del cliente y facturas...</span>
          </div>

          <div v-else class="p-6 flex flex-col gap-6">

            <!-- Error general / datos faltantes -->
            <div v-if="errorVista" class="px-5 py-4 rounded-lg border border-red-200 bg-red-50">
              <p class="text-lg font-bold text-red-700">{{ errorVista }}</p>
              <ul v-if="listaFaltantes.length" class="mt-2 ml-6 list-disc text-base text-red-700">
                <li v-for="(mensaje, i) in listaFaltantes" :key="'falta-' + i">{{ mensaje }}</li>
              </ul>
            </div>

            <!-- Datos del cliente -->
            <div v-if="cliente.rfc" class="grid grid-cols-1 md:grid-cols-4 gap-4 text-base">
              <div class="md:col-span-2">
                <p class="font-bold text-gray-500 uppercase text-sm">Razón social</p>
                <p class="text-lg font-bold text-gray-800">{{ cliente.razon_social }}</p>
              </div>
              <div>
                <p class="font-bold text-gray-500 uppercase text-sm">RFC</p>
                <p class="text-lg font-bold text-gray-800">{{ cliente.rfc }}</p>
              </div>
              <div>
                <p class="font-bold text-gray-500 uppercase text-sm">Código CONTPAQi</p>
                <p class="text-lg font-bold text-gray-800">{{ cliente.cte_codigo || '—' }}</p>
              </div>
              <div v-if="encabezado.lugar_expedicion">
                <p class="font-bold text-gray-500 uppercase text-sm">Lugar de expedición</p>
                <p class="text-lg font-bold text-gray-800">{{ encabezado.lugar_expedicion }}</p>
              </div>
              <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <p class="font-bold text-gray-500 uppercase text-sm">Cuenta ordenante (cliente)</p>
                  <p class="text-lg font-bold" :class="encabezado.cuenta ? 'text-gray-800' : 'text-orange-600'">
                    <template v-if="encabezado.cuenta">
                      {{ encabezado.banco }} {{ encabezado.cuenta }}
                      <span v-if="encabezado.clabe" class="text-gray-500 font-semibold">· CLABE {{ encabezado.clabe
                        }}</span>
                    </template>
                    <template v-else>No identificada</template>
                  </p>
                </div>
                <div>
                  <p class="font-bold text-gray-500 uppercase text-sm">
                    Cuenta del encabezado
                    <span v-if="encabezado.beneficiaria_origen" class="normal-case font-semibold text-gray-400">({{
                      encabezado.beneficiaria_origen }})</span>
                  </p>
                  <p class="text-lg font-bold"
                    :class="encabezado.beneficiaria_cuenta ? 'text-gray-800' : 'text-orange-600'">
                    <template v-if="encabezado.beneficiaria_cuenta">
                      {{ encabezado.beneficiaria_alias }} {{ encabezado.beneficiaria_cuenta }}
                    </template>
                    <template v-else>Sin cuenta</template>
                  </p>
                </div>
              </div>
            </div>

            <!-- Facturas a saldar (con scroll cuando son muchas) -->
            <div v-if="documentos.length" class="flex items-center justify-between -mb-3">
              <p class="text-base font-bold text-gray-600">
                {{ documentos.length }} {{ documentos.length === 1 ? 'factura' : 'facturas' }} a saldar
              </p>
            </div>
            <div v-if="documentos.length" class="border border-gray-200 rounded-lg overflow-auto tabla-facturas">
              <table class="w-full text-left text-lg">
                <thead class="bg-gray-50 text-gray-700 text-base font-black sticky top-0 z-10 shadow-sm">
                  <tr>
                    <th class="px-4 py-3">Factura</th>
                    <th class="px-4 py-3 text-right">Importe a saldar</th>
                    <th class="px-4 py-3">UUID</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(doc, i) in documentos" :key="'doc-' + i" class="border-t border-gray-100">
                    <td class="px-4 py-3 font-bold text-gray-800">{{ doc.serie }} {{ doc.folio }}</td>
                    <td class="px-4 py-3 text-right font-black text-gray-800">{{ formatearDinero(doc.importe) }}</td>
                    <td class="px-4 py-3 font-mono text-base"
                      :class="doc.uuid ? 'text-gray-600' : 'text-orange-600 font-bold'">
                      {{ doc.uuid || 'XML no encontrado' }}
                    </td>
                  </tr>
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-300 sticky bottom-0 z-10">
                  <tr>
                    <td class="px-4 py-3 font-black text-gray-800">Total a saldar</td>
                    <td class="px-4 py-3 text-right font-black text-gray-900">{{ formatearDinero(totalSaldar) }}</td>
                    <td class="px-4 py-3 text-base font-semibold"
                      :class="Math.abs(diferencia) < 0.01 ? 'text-green-700' : 'text-orange-700'">
                      Depósito: {{ formatearDinero(deposito) }}
                      <span v-if="Math.abs(diferencia) >= 0.01">
                        (diferencia {{ formatearDinero(diferencia) }}: el REP solo ampara facturas CFDI)
                      </span>
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <!-- Advertencias -->
            <div v-if="listaAdvertencias.length" class="px-5 py-4 rounded-lg border border-orange-200 bg-orange-50">
              <p class="text-lg font-bold text-orange-800">Advertencias</p>
              <ul class="mt-2 ml-6 list-disc text-base text-orange-800">
                <li v-for="(mensaje, i) in listaAdvertencias" :key="'adv-' + i">{{ mensaje }}</li>
              </ul>
            </div>
          </div>
        </section>
      </div>

      <!-- PIE -->
      <div
        class="bg-gray-100 px-8 py-5 flex flex-col md:flex-row md:items-center justify-between gap-4 border-t shrink-0">
        <p class="text-base text-gray-500">
          Coloca el archivo descargado en la carpeta de datos recibidos de SyncCOM.
        </p>
        <div class="flex justify-end gap-4">
          <button type="button" @click="cerrar"
            class="px-6 py-4 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 font-bold transition-colors text-xl shadow-sm">
            Cancelar
          </button>
          <button type="button" @click="descargarTxt" :disabled="!puedeDescargar"
            class="px-6 py-4 rounded-lg font-bold transition-colors flex items-center gap-2 text-xl shadow-sm text-white disabled:bg-gray-400 disabled:cursor-not-allowed"
            :class="puedeDescargar ? 'bg-indigo-600 hover:bg-indigo-700' : ''">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
            </svg>
            {{ descargando ? 'Generando...' : 'Descargar TXT' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

export default {
  name: 'ModalComplementoPago',
  components: {
    Multiselect
  },
  props: {
    mostrar: {
      type: Boolean,
      default: false
    },
    ingreso: {
      type: Object,
      default: () => ({})
    }
  },
  data() {
    return {
      buscandoTC: false,
      cargandoVista: false,
      descargando: false,
      errorVista: '',
      faltantes: {},
      advertencias: {},
      documentos: [],
      cliente: {},
      encabezado: {},
      form: {
        tipo_cambio: 1,
        formaPagoObj: { value: '03', label: '03 - Transferencia electrónica' },
        parcialidad: 1
      },
      formasPago: [
        { value: '01', label: '01 - Efectivo' },
        { value: '02', label: '02 - Cheque nominativo' },
        { value: '03', label: '03 - Transferencia electrónica' },
        { value: '04', label: '04 - Tarjeta de crédito' },
        { value: '05', label: '05 - Monedero electrónico' },
        { value: '06', label: '06 - Dinero electrónico' },
        { value: '08', label: '08 - Vales de despensa' },
        { value: '12', label: '12 - Dación en pago' },
        { value: '13', label: '13 - Pago por subrogación' },
        { value: '14', label: '14 - Pago por consignación' },
        { value: '15', label: '15 - Condonación' },
        { value: '17', label: '17 - Compensación' },
        { value: '23', label: '23 - Novación' },
        { value: '24', label: '24 - Confusión' },
        { value: '25', label: '25 - Remisión de deuda' },
        { value: '26', label: '26 - Prescripción o caducidad' },
        { value: '27', label: '27 - A satisfacción del acreedor' },
        { value: '28', label: '28 - Tarjeta de débito' },
        { value: '29', label: '29 - Tarjeta de servicios' },
        { value: '30', label: '30 - Aplicación de anticipos' },
        { value: '31', label: '31 - Intermediario pagos' }
      ]
    };
  },
  computed: {
    esDolares() {
      const banco = String(this.ingreso.banco_receptor || '').toUpperCase();
      return banco.includes('DLLS') || banco.includes('USD') || banco.includes('DOLARES') || banco.includes('DLL');
    },
    esPUE() {
      return String(this.ingreso.metodo_pago || '').toUpperCase() === 'PUE';
    },
    listaFaltantes() {
      return Object.values(this.faltantes || {});
    },
    listaAdvertencias() {
      return Object.values(this.advertencias || {});
    },
    totalSaldar() {
      return this.documentos.reduce((acc, d) => acc + (Number(d.importe) || 0), 0);
    },
    deposito() {
      return Number(this.ingreso.monto_deposito) || 0;
    },
    diferencia() {
      return Math.round((this.deposito - this.totalSaldar) * 100) / 100;
    },
    puedeDescargar() {
      return !this.esPUE
        && !this.cargandoVista
        && !this.descargando
        && !this.buscandoTC
        && !this.errorVista
        && this.documentos.length > 0;
    }
  },
  watch: {
    mostrar: {
      immediate: true,
      handler(visible) {
        if (visible) {
          this.$nextTick(() => this.inicializarModal());
        }
      }
    }
  },
  methods: {
    async inicializarModal() {
      if (!this.ingreso || !this.ingreso.id) {
        return;
      }

      this.errorVista = '';
      this.faltantes = {};
      this.advertencias = {};
      this.documentos = [];
      this.cliente = {};
      this.encabezado = {};
      this.form.parcialidad = 1;
      this.form.formaPagoObj = this.formasPago.find(f => f.value === '03');
      this.form.tipo_cambio = 1;

      if (this.esPUE) {
        return;
      }

      if (this.esDolares) {
        await this.consultarTipoCambio();
      }

      await this.cargarVistaPrevia();
    },

    async consultarTipoCambio() {
      this.buscandoTC = true;
      try {
        const response = await axios.get('/ingresos-conciliados/tipo-cambio', {
          params: { banco: this.ingreso.banco_receptor, fecha: this.ingreso.fecha }
        });
        if (response.data && response.data.tipo_cambio) {
          this.form.tipo_cambio = Number(response.data.tipo_cambio).toFixed(4);
        }
      } catch (error) {
        console.error('No se pudo consultar el tipo de cambio', error);
      } finally {
        this.buscandoTC = false;
      }
    },

    parametros() {
      return {
        forma_pago: this.form.formaPagoObj ? this.form.formaPagoObj.value : '03',
        tipo_cambio: this.form.tipo_cambio,
        parcialidad: Math.max(1, parseInt(this.form.parcialidad, 10) || 1),
        timbrar: 1, // [encCFDI]SI: el REP se timbra al importarlo
        ansi: 1
      };
    },

    async cargarVistaPrevia() {
      this.cargandoVista = true;
      this.errorVista = '';
      this.faltantes = {};

      try {
        const response = await axios.get(`/ingresos-conciliados/${this.ingreso.id}/txt-rep`, {
          params: { ...this.parametros(), vista: 1 }
        });
        this.documentos = response.data.documentos || [];
        this.advertencias = response.data.advertencias || {};
        this.cliente = response.data.cliente || {};
        this.encabezado = response.data.encabezado || {};
      } catch (error) {
        const datos = error.response ? error.response.data : null;
        this.errorVista = (datos && datos.error) ? datos.error : 'No se pudo preparar la vista previa del REP.';
        this.faltantes = (datos && datos.faltantes) ? datos.faltantes : {};
        this.advertencias = (datos && datos.advertencias) ? datos.advertencias : {};
      } finally {
        this.cargandoVista = false;
      }
    },

    async descargarTxt() {
      if (!this.puedeDescargar) {
        return;
      }

      this.descargando = true;
      try {
        const archivo = await axios.get(`/ingresos-conciliados/${this.ingreso.id}/txt-rep`, {
          params: this.parametros(),
          responseType: 'blob'
        });

        const disposicion = archivo.headers['content-disposition'] || '';
        const coincidencia = disposicion.match(/filename="?([^"]+)"?/);
        const nombreArchivo = coincidencia ? coincidencia[1] : `REP_${this.ingreso.id}.txt`;

        const url = window.URL.createObjectURL(archivo.data);
        const enlace = document.createElement('a');
        enlace.href = url;
        enlace.download = nombreArchivo;
        document.body.appendChild(enlace);
        enlace.click();
        document.body.removeChild(enlace);
        window.URL.revokeObjectURL(url);

        Swal.fire({
          title: 'REP generado',
          text: `Se descargó ${nombreArchivo}.`,
          icon: 'success',
          toast: true,
          position: 'top-end',
          timer: 3500,
          showConfirmButton: false
        });

        this.$emit('generado', nombreArchivo);
        this.cerrar();
      } catch (error) {
        // Con responseType 'blob' el error llega como Blob
        let datos = error.response ? error.response.data : null;
        if (datos instanceof Blob) {
          try {
            datos = JSON.parse(await datos.text());
          } catch (e) {
            datos = null;
          }
        }
        this.errorVista = (datos && datos.error) ? datos.error : 'No se pudo generar el TXT.';
        this.faltantes = (datos && datos.faltantes) ? datos.faltantes : {};
      } finally {
        this.descargando = false;
      }
    },

    formatearDinero(monto) {
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2
      }).format(parseFloat(monto) || 0);
    },

    cerrar() {
      this.$emit('cerrar');
    }
  }
};
</script>

<style scoped>
:deep(.custom-multiselect .multiselect__tags) {
  border-color: #D1D5DB;
  border-radius: 8px;
  padding-top: 14px !important;
  padding-left: 16px !important;
  min-height: 54px !important;
  font-size: 18px !important;
}

:deep(.custom-multiselect .multiselect__select) {
  height: 54px !important;
}

:deep(.custom-multiselect.multiselect--active .multiselect__tags) {
  border-color: #4F46E5;
  box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.2);
}

:deep(.custom-multiselect .multiselect__single),
:deep(.custom-multiselect .multiselect__input) {
  font-size: 18px !important;
  margin-bottom: 0 !important;
}

:deep(.custom-multiselect .multiselect__option) {
  font-size: 17px !important;
  padding: 12px 16px !important;
}

/* Lista de facturas: altura máxima con scroll; el encabezado y el total quedan fijos */
.tabla-facturas {
  max-height: 22rem;
}

.tabla-facturas thead th,
.tabla-facturas tfoot td {
  background-color: #F9FAFB;
}

input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
</style>