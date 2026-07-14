<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/quotes?company_id=' + company.activeId)).data
  loading.value = false
}
async function convertir(q: any) {
  if (!confirm('¿Convertir la cotización en factura y emitirla al SRI?')) return
  await api.post('/quotes/' + q.id + '/convert', { forma_pago: 'efectivo' })
  load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Cotizaciones</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">Se crean desde el Punto de Venta con "Guardar cotización". Si el cliente aprueba, se convierten en factura con un clic.</p>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="id" header="#" />
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Estado"><template #body="{ data }"><Tag :value="data.estado" :severity="data.estado==='facturada'?'success':'warn'" /></template></Column>
      <Column header="">
        <template #body="{ data }">
          <Button v-if="data.estado==='pendiente'" label="Convertir en factura" size="small" @click="convertir(data)" />
        </template>
      </Column>
    </DataTable>
  </div>
</template>
