<template>
    <div v-if="mostrar" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 p-4 md:p-8">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-7xl flex flex-col overflow-visible"
            style="height: 90vh;">

            <!-- Encabezado -->
            <div class="px-8 py-5 flex justify-between items-center rounded-t-xl shrink-0" style="background-color: #2A3A4D;">
                <h3 class="text-white font-bold text-xl tracking-wide">✉️ Configurar y Enviar Correo</h3>
                <button @click="cerrar"
                    class="text-gray-400 hover:text-white transition-colors bg-gray-700 hover:bg-gray-600 rounded-full p-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <!-- Cuerpo dividido en 2 columnas -->
            <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">
                <div class="w-full lg:w-4/12 p-8 border-r border-gray-200 flex flex-col overflow-visible bg-white">
                    <h4 class="font-extrabold text-gray-800 text-xl mb-3">Destinatarios</h4>
                    <div v-if="listaCorreos.length > 0"
                        class="mb-4 bg-green-100 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm font-bold flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        Correos precargados automáticamente.
                    </div>
                    <p v-else class="text-base text-gray-600 mb-6">Ingresa los correos y presiona <strong>Enter</strong>
                        para agregarlos a la lista de envío.</p>

                    <div class="flex-1 overflow-visible">
                        <multiselect v-model="listaCorreos" :options="opcionesSugeridas" :multiple="true"
                            :taggable="true" @tag="agregarCorreo" placeholder="Escribe un correo y presiona Enter..."
                            tag-placeholder="Presiona Enter para agregar" select-label="" selected-label="Seleccionado"
                            deselect-label="Remover" class="w-full shadow-sm text-lg">
                            <template slot="noResult">No se encontraron correos.</template>
                            <template slot="noOptions">Escribe un correo para agregarlo.</template>
                        </multiselect>
                    </div>
                </div>

                <!-- COLUMNA DERECHA: Vista Previa del Correo -->
                <div class="w-full lg:w-8/12 p-8 bg-gray-100 flex flex-col overflow-y-auto custom-scrollbar">
                    <h4 class="font-extrabold text-gray-800 text-xl mb-6 flex items-center gap-3">
                        <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                            </path>
                        </svg>
                        Vista Previa del Mensaje
                    </h4>

                    <!-- Contenedor del Correo (Réplica exacta de la imagen) -->
                    <div
                        class="bg-white border border-gray-200 flex flex-col flex-1 relative mx-auto w-full max-w-3xl shadow-sm rounded-sm">

                        <!-- HEADER DEL CORREO -->
                        <div class="px-10 pt-10 pb-6 flex justify-between items-start">
                            <div>
                                <h2 class="text-2xl font-black tracking-wide mb-2" style="color: #1b365d;">Complemento de Pago
                                </h2>
                                <p class="text-gray-500 text-base">Folio: <span class="font-black text-gray-800">CP-{{
                                    previewFolioComp
                                }}</span></p>
                            </div>

                            <!-- Simulación de Logo InTactics con CSS -->
                            <div class="flex flex-col items-center justify-center mr-2">
                                <div class="flex flex-col gap-[3px] mb-1 transform -skew-x-12">
                                    <div class="flex gap-[3px]">
                                        <div class="w-1.5 h-1.5 rounded-full bg-transparent"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-transparent"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-400"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-500"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                    </div>
                                    <div class="flex gap-[3px]">
                                        <div class="w-1.5 h-1.5 rounded-full bg-transparent"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-300"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-400"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-500"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                    </div>
                                    <div class="flex gap-[3px]">
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-300"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-400"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-400"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-transparent"></div>
                                    </div>
                                    <div class="flex gap-[3px]">
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-400"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                        <div class="w-1.5 h-1.5 rounded-full" style="background-color: #1b365d;"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-500"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-gray-300"></div>
                                        <div class="w-1.5 h-1.5 rounded-full bg-transparent"></div>
                                    </div>
                                </div>
                                <span class="text-gray-500 font-medium text-sm tracking-tight">{{ nombreEmpresaEmisora
                                }}<sup class="text-[8px]">®</sup></span>
                            </div>
                        </div>

                        <!-- LÍNEA AZUL GRUESA -->
                        <div class="w-full h-1" style="background-color: #1b365d;"></div>

                        <!-- CUERPO DEL CORREO -->
                        <div class="px-10 py-8 text-gray-700 text-base flex-1">
                            <p class="mb-6 text-[15px]">Buen día estimado <strong class="text-gray-800 uppercase">{{
                                previewCliente
                            }}</strong>,</p>

                            <p class="mb-8 text-[15px]">
                                Por medio del presente le compartimos su complemento de pago<br>
                                correspondiente a las facturas referenciadas.
                            </p>

                            <!-- TABLA -->
                            <div class="w-full rounded-md overflow-hidden border border-gray-100">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr>
                                            <th
                                                class="bg-gray-100 text-gray-500 text-xs font-bold uppercase tracking-wider py-4 px-6 w-[30%]">
                                                FECHA</th>
                                            <th
                                                class="bg-gray-100 text-gray-500 text-xs font-bold uppercase tracking-wider py-4 px-6 w-[35%]">
                                                REFERENCIA</th>
                                            <th
                                                class="text-xs font-bold uppercase tracking-wider py-4 px-6 w-[35%] text-center border-l border-blue-100" style="background-color: #f0f4f8; color: #1b365d;">
                                                COMPLEMENTO</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="border-t border-gray-100">
                                            <td class="py-5 px-6 text-sm text-gray-600">{{ previewFecha }}</td>
                                            <td class="py-5 px-6 text-sm text-gray-600">{{ previewFolioSc }}</td>
                                            <td
                                                class="py-5 px-6 text-sm font-bold text-center border-l border-blue-100" style="background-color: #f4f7fb; color: #1b365d;">
                                                CP-{{ previewFolioComp }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <p class="text-[13px] text-gray-500 italic mt-8">* Adjunto a este correo encontrará el
                                archivo PDF
                                correspondiente a su documento fiscal.</p>
                        </div>

                        <!-- FOOTER DEL CORREO -->
                        <div class="px-10 pb-8 pt-8 border-t border-gray-100 text-center">
                            <p class="text-gray-700 text-[15px] font-medium mb-1">Atentamente,</p>
                            <p class="text-lg font-bold mb-4" style="color: #1b365d;">Cuentas por Pagar {{ nombreEmpresaEmisora
                            }}</p>
                            <p class="text-xs text-gray-400">Generado automáticamente por el Sistema {{
                                nombreEmpresaEmisora }}</p>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Pie del Modal -->
            <div class="bg-gray-100 px-8 py-5 flex justify-end gap-4 rounded-b-xl shrink-0 border-t border-gray-200">
                <button @click="cerrar"
                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-8 py-3 rounded-lg font-bold text-lg transition">
                    Cancelar
                </button>
                <button @click="enviarCorreos" :disabled="enviando || listaCorreos.length === 0"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-10 py-3 rounded-lg font-black text-lg transition shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center">
                    <svg v-if="enviando" class="animate-spin -ml-1 mr-3 h-6 w-6 text-white" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <svg v-else class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8">
                        </path>
                    </svg>
                    {{ enviando ? 'Enviando Documentos...' : 'Enviar Documentos' }}
                </button>
            </div>

        </div>
    </div>
</template>

<script>
import Swal from 'sweetalert2';
import axios from 'axios';
import Multiselect from 'vue-multiselect';
import 'vue-multiselect/dist/vue-multiselect.min.css';

export default {
    name: 'ModalEnviarCorreo',
    components: {
        Multiselect
    },
    props: {
        mostrar: { type: Boolean, default: false },
        ingreso: { type: Object, default: () => ({}) }
    },
    data() {
        return {
            listaCorreos: [],
            opcionesSugeridas: [],
            enviando: false,
            directorioCorreos: {
                'MANZANILLO': {
                    'COPSAYS': ['mferreiro@grupomla.com.mx'],
                    'ACUMEN FRUIT': ['conta05@grupomla.com.mx', 'kantonio@grupomla.com.mx'],
                    'SEASONXPRESS': ['alejandronp@seasonxpress.com', 'logistica@seasonxpress.com'],
                    'ALMACENADORA Y MAQUILAS': ['jreyes@grupovaca.com', 'GGARCIA@grupovaca.com', 'mmiranda@grupovaca.com'],
                    'PROMOTORA EN COMERCIO EXTERIOR RG': ['prom.comercioexterior@gmail.com', 'robles1827@hotmail.com'],
                    'FRESKOS IMPORT': ['finanzas@proebapack.mx', 'ventas@freskos.mx', 'aux_contableproeba@outlook.com', 'administracion@proebapack.mx', 'guillermobarajas@proebapack.com.mx', 'ivanbarajas017@gmail.com', 'administracion@freskos.mx']
                },
                'TIJUANA': {
                    'CARNICOS DM': ['direccionbpl@gmail.com', 'gerente1bpl@gmail.com', 'logisticabpl1@gmail.com', 'contabilidad1.bpl@gmail.com', 'vtasmaycentro.cdm@gmail.com'],
                    'CONSULTORES ADUANALES LP': ['patricia@lpina.com', 'direccionbpl@gmail.com', 'gerente1bpl@gmail.com', 'logisticabpl1@gmail.com', 'contabilidad1.bpl@gmail.com', 'vtasmaycentro.cdm@gmail.com'],
                    'MERCADO MK': ['Admon3@mercadomk.com', 'admon4@mercadomk.com', 'facturas.importacion@mercadomk.com', 'logistica2@mercadomk.com'],
                    'CONGELADORA DON PULPITO': ['donpulpitosadecv@gmail.com', 'ferlechuga@live.com.mx'],
                    'PRODUCTOS BGA': ['yrobledo@jfar.us', 'mgomez@pbga.mx', 'mgarza@pbga.mx', 'hherrera@jfar.us'],
                    'IDE FOODS': ['airizar@idefoods.com', 'operaciones2@idefoods.com', 'dgarcia@idefoods.com'],
                    'LA COSMOPOLITANA': ['irmin.alvarez@ck.com.mx', 'sandra.hernandez@ck.com.mx', 'nidia.quintero@ck.com.mx', 'sandra.padilla@ck.com.mx', 'gustavo.sanchez@ck.com.mx'],
                    'BO KWANG PRINTING': ['rubi@woori-usa.com', 'wooritrafico2@gmail.com', 'import@woori-usa.com', 'wooritraffico@gmail.com', 'wooriimport@gmail.com', 'trafico@woori-usa.com', 'import2@woori-usa.com'],
                    'MAS BODEGA Y LOGISTICA': ['auxadmon.tijuana@masbodega.com', 'luis.yances@iconn.com.mx', 'comprador.tijuana@iconn.com.mx', 'leslie.romero@iconn.com.mx', 'ximena.garcia@iconn.com.mx', 'alondra.ramirez@iconn.com.mx'],
                    'AQUARIUM SEAFOOD': ['exportaciones@aquariumseafood.com', 'cortespacificfoods@gmail.com'],
                    'TAOS ALIMENTOS': ['facturas@taos.mx'],
                    'G Y S MARKETING': ['Yadira.Rodriguez@gysmarketing.com', 'Denisse.Pujol@gysmarketing.com'],
                    'MVF': ['fgutierrez@venturafoods.com', 'gcrosbie@venturafoods.com', 'ggaribay@venturafoods.com', 'venturafoodsoperationsebill@venturafoods.com', 'fsanchez@venturafoods.com', 'laguilar@venturafoods.com', 'bvilchis@venturafoods.com', 'lmaldonado@venturafoods.com', 'rorozco@venturafoods.com'],
                    'CY ALIMENTOS': ['proveedores@cyalimentos.com', 'jcordova@cyalimentos.com', 'cuentasporpagar@cyalimentos.com'],
                    'ALIMENTOS SOLES': ['uxcompras@soles.com.mx', 'dcamargo@soles.com.mx', 'cmadrid@soles.com.mx'],
                    'COBRE DEL MAYO': ['maria.celaya@cobredelmayo.com', 'pedro.apodaca@cobredelmayo.com', 'nalleli.lopez@cobredelmayo.com'],
                    'TP LASER': ['Marlenis.Villatoro@transplace.com', 'Jonathan.Osoria@transplace.com', 'Jose.Vargas@transplace.com', 'Alejandra.Chapa@transplace.com'],
                    'CORTEZ SEA FOOD': ['contador@bsfoods.com.mx', 'director@bsfoods.com.mx'],
                    'ESTATE PRODUCE': ['oscar@estateproduce.com', 'david.gonzalez@estateproduce.com'],
                    'CITRUS GAME': ['hectormarcoff@citrusgame.com.mx', 'esmeralda@citrusgame.com.mx', 'facturas@citrusgame.com.mx'],
                    'VITCITRUS': ['pattyvitcitrus@gmail.com', 'renelajoya@hotmail.com', 'embarquesvitcitrus@gmail.com'],
                    'FOOD INTERNATIONAL BOARD OF TRADE': ['contabilidad@foodint.com'],
                    'PRODUCTOS Y ALIMENTOS GUADALAJARA': ['sandra.padilla@ck.com.mx', 'nidia.quintero@ck.com.mx', 'i.administrativo@goci.com.mx', 'irmin.alvarez@ck.com.mx', 'sandra.hernandez@ck.com.mx'],
                    'SONORA AGROPECUARIA': ['santiago.gonzalez@bachoco.net', 'jessica.navarro@bachoco.net', 'rebeca.diaz@bachoco.net'],
                    'COMPAÑIA MINERA LA PITALLA': ['guadalupe.sabori@heliostarmetals.com', 'humberto.leyva@heliostarmetals.com', 'Ana.Pablos@heliostarmetals.com', 'Recepcion.HMO@heliostarmetals.com', 'Guadalupe.Navarro@heliostarmetals.com'],
                    'MARCO ANTONIO COTA GAXIOLA': ['marcocota@outlook.com', 'itzelbarrloa28@hotmail.com'],
                    'FOOD SERVICE DE MEXICO': ['angmoral@foodservice.com.mx', 'rosaelva.hernandez@foodservice.com.mx', 'mayrymf@foodservice.com.mx', 'ernanda.juarez@foodservice.com.mx'],
                    'LABORATORIOS DE PRODUCTOS ECOLOGICOS NATURALM': ['denisse@highvibesbrands.com'],
                    'DISTRIBUIDORA TRES REGIONES': ['anacristinazunigab@gmail.com', 'distribuidoratresregiones@gmail.com'],
                    'COMERCIAL DE ALIMENTOS SANCHEZ': ['logistica@comercialdealimentossanchez.com', 'admon4@comercialdealimentossanchez.com'],
                    'LUIS ANTONIO ARAMBURO NAJAR': ['bodegademariscos_004@hotmail.com']
                },
                'MEXICALI': {
                    'TP LASER': ['zahira.alvarez@tplaser.com.mx'],
                    'IDE FOODS': ['airizar@idefoods.com', 'operaciones2@idefoods.com'],
                    'MERCADO MK': ['compras2@mercadomk.com', 'logistica2@mercadomk.com', 'logistica5@mercadomk.com', 'admon4@mercadomk.com', 'Admon3@mercadomk.com', 'facturas.importacion@mercadomk.com'],
                    'CONSULTORES ADUANALES LP': ['patricia@lpina.com'],
                    'GRUPO AUTOELECTRICO PERMOR': ['eramirezu@gmail.com', 'grupopermor_contabilidad@hotmail.com', 'autopro.alko@gmail.com'],
                    'ACUMEN FRUIT': ['administrativo11@grupomla.com.mx', 'conta04@grupomla.com.mx', 'administrativo15@grupomla.com.mx', 'pagosgdl@grupomla.com.mx'],
                    'ARACELI DEL PILAR GONZALEZ ACOSTA': ['patricia@lpina.com', 'atrevino@lpina.com', 'lpina3@lpina.com', 'administracion@lpina.com'],
                    'GYS MARKETING': ['Yadira.Rodriguez@gysmarketing.com', 'Denisse.Pujol@gysmarketing.com', 'Rigoberto.Sugich@gysmarketing.com'],
                    'BO KWANG PRINTING': ['rubi@woori-usa.com']
                },
                'LAREDO': {
                    'GSI CUMBERLAND DE MEXICO': ['Salvador.Rojas@agcocorp.com', 'ahuri.lopez@grainproteintech.com'],
                    'BO KWANG PRINTING': ['rubi@woori-usa.com'],
                    'CREMERIAS DE OCCIDENTE': ['cremeriasdeoccidente@hotmail.com', 'anibalruav@gmail.com'],
                    'ALIMENTOS SOLES': ['vosuna@soles.com.mx', 'Gustavo@soles.com.mx'],
                    'AGRICOLA DIANA LAURA': ['gerentegeneral@adlproduce.com.mx', 'pagos@adlproduce.com.mx'],
                    'CINTHYA VERONICA BERNAL LAMARQUE': ['robertoghenderson2009@hotmail.com', 'cbl90@hotmail.com'],
                    'MINAS DE ORO NACIONAL': ['ctasxpagar@minasdeoro.com', 'Flor.Perez@minasdeoro.com', 'Paola.Penunuri@minasdeoro.com', 'Karen.Perez@minasdeoro.com', 'Viridiana.Ozuna@minasdeoro.com'],
                    'COLOSTRO': ['juan@colostro.mx', 'jfheguertty@produceexports.mx'],
                    'SONORA AGROPECUARIA': ['santiago.gonzalez@bachoco.net', 'jessica.navarro@bachoco.net', 'rebeca.diaz@bachoco.net'],
                    'PRODUCTOS CARNICOS SANTA CECILIA': ['Aexport@santara.com.mx', 'Gerente.Operaciones@santara.com.mx', 'Compras@santara.com.mx', 'Pagos@pecuarias.com'],
                    'JAIME MAURICIO GODINEZ GUERRERO': ['adriana.mcc@advancers.com.mx'],
                    'DGD PRODUCCIONES': ['adriana.mcc@advancers.com.mx'],
                    'G Y S MARKETING': ['Denisse.Pujol@gysmarketing.com', 'Rigoberto.Sugich@gysmarketing.com'],
                    'DAVID GARCIA CALDERON': ['dgcalderon@hotmail.com'],
                    'LUZ LILIANA GARCIA LOPEZ': ['Comprasmty@elmariscal.mx', 'Lgonzalez@elmariscal.mx', 'jgonzalez@elmariscal.mx'],
                    'ALIMENTOS PROFUSA': ['exportacionimportacion@profusa.net', 'luis.galvan@profusa.net', 'tesoreria@profusa.com'],
                    'HECTOR EDUARDO DIAZ GARCIA': ['a.imperial@mtomove.com', 'operaciones@mtomove.com'],
                    'CARNES SELECTAS EL ENCANTO': ['nydialmp@gmail.com', 'admon.irancastillo@gmail.com'],
                    'MACSA DE LA SULTANA': ['Comprasmty@elmariscal.mx', 'jgonzalez@elmariscal.mx'],
                    'CARNES DON CARMELO': ['nydialmp@gmail.com', 'admon.irancastillo@gmail.com'],
                    'AFS LOGISTICA ALIMENTARIA': ['alimco@megared.net.mx', 'contabilidadalimco@hotmail.com', 'lfgg_fiscal@outlook.com'],
                    'LOAMONT': ['nydialmp@gmail.com', 'admon.irancastillo@gmail.com'],
                    'SIGMA FOODSERVICE COMERCIAL': ['facturasigma@alliax.com'],
                    'ALMACENADORA Y MAQUILAS': ['mmartinez@grupovaca.com', 'ggarcia@grupovaca.com'],
                    'SURTIDORA DEL BAJIO': ['yulissa.herrera@surtidoradelbajio.com'],
                    'CENTRO ABARROTERO DEL BAJIO': ['cgorozcog@cabqro.com', 'cmontesf@cabqro.com', 'gsubaldog@cabqro.com', 'mubaldog@cabqro.com'],
                    'XSANT': ['bhuerta@grupoaltex.com', 'cespinozag@grupoaltex.com', 'acomprasxst@grupoaltex.com'],
                    'SURTIDORA ABARROTERA DE GUADALAJARA': ['alejandra.callejas@surtidoradelbajio.com', 'yulissa.herrera@surtidoradelbajio.com', 'pablo.arenas@surtidoradelbajio.com', 'aida.gamiz@sag.com.mx', 'proveedores.importaciones@surtidoradelbajio.com'],
                    'COMERCIALIZADORA SUAREZ VARGAS': ['comersuavar@prodigy.net.mx'],
                    'VITCITRUS': ['pattyvitcitrus@gmail.com', 'embarquesvitcitrus@gmail.com', 'renelajoya@hotmail.com'],
                    'AGROPECUARIA JS': ['admon.irancastillo@gmail.com'],
                    'MODULA STORAGE SOLUTIONS': ['karina.laja@salab.com.mx'],
                    'MVF': ['fgutierrez@venturafoods.com', 'gcrosbie@venturafoods.com', 'ggaribay@venturafoods.com', 'venturafoodsoperationsebill@venturafoods.com', 'fsanchez@venturafoods.com', 'laguilar@venturafoods.com', 'bvilchis@venturafoods.com', 'lmaldonado@venturafoods.com', 'rorozco@venturafoods.com'],
                    'GUILLERMO ACEVES CASILLAS': ['gacevescasillas@outlook.com', 'a.moreno@asesoresmm.com.mx', 'lcardenas@arlifoods.com', 'mweber@arlifoods.com', 'e.dominguez@asesoresmm.com.mx', 'malegarcia@arlifoods.com'],
                    'PPP FOODS COMMERCIAL': ['nydialmp@gmail.com', 'admon.irancastillo@gmail.com'],
                    'PRODUCTOS BGA': ['yrobledo@jfar.us', 'mgomez@pbga.mx', 'mgarza@pbga.mx', 'hherrera@jfar.us'],
                    'REPRESENTACIONES MINERAS E INDUSTRIALES': ['gerardo.mena@reminsaco.mx', 'administracion@reminsaco.mx'],
                    'CARNES SELECTAS EL ENCANTO': ['nydialmp@gmail.com', 'admon.irancastillo@gmail.com'],
                    'COMERCIALIZADORA SUAREZ VARGAS': ['comersuavar@prodigy.net.mx'],
                    'CONSTRUCCIONES DROBER MEXICANO': ['karina.laja@salab.com.mx'],
                    'PRODUCTOS ORTOPEDICOS HRR': ['ricardo.hernandez@iowabrace.com.mx', 'hilda.larrazabal@iowabrace.com.mx'],
                    'SIERRA SERVICIOS DE PERFORACION ': ['karina.laja@salab.com.mx'],
                    'GIANT FOOD SERVICE': ['anajera@giantfs.mx', 'sfierro@giantfs.mx', 'analista@giantfs.mx', 'anajera@giantfs.mx;'],
                    'MADRID FRANCO Y ASOCIADOS': ['mfasa36@hotmail.com', 'direccion@logisticasim.com.mx', 'comercializacion@logisticasim.com.mx', 'transportes@logisticasim.com.mx'],
                    'PRODUCTOS SANOS DEL VALLE': ['operaciones@vidadignaproduce.com', 'pagos@vidadignaproduce.com']
                },
                'NOGALES': {
                    'CITRUS GAME': ['monica@citrusgame.com.mx', 'violeta@flavorkingfarms.com', 'vannesapaco@citrusgame.com.mx', 'esmeralda@citrusgame.com.mx', 'compras@citrusgame.com.mx', 'eliza@flavorkingfarms.com', 'joelencinas@citrusgame.com.mx', 'logistica@citrusgame.com.mx', 'citrusgame@gmail.com', 'fernanda@citrusgame.com.mx', 'transportes@citrusgame.com.mx', 'rociovidal@citrusgame.com.mx'],
                    'ACUMEN FRUIT': ['mportaciones@grupomla.com.mx', 'jramirez@grupomla.com.mx', 'pagosgdl@grupomla.com.mx', 'conta04@grupomla.com.mx', 'administrativo15@grupomla.com.mx', 'administrativo11@grupomla.com.mx', 'importaciones3@grupomla.com.mx', 'jfp@grupomla.com.mx', 'karzate@grupomla.com.mx', 'luceromeza@grupomla.com.mx', 'conta02@grupomla.com.mx', 'conta07@grupomla.com.mx', 'acruz@grupomla.com.mx', 'conta05@grupomla.com.mx', 'edaena@grupomla.com.mx', 'administrativo5@grupomla.com.mx', 'admin01@grupomla.com.mx', 'valente@grupomla.com.mx', 'importadoras4@grupomla.com.mx', 'conta09@grupomla.com.mx', 'chequesq185@grupomla.com.mx', 'contaimp@grupomla.com.mx', 'jornelas@grupomla.com.mx', 'kantonio@grupomla.com.mx'],
                    'CIPRIA MINERALIA': ['mario.barreras@cobredelmayo.com', 'rodolfo.rodriguez@cobredelmayo.com', 'jose.felix@cipriamineralia.com', 'comercio.exterior@cipriamineralia.com', 'maria.celaya@cobredelmayo.com', 'thomas.felix@cobredelmayo.com'],
                    'SERVICIOS ADMINISTRATIVOS DEL YAQUI': ['gkuraica@horticola.com.mx', 'sgarcia@horticola.com.mx', 'rcastro@horticola.com.mx', 'icarrillo@horticola.com.mx', 'facturacion@horticola.com.mx', 'auxadmon@horticola.com.mx', 'contabilidadsaysa@horticola.com.mx'],
                    'GUILLERMO ACEVES CASILLAS': ['e.dominguez@asesoresmm.com.mx', 'gacevescasillas@outlook.com', 'a.moreno@asesoresmm.com.mx', 'lcardenas@arlifoods.com', 'mweber@arlifoods.com'],
                    'COLOSTRO': ['administracion@colostro.mx'],
                    'PRODUCTOS Y ALIMENTOS GUADALAJARA': ['sandra.padilla@ck.com.mx'],
                    'MINAS DE ORO NACIONAL': ['Israel.Monge@alamosgold.com', 'Flor.Perez@minasdeoro.com'],
                    'ACUICOLA LA FILIPINA': ['walterhubbard@gmail.com', 'rodolfopineda.lafilipina@hotmail.com'],
                    'VIÑEDOS MARIBEL': ['mrleonoficina@hotmail.com'],
                    'CARNE DE JAIBA': ['carnedejaiba@gmail.com'],
                    'AGRICOLA GALBA': ['exportacionimportacion@profusa.net', 'luis.galvan@profusa.net'],
                    'RASTROS Y FRIGORIFICOS DE CULIACAN': ['admon.irancastillo@gmail.com'],
                    'SURTIDORA ABARROTERA DE GUADALAJARA': ['aida.gamiz@sag.com.mx'],
                    'SURTIDORA DEL BAJIO': ['Yulissa.herrera@surtidoradelbajio.com'],
                    'CARNES SELECTAS EL ENCANTO': ['nydialmp@gmail.com', 'admon.irancastillo@gmail.com'],
                    'LEONALI': ['ivan.perez@leonali.com', 'Alejandro.marti@leonali.com', 'ariadna.mani@leonali.com'],
                    'RYC ALIMENTOS': ['diana.axcal@bachoco.net'],
                    'MACSA DE LA SULTANA': ['jgonzalez@elmariscal.mx', 'Comprasmty@elmariscal.mx'],
                    'FIVETACTICS': ['mirna.lopez@intactics.com'],
                    'PRODUCE IMPORT SOLUTIONS': ['monserrat@xtrategas.com', 'compras@produceimportsolutions.com'],
                    'AGROINDUSTRIA DEL BACANORA': ['labacanorera@gmail.com'],
                    'PROMOTORA DE MERCADOS RA': ['nelly.galaviz@altosano.com', 'pagos@alimsa.com.mx'],
                    'AGRICOLA DUKE': ['victor_mjacoboc@hotmail.com'],
                    'GRUPO MULTIDICONA': ['importaciones@gmd-ventas.com', 'finanzas2@gmd1.com'],
                    'BERRIES PARADISE': ['igarcia@berriesparadise.com.mx', 'mvalencia@berriesparadise.com.mx', 'lquintero@berriesparadise.com.mx', 'cmedina@berriesparadise.com.mx'],
                    'PROALIMENTOS DEL YAQUI': ['gkuraica@horticola.com.mx', 'sgarcia@horticola.com.mx', 'rcastro@horticola.com.mx', 'icarrillo@horticola.com.mx'],
                    'ALIMENTOS SOLES': ['amolina@soles.com.mx', 'cmadrid@soles.com.mx', 'export@soles.com.mx', 'exportsoles@gmail.com', 'elizabeth.pb.soles@gmail.com', 'LGUTIERREZ@SOLES.COM.MX', 'comprasjr@soles.com.mx', 'auxcamaron@soles.com.mx', 'logisticacamaron@soles.com.mx', 'erivez53@gmail.com'],
                    'YOREME CORTES Y PROCESOS': ['santiago@yoreme.com'],
                    'ARROW COMPONENTS MEXICO': ['Raymundo.Carrasco@arrow.com', 'ernesto.velasco@arrow.com', 'nicolas.castillo@arrow.com', 'Afra.Estevis@arrow.com', 'Ivan.Deolarte@arrow.com', 'Raul.Fariasgarcia@arrow.com'],
                    'G Y S MARKETING': ['Denisse.Pujol@gysmarketing.com', 'Marcela.Vasquez@gysmarketing.com', 'Rigoberto.Sugich@gysmarketing.com', 'Yadira.Rodriguez@gysmarketing.com', 'sgonzalez@gysmarketing.com'],
                    'MARTIN LEON LIZARRAGA': ['mrleonoficina@hotmail.com', 'rosariogs-mll@hotmail.com'],
                    'COBRE DEL MAYO': ['maria.celaya@cobredelmayo.com', 'nalleli.lopez@cobredelmayo.com', 'rodolfo.rodriguez@cobredelmayo.com', 'jose.felix@cipriamineralia.com', 'thomas.felix@cobredelmayo.com'],
                    'JARUSO IMPO': ['jarusoadmon@gmail.com', 'jorgerubio5@hotmail.com'],
                    'NEGOCIOS AGROPECUARIOS SANTA MARIA': ['nasm97@hotmail.com'],
                    'PROMOTORA COMERCIAL ALPRO': ['lorenia.robles@bachoco.net', 'maria.padilla@bachoco.net', 'karina.enriquez@bachoco.net', 'jazive.gracia@bachoco.net'],
                    'TAOS ALIMENTOS': ['atapia@taos.mx', 'otapia@taos.mx', 'facturas@taos.mx', 'lsanteliz@taos.mx', 'logistica@taos.mx', 'Sergio@foodlinkcorp.com', 'alondra@foodlinkcorp.com'],
                    'CINTHYA VERONICA BERNAL LAMARQUE': ['cbl90@hotmail.com', 'robertoghenderson2009@hotmail.com'],
                    'GSI CUMBERLAND DE MEXICO': ['ahuri.lopez@grainproteintech.com'],
                    'QUESOS Y QUESOS': ['nlohr@quesosyquesos.com.mx', 'bcaballero@quesosyquesos.com.mx'],
                    'AGRICOLA DIANA LAURA': ['Cuentasporcobrar@adlproduce.com.mx', 'contabilidad@adlproduce.com.mx'],
                    'ANABEL GALAVIZ ESCALANTE': ['DISTRIBUIDORA.ROGA@hotmail.com', 'emmanuelyanabel@hotmail.com'],
                    'MULTIGRANOS DEL YAQUI': ['gkuraica@horticola.com.mx', 'sgarcia@horticola.com.mx', 'rcastro@horticola.com.mx', 'icarrillo@horticola.com.mx', 'facturacion@horticola.com.mx'],
                    'SONORA AGROPECUARIA': ['jesus.castrejon@bachoco.net', 'yesenia.toral@bachoco.net', 'maria.evaristo@bachoco.net', 'jorge.garcia@bachoco.net'],
                    'BACHOCO': ['jesus.castrejon@bachoco.net', 'yesenia.toral@bachoco.net', 'maria.evaristo@bachoco.net', 'jorge.garcia@bachoco.net'],
                    'LUIS FERNANDO ROMERO DAVILA': ['piratamex@yahoo.com', 'aidaleyva@hotmail.com'],
                    'DISTRIBUIDORA J&I': ['robertobarreras@joritafood.com.com'],
                    'SILVA CAMACHO': ['silvacamacho1@hotmail.com'],
                    'DUKE FRESH PROCESS': ['marcob@bourncom.com', 'contabilidad@bctuscustoms.com', 'Accounting@bctuscustoms.com'],
                    'MONTECRISTO FRESH LLC': ['marcob@bourncom.com', 'contabilidad@bctuscustoms.com', 'Accounting@bctuscustoms.com'],
                    'FRUTAS RCH': ['robertobarreras@joritafood.com'],
                    'CORTEZ SEA FOOD': ['cortezseafood@live.com.mx'],
                    'CY ALIMENTOS': ['cuentasporpagar@cyalimentos.com', 'comprascyalimentos@gmail.com', 'proveedores@cyalimentos.com'],
                    'EMMANUEL JACOBO CORDOVA YEPSON': ['cuentasporpagar@cyalimentos.com', 'comprascyalimentos@gmail.com', 'proveedores@cyalimentos.com'],
                    'SIGMA FOODSERVICE COMERCIAL': ['facturasigma@alliax.com', 'soportesigmaareqsat@sigma-limentos.com', 'mbriones@sigma-alimentos.com', 'jgamez@sigma-alimentos.com', 'oortiz@sigma-alimentos.com'],
                    'SUNNY BROS LLC': ['teyofoods2@gmail.com', 'exportsoles@gmail.com', 'EXPORT@soles.com.m'],
                    'RN DEL PACIFICO': ['nava_ueass@hotmail.com', 'facturasrndelpacifico2025@hotmail.com', 'iemarson@hotmail.com', 'gvega.chayrez@gmail.com', 'gvega_chayrez@hotmail.com']
                }
            }
        }
    },
    computed: {
        previewCliente() {
            return (this.ingreso && this.ingreso.cliente) ? this.ingreso.cliente : 'Estimado Cliente';
        },
        previewFolioSc() {
            return (this.ingreso && this.ingreso.folio_sc) ? this.ingreso.folio_sc : 'N/A';
        },
        previewFolioComp() {
            return (this.ingreso && this.ingreso.folio_complemento) ? this.ingreso.folio_complemento : 'Pendiente';
        },
        previewFecha() {
            return (this.ingreso && this.ingreso.fecha) ? this.ingreso.fecha : 'N/A';
        },
        previewMonto() {
            return (this.ingreso && this.ingreso.monto_deposito) ? this.ingreso.monto_deposito : 0;
        },
        previewNombreArchivo() {
            return (this.ingreso && this.ingreso.folio_complemento) ? this.ingreso.folio_complemento : 'folio';
        },
        nombreEmpresaEmisora() {
            if (!this.ingreso || !this.ingreso.sucursal_origen) {
                return 'InTactics';
            }
            const sucursal = String(this.ingreso.sucursal_origen).toUpperCase();
            if (sucursal.includes('TRANSPORTACTICS')) {
                return 'Transportactics';
            }
            if (sucursal.includes('INTSHIPPERTS')) {
                return 'INTSHIPPERTS';
            }
            return 'InTactics';
        }
    },
    watch: {
        tiposComprobanteArray: {
            deep: true,
            handler(newVal, oldVal) {
                // Solo lo encendemos si el usuario hizo un cambio real (evitamos que se encienda al cargar el modal)
                if (oldVal && oldVal.length !== undefined) {
                    this.necesitaRecalcular = true;
                }
            }
        },
        mostrar: {
            immediate: true,
            handler(val) {
                if (val) {
                    this.listaCorreos = [];
                    this.opcionesSugeridas = [];
                    this.enviando = false;

                    this.$nextTick(() => {
                        this.precargarCorreosCliente();
                    });
                }
            }
        },
        ingreso: {
            deep: true,
            handler() {
                if (this.mostrar) {
                    this.precargarCorreosCliente();
                }
            }
        }
    },
    methods: {
        precargarCorreosCliente() {
            // Si no hay ingreso o no hay sucursal, detenemos la ejecución
            if (!this.ingreso || !this.ingreso.sucursal_origen) {
                return;
            }

            // 1. Limpiamos la sucursal (Detectará perfectamente "NOGALES IMPO")
            const sucursal = String(this.ingreso.sucursal_origen).toUpperCase();

            // 2. Extraemos el nombre del cliente resolviendo la relación de la BD (cliente_id)
            let nombreCliente = '';
            if (this.ingreso.cliente && typeof this.ingreso.cliente === 'object') {
                // Si Laravel manda el objeto de la relación por el cliente_id
                nombreCliente = String(this.ingreso.cliente.nombre || '').toUpperCase().trim();
            } else if (this.ingreso.cliente) {
                // Si Laravel ya manda el string procesado
                nombreCliente = String(this.ingreso.cliente).toUpperCase().trim();
            }

            if (!nombreCliente) {
                return;
            }

            let zonaDetectada = null;

            // 3. Detección Inteligente a prueba de sufijos como " IMPO" o " EXPO"
            if (sucursal.includes('MANZANILLO') || sucursal.includes('INTSHIPPERT') || sucursal.includes('ZLO')) {
                zonaDetectada = 'MANZANILLO';
            } else if (sucursal.includes('TIJUANA') || sucursal.includes('TIJ')) {
                zonaDetectada = 'TIJUANA';
            } else if (sucursal.includes('MEXICALI') || sucursal.includes('MXL')) {
                zonaDetectada = 'MEXICALI';
            } else if (sucursal.includes('LAREDO') || sucursal.includes('NL')) {
                zonaDetectada = 'LAREDO';
            } else if (sucursal.includes('NOGALES') || sucursal.includes('NOG')) {
                zonaDetectada = 'NOGALES';
            }

            if (!zonaDetectada) {
                return;
            }

            const clientesZona = this.directorioCorreos[zonaDetectada];
            if (!clientesZona) {
                return;
            }

            // 4. Buscamos al cliente en el diccionario
            for (const [claveDiccionario, correosArray] of Object.entries(clientesZona)) {
                if (nombreCliente.includes(claveDiccionario) || claveDiccionario.includes(nombreCliente)) {
                    // Se limpian los correos pasándolos todos a minúsculas
                    const correosLimpios = correosArray.map(email => String(email).toLowerCase().trim());

                    // Asignación reactiva a las listas
                    this.opcionesSugeridas = [...correosLimpios];
                    this.listaCorreos = [...correosLimpios];
                    break;
                }
            }
        },
        agregarCorreo(nuevoCorreo) {
            const email = String(nuevoCorreo).trim().toLowerCase();
            const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (!regex.test(email)) {
                Swal.fire('Correo inválido', `"${email}" no es un formato válido.`, 'warning');
                return;
            }

            if (!this.opcionesSugeridas.includes(email)) {
                this.opcionesSugeridas.push(email);
            }

            if (!this.listaCorreos.includes(email)) {
                this.listaCorreos.push(email);
            }
        },
        async enviarCorreos() {
            this.enviando = true;
            try {
                const response = await axios.post(`/ingresos-conciliados/${this.ingreso.id}/complemento/enviar-correo`, {
                    correos: this.listaCorreos
                });

                if (response.data.success) {
                    Swal.fire('¡Enviado!', response.data.message, 'success');
                    this.cerrar();
                }
            } catch (error) {
                let msj = 'Error al enviar los correos.';
                if (error.response && error.response.data && error.response.data.error) {
                    msj = error.response.data.error;
                }

                Swal.fire('Error', msj, 'error');
            } finally {
                this.enviando = false;
            }
        },
        cerrar() {
            this.$emit('cerrar');
        }
    }
}
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 10px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 8px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 8px;
}

/* Ajustes para hacer más grande y legible el multiselect internamente */
:deep(.multiselect__tags) {
    min-height: 50px;
    padding-top: 12px;
    border-radius: 8px;
}

:deep(.multiselect__input) {
    font-size: 16px;
}

:deep(.multiselect__tag) {
    font-size: 14px;
    padding: 6px 28px 6px 12px;
    margin-bottom: 8px;
    background-color: #2A3A4D;
}

:deep(.multiselect__tag-icon) {
    line-height: 28px;
}

:deep(.multiselect__tag-icon:after) {
    color: #fff;
}

:deep(.multiselect__tag-icon:hover) {
    background: #e53e3e;
}
</style>