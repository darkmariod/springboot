<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import api from '../lib/api'
import AsientoForm from '../components/AsientoForm.vue'
import { useCompanyStore } from '../stores/company'
import { useAuthStore } from '../stores/auth'
import { onShortcut } from '../composables/useShortcuts'

const company = useCompanyStore()
const auth = useAuthStore()
const asientos = ref<any[]>([])
const cuentas = ref<any[]>([])
const income = ref<any>(null)
const balance = ref<any>(null)
const loading = ref(true)
// Ids de los asientos pendientes marcados (solo un asiento pendiente se puede mayorizar)
const seleccion = ref<number[]>([])
const expandidas = ref<Record<string, boolean>>({})
const formVisible = ref(false)
const asientoEnForm = ref<any | null>(null)
// Resultado de la última acción: un mensaje de éxito o de error y, si hubo, los asientos que no se mayorizaron
const aviso = ref<{ tipo: 'success' | 'error' | 'warn'; texto: string } | null>(null)
const omitidos = ref<{ numero: string; motivo: string }[]>([])
const trabajando = ref(false)

const filtros = ref<{ mes: number | null; anio: number | null; estado: string | null; q: string }>({ mes: null, anio: null, estado: null, q: '' })

// Mayorizar, desmayorizar, crear, editar y eliminar son del administrador y del contador (el servidor lo exige igual)
const puedeEscribir = computed(() => ['admin', 'contador'].includes(auth.user?.rol ?? ''))

const money = (n: any) => `$${Number(n).toFixed(2)}`
const fechaDe = (a: any) => String(a.fecha).slice(0, 10)

const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
const opcionesMes = [{ label: 'Todos los meses', value: null }, ...meses.map((m, i) => ({ label: m, value: i + 1 }))]
const opcionesAnio = computed(() => {
  const anios = new Set<number>([new Date().getFullYear()])
  for (const a of asientos.value) anios.add(Number(fechaDe(a).slice(0, 4)))
  return [{ label: 'Todos los años', value: null }, ...[...anios].sort((x, y) => y - x).map(y => ({ label: String(y), value: y }))]
})
const opcionesEstado = [
  { label: 'Todos los estados', value: null },
  { label: 'Borrador', value: 'borrador' },
  { label: 'Pendiente', value: 'pendiente' },
  { label: 'Mayorizado', value: 'mayorizado' },
  { label: 'Manuales', value: 'manual' },
  { label: 'Editados a mano', value: 'editado' },
  { label: 'Descuadrados', value: 'descuadrado' },
]

const visibles = computed(() => {
  const f = filtros.value
  const t = f.q.trim().toLowerCase()
  return asientos.value.filter(a => {
    const fecha = fechaDe(a)
    if (f.anio && Number(fecha.slice(0, 4)) !== f.anio) return false
    if (f.mes && Number(fecha.slice(5, 7)) !== f.mes) return false
    if (f.estado === 'manual') { if (!a.manual) return false }
    else if (f.estado === 'editado') { if (!a.editado_manualmente) return false }
    else if (f.estado === 'descuadrado') { if (!a.descuadrado) return false }
    else if (f.estado && a.estado !== f.estado) return false
    if (t && !(String(a.numero).toLowerCase().includes(t) || String(a.concepto).toLowerCase().includes(t))) return false
    return true
  })
})
const pendientesVisibles = computed(() => visibles.value.filter(a => a.estado === 'pendiente'))
const periodoElegido = computed(() => filtros.value.mes ? `${meses[filtros.value.mes - 1]} ${filtros.value.anio ?? ''}`.trim() : '')

// Elegir un mes sin año: se toma el año en curso
watch(() => filtros.value.mes, (m) => { if (m && !filtros.value.anio) filtros.value.anio = new Date().getFullYear() })
// Lo seleccionado que ya no se ve (por un filtro) deja de estar seleccionado
watch(visibles, (v) => { seleccion.value = seleccion.value.filter(id => v.some(a => a.id === id && a.estado === 'pendiente')) })
const todosMarcados = computed(() => pendientesVisibles.value.length > 0 && pendientesVisibles.value.every(a => seleccion.value.includes(a.id)))
function marcarTodos(v: boolean) { seleccion.value = v ? pendientesVisibles.value.map(a => a.id) : [] }

const ORIGENES: Record<string, string> = {
  Invoice: 'Factura', Purchase: 'Compra', CreditNote: 'Nota de crédito', Advance: 'Anticipo', Payroll: 'Nómina',
  InventoryMovement: 'Ajuste de inventario', Withholding: 'Retención', WithholdingEmitted: 'Retención emitida',
  PhysicalCount: 'Toma física', StockTransformation: 'Fraccionamiento / conversión',
  CashMovement: 'Comprobante de caja', BankMovement: 'Movimiento de banco', CardSettlement: 'Liquidación de tarjetas',
  EmployeeMovement: 'Movimiento de empleado', FixedAsset: 'Activo fijo', FixedAssetDepreciation: 'Depreciación de activos fijos',
}
const origenDe = (a: any) => a.manual ? 'Manual' : a.origen_type ? (ORIGENES[String(a.origen_type).split('\\').pop() ?? ''] ?? String(a.origen_type).split('\\').pop()) : 'Sistema'

function mensajeError(err: any, defecto: string): string {
  const d = err.response?.data
  if (d?.errors) return (Object.values(d.errors).flat() as string[]).join(' · ')
  return d?.message ?? defecto
}

async function load() {
  loading.value = true
  try {
    const [j, i, b, c] = await Promise.all([
      api.get(`/journal?company_id=${company.activeId}`),
      api.get(`/income-statement?company_id=${company.activeId}`),
      api.get(`/balance-sheet?company_id=${company.activeId}`),
      api.get(`/accounts?company_id=${company.activeId}`),
    ])
    asientos.value = j.data; income.value = i.data; balance.value = b.data
    cuentas.value = c.data.data ?? c.data
    seleccion.value = []
  } finally {
    loading.value = false
  }
}

function exito(texto: string) { aviso.value = { tipo: 'success', texto }; omitidos.value = [] }
function falla(err: any, defecto: string) { aviso.value = { tipo: 'error', texto: mensajeError(err, defecto) }; omitidos.value = [] }

function nuevo() {
  if (!puedeEscribir.value) return
  asientoEnForm.value = null
  formVisible.value = true
}
function editar(a: any) {
  asientoEnForm.value = a
  formVisible.value = true
}
async function alGuardar(texto: string) {
  exito(texto)
  await load()
}

// Pasa un borrador a pendiente: desde ahí cuenta en el libro mayor y los estados financieros
async function contabilizar(a: any) {
  trabajando.value = true
  try {
    await api.post(`/journal-entries/${a.id}/contabilizar`, { company_id: company.activeId })
    exito(`Asiento ${a.numero} contabilizado: ahora está pendiente y cuenta en el libro mayor y los estados financieros.`)
    await load()
  } catch (err: any) { falla(err, 'No se pudo contabilizar el asiento.') } finally { trabajando.value = false }
}
async function desmayorizar(a: any) {
  trabajando.value = true
  try {
    await api.post(`/journal-entries/${a.id}/desmayorizar`, { company_id: company.activeId })
    exito(`Asiento ${a.numero} desmayorizado: ya puedes editarlo.`)
    await load()
  } catch (err: any) { falla(err, 'No se pudo desmayorizar el asiento.') } finally { trabajando.value = false }
}
async function eliminar(a: any) {
  if (!confirm(`¿Eliminar el asiento ${a.numero} — ${a.concepto}? Esto no se puede deshacer.`)) return
  trabajando.value = true
  try {
    await api.delete(`/journal-entries/${a.id}`, { params: { company_id: company.activeId } })
    exito(`Asiento ${a.numero} eliminado.`)
    await load()
  } catch (err: any) { falla(err, 'No se pudo eliminar el asiento.') } finally { trabajando.value = false }
}

// Mayoriza (cierra) los asientos pendientes y cuadrados indicados; los demás se informan con su motivo
async function mayorizar(payload: Record<string, any>, descripcion: string) {
  trabajando.value = true
  try {
    const res = await api.post('/journal-entries/mayorizar', { company_id: company.activeId, ...payload })
    const n = res.data.mayorizados as number
    omitidos.value = res.data.omitidos ?? []
    aviso.value = {
      tipo: omitidos.value.length ? 'warn' : 'success',
      texto: (n === 1 ? `1 asiento mayorizado ${descripcion}.` : `${n} asientos mayorizados ${descripcion}.`)
        + (omitidos.value.length ? ` No se mayorizaron ${omitidos.value.length}:` : ''),
    }
    await load()
  } catch (err: any) { falla(err, 'No se pudo mayorizar.') } finally { trabajando.value = false }
}
function contabilizarSeleccionados() {
  const ids = [...seleccion.value]
  if (!ids.length) return
  mayorizar({ ids }, 'de los seleccionados')
}
function contabilizarMes() {
  const n = pendientesVisibles.value.length
  if (!filtros.value.mes || !filtros.value.anio) return
  if (!confirm(`Se mayorizarán los asientos pendientes y cuadrados de ${periodoElegido.value} (${n} pendientes). Los descuadrados y los borradores se omiten y se te dirá cuáles. ¿Continuar?`)) return
  mayorizar({ mes: filtros.value.mes, anio: filtros.value.anio }, `de ${periodoElegido.value}`)
}

onMounted(load)
onShortcut('nuevo', () => nuevo())
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px; flex-wrap:wrap;">
      <h2 style="margin:0;">Contabilidad</h2>
      <Button v-if="puedeEscribir" label="Nuevo asiento" icon="pi pi-plus" size="small" @click="nuevo" data-test="nuevo-asiento" />
    </div>

    <!-- Estados financieros -->
    <div v-if="income && balance" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; margin-bottom:20px;">
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Ingresos</div><div style="font-size:20px; font-weight:700;">{{ money(income.ingresos) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Gastos</div><div style="font-size:20px; font-weight:700;">{{ money(income.gastos) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Utilidad</div><div style="font-size:20px; font-weight:700;">{{ money(income.utilidad_ejercicio) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Activos</div><div style="font-size:20px; font-weight:700;">{{ money(balance.activos) }}</div></template></Card>
      <Card><template #content><div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Balance</div><Tag :value="balance.cuadrado ? 'Cuadrado ✓' : 'No cuadra'" :severity="balance.cuadrado ? 'success':'danger'" /></template></Card>
    </div>

    <h3 style="margin:0 0 4px;">Libro diario</h3>
    <p class="nota">
      Los estados financieros y el libro mayor ya cuentan los asientos <b>Pendientes</b> y los <b>Mayorizados</b>; los <b>Borradores</b> no cuentan
      hasta que los contabilices. Mayorizar cierra el asiento: para corregirlo primero lo desmayorizas.
      Los meses cerrados (<b>Períodos contables</b>) no admiten asientos nuevos ni cambios.
    </p>

    <Message v-if="aviso" :severity="aviso.tipo" :closable="true" @close="aviso = null; omitidos = []" style="margin-bottom:10px;" data-test="aviso">
      {{ aviso.texto }}
      <ul v-if="omitidos.length" style="margin:6px 0 0; padding-left:18px;">
        <li v-for="o in omitidos.slice(0, 10)" :key="o.numero"><b>{{ o.numero }}</b>: {{ o.motivo }}</li>
        <li v-if="omitidos.length > 10">… y {{ omitidos.length - 10 }} más</li>
      </ul>
    </Message>

    <!-- Filtros y acciones por lote -->
    <div class="barra">
      <Select v-model="filtros.mes" :options="opcionesMes" optionLabel="label" optionValue="value" placeholder="Todos los meses" style="width:170px;" aria-label="Mes" />
      <Select v-model="filtros.anio" :options="opcionesAnio" optionLabel="label" optionValue="value" placeholder="Todos los años" style="width:150px;" aria-label="Año" />
      <Select v-model="filtros.estado" :options="opcionesEstado" optionLabel="label" optionValue="value" placeholder="Todos los estados" style="width:190px;" aria-label="Estado" />
      <InputText v-model="filtros.q" placeholder="Buscar por número o concepto" style="width:250px;" aria-label="Buscar asiento" />
      <span style="flex:1;"></span>
      <template v-if="puedeEscribir">
        <Button :label="`Contabilizar seleccionados (${seleccion.length})`" icon="pi pi-check" size="small" severity="secondary"
                :disabled="!seleccion.length || trabajando" v-tooltip.top="'Mayoriza los asientos pendientes seleccionados'" @click="contabilizarSeleccionados" />
        <Button :label="filtros.mes ? `Contabilizar ${periodoElegido}` : 'Contabilizar el mes'" icon="pi pi-calendar-plus" size="small"
                :disabled="!filtros.mes || !pendientesVisibles.length || trabajando"
                v-tooltip.top="filtros.mes ? 'Mayoriza todos los pendientes cuadrados de ese mes' : 'Elige un mes para contabilizarlo completo'" @click="contabilizarMes" />
      </template>
    </div>

    <DataTable :value="visibles" :loading="loading" size="small" stripedRows dataKey="id"
               v-model:expandedRows="expandidas"
               :paginator="visibles.length > 25" :rows="25" scrollable>
      <template #empty>{{ loading ? '' : 'No hay asientos con esos filtros.' }}</template>
      <Column expander style="width:42px;" />
      <Column v-if="puedeEscribir" style="width:42px;">
        <template #header>
          <Checkbox :modelValue="todosMarcados" binary :disabled="!pendientesVisibles.length" aria-label="Marcar todos los pendientes"
                    v-tooltip.top="'Marca todos los pendientes que ves'" @update:modelValue="marcarTodos" />
        </template>
        <template #body="{ data }">
          <Checkbox v-if="data.estado === 'pendiente'" v-model="seleccion" :value="data.id" :aria-label="`Marcar el asiento ${data.numero}`" />
        </template>
      </Column>
      <Column field="numero" header="Asiento" style="min-width:110px;" />
      <Column header="Fecha" style="min-width:100px;"><template #body="{ data }">{{ fechaDe(data) }}</template></Column>
      <Column header="Concepto" style="min-width:240px;">
        <template #body="{ data }">
          <div>{{ data.concepto }}</div>
          <small style="color:#94a3b8;">{{ origenDe(data) }}</small>
        </template>
      </Column>
      <Column header="Debe" style="min-width:90px;"><template #body="{ data }">{{ money(data.total_debe) }}</template></Column>
      <Column header="Haber" style="min-width:90px;"><template #body="{ data }">{{ money(data.total_haber) }}</template></Column>
      <Column header="Estado" style="min-width:230px;">
        <template #body="{ data }">
          <div style="display:flex; gap:4px; flex-wrap:wrap;">
            <Tag v-if="data.estado === 'borrador'" value="Borrador" severity="secondary" v-tooltip.top="'No cuenta en el libro mayor ni en los estados financieros'" />
            <Tag v-else-if="data.estado === 'mayorizado'" value="Mayorizado" severity="success" />
            <Tag v-else value="Pendiente" severity="warn" />
            <Tag v-if="data.manual" value="Manual" severity="info" />
            <Tag v-if="data.editado_manualmente" value="Editado" severity="contrast" v-tooltip.top="'Se corrigió a mano; el cambio está en la auditoría'" />
            <Tag v-if="data.descuadrado" value="Descuadrado" severity="danger" v-tooltip.top="'Las líneas no suman lo mismo en Debe y Haber: no se puede mayorizar'" />
            <Tag v-if="data.anulado" value="Anulado" severity="secondary" />
            <Tag v-if="data.es_contra" value="Contra-asiento" severity="secondary" />
            <Tag v-if="data.es_cierre" value="Cierre del ejercicio" severity="contrast" />
            <Tag v-if="data.periodo_cerrado" value="Período cerrado" severity="secondary" icon="pi pi-lock"
                 v-tooltip.top="'El mes de este asiento está cerrado: para cambiarlo, el administrador debe reabrir el período'" />
          </div>
        </template>
      </Column>
      <Column v-if="puedeEscribir" header="" style="min-width:290px;">
        <template #body="{ data }">
          <Button v-if="data.estado === 'borrador'" label="Contabilizar" icon="pi pi-check" size="small" text :disabled="trabajando" @click="contabilizar(data)" />
          <Button v-if="data.puede_editar" label="Editar" icon="pi pi-pencil" size="small" text :disabled="trabajando" @click="editar(data)" />
          <Button v-if="data.puede_desmayorizar" label="Desmayorizar" icon="pi pi-lock-open" size="small" text :disabled="trabajando" @click="desmayorizar(data)" />
          <Button v-if="data.puede_eliminar" label="Eliminar" icon="pi pi-trash" size="small" text severity="danger" :disabled="trabajando" @click="eliminar(data)" />
        </template>
      </Column>
      <template #expansion="{ data }">
        <table class="detalle">
          <thead><tr><th>Cuenta</th><th>Debe</th><th>Haber</th><th>Referencia</th></tr></thead>
          <tbody>
            <tr v-for="l in data.lines" :key="l.id">
              <td>{{ l.account?.codigo }} — {{ l.account?.nombre }}</td>
              <td>{{ Number(l.debe) ? money(l.debe) : '' }}</td>
              <td>{{ Number(l.haber) ? money(l.haber) : '' }}</td>
              <td>{{ l.referencia }}</td>
            </tr>
          </tbody>
        </table>
      </template>
    </DataTable>

    <AsientoForm v-model:visible="formVisible" :asiento="asientoEnForm" :cuentas="cuentas" @guardado="alGuardar" />
  </div>
</template>

<style scoped>
.nota { color: #64748b; font-size: 13px; margin: 0 0 10px; }
.barra { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
.detalle { width: 100%; font-size: 12px; border-collapse: collapse; }
.detalle th { text-align: left; color: #64748b; padding: 3px 8px; border-bottom: 1px solid #e2e8f0; }
.detalle td { padding: 3px 8px; }
</style>
