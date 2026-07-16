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
const importing = ref(false)
const msg = ref<any>(null)
const fileRef = ref<HTMLInputElement>()
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/withholdings?company_id=' + company.activeId)).data
  loading.value = false
}
async function importar(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  importing.value = true; msg.value = null
  const form = new FormData()
  form.append('company_id', String(company.activeId)); form.append('xml', file)
  try {
    const res = await api.post('/withholdings/import', form)
    const emp = res.data.invoice ? ('empató con la factura ' + res.data.invoice.numero) : 'sin factura asociada'
    msg.value = { type: 'success', text: 'Retención ' + res.data.numero + ' importada — ' + emp }
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
      con su factura leyendo el número del documento sustento — sin digitar nada.
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
  </div>
</template>
