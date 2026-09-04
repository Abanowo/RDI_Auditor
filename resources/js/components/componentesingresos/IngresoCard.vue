<template>
    <div
        class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition-all duration-200 flex flex-col relative mb-5">

        <!-- Barra lateral indicadora de color -->
        <div class="absolute left-0 top-0 bottom-0 w-2"
            :class="diferencia < 0 ? 'bg-red-500' : (diferencia > 0 ? 'bg-yellow-500' : 'bg-green-500')">
        </div>

        <!-- ENCABEZADO -->
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 ml-2">
            <div class="flex items-center gap-4">
                <span class="px-3 py-1 text-white font-black rounded uppercase shadow-sm text-xl"
                    style="background-color: #2A3A4D;">
                    {{ item.sucursal_origen || 'N/A' }}
                </span>
                <span class="text-xl font-bold text-gray-500">{{ item.fecha }}</span>
                <span
                    class="text-xl font-bold text-indigo-700 bg-indigo-100 border border-indigo-200 px-3 py-1 rounded uppercase hidden sm:block">
                    Ref: {{ item.folio_sc || 'N/A' }}
                </span>
            </div>
            <div class="flex items-center gap-3">
                <span class="font-bold text-gray-400 uppercase text-xl hidden md:block">Banco:</span>
                <span class="text-xl font-bold text-gray-700">{{ item.banco_receptor || 'N/E' }}</span>
            </div>
        </div>

        <!-- CUERPO DE LA TARJETA -->
        <div class="p-6 ml-2 flex flex-col gap-5">

            <!-- Título del Cliente -->
            <h3 class="text-2xl font-black text-gray-800 uppercase tracking-tight leading-none truncate">
                {{ item.cliente }}
            </h3>

            <!-- Fila Principal: Totales (Izquierda) + Desglose (Derecha) -->
            <div class="flex flex-col xl:flex-row gap-6 w-full">

                <!-- TOTALES (Uno a lado del otro) -->
                <div class="flex gap-4 w-full xl:w-auto shrink-0">
                    <div
                        class="flex flex-col justify-center px-5 py-3 bg-blue-100 border border-blue-200 rounded-lg shrink-0 min-w-[150px]">
                        <span class="font-bold text-blue-700 uppercase text-sm mb-1">Depósito</span>
                        <span class="text-xl font-black text-blue-700 leading-none">{{
                            formatearDinero(item.monto_deposito) }}</span>
                    </div>
                    <div
                        class="flex flex-col justify-center px-5 py-3 bg-gray-100 border border-gray-200 rounded-lg shrink-0 min-w-[150px]">
                        <span class="font-bold text-gray-500 uppercase text-sm mb-1">SC (Calc)</span>
                        <span class="text-xl font-black text-gray-800 leading-none">{{ formatearDinero(montoSC)
                        }}</span>
                    </div>
                    <div class="flex flex-col justify-center px-5 py-3 border rounded-lg shrink-0 min-w-[150px]"
                        :class="diferencia < 0 ? 'bg-red-100 border-red-200' : (diferencia > 0 ? 'bg-yellow-100 border-yellow-200' : 'bg-green-100 border-green-200')">
                        <span class="font-bold uppercase text-sm mb-1"
                            :class="diferencia < 0 ? 'text-red-600' : (diferencia > 0 ? 'text-yellow-600' : 'text-green-600')">Diferencia</span>
                        <span class="text-xl font-black leading-none"
                            :class="diferencia < 0 ? 'text-red-700' : (diferencia > 0 ? 'text-yellow-700' : 'text-green-700')">{{
                                formatearDinero(diferencia)
                            }}</span>
                    </div>
                </div>

                <!-- SEPARADOR VISUAL SOLO EN ESCRITORIO -->
                <div class="hidden xl:block w-px bg-gray-200 shrink-0 my-2"></div>

                <!-- DESGLOSE (Todos en la misma línea usando grid-cols-8) -->
                <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-3 w-full">
                    <div v-for="(concepto, index) in desgloseActivo" :key="index"
                        class="border rounded-lg px-4 py-3 flex flex-col justify-center items-start relative overflow-hidden transition-colors"
                        :class="Number(concepto.monto) > 0 ? 'border-green-300 bg-green-100' : 'bg-gray-100 border-gray-200'">

                        <!-- Etiqueta del concepto -->
                        <span class="font-bold uppercase tracking-wider text-sm truncate w-full mb-1.5"
                            :class="Number(concepto.monto) > 0 ? 'text-gray-700' : 'text-gray-700'">
                            {{ concepto.label }}
                        </span>

                        <!-- Monto -->
                        <span class="text-base font-black truncate w-full leading-none"
                            :class="Number(concepto.monto) > 0 ? 'text-green-600' : 'text-gray-700'">
                            {{ formatearDinero(concepto.monto) }}
                        </span>
                    </div>
                </div>

            </div>
        </div>

        <!-- PIE DE LA TARJETA -->
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-between items-center ml-2">
            <div class="flex items-center gap-5">
                <span v-if="item.tipo_comprobante === 'CFDI'"
                    class="px-4 py-1.5 bg-blue-100 text-blue-700 font-bold rounded border border-blue-200 text-xl">CFDI</span>
                <span v-else-if="item.tipo_comprobante === 'Nota Cargo'"
                    class="px-4 py-1.5 bg-purple-100 text-purple-700 font-bold rounded border border-purple-200 text-xl">NOTA
                    CARGO</span>
                <span v-else
                    class="px-4 py-1.5 bg-gray-200 text-gray-600 font-bold rounded border border-gray-300 text-xl">
                    {{ item.tipo_comprobante || 'N/A' }}
                </span>

                <div class="h-5 w-px bg-gray-300"></div>

                <!-- FOLIO DEL COMPLEMENTO -->
                <span class="text-base font-bold text-gray-500">Comp:
                    <span class="text-gray-800">
                        <!-- Si es PUE dice "NO APLICA", de lo contrario pone el folio o "SIN COMPLEMENTO" -->
                        {{ item.metodo_pago === 'PUE' ? 'NO APLICA' : (item.folio_complemento || 'SIN COMPLEMENTO') }}
                    </span>
                </span>

                <div class="h-5 w-px bg-gray-300"></div>
                <span class="font-bold px-4 py-1.5 rounded uppercase border text-sm" :class="item.metodo_pago === 'PUE'
                    ? 'bg-gray-100 text-gray-700 border-gray-200'
                    : (item.estado_envio === 'ENVIADO'
                        ? 'bg-green-100 text-green-700 border-green-200'
                        : 'bg-orange-100 text-orange-700 border-orange-200')">
                    <!-- Si es PUE dice "NO APLICA", de lo contrario pone el estado o "PENDIENTE" -->
                    {{ item.metodo_pago === 'PUE' ? 'NO APLICA' : (item.estado_envio || 'PENDIENTE') }}
                </span>
            </div>

            <!-- Botones de Acción (Más grandes) -->
            <div class="flex items-center gap-2.5">
                <button @click="$emit('generar', item)" :disabled="item.metodo_pago === 'PUE'" :class="[
                    'p-2.5 rounded-lg transition-colors',
                    item.metodo_pago === 'PUE'
                        ? 'text-gray-400 bg-gray-100 cursor-not-allowed'
                        : 'text-green-600 hover:text-white bg-green-100 hover:bg-green-500'
                ]"
                    :title="item.metodo_pago === 'PUE' ? 'No requiere complemento (Factura PUE)' : 'Generar Complemento'">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('visualizar', item)" :disabled="item.metodo_pago === 'PUE'" :class="[
                    'p-2.5 rounded-lg transition-colors',
                    item.metodo_pago === 'PUE'
                        ? 'text-gray-400 bg-gray-100 cursor-not-allowed'
                        : 'text-blue-600 hover:text-white bg-blue-100 hover:bg-blue-500'
                ]"
                    :title="item.metodo_pago === 'PUE' ? 'No requiere complemento (Factura PUE)' : 'Visualizar Complemento'">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('enviar', item)" :disabled="item.metodo_pago === 'PUE'" :class="[
                    'p-2.5 rounded-lg transition-colors',
                    item.metodo_pago === 'PUE'
                        ? 'text-gray-400 bg-gray-100 cursor-not-allowed'
                        : 'text-purple-600 hover:text-white bg-purple-100 hover:bg-purple-500'
                ]"
                    :title="item.metodo_pago === 'PUE' ? 'No requiere complemento (Factura PUE)' : 'Enviar Complemento'">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                </button>
                <button v-if="esManzanilloCard" @click="$emit('ver-desglose', item)"
                    class="text-purple-600 bg-purple-100 hover:bg-purple-200 p-2.5 rounded-lg transition-colors"
                    title="Ver Desglose de Anticipos por Contenedor">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                    </svg>
                </button>
                <button @click="$emit('editar', item)" :disabled="item.timbrado || item.estado_envio === 'ENVIADO'"
                    :class="[
                        'p-2.5 rounded-lg transition-colors',
                        item.timbrado || item.estado_envio === 'ENVIADO'
                            ? 'text-gray-400 bg-gray-100 cursor-not-allowed'
                            : 'text-indigo-600 hover:text-white bg-indigo-100 hover:bg-indigo-500'
                    ]"
                    :title="(item.timbrado || item.estado_envio === 'ENVIADO') ? 'Inhabilitado: El ingreso ya fue timbrado' : 'Editar Fila'">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
                        </path>
                    </svg>
                </button>
                <button @click="$emit('eliminar', item.id)"
                    :disabled="!puedeEliminar || item.timbrado || item.estado_envio === 'ENVIADO'" :class="[
                        'p-2.5 rounded-lg transition-colors',
                        (!puedeEliminar || item.timbrado || item.estado_envio === 'ENVIADO')
                            ? 'text-gray-400 bg-gray-100 cursor-not-allowed'
                            : 'text-red-500 hover:text-white bg-red-100 hover:bg-red-500'
                    ]"
                    :title="!puedeEliminar
                        ? 'No tienes los permisos necesarios para eliminar'
                        : ((item.timbrado || item.estado_envio === 'ENVIADO') ? 'Inhabilitado: El ingreso ya fue timbrado' : 'Eliminar Fila')">

                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
        item: {
            type: Object,
            required: true
        },
        usuarioActual: {
            type: Object,
            default: () => ({})
        }
    },
    computed: {
        esRegistroIntshipperts() {
            const nombreCliente = String(this.item.cliente || '').toUpperCase();
            const sucursalOrigen = String(this.item.sucursal_origen || '').toUpperCase();
            return nombreCliente.includes('INTSHIPPERTS') || sucursalOrigen.includes('INTSHIPPERT');
        },
        esRegistroTransportactics() {
            const nombreCliente = String(this.item.cliente || '').toUpperCase();
            const sucursalOrigen = String(this.item.sucursal_origen || '').toUpperCase();
            return nombreCliente.includes('TRANSPORTACTICS') || sucursalOrigen.includes('TRANSPORTACTIC');
        },
        esRegistroManzanillo() {
            const sucursalOrigen = String(this.item.sucursal_origen || '').toUpperCase();
            return sucursalOrigen.includes('MANZANILLO');
        },

        desgloseActivo() {
            if (this.esRegistroIntshipperts) {
                return [
                    { label: 'Anticipo', monto: this.item.anticipo },
                    { label: 'Alman / Flete', monto: this.item.flete }
                ];
            } else if (this.esRegistroTransportactics) {
                return [
                    { label: 'Flete (XML)', monto: this.item.flete },
                    { label: 'Pago Proveedor', monto: this.item.pago_proveedor },
                    { label: 'Ganancia', monto: this.item.ganancia }
                ];
            } else if (this.esRegistroManzanillo) {
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
            if (this.esRegistroTransportactics) {
                return Number(this.item.flete) || 0;
            }

            if (this.esRegistroManzanillo || this.esRegistroIntshipperts) {
                return (Number(this.item.anticipo) || 0) + (Number(this.item.garantias) || 0) +
                    (Number(this.item.desglose_naviera) || 0) + (Number(this.item.impuestos) || 0) +
                    (Number(this.item.flete) || 0) + (Number(this.item.honorarios) || 0);
            }

            return (Number(this.item.honorarios) || 0) + (Number(this.item.impuestos) || 0) +
                (Number(this.item.eci) || 0) + (Number(this.item.maniobras) || 0) +
                (Number(this.item.flete) || 0) + (Number(this.item.muestras) || 0) + (Number(this.item.llc) || 0);
        },
        diferencia() {
            const calc = (Number(this.item.monto_deposito) || 0) - this.montoSC;
            return Number(calc.toFixed(2));
        },
        esManzanilloCard() {
            const sucursal = String((this.item && this.item.sucursal_origen) || '').toUpperCase();
            return sucursal.includes('MANZANILLO') || sucursal.includes('ZLO');
        },
        puedeEliminar() {
            // 1. Leemos directamente el usuario de la ventana (inyectado por Blade)
            const usuario = window.UsuarioActual;

            // Si no hay usuario, bloqueamos el botón por seguridad
            if (!usuario || !usuario.nombre) {
                return false;
            }

            // 2. Armamos el nombre y limpiamos acentos/mayúsculas
            const nombreStr = (usuario.nombre || '') + ' ' + (usuario.apellidos || '');
            const nombreLimpio = nombreStr.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase();

            // 3. Verificamos si es alguna de las usuarias autorizadas
            return nombreLimpio.includes('SONIA GOMEZ') ||
                nombreLimpio.includes('MIRNA LOPEZ') ||
                nombreLimpio.includes('SAYDA LEYVA');
        }
    },
    methods: {
        formatearDinero(monto) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2
            }).format(parseFloat(monto) || 0);
        },
        mostrarDesgloseManzanillo(item) {
            if (!item.operaciones || item.operaciones.length === 0) {
                return;
            }

            let filasHtml = item.operaciones.map(op => {
                // Lee el anticipo y el folio tanto si vienen directos como por pivote
                const anticipoVal = op.anticipo !== undefined
                    ? parseFloat(op.anticipo || 0)
                    : (op.pivot ? parseFloat(op.pivot.anticipo || 0) : 0);

                const refVal = op.referencia || (op.pivot ? op.pivot.referencia : null) || op.folio || 'N/A';

                return `
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: left; font-weight: bold;">
                        ${folioVal}
                        </td>
                        <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right; color: #00C09F; font-weight: 900;">
                        $${anticipoVal.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                        </td>
                    </tr>
                    `;
                        }).join('');

                        Swal.fire({
                            title: 'Desglose de Anticipos',
                            html: `
                    <div style="font-family: Arial, sans-serif;">
                        <p style="color: #666; margin-bottom: 15px; text-align: left;">
                        Cliente: <b>${item.cliente?.nombre || item.cliente}</b><br>
                        Total Anticipo Global: <b>$${parseFloat(item.anticipo || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</b>
                        </p>
                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                        <thead style="background: #f8f9fa;">
                            <tr>
                            <th style="padding: 10px; text-align: left;">Contenedor / Ref.</th>
                            <th style="padding: 10px; text-align: right;">Anticipo Asignado</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filasHtml}
                        </tbody>
                        </table>
                    </div>
                    `,
                confirmButtonColor: '#2A3A4D',
                confirmButtonText: 'Cerrar',
                width: '600px'
            });
        }
    }
}
</script>