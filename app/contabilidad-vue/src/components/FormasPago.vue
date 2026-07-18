<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import DatePicker from 'primevue/datepicker'
import api from '../lib/api'

interface PagoRow {
  id: number
  tipo: string | null
  fecha: string
  valor: number
  bank_id: number | null
  documento: string | null
  cuenta: string | null
}
interface FormaPago {
  value: string
  label: string
  sri: string
  pide_banco: boolean
  pide_documento: boolean
  pide_cuenta: boolean
}
interface Bank {
  id: number
  nombre: string
}

const props = defineProps<{
  modelValue: PagoRow[]
  total: number
  banks: Bank[]
}>()
const emit = defineEmits<{
  'update:modelValue': [value: PagoRow[]]
}>()

const formas = ref<FormaPago[]>([])
let nextId = 1

function addRow() {
  const rows = [...props.modelValue]
  rows.push({ id: nextId++, tipo: null, fecha: '', valor: 0, bank_id: null, documento: null, cuenta: null })
  emit('update:modelValue', rows)
}
function removeRow(id: number) {
  const rows = props.modelValue.filter(r => r.id !== id)
  emit('update:modelValue', rows)
}
function updateRow(id: number, field: string, value: any) {
  const rows = props.modelValue.map(r => r.id === id ? { ...r, [field]: value } : r)
  emit('update:modelValue', rows)
}

const _formaActual = computed(() => (id: number) => {
  const row = props.modelValue.find(r => r.id === id)
  if (!row?.tipo) return null
  return formas.value.find(f => f.value === row.tipo) ?? null
})

const totalAbonado = computed(() => props.modelValue.reduce((s, r) => s + (Number(r.valor) || 0), 0))
const saldoPendiente = computed(() => props.total - totalAbonado.value)

function _todayString() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

onMounted(async () => {
  const res = await api.get('/catalogos/formas-pago')
  formas.value = res.data
})
</script>

<template>
  <div>
    <div style="margin-bottom:8px; font-weight:600; font-size:14px;">Formas de pago</div>
    <DataTable :value="modelValue" size="small" stripedRows>
      <Column header="Tipo">
        <template #body="{ data }">
          <Select v-model="data.tipo" :options="formas" optionLabel="label" optionValue="value"
                  placeholder="Seleccione" fluid @update:modelValue="(v:any) => updateRow(data.id, 'tipo', v)" />
        </template>
      </Column>
      <Column header="Fecha">
        <template #body="{ data }">
          <DatePicker v-model="data.fecha" dateFormat="yy-mm-dd" fluid
                      @update:modelValue="(v:any) => updateRow(data.id, 'fecha', v)" />
        </template>
      </Column>
      <Column header="Valor">
        <template #body="{ data }">
          <InputNumber v-model="data.valor" mode="currency" currency="USD" fluid
                        @update:modelValue="(v:any) => updateRow(data.id, 'valor', v)" />
        </template>
      </Column>
      <Column header="Banco" v-if="modelValue.some(r => {
        const f = formas.find(x => x.value === r.tipo)
        return f?.pide_banco
      })">
        <template #body="{ data }">
          <Select v-if="formas.find(f => f.value === data.tipo)?.pide_banco"
                  v-model="data.bank_id" :options="banks" optionLabel="nombre" optionValue="id"
                  placeholder="Seleccione banco" fluid
                  @update:modelValue="(v:any) => updateRow(data.id, 'bank_id', v)" />
        </template>
      </Column>
      <Column header="Documento" v-if="modelValue.some(r => {
        const f = formas.find(x => x.value === r.tipo)
        return f?.pide_documento
      })">
        <template #body="{ data }">
          <InputText v-if="formas.find(f => f.value === data.tipo)?.pide_documento"
                     :modelValue="data.documento"
                     placeholder="N.° de documento"
                     fluid
                     @update:modelValue="(v:any) => updateRow(data.id, 'documento', v)" />
        </template>
      </Column>
      <Column header="Cuenta" v-if="modelValue.some(r => {
        const f = formas.find(x => x.value === r.tipo)
        return f?.pide_cuenta
      })">
        <template #body="{ data }">
          <InputText v-if="formas.find(f => f.value === data.tipo)?.pide_cuenta"
                     :modelValue="data.cuenta"
                     placeholder="N.° de cuenta"
                     fluid
                     @update:modelValue="(v:any) => updateRow(data.id, 'cuenta', v)" />
        </template>
      </Column>
      <Column header="" style="width:50px;">
        <template #body="{ data }">
          <Button icon="pi pi-minus" severity="danger" text rounded size="small" @click="removeRow(data.id)" />
        </template>
      </Column>
      <template #footer>
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <Button icon="pi pi-plus" text rounded size="small" @click="addRow" />
          <div style="display:flex; gap:24px; font-size:13px;">
            <div>Total abonado: <b style="color:#059669;">{{ '$' + totalAbonado.toFixed(2) }}</b></div>
            <div>Saldo pendiente: <b :style="{color: saldoPendiente > 0 ? '#dc2626' : '#059669'}">{{ '$' + (saldoPendiente < 0 ? 0 : saldoPendiente).toFixed(2) }}</b></div>
          </div>
        </div>
      </template>
    </DataTable>
  </div>
</template>
