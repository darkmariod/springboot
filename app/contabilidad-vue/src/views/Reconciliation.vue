<script setup lang="ts">
/**
 * Conciliación bancaria (T5.3).
 *  - Movimientos manuales con su contrapartida, número (MB-000001) y asiento; el número de cheque o documento y, si es un
 *    cheque, su fecha de cobro: un cheque posfechado se ve, pero no cuenta en el saldo ni se concilia antes de esa fecha.
 *  - Importar el estado de cuenta del banco en CSV: se muestran las parejas propuestas, lo que no tiene pareja en el sistema y lo
 *    que el banco no trae; aceptar solo marca como conciliado, nunca asienta nada.
 */
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useCuentasStore } from '../stores/cuentas'

const company = useCompanyStore()
const cuentas = useCuentasStore()
const banks = ref<any[]>([])
const bankId = ref<number | null>(null)
const data = ref<any>({ movimientos: [], saldo_sistema: 0, saldo_conciliado: 0, saldo_posfechado: 0, posfechados: 0 })
const msg = ref<any>(null)
const guardando = ref(false)

const tipos = [{ label: 'Crédito (entra)', value: 'credito' }, { label: 'Débito (sale)', value: 'debito' }]
const formas = [
  { label: 'Cheque', value: 'cheque' },
  { label: 'Transferencia', value: 'transferencia' }, { label: 'Depósito', value: 'deposito' }, { label: 'Otro', value: 'otro' },
]
const money = (n: any) => (Number(n ?? 0) < 0 ? '-' : '') + '$' + Math.abs(Number(n ?? 0)).toFixed(2)
const isoLocal = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const dmy = (iso: any) => { const [y, m, d] = String(iso ?? '').slice(0, 10).split('-'); return y ? `${d}/${m}/${y}` : '—' }
const vacio = () => ({ tipo: 'credito', fecha: new Date(), forma: null as string | null, documento: '', fecha_cobro: null as Date | null,
  monto: 0, concepto: '', cuenta_contrapartida_id: null as number | null, ya_contabilizado: false })
const nuevo = ref<any>(vacio())
const errorDe = (err: any, defecto: string) => {
  const e = err.response?.data?.errors
  return e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? defecto)
}

const posfechadoNuevo = computed(() => nuevo.value.forma === 'cheque' && nuevo.value.fecha_cobro
  && isoLocal(new Date(nuevo.value.fecha_cobro)) > isoLocal(new Date()))

async function loadBanks() {
  banks.value = (await api.get('/banks?company_id=' + company.activeId)).data
  bankId.value = banks.value[0]?.id ?? null
  if (bankId.value) load()
}
async function load() {
  if (!bankId.value) return
  data.value = (await api.get('/bank-movements?company_id=' + company.activeId + '&bank_id=' + bankId.value)).data
}

async function agregar() {
  msg.value = null
  const n = nuevo.value
  if (!bankId.value) { msg.value = { type: 'error', text: 'Elige un banco.' }; return }
  if (!n.ya_contabilizado && !n.cuenta_contrapartida_id) { msg.value = { type: 'error', text: 'Elige la cuenta de contrapartida del movimiento.' }; return }
  guardando.value = true
  try {
    const res = await api.post('/bank-movements', {
      company_id: company.activeId, bank_id: bankId.value, fecha: isoLocal(new Date(n.fecha)), tipo: n.tipo, monto: n.monto,
      concepto: n.concepto, documento: n.documento?.trim() || null, forma: n.forma,
      fecha_cobro: n.forma === 'cheque' && n.fecha_cobro ? isoLocal(new Date(n.fecha_cobro)) : null,
      ya_contabilizado: n.ya_contabilizado, cuenta_contrapartida_id: n.ya_contabilizado ? null : n.cuenta_contrapartida_id,
    })
    const num = res.data.numero
    msg.value = { type: 'success', text: num ? `Movimiento ${num} registrado y asentado.` : 'Movimiento registrado para conciliar (sin asiento).' }
    nuevo.value = vacio()
    await load()
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudo registrar el movimiento.') }
  } finally {
    guardando.value = false
  }
}

async function toggle(m: any) {
  msg.value = null
  try { await api.post('/bank-movements/' + m.id + '/toggle') } catch (err: any) { msg.value = { type: 'error', text: errorDe(err, 'No se pudo conciliar.') } }
  load()
}

function estado(m: any) {
  if (m.posfechado) return { label: 'Cheque posfechado · cobro ' + dmy(m.fecha_cobro), severity: 'warn' }
  if (m.conciliado) return { label: 'Conciliado', severity: 'success' }
  return { label: 'Pendiente', severity: 'secondary' }
}

// ── Importar estado de cuenta (CSV) ──────────────────────────────────────────
const imp = ref<any>(null)
function abrirImportar() {
  imp.value = { archivo: null as File | null, desde: null, hasta: null, cargando: false, aplicando: false, error: '', resultado: null }
}
function elegirArchivo(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  if (imp.value) { imp.value.archivo = f; imp.value.resultado = null; imp.value.error = '' }
}
async function revisar() {
  const i = imp.value
  if (!i?.archivo || !bankId.value) return
  i.cargando = true
  i.error = ''
  i.resultado = null
  try {
    const fd = new FormData()
    fd.append('company_id', String(company.activeId))
    fd.append('bank_id', String(bankId.value))
    fd.append('csv', i.archivo)
    if (i.desde) fd.append('desde', isoLocal(new Date(i.desde)))
    if (i.hasta) fd.append('hasta', isoLocal(new Date(i.hasta)))
    i.resultado = (await api.post('/bank-movements/importar', fd)).data
  } catch (err: any) {
    i.error = errorDe(err, 'No se pudo leer el archivo.')
  } finally {
    i.cargando = false
  }
}
const parejas = computed(() => (imp.value?.resultado?.lineas ?? []).filter((l: any) => l.movimiento))
async function aceptar() {
  const i = imp.value
  if (!i?.resultado || !parejas.value.length) return
  i.aplicando = true
  try {
    const r = (await api.post('/bank-movements/importar/aplicar', {
      company_id: company.activeId, bank_id: bankId.value, ids: parejas.value.map((l: any) => l.movimiento.id),
    })).data
    const res = i.resultado.resumen
    msg.value = { type: 'success', text: `${r.conciliados} conciliados, ${res.sin_pareja_sistema} sin pareja en el sistema, ${res.sin_pareja_banco} sin pareja en el banco.` }
    imp.value = null
    await load()
  } catch (err: any) {
    i.error = errorDe(err, 'No se pudo conciliar.')
  } finally {
    i.aplicando = false
  }
}
/** Lleva una línea del banco que el sistema no tiene al formulario para registrarla (con su contrapartida y asiento). */
function registrarLinea(l: any) {
  nuevo.value = { ...vacio(), tipo: l.tipo, fecha: new Date(l.fecha + 'T00:00:00'), monto: l.monto, concepto: l.descripcion || 'Movimiento del banco', documento: l.documento ?? '' }
  imp.value = null
  msg.value = { type: 'info', text: 'Completa la cuenta de contrapartida y pulsa Agregar para registrar el movimiento del banco.' }
}

watch(() => company.activeId, () => { cuentas.cargar(); loadBanks() })
onMounted(() => { loadBanks(); cuentas.cargar() })
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:14px; gap:12px; flex-wrap:wrap;">
      <div style="flex:1; min-width:240px;"><h2 style="margin:0 0 8px;">Conciliación bancaria</h2>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <Select v-model="bankId" :options="banks" optionLabel="nombre" optionValue="id" placeholder="Banco" @change="load" />
          <Button label="Importar estado de cuenta (CSV)" icon="pi pi-upload" size="small" severity="secondary" outlined
                  :disabled="!bankId" data-testid="btn-importar" @click="abrirImportar" />
        </div></div>
      <div style="display:flex; gap:10px;">
        <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px 14px; background:#fff;">
          <div style="font-size:11px; color:#94a3b8;">SALDO SISTEMA</div><b data-testid="saldo-sistema">{{ money(data.saldo_sistema) }}</b></div>
        <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px 14px; background:#fff;">
          <div style="font-size:11px; color:#94a3b8;">CONCILIADO</div><b data-testid="saldo-conciliado">{{ money(data.saldo_conciliado) }}</b></div>
        <div v-if="data.posfechados" style="border:1px solid #f1d9a6; border-radius:8px; padding:10px 14px; background:#fffaf0;">
          <div style="font-size:11px; color:#b7791f;">POSFECHADOS ({{ data.posfechados }})</div><b data-testid="saldo-posfechado">{{ money(data.saldo_posfechado) }}</b></div>
      </div>
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Los pagos y cobros hechos con cheque o transferencia aparecen aquí solos, con su número de documento.
      Los demás movimientos del banco los registras abajo con su cuenta de contrapartida (quedan asentados), o importas el estado de cuenta
      en CSV para conciliar de una vez. Un cheque posfechado no cuenta en el saldo hasta su fecha de cobro.
      El saldo conciliado debe coincidir con el saldo final del estado de cuenta.
    </Message>
    <Message v-if="msg" :severity="msg.type" closable style="margin-bottom:14px;" @close="msg = null">{{ msg.text }}</Message>

    <div class="form-mov">
      <label class="f">Tipo<Select v-model="nuevo.tipo" :options="tipos" optionLabel="label" optionValue="value" /></label>
      <label class="f">Fecha<DatePicker v-model="nuevo.fecha" dateFormat="dd/mm/yy" showIcon style="width:150px" /></label>
      <label class="f">Forma<Select v-model="nuevo.forma" :options="formas" optionLabel="label" optionValue="value" placeholder="Sin especificar" showClear style="width:170px" /></label>
      <label class="f">N.º cheque / documento<InputText v-model="nuevo.documento" maxlength="100" style="width:160px" data-testid="documento" /></label>
      <label v-if="nuevo.forma === 'cheque'" class="f">Fecha de cobro<DatePicker v-model="nuevo.fecha_cobro" dateFormat="dd/mm/yy" showIcon style="width:150px" data-testid="fecha-cobro" /></label>
      <label class="f">Monto<InputNumber v-model="nuevo.monto" mode="currency" currency="USD" locale="en-US" :input-style="{ width: '130px' }" @focus="($event: Event) => ($event.target as HTMLInputElement).select()" /></label>
      <label class="f" style="flex:1; min-width:200px;">Concepto<InputText v-model="nuevo.concepto" maxlength="255" /></label>
      <label v-if="!nuevo.ya_contabilizado" class="f" style="min-width:260px;">Contrapartida
        <Select v-model="nuevo.cuenta_contrapartida_id" :options="cuentas.detalle" optionLabel="label" optionValue="id" filter
                placeholder="Cuenta contable" emptyFilterMessage="Sin coincidencias" data-testid="contrapartida" /></label>
      <label class="f chk"><Checkbox v-model="nuevo.ya_contabilizado" :binary="true" inputId="ya" />
        <span>Ya está contabilizado (solo conciliar, sin asiento)</span></label>
      <Button label="Agregar" :loading="guardando" data-testid="agregar" @click="agregar" />
      <p v-if="posfechadoNuevo" class="nota">Cheque posfechado: el asiento queda hoy, pero no cuenta en el saldo ni se concilia hasta su fecha de cobro.</p>
    </div>

    <DataTable :value="data.movimientos" size="small" stripedRows data-testid="tabla-movimientos">
      <Column header="Fecha" style="width:92px"><template #body="{ data: m }">{{ dmy(m.fecha) }}</template></Column>
      <Column header="N.º" style="width:100px"><template #body="{ data: m }"><span class="mono">{{ m.numero ?? '—' }}</span></template></Column>
      <Column header="Concepto"><template #body="{ data: m }">
        {{ m.concepto }}
        <div v-if="m.cuenta" class="sub">Contrapartida: {{ m.cuenta.codigo }} · {{ m.cuenta.nombre }}</div></template></Column>
      <Column header="Documento"><template #body="{ data: m }">{{ m.documento ?? '' }}</template></Column>
      <Column header="Monto"><template #body="{ data: m }">
        <span :style="{color: m.tipo==='credito' ? '#22a06b':'#d93025'}">{{ m.tipo==='credito'?'+':'-' }}{{ money(m.monto) }}</span></template></Column>
      <Column header="Estado" style="width:230px"><template #body="{ data: m }">
        <Tag :value="estado(m).label" :severity="estado(m).severity" /></template></Column>
      <Column header="Conciliado" style="width:96px"><template #body="{ data: m }">
        <Checkbox :modelValue="m.conciliado" :binary="true" :disabled="m.posfechado && !m.conciliado" @update:modelValue="toggle(m)" /></template></Column>
      <template #empty><div style="padding:18px; text-align:center; color:#94a3b8;">Este banco todavía no tiene movimientos.</div></template>
    </DataTable>

    <!-- Importar estado de cuenta -->
    <Dialog :visible="!!imp" modal header="Importar estado de cuenta (CSV)" style="width:980px; max-width:96vw" @update:visible="imp = null">
      <div v-if="imp">
        <p class="nota" style="margin-top:0">
          Sube el archivo que baja tu banco (CSV, separado por comas, punto y coma o tabulaciones). Reconozco las columnas
          <b>Fecha</b>, <b>Descripción/Detalle</b>, <b>Documento/Referencia</b> y <b>Débito + Crédito</b> o <b>Valor</b> (con <b>Tipo</b>, o con los débitos en negativo).
          Esto solo concilia: no crea ningún asiento.
        </p>
        <div class="imp-form">
          <label class="f">Archivo<input type="file" accept=".csv,.txt,text/csv,text/plain" data-testid="archivo-csv" @change="elegirArchivo" /></label>
          <label class="f">Banco<InputText :modelValue="banks.find((b: any) => b.id === bankId)?.nombre" disabled style="width:200px" /></label>
          <label class="f">Desde<DatePicker v-model="imp.desde" dateFormat="dd/mm/yy" showIcon showButtonBar style="width:150px" /></label>
          <label class="f">Hasta<DatePicker v-model="imp.hasta" dateFormat="dd/mm/yy" showIcon showButtonBar style="width:150px" /></label>
          <Button label="Revisar archivo" icon="pi pi-search" :disabled="!imp.archivo" :loading="imp.cargando" data-testid="revisar" @click="revisar" />
        </div>
        <Message v-if="imp.error" severity="error" :closable="false" style="margin:10px 0 0">{{ imp.error }}</Message>

        <template v-if="imp.resultado">
          <div class="conteo" data-testid="conteo">
            <b>{{ imp.resultado.resumen.con_pareja }}</b> con pareja (se concilian al aceptar),
            <b>{{ imp.resultado.resumen.sin_pareja_sistema }}</b> sin pareja en el sistema,
            <b>{{ imp.resultado.resumen.sin_pareja_banco }}</b> sin pareja en el banco
            <span class="sub" style="margin-left:8px">
              Formato: {{ imp.resultado.formato }} · período {{ dmy(imp.resultado.periodo.desde) }} al {{ dmy(imp.resultado.periodo.hasta) }}
              <template v-if="imp.resultado.resumen.ignoradas"> · {{ imp.resultado.resumen.ignoradas }} fila(s) ignoradas</template>
              <template v-if="imp.resultado.resumen.fuera_del_periodo"> · {{ imp.resultado.resumen.fuera_del_periodo }} fuera del período</template>
            </span>
          </div>

          <h4 class="tit">Movimientos del archivo del banco</h4>
          <DataTable :value="imp.resultado.lineas" size="small" stripedRows scrollable scrollHeight="260px" data-testid="tabla-lineas">
            <Column header="Fecha" style="width:92px"><template #body="{ data: l }">{{ dmy(l.fecha) }}</template></Column>
            <Column field="descripcion" header="Descripción" />
            <Column header="Documento" style="width:110px"><template #body="{ data: l }">{{ l.documento ?? '' }}</template></Column>
            <Column header="Monto" style="width:100px"><template #body="{ data: l }">
              <span :style="{color: l.tipo==='credito' ? '#22a06b':'#d93025'}">{{ l.tipo==='credito'?'+':'-' }}{{ money(l.monto) }}</span></template></Column>
            <Column header="Pareja en el sistema"><template #body="{ data: l }">
              <template v-if="l.movimiento">
                <Tag value="Pareja" severity="success" />
                <span class="sub" style="margin-left:6px">{{ l.movimiento.numero ?? 'sin número' }} · {{ dmy(l.movimiento.fecha) }} · {{ l.movimiento.concepto }}
                  <i>({{ l.criterio }})</i></span>
              </template>
              <template v-else>
                <Tag value="Sin pareja en el sistema" severity="danger" />
                <Button label="Registrar" size="small" text @click="registrarLinea(l)" />
              </template></template></Column>
          </DataTable>

          <h4 class="tit">Movimientos del sistema que el banco no trae</h4>
          <DataTable :value="imp.resultado.sin_pareja_banco" size="small" stripedRows data-testid="tabla-sin-pareja-banco">
            <Column header="Fecha" style="width:92px"><template #body="{ data: m }">{{ dmy(m.fecha) }}</template></Column>
            <Column header="N.º" style="width:100px"><template #body="{ data: m }"><span class="mono">{{ m.numero ?? '—' }}</span></template></Column>
            <Column field="concepto" header="Concepto" />
            <Column header="Documento" style="width:110px"><template #body="{ data: m }">{{ m.documento ?? '' }}</template></Column>
            <Column header="Monto" style="width:100px"><template #body="{ data: m }">
              <span :style="{color: m.tipo==='credito' ? '#22a06b':'#d93025'}">{{ m.tipo==='credito'?'+':'-' }}{{ money(m.monto) }}</span></template></Column>
            <template #empty><div style="padding:10px; color:#94a3b8;">Todo lo que tienes pendiente en ese período aparece en el archivo.</div></template>
          </DataTable>
        </template>
      </div>
      <template #footer>
        <Button label="Ignorar" text @click="imp = null" />
        <Button v-if="imp?.resultado" :label="`Aceptar ${parejas.length} pareja(s)`" icon="pi pi-check" :disabled="!parejas.length"
                :loading="imp.aplicando" data-testid="aceptar" @click="aceptar" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.form-mov { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px; background:#fff; border:1px solid #e2e5ea; border-radius:10px; padding:12px; }
.f { display:flex; flex-direction:column; gap:4px; font-size:12px; }
.f.chk { flex-direction:row; align-items:center; gap:6px; padding-bottom:8px; }
.nota { flex-basis:100%; margin:2px 0 0; font-size:12px; color:#64748b; }
.sub { font-size:11.5px; color:#8fa2ad; }
.mono { font-family: ui-monospace, Menlo, monospace; font-size:12px; }
.imp-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.conteo { margin:14px 0 6px; padding:9px 12px; background:#f4f8f9; border:1px solid #dbe6ea; border-radius:8px; font-size:13.5px; }
.tit { margin:14px 0 6px; font-size:13.5px; }
</style>
