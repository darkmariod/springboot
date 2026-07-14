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
const money = (n: any) => '$' + Number(n).toFixed(2)
const sev: Record<string,string> = { activo:'info', pasivo:'warn', patrimonio:'secondary', ingreso:'success', gasto:'danger' }

onMounted(async () => {
  rows.value = (await api.get('/ledger?company_id=' + company.activeId)).data
  loading.value = false
})
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Libro mayor</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">Movimientos agrupados por cuenta contable</p>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="codigo" header="Código" />
      <Column field="nombre" header="Cuenta" />
      <Column header="Tipo"><template #body="{ data }"><Tag :value="data.tipo" :severity="sev[data.tipo]" /></template></Column>
      <Column header="Debe"><template #body="{ data }">{{ money(data.debe) }}</template></Column>
      <Column header="Haber"><template #body="{ data }">{{ money(data.haber) }}</template></Column>
      <Column header="Saldo"><template #body="{ data }"><b>{{ money(data.saldo) }}</b></template></Column>
      <Column field="movimientos" header="Movs." />
    </DataTable>
  </div>
</template>
