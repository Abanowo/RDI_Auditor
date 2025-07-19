<template>
  <div v-if="links.length > 3">
    <div class="flex flex-wrap -mb-1 justify-center mt-6">

      <template v-for="link in links">

        <div v-if="link.url === null"
             :key="`disabled-${link.label}`"
             class="mr-1 mb-1 px-4 py-3 text-sm leading-4 text-gray-400 border rounded"
             v-html="link.label" />

        <button v-else-if="link.active"
                :key="`active-${link.label}`"
                class="mr-1 mb-1 px-4 py-3 text-sm leading-4 border rounded font-bold text-white"
                :class="'bg-theme-primary'"
                v-html="link.label" />

        <button v-else
                :key="`link-${link.label}`"
                @click="changePage(link.url)"
                class="mr-1 mb-1 px-4 py-3 text-sm leading-4 border rounded hover:bg-white focus:border-theme-primary focus:text-theme-primary"
                v-html="link.label" />

      </template>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    // Le decimos a este componente que va a recibir un array llamado 'links'
    links: {
      type: Array,
      default: () => [],
    },
  },
  methods: {
    /**
     * Cuando se hace clic en un botón de página, este método
     * emite un evento hacia el componente padre ('AuditPage')
     * pasándole la URL de la página a la que queremos ir.
     */
    changePage(url) {
       if (!url) return; // La guarda de seguridad es correcta

        // 1. Creamos un objeto URL para poder analizarlo fácilmente.
        //    El segundo argumento es una base por si la URL es relativa (buena práctica).
        const urlObject = new URL(url, window.location.origin);

        // 2. Extraemos el valor del parámetro 'page'.
        const page = urlObject.searchParams.get('page');

        // 3. Emitimos SOLO el número de página.
        this.$emit("change-page", page);
    },
  },
};
</script>
