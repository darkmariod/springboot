<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const data = ref<any>({ items: [], valor_total: 0 })
const loading = ref(true)
const kardex = ref<any>(null)

const money = (n: any) => `$${Number(n).toFixed(2)}`

async function load() {
  loading.value = true
  data.value = (await api.get(`/inventory/stock?company_id=${company.activeId}`)).data
  loading.value = false
}
async function verKardex(p: any) {
  kardex.value = { loading: true, producto: p }
  kardex.value = (await api.get(`/inventory/kardex/${p.id}`)).data
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Inventario</h2>
      <div style="text-align:right;">
        <div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Valor del inventario</div>
        <div style="font-size:20px; font-weight:700;">{{ money(data.valor_total) }}</div>
      </div>
    </div>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">Existencias al costo promedio ponderado. Clic en un producto para ver su kardex.</p>
    <DataTable :value="data.items" :loading="loading" size="small" stripedRows @row-click="(e) => verKardex(e.data)" selectionMode="single" dataKey="id">
      <Column field="codigo" header="Código" />
      <Column field="descripcion" header="Producto" />
      <Column header="Stock"><template #body="{ data }">{{ Number(data.stock).toLocaleString() }}</template></Column>
      <Column header="Costo prom."><template #body="{ data }">{{ money(data.costo_promedio) }}</template></Column>
      <Column header="Valor"><template #body="{ data }">{{ money(data.valor) }}</template></Column>
    </DataTable>

    <Dialog :visible="!!kardex" modal :header="'Kardex — ' + (kardex?.producto?.descripcion ?? '')" style="width:640px" @update:visible="kardex=null">
      <DataTable v-if="kardex?.movimientos" :value="kardex.movimientos" size="small" stripedRows>
        <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
        <Column field="tipo" header="Tipo" />
        <Column field="concepto" header="Concepto" />
        <Column header="Cant."><template #body="{ data }">{{ data.tipo==='egreso'?'-':'+' }}{{ Number(data.cantidad) }}</template></Column>
        <Column header="Saldo"><template #body="{ data }">{{ Number(data.saldo_cantidad) }}</template></Column>
        <Column header="C. prom."><template #body="{ data }">{{ money(data.saldo_costo_promedio) }}</template></Column>
      </DataTable>
    </Dialog>
  </div>
</template>
