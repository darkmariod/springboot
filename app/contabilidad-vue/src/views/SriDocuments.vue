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
const procesando = ref(false)
const msg = ref<any>(null)

async function load() {
  loading.value = true
  rows.value = (await api.get('/sri-documents/pending?company_id=' + company.activeId)).data
  loading.value = false
}
async function autorizarLote() {
  procesando.value = true; msg.value = null
  const res = await api.post('/sri-documents/authorize-batch', { company_id: company.activeId })
  const d = res.data
  msg.value = { type: d.autorizados > 0 ? 'success' : 'warn',
    text: 'Procesados: ' + d.procesados + ' · Autorizados: ' + d.autorizados + (d.mensaje ? ' · ' + d.mensaje : '') }
  procesando.value = false; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div><h2 style="margin:0;">Documentos SRI</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">Solo los comprobantes NO autorizados — autorizalos en lote con un clic</p></div>
      <Button label="Autorizar en lote" icon="pi pi-check-square" :loading="procesando" :disabled="!rows.length" @click="autorizarLote" />
    </div>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="tipo_comprobante" header="Tipo" />
      <Column header="Clave de acceso"><template #body="{ data }"><span style="font-family:monospace; font-size:11px;">{{ data.clave_acceso }}</span></template></Column>
      <Column header="Estado"><template #body="{ data }"><Tag :value="data.estado" severity="warn" /></template></Column>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha_emision).slice(0,10) }}</template></Column>
    </DataTable>
    <p v-if="!loading && !rows.length" style="color:#22a06b; text-align:center; padding:20px;">
      ✓ Todos los comprobantes están autorizados.</p>
  </div>
</template>
