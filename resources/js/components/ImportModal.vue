<template>
  <div
    v-if="show"
    @click.self="close"
    class="fixed inset-0 bg-black bg-opacity-60 z-50 flex justify-center items-center p-4"
  >
    <div
      class="bg-gray-50 rounded-lg shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col"
    >
      <div
        class="flex justify-between items-center p-4 border-b bg-white rounded-t-lg flex-shrink-0"
      >
        <h3 class="text-xl font-bold text-theme-dark">Subir Nuevo Estado de Cuenta</h3>
        <button @click="close" class="text-gray-400 hover:text-gray-800 text-3xl">
          &times;
        </button>
      </div>

      <div class="p-6 overflow-y-auto flex-grow flex justify-center items-center">
        <UploadForm
            @close="close"
            @upload-success="handleUploadSuccess"
        />
      </div>
    </div>
  </div>
</template>

<script>
import UploadForm from "./UploadForm.vue"; // Asumiendo que tu form se llama así

export default {
  components: {
    UploadForm,
  },
  props: {
    show: { type: Boolean, default: false },
  },
  emits: ["close"],
  methods: {
    close() {
      this.$emit("close");
    },
    handleUploadSuccess() {
      // Cuando el form nos avise que tuvo éxito, también cerramos el modal.
      // Podríamos también avisarle a la página principal que recargue la lista.
      this.close();
      this.$emit('trigger-update', 1);
    },
  },
};
</script>
