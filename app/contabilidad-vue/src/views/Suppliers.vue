<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)

onMounted(async () => {
  const all = (await api.get('/contacts?company_id=' + company.activeId)).data
  rows.value = all.filter((c: any) => c.es_proveedor)
  loading.value = false
})
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Proveedores</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">Se crean automáticamente al importar compras del SRI (o desde Contactos)</p>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="identificacion" header="RUC" />
      <Column field="razon_social" header="Razón social" />
      <Column field="direccion" header="Dirección" />
      <Column field="telefono" header="Teléfono" />
    </DataTable>
  </div>
</template>
