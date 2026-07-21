<script setup lang="ts">
import { ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()

const loading = ref(false)
const msg = ref<any>(null)
const showTable = ref(false)
const filas = ref<any[]>([])
const valorTotal = ref(0)

const params = ref({
  warehouse_id: null as number | null,
  category: null as string | null,
  producto: '',
  tipo_precio: null as string | null,
  hasta: new Date().toISOString().slice(0, 10),
  orden: 'codigo',
})

const bodegas = ref<any[]>([])
const categorias = [
  { label: 'Todos', value: null },
  { label: 'Bienes', value: 'bien' },
  { label: 'Servicios', value: 'servicio' },
]
const ordenes = [
  { label: 'Código', value: 'codigo' },
  { label: 'Descripción', value: 'descripcion' },
  { label: 'Stock', value: 'stock' },
]
const tiposPrecio = [
  { label: 'Costo', value: 'costo' },
  { label: 'Venta', value: 'venta' },
]

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

async function loadBodegas() {
  bodegas.value = (await api.get('/warehouses?company_id=' + company.activeId)).data
}

async function generar() {
  loading.value = true
  msg.value = null
  showTable.value = false
  try {
    const params_: any = { company_id: company.activeId, hasta: params.value.hasta, orden: params.value.orden }
    if (params.value.warehouse_id) params_.warehouse_id = params.value.warehouse_id
    if (params.value.category) params_.category = params.value.category
    if (params.value.producto) params_.producto = params.value.producto

    const data = (await api.get('/reports/stock', { params: params_ })).data
    filas.value = data.items
    valorTotal.value = data.valor_total
    showTable.value = true
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.message ?? 'Error al generar reporte.' }
  } finally {
    loading.value = false
  }
}

async function exportCsv() {
  const params_: any = { company_id: company.activeId, hasta: params.value.hasta, orden: params.value.orden }
  if (params.value.warehouse_id) params_.warehouse_id = params.value.warehouse_id
  if (params.value.category) params_.category = params.value.category
  if (params.value.producto) params_.producto = params.value.producto

  const data = (await api.get('/reports/csv', { params: params_, responseType: 'blob' })).data
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([data], { type: 'text/csv' }))
  a.download = 'reporte-existencias.csv'
  a.click()
}

function exportPdf() {
  const params_: any = new URLSearchParams({ company_id: String(company.activeId), hasta: params.value.hasta, orden: params.value.orden })
  if (params.value.warehouse_id) params_.set('warehouse_id', String(params.value.warehouse_id))
  if (params.value.category) params_.set('category', params.value.category)
  if (params.value.producto) params_.set('producto', params.value.producto)

  window.open('/api/reports/pdf?' + params_.toString(), '_blank')
}

function resetear() {
  params.value = {
    warehouse_id: null,
    category: null,
    producto: '',
    tipo_precio: null,
    hasta: new Date().toISOString().slice(0, 10),
    orden: 'codigo',
  }
  showTable.value = false
  filas.value = []
  valorTotal.value = 0
  msg.value = null
}

loadBodegas()
</script>

<template>
  <div style="display: flex; height: 100%;">
    <!-- Panel de parámetros -->
    <aside class="no-print" style="width: 290px; border-right: 1px solid #e2e5ea; background: #fff; padding: 16px; overflow: auto; flex-shrink: 0;">
      <div style="font-weight: 700; font-size: 13px; color: #4a3220; margin-bottom: 12px;">Parámetros del Reporte</div>

      <fieldset class="kvs-fieldset">
        <legend>Filtros</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Bodega:</label>
          <Select v-model="params.warehouse_id" :options="[{ id: null, nombre: 'Todas' }, ...bodegas]"
                  optionLabel="nombre" optionValue="id" placeholder="Todas" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Categoría:</label>
          <Select v-model="params.category" :options="categorias" optionLabel="label" optionValue="value"
                  class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Artículo:</label>
          <InputText v-model="params.producto" placeholder="Código o nombre" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Tipo Precio:</label>
          <Select v-model="params.tipo_precio" :options="tiposPrecio" optionLabel="label" optionValue="value"
                  class="kvs-in" />
        </div>
      </fieldset>

      <fieldset class="kvs-fieldset" style="margin-top: 10px;">
        <legend>Opciones</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Hasta:</label>
          <InputText v-model="params.hasta" type="date" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Orden:</label>
          <Select v-model="params.orden" :options="ordenes" optionLabel="label" optionValue="value"
                  class="kvs-in" />
        </div>
      </fieldset>

      <div style="display: flex; gap: 8px; margin-top: 14px;">
        <Button label="Generar" icon="pi pi-cog" size="small" :loading="loading" @click="generar" />
        <Button label="Resetear" icon="pi pi-eraser" size="small" text @click="resetear" />
      </div>
    </aside>

    <!-- Panel de resultados -->
    <div style="flex: 1; overflow: auto; background: #eef1f5; padding: 18px;">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h3 style="margin: 0;">Reporte de Existencias</h3>
        <div v-if="showTable" style="display: flex; gap: 8px;">
          <Button label="CSV" icon="pi pi-file-excel" size="small" outlined @click="exportCsv" />
          <Button label="PDF" icon="pi pi-print" size="small" outlined @click="exportPdf" />
          <Button label="Word" icon="pi pi-file" size="small" outlined disabled />
        </div>
      </div>

      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 12px;">{{ msg.text }}</Message>

      <div v-if="!showTable && !msg" style="color: #94a3b8; text-align: center; padding: 80px 20px;">
        Configurá los parámetros y presioná <b>Generar</b>.
      </div>

      <DataTable v-if="showTable" :value="filas" size="small" stripedRows :paginator="true" :rows="25">
        <Column field="codigo" header="Código" style="width: 100px;" />
        <Column field="descripcion" header="Descripción" />
        <Column field="unidad" header="Unidad" style="width: 80px;" />
        <Column header="Stock Actual" style="width: 100px;">
          <template #body="{ data }">{{ Number(data.stock_actual).toFixed(2) }}</template>
        </Column>
        <Column header="Costo Promedio" style="width: 120px;">
          <template #body="{ data }">{{ money(data.costo_promedio) }}</template>
        </Column>
        <Column header="Valor Total" style="width: 120px;">
          <template #body="{ data }">{{ money(data.valor_total) }}</template>
        </Column>
        <Column header="Último Mov." style="width: 100px;">
          <template #body="{ data }">{{ data.ultimo_movimiento_fecha ?? '—' }}</template>
        </Column>
        <template #footer>
          <div style="display: flex; justify-content: space-between; font-weight: 600;">
            <span>Total: {{ filas.length }} artículos</span>
            <span>{{ money(valorTotal) }}</span>
          </div>
        </template>
      </DataTable>
    </div>
  </div>
</template>
