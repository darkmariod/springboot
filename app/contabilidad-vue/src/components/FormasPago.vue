<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import DatePicker from 'primevue/datepicker'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

interface PagoRow {
  id: number
  tipo: string | null
  fecha: string
  valor: number
  bank_id: number | null
  documento: string | null
  cuenta: string | null
  documento_cruce?: number | null
}
interface DocCruce {
  id: number
  numero: string
  fecha: string | null
  saldo: number
  etiqueta: string
}
interface FormaPago {
  value: string
  label: string
  sri: string
  pide_banco: boolean
  pide_documento: boolean
  pide_cuenta: boolean
  es_cruce?: boolean
}
interface Bank {
  id: number
  nombre: string
}

// El cruce de saldos cancela este documento contra uno del MISMO contacto (cliente que también es proveedor).
// Solo se ofrece cuando la pantalla lo permite y conoce el contacto: `lado` 'cobro' = se cobra una factura
// (se cruza contra compras); 'pago' = se paga una compra (se cruza contra facturas).
const props = withDefaults(defineProps<{
  modelValue: PagoRow[]
  total: number
  banks: Bank[]
  permiteCruce?: boolean
  contactId?: number | null
  lado?: 'cobro' | 'pago'
}>(), { permiteCruce: false, contactId: null, lado: 'cobro' })
const emit = defineEmits<{
  'update:modelValue': [value: PagoRow[]]
}>()

const company = useCompanyStore()
const formas = ref<FormaPago[]>([])
const docsCruce = ref<DocCruce[]>([])
const docsCargados = ref(false)
let nextId = 1

const cruceDisponible = computed(() => props.permiteCruce && !!props.contactId)
const formasVisibles = computed(() => formas.value.filter(f => !f.es_cruce || cruceDisponible.value))
const esCruce = (tipo: string | null) => !!formas.value.find(f => f.value === tipo)?.es_cruce
const hayCruce = computed(() => cruceDisponible.value && props.modelValue.some(r => esCruce(r.tipo)))
const contraparte = computed(() => props.lado === 'cobro'
  ? { docs: 'compras', doc: 'la compra', quien: 'cliente' }
  : { docs: 'facturas', doc: 'la factura', quien: 'proveedor' })

async function cargarDocsCruce() {
  docsCargados.value = false
  try {
    const res = await api.get('/cruce-saldos/documentos', {
      params: { company_id: company.activeId, contact_id: props.contactId, lado: props.lado },
    })
    docsCruce.value = res.data.map((d: any) => ({
      ...d,
      etiqueta: d.numero + (d.fecha ? ' · ' + d.fecha : '') + ' · saldo $' + Number(d.saldo).toFixed(2),
    }))
  } catch {
    docsCruce.value = []
  } finally {
    docsCargados.value = true
  }
}
// Se piden los documentos solo cuando alguien elige "Cruce de saldos" (y de nuevo si cambia el contacto)
watch(hayCruce, (v) => { if (v && !docsCargados.value) cargarDocsCruce() })
watch(() => [props.contactId, props.lado], () => { docsCargados.value = false; docsCruce.value = []; if (hayCruce.value) cargarDocsCruce() })

function addRow() {
  const rows = [...props.modelValue]
  rows.push({ id: nextId++, tipo: null, fecha: '', valor: 0, bank_id: null, documento: null, cuenta: null, documento_cruce: null })
  emit('update:modelValue', rows)
}
function removeRow(id: number) {
  const rows = props.modelValue.filter(r => r.id !== id)
  emit('update:modelValue', rows)
}
function updateRow(id: number, field: string, value: any) {
  const rows = props.modelValue.map(r => {
    if (r.id !== id) return r
    const nueva = { ...r, [field]: value }
    // Al dejar el cruce se olvida el documento elegido
    if (field === 'tipo' && !esCruce(value)) nueva.documento_cruce = null
    // El valor de un cruce no puede pasar del saldo del documento elegido
    if (field === 'documento_cruce') {
      const doc = docsCruce.value.find(d => d.id === value)
      if (doc && (Number(nueva.valor) || 0) > doc.saldo) nueva.valor = doc.saldo
    }
    return nueva
  })
  emit('update:modelValue', rows)
}

const totalAbonado = computed(() => props.modelValue.reduce((s, r) => s + (Number(r.valor) || 0), 0))
const saldoPendiente = computed(() => props.total - totalAbonado.value)

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
          <Select v-model="data.tipo" :options="formasVisibles" optionLabel="label" optionValue="value"
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
      <Column header="Documento a cruzar" v-if="hayCruce">
        <template #body="{ data }">
          <Select v-if="esCruce(data.tipo)"
                  :modelValue="data.documento_cruce" :options="docsCruce" optionLabel="etiqueta" optionValue="id"
                  :placeholder="'Elige ' + contraparte.doc" fluid
                  @update:modelValue="(v:any) => updateRow(data.id, 'documento_cruce', v)" />
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
    <small v-if="hayCruce && docsCargados && !docsCruce.length" style="display:block; margin-top:6px; color:#d97706;">
      Este {{ contraparte.quien }} no tiene {{ contraparte.docs }} abiertas para cruzar.
    </small>
  </div>
</template>
