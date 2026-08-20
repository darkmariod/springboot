<script setup lang="ts">
/**
 * Auditoría: quién hizo qué, cuándo y desde dónde.
 * El registro no se puede editar ni borrar (lo bloquea el modelo en el backend).
 */
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const total = ref(0)
const loading = ref(true)
const detalle = ref<any>(null)

const opciones = ref<{ modelos: string[]; acciones: string[]; usuarios: any[] }>({
  modelos: [], acciones: [], usuarios: [],
})

const f = ref<any>({ modelo: null, accion: null, user_id: null, desde: null, hasta: null, buscar: '' })
const pagina = ref(0)
const porPagina = ref(50)

const colores: Record<string, string> = {
  creo: 'success', actualizo: 'warn', elimino: 'danger', anulo: 'danger',
  ingreso: 'info', salida: 'secondary', acceso_fallido: 'danger', cambio_logo: 'info',
}
const etiquetas: Record<string, string> = {
  creo: 'Creó', actualizo: 'Modificó', elimino: 'Eliminó', anulo: 'Anuló',
  ingreso: 'Ingresó', salida: 'Salió', acceso_fallido: 'Acceso fallido', cambio_logo: 'Cambió el logo',
}

const fecha = (d: any) => (d ? new Date(d).toLocaleString('es-EC') : '—')
const iso = (d: any) => (d ? new Date(d).toISOString().slice(0, 10) : null)

function params() {
  return {
    company_id: company.activeId,
    modelo: f.value.modelo, accion: f.value.accion, user_id: f.value.user_id,
    desde: iso(f.value.desde), hasta: iso(f.value.hasta),
    buscar: f.value.buscar || null,
    page: pagina.value + 1, por_pagina: porPagina.value,
  }
}

async function cargar() {
  loading.value = true
  try {
    const { data } = await api.get('/audit', { params: params() })
    rows.value = data.data ?? data
    total.value = data.total ?? rows.value.length
  } finally {
    loading.value = false
  }
}

async function cargarOpciones() {
  const { data } = await api.get('/audit/opciones', { params: { company_id: company.activeId } })
  opciones.value = data
}

function limpiar() {
  f.value = { modelo: null, accion: null, user_id: null, desde: null, hasta: null, buscar: '' }
  pagina.value = 0
  cargar()
}

async function exportar() {
  const { data } = await api.get('/audit/exportar', { params: params(), responseType: 'blob' })
  const url = URL.createObjectURL(new Blob([data], { type: 'text/csv' }))
  const a = document.createElement('a')
  a.href = url
  a.download = 'auditoria.csv'
  a.click()
  URL.revokeObjectURL(url)
}

function paginar(e: any) {
  pagina.value = e.page
  porPagina.value = e.rows
  cargar()
}

// Lista de cambios «campo: antes → después» para la ventana de detalle
const cambios = computed(() => {
  const c = detalle.value?.cambios
  if (!c) return []
  return Object.entries(c).map(([campo, valor]) => ({ campo, valor: String(valor ?? '—') }))
})

watch(() => company.activeId, () => { pagina.value = 0; cargar(); cargarOpciones() })
onMounted(() => { cargar(); cargarOpciones() })
</script>

<template>
  <div class="aud">
    <header class="aud-head">
      <div>
        <h2>Auditoría</h2>
        <p>Registro de todo lo que ocurre en el sistema. No se puede modificar ni borrar.</p>
      </div>
      <Button label="Exportar CSV" icon="pi pi-download" size="small" outlined @click="exportar" />
    </header>

    <div class="aud-filtros">
      <Select v-model="f.user_id" :options="opciones.usuarios" optionLabel="name" optionValue="id"
              placeholder="Usuario" size="small" showClear class="fi" @change="pagina = 0; cargar()" />
      <Select v-model="f.accion" :options="opciones.acciones" placeholder="Acción" size="small"
              showClear class="fi" @change="pagina = 0; cargar()">
        <template #option="{ option }">{{ etiquetas[option] ?? option }}</template>
        <template #value="{ value }">{{ value ? (etiquetas[value] ?? value) : 'Acción' }}</template>
      </Select>
      <Select v-model="f.modelo" :options="opciones.modelos" placeholder="Módulo" size="small"
              showClear class="fi" @change="pagina = 0; cargar()" />
      <DatePicker v-model="f.desde" placeholder="Desde" dateFormat="dd/mm/yy" size="small"
                  showIcon class="fi" @update:modelValue="pagina = 0; cargar()" />
      <DatePicker v-model="f.hasta" placeholder="Hasta" dateFormat="dd/mm/yy" size="small"
                  showIcon class="fi" @update:modelValue="pagina = 0; cargar()" />
      <InputText v-model="f.buscar" placeholder="Buscar detalle o IP" size="small" class="fi"
                 @keydown.enter="pagina = 0; cargar()" />
      <Button label="Limpiar" icon="pi pi-filter-slash" size="small" text @click="limpiar" />
    </div>

    <DataTable :value="rows" :loading="loading" size="small" stripedRows lazy
               paginator :rows="porPagina" :totalRecords="total" :first="pagina * porPagina"
               :rowsPerPageOptions="[25, 50, 100]" @page="paginar" class="aud-tabla">
      <Column header="Cuándo" style="width:170px">
        <template #body="{ data }">{{ fecha(data.created_at) }}</template>
      </Column>
      <Column header="Quién" style="width:150px">
        <template #body="{ data }">
          {{ data.user?.name ?? 'Sistema' }}
        </template>
      </Column>
      <Column header="Acción" style="width:140px">
        <template #body="{ data }">
          <Tag :value="etiquetas[data.accion] ?? data.accion" :severity="colores[data.accion] ?? 'secondary'" />
        </template>
      </Column>
      <Column field="modelo" header="Módulo" style="width:130px" />
      <Column header="Detalle">
        <template #body="{ data }">
          <span>{{ data.descripcion ?? '—' }}</span>
          <span v-if="data.modelo_id" class="aud-id">#{{ data.modelo_id }}</span>
        </template>
      </Column>
      <Column header="Cambios" style="width:110px">
        <template #body="{ data }">
          <Button v-if="data.cambios" label="Ver" icon="pi pi-search" size="small" text
                  @click="detalle = data" />
          <span v-else class="aud-vacio">—</span>
        </template>
      </Column>
      <Column field="ip" header="IP" style="width:120px" />
      <template #empty><div class="aud-sin">Sin movimientos para este filtro.</div></template>
    </DataTable>

    <Dialog :visible="!!detalle" modal header="Qué cambió" style="width:520px"
            @update:visible="detalle = null">
      <div v-if="detalle" class="aud-det">
        <div class="aud-meta">
          <b>{{ etiquetas[detalle.accion] ?? detalle.accion }}</b> ·
          {{ detalle.modelo }} <span v-if="detalle.modelo_id">#{{ detalle.modelo_id }}</span><br>
          {{ detalle.user?.name ?? 'Sistema' }} · {{ fecha(detalle.created_at) }} · IP {{ detalle.ip }}
        </div>
        <table class="aud-cambios">
          <tr v-for="c in cambios" :key="c.campo">
            <td class="aud-campo">{{ c.campo }}</td>
            <td>{{ c.valor }}</td>
          </tr>
        </table>
      </div>
    </Dialog>
  </div>
</template>

<style scoped>
.aud { padding: 20px; }
.aud-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
.aud-head h2 { margin: 0 0 3px; font-size: 20px; }
.aud-head p { color: #8fa2ad; font-size: 13px; margin: 0; }
.aud-filtros { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
.aud-filtros .fi { min-width: 150px; }
.aud-tabla :deep(td) { padding: 8px 12px; }
.aud-tabla :deep(th) { padding: 9px 12px; }
.aud-id { color: #94a3b8; font-size: 11.5px; margin-left: 6px; }
.aud-vacio { color: #cbd5e1; }
.aud-sin { padding: 26px; text-align: center; color: #94a3b8; font-size: 13px; }
.aud-meta { font-size: 12.5px; color: #64748b; line-height: 1.7; margin-bottom: 12px;
  padding-bottom: 10px; border-bottom: 1px solid #e2e8f0; }
.aud-cambios { width: 100%; border-collapse: collapse; font-size: 13px; }
.aud-cambios td { padding: 7px 8px; border-bottom: 1px solid #eef1f5; vertical-align: top; }
.aud-campo { font-weight: 600; width: 40%; color: #334155; }
</style>
