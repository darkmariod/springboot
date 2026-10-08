<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const trabajando = ref(false)
const msg = ref<any>(null)
const fileRef = ref<HTMLInputElement>()
const sev: Record<string, string> = { pendiente: 'warn', procesada: 'success', error: 'danger', omitido: 'secondary' }
const etiqueta: Record<string, string> = { pendiente: 'pendiente', procesada: 'procesada', error: 'error', omitido: 'omitido' }

async function load() {
  loading.value = true
  rows.value = (await api.get('/pending-imports?company_id=' + company.activeId)).data
  loading.value = false
}
async function subirTxt(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  trabajando.value = true; msg.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId)); form.append('txt', file)
  try {
    const res = await api.post('/pending-imports/upload-txt', form)
    const d = res.data
    msg.value = { type: 'success',
      text: `${d.insertadas} facturas nuevas · ${d.omitidas ?? 0} omitidas (no son facturas de compra) · ${d.repetidas} ya estaban`
        + (d.sin_clave ? ` · ${d.sin_clave} línea(s) sin clave de acceso` : '') }
    load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'No se pudo leer el TXT.' }
  } finally { trabajando.value = false; if (fileRef.value) fileRef.value.value = '' }
}
async function procesar() {
  trabajando.value = true; msg.value = null
  const res = await api.post('/pending-imports/process', { company_id: company.activeId })
  msg.value = { type: res.data.errores ? 'warn' : 'success',
    text: `${res.data.procesadas} compras registradas · ${res.data.errores} con error` }
  trabajando.value = false; load()
}
// Procesar UNA factura de la lista (como en KVS: se elige y se procesa)
const procesandoId = ref<number | null>(null)
async function procesarUna(row: any) {
  procesandoId.value = row.id; msg.value = null
  try {
    const res = await api.post('/pending-imports/' + row.id + '/process')
    msg.value = { type: 'success',
      text: 'Compra ' + res.data.purchase.numero + ' de ' + res.data.purchase.contact.razon_social + ' registrada.' }
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.error ?? 'No se pudo procesar.' }
  } finally { procesandoId.value = null; load() }
}

// ---- Ventas emitidas: se registran subiendo el XML autorizado que se descarga del portal del SRI
const ventasRef = ref<HTMLInputElement>()
const subiendoVentas = ref(false)
const ventas = ref<any>(null)
async function subirVentas(e: Event) {
  const files = Array.from((e.target as HTMLInputElement).files ?? [])
  if (!files.length) return
  subiendoVentas.value = true; ventas.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId))
  files.forEach(f => form.append('xml_files[]', f))
  try {
    ventas.value = (await api.post('/sri/importar-ventas-xml', form)).data
  } catch (err: any) {
    ventas.value = err.response?.data?.rechazadas
      ? err.response.data
      : { importadas: [], rechazadas: [{ archivo: '', motivo: err.response?.data?.message ?? 'No se pudo importar.' }] }
  } finally { subiendoVentas.value = false; if (ventasRef.value) ventasRef.value.value = '' }
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div>
        <h2 style="margin:0;">Importar compras del SRI (lote)</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">
          Sube el TXT que descargas del portal del SRI con todos los comprobantes recibidos del mes.
          Solo las facturas se importan como compra; retenciones, notas de crédito y demás quedan como omitidos.
        </p>
      </div>
      <div style="display:flex; gap:8px;">
        <input ref="fileRef" type="file" accept=".txt" style="display:none" @change="subirTxt" />
        <Button label="Subir TXT del SRI" icon="pi pi-upload" :loading="trabajando" @click="fileRef?.click()" />
        <Button label="Traer XML y procesar" icon="pi pi-cloud-download" severity="secondary"
                :loading="trabajando" :disabled="!rows.some(r => r.estado === 'pendiente')" @click="procesar" />
      </div>
    </div>

    <Message severity="warn" :closable="false" style="margin-bottom:14px;">
      El SRI solo entrega el XML durante <b>~1 mes</b> desde la emisión. Importe todos los meses
      o va a perder esos comprobantes.
    </Message>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>

    <DataTable :value="rows" :loading="loading" size="small" stripedRows paginator :rows="15">
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha ?? '').slice(0,10) }}</template></Column>
      <Column field="ruc_emisor" header="RUC emisor" />
      <Column field="razon_social" header="Proveedor" />
      <Column header="Clave de acceso"><template #body="{ data }">
        <span style="font-family:monospace; font-size:11px;">{{ data.clave_acceso }}</span></template></Column>
      <Column header="Estado"><template #body="{ data }">
        <Tag :value="etiqueta[data.estado] ?? data.estado" :severity="sev[data.estado]" /></template></Column>
      <Column header="Detalle"><template #body="{ data }">
        <span :style="{ fontSize: '12px', color: data.estado === 'omitido' ? '#64748b' : '#d93025' }">{{ data.error }}</span></template></Column>
      <Column header=""><template #body="{ data }">
        <Button v-if="data.estado === 'pendiente'" label="Procesar" size="small" outlined
                :loading="procesandoId === data.id" @click="procesarUna(data)" />
      </template></Column>
    </DataTable>

    <!-- Ventas emitidas desde el portal del SRI -->
    <div style="margin-top:28px; padding-top:18px; border-top:1px solid #e2e5ea;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:12px;">
        <div>
          <h2 style="margin:0;">Ventas emitidas (XML)</h2>
          <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">
            Si facturas desde el portal del SRI, sube aquí el XML autorizado de cada factura para registrar la venta.
            No se mueve el inventario; la factura queda por cobrar.
          </p>
        </div>
        <div>
          <input ref="ventasRef" type="file" accept=".xml" multiple style="display:none" @change="subirVentas" />
          <Button label="Subir XML de ventas" icon="pi pi-upload" severity="secondary" :loading="subiendoVentas" @click="ventasRef?.click()" />
        </div>
      </div>
      <template v-if="ventas">
        <Message v-if="ventas.importadas.length" severity="success" :closable="false" style="margin-bottom:10px;">
          {{ ventas.importadas.length }} venta(s) registrada(s):
          <span v-for="v in ventas.importadas" :key="v.id" style="display:block; font-size:13px;">
            {{ v.numero }} — {{ v.cliente }} · ${{ Number(v.total).toFixed(2) }}
          </span>
        </Message>
        <Message v-if="ventas.rechazadas.length" severity="warn" :closable="false">
          {{ ventas.rechazadas.length }} archivo(s) no se registraron:
          <span v-for="(x, i) in ventas.rechazadas" :key="i" style="display:block; font-size:13px;">
            <b v-if="x.archivo">{{ x.archivo }}:</b> {{ x.motivo }}
          </span>
        </Message>
      </template>
    </div>
  </div>
</template>
