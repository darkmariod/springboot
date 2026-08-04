<script setup lang="ts">
/**
 * ReportViewer.vue — Reportes estilo KBS (PANTALLA 1).
 * Radio de tipo de reporte: Existencias | Series. Parámetros + exportación.
 */
import { ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import RadioButton from 'primevue/radiobutton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()

const loading = ref(false)
const msg = ref<any>(null)
const showTable = ref(false)

const tipo = ref('existencias')
const tiposReporte = [
  { label: 'Existencias', value: 'existencias' },
  { label: 'Series por Artículo', value: 'series' },
]

// Resultados de existencias
const filas = ref<any[]>([])
const valorTotal = ref(0)

// Resultados de series
const seriesItems = ref<any[]>([])
const seriesResumen = ref<any[]>([])
const seriesTotales = ref<any>({ disponibles: 0, vendidas: 0, total: 0 })

const params = ref({
  warehouse_id: null as number | null,
  category: null as string | null,
  producto: '',
  tipo_precio: null as string | null,
  hasta: new Date().toISOString().slice(0, 10),
  orden: 'codigo',
  estado_serie: null as string | null,
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
const estadosSerie = [
  { label: 'Todos', value: null },
  { label: 'Disponibles', value: 'disponible' },
  { label: 'Vendidas', value: 'vendido' },
]

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

async function loadBodegas() {
  bodegas.value = (await api.get('/warehouses?company_id=' + company.activeId)).data
}

function baseParams(): any {
  const params_: any = { company_id: company.activeId }
  if (params.value.producto) params_.producto = params.value.producto
  return params_
}

async function generar() {
  loading.value = true
  msg.value = null
  showTable.value = false
  try {
    if (tipo.value === 'series') {
      const params_ = baseParams()
      if (params.value.estado_serie) params_.estado = params.value.estado_serie
      const data = (await api.get('/reports/series', { params: params_ })).data
      seriesItems.value = data.items
      seriesResumen.value = data.resumen
      seriesTotales.value = data.totales
    } else {
      const params_: any = { ...baseParams(), hasta: params.value.hasta, orden: params.value.orden }
      if (params.value.warehouse_id) params_.warehouse_id = params.value.warehouse_id
      if (params.value.category) params_.category = params.value.category
      const data = (await api.get('/reports/stock', { params: params_ })).data
      filas.value = data.items
      valorTotal.value = data.valor_total
    }
    showTable.value = true
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.message ?? 'Error al generar reporte.' }
  } finally {
    loading.value = false
  }
}

async function exportCsv() {
  const isSeries = tipo.value === 'series'
  const params_ = baseParams()
  if (isSeries) {
    if (params.value.estado_serie) params_.estado = params.value.estado_serie
  } else {
    params_.hasta = params.value.hasta
    params_.orden = params.value.orden
    if (params.value.warehouse_id) params_.warehouse_id = params.value.warehouse_id
    if (params.value.category) params_.category = params.value.category
  }
  const data = (await api.get(isSeries ? '/reports/series-csv' : '/reports/csv', { params: params_, responseType: 'blob' })).data
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([data], { type: 'text/csv' }))
  a.download = isSeries ? 'reporte-series.csv' : 'reporte-existencias.csv'
  a.click()
}

async function exportPdf() {
  try {
    const params_: any = { ...baseParams(), tipo: 'existencias', hasta: params.value.hasta, orden: params.value.orden }
    if (params.value.warehouse_id) params_.warehouse_id = params.value.warehouse_id
    if (params.value.category) params_.category = params.value.category
    const res = await api.post('/reports/pdf', params_, { responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    a.download = 'reporte-existencias.pdf'
    a.click()
  } catch (e: any) {
    msg.value = { type: 'error', text: 'No se pudo generar el PDF.' }
  }
}

function resetear() {
  params.value = {
    warehouse_id: null,
    category: null,
    producto: '',
    tipo_precio: null,
    hasta: new Date().toISOString().slice(0, 10),
    orden: 'codigo',
    estado_serie: null,
  }
  showTable.value = false
  filas.value = []
  valorTotal.value = 0
  seriesItems.value = []
  seriesResumen.value = []
  seriesTotales.value = { disponibles: 0, vendidas: 0, total: 0 }
  msg.value = null
}

loadBodegas()
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Reportes" :company="{ ruc: company.activeId, razon_social: 'Inventario' }" subtitle="Parámetros y Exportación" />
    <div style="display: flex; flex: 1; min-height: 0;">
    <!-- Panel de parámetros -->
    <aside class="no-print" style="width: 300px; border-right: 1px solid #e2e5ea; background: #fff; padding: 16px; overflow: auto; flex-shrink: 0;">
      <div style="font-weight: 700; font-size: 13px; color: #4a3220; margin-bottom: 12px;">Parámetros del Reporte</div>

      <div style="margin-bottom: 12px;">
        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Tipo de reporte:</div>
        <div style="display: flex; flex-direction: column; gap: 8px;">
          <label v-for="t in tiposReporte" :key="t.value" style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
            <RadioButton v-model="tipo" :value="t.value" :input-id="'rep-' + t.value" />
            <span>{{ t.label }}</span>
          </label>
        </div>
      </div>

      <fieldset v-if="tipo === 'existencias'" class="kvs-fieldset">
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

      <fieldset v-else class="kvs-fieldset">
        <legend>Filtros</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Artículo:</label>
          <InputText v-model="params.producto" placeholder="Código o nombre" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Estado:</label>
          <Select v-model="params.estado_serie" :options="estadosSerie" optionLabel="label" optionValue="value"
                  class="kvs-in" />
        </div>
      </fieldset>

      <fieldset v-if="tipo === 'existencias'" class="kvs-fieldset" style="margin-top: 10px;">
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
        <h3 style="margin: 0;">{{ tipo === 'series' ? 'Reporte de Series por Artículo' : 'Reporte de Existencias' }}</h3>
        <div v-if="showTable" style="display: flex; gap: 8px;">
          <Button label="CSV" icon="pi pi-file-excel" size="small" outlined @click="exportCsv" />
          <Button v-if="tipo === 'existencias'" label="PDF" icon="pi pi-print" size="small" outlined @click="exportPdf" />
          <Button v-else label="PDF" icon="pi pi-print" size="small" outlined disabled title="PDF disponible solo en Existencias" />
        </div>
      </div>

      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 12px;">{{ msg.text }}</Message>

      <div v-if="!showTable && !msg" style="color: #94a3b8; text-align: center; padding: 80px 20px;">
        Configurá los parámetros y presioná <b>Generar</b>.
      </div>

      <!-- ── Reporte de Existencias ── -->
      <template v-if="showTable && tipo === 'existencias'">
        <DataTable :value="filas" size="small" stripedRows :paginator="true" :rows="25">
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
      </template>

      <!-- ── Reporte de Series ── -->
      <template v-if="showTable && tipo === 'series'">
        <DataTable :value="seriesResumen" size="small" stripedRows :paginator="true" :rows="15">
          <Column field="codigo" header="Código" style="width: 110px;" />
          <Column field="descripcion" header="Descripción" />
          <Column header="Disponibles" style="width: 100px;">
            <template #body="{ data }"><b style="color:#177245;">{{ data.disponibles }}</b></template>
          </Column>
          <Column header="Vendidas" style="width: 100px;">
            <template #body="{ data }"><b style="color:#c0392b;">{{ data.vendidas }}</b></template>
          </Column>
          <Column header="Total" style="width: 100px;">
            <template #body="{ data }">{{ data.total }}</template>
          </Column>
          <template #footer>
            <div style="display: flex; justify-content: space-between; font-weight: 600;">
              <span>Total: {{ seriesResumen.length }} artículos con series</span>
              <span>Disp: {{ seriesTotales.disponibles }} · Vend: {{ seriesTotales.vendidas }} · Total: {{ seriesTotales.total }}</span>
            </div>
          </template>
        </DataTable>

        <h4 style="margin: 18px 0 8px;">Detalle por serie</h4>
        <DataTable :value="seriesItems" size="small" stripedRows :paginator="true" :rows="25">
          <Column field="codigo" header="Código" style="width: 110px;" />
          <Column field="descripcion" header="Descripción" />
          <Column field="serie" header="Serie" style="width: 140px;">
            <template #body="{ data }"><span style="font-family:monospace;">{{ data.serie }}</span></template>
          </Column>
          <Column field="estado" header="Estado" style="width: 100px;">
            <template #body="{ data }">
              <span :style="{ color: data.estado === 'disponible' ? '#177245' : '#c0392b', fontWeight: 600 }">
                {{ data.estado }}
              </span>
            </template>
          </Column>
          <Column field="factura" header="Factura" style="width: 130px;">
            <template #body="{ data }">{{ data.factura ?? '—' }}</template>
          </Column>
          <Column header="Fecha Venta" style="width: 110px;">
            <template #body="{ data }">{{ data.fecha_venta ? String(data.fecha_venta).slice(0, 10) : '—' }}</template>
          </Column>
        </DataTable>
      </template>
    </div>
    </div>
  </div>
</template>
