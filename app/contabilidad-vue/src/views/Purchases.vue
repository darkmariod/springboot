<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import RetencionCompra from '../components/RetencionCompra.vue'
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

const seriesDialog = ref<any>(null)

async function pedirSeries(purchase: any) {
  const productos = (await api.get('/products?company_id=' + company.activeId)).data
  const pendientes = (purchase.items ?? [])
    .map((it: any) => {
      const p = productos.find((x: any) => x.codigo === it.codigo_principal)
      return p?.maneja_series ? { product: p, cantidad: Number(it.cantidad), series: [] as string[] } : null
    })
    .filter(Boolean)
  if (pendientes.length) seriesDialog.value = { purchase, pendientes, idx: 0 }
}
async function guardarSeries() {
  for (const p of seriesDialog.value.pendientes) {
    const limpias = p.series.filter((s: string) => s.trim())
    if (limpias.length) {
      await api.post('/series', {
        company_id: company.activeId, product_id: p.product.id,
        purchase_id: seriesDialog.value.purchase.id, series: limpias,
      })
    }
  }
  seriesDialog.value = null
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
  importing.value = true; msg.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId))
  form.append('xml', file)
  if (sustento.value) form.append('sustento_tributario', sustento.value)
  try {
    const res = await api.post('/purchases/import', form)
    importada.value = res.data
    msg.value = { type: 'success', text: `Compra ${res.data.numero} de ${res.data.contact.razon_social} importada con sustento ${res.data.sustento_tributario} — ${money(res.data.importe_total)}` }
    load()
    pedirSeries(res.data)
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.errors?.xml?.[0] ?? err.response?.data?.message ?? 'No se pudo importar.' }
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

    <Dialog :visible="!!seriesDialog" modal header="Ingresar series de los productos" style="width:520px"
            @update:visible="seriesDialog=null">
      <div v-if="seriesDialog" style="display:flex; flex-direction:column; gap:18px;">
        <Message severity="info" :closable="false">
          Puede usar el lector de código de barras: escanee y presione Enter en cada campo.
        </Message>
        <div v-for="p in seriesDialog.pendientes" :key="p.product.id">
          <b>{{ p.product.descripcion }}</b>
          <span style="color:#94a3b8;"> — {{ p.cantidad }} unidades</span>
          <div style="display:flex; flex-direction:column; gap:6px; margin-top:8px;">
            <InputText v-for="n in p.cantidad" :key="n" v-model="p.series[n-1]"
                       :placeholder="'Serie ' + n" />
          </div>
        </div>
      </div>
      <template #footer>
        <Button label="Después" text @click="seriesDialog=null" />
        <Button label="Guardar series" @click="guardarSeries" />
      </template>
    </Dialog>
  </div>
</template>
