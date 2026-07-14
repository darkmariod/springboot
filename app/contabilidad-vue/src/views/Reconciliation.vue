<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const banks = ref<any[]>([])
const bankId = ref<number | null>(null)
const data = ref<any>({ movimientos: [], saldo_sistema: 0, saldo_conciliado: 0 })
const nuevo = ref<any>({ tipo: 'credito', monto: 0, concepto: '', fecha: new Date().toISOString().slice(0,10) })
const tipos = [{ label: 'Crédito (entra)', value: 'credito' }, { label: 'Débito (sale)', value: 'debito' }]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function loadBanks() {
  banks.value = (await api.get('/banks?company_id=' + company.activeId)).data
  bankId.value = banks.value[0]?.id ?? null
  if (bankId.value) load()
}
async function load() {
  data.value = (await api.get('/bank-movements?company_id=' + company.activeId + '&bank_id=' + bankId.value)).data
}
async function agregar() {
  await api.post('/bank-movements', { ...nuevo.value, company_id: company.activeId, bank_id: bankId.value })
  nuevo.value = { tipo: 'credito', monto: 0, concepto: '', fecha: new Date().toISOString().slice(0,10) }
  load()
}
async function toggle(m: any) { await api.post('/bank-movements/' + m.id + '/toggle'); load() }
onMounted(loadBanks)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:14px; gap:12px;">
      <div style="flex:1;"><h2 style="margin:0 0 8px;">Conciliación bancaria</h2>
        <Select v-model="bankId" :options="banks" optionLabel="nombre" optionValue="id" placeholder="Banco" @change="load" /></div>
      <div style="display:flex; gap:10px;">
        <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px 14px; background:#fff;">
          <div style="font-size:11px; color:#94a3b8;">SALDO SISTEMA</div><b>{{ money(data.saldo_sistema) }}</b></div>
        <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px 14px; background:#fff;">
          <div style="font-size:11px; color:#94a3b8;">CONCILIADO</div><b>{{ money(data.saldo_conciliado) }}</b></div>
      </div>
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Registrá los movimientos del estado de cuenta del banco y marcá los que ya verificaste.
      El saldo conciliado debe coincidir con el saldo final del estado de cuenta.
    </Message>
    <div style="display:flex; gap:8px; align-items:flex-end; margin-bottom:14px; background:#fff; border:1px solid #e2e5ea; border-radius:10px; padding:12px;">
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Tipo<Select v-model="nuevo.tipo" :options="tipos" optionLabel="label" optionValue="value" /></label>
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Monto<InputNumber v-model="nuevo.monto" mode="currency" currency="USD" /></label>
      <label style="flex:1; display:flex; flex-direction:column; gap:4px; font-size:12px;">Concepto<InputText v-model="nuevo.concepto" /></label>
      <Button label="Agregar" @click="agregar" />
    </div>
    <DataTable :value="data.movimientos" size="small" stripedRows>
      <Column header="Fecha"><template #body="{ data: m }">{{ String(m.fecha).slice(0,10) }}</template></Column>
      <Column field="concepto" header="Concepto" />
      <Column header="Monto"><template #body="{ data: m }">
        <span :style="{color: m.tipo==='credito' ? '#22a06b':'#d93025'}">{{ m.tipo==='credito'?'+':'-' }}{{ money(m.monto) }}</span></template></Column>
      <Column header="Conciliado"><template #body="{ data: m }">
        <Checkbox :modelValue="m.conciliado" :binary="true" @update:modelValue="toggle(m)" /></template></Column>
    </DataTable>
  </div>
</template>
