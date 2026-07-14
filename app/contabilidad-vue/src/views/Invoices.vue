<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)

const estadoSev: Record<string, string> = {
  generado: 'warn', firmado: 'info', enviado: 'info', AUTORIZADO: 'success', autorizado: 'success',
}
const money = (n: any) => `$${Number(n).toFixed(2)}`

async function load() {
  loading.value = true
  rows.value = (await api.get(`/invoices?company_id=${company.activeId}`)).data
  loading.value = false
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <h2 style="margin:0 0 14px;">Facturas</h2>
    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column field="numero" header="Número" />
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha_emision).slice(0,10) }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Pago"><template #body="{ data }">{{ data.forma_pago }}</template></Column>
      <Column header="Estado SRI">
        <template #body="{ data }">
          <Tag :value="data.sri_document?.estado ?? '—'" :severity="estadoSev[data.sri_document?.estado] ?? 'secondary'" />
        </template>
      </Column>
    </DataTable>
  </div>
</template>
