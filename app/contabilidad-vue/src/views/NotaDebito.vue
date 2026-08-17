<template>
  <div style="padding: 20px">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
      <h3 style="margin: 0">Notas de Débito</h3>
      <Button label="Nueva Nota de Débito" icon="pi pi-plus" @click="openNew" />
    </div>

    <DataTable :value="rows" :loading="loading" stripedRows size="small">
      <Column field="numero" header="Número" />
      <Column field="contact.razon_social" header="Cliente" />
      <Column field="fecha_emision" header="Fecha" />
      <Column field="importe_total" header="Total">
        <template #body="{ data }">$ {{ Number(data.importe_total).toFixed(2) }}</template>
      </Column>
      <Column header="Acciones" style="width: 120px">
        <template #body="{ data }">
          <Button icon="pi pi-send" size="small" label="Emitir" @click="emitir(data)" :loading="emitting === data.id" />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialog" header="Nueva Nota de Débito" modal style="width: 650px">
      <div class="kvs-fieldset">
        <div class="kvs-row">
          <label class="kvs-lbl">Factura a referenciar</label>
          <Select v-model="form.invoice_id" :options="invoices" optionLabel="numero" optionValue="id" class="kvs-in" placeholder="Seleccionar factura" />
        </div>

        <h4 style="margin-top: 16px">Motivos del débito</h4>
        <div v-for="(mot, i) in form.motivos" :key="i" class="kvs-row" style="gap: 8px; align-items: end">
          <div style="flex: 2">
            <label class="kvs-lbl">Razón</label>
            <InputText v-model="mot.razon" class="kvs-in" placeholder="Ej: Interés por mora" />
          </div>
          <div style="flex: 1">
            <label class="kvs-lbl">Valor</label>
            <InputNumber v-model="mot.valor" class="kvs-in" :minFractionDigits="2" />
          </div>
          <Button icon="pi pi-trash" text severity="danger" @click="form.motivos.splice(i, 1)" :disabled="form.motivos.length <= 1" />
        </div>
        <Button label="Agregar motivo" icon="pi pi-plus" text size="small" @click="form.motivos.push({ razon: '', valor: 0 })" style="margin-top: 8px" />

        <div class="kvs-row" style="margin-top: 16px">
          <label class="kvs-lbl">Total</label>
          <InputNumber :modelValue="totalMotivos" class="kvs-in" disabled :minFractionDigits="2" />
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
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const invoices = ref<any[]>([])
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
]

const form = ref({
  invoice_id: null as number | null,
  motivos: [{ razon: '', valor: 0 }],
  forma_pago: '01',
})

const totalMotivos = computed(() => form.value.motivos.reduce((s, m) => s + (m.valor || 0), 0))

function openNew() {
  form.value = { invoice_id: null, motivos: [{ razon: '', valor: 0 }], forma_pago: '01' }
  dialog.value = true
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/notas-debito?company_id=' + company.activeId)
    rows.value = data
  } finally {
    loading.value = false
  }
}

async function loadInvoices() {
  const { data } = await api.get('/invoices?company_id=' + company.activeId)
  invoices.value = data
}

async function guardar() {
  if (!form.value.invoice_id) return
  saving.value = true
  msg.value = ''
  try {
    await api.post('/notas-debito', {
      company_id: company.activeId,
      invoice_id: form.value.invoice_id,
      motivos: form.value.motivos,
      forma_pago: form.value.forma_pago,
    })
    dialog.value = false
    msg.value = 'Nota de débito guardada'
    msgOk.value = true
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
    const { data } = await api.post(`/notas-debito/${row.id}/emit`)
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

onMounted(() => { load(); loadInvoices() })
</script>
