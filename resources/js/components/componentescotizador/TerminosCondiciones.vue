<template>
    <section class="cz-block cz-block--azul">
        <div class="cz-block-head">
            <h2>Términos y condiciones</h2>
            <button type="button" class="cz-add cz-add--green" @click="agregar">+ Agregar</button>
        </div>

        <div class="cz-terms-list">
            <div v-for="(t, i) in terminos" :key="t._k" class="cz-term">
                <span class="cz-term-n">{{ i + 1 }}.</span>
                <textarea v-model="t.texto" rows="2"></textarea>
                <div class="cz-term-btns">
                    <button type="button" class="b-blue" :disabled="t._ocupado" @click="guardar(t)">{{ t._estado ||
                        'Guardar' }}</button>
                    <button type="button" class="b-red" :disabled="t._ocupado" @click="eliminar(i)">Eliminar</button>
                </div>
            </div>
            <div v-if="!terminos.length" class="cz-empty">Sin términos. Usa “+ Agregar” para escribir uno.</div>
        </div>
    </section>
</template>

<script>
import axios from 'axios';

let llave = 0;

export default {
    name: 'TerminosCondiciones',

    props: {
        terminos: { type: Array, required: true },
        cotizacionId: { type: Number, default: null },
        baseUrl: { type: String, default: '' },
    },

    methods: {
        agregar() {
            this.terminos.push({ _k: 't' + (++llave), id: null, texto: '', _estado: '', _ocupado: false });
        },

        marcar(t, estado) {
            t._estado = estado;
            setTimeout(function () { t._estado = ''; }, 1600);
        },

        guardar(t) {
            if (!t.texto || !t.texto.trim()) {
                this.marcar(t, 'Vacío');
                return;
            }
            // Cotización nueva: el término se guarda junto con "Guardar cotización"
            if (!this.cotizacionId) {
                this.marcar(t, 'Listo');
                return;
            }

            const peticion = t.id
                ? axios.put(this.baseUrl + '/cotizacion-terminos/' + t.id, { texto: t.texto })
                : axios.post(this.baseUrl + '/cotizaciones/' + this.cotizacionId + '/terminos', { texto: t.texto });

            t._ocupado = true;
            peticion
                .then((r) => { t.id = r.data.id; this.marcar(t, 'Guardado'); })
                .catch((e) => { this.$emit('aviso', this.mensajesError(e)[0]); })
                .then(() => { t._ocupado = false; });
        },

        eliminar(i) {
            const t = this.terminos[i];
            if (!window.confirm('¿Eliminar el término ' + (i + 1) + '?')) return;

            if (!t.id || !this.cotizacionId) {
                this.terminos.splice(i, 1);
                return;
            }

            t._ocupado = true;
            axios.delete(this.baseUrl + '/cotizacion-terminos/' + t.id)
                .then(() => { this.terminos.splice(this.terminos.indexOf(t), 1); })
                .catch((e) => { t._ocupado = false; this.$emit('aviso', this.mensajesError(e)[0]); });
        },

        /* ---------- Manejo de respuestas del servidor ---------- */

        /** Convierte un error de axios en una lista de mensajes para mostrar. */
        mensajesError(error) {
            if (error && error.message === 'respuesta-no-json') {
                return ['La ruta ' + error.url + ' no devolvió datos JSON. Revisa que las rutas del cotizador estén '
                    + 'en routes/web.php ANTES del comodín /{any} y ejecuta "php artisan route:clear".'];
            }
            const r = error && error.response;
            if (r && r.status === 422 && r.data && r.data.errors) {
                return Object.keys(r.data.errors).reduce(function (lista, campo) {
                    return lista.concat(r.data.errors[campo]);
                }, []);
            }
            if (r && r.status === 419) return ['La sesión expiró. Recarga la página e intenta de nuevo.'];
            if (r && r.status === 403) return ['No tienes permiso para esta acción.'];
            if (r && r.data && r.data.message) return [r.data.message];
            return ['No hubo respuesta del servidor. Revisa tu conexión e intenta de nuevo.'];
        },
    },
};
</script>

<style scoped>
/* ===================================================================== *
 |  TerminosCondiciones: lista de términos con Guardar / Eliminar
 |  Los colores (--blue, --orange…) vienen de CotizacionGenerador.vue.
 * ===================================================================== */

/* Medidas y fuente iguales a las del modal (el estilo scoped del padre no llega aquí) */
.cz-block,
.cz-block *,
.cz-block *::before,
.cz-block *::after {
    box-sizing: border-box;
}

.cz-block button,
.cz-block input,
.cz-block textarea {
    font-family: inherit;
}

.cz-block {
    margin-bottom: 26px;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .06);
}

.cz-block-head {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 40px;
    padding: 7px 16px;
    background: var(--blue);
    color: #fff;
}

.cz-block-head h2 {
    flex: 1;
    margin: 0;
    font-size: 11.5px !important;
    font-weight: 800;
    line-height: 1.2 !important;
    letter-spacing: .02em;
    text-transform: uppercase;
    color: #fff;
}

.cz-add {
    display: inline-flex;
    align-items: center;
    height: 24px;
    padding: 0 10px !important;
    border: 0;
    border-radius: 4px;
    background: rgba(255, 255, 255, .2);
    font-size: 9.5px !important;
    font-weight: 800;
    line-height: 1 !important;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #fff !important;
    cursor: pointer;
}

.cz-add--green {
    background: var(--green);
}

.cz-add--green:hover {
    background: var(--green-d);
}

.cz-empty {
    padding: 26px;
    background: #f8fafc;
    text-align: center;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--soft);
}

.cz-terms-list {
    padding: 20px 20px 4px;
    background: #f8fafc;
}

.cz-term {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
    padding: 12px 12px 12px 0;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: #fff;
}

.cz-term-n {
    width: 44px;
    text-align: center;
    font-size: 12px;
    font-weight: 800;
    color: var(--orange-d);
}

.cz-term textarea {
    flex: 1;
    min-height: 56px;
    padding: 10px 12px;
    border: 1px solid var(--line);
    border-radius: 4px;
    background: #f8fafc;
    resize: vertical;
    font-size: 11px !important;
    font-weight: 700;
    line-height: 1.65;
    text-transform: uppercase;
    color: var(--ink);
}

.cz-term textarea:focus {
    outline: none;
    border-color: var(--orange);
    background: #fff;
}

.cz-term-btns {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.cz-term-btns button {
    width: 87px;
    height: 30px;
    border: 0;
    border-radius: 3px;
    font-size: 9.5px !important;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: #fff !important;
    cursor: pointer;
}

.cz-term-btns button:disabled {
    opacity: .6;
    cursor: wait;
}

.b-blue {
    background: #0a7fd1;
}

.b-blue:hover {
    background: #0868ab;
}

.b-red {
    background: var(--red);
}

.b-red:hover {
    background: #c0392b;
}
</style>