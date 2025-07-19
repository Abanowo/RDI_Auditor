<template>
  <div class="p-4 bg-white rounded-lg shadow-md mb-6 border">
    <div class="grid grid-cols-1 lg:grid-cols-3 xl:grid-cols-4 gap-x-6 gap-y-4">
      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Identificadores</legend>
        <div class="space-y-3">
          <div class="flex space-x-2">
            <input
              type="text"
              v-model="filters.pedimento"
              placeholder="Pedimento"
              class="block w-full py-2 border-gray-300 rounded-md shadow-sm text-sm"
            />
          </div>
          <div class="flex space-x-2">
            <input
              type="text"
              v-model="filters.folio"
              placeholder="Folio de factura"
              class="block py-2 w-full border-gray-300 rounded-md shadow-sm text-sm"
            />
            <select
              v-model="filters.folio_tipo_documento"
              class="block py-2 w-2/3 border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Cualquier Tipo</option>
              <option value="sc">SC</option>
              <option value="flete">Flete</option>
              <option value="llc">LLC</option>
            </select>
          </div>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Estados/Cliente</legend>
        <div class="space-y-3">
          <div class="flex space-x-2">
            <select
              v-model="filters.estado"
              class="block py-2 w-full border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Todos los Estados</option>
              <optgroup label="Correctos">
                <option>Coinciden!</option>
                <option>SC Encontrada</option>
                <option>EXPO</option>
              </optgroup>
              <optgroup label="Para auditar">
                <option>Pago de mas!</option>
                <option>Pago de menos!</option>
                <option>Sin Flete!</option>
                <option>Sin SC!</option>
              </optgroup>
              <optgroup label="Pago de derecho">
                <option>Normal</option>
                <option>Segundo Pago</option>
                <option>Medio Pago</option>
                <option>Intactics</option>
              </optgroup>
            </select>
            <select
              v-model="filters.estado_tipo_documento"
              class="block py-2 w-2/3 border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Cualquier Tipo</option>
              <option value="sc">SC</option>
              <option value="impuestos">Impuestos</option>
              <option value="flete">Flete</option>
              <option value="llc">LLC</option>
              <option value="pago_derecho">Pago Derecho</option>
            </select>
          </div>

          <div class="flex space-x-2">
            <div
              class="block py-2 w-full border-gray-300 rounded-md shadow-sm text-sm bg-gray-100 p-2 text-center text-gray-400"
            >
              <v-select
                v-model="filters.cliente_id"
                :options="clienteOptions"
                label="nombre"
                :reduce="(cliente) => cliente.id"
                placeholder="Buscar Cliente..."
                class="w-full"
              ></v-select>
            </div>
          </div>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Periodo</legend>
        <div class="space-y-3">
          <div class="flex items-center space-x-2">
            <vue-ctk-date-time-picker
              v-model="dateRange"
              label="Selecciona un rango de fechas"
              class="w-2/3"
              formatted="YYYY-MM-DD"
              format="YYYY-MM-DD"
              :range="true"
              :no-time="true"
              :button-color="'#b47500'"
              :shortcuts="[
                { key: 'thisWeek', label: 'Esta Semana' },
                { key: 'lastWeek', label: 'Semana Pasada' },
                { key: 'thisMonth', label: 'Este Mes' },
                { key: 'lastMonth', label: 'Mes Pasado' },
              ]"
            />

            <select
              v-model="filters.fecha_tipo_documento"
              class="block w-1/3 py-2 border-gray-300 rounded-md shadow-sm text-sm"
            >
              <option value="">Cualquier Tipo</option>
              <option value="sc">SC</option>
              <option value="impuestos">Impuestos</option>
              <option value="flete">Flete</option>
              <option value="llc">LLC</option>
              <option value="pago_derecho">Pago Derecho</option>
            </select>
          </div>
          <fieldset class="py-4">
            <legend class="font-semibold text-sm">Acciones</legend>
            <div class="flex space-x-2">
              <button
                @click="search"
                class="w-full bg-theme-primary text-white py-2 px-4 rounded-md shadow-sm hover:opacity-90"
              >
                Buscar
              </button>
              <button
                @click="clear"
                class="w-full bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300"
              >
                Limpiar
              </button>
            </div>
          </fieldset>
        </div>
      </fieldset>

      <fieldset class="border p-4 rounded-md">
        <legend class="px-2 font-semibold text-sm">Reportes</legend>
        <div class="h-full flex flex-col justify-end space-y-2">
          <!-- RICH SELECTBOX DE REPORTES RECIENTES -->
          <v-select
            ref="reporteSelect"
            :options="tareasCompletadas"
            placeholder="Ver reportes recientes..."
            class="w-full mb-2"
            :filterable="false"
            @option:selected="limpiarSeleccionReporte"
          >
            <template #selected-option-container>
              <div class="text-sm text-gray-500">Seleccione un reporte...</div>
            </template>

            <template
              #option="{
                id,
                nombre_archivo,
                banco,
                sucursal,
                created_at,
                ruta_reporte_impuestos,
                ruta_reporte_impuestos_pendientes,
              }"
            >
              <div class="py-2 px-3">
                <p class="font-bold text-base truncate" :title="nombre_archivo">
                  {{ nombre_archivo }}
                </p>
                <div class="flex justify-between text-xs mt-1">
                  <span>{{ sucursal }}</span>
                  <span>{{ banco }}</span>
                  <span>{{ formatRelativeDate(created_at) }}</span>
                </div>
                <div class="flex space-x-4 text-sm mt-2 pt-2 border-t">
                  <a
                    v-if="ruta_reporte_impuestos"
                    :href="getDownloadUrl(id, 'facturado')"
                    @click.stop
                    target="_blank"
                    class="font-medium hover:underline"
                    >Reporte - Facturados</a
                  >
                  <a
                    v-if="ruta_reporte_impuestos_pendientes"
                    :href="getDownloadUrl(id, 'pendiente')"
                    @click.stop
                    target="_blank"
                    class="font-medium hover:underline"
                    >Reporte - Pendientes</a
                  >
                </div>
              </div>
            </template>

            <template #no-options>
              No hay reportes recientes para esta sucursal.
            </template>
          </v-select>
          <div class="flex items-center space-x-2"></div>
        </div>
      </fieldset>
    </div>
  </div>
</template>
