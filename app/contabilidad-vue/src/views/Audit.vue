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
const sev: Record<string, string> = { creo: 'success', actualizo: 'warn', elimino: 'danger' }

onMounted(async () => {
  rows.value = (await api.get('/audit?company_id=' + company.activeId)).data
  loading.value = false
})
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Auditoría</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">Quién hizo cada operación en el sistema</p>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows paginator :rows="20">
      <Column header="Cuándo"><template #body="{ data }">
        {{ new Date(data.created_at).toLocaleString() }}</template></Column>
      <Column header="Quién"><template #body="{ data }">{{ data.user?.name ?? '—' }}</template></Column>
      <Column header="Acción"><template #body="{ data }">
        <Tag :value="data.accion" :severity="sev[data.accion]" /></template></Column>
      <Column field="modelo" header="Módulo" />
      <Column field="descripcion" header="Documento" />
      <Column field="ip" header="IP" />
    </DataTable>
  </div>
</template>
