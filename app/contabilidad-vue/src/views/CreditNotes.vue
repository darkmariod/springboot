<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const contacts = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ tipo: 'interna', importe_total: 0 })
const tipos = [
  { label: 'Interna (no va al SRI)', value: 'interna' },
  { label: 'SRI (comprobante electrónico)', value: 'sri' },
]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/credit-notes?company_id=' + company.activeId)).data
  contacts.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  loading.value = false
}
async function guardar() {
  await api.post('/credit-notes', { ...form.value, company_id: company.activeId })
  dialog.value = false; form.value = { tipo: 'interna', importe_total: 0 }; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Notas de crédito</h2>
      <Button label="Nueva nota" icon="pi pi-plus" @click="dialog = true" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      La nota <b>interna</b> da de baja una deuda por cruce de cuentas y no se envía al SRI.
      La nota <b>SRI</b> es un comprobante electrónico (devolución de mercadería).
    </Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column header="Tipo"><template #body="{ data }">
        <Tag :value="data.tipo" :severity="data.tipo==='sri' ? 'info' : 'secondary'" /></template></Column>
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column field="motivo" header="Motivo" />
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Sin usar"><template #body="{ data }"><b>{{ money(data.saldo_disponible) }}</b></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Nueva nota de crédito" style="width:440px">
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos de la nota</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Tipo:</label>
          <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Cliente:</label>
          <Select v-model="form.contact_id" :options="contacts" optionLabel="razon_social" optionValue="id" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Motivo:</label>
          <InputText v-model="form.motivo" placeholder="Devolución de mercadería / cruce" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Importe total:</label>
          <InputNumber v-model="form.importe_total" mode="currency" currency="USD" class="kvs-in" />
        </div>
      </fieldset>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="dialog=false" />
          <Button label="Guardar" @click="guardar" />
        </div>
      </template>
    </Dialog>
  </div>
</template>
