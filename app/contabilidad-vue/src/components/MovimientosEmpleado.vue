<script setup lang="ts">
/**
 * Movimientos de un empleado (T7.1): anticipos, préstamos a cuotas, descuentos e ingresos extra.
 * El rol de pagos los toma solo cada mes; aquí se registran, se consultan y se cancelan mientras no se hayan descontado.
 * Si la empresa entrega el dinero (anticipo o préstamo), se asienta Debe "Anticipos y préstamos a empleados" / Haber Caja o Banco.
 */
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const props = defineProps<{ employee: any }>()
const company = useCompanyStore()

const filas = ref<any[]>([])
const bancos = ref<any[]>([])
const cargando = ref(false)
const guardando = ref(false)
const msg = ref<any>(null)
const filtro = ref<{ mes: string; estado: string | null }>({ mes: '', estado: null })

const tipos = [
  { label: 'Anticipo de sueldo', value: 'anticipo' },
  { label: 'Préstamo (a cuotas)', value: 'prestamo' },
  { label: 'Descuento', value: 'descuento' },
  { label: 'Ingreso extra', value: 'ingreso_extra' },
]
const subtipos = [
  { label: 'Horas extra', value: 'horas_extra' },
  { label: 'Comisiones', value: 'comisiones' },
  { label: 'Bono', value: 'bono' },
]
const formasEntrega = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Cheque de caja', value: 'cheque_caja' },
  { label: 'Cheque de banco', value: 'cheque_banco' },
  { label: 'Transferencia', value: 'transferencia' },
]
const estados = [
  { label: 'Pendiente', value: 'pendiente' },
  { label: 'Aplicado', value: 'aplicado' },
  { label: 'Cancelado', value: 'cancelado' },
]
const etiquetaTipo = (t: string) => tipos.find((x) => x.value === t)?.label ?? t

const hoy = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const vacio = () => ({
  tipo: 'anticipo', subtipo: 'horas_extra', concepto: '', fecha: hoy(), monto: null as number | null,
  cuotas: 1, aplicar_desde: '', entregar: false, forma_pago: 'efectivo', bank_id: null as number | null, documento: '',
})
const form = ref<any>(vacio())

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const entregaDinero = computed(() => form.value.tipo === 'anticipo' || form.value.tipo === 'prestamo')
const pideBanco = computed(() => form.value.entregar && ['cheque_banco', 'transferencia'].includes(form.value.forma_pago))
const cuotaCalculada = computed(() => (form.value.tipo === 'prestamo' && form.value.monto && form.value.cuotas > 0)
  ? Math.round((Number(form.value.monto) / Number(form.value.cuotas)) * 100) / 100 : null)
const puedeGuardar = computed(() => !!form.value.concepto.trim() && Number(form.value.monto) > 0 && !!form.value.fecha
  && (!pideBanco.value || !!form.value.bank_id) && !guardando.value)

// Lo que falta por descontar de lo que sigue pendiente (no cuenta ingresos extra)
const porDescontar = computed(() => filas.value
  .filter((f) => f.estado === 'pendiente' && f.tipo !== 'ingreso_extra')
  .reduce((s, f) => s + Number(f.saldo), 0))

function errorDe(err: any, defecto: string) {
  const e = err.response?.data?.errors
  return e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? defecto)
}

async function cargar() {
  if (!props.employee?.id) return
  cargando.value = true
  try {
    const params: any = { company_id: company.activeId, employee_id: props.employee.id }
    if (filtro.value.mes) params.mes = filtro.value.mes
    if (filtro.value.estado) params.estado = filtro.value.estado
    filas.value = (await api.get('/employee-movements', { params })).data
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudieron cargar los movimientos.') }
  } finally {
    cargando.value = false
  }
}

async function cargarBancos() {
  try {
    bancos.value = (await api.get('/banks', { params: { company_id: company.activeId } })).data
  } catch { bancos.value = [] }
}

async function registrar() {
  if (!puedeGuardar.value) return
  guardando.value = true
  msg.value = null
  const f = form.value
  const payload: any = {
    company_id: company.activeId, employee_id: props.employee.id, tipo: f.tipo, concepto: f.concepto.trim(),
    fecha: f.fecha, monto: f.monto,
  }
  if (f.tipo === 'ingreso_extra') payload.subtipo = f.subtipo
  if (f.tipo === 'prestamo') payload.cuotas = f.cuotas
  if (f.aplicar_desde) payload.aplicar_desde = f.aplicar_desde + '-01'
  if (entregaDinero.value && f.entregar) {
    payload.entrega = { forma_pago: f.forma_pago, bank_id: pideBanco.value ? f.bank_id : null, documento: f.documento || null }
  }
  try {
    const { data } = await api.post('/employee-movements', payload)
    msg.value = {
      type: 'success',
      text: `Movimiento registrado: ${etiquetaTipo(data.tipo).toLowerCase()} por ${money(data.monto)}. ` +
        (data.tipo === 'ingreso_extra' ? 'Entrará al rol de pagos del mes.' : 'Se descontará en el rol de pagos.'),
    }
    form.value = vacio()
    await cargar()
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudo registrar el movimiento.') }
  } finally {
    guardando.value = false
  }
}

async function cancelar(m: any) {
  const aviso = m.entregado ? ' Como el dinero salió de caja o banco, se registrará el asiento de reversión.' : ''
  if (!confirm(`¿Cancelar «${m.concepto}» por ${money(m.monto)}?${aviso}`)) return
  msg.value = null
  try {
    await api.post(`/employee-movements/${m.id}/cancelar`, { company_id: company.activeId })
    msg.value = { type: 'success', text: 'Movimiento cancelado.' }
    await cargar()
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudo cancelar el movimiento.') }
  }
}

const severidad = (e: string) => (e === 'pendiente' ? 'warn' : e === 'aplicado' ? 'success' : 'secondary')
const fechaCorta = (v: any) => String(v ?? '').slice(0, 10).split('-').reverse().join('/')
const cuotasTexto = (m: any) => (m.tipo === 'prestamo' ? `${m.cuotas} de ${money(m.cuota)}` : '—')

onMounted(() => { cargar(); cargarBancos() })
watch(() => props.employee?.id, () => { form.value = vacio(); msg.value = null; cargar() })
watch(filtro, cargar, { deep: true })
</script>

<template>
  <div>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>

    <fieldset class="kvs-fieldset" data-testid="form-movimiento">
      <legend>Nuevo movimiento</legend>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Tipo:</label>
        <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value" class="kvs-in" style="max-width:240px" data-testid="mov-tipo" />
        <Select v-if="form.tipo === 'ingreso_extra'" v-model="form.subtipo" :options="subtipos" optionLabel="label" optionValue="value" style="width:160px" />
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Concepto:</label>
        <InputText v-model="form.concepto" maxlength="150" placeholder="Ej.: Anticipo de quincena, 8 horas extra de septiembre" class="kvs-in" data-testid="mov-concepto" />
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Fecha:</label>
        <InputText v-model="form.fecha" type="date" :disabled="entregaDinero && form.entregar" class="kvs-in" style="max-width:170px" />
        <label class="kvs-lbl" style="min-width:auto"><span class="req-star">*</span> Monto:</label>
        <InputNumber v-model="form.monto" mode="currency" currency="USD" locale="en-US" class="kvs-in" style="max-width:150px"
                     @focus="($event: Event) => ($event.target as HTMLInputElement).select()" data-testid="mov-monto" />
      </div>
      <div v-if="form.tipo === 'prestamo'" class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Cuotas:</label>
        <InputNumber v-model="form.cuotas" :min="1" :max="60" :useGrouping="false" class="kvs-in" style="max-width:110px" />
        <span v-if="cuotaCalculada" class="nota">cuotas de {{ money(cuotaCalculada) }} (la última absorbe los centavos)</span>
      </div>
      <div v-if="form.tipo !== 'ingreso_extra'" class="kvs-row">
        <label class="kvs-lbl">Descontar desde:</label>
        <InputText v-model="form.aplicar_desde" type="month" class="kvs-in" style="max-width:170px" />
        <span class="nota">Si lo dejas vacío, desde el mes de la fecha.</span>
      </div>
      <div v-if="form.tipo === 'ingreso_extra'" class="kvs-row">
        <label class="kvs-lbl">Entra al rol de:</label>
        <InputText v-model="form.aplicar_desde" type="month" class="kvs-in" style="max-width:170px" />
        <span class="nota">Si lo dejas vacío, el mes de la fecha. Paga IESS y provisiones, igual que las horas extra.</span>
      </div>

      <template v-if="entregaDinero">
        <div class="kvs-row">
          <label style="display:flex; align-items:center; gap:8px; margin-left:4px;">
            <Checkbox v-model="form.entregar" :binary="true" data-testid="mov-entregar" />
            <span>La empresa entrega el dinero ahora (sale de caja o banco)</span>
          </label>
        </div>
        <div v-if="form.entregar" class="kvs-row">
          <label class="kvs-lbl">Entrega por:</label>
          <Select v-model="form.forma_pago" :options="formasEntrega" optionLabel="label" optionValue="value" style="width:170px" />
          <Select v-if="pideBanco" v-model="form.bank_id" :options="bancos" optionLabel="nombre" optionValue="id" placeholder="Elige el banco" style="width:200px" />
          <InputText v-if="form.forma_pago !== 'efectivo'" v-model="form.documento" placeholder="N.º cheque o transferencia" maxlength="60" style="width:200px" />
        </div>
        <p v-if="form.entregar" class="nota" style="margin:0 0 6px 4px;">
          Se asienta Debe «Anticipos y préstamos a empleados» / Haber Caja o Bancos con fecha de hoy. Cada cuota que descuente el rol cancela esa cuenta.
        </p>
        <p v-else class="nota" style="margin:0 0 6px 4px;">
          Sin entrega, solo se registra el descuento: no se mueve caja ni bancos.
        </p>
      </template>

      <div class="kvs-row" style="justify-content:flex-end;">
        <Button label="Registrar movimiento" icon="pi pi-plus" size="small" :disabled="!puedeGuardar" :loading="guardando" @click="registrar" data-testid="mov-registrar" />
      </div>
    </fieldset>

    <div class="barra">
      <b>Movimientos</b>
      <InputText v-model="filtro.mes" type="month" style="width:150px" title="Mes del movimiento" />
      <Select v-model="filtro.estado" :options="estados" optionLabel="label" optionValue="value" placeholder="Todos los estados" showClear style="width:190px" />
      <span class="nota" style="margin-left:auto;">Por descontar: <b data-testid="por-descontar">{{ money(porDescontar) }}</b></span>
    </div>

    <DataTable :value="filas" :loading="cargando" size="small" stripedRows dataKey="id" class="kvs-grid" data-testid="tabla-movimientos">
      <template #empty><span class="nota">Este empleado no tiene movimientos con ese filtro.</span></template>
      <Column header="Fecha" style="width:92px"><template #body="{ data }">{{ fechaCorta(data.fecha) }}</template></Column>
      <Column header="Tipo"><template #body="{ data }">
        {{ etiquetaTipo(data.tipo) }}<span v-if="data.subtipo" class="nota"> · {{ data.subtipo.replace('_', ' ') }}</span></template></Column>
      <Column field="concepto" header="Concepto" />
      <Column header="Monto" style="width:90px"><template #body="{ data }">{{ money(data.monto) }}</template></Column>
      <Column header="Cuotas" style="width:110px"><template #body="{ data }">{{ cuotasTexto(data) }}</template></Column>
      <Column header="Saldo" style="width:90px"><template #body="{ data }"><b>{{ money(data.estado === 'cancelado' ? 0 : data.saldo) }}</b></template></Column>
      <Column header="Estado" style="width:100px"><template #body="{ data }">
        <Tag :value="data.estado" :severity="severidad(data.estado)" />
        <i v-if="data.entregado" class="pi pi-wallet" title="El dinero salió de caja o banco" style="margin-left:6px; color:#546e7a;" />
      </template></Column>
      <Column header="" style="width:60px"><template #body="{ data }">
        <Button v-if="data.puede_cancelar" icon="pi pi-times" size="small" text severity="danger" title="Cancelar (aún no se descontó)" @click="cancelar(data)" />
      </template></Column>
    </DataTable>
  </div>
</template>

<style scoped>
.nota { font-size: 12px; color: #64748b; }
.barra { display: flex; align-items: center; gap: 10px; margin: 12px 0 8px; }
</style>
