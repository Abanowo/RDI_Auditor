/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */

require('./bootstrap');

window.Vue = require('vue').default;

/**
 * The following block of code may be used to automatically register your
 * Vue components. It will recursively scan this directory for the Vue
 * components and automatically register them with their "basename".
 *
 * Eg. ./components/ExampleComponent.vue -> <example-component></example-component>
 */

// const files = require.context('./', true, /\.vue$/i)
// files.keys().map(key => Vue.component(key.split('/').pop().split('.')[0], files(key).default))
Vue.component('filtro-auditorias', require('./components/FilterBar.vue').default);
Vue.component('lista-auditorias', require('./components/AuditPage.vue').default);
Vue.component('paginacion', require('./components/Pagination.vue').default);
Vue.component('upload-form', require('./components/UploadForm.vue').default);

//Vue.component('bannerpedimento', require('./components/BannerPedimento.vue').default);

/**
 * Next, we will create a fresh Vue application instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */
// --- AÑADE ESTE FILTRO AQUÍ ---
/**
 * Filtro global para formatear números como moneda,
 * asegurando que siempre tengan 2 decimales.
 */
Vue.filter('currency', function (value, currencySymbol = '$', decimalPlaces = 2) {
  // Si el valor no es un número, lo devolvemos tal cual (ej. 'N/A')
  if (typeof value !== 'number') {
    const parsedValue = parseFloat(value);
    if (isNaN(parsedValue)) {
        return value;
    }
    value = parsedValue;
  }
  // toFixed(2) es el método de JavaScript que hace toda la magia.
  const formattedValue = value.toFixed(decimalPlaces).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${currencySymbol}${formattedValue}`;
});
const app = new Vue({
    el: '#app',
});
