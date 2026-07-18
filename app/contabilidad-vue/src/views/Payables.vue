<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import FormasPago from '../components/FormasPago.vue'

const company = useCompanyStore()
const data = ref<any>({ cartera: [], total: 0 })
const banks = ref<any[]>([])
const loading = ref(true)
const pago = ref<any>(null)
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  data.value = (await api.get('/payables?company_id=' + company.activeId)).data
  banks.value = (await api.get('/banks?company_id=' + company.activeId)).data
  loading.value = false
}
function abrirPago(r: any) {
  pago.value = {
    purchase: r,
    pagos: [{ id: 1, tipo: 'efectivo', fecha: '', valor: r.saldo, bank_id: null, documento: null, cuenta: null }],
  }
}
async function pagar() {
  await api.post('/payables/' + pago.value.purchase.id + '/pay', {
    pagos: pago.value.pagos.map((p: any) => ({
      tipo: p.tipo, valor: p.valor, bank_id: p.bank_id, documento: p.documento,
    })),
  })
  pago.value = null; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div><h2 style="margin:0;">Cuentas por pagar</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">Cartera de proveedores</p></div>
      <div style="text-align:right;"><div style="font-size:11px; color:#94a3b8;">TOTAL POR PAGAR</div>
        <div style="font-size:20px; font-weight:700;">{{ money(data.total) }}</div></div>
    </div>
    <DataTable :value="data.cartera" :loading="loading" size="small" stripedRows>
      <Column field="numero" header="Factura" />
      <Column field="proveedor" header="Proveedor" />
      <Column field="fecha" header="Fecha" />
      <Column header="Total"><template #body="{ data: r }">{{ money(r.total) }}</template></Column>
      <Column header="Saldo"><template #body="{ data: r }"><b>{{ money(r.saldo) }}</b></template></Column>
      <Column header=""><template #body="{ data: r }"><Button label="Pagar" size="small" @click="abrirPago(r)" /></template></Column>
    </DataTable>

    <Dialog :visible="!!pago" modal header="Pagar al proveedor" style="width:760px" @update:visible="pago=null">
      <div v-if="pago" style="display:flex; flex-direction:column; gap:12px;">
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          {{ pago.purchase.numero }} — {{ pago.purchase.proveedor }} · saldo {{ money(pago.purchase.saldo) }}</div>
        <FormasPago v-model="pago.pagos" :total="pago.purchase.saldo" :banks="banks" />
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="pago=null" />
        <Button label="Pagar" @click="pagar" />
      </template>
    </Dialog>
  </div>
</template>
