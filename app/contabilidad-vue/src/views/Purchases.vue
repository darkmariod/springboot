<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import RetencionCompra from '../components/RetencionCompra.vue'
import SeriesInput from '../components/SeriesInput.vue'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { usePlanStore } from '../stores/plan'

const company = useCompanyStore()
const plan = usePlanStore()
const rows = ref<any[]>([])
const loading = ref(true)
const importing = ref(false)
const msg = ref<{ type: string; text: string } | null>(null)
const fileRef = ref<HTMLInputElement>()
const sustentos = ref<any[]>([])
// '' = automático: 06 (inventario) si la factura trae bienes con stock, 01 si son servicios o gastos
const sustento = ref('')
const opcionesSustento = computed(() => [
  { value: '', label: 'Automático (06 si lleva inventario, 01 si no)' },
  ...sustentos.value,
])

// Retención de una compra (se pregunta justo después de importar o desde la fila)
const retencionDialog = ref<any>(null)
const importada = ref<any>(null)
const puedeRetener = computed(() => plan.tiene('facturacion_sri'))
function abrirRetencion(compra: any, preguntar = false) {
  retencionDialog.value = { compra, preguntar }
}
async function retencionRegistrada() {
  await load()
  const actual = retencionDialog.value?.compra
  const fresco = rows.value.find((r: any) => r.id === actual?.id)
  if (fresco && retencionDialog.value) retencionDialog.value.compra = fresco
}

// Una factura con productos que manejan series no se puede importar sin ellas (el XML no las trae y el stock no sube sin sus
// series): el servidor responde 422 con las líneas que las necesitan, aquí se piden y se vuelve a mandar el mismo archivo.
const archivoPendiente = ref<File | null>(null)
const seriesImport = ref<{
  mensaje: string
  error: string
  lineas: any[]
  series: Record<string, string[]>
  valido: Record<string, boolean>
} | null>(null)
const seriesCompletas = computed(
  () => !!seriesImport.value && seriesImport.value.lineas.every((l: any) => seriesImport.value!.valido[l.codigo]),
)
function marcarValida(codigo: string, v: boolean) {
  if (seriesImport.value) seriesImport.value.valido[codigo] = v
}
function cancelarSeries() {
  seriesImport.value = null
  archivoPendiente.value = null
  msg.value = { type: 'warn', text: 'No se importó la factura: faltaron las series de los productos que las manejan.' }
}
function importarConSeries() {
  if (archivoPendiente.value && seriesImport.value) {
    seriesImport.value.error = ''
    enviarImportacion(archivoPendiente.value, seriesImport.value.series)
  }
}

const money = (n: any) => `$${Number(n).toFixed(2)}`

async function load() {
  loading.value = true
  rows.value = (await api.get(`/purchases?company_id=${company.activeId}`)).data
  loading.value = false
}
async function loadSustentos() {
  const res = await api.get('/catalogos/sustentos')
  sustentos.value = res.data
}
async function importar(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  await enviarImportacion(file, null)
}
async function enviarImportacion(file: File, series: Record<string, string[]> | null) {
  importing.value = true; msg.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId))
  form.append('xml', file)
  if (sustento.value) form.append('sustento_tributario', sustento.value)
  if (series) form.append('series', JSON.stringify(series))
  try {
    const res = await api.post('/purchases/import', form)
    seriesImport.value = null
    archivoPendiente.value = null
    importada.value = res.data
    msg.value = { type: 'success', text: `Compra ${res.data.numero} de ${res.data.contact.razon_social} importada con sustento ${res.data.sustento_tributario} — ${money(res.data.importe_total)}` }
    load()
  } catch (err: any) {
    const data = err.response?.data
    if (err.response?.status === 422 && data?.requiere_series) {
      // Paso de captura de series: una entrada por producto, con lo que ya se había escrito
      archivoPendiente.value = file
      const previas = seriesImport.value?.series ?? series ?? {}
      seriesImport.value = {
        mensaje: data.message,
        error: series ? data.message : '',
        lineas: data.lineas,
        series: Object.fromEntries(data.lineas.map((l: any) => [l.codigo, previas[l.codigo] ?? []])),
        valido: {},
      }
      return
    }
    const textoSeries = data?.errors?.series?.join(' ')
    if (seriesImport.value && textoSeries) {
      seriesImport.value.error = textoSeries   // series repetidas o que ya existen: se corrigen en el mismo diálogo
      return
    }
    msg.value = { type: 'error', text: data?.errors?.xml?.[0] ?? textoSeries ?? data?.message ?? 'No se pudo importar.' }
  } finally {
    importing.value = false
    if (fileRef.value) fileRef.value.value = ''
  }
}
onMounted(() => { load(); loadSustentos() })
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Compras</h2>
      <div style="display:flex; gap:10px; align-items:center;">
        <label style="display:flex; flex-direction:column; gap:2px; font-size:12px; color:#64748b;">
          Sustento tributario
          <Select v-model="sustento" :options="opcionesSustento" optionLabel="label" optionValue="value" style="width:320px;" />
        </label>
        <input ref="fileRef" type="file" accept=".xml" style="display:none" @change="importar" />
        <Button label="Importar factura (XML del SRI)" icon="pi pi-upload" :loading="importing" @click="fileRef?.click()" />
      </div>
    </div>

    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Suba el XML que le envía su proveedor (o que descarga del portal del SRI). El sistema crea
      el proveedor, registra la compra y calcula el crédito tributario del IVA. No necesita el .p12.
    </Message>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">
      {{ msg.text }}
      <Button v-if="msg.type === 'success' && importada && puedeRetener" label="Aplicar retención" icon="pi pi-percentage"
              size="small" text style="margin-left:10px;" @click="abrirRetencion(importada, true)" />
    </Message>

    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha_emision).slice(0,10) }}</template></Column>
      <Column field="numero" header="Comprobante" />
      <Column header="Proveedor"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column header="Base"><template #body="{ data }">{{ money(data.total_sin_impuestos) }}</template></Column>
      <Column header="IVA"><template #body="{ data }">{{ money(data.total_impuesto) }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Sustento"><template #body="{ data }">{{ data.sustento_tributario }}</template></Column>
      <Column v-if="puedeRetener" header="Retención" style="width:120px;">
        <template #body="{ data }">
          <Button label="Retención" icon="pi pi-percentage" size="small" text @click="abrirRetencion(data)" />
        </template>
      </Column>
    </DataTable>

    <Dialog :visible="!!retencionDialog" modal header="Retención de la compra" style="width:820px"
            @update:visible="retencionDialog = null">
      <RetencionCompra v-if="retencionDialog" :purchase="retencionDialog.compra" :preguntar="retencionDialog.preguntar"
                       @registrada="retencionRegistrada" />
    </Dialog>

    <Dialog :visible="!!seriesImport" modal header="Series de la factura" style="width:560px"
            @update:visible="(v: boolean) => { if (!v) cancelarSeries() }">
      <div v-if="seriesImport" style="display:flex; flex-direction:column; gap:18px; max-height:65vh; overflow:auto;">
        <Message severity="info" :closable="false">
          Esta factura trae productos que manejan series y el XML no las incluye. Escribe o escanea una serie por cada
          unidad: sin ellas el stock no puede subir. Puedes usar el lector de código de barras.
        </Message>
        <Message v-if="seriesImport.error" severity="error" :closable="false">{{ seriesImport.error }}</Message>
        <div v-for="l in seriesImport.lineas" :key="l.codigo">
          <b>{{ l.descripcion }}</b>
          <span style="color:#64748b;"> · {{ l.codigo }} · {{ l.cantidad }} unidad{{ l.cantidad === 1 ? '' : 'es' }}</span>
          <div style="margin-top:8px;">
            <SeriesInput v-model="seriesImport.series[l.codigo]" :cantidad="l.cantidad"
                         @valido="(v: boolean) => marcarValida(l.codigo, v)" />
          </div>
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="cancelarSeries" />
        <Button label="Importar factura" icon="pi pi-upload" :loading="importing" :disabled="!seriesCompletas"
                @click="importarConSeries" />
      </template>
    </Dialog>
  </div>
</template>
