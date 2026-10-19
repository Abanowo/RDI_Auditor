<template>
  <div
    v-if="show"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="gpc-preview-titulo"
  >
    <!-- Fondo -->
    <div class="absolute inset-0 bg-gray-900 bg-opacity-60" @click="cerrar"></div>

    <!-- Ventana -->
    <div class="relative bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] flex flex-col">

      <!-- Encabezado -->
      <div class="flex items-start justify-between px-6 py-4 border-b border-gray-200">
        <div>
          <h2 id="gpc-preview-titulo" class="text-lg font-bold text-blue-900">Revisar envío al GPC</h2>
          <p v-if="operacion" class="text-sm text-gray-600 mt-0.5">
            Pedimento <span class="font-semibold text-gray-800">{{ operacion.pedimento }}</span>
            <span v-if="operacion.cliente"> de {{ operacion.cliente }}</span>
          </p>
        </div>
        <button
          type="button"
          class="text-gray-400 hover:text-gray-700 rounded p-1 focus:outline-none focus:ring-2 focus:ring-blue-500"
          :disabled="sending"
          aria-label="Cerrar"
          @click="cerrar"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Cuerpo -->
      <div class="flex-1 overflow-y-auto px-6 py-4">

        <!-- Cargando -->
        <div v-if="loading" class="flex flex-col items-center justify-center py-16 text-gray-600">
          <svg class="h-8 w-8 animate-spin text-blue-700 mb-3" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          <p class="font-semibold">Leyendo el expediente del pedimento</p>
          <p class="text-sm text-gray-500 mt-1">Se revisan facturas, XML y hojas de apoyo. Puede tardar un par de minutos.</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          <p class="font-semibold mb-1">No se pudo armar la vista previa</p>
          <p>{{ error }}</p>
          <ul v-if="avisos.length" class="list-disc ml-5 mt-2">
            <li v-for="(aviso, i) in avisos" :key="'err-aviso-' + i">{{ aviso }}</li>
          </ul>
        </div>

        <!-- Contenido -->
        <div v-else-if="filas.length">

          <!-- Avisos del proceso -->
          <div v-if="avisos.length" class="rounded-md border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900 mb-4">
            <p class="font-semibold mb-1">Revisa antes de enviar</p>
            <ul class="list-disc ml-5">
              <li v-for="(aviso, i) in avisos" :key="'aviso-' + i">{{ aviso }}</li>
            </ul>
          </div>

          <!-- Resumen -->
          <div class="flex flex-wrap items-stretch gap-3 mb-5">
            <div class="border border-gray-200 rounded-md px-4 py-2">
              <p class="text-xs text-gray-500">Conceptos con monto</p>
              <p class="text-xl font-bold text-gray-900">{{ resumen.con_monto }}<span class="text-sm font-normal text-gray-500"> de {{ resumen.registros }}</span></p>
            </div>
            <div
              v-for="(total, moneda) in resumen.totales"
              :key="'total-' + moneda"
              class="border border-gray-200 rounded-md px-4 py-2"
            >
              <p class="text-xs text-gray-500">Total en {{ moneda }}</p>
              <p class="text-xl font-bold text-gray-900">{{ formatoMonto(total, moneda) }}</p>
            </div>
            <div
              class="rounded-md px-4 py-2 border"
              :class="conteoAlertas.error ? 'border-red-300 bg-red-50' : (conteoAlertas.aviso ? 'border-yellow-300 bg-yellow-50' : 'border-green-300 bg-green-50')"
            >
              <p class="text-xs text-gray-600">Observaciones</p>
              <p class="text-sm font-semibold mt-1" :class="conteoAlertas.error ? 'text-red-800' : (conteoAlertas.aviso ? 'text-yellow-900' : 'text-green-800')">
                <span v-if="!conteoAlertas.error && !conteoAlertas.aviso">Sin observaciones</span>
                <span v-else>
                  <span v-if="conteoAlertas.error">{{ conteoAlertas.error }} por corregir</span>
                  <span v-if="conteoAlertas.error && conteoAlertas.aviso"> y </span>
                  <span v-if="conteoAlertas.aviso">{{ conteoAlertas.aviso }} por revisar</span>
                </span>
              </p>
            </div>
          </div>

          <!-- Tablas por origen -->
          <section v-for="grupo in grupos" :key="grupo.origen" class="mb-6">
            <h3 class="text-sm font-bold text-gray-800 mb-2">
              {{ grupo.origen }}
              <span class="font-normal text-gray-500">({{ grupo.filas.length }})</span>
            </h3>

            <div class="overflow-x-auto border border-gray-200 rounded-md">
              <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                  <tr>
                    <th class="px-3 py-2 font-semibold">Concepto</th>
                    <th class="px-3 py-2 font-semibold">Proveedor</th>
                    <th v-if="grupo.conFactura" class="px-3 py-2 font-semibold">Factura</th>
                    <th v-if="grupo.conContenedor" class="px-3 py-2 font-semibold">Contenedor</th>
                    <th class="px-3 py-2 font-semibold">Fecha</th>
                    <th class="px-3 py-2 font-semibold text-right">Monto</th>
                    <th v-if="grupo.conLlc" class="px-3 py-2 font-semibold text-right">Monto LLC</th>
                    <th class="px-3 py-2 font-semibold">Observaciones</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(fila, idx) in grupo.filas"
                    :key="grupo.origen + '-' + idx"
                    class="border-t border-gray-100"
                    :class="claseFila(fila)"
                  >
                    <td class="px-3 py-2 font-medium text-gray-900 whitespace-nowrap">{{ fila.concepto || 'Sin concepto' }}</td>
                    <td class="px-3 py-2 text-gray-700">{{ fila.proveedor || '—' }}</td>
                    <td v-if="grupo.conFactura" class="px-3 py-2 text-gray-700 whitespace-nowrap">{{ fila.factura || '—' }}</td>
                    <td v-if="grupo.conContenedor" class="px-3 py-2 text-gray-700 whitespace-pre-line">{{ fila.contenedor || '—' }}</td>
                    <td class="px-3 py-2 text-gray-700 whitespace-nowrap">{{ fila.fecha || '—' }}</td>
                    <td class="px-3 py-2 text-right font-semibold whitespace-nowrap" :class="fila.monto ? 'text-gray-900' : 'text-gray-400'">
                      {{ fila.monto ? formatoMonto(fila.monto, fila.moneda) : 'Sin monto' }}
                    </td>
                    <td v-if="grupo.conLlc" class="px-3 py-2 text-right whitespace-nowrap text-gray-700">
                      {{ fila.monto_llc ? formatoMonto(fila.monto_llc, 'USD') : '—' }}
                    </td>
                    <td class="px-3 py-2">
                      <span
                        v-for="(obs, o) in fila.observaciones"
                        :key="'obs-' + o"
                        class="inline-block rounded px-1.5 py-0.5 text-xs mr-1 mb-0.5"
                        :class="claseObservacion(obs.tipo)"
                      >{{ obs.texto }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <!-- Vacío -->
        <div v-else class="py-16 text-center text-gray-600">
          <p class="font-semibold">No hay conceptos para enviar</p>
          <p class="text-sm text-gray-500 mt-1">Revisa que el expediente del pedimento tenga sus facturas cargadas.</p>
        </div>
      </div>

      <!-- Pie -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg">
        <label v-if="puedeRevisar" class="flex items-center text-sm text-gray-800 cursor-pointer select-none">
          <input
            v-model="revisado"
            type="checkbox"
            class="h-4 w-4 rounded border-gray-300 text-blue-700 focus:ring-blue-500 mr-2"
            :disabled="sending"
          />
          Revisé los conceptos y montos
          <span v-if="conteoAlertas.error" class="ml-1 text-red-700">(hay {{ conteoAlertas.error }} por corregir)</span>
        </label>
        <span v-else></span>

        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="px-4 py-2 rounded border border-gray-300 bg-white text-gray-800 font-semibold hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
            :disabled="sending"
            @click="cerrar"
          >
            Cancelar
          </button>
          <button
            v-if="puedeRevisar"
            type="button"
            class="px-4 py-2 rounded font-semibold text-white flex items-center focus:outline-none focus:ring-2 focus:ring-green-500"
            :class="puedeEnviar ? 'bg-green-600 hover:bg-green-700' : 'bg-green-300 cursor-not-allowed'"
            :disabled="!puedeEnviar"
            @click="confirmar"
          >
            <svg v-if="sending" class="h-4 w-4 animate-spin mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            {{ sending ? 'Enviando al GPC…' : 'Enviar al GPC' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
const DEBUG_GPC = true;
export default {
  name: "GpcPreviewModal",

  props: {
    show: { type: Boolean, default: false },
    operacion: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    sending: { type: Boolean, default: false },
    // Respuesta de /auditoria/llenar-gpc-directo/vista-previa
    preview: { type: Object, default: null },
    error: { type: String, default: "" },
  },

  data() {
    return {
      revisado: false,
    };
  },

  computed: {
    avisos() {
      return (this.preview && this.preview.avisos) || [];
    },

    resumen() {
      return (this.preview && this.preview.resumen) || { registros: 0, con_monto: 0, totales: {} };
    },

    // Cuántas veces aparece cada "concepto base + monto" dentro del mismo origen
    repetidos() {
      const conteo = {};
      ((this.preview && this.preview.filas) || []).forEach((fila) => {
        if (!fila.monto) {
          return;
        }
        const llave = this.llaveRepetido(fila);
        conteo[llave] = (conteo[llave] || 0) + 1;
      });
      return conteo;
    },

    filas() {
      const filas = (this.preview && this.preview.filas) || [];
      return filas.map((fila) => Object.assign({}, fila, { observaciones: this.observacionesDe(fila) }));
    },

    grupos() {
      const orden = [];
      const mapa = {};

      this.filas.forEach((fila) => {
        if (!mapa[fila.origen]) {
          mapa[fila.origen] = { origen: fila.origen, filas: [], conFactura: false, conContenedor: false, conLlc: false };
          orden.push(fila.origen);
        }
        const grupo = mapa[fila.origen];
        grupo.filas.push(fila);
        if (fila.factura) {
          grupo.conFactura = true;
        }
        if (fila.canal === "alman" || fila.canal === "traslado") {
          grupo.conContenedor = true;
        }
        if (fila.monto_llc) {
          grupo.conLlc = true;
        }
      });

      return orden.map((origen) => mapa[origen]);
    },

    conteoAlertas() {
      let error = 0;
      let aviso = 0;
      this.filas.forEach((fila) => {
        if (fila.observaciones.some((o) => o.tipo === "error")) {
          error++;
        } else if (fila.observaciones.some((o) => o.tipo === "aviso")) {
          aviso++;
        }
      });
      return { error, aviso };
    },

    puedeRevisar() {
      return !this.loading && !this.error && this.resumen.con_monto > 0 && !!(this.preview && this.preview.token);
    },

    puedeEnviar() {
      return this.puedeRevisar && this.revisado && !this.sending;
    },
  },

  watch: {
    show(visible) {
      this.revisado = false;
      if (visible) {
        document.addEventListener("keydown", this.alPresionarTecla);
      } else {
        document.removeEventListener("keydown", this.alPresionarTecla);
      }
      if (DEBUG_GPC) {
        console.log("[GPC modal] show =", visible, "| operación:", this.operacion && this.operacion.pedimento);
      }
    },

    preview(valor) {
      this.revisado = false;
      if (DEBUG_GPC && valor) {
        this.validarPreview(valor);
      }
    },

    error(valor) {
      if (DEBUG_GPC && valor) {
        console.warn("[GPC modal] Se muestra el error:", valor);
      }
    },

    // Cuando la casilla se marca, explica por qué el botón sigue deshabilitado (si lo está)
    revisado(valor) {
      if (DEBUG_GPC && valor && !this.puedeEnviar) {
        console.warn("[GPC modal] La casilla está marcada pero 'Enviar al GPC' sigue deshabilitado:", this.motivosNoEnviar());
      }
    },
  },

  mounted() {
    if (DEBUG_GPC) console.log("[GPC modal] Componente montado. Props:", JSON.parse(JSON.stringify(this.$props)));
  },

  beforeDestroy() {
    document.removeEventListener("keydown", this.alPresionarTecla);
  },

  methods: {
    alPresionarTecla(evento) {
      if (evento.key === "Escape") {
        this.cerrar();
      }
    },

    cerrar() {
      if (this.sending) {
        return;
      }
      this.$emit("close");
    },

    confirmar() {
      if (!this.puedeEnviar) {
        if (DEBUG_GPC) {
          console.warn("[GPC modal] Clic en 'Enviar al GPC' sin poder enviar:", this.motivosNoEnviar());
        }
        return;
      }
      if (DEBUG_GPC) {
        console.log("[GPC modal] Confirmación del usuario; se emite 'confirm'.");
      }
      this.$emit("confirm");
    },

    formatoMonto(valor, moneda) {
      const numero = Number(valor);
      if (isNaN(numero)) {
        return valor;
      }
      const texto = new Intl.NumberFormat("es-MX", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(numero);
      return "$" + texto + " " + (moneda || "MXN");
    },

    conceptoBase(concepto) {
      return String(concepto || "")
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/\s*\d+$/, "")
        .trim();
    },

    llaveRepetido(fila) {
      return fila.origen + "|" + this.conceptoBase(fila.concepto) + "|" + Number(fila.monto).toFixed(2);
    },

    // Reglas de integridad de cada renglón
    observacionesDe(fila) {
      const obs = [];
      const tieneMonto = fila.monto !== null && fila.monto !== undefined && Number(fila.monto) > 0;

      if (!tieneMonto) {
        if (fila.canal === "multi") {
          obs.push({ tipo: "info", texto: "Renglón de plantilla" });
        } else {
          obs.push({ tipo: "error", texto: "Sin monto" });
        }
        return obs;
      }

      if (String(fila.pedimento || "").indexOf("DESC_") === 0) {
        obs.push({ tipo: "error", texto: "Contenedor sin pedimento en la BD" });
      }
      if (!fila.proveedor || fila.proveedor === "N/A") {
        obs.push({ tipo: "aviso", texto: "Sin proveedor" });
      }
      if (!fila.fecha) {
        obs.push({ tipo: "aviso", texto: "Sin fecha" });
      }
      if (fila.moneda === "USD") {
        obs.push({ tipo: "aviso", texto: "En dólares" });
      }
      if ((this.repetidos[this.llaveRepetido(fila)] || 0) > 1) {
        obs.push({ tipo: "aviso", texto: "Monto repetido en el mismo concepto" });
      }

      return obs;
    },

    claseFila(fila) {
      if (fila.observaciones.some((o) => o.tipo === "error")) {
        return "bg-red-50";
      }
      if (fila.observaciones.some((o) => o.tipo === "aviso")) {
        return "bg-yellow-50";
      }
      return "";
    },

    claseObservacion(tipo) {
      if (tipo === "error") {
        return "bg-red-100 text-red-800";
      }
      if (tipo === "aviso") {
        return "bg-yellow-100 text-yellow-900";
      }
      return "bg-gray-100 text-gray-600";
    },

    validarPreview(preview) {
      console.groupCollapsed("[GPC modal] Datos recibidos para la ventana");
      console.log("preview:", JSON.parse(JSON.stringify(preview)));

      if (!Array.isArray(preview.filas)) {
        console.error("[GPC modal] 'filas' no es un arreglo:", preview.filas);
      } else {
        const camposRequeridos = ["origen", "concepto", "monto", "moneda", "canal"];
        preview.filas.forEach((fila, i) => {
          const faltantes = camposRequeridos.filter((c) => !(c in fila));
          if (faltantes.length) {
            console.warn(`[GPC modal] Fila ${i} sin los campos: ${faltantes.join(", ")}`, fila);
          }
          if (fila.monto !== null && fila.monto !== undefined && isNaN(Number(fila.monto))) {
            console.warn(`[GPC modal] Fila ${i} con monto no numérico:`, fila.monto, fila);
          }
        });
        console.log(`Filas: ${preview.filas.length} | grupos en pantalla: ${this.grupos.length}`);
      }

      if (preview.code === 0 && !preview.token) {
        console.error("[GPC modal] Respuesta exitosa pero sin token: no se podrá enviar.");
      }
      if (!preview.resumen) {
        console.warn("[GPC modal] La respuesta no trae 'resumen'.");
      }

      console.log("¿Se puede revisar?", this.puedeRevisar, this.puedeRevisar ? "" : this.motivosNoEnviar());
      console.groupEnd();
    },

    // Explica en palabras por qué no se puede enviar todavía
    motivosNoEnviar() {
      const motivos = [];
      if (this.loading) {
        motivos.push("todavía está cargando");
      }
      if (this.error) {
        motivos.push("hay un error: " + this.error);
      }
      if (!(this.preview && this.preview.token)) {
        motivos.push("la respuesta no trae token");
      }
      if (!this.resumen.con_monto) {
        motivos.push("no hay conceptos con monto (con_monto = " + this.resumen.con_monto + ")");
      }
      if (!this.revisado) {
        motivos.push("falta marcar 'Revisé los conceptos y montos'");
      }
      if (this.sending) {
        motivos.push("ya se está enviando");
      }
      return motivos;
    },
  },
};
</script>