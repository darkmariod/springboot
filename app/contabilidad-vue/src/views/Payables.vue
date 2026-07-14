<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const data = ref<any>({ cartera: [], total: 0 })
const banks = ref<any[]>([])
const loading = ref(true)
const pago = ref<any>(null)
const formas = [
  { label: 'Efectivo', value: 'efectivo' }, { label: 'Transferencia', value: 'transferencia' },
  { label: 'Cheque', value: 'cheque' }, { label: 'Cruce de cuenta', value: 'cruce' },
]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  data.value = (await api.get('/payables?company_id=' + company.activeId)).data
  banks.value = (await api.get('/banks?company_id=' + company.activeId)).data
  loading.value = false
}
function abrirPago(r: any) { pago.value = { purchase: r, monto: r.saldo, forma_pago: 'efectivo', bank_id: null, cheque_numero: '' } }
async function pagar() {
  await api.post('/payables/' + pago.value.purchase.id + '/pay', {
    monto: pago.value.monto, forma_pago: pago.value.forma_pago,
    bank_id: pago.value.bank_id, cheque_numero: pago.value.cheque_numero || null,
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

    <Dialog :visible="!!pago" modal header="Pagar al proveedor" style="width:420px" @update:visible="pago=null">
      <div v-if="pago" style="display:flex; flex-direction:column; gap:12px;">
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          {{ pago.purchase.numero }} — {{ pago.purchase.proveedor }} · saldo {{ money(pago.purchase.saldo) }}</div>
        <label style="display:flex; flex-direction:column; gap:4px;">Monto<InputNumber v-model="pago.monto" mode="currency" currency="USD" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Forma de pago<Select v-model="pago.forma_pago" :options="formas" optionLabel="label" optionValue="value" fluid /></label>
        <label v-if="['cheque','transferencia'].includes(pago.forma_pago)" style="display:flex; flex-direction:column; gap:4px;">Banco
          <Select v-model="pago.bank_id" :options="banks" optionLabel="nombre" optionValue="id" fluid /></label>
        <label v-if="pago.forma_pago==='cheque'" style="display:flex; flex-direction:column; gap:4px;">N° de cheque
          <InputText v-model="pago.cheque_numero" fluid /></label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="pago=null" />
        <Button label="Pagar" @click="pagar" />
      </template>
    </Dialog>
  </div>
</template>
