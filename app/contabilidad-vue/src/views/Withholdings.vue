<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { usePlanStore } from '../stores/plan'

const company = useCompanyStore()
const plan = usePlanStore()
const rows = ref<any[]>([])
const emitidas = ref<any[]>([])
const reintentando = ref<number | null>(null)
const loading = ref(true)
const importing = ref(false)
const msg = ref<any>(null)
const fileRef = ref<HTMLInputElement>()
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/withholdings?company_id=' + company.activeId)).data
  // Las que la empresa emite a sus proveedores (con el módulo del SRI): una fila por comprobante
  if (plan.tiene('facturacion_sri')) {
    try {
      emitidas.value = (await api.get('/withholdings-emitted?company_id=' + company.activeId)).data
    } catch {
      emitidas.value = []
    }
  }
  loading.value = false
}

// El comprobante tiene una fila por línea (mismo número): se muestra uno solo, con lo retenido sumado
const comprobantesEmitidos = computed(() => {
  const porNumero = new Map<string, any>()
  for (const w of [...emitidas.value].reverse()) {
    const c = porNumero.get(w.numero) ?? { id: w.id, numero: w.numero, fecha: w.fecha, compra: w.purchase?.numero ?? '', total: 0, estado: null, lineas: 0 }
    c.total += Number(w.total_retenido)
    c.lineas += 1
    c.estado = c.estado ?? w.sri_document?.estado ?? null
    porNumero.set(w.numero, c)
  }
  return [...porNumero.values()].reverse()
})

async function generarComprobante(c: any) {
  reintentando.value = c.id
  msg.value = null
  try {
    const { data } = await api.post(`/withholdings-emitted/${c.id}/emit`, { company_id: company.activeId })
    msg.value = { type: data.emision?.estado === 'error' ? 'warn' : 'success', text: data.emision?.mensaje ?? 'Listo.' }
    await load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'No se pudo generar el comprobante.' }
  } finally {
    reintentando.value = null
  }
}

function etiquetaEstado(estado: string | null) {
  if (!estado) return 'Sin comprobante'
  const e = estado.toLowerCase()
  return ({ generado: 'Generado', firmado: 'Firmado', enviado: 'Enviado al SRI', autorizado: 'Autorizado' } as any)[e] ?? estado
}
const severidadEstado = (estado: string | null) =>
  (estado ?? '').toLowerCase() === 'autorizado' ? 'success' : (estado ?? '').toLowerCase() === 'generado' ? 'info' : 'warn'
async function importar(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  importing.value = true; msg.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId)); form.append('xml', file)
  try {
    const res = await api.post('/withholdings/import', form)
    const emp = res.data.invoice ? ('empató con la factura ' + res.data.invoice.numero) : 'sin factura asociada'
    msg.value = { type: 'success', text: 'Retención ' + res.data.numero + ' del ' + String(res.data.fecha).slice(0, 10) + ' importada — ' + emp }
    load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.errors?.xml?.[0] ?? 'No se pudo importar.' }
  } finally { importing.value = false; if (fileRef.value) fileRef.value.value = '' }
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Retenciones recibidas</h2>
      <input ref="fileRef" type="file" accept=".xml" style="display:none" @change="importar" />
      <Button label="Subir retención (XML)" icon="pi pi-upload" :loading="importing" @click="fileRef?.click()" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Suba el XML de la retención que le emitió su cliente. El sistema la empata automáticamente
      con su factura leyendo el número del documento sustento — sin digitar nada. La fecha de la retención es la de
      emisión del XML, y si el mismo comprobante se sube dos veces el sistema avisa y no lo registra de nuevo.
    </Message>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="numero" header="Retención" />
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column header="Retenido"><template #body="{ data }">{{ money(data.total_retenido) }}</template></Column>
      <Column header="Empatada con">
        <template #body="{ data }">
          <Tag v-if="data.invoice" :value="'Factura ' + data.invoice.numero" severity="success" />
          <Tag v-else value="Sin factura" severity="warn" />
        </template>
      </Column>
    </DataTable>

    <!-- Retenciones que la empresa le emite a sus proveedores (se registran desde la compra) -->
    <template v-if="plan.tiene('facturacion_sri')">
      <h2 style="margin:26px 0 10px;">Retenciones emitidas a proveedores</h2>
      <Message severity="info" :closable="false" style="margin-bottom:14px;">
        Se registran desde la compra (Compras, pestaña Retención). El comprobante electrónico queda generado y se
        enviará al SRI cuando la empresa cargue su certificado de firma.
      </Message>
      <DataTable :value="comprobantesEmitidos" :loading="loading" size="small" stripedRows>
        <Column field="numero" header="Retención" />
        <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha ?? '').slice(0,10) }}</template></Column>
        <Column field="compra" header="Compra" />
        <Column header="Líneas"><template #body="{ data }">{{ data.lineas }}</template></Column>
        <Column header="Retenido"><template #body="{ data }">{{ money(data.total) }}</template></Column>
        <Column header="Comprobante electrónico">
          <template #body="{ data }">
            <Tag :value="etiquetaEstado(data.estado)" :severity="severidadEstado(data.estado)" />
            <Button v-if="!data.estado" label="Generar" icon="pi pi-refresh" size="small" text
                    :loading="reintentando === data.id" @click="generarComprobante(data)" />
          </template>
        </Column>
        <template #empty>Todavía no hay retenciones emitidas.</template>
      </DataTable>
    </template>
  </div>
</template>
