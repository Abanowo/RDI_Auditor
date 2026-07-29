<template>
  <div class="min-h-screen font-sans text-gray-800 flex flex-col" style="background-color: #F4F6F9;">
    
    <!-- ======================================================================= -->
    <!-- ESTADO 1: PANTALLA DE INICIO (Menú Principal) -->
    <!-- ======================================================================= -->
    <div v-if="!mostrarMenuFinanzas" class="flex-1 flex items-center justify-center p-4">
      <div class="bg-white p-10 rounded-2xl shadow-xl text-center max-w-md w-full border border-gray-100">
        <!-- Logo -->
        <div class="bg-black p-4 rounded-xl inline-block mb-6 shadow-inner">
          <img src="/images/logo.png" alt="InTactics" class="h-10 w-auto object-contain">
        </div>
        
        <h1 class="text-3xl font-black text-gray-800 mb-2 tracking-tight">ERP InTactics</h1>
        <p class="text-gray-500 text-sm mb-10 font-medium">Portal Operativo y Administrativo</p>
        
        <!-- Botón para entrar al Módulo de Finanzas -->
        <button 
          @click="entrarModuloFinanzas" 
          class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-4 px-6 rounded-xl transition-all duration-300 flex items-center justify-center gap-3 shadow-md hover:shadow-lg transform hover:-translate-y-1"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          Módulo Finanzas
        </button>
      </div>
    </div>

    <!-- ======================================================================= -->
    <!-- ESTADO 2: SUB-MENÚ ESTILO TARJETAS Y ÁREA DE TRABAJO -->
    <!-- ======================================================================= -->
    <div v-else class="fade-in flex-1 flex flex-col">
      
      <!-- Encabezado superior sencillo -->
      <div class="px-6 pt-6 pb-2 flex items-center justify-between">
        <div class="flex items-center gap-4">
          <button @click="mostrarMenuFinanzas = false" class="text-gray-400 hover:text-blue-500 transition-colors flex items-center gap-1 text-sm font-bold">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            Volver al inicio
          </button>
        </div>
        <img src="/images/logo.png" alt="InTactics" class="h-6 w-auto object-contain opacity-70">
      </div>

      <!-- BOTONES ESTILO TARJETAS BLANCAS (IDÉNTICOS A LA IMAGEN) -->
      <div class="px-6 py-4 flex flex-wrap gap-4 border-b border-gray-200">
        
        <!-- Tarjeta 1: Control Proveedores -->
        <button 
          @click="moduloActivo = 'proveedores'"
          class="bg-white rounded shadow-sm border p-4 flex items-center gap-4 w-[280px] h-20 transition-all duration-200 text-left hover:shadow-md"
          :class="moduloActivo === 'proveedores' ? 'border-blue-400 ring-1 ring-blue-400' : 'border-gray-200 hover:border-gray-300'"
        >
          <div class="text-[#F59E0B] shrink-0">
            <!-- Icono de engranes/operaciones en Naranja -->
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
          </div>
          <span class="text-[13px] font-semibold text-[#6C7A89] leading-tight w-full">Control de<br>Proveedores</span>
        </button>

        <!-- Tarjeta 2: Ingresos (Finanzas) -->
        <button 
          @click="moduloActivo = 'ingresos'"
          class="bg-white rounded shadow-sm border p-4 flex items-center gap-4 w-[280px] h-20 transition-all duration-200 text-left hover:shadow-md"
          :class="moduloActivo === 'ingresos' ? 'border-blue-400 ring-1 ring-blue-400' : 'border-gray-200 hover:border-gray-300'"
        >
          <div class="text-[#10B981] shrink-0">
            <!-- Icono de documento/pagos en Verde -->
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 8h.01"></path></svg>
          </div>
          <span class="text-[13px] font-semibold text-[#6C7A89] leading-tight w-full">Finanzas<br>e Ingresos</span>
        </button>

      </div>

      <!-- ÁREA DE CONTENIDO DINÁMICO (Las Vistas) -->
      <main class="w-full relative flex-1">
        <!-- Mensaje por defecto cuando no se ha seleccionado ninguna tarjeta -->
        <div v-if="!moduloActivo" class="flex flex-col items-center justify-center h-full pt-20 text-gray-400">
          <svg class="w-16 h-16 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path></svg>
          <p class="text-sm font-medium">Seleccione un submódulo en las tarjetas superiores para comenzar.</p>
        </div>

        <transition name="fade" mode="out-in">
          <!-- Sub-Módulo 1 -->
          <VistaControlProveedores v-if="moduloActivo === 'proveedores'" key="proveedores" />
          <!-- Sub-Módulo 2 -->
          <VistaFinanzasIngresos v-else-if="moduloActivo === 'ingresos'" key="ingresos" />
        </transition>
      </main>

    </div>
  </div>
</template>

<script>
import VistaControlProveedores from './componentesproveedores/vista_control_proveedores.vue'; 
import VistaFinanzasIngresos from './componentesingresos/VistaFinanzasIngresos.vue';

export default {
  name: 'VistaPrincipal',
  components: {
    VistaControlProveedores,
    VistaFinanzasIngresos
  },
  data() {
    return {
      mostrarMenuFinanzas: false, 
      moduloActivo: null // <-- Lo iniciamos en NULL para que al principio solo se vean los botones limpios
    };
  },
  methods: {
    entrarModuloFinanzas() {
      this.mostrarMenuFinanzas = true;
      this.moduloActivo = null; // Muestra las tarjetas superiores y pide que elijas una
    }
  }
}
</script>

<style scoped>
/* Animación para la transición entre submódulos */
.fade-enter-active, .fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.fade-enter, .fade-leave-to {
  opacity: 0;
  transform: translateY(5px);
}

/* Animación para cuando aparece el área de trabajo */
.fade-in {
  animation: fadeIn 0.3s ease-out forwards;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>