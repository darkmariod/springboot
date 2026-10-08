<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const contacts = ref<any[]>([])
const banks = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const error = ref('')
// Anticipos de clientes (dinero recibido) o de proveedores (dinero entregado antes de que facturen)
const tipo = ref<'cliente' | 'proveedor'>('cliente')
const tipos = [
  { label: 'Clientes', value: 'cliente' },
  { label: 'Proveedores', value: 'proveedor' },
]
const nuevoForm = () => ({ forma_pago: 'efectivo', monto: 0, contact_id: null, bank_id: null, documento: null, nota: null })
const form = ref<any>(nuevoForm())
const formas = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Transferencia', value: 'transferencia' },
  { label: 'Cheque', value: 'cheque' },
]
const money = (n: any) => '$' + Number(n).toFixed(2)
const esProveedor = computed(() => tipo.value === 'proveedor')
const contactos = computed(() => contacts.value.filter(c => esProveedor.value ? c.es_proveedor : c.es_cliente))
const pideBanco = computed(() => form.value.forma_pago !== 'efectivo')

async function load() {
  loading.value = true
  rows.value = (await api.get('/advances', { params: { company_id: company.activeId, tipo: tipo.value } })).data
  loading.value = false
}
async function cargarCatalogos() {
  contacts.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  banks.value = (await api.get('/banks?company_id=' + company.activeId)).data
}
function abrir() {
  error.value = ''
  form.value = nuevoForm()
  dialog.value = true
}
async function guardar() {
  error.value = ''
  try {
    await api.post('/advances', {
      ...form.value, tipo: tipo.value, company_id: company.activeId,
      // El banco y el documento solo cuentan cuando el dinero no es efectivo
      bank_id: pideBanco.value ? form.value.bank_id : null,
      documento: pideBanco.value ? form.value.documento : null,
    })
  } catch (err: any) {
    const e = err.response?.data?.errors
    error.value = e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? 'No se pudo guardar el anticipo.')
    return
  }
  dialog.value = false; load()
}
// Al cambiar entre clientes y proveedores se recarga la lista
watch(tipo, () => load())
onMounted(async () => { await cargarCatalogos(); await load() })
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px;">
      <div>
        <h2 style="margin:0;">{{ esProveedor ? 'Anticipos a proveedores' : 'Anticipos de clientes' }}</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">
          <template v-if="esProveedor">
            Dinero entregado a un proveedor antes de que facture. Se aplica a su compra desde Cuentas por pagar (Usar anticipo).
          </template>
          <template v-else>
            Dinero recibido antes de facturar. Se cruza contra la factura desde Cuentas por cobrar.
          </template>
        </p>
      </div>
      <div style="display:flex; align-items:center; gap:12px;">
        <SelectButton v-model="tipo" :options="tipos" optionLabel="label" optionValue="value" :allowEmpty="false" />
        <Button label="Nuevo anticipo" icon="pi pi-plus" @click="abrir" />
      </div>
    </div>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column :header="esProveedor ? 'Proveedor' : 'Cliente'"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column field="forma_pago" header="Forma de pago" />
      <Column header="Monto"><template #body="{ data }">{{ money(data.monto) }}</template></Column>
      <Column header="Saldo sin usar"><template #body="{ data }"><b>{{ money(data.saldo) }}</b></template></Column>
      <template #empty>No hay anticipos {{ esProveedor ? 'a proveedores' : 'de clientes' }} registrados.</template>
    </DataTable>

    <Dialog v-model:visible="dialog" modal :header="esProveedor ? 'Nuevo anticipo a proveedor' : 'Nuevo anticipo de cliente'" style="width:440px">
      <Message v-if="error" severity="error" :closable="false" style="margin-bottom:10px;">{{ error }}</Message>
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos del anticipo</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">{{ esProveedor ? 'Proveedor:' : 'Cliente:' }}</label>
          <Select v-model="form.contact_id" :options="contactos" optionLabel="razon_social" optionValue="id" filter
                  :placeholder="esProveedor ? 'Elige el proveedor' : 'Elige el cliente'" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Monto:</label>
          <InputNumber v-model="form.monto" mode="currency" currency="USD" @focus="($event: Event) => ($event.target as HTMLInputElement).select()" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Forma de pago:</label>
          <Select v-model="form.forma_pago" :options="formas" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>
        <div v-if="pideBanco" class="kvs-row">
          <label class="kvs-lbl">Banco:</label>
          <Select v-model="form.bank_id" :options="banks" optionLabel="nombre" optionValue="id" placeholder="Elige el banco" showClear class="kvs-in" />
        </div>
        <div v-if="pideBanco" class="kvs-row">
          <label class="kvs-lbl">N.° de cheque o documento:</label>
          <InputText v-model="form.documento" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Nota:</label>
          <InputText v-model="form.nota" class="kvs-in" />
        </div>
      </fieldset>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="dialog=false" />
          <Button label="Guardar" :disabled="!form.contact_id || !(form.monto > 0)" @click="guardar" />
        </div>
      </template>
    </Dialog>
  </div>
</template>
