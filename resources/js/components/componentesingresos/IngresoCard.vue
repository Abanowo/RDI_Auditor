<template>
    <div
        class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition-all duration-200 flex flex-col relative mb-6">

        <!-- Barra lateral indicadora de color (Rojo si hay diferencia, Verde si está cuadrado) -->
        <div class="absolute left-0 top-0 bottom-0 w-2" :class="diferencia < 0 ? 'bg-red-500' : 'bg-green-500'">
        </div>

        <!-- ENCABEZADO DE LA TARJETA -->
        <div
            class="px-8 py-5 border-b border-gray-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-gray-100 ml-2">
            <div class="flex items-center gap-4">
                <!-- Badge de Operación/Sucursal -->
                <span class="px-4 py-1.5 text-white text-sm font-black rounded-lg tracking-wider uppercase shadow-sm"
                    style="background-color: #2A3A4D;">
                    {{ item.sucursal_origen || 'N/A' }}
                </span>
                <span class="text-base font-bold text-gray-500">{{ item.fecha }}</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold text-gray-400 uppercase">Banco Receptor:</span>
                <span class="text-base font-bold text-gray-700">{{ item.banco_receptor || 'No Especificado' }}</span>
            </div>
        </div>

        <!-- CUERPO DE LA TARJETA -->
        <div class="p-8 flex flex-col xl:flex-row gap-10 ml-2">

            <!-- LADO IZQUIERDO: Identificación y Totales -->
            <div class="w-full xl:w-1/3 flex flex-col justify-center">
                <div class="flex items-center gap-2 mb-3">
                    <span
                        class="text-sm font-bold text-indigo-700 bg-indigo-100 border border-indigo-200 px-3 py-1 rounded-md tracking-wide uppercase">
                        Ref / Folio SC: {{ item.folio_sc || 'N/A' }}
                    </span>
                </div>
                <h3 class="text-2xl lg:text-3xl font-black text-gray-800 uppercase tracking-tight mb-8 leading-tight">
                    {{ item.cliente }}
                </h3>

                <div class="space-y-3">
                    <div
                        class="flex justify-between items-center p-4 bg-purple-100 rounded-xl border border-purple-200">
                        <span class="text-sm font-bold text-purple-700 uppercase">Monto Depósito</span>
                        <span class="text-xl font-black text-purple-700">{{ formatearDinero(item.monto_deposito)
                            }}</span>
                    </div>
                    <div class="flex justify-between items-center p-4 bg-gray-100 rounded-xl border border-gray-200">
                        <span class="text-sm font-bold text-gray-600 uppercase">Monto SC (Calc)</span>
                        <span class="text-xl font-black text-gray-800">{{ formatearDinero(montoSC) }}</span>
                    </div>
                    <div class="flex justify-between items-center p-4 rounded-xl border"
                        :class="diferencia < 0 ? 'bg-red-100 border-red-200' : 'bg-green-100 border-green-200'">
                        <span class="text-sm font-bold uppercase"
                            :class="diferencia < 0 ? 'text-red-700' : 'text-green-700'">Diferencia</span>
                        <span class="text-xl font-black" :class="diferencia < 0 ? 'text-red-600' : 'text-green-600'">{{
                            formatearDinero(diferencia) }}</span>
                    </div>
                </div>
            </div>

            <!-- LADO DERECHO: Desglose en Cuadrícula -->
            <div class="w-full xl:w-2/3 flex flex-col justify-center">
                <h4 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4 border-b border-gray-200 pb-3">
                    Desglose de Conceptos
                </h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">

                    <div v-for="(concepto, index) in desgloseActivo" :key="index"
                        class="bg-white border border-gray-200 rounded-xl p-4 flex flex-col justify-between transition-colors shadow-sm relative overflow-hidden group"
                        :class="Number(concepto.monto) > 0 ? 'border-green-300 bg-green-50' : 'hover:border-blue-300'">

                        <!-- Barra superior del recuadro -->
                        <div class="absolute top-0 left-0 right-0 h-1.5 transition-colors"
                            :class="Number(concepto.monto) > 0 ? 'bg-green-500' : 'bg-gray-200 group-hover:bg-blue-400'">
                        </div>

                        <div class="flex justify-between items-start mb-3 mt-2">
                            <span class="text-xs font-black uppercase tracking-wider"
                                :class="Number(concepto.monto) > 0 ? 'text-gray-800' : 'text-gray-400'">{{
                                concepto.label }}</span>
                        </div>

                        <div class="flex justify-between items-end">
                            <span class="text-[11px] font-bold uppercase"
                                :class="Number(concepto.monto) > 0 ? 'text-green-600' : 'text-gray-400'">
                                {{ Number(concepto.monto) > 0 ? 'Registrado' : 'S/D' }}
                            </span>
                            <span class="text-lg font-black"
                                :class="Number(concepto.monto) > 0 ? 'text-green-600' : 'text-gray-300'">
                                {{ formatearDinero(concepto.monto) }}
                            </span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- PIE DE LA TARJETA: Estatus y Acciones -->
        <div
            class="px-8 py-5 bg-gray-100 border-t border-gray-200 flex flex-col sm:flex-row justify-between items-center gap-6 ml-2">

            <!-- Estatus e Info de Complemento -->
            <div class="flex flex-wrap items-center gap-4">
                <span v-if="item.tipo_comprobante === 'CFDI'"
                    class="px-4 py-1.5 bg-blue-100 text-blue-700 font-extrabold rounded-lg border border-blue-200 text-xs">CFDI</span>
                <span v-else-if="item.tipo_comprobante === 'Nota Cargo'"
                    class="px-4 py-1.5 bg-purple-100 text-purple-700 font-extrabold rounded-lg border border-purple-200 text-xs">NOTA
                    CARGO</span>
                <span v-else
                    class="px-4 py-1.5 bg-gray-200 text-gray-600 font-extrabold rounded-lg border border-gray-300 text-xs">{{
                        item.tipo_comprobante || 'N/A' }}</span>

                <div class="h-6 w-px bg-gray-300 hidden sm:block"></div>

                <span class="text-sm font-bold text-gray-600">Comp: <span class="text-gray-800">{{
                    item.folio_complemento || 'SIN COMPLEMENTO' }}</span></span>

                <div class="h-6 w-px bg-gray-300 hidden sm:block"></div>

                <span class="text-xs font-bold px-4 py-1.5 rounded-lg uppercase tracking-wider border"
                    :class="item.estado_envio === 'ENVIADO' ? 'bg-green-100 text-green-700 border-green-200' : 'bg-orange-100 text-orange-700 border-orange-200'">
                    {{ item.estado_envio || 'PENDIENTE' }}
                </span>
            </div>

            <!-- Botones de Acción -->
            <div class="flex items-center gap-3">
                <button @click="$emit('generar', item)"
                    class="text-green-600 hover:text-white bg-green-100 hover:bg-green-500 p-3 rounded-xl transition-colors shadow-sm"
                    title="Generar Complemento">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('visualizar', item)"
                    class="text-blue-600 hover:text-white bg-blue-100 hover:bg-blue-500 p-3 rounded-xl transition-colors shadow-sm"
                    title="Visualizar Complemento">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('enviar', item)"
                    class="text-purple-600 hover:text-white bg-purple-100 hover:bg-purple-500 p-3 rounded-xl transition-colors shadow-sm"
                    title="Enviar Complemento">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                </button>
                <button @click="$emit('editar', item)"
                    class="text-indigo-600 hover:text-white bg-indigo-100 hover:bg-indigo-500 p-3 rounded-xl transition-colors shadow-sm"
                    title="Editar Fila">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('eliminar', item.id)"
                    class="text-red-500 hover:text-white bg-red-100 hover:bg-red-500 p-3 rounded-xl transition-colors shadow-sm"
                    title="Eliminar Fila">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                        </path>
                    </svg>
                </button>
            </div>
        </div>

    </div>
</template>

<script>
export default {
    name: 'IngresoCard',
    props: {
        item: { type: Object, required: true },
        esVistaManzanillo: { type: Boolean, default: false },
        esVistaIntshipperts: { type: Boolean, default: false },
        esVistaTransportactics: { type: Boolean, default: false }
    },
    computed: {
        desgloseActivo() {
            if (this.esVistaIntshipperts) {
                return [
                    { label: 'Anticipo', monto: this.item.anticipo },
                    { label: 'Alman / Flete', monto: this.item.flete }
                ];
            } else if (this.esVistaTransportactics) {
                return [
                    { label: 'Flete (XML)', monto: this.item.flete },
                    { label: 'Pago Proveedor', monto: this.item.pago_proveedor },
                    { label: 'Ganancia', monto: this.item.ganancia }
                ];
            } else if (this.esVistaManzanillo) {
                return [
                    { label: 'Anticipo', monto: this.item.anticipo },
                    { label: 'Garantías', monto: this.item.garantias },
                    { label: 'Desglose Naviera', monto: this.item.desglose_naviera },
                    { label: 'Impuestos', monto: this.item.impuestos },
                    { label: 'Alman / Flete', monto: this.item.flete },
                    { label: 'Honorarios', monto: this.item.honorarios }
                ];
            } else {
                return [
                    { label: 'Honorarios', monto: this.item.honorarios },
                    { label: 'Total GPC', monto: this.item.total_gpc },
                    { label: 'Impuestos', monto: this.item.impuestos },
                    { label: 'ECI', monto: this.item.eci },
                    { label: 'Maniobras', monto: this.item.maniobras },
                    { label: 'Flete', monto: this.item.flete },
                    { label: 'Muestras', monto: this.item.muestras },
                    { label: 'LLC', monto: this.item.llc }
                ];
            }
        },
        montoSC() {
            const nombreCliente = String(this.item.cliente || '').toUpperCase();
            const sucursalOrigen = String(this.item.sucursal_origen || '').toUpperCase();
            const isTransportactics = nombreCliente.includes('TRANSPORTACTICS') || sucursalOrigen.includes('TRANSPORTACTIC');

            if (isTransportactics) return Number(this.item.flete) || 0;

            const esManzanilloRow = sucursalOrigen.includes('MANZANILLO') || sucursalOrigen.includes('INTSHIPPERT');
            if (esManzanilloRow) {
                return (Number(this.item.anticipo) || 0) + (Number(this.item.garantias) || 0) +
                    (Number(this.item.desglose_naviera) || 0) + (Number(this.item.impuestos) || 0) +
                    (Number(this.item.flete) || 0) + (Number(this.item.honorarios) || 0);
            }

            return (Number(this.item.honorarios) || 0) + (Number(this.item.impuestos) || 0) +
                (Number(this.item.eci) || 0) + (Number(this.item.maniobras) || 0) +
                (Number(this.item.flete) || 0) + (Number(this.item.muestras) || 0) + (Number(this.item.llc) || 0);
        },
        diferencia() {
            return (Number(this.item.monto_deposito) || 0) - this.montoSC;
        }
    },
    methods: {
        formatearDinero(monto) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2
            }).format(parseFloat(monto) || 0);
        }
    }
}
</script>