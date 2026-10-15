<script setup lang="ts">
/**
 * Depósito de caja a banco (T5.3). Debe Bancos / Haber Caja con su número DP-000001 y su papeleta.
 * El tope es el efectivo disponible en caja (saldo de la cuenta Caja del libro mayor): depositar justo ese valor se permite,
 * un centavo más no. El servidor es quien decide; aquí solo se avisa antes de enviar.
 */
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const props = defineProps<{ visible: boolean }>()
const emit = defineEmits<{ (e: 'update:visible', v: boolean): void; (e: 'saved', deposito: any): void }>()

const company = useCompanyStore()
const bancos = ref<any[]>([])
const disponible = ref(0)
const cargando = ref(false)
const guardando = ref(false)
const error = ref('')
const form = ref<any>({ bank_id: null, fecha: new Date(), monto: null, documento: '', observacion: '' })

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const isoLocal = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

// En centavos para no comparar decimales sueltos
const excede = computed(() => Math.round(Number(form.value.monto ?? 0) * 100) > Math.round(disponible.value * 100))
const puedeGuardar = computed(() => !!form.value.bank_id && Number(form.value.monto) > 0 && form.value.documento.trim() !== '' && !excede.value && !guardando.value)

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const id = company.activeId
    const [b, d] = await Promise.all([
      api.get('/banks', { params: { company_id: id } }),
      api.get('/deposits/disponible', { params: { company_id: id } }),
    ])
    bancos.value = b.data
    disponible.value = Number(d.data.disponible ?? 0)
    form.value = { bank_id: bancos.value[0]?.id ?? null, fecha: new Date(), monto: disponible.value > 0 ? disponible.value : null, documento: '', observacion: '' }
  } catch {
    error.value = 'No se pudo consultar el efectivo disponible. Inténtalo de nuevo.'
  } finally {
    cargando.value = false
  }
}

// El disponible depende de la fecha del depósito
async function recalcular() {
  try {
    const d = await api.get('/deposits/disponible', { params: { company_id: company.activeId, fecha: isoLocal(new Date(form.value.fecha)) } })
    disponible.value = Number(d.data.disponible ?? 0)
  } catch { /* se queda con el último valor; el servidor valida igual */ }
}

async function guardar() {
  if (!puedeGuardar.value) return
  guardando.value = true
  error.value = ''
  try {
    const { data } = await api.post('/deposits', {
      company_id: company.activeId, bank_id: form.value.bank_id, fecha: isoLocal(new Date(form.value.fecha)),
      monto: form.value.monto, documento: form.value.documento.trim(), observacion: form.value.observacion || null,
    })
    emit('saved', data)
    emit('update:visible', false)
  } catch (err: any) {
    const e = err.response?.data?.errors
    error.value = e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? 'No se pudo registrar el depósito.')
  } finally {
    guardando.value = false
  }
}

watch(() => props.visible, (v) => { if (v) cargar() })
</script>

<template>
  <Dialog :visible="visible" modal header="Depositar a banco" style="width: 520px" @update:visible="(v: boolean) => emit('update:visible', v)">
    <p style="margin: 0 0 12px; font-size: 13px; color: #64748b;">
      Registra el dinero que llevas de la caja al banco. Se asienta Debe Bancos / Haber Caja y queda en la conciliación bancaria con tu número de papeleta.
    </p>
    <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 10px;">{{ error }}</Message>
    <div class="disp" data-testid="disponible">
      Efectivo disponible en caja: <b>{{ money(disponible) }}</b>
    </div>
    <fieldset class="kvs-fieldset" :disabled="cargando">
      <legend>Datos del depósito</legend>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Banco:</label>
        <Select v-model="form.bank_id" :options="bancos" optionLabel="nombre" optionValue="id" placeholder="Elige el banco" class="kvs-in" />
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Fecha:</label>
        <DatePicker v-model="form.fecha" dateFormat="dd/mm/yy" showIcon class="kvs-in" style="max-width: 180px" @update:modelValue="recalcular" />
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Monto:</label>
        <InputNumber v-model="form.monto" mode="currency" currency="USD" locale="en-US" class="kvs-in" style="max-width: 180px"
                     @focus="($event: Event) => ($event.target as HTMLInputElement).select()" />
      </div>
      <p v-if="excede" class="aviso" data-testid="aviso-excede">
        No puedes depositar más de lo que hay en caja: disponible {{ money(disponible) }}.
      </p>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> N.º papeleta:</label>
        <InputText v-model="form.documento" placeholder="Número de la papeleta de depósito" class="kvs-in" maxlength="100" />
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl">Observación:</label>
        <InputText v-model="form.observacion" class="kvs-in" maxlength="255" />
      </div>
    </fieldset>
    <template #footer>
      <Button label="Cancelar" text @click="emit('update:visible', false)" />
      <Button label="Depositar" icon="pi pi-building-columns" :disabled="!puedeGuardar" :loading="guardando" @click="guardar" />
    </template>
  </Dialog>
</template>

<style scoped>
.disp { background: #f4f8f9; border: 1px solid #dbe6ea; border-radius: 8px; padding: 9px 12px; margin-bottom: 12px; font-size: 14px; }
.aviso { margin: -2px 0 10px; color: #d93025; font-size: 12.5px; }
</style>
