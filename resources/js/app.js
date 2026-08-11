require('./bootstrap');
window.Vue = require('vue').default;

// Componentes secundarios de Auditoría
Vue.component('filtro-auditorias', require('./components/FilterBar.vue').default);
Vue.component('paginacion', require('./components/Pagination.vue').default);
Vue.component('upload-form', require('./components/UploadForm.vue').default);

//Vue.component('lista-auditorias', require('./components/AuditPage.vue').default);

Vue.component('lista-auditorias', require('./components/VistaPrincipal.vue').default);

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

const app = new Vue({
    el: '#app',
});