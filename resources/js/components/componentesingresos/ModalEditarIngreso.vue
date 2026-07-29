<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 md:p-8">

        <!-- CONTENEDOR PRINCIPAL DEL MODAL -->
        <div
            class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[calc(100vh-4rem)] flex flex-col overflow-hidden">

            <!-- Header Modal -->
            <div class="bg-blue-500 px-4 py-3 flex justify-between items-center shrink-0">
                <h3 class="text-white text-xs font-bold uppercase tracking-wider">EDITAR INGRESO CONCILIADO</h3>
                <button @click="$emit('close')" class="text-gray-400 hover:text-white transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Body Modal -->
            <div class="p-6 overflow-y-auto flex-1 grid grid-cols-2 gap-4">

                <!-- SUCURSAL -->
                <div class="col-span-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">SUCURSAL ORIGEN *</label>
                    <multiselect v-model="form.sucursal_origen" :options="opcionesSucursal" placeholder="Seleccione..."
                        :show-labels="false" class="text-sm">
                    </multiselect>
                </div>

                <!-- BANCO -->
                <div class="col-span-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">BANCO RECEPTOR</label>
                    <multiselect v-model="form.banco_receptor" :options="opcionesBanco" placeholder="Seleccione..."
                        :show-labels="false" class="text-sm">
                    </multiselect>
                </div>

                <!-- FECHA -->
                <div class="col-span-2">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">FECHA DE DEPÓSITO *</label>
                    <VueCtkDateTimePicker v-model="form.fecha" format="YYYY-MM-DD" formatted="YYYY-MM-DD"
                        color="#1d4ed8" button-color="#1d4ed8" :only-date="true" label="Seleccione la fecha"
                        class="text-sm">
                    </VueCtkDateTimePicker>
                </div>

                <!-- CLIENTE -->
                <div class="col-span-2">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">RAZÓN SOCIAL CLIENTE (Filtra
                        los folios) *</label>
                    <multiselect v-model="form.cliente" :options="opcionesCliente" track-by="id" label="nombre"
                        placeholder="Seleccione un cliente..." :show-labels="false" class="text-sm">
                    </multiselect>
                </div>

                <!-- REFERENCIA (MULTISELECT INTELIGENTE) -->
                <div class="col-span-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">PEDIMENTOS / FOLIOS
                        DISPONIBLES</label>
                    <div class="flex">
                        <multiselect v-model="form.referenciasObj" :options="opcionesPedimentos" :multiple="true"
                            :taggable="true" @tag="agregarReferencia" track-by="label" label="label"
                            :loading="cargandoSheet" :disabled="!form.sucursal_origen"
                            placeholder="Seleccione folios o escriba..." class="w-full text-sm">
                            <template slot="noResult">No se encontraron folios para este cliente</template>
                        </multiselect>

                        <button @click="buscarYRecalcularPedimento" type="button" title="Calcular montos desde XML"
                            :disabled="cargandoSheet"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-r transition-colors flex items-center justify-center -ml-1 z-10 disabled:opacity-50">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </div>
                    <span v-if="!form.sucursal_origen" class="text-[9px] text-red-500 font-bold">⚠️ Selecciona una
                        sucursal para cargar la
                        lista.</span>
                </div>

                <!-- MONTO -->
                <div class="col-span-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">MONTO DEPÓSITO ($) *</label>
                    <input v-model="form.monto_deposito" type="number" step="0.01" placeholder="0.00"
                        class="w-full border border-gray-300 rounded px-3 py-2.5 text-sm font-black text-purple-700 focus:outline-none focus:border-[#2A3A4D]">
                </div>

                <!-- TIPO COMPROBANTE -->
                <div class="col-span-2 flex items-center gap-6 mt-1 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                    <label class="text-[11px] font-extrabold text-gray-500 uppercase tracking-wide">
                        TIPO DE COMPROBANTE:
                    </label>
                    <div class="flex gap-4">
                        <label class="inline-flex items-center cursor-pointer">
                            <input v-model="tiposComprobanteArray" type="checkbox" value="CFDI"
                                class="form-checkbox h-4 w-4 text-[#00C09F] focus:ring-[#00C09F] cursor-pointer rounded">
                            <span class="ml-2 text-sm text-gray-700 font-bold">CFDI (Factura)</span>
                        </label>
                        <label class="inline-flex items-center cursor-pointer">
                            <input v-model="tiposComprobanteArray" type="checkbox" value="Nota Cargo"
                                class="form-checkbox h-4 w-4 text-[#00C09F] focus:ring-[#00C09F] cursor-pointer rounded">
                            <span class="ml-2 text-sm text-gray-700 font-bold">Nota Cargo</span>
                        </label>
                    </div>
                </div>

                <!-- DESGLOSE -->
                <div class="col-span-2 mt-2 border border-[#2A3A4D] rounded-lg p-3 bg-gray-50/50">
                    <h4 class="text-[11px] font-extrabold text-[#2A3A4D] uppercase tracking-wide mb-3">DESGLOSE:</h4>
                    <div class="grid grid-cols-4 gap-3">
                        <div>
                            <label class="block text-[10px] text-gray-500 mb-1">Honorarios:</label>
                            <input v-model="form.honorarios" type="number" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[#2A3A4D]">
                        </div>
                        <div>
                            <label class="block text-[10px] text-gray-500 mb-1">Impuestos:</label>
                            <input v-model="form.impuestos" type="number" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[#2A3A4D]">
                        </div>
                        <div>
                            <label class="block text-[10px] text-gray-500 mb-1">ECI:</label>
                            <input v-model="form.eci" type="number" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[#2A3A4D]">
                        </div>
                        <div>
                            <label class="block text-[10px] text-gray-500 mb-1">Maniobras:</label>
                            <input v-model="form.maniobras" type="number" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[#2A3A4D]">
                        </div>
                        <div>
                            <label class="block text-[10px] text-gray-500 mb-1">Flete:</label>
                            <input v-model="form.flete" type="number" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[#2A3A4D]">
                        </div>
                        <div>
                            <label class="block text-[10px] text-gray-500 mb-1">Muestras:</label>
                            <input v-model="form.muestras" type="number" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[#2A3A4D]">
                        </div>
                        <div>
                            <label class="block text-[10px] text-gray-500 mb-1">LLC:</label>
                            <input v-model="form.llc" type="number" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[#2A3A4D]">
                        </div>

                        <!-- RESUMEN DE TOTALES -->
                        <div
                            class="col-span-full mt-2 p-2.5 bg-white border border-gray-200 rounded flex flex-wrap items-center justify-between shadow-sm">
                            <div class="flex items-center gap-2 px-2">
                                <span class="text-[10px] text-gray-500 font-bold uppercase">Total GPC:</span>
                                <span class="text-sm font-black text-blue-600">{{ formatearDinero(totalGPC) }}</span>
                            </div>
                            <div class="hidden md:block w-px h-6 bg-gray-200"></div>
                            <div class="flex items-center gap-2 px-2">
                                <span class="text-[10px] text-gray-500 font-bold uppercase">Total Honorarios:</span>
                                <span class="text-sm font-black text-[#00C09F]">{{ formatearDinero(form.honorarios)
                                    }}</span>
                            </div>
                            <div
                                class="flex items-center gap-2 bg-yellow-50 border border-yellow-200 px-3 py-1.5 rounded ml-auto">
                                <span class="text-[10px] text-yellow-700 font-bold uppercase">Suma Total:</span>
                                <span class="text-base font-black text-yellow-600">{{ formatearDinero(sumaTotal)
                                    }}</span>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Footer Modal -->
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3 bg-gray-50 shrink-0">
                <button @click="$emit('close')"
                    class="px-5 py-2 rounded bg-gray-100 text-gray-700 text-sm font-bold hover:bg-gray-200 transition-colors shadow-sm">
                    Cancelar
                </button>
                <button @click="guardarIngreso" :disabled="isSubmitting"
                    :class="['px-5 py-2 rounded text-white text-sm font-bold transition-colors shadow-sm', isSubmitting ? 'bg-gray-400 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700']">
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
            isSubmitting: false,
            cargandoSheet: false,
            pedimentosSheet: [],
            tiposComprobanteArray: [],
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
            }
        }
    },
    computed: {
        opcionesPedimentos() {
            let sheetArray = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];
            let filtrados = [];
            
            if (!this.form.cliente) {
                filtrados = sheetArray;
            } else {
                // Aseguramos obtener el nombre sea string o sea un objeto
                const nombreC = typeof this.form.cliente === 'object' ? this.form.cliente.nombre : this.form.cliente;
                const clienteSeleccionado = String(nombreC).toUpperCase().trim();
                
                filtrados = sheetArray.filter(p => {
                    // Si Laravel no manda el cliente, lo dejamos pasar para no vaciar la lista
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
                    
                    // Creamos un objeto nuevo en memoria para no romper la reactividad de Vue
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
        totalGPC() {
            return (parseFloat(this.form.impuestos) || 0) + (parseFloat(this.form.eci) || 0) +
                (parseFloat(this.form.maniobras) || 0) + (parseFloat(this.form.flete) || 0) +
                (parseFloat(this.form.muestras) || 0) + (parseFloat(this.form.llc) || 0);
        },
        sumaTotal() {
            return this.totalGPC + (parseFloat(this.form.honorarios) || 0);
        }
    },
    watch: {
        'form.sucursal_origen': function (newVal, oldVal) {
            if (newVal && newVal !== oldVal) {
                this.cargarListaPedimentos();
            }
        },
        'form.referenciasObj': function (newVal) {
            if (!this.form.cliente && newVal && newVal.length > 0) {
                const refSeleccionada = newVal[0]; 
                const sheetArray = Array.isArray(this.pedimentosSheet) ? this.pedimentosSheet : [];
                
                const refNombre = String(refSeleccionada.label || refSeleccionada.folio || '').trim();

                const dataPedimento = sheetArray.find(p => {
                    const pLabel = String(p.label || p.folio || p.pedimento || '').trim();
                    return pLabel === refNombre;
                });

                if (dataPedimento && dataPedimento.cliente) {
                    const nombreClientePedimento = String(dataPedimento.cliente).toUpperCase().trim();
                    const clienteEncontrado = this.opcionesCliente.find(c => {
                        const nombreOpcion = String(c.nombre).toUpperCase().trim();
                        return nombreOpcion.includes(nombreClientePedimento) || nombreClientePedimento.includes(nombreOpcion);
                    });

                    if (clienteEncontrado) {
                        this.form.cliente = clienteEncontrado;
                    }
                }
            }
        }
    },
    mounted() {
        this.form = { ...this.form, ...this.ingresoBase };

        if (this.form.cliente_id && this.opcionesCliente.length > 0) {
            this.form.cliente = this.opcionesCliente.find(c => c.id === this.form.cliente_id) || null;
        }

        const clienteActual = this.form.cliente && this.form.cliente.nombre ? String(this.form.cliente.nombre).toUpperCase() : '';

        if (this.form.referencia) {
            this.form.referenciasObj = String(this.form.referencia).split(',').map(r => {
                let val = r.trim();
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

        // Dispara la carga si ya hay sucursal en Edit
        if (this.form.sucursal_origen) {
            this.cargarListaPedimentos();
        }
    },
    methods: {
        formatearDinero(monto) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 }).format(parseFloat(monto) || 0);
        },

        agregarReferencia(newTag) {
            const clienteActual = this.form.cliente && this.form.cliente.nombre ? String(this.form.cliente.nombre).toUpperCase() : '';
            const nuevoFolio = { label: newTag, folio: newTag, pedimento: newTag, cliente: clienteActual };
            this.form.referenciasObj.push(nuevoFolio);
        },

        async cargarListaPedimentos() {
            if (this.cargandoSheet) return; 

            this.cargandoSheet = true;
            try {
                // Formato de seguridad para la sucursal
                const nombreSucursal = typeof this.form.sucursal_origen === 'object' && this.form.sucursal_origen !== null 
                    ? (this.form.sucursal_origen.nombre || this.form.sucursal_origen.id) 
                    : this.form.sucursal_origen;

                // 🚀 URL CORREGIDA: Apunta exactamente al mismo lugar que el Modal de Nuevo Ingreso
                const response = await axios.get('/ingresos-conciliados/listar-pedimentos', {
                    params: { sucursal: nombreSucursal }
                });
                
                let dataResult = response.data;

                // Forzamos a Arreglo por si Laravel manda un objeto (como paginación o colecciones)
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

            let pedimentosLimpios = this.form.referenciasObj.map(ref => {
                return ref.folio ? String(ref.folio).replace('F-', '').trim() : String(ref.label);
            }).filter(r => r !== '');

            Swal.fire({
                title: 'Recalculando...',
                text: 'Buscando folios en el sistema...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const nombreSucursal = typeof this.form.sucursal_origen === 'object' && this.form.sucursal_origen !== null 
                    ? (this.form.sucursal_origen.nombre || this.form.sucursal_origen.id) 
                    : this.form.sucursal_origen;

                const response = await axios.post('/ingresos-conciliados/buscar-sheet', {
                    pedimentos: pedimentosLimpios,
                    sucursal: nombreSucursal,
                    tipo_comprobante: this.tiposComprobanteArray
                });
                
                const datos = response.data;
                
                this.form.honorarios = Number(datos.honorarios) || 0;
                this.form.impuestos = Number(datos.impuestos) || 0;
                this.form.eci = Number(datos.eci) || 0;
                this.form.maniobras = Number(datos.maniobras) || 0;
                this.form.flete = Number(datos.flete) || 0;
                this.form.muestras = Number(datos.muestras) || 0;
                this.form.llc = Number(datos.llc) || 0;
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
                
                const sumatoriaTotal = this.form.honorarios +
                    this.form.impuestos +
                    this.form.eci +
                    this.form.maniobras +
                    this.form.flete +
                    this.form.muestras +
                    this.form.llc;

                if (sumatoriaTotal > 0) {
                    this.form.monto_deposito = Number(sumatoriaTotal.toFixed(2));
                }

                if (datos.cliente_detectado) {
                    const nombreExcel = String(datos.cliente_detectado).trim().toUpperCase();
                    const clienteEncontrado = this.opcionesCliente.find(c => String(c.nombre).trim().toUpperCase() === nombreExcel);
                    if (clienteEncontrado) {
                        this.form.cliente = clienteEncontrado;
                    }
                }

                Swal.fire({ title: '¡Datos actualizados!', text: 'Montos recalculados.', icon: 'success', timer: 2000, showConfirmButton: false });
            } catch (error) {
                if (error.response && error.response.status === 400) {
                    Swal.fire('Atención', error.response.data.error, 'warning');
                } else {
                    Swal.fire('Información', 'No se encontraron montos adicionales o falló la red.', 'info');
                }
            }
        },

        async guardarIngreso() {
            if (!this.form.cliente || !this.form.monto_deposito || !this.form.fecha) {
                return Swal.fire('Atención', 'Completa la fecha, cliente y el monto de depósito.', 'warning');
            }

            this.isSubmitting = true;
            const payload = { ...this.form };

            payload.cliente_id = payload.cliente.id;
            delete payload.cliente;
            delete payload._original;
            delete payload.cp;

            payload.referencia = this.form.referenciasObj.map(r => r.folio || r.label).join(', ');
            delete payload.referenciasObj;

            if (this.tiposComprobanteArray.includes('CFDI') && this.tiposComprobanteArray.includes('Nota Cargo')) {
                payload.tipo_comprobante = 'Ambos';
            } else {
                payload.tipo_comprobante = this.tiposComprobanteArray[0] || 'N/A';
            }

            if (payload.pedimento_detectado) {
                payload.referencia = payload.pedimento_detectado;
            } 
            
            delete payload.pedimento_detectado; 

            if (this.form.pedimento_detectado) {
                payload.referencia = this.form.pedimento_detectado;
            }

            try {
                const response = await axios.put(`/ingresos-conciliados/${this.form.id}`, payload);
                if (response.data.success) {
                    Swal.fire({ title: '¡Actualizado!', text: 'Ingreso modificado correctamente.', icon: 'success', toast: true, position: 'top-end', timer: 3000, showConfirmButton: false });
                    this.$emit('ingreso-actualizado');
                }
            } catch (error) {
                Swal.fire('Error', 'Problema al guardar los cambios', 'error');
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

:deep(.multiselect__tags) {
    border-color: #D1D5DB !important;
    padding-top: 6px !important;
    min-height: 38px !important;
}

:deep(.multiselect__tag) {
    background-color: #2A3A4D !important;
}

:deep(.multiselect__option--highlight) {
    background-color: #00C09F !important;
}

:deep(.multiselect__tag-icon:after) {
    color: white !important;
}

:deep(.multiselect__tag-icon:hover) {
    background-color: #EF4444 !important;
}
</style>