<template>
    <div
        class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition-all duration-200 flex flex-col relative mb-3">

        <!-- Barra lateral indicadora de color (Rojo: Negativo, Verde: Exacto, Amarillo: A favor) -->
        <div class="absolute left-0 top-0 bottom-0 w-1.5"
            :class="diferencia < 0 ? 'bg-red-500' : (diferencia > 0 ? 'bg-yellow-500' : 'bg-green-500')">
        </div>

        <!-- ENCABEZADO SUPER COMPACTO -->
        <div class="px-4 py-2 border-b border-gray-200 flex justify-between items-center bg-gray-100 ml-1.5">
            <div class="flex items-center gap-3">
                <span class="px-2 py-0.5 text-white font-black rounded uppercase shadow-sm"
                    style="font-size: 10px; background-color: #2A3A4D;">
                    {{ item.sucursal_origen || 'N/A' }}
                </span>
                <span class="text-xs font-bold text-gray-500">{{ item.fecha }}</span>
                <span
                    class="text-[10px] font-bold text-indigo-700 bg-indigo-100 border border-indigo-200 px-2 py-0.5 rounded uppercase hidden sm:block">
                    Ref: {{ item.folio_sc || 'N/A' }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-bold text-gray-400 uppercase text-[10px] hidden md:block">Banco:</span>
                <span class="text-xs font-bold text-gray-700">{{ item.banco_receptor || 'N/E' }}</span>
            </div>
        </div>

        <!-- CUERPO DE LA TARJETA (TODO EN LÍNEA) -->
        <div class="p-3 ml-1.5 flex flex-col gap-3">

            <!-- Título del Cliente -->
            <h3 class="text-base font-black text-gray-800 uppercase tracking-tight leading-none truncate">
                {{ item.cliente }}
            </h3>

            <!-- Fila Principal: Totales (Izquierda) + Desglose (Derecha) -->
            <div class="flex flex-col xl:flex-row gap-4 w-full">

                <!-- TOTALES (Uno a lado del otro) -->
                <div class="flex gap-2 w-full xl:w-auto shrink-0">
                    <div
                        class="flex flex-col justify-center px-3 py-1.5 bg-blue-100 border border-blue-200 rounded shrink-0 min-w-[110px]">
                        <span class="font-bold text-blue-700 uppercase text-[9px] mb-0.5">Depósito</span>
                        <span class="text-sm font-black text-blue-700 leading-none">{{
                            formatearDinero(item.monto_deposito) }}</span>
                    </div>
                    <div
                        class="flex flex-col justify-center px-3 py-1.5 bg-gray-100 border border-gray-200 rounded shrink-0 min-w-[110px]">
                        <span class="font-bold text-gray-500 uppercase text-[9px] mb-0.5">SC</span>
                        <span class="text-sm font-black text-gray-800 leading-none">{{ formatearDinero(montoSC)
                            }}</span>
                    </div>
                    <div class="flex flex-col justify-center px-3 py-1.5 border rounded shrink-0 min-w-[110px]"
                        :class="diferencia < 0 ? 'bg-red-100 border-red-200' : (diferencia > 0 ? 'bg-yellow-100 border-yellow-200' : 'bg-green-100 border-green-200')">
                        <span class="font-bold uppercase text-[9px] mb-0.5"
                            :class="diferencia < 0 ? 'text-red-600' : (diferencia > 0 ? 'text-yellow-600' : 'text-green-600')">Diferencia</span>
                        <span class="text-sm font-black leading-none"
                            :class="diferencia < 0 ? 'text-red-700' : (diferencia > 0 ? 'text-yellow-700' : 'text-green-700')">{{
                                formatearDinero(diferencia)
                            }}</span>
                    </div>
                </div>

                <!-- SEPARADOR VISUAL SOLO EN ESCRITORIO -->
                <div class="hidden xl:block w-px bg-gray-200 shrink-0 my-1"></div>

                <!-- DESGLOSE (Todos en la misma línea usando grid-cols-8) -->
                <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-2 w-full">
                    <div v-for="(concepto, index) in desgloseActivo" :key="index"
                        class="border rounded px-2 py-1.5 flex flex-col justify-center items-start relative overflow-hidden transition-colors"
                        :class="Number(concepto.monto) > 0 ? 'border-green-300 bg-green-100' : 'bg-white border-gray-200'">

                        <!-- Etiqueta del concepto -->
                        <span class="font-bold uppercase tracking-wider text-[9px] truncate w-full mb-0.5"
                            :class="Number(concepto.monto) > 0 ? 'text-gray-700' : 'text-gray-400'">
                            {{ concepto.label }}
                        </span>

                        <!-- Monto -->
                        <span class="text-xs font-black truncate w-full leading-none"
                            :class="Number(concepto.monto) > 0 ? 'text-green-600' : 'text-gray-300'">
                            {{ formatearDinero(concepto.monto) }}
                        </span>
                    </div>
                </div>

            </div>
        </div>

        <!-- PIE DE LA TARJETA COMPACTO -->
        <div class="px-4 py-2 bg-gray-100 border-t border-gray-200 flex justify-between items-center ml-1.5">
            <div class="flex items-center gap-3">
                <span v-if="item.tipo_comprobante === 'CFDI'"
                    class="px-2 py-0.5 bg-blue-100 text-blue-700 font-bold rounded border border-blue-200 text-[10px]">CFDI</span>
                <span v-else-if="item.tipo_comprobante === 'Nota Cargo'"
                    class="px-2 py-0.5 bg-purple-100 text-purple-700 font-bold rounded border border-purple-200 text-[10px]">NOTA
                    CARGO</span>
                <span v-else
                    class="px-2 py-0.5 bg-gray-200 text-gray-600 font-bold rounded border border-gray-300 text-[10px]">{{
                        item.tipo_comprobante || 'N/A' }}</span>

                <div class="h-3 w-px bg-gray-300"></div>
                <span class="text-[11px] font-bold text-gray-500">Comp: <span class="text-gray-800">{{
                    item.folio_complemento || 'SIN COMPLEMENTO' }}</span></span>

                <div class="h-3 w-px bg-gray-300"></div>
                <span class="font-bold px-2 py-0.5 rounded uppercase border text-[10px]"
                    :class="item.estado_envio === 'ENVIADO' ? 'bg-green-100 text-green-700 border-green-200' : 'bg-orange-100 text-orange-700 border-orange-200'">
                    {{ item.estado_envio || 'PENDIENTE' }}
                </span>
            </div>

            <!-- Botones miniatura -->
            <div class="flex items-center gap-1.5">
                <button @click="$emit('generar', item)"
                    class="text-green-600 hover:text-white bg-green-100 hover:bg-green-500 p-1.5 rounded transition-colors"
                    title="Generar Complemento">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('visualizar', item)"
                    class="text-blue-600 hover:text-white bg-blue-100 hover:bg-blue-500 p-1.5 rounded transition-colors"
                    title="Visualizar Complemento">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('enviar', item)"
                    class="text-purple-600 hover:text-white bg-purple-100 hover:bg-purple-500 p-1.5 rounded transition-colors"
                    title="Enviar Complemento">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                </button>
                <button @click="$emit('editar', item)"
                    class="text-indigo-600 hover:text-white bg-indigo-100 hover:bg-indigo-500 p-1.5 rounded transition-colors"
                    title="Editar Fila">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('eliminar', item.id)"
                    class="text-red-500 hover:text-white bg-red-100 hover:bg-red-500 p-1.5 rounded transition-colors"
                    title="Eliminar Fila">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    { label: 'Desg. Naviera', monto: this.item.desglose_naviera },
                    { label: 'Impuestos', monto: this.item.impuestos },
                    { label: 'Alm/Flete', monto: this.item.flete },
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
            // Utilizamos toFixed para evitar errores matemáticos de JS con decimales largos
            const calc = (Number(this.item.monto_deposito) || 0) - this.montoSC;
            return Number(calc.toFixed(2));
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