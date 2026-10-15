<script setup lang="ts">
import { computed, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rol = ref<any>(null)
const anio = ref(new Date().getFullYear())
const mes = ref(new Date().getMonth() + 1)
const cargando = ref(false)
const msg = ref<any>(null)
const expandidos = ref<any>({})
const meses = [
  { label: 'Enero', value: 1 }, { label: 'Febrero', value: 2 }, { label: 'Marzo', value: 3 },
  { label: 'Abril', value: 4 }, { label: 'Mayo', value: 5 }, { label: 'Junio', value: 6 },
  { label: 'Julio', value: 7 }, { label: 'Agosto', value: 8 }, { label: 'Septiembre', value: 9 },
  { label: 'Octubre', value: 10 }, { label: 'Noviembre', value: 11 }, { label: 'Diciembre', value: 12 },
]
const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const n = (v: any) => Number(v ?? 0)

const abierto = computed(() => rol.value?.estado === 'abierto')
// Lo que se descuenta del bruto además del IESS: permisos sin sueldo, anticipos, préstamos, descuentos y consumos
const descuentosDe = (l: any) => n(l.permisos) + n(l.anticipos) + n(l.prestamos) + n(l.descuentos) + n(l.consumos)
const extrasDe = (l: any) => n(l.horas_extra) + n(l.comisiones) + n(l.otros_ingresos)
const provisionesDe = (l: any) => n(l.aporte_patronal) + n(l.decimo_tercero) + n(l.decimo_cuarto) + n(l.fondos_reserva) + n(l.vacaciones)
// Empleados a quienes no les cupo todo el descuento: lo que falta pasa al rol del mes siguiente
const conDiferido = computed(() => (rol.value?.lines ?? []).filter((l: any) => n(l.diferido) > 0.004))

const etiquetas: Record<string, string> = {
  anticipo: 'Anticipo', prestamo: 'Préstamo', descuento: 'Descuento', ingreso_extra: 'Ingreso extra', permiso: 'Permiso sin sueldo', manual: 'Escrito a mano',
}
const movimientosDe = (l: any) => (l.detalle ?? []).filter((d: any) => d.tipo !== 'consumo')
const consumosDe = (l: any) => (l.detalle ?? []).filter((d: any) => d.tipo === 'consumo')
const esDescuento = (d: any) => ['anticipo', 'prestamo', 'descuento', 'permiso'].includes(d.tipo) || (d.tipo === 'manual' && ['prestamos', 'anticipos', 'descuentos'].includes(d.columna))

function mensajeDe(e: any, defecto: string) {
  const errs = e.response?.data?.errors
  return errs ? Object.values(errs).flat().join(' · ') : (e.response?.data?.message ?? defecto)
}

async function generar() {
  cargando.value = true; msg.value = null
  try {
    rol.value = (await api.post('/payrolls/generate', {
      company_id: company.activeId, anio: anio.value, mes: mes.value,
    })).data
    expandidos.value = {}
  } catch (e: any) {
    msg.value = { type: 'error', text: mensajeDe(e, 'No se pudo generar.') }
  } finally { cargando.value = false }
}

async function cerrar() {
  msg.value = null
  try {
    const res = await api.post('/payrolls/' + rol.value.id + '/close', { company_id: company.activeId })
    const id = rol.value.id
    rol.value = (await api.get('/payrolls/' + id, { params: { company_id: company.activeId } })).data
    msg.value = { type: 'success', text: res.data.mensaje + (res.data.asiento ? ' Asiento ' + res.data.asiento + '.' : '') }
  } catch (e: any) {
    msg.value = { type: 'error', text: mensajeDe(e, 'No se pudo cerrar el rol.') }
  }
}

// Marca o desmarca una factura del cliente ligado al empleado y recalcula su línea (nada se cobra hasta cerrar el rol)
async function alternarConsumo(l: any, factura: any, incluido: boolean) {
  msg.value = null
  const excluir = consumosDe(l).filter((c: any) => (c.invoice_id === factura.invoice_id ? !incluido : !c.incluido)).map((c: any) => c.invoice_id)
  try {
    rol.value = (await api.post('/payrolls/' + rol.value.id + '/consumos', {
      company_id: company.activeId, employee_id: l.employee_id, excluir,
    })).data
  } catch (e: any) {
    msg.value = { type: 'error', text: mensajeDe(e, 'No se pudo cambiar el descuento.') }
  }
}

async function descargar(url: string, params: any, nombre: string, tipo: string) {
  msg.value = null
  try {
    const res = await api.get(url, { params: { company_id: company.activeId, ...params }, responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: tipo }))
    a.download = nombre
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    msg.value = { type: 'error', text: 'No se pudo generar el documento. Inténtalo de nuevo.' }
  }
}
const sufijo = () => `${rol.value.anio}-${String(rol.value.mes).padStart(2, '0')}`
const rolIndividual = (l: any) => descargar(`/payrolls/${rol.value.id}/rol/${l.employee_id}`, {}, `Rol-${sufijo()}-${l.employee?.cedula ?? l.employee_id}.pdf`, 'application/pdf')
const rolGeneral = (formato: 'pdf' | 'csv') => descargar(`/payrolls/${rol.value.id}/rol-general`, { formato }, `Rol-general-${sufijo()}.${formato}`, formato === 'pdf' ? 'application/pdf' : 'text/csv')
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Rol de pagos</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">
      El rol descuenta el 9.45% de IESS al empleado. Las provisiones (patronal 11.15%, décimos,
      fondos, vacaciones) son costo de la empresa y no se le descuentan. Toma solos los movimientos
      del empleado (anticipos, préstamos, descuentos, ingresos extra), sus permisos sin sueldo y los consumos
      que la empresa le facturó; nada baja de saldo hasta que cierres el rol.
    </p>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>

    <div style="display:flex; gap:10px; align-items:flex-end; margin-bottom:16px; background:#fff; border:1px solid #e2e5ea; border-radius:10px; padding:14px; flex-wrap:wrap;">
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Mes
        <Select v-model="mes" :options="meses" optionLabel="label" optionValue="value" data-testid="rol-mes" /></label>
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Año
        <InputNumber v-model="anio" :useGrouping="false" data-testid="rol-anio" /></label>
      <Button label="Generar rol" icon="pi pi-cog" :loading="cargando" @click="generar" data-testid="rol-generar" />
      <Button v-if="rol && abierto" label="Cerrar y contabilizar"
              icon="pi pi-check" severity="secondary" @click="cerrar" data-testid="rol-cerrar" />
      <Button v-if="rol" label="Rol general (PDF)" icon="pi pi-file-pdf" severity="secondary" outlined @click="rolGeneral('pdf')" data-testid="rol-general-pdf" />
      <Button v-if="rol" label="Rol general (Excel)" icon="pi pi-file-excel" severity="secondary" outlined @click="rolGeneral('csv')" data-testid="rol-general-csv" />
      <Tag v-if="rol" :value="rol.estado" :severity="rol.estado === 'cerrado' ? 'success' : 'warn'" />
    </div>

    <Message v-if="rol && conDiferido.length" severity="warn" :closable="false" style="margin-bottom:14px;" data-testid="aviso-diferido">
      A {{ conDiferido.length }} empleado(s) no les cupo todo el descuento porque el neto no puede quedar en negativo:
      <span v-for="(l, i) in conDiferido" :key="l.id"><b>{{ l.employee?.nombres }}</b> ({{ money(l.diferido) }} pasa al mes siguiente)<span v-if="Number(i) < conDiferido.length - 1">, </span></span>.
    </Message>

    <div v-if="rol" style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#94a3b8;">INGRESOS</div><b>{{ money(rol.total_ingresos) }}</b></div>
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#d93025;">EGRESOS</div><b>{{ money(rol.total_egresos) }}</b></div>
      <div style="border:2px solid #2c3e50; border-radius:8px; padding:10px; background:#f8fafc;">
        <div style="font-size:11px; color:#94a3b8;">NETO A PAGAR</div><b data-testid="rol-neto">{{ money(rol.total_neto) }}</b></div>
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#94a3b8;">PROVISIONES</div><b>{{ money(rol.total_provisiones) }}</b></div>
    </div>

    <DataTable v-if="rol" v-model:expandedRows="expandidos" :value="rol.lines" dataKey="id" size="small" stripedRows scrollable data-testid="tabla-rol">
      <Column expander style="width:3rem" />
      <Column header="Empleado"><template #body="{ data }">{{ data.employee?.nombres }}</template></Column>
      <Column header="Sueldo"><template #body="{ data }">{{ money(data.sueldo) }}</template></Column>
      <Column header="Extras"><template #body="{ data }">{{ money(extrasDe(data)) }}</template></Column>
      <Column header="IESS 9.45%"><template #body="{ data }">
        <span style="color:#d93025;">-{{ money(data.aporte_personal) }}</span></template></Column>
      <Column header="Descuentos"><template #body="{ data }">
        <span style="color:#d93025;">-{{ money(descuentosDe(data)) }}</span></template></Column>
      <Column header="Neto"><template #body="{ data }"><b>{{ money(data.neto) }}</b></template></Column>
      <Column header="Provisiones"><template #body="{ data }">{{ money(provisionesDe(data)) }}</template></Column>
      <Column header="Rol"><template #body="{ data }">
        <Button label="Rol individual" icon="pi pi-print" size="small" text @click="rolIndividual(data)" :data-testid="'rol-individual-' + data.employee_id" /></template></Column>

      <template #expansion="{ data }">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px; padding:6px 10px 10px;" :data-testid="'detalle-' + data.employee_id">
          <div>
            <div class="sec">Movimientos y permisos aplicados</div>
            <div v-if="!movimientosDe(data).length" class="nota">Sin movimientos este mes.</div>
            <div v-for="(d, i) in movimientosDe(data)" :key="i" class="fila">
              <span>{{ etiquetas[d.tipo] ?? d.tipo }}<template v-if="d.concepto"> · {{ d.concepto }}</template><template v-if="d.cuota"> ({{ d.cuota }})</template></span>
              <b :style="{ color: esDescuento(d) ? '#d93025' : '#188038' }">{{ esDescuento(d) ? '-' : '+' }}{{ money(d.monto) }}</b>
              <span v-if="n(d.diferido) > 0" class="dif">{{ money(d.diferido) }} pasa al mes siguiente (el neto no puede ser negativo)</span>
            </div>

            <div class="sec" style="margin-top:12px;">Provisiones del empleador</div>
            <div class="fila"><span>Aporte patronal</span><b>{{ money(data.aporte_patronal) }}</b></div>
            <div class="fila"><span>Décimo tercero</span><b>{{ money(data.decimo_tercero) }}</b></div>
            <div class="fila"><span>Décimo cuarto</span><b>{{ money(data.decimo_cuarto) }}</b></div>
            <div class="fila"><span>Fondos de reserva</span><b>{{ money(data.fondos_reserva) }}</b></div>
            <div class="fila"><span>Vacaciones</span><b>{{ money(data.vacaciones) }}</b></div>
          </div>
          <div>
            <div class="sec">Consumos de la empresa (facturas a crédito del empleado)</div>
            <div v-if="!consumosDe(data).length" class="nota">No tiene facturas abiertas. Vincula un cliente al empleado en Empleados > Laboral.</div>
            <div v-for="c in consumosDe(data)" :key="c.invoice_id" class="fila">
              <label style="display:flex; align-items:center; gap:8px; flex:1;">
                <Checkbox :modelValue="c.incluido" :binary="true" :disabled="!abierto"
                          @update:modelValue="(v: boolean) => alternarConsumo(data, c, v)" :data-testid="'consumo-' + c.invoice_id" />
                <span>Factura {{ c.numero }} <span class="nota">({{ c.fecha }}, saldo {{ money(c.saldo) }})</span></span>
              </label>
              <b :style="{ color: c.incluido && n(c.monto) > 0 ? '#d93025' : '#94a3b8' }">-{{ money(c.monto) }}</b>
              <span v-if="c.incluido && n(c.monto) < n(c.saldo)" class="dif">Se descuenta {{ money(c.monto) }}; el resto ({{ money(n(c.saldo) - n(c.monto)) }}) sigue abierto en la factura.</span>
            </div>
            <div v-if="abierto && consumosDe(data).length" class="nota" style="margin-top:6px;">
              Desmarca una factura para no descontarla en este rol. Al cerrar, cada valor descontado se cobra en la factura sin mover caja ni bancos.
            </div>
          </div>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<style scoped>
.sec { font-size: 11px; font-weight: 700; color: #546e7a; text-transform: uppercase; margin-bottom: 4px; }
.fila { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 3px 0; border-bottom: 1px solid #eef1f5; font-size: 12.5px; }
.nota { font-size: 12px; color: #64748b; }
.dif { flex-basis: 100%; font-size: 11.5px; color: #b45309; }
</style>
