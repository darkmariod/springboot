<script setup lang="ts">
/**
 * Permisos y vacaciones de un empleado (T7.1): saldo de vacaciones, registro de ausencias y su estado.
 * Un permiso sin sueldo aprobado se descuenta en el rol de su mes: sueldo / 30 por cada día.
 * Las vacaciones ganadas son 15 días por año de servicio (1,25 por mes completo): la contadora debe validar esa regla.
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
const saldo = ref<any>(null)
const cargando = ref(false)
const guardando = ref(false)
const msg = ref<any>(null)
const avisos = ref<string[]>([])

const tipos = [
  { label: 'Permiso', value: 'permiso' },
  { label: 'Vacaciones', value: 'vacaciones' },
  { label: 'Enfermedad', value: 'enfermedad' },
  { label: 'Otro', value: 'otro' },
]
const etiquetaTipo = (t: string) => tipos.find((x) => x.value === t)?.label ?? t

const hoy = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const vacio = () => ({ tipo: 'permiso', fecha_desde: hoy(), fecha_hasta: hoy(), dias: null as number | null, remunerado: true, observacion: '' })
const form = ref<any>(vacio())

const num = (n: any) => String(Math.round(Number(n ?? 0) * 100) / 100)
const puedeGuardar = computed(() => !!form.value.fecha_desde && !!form.value.fecha_hasta && form.value.fecha_hasta >= form.value.fecha_desde && !guardando.value)

function errorDe(err: any, defecto: string) {
  const e = err.response?.data?.errors
  return e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? defecto)
}

async function cargar() {
  if (!props.employee?.id) return
  cargando.value = true
  try {
    const [l, s] = await Promise.all([
      api.get('/employee-leaves', { params: { company_id: company.activeId, employee_id: props.employee.id } }),
      api.get(`/employees/${props.employee.id}/vacaciones`, { params: { company_id: company.activeId } }),
    ])
    filas.value = l.data
    saldo.value = s.data
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudieron cargar los permisos y vacaciones.') }
  } finally {
    cargando.value = false
  }
}

async function registrar() {
  if (!puedeGuardar.value) return
  guardando.value = true
  msg.value = null
  avisos.value = []
  const f = form.value
  try {
    const { data } = await api.post('/employee-leaves', {
      company_id: company.activeId, employee_id: props.employee.id, tipo: f.tipo,
      fecha_desde: f.fecha_desde, fecha_hasta: f.fecha_hasta, dias: f.dias || undefined,
      remunerado: f.remunerado, observacion: f.observacion || null,
    })
    msg.value = {
      type: 'success',
      text: `Ausencia registrada: ${etiquetaTipo(data.tipo).toLowerCase()} de ${num(data.dias)} día(s).` +
        (!data.remunerado ? ' Se descontará en el rol del mes (sueldo / 30 por día).' : ''),
    }
    avisos.value = data.avisos ?? []
    form.value = vacio()
    await cargar()
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudo registrar la ausencia.') }
  } finally {
    guardando.value = false
  }
}

async function cambiarEstado(a: any, estado: string) {
  if (estado === 'cancelado' && !confirm('¿Cancelar esta ausencia?')) return
  msg.value = null
  avisos.value = []
  try {
    const { data } = await api.put(`/employee-leaves/${a.id}`, { company_id: company.activeId, estado })
    avisos.value = data.avisos ?? []
    await cargar()
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudo cambiar el estado.') }
  }
}

const severidad = (e: string) => (e === 'aprobado' ? 'success' : e === 'pendiente' ? 'warn' : 'secondary')
const fecha = (v: any) => String(v ?? '').slice(0, 10).split('-').reverse().join('/')

onMounted(cargar)
watch(() => props.employee?.id, () => { form.value = vacio(); msg.value = null; avisos.value = []; cargar() })
</script>

<template>
  <div>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>
    <Message v-for="a in avisos" :key="a" severity="warn" :closable="false" style="margin-bottom:10px;">{{ a }}</Message>

    <div v-if="saldo" class="saldo" data-testid="saldo-vacaciones">
      <div class="caja"><span>Ganados</span><b>{{ num(saldo.ganados) }} días</b>
        <small>{{ saldo.meses }} meses de servicio × {{ num(saldo.dias_por_anio / 12) }}</small></div>
      <div class="caja"><span>Tomados</span><b>{{ num(saldo.tomados) }} días</b><small>vacaciones aprobadas</small></div>
      <div class="caja" :class="{ debe: saldo.debe > 0 }">
        <span>Saldo al {{ fecha(saldo.al) }}</span>
        <b v-if="saldo.debe > 0" data-testid="saldo-debe">Debe {{ num(saldo.debe) }} días</b>
        <b v-else data-testid="saldo-dias">{{ num(saldo.saldo) }} días</b>
        <small>{{ saldo.debe > 0 ? 'tomó más de los que ganó' : 'disponibles' }}</small>
      </div>
    </div>

    <fieldset class="kvs-fieldset" data-testid="form-ausencia">
      <legend>Nueva ausencia</legend>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Tipo:</label>
        <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value" class="kvs-in" style="max-width:200px" data-testid="aus-tipo" />
        <label style="display:flex; align-items:center; gap:8px; margin-left:8px;">
          <Checkbox v-model="form.remunerado" :binary="true" data-testid="aus-remunerado" />
          <span>Con sueldo</span>
        </label>
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl"><span class="req-star">*</span> Desde:</label>
        <InputText v-model="form.fecha_desde" type="date" class="kvs-in" style="max-width:170px" data-testid="aus-desde" />
        <label class="kvs-lbl" style="min-width:auto"><span class="req-star">*</span> Hasta:</label>
        <InputText v-model="form.fecha_hasta" type="date" class="kvs-in" style="max-width:170px" data-testid="aus-hasta" />
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl">Días:</label>
        <InputNumber v-model="form.dias" :min="0.5" :maxFractionDigits="2" placeholder="Automático" class="kvs-in" style="max-width:130px" data-testid="aus-dias" />
        <span class="nota">Si lo dejas vacío se cuentan los días de lunes a viernes entre las dos fechas.</span>
      </div>
      <div class="kvs-row">
        <label class="kvs-lbl">Observación:</label>
        <InputText v-model="form.observacion" maxlength="500" class="kvs-in" />
      </div>
      <p v-if="!form.remunerado" class="nota" style="margin:0 0 6px 4px;">
        Sin sueldo: se descuenta en el rol de pagos del mes = sueldo ÷ 30 × días (y baja el IESS y las provisiones de ese mes).
      </p>
      <div class="kvs-row" style="justify-content:flex-end;">
        <Button label="Registrar ausencia" icon="pi pi-plus" size="small" :disabled="!puedeGuardar" :loading="guardando" @click="registrar" data-testid="aus-registrar" />
      </div>
    </fieldset>

    <DataTable :value="filas" :loading="cargando" size="small" stripedRows dataKey="id" class="kvs-grid" data-testid="tabla-ausencias" style="margin-top:12px;">
      <template #empty><span class="nota">Este empleado no tiene permisos ni vacaciones registrados.</span></template>
      <Column header="Desde" style="width:100px"><template #body="{ data }">{{ fecha(data.fecha_desde) }}</template></Column>
      <Column header="Hasta" style="width:100px"><template #body="{ data }">{{ fecha(data.fecha_hasta) }}</template></Column>
      <Column header="Tipo"><template #body="{ data }">{{ etiquetaTipo(data.tipo) }}</template></Column>
      <Column header="Días" style="width:70px"><template #body="{ data }">{{ num(data.dias) }}</template></Column>
      <Column header="Sueldo" style="width:90px"><template #body="{ data }">{{ data.remunerado ? 'Con sueldo' : 'Sin sueldo' }}</template></Column>
      <Column field="observacion" header="Observación" />
      <Column header="Estado" style="width:100px"><template #body="{ data }"><Tag :value="data.estado" :severity="severidad(data.estado)" /></template></Column>
      <Column header="" style="width:90px"><template #body="{ data }">
        <Button v-if="data.estado === 'pendiente'" icon="pi pi-check" size="small" text title="Aprobar" @click="cambiarEstado(data, 'aprobado')" />
        <Button v-if="data.estado === 'pendiente' || data.estado === 'aprobado'" icon="pi pi-times" size="small" text severity="danger" title="Cancelar" @click="cambiarEstado(data, 'cancelado')" />
      </template></Column>
    </DataTable>
  </div>
</template>

<style scoped>
.nota { font-size: 12px; color: #64748b; }
.saldo { display: flex; gap: 10px; margin-bottom: 12px; }
.caja { flex: 1; border: 1px solid #dbe6ea; background: #f4f8f9; border-radius: 8px; padding: 8px 12px; display: flex; flex-direction: column; }
.caja span { font-size: 11px; color: #64748b; text-transform: uppercase; }
.caja b { font-size: 18px; color: #1f2733; }
.caja small { font-size: 11px; color: #94a3b8; }
.caja.debe { border-color: #f3b8b3; background: #fdf1f0; }
.caja.debe b { color: #d93025; }
</style>
