require('./bootstrap');
window.Vue = require('vue').default;

// Componentes secundarios de Auditoría
Vue.component('filtro-auditorias', require('./components/FilterBar.vue').default);
Vue.component('paginacion', require('./components/Pagination.vue').default);
Vue.component('upload-form', require('./components/UploadForm.vue').default);

Vue.component('lista-auditorias', require('./components/AuditPage.vue').default);

//Vue.component('lista-auditorias', require('./components/VistaPrincipal.vue').default);

/**
 * Filtro global para formatear números como moneda
 */
Vue.filter('currency', function (value, currencySymbol = '$', decimalPlaces = 2) {
  if (typeof value !== 'number') {
    const parsedValue = parseFloat(value);
    if (isNaN(parsedValue)) {
        return value;
    }
    value = parsedValue;
  }
  const formattedValue = value.toFixed(decimalPlaces).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${currencySymbol}${formattedValue}`;
});

// Errores de cualquier componente (render, computed, watchers, métodos llamados por Vue)
Vue.config.errorHandler = function (err, vm, info) {
  const componente = (vm && vm.$options && vm.$options.name) || (vm && vm.$options && vm.$options._componentTag) || "componente sin nombre";
  console.error(`[Vue] Error en <${componente}> (${info}):`, err);
  if (vm && vm.$props) console.error("[Vue] Props del componente:", JSON.parse(JSON.stringify(vm.$props)));
};

// Advertencias de Vue (props con tipo incorrecto, propiedades no definidas en el template...)
Vue.config.warnHandler = function (msg, vm, trace) {
  console.warn(`[Vue] Advertencia: ${msg}`, trace);
};

// Promesas sin .catch (por ejemplo, un axios que falla dentro de un método sin try/catch)
window.addEventListener("unhandledrejection", function (evento) {
  console.error("[JS] Promesa rechazada sin manejar:", evento.reason);
});

// Errores de JavaScript fuera de Vue
window.addEventListener("error", function (evento) {
  console.error("[JS] Error:", evento.message, "|", evento.filename + ":" + evento.lineno);
});

const app = new Vue({
    el: '#app',
});