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
const rows = ref<any[]>([])
const contacts = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ forma_pago: 'efectivo', monto: 0 })
const formas = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Transferencia', value: 'transferencia' },
  { label: 'Cheque', value: 'cheque' },
]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/advances?company_id=' + company.activeId)).data
  contacts.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  loading.value = false
}
async function guardar() {
  await api.post('/advances', { ...form.value, company_id: company.activeId })
  dialog.value = false; form.value = { forma_pago: 'efectivo', monto: 0 }; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div>
        <h2 style="margin:0;">Anticipos de clientes</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">
          Dinero recibido antes de facturar. Se cruza contra la factura desde Cuentas por cobrar.
        </p>
      </div>
      <Button label="Nuevo anticipo" icon="pi pi-plus" @click="dialog = true" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column field="forma_pago" header="Forma de pago" />
      <Column header="Monto"><template #body="{ data }">{{ money(data.monto) }}</template></Column>
      <Column header="Saldo sin usar"><template #body="{ data }"><b>{{ money(data.saldo) }}</b></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Nuevo anticipo" style="width:420px">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <label style="display:flex; flex-direction:column; gap:4px;">Cliente
          <Select v-model="form.contact_id" :options="contacts" optionLabel="razon_social" optionValue="id" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Monto
          <InputNumber v-model="form.monto" mode="currency" currency="USD" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Forma de pago
          <Select v-model="form.forma_pago" :options="formas" optionLabel="label" optionValue="value" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Nota
          <InputText v-model="form.nota" fluid /></label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog=false" />
        <Button label="Guardar" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
