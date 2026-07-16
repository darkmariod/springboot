<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const asientos = ref<any[]>([])
const income = ref<any>(null)
const balance = ref<any>(null)
const loading = ref(true)

const money = (n: any) => `$${Number(n).toFixed(2)}`

async function load() {
  loading.value = true
  const [j, i, b] = await Promise.all([
    api.get(`/journal?company_id=${company.activeId}`),
    api.get(`/income-statement?company_id=${company.activeId}`),
    api.get(`/balance-sheet?company_id=${company.activeId}`),
  ])
  asientos.value = j.data; income.value = i.data; balance.value = b.data
  loading.value = false
}
async function mayorizar() {
  await api.post('/journal/mayorizar', { company_id: company.activeId })
  load()
}
async function desmayorizar(e: any) {
  await api.post('/journal/' + e.id + '/desmayorizar')
  load()
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Contabilidad</h2>
      <Button label="Mayorizar pendientes" icon="pi pi-check" size="small" @click="mayorizar" />
    </div>

    <!-- Estados financieros -->
    <div v-if="income && balance" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; margin-bottom:20px;">
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Ingresos</div><div style="font-size:20px; font-weight:700;">{{ money(income.ingresos) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Gastos</div><div style="font-size:20px; font-weight:700;">{{ money(income.gastos) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Utilidad</div><div style="font-size:20px; font-weight:700;">{{ money(income.utilidad_ejercicio) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Activos</div><div style="font-size:20px; font-weight:700;">{{ money(balance.activos) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Balance</div><Tag :value="balance.cuadrado ? 'Cuadrado ✓' : 'No cuadra'" :severity="balance.cuadrado ? 'success':'danger'" /></template></Card>
    </div>

    <h3 style="margin:0 0 8px;">Libro diario</h3>
    <DataTable :value="asientos" :loading="loading" size="small" stripedRows>
      <Column field="numero" header="Asiento" />
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column field="concepto" header="Concepto" />
      <Column header="Debe"><template #body="{ data }">{{ money(data.total_debe) }}</template></Column>
      <Column header="Estado"><template #body="{ data }"><Tag :value="data.estado" :severity="data.estado==='mayorizado'?'success':'warn'" /></template></Column>
      <Column header=""><template #body="{ data }">
        <Button v-if="data.estado==='mayorizado'" label="Desmayorizar" size="small" text @click="desmayorizar(data)" />
      </template></Column>
    </DataTable>
  </div>
</template>
