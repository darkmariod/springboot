<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const importing = ref(false)
const msg = ref<{ type: string; text: string } | null>(null)
const fileRef = ref<HTMLInputElement>()

const money = (n: any) => `$${Number(n).toFixed(2)}`

async function load() {
  loading.value = true
  rows.value = (await api.get(`/purchases?company_id=${company.activeId}`)).data
  loading.value = false
}
async function importar(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  importing.value = true; msg.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId))
  form.append('xml', file)
  try {
    const res = await api.post('/purchases/import', form)
    msg.value = { type: 'success', text: `Compra ${res.data.numero} de ${res.data.contact.razon_social} importada — ${money(res.data.importe_total)}` }
    load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.errors?.xml?.[0] ?? err.response?.data?.message ?? 'No se pudo importar.' }
  } finally {
    importing.value = false
    if (fileRef.value) fileRef.value.value = ''
  }
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Compras</h2>
      <input ref="fileRef" type="file" accept=".xml" style="display:none" @change="importar" />
      <Button label="Importar factura (XML del SRI)" icon="pi pi-upload" :loading="importing" @click="fileRef?.click()" />
    </div>

    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Subí el XML que te envía tu proveedor (o que bajás del portal del SRI). El sistema crea
      el proveedor, registra la compra y calcula el crédito tributario del IVA. No necesita el .p12.
    </Message>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>

    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha_emision).slice(0,10) }}</template></Column>
      <Column field="numero" header="Comprobante" />
      <Column header="Proveedor"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column header="Base"><template #body="{ data }">{{ money(data.total_sin_impuestos) }}</template></Column>
      <Column header="IVA"><template #body="{ data }">{{ money(data.total_impuesto) }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
    </DataTable>
  </div>
</template>
