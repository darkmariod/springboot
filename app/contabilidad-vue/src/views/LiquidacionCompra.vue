<template>
  <div style="padding: 20px">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
      <h3 style="margin: 0">Liquidación de Compra</h3>
      <Button label="Nueva Liquidación" icon="pi pi-plus" @click="dialog = true" />
    </div>

    <DataTable :value="rows" :loading="loading" stripedRows size="small">
      <Column field="numero" header="Número" />
      <Column field="contact.razon_social" header="Proveedor" />
      <Column field="fecha_emision" header="Fecha" />
      <Column field="importe_total" header="Total">
        <template #body="{ data }">$ {{ Number(data.importe_total).toFixed(2) }}</template>
      </Column>
      <Column header="Estado">
        <template #body="{ data }">
          <Tag :value="data.tipo_comprobante === 'liquidacion_compra' ? 'Pendiente' : data.tipo_comprobante" severity="warn" />
        </template>
      </Column>
      <Column header="Acciones" style="width: 120px">
        <template #body="{ data }">
          <Button icon="pi pi-send" size="small" label="Emitir" @click="emitir(data)" :loading="emitting === data.id" />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialog" header="Nueva Liquidación de Compra" modal style="width: 600px">
      <div class="kvs-fieldset">
        <div class="kvs-row">
          <label class="kvs-lbl">Proveedor</label>
          <Select v-model="form.contact_id" :options="contacts" optionLabel="razon_social" optionValue="id" class="kvs-in" placeholder="Seleccionar proveedor" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Descripción</label>
          <InputText v-model="form.descripcion" class="kvs-in" placeholder="Descripción de la compra" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Cantidad</label>
          <InputNumber v-model="form.cantidad" class="kvs-in" :min="1" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Precio Unitario</label>
          <InputNumber v-model="form.precio_unitario" class="kvs-in" :minFractionDigits="2" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Total</label>
          <InputNumber :modelValue="form.cantidad * form.precio_unitario" class="kvs-in" disabled :minFractionDigits="2" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Forma de Pago</label>
          <Select v-model="form.forma_pago" :options="formasPago" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog = false" />
        <Button label="Guardar" @click="guardar" :loading="saving" />
      </template>
    </Dialog>

    <Message v-if="msg" :severity="msgOk ? 'success' : 'error'" :closable="false" style="margin-top: 12px">{{ msg }}</Message>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
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
const loading = ref(false)
const dialog = ref(false)
const saving = ref(false)
const emitting = ref<number | null>(null)
const msg = ref('')
const msgOk = ref(true)

const formasPago = [
  { label: 'Efectivo', value: '01' },
  { label: 'Transferencia', value: '20' },
  { label: 'Tarjeta de crédito', value: '19' },
  { label: 'Tarjeta de débito', value: '18' },
]

const form = ref({
  contact_id: null as number | null,
  descripcion: '',
  cantidad: 1,
  precio_unitario: 0,
  forma_pago: '01',
})

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/liquidacion-compra?company_id=' + company.activeId)
    rows.value = data
  } finally {
    loading.value = false
  }
}

async function loadContacts() {
  const { data } = await api.get('/contacts?company_id=' + company.activeId + '&tipo=proveedor')
  contacts.value = data
}

async function guardar() {
  if (!form.value.contact_id) return
  saving.value = true
  msg.value = ''
  try {
    const total = form.value.cantidad * form.value.precio_unitario
    await api.post('/liquidacion-compra', {
      company_id: company.activeId,
      contact_id: form.value.contact_id,
      items: [{
        codigo_principal: 'LIQ-001',
        descripcion: form.value.descripcion,
        cantidad: form.value.cantidad,
        precio_unitario: form.value.precio_unitario,
        base_imponible: total,
        tarifa: 15,
      }],
      total_sin_impuestos: total,
      total_impuesto: total * 0.15,
      importe_total: total * 1.15,
      forma_pago: form.value.forma_pago,
    })
    dialog.value = false
    msg.value = 'Liquidación guardada'
    msgOk.value = true
    form.value = { contact_id: null, descripcion: '', cantidad: 1, precio_unitario: 0, forma_pago: '01' }
    await load()
  } catch (e: any) {
    msg.value = e?.response?.data?.message || 'Error al guardar'
    msgOk.value = false
  } finally {
    saving.value = false
  }
}

async function emitir(row: any) {
  emitting.value = row.id
  msg.value = ''
  try {
    const { data } = await api.post(`/liquidacion-compra/${row.id}/emit`)
    msg.value = data.mensaje || 'Emitida'
    msgOk.value = true
    await load()
  } catch (e: any) {
    msg.value = e?.response?.data?.message || 'Error al emitir'
    msgOk.value = false
  } finally {
    emitting.value = null
  }
}

onMounted(() => { load(); loadContacts() })
</script>
