<script setup lang="ts">
/**
 * Conciliación de tarjetas.
 * Un cobro con tarjeta no entra el mismo día: el procesador deposita después,
 * en lotes y descontando comisión. Aquí se cruza cada voucher contra ese depósito.
 */
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const pendientes = ref<any[]>([])
const liquidaciones = ref<any[]>([])
const resumen = ref<any>({})
const seleccion = ref<any[]>([])
const activa = ref<any>(null)          // liquidación que se está conciliando
const dialog = ref<any>(null)
const bancos = ref<any[]>([])
const msg = ref<any>(null)
const loading = ref(true)

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const fecha = (d: any) => (d ? new Date(d).toLocaleDateString('es-EC') : '—')
const isoLocal = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const centavos = (n: any) => Math.round(Number(n ?? 0) * 100)

// Bruto = neto + comisión + retenciones. El neto se calcula, y si lo corriges a mano (lo que dice tu banco) el servidor revisa el cuadre.
const netoCalculado = computed(() => dialog.value
  ? (centavos(dialog.value.monto_bruto) - centavos(dialog.value.comision) - centavos(dialog.value.retencion_iva) - centavos(dialog.value.retencion_renta)) / 100
  : 0)
const descuadre = computed(() => dialog.value && dialog.value.monto_neto !== null
  ? centavos(dialog.value.monto_bruto) - centavos(dialog.value.monto_neto) - centavos(dialog.value.comision) - centavos(dialog.value.retencion_iva) - centavos(dialog.value.retencion_renta)
  : 0)

const sumaSeleccion = computed(() =>
  seleccion.value.reduce((s, t) => s + Number(t.monto), 0))
const diferencia = computed(() =>
  activa.value ? Number((sumaSeleccion.value - Number(activa.value.monto_bruto)).toFixed(2)) : 0)

async function cargar() {
  loading.value = true
  try {
    const id = company.activeId
    const [p, l, r, b] = await Promise.all([
      api.get('/card/pendientes', { params: { company_id: id } }),
      api.get('/card/settlements', { params: { company_id: id } }),
      api.get('/card/resumen', { params: { company_id: id } }),
      api.get('/banks', { params: { company_id: id } }),
    ])
    pendientes.value = p.data
    liquidaciones.value = l.data
    resumen.value = r.data
    bancos.value = b.data
  } finally { loading.value = false }
}

function nuevaLiquidacion() {
  dialog.value = { fecha: new Date(), bank_id: bancos.value[0]?.id ?? null, procesador: 'Datafast', lote: '', monto_bruto: null, comision: null,
    retencion_iva: null, retencion_renta: null, monto_neto: null, notas: '' }
}

// Mientras no corrijas el neto a mano, sigue al cálculo
watch(netoCalculado, (n) => { if (dialog.value && !dialog.value.neto_manual) dialog.value.monto_neto = dialog.value.monto_bruto ? n : null })

async function guardarLiquidacion() {
  try {
    const { neto_manual, ...resto } = dialog.value
    const d = { ...resto, company_id: company.activeId, fecha: isoLocal(new Date(dialog.value.fecha)) }
    const { data } = await api.post('/card/settlements', d)
    msg.value = { type: 'success', text: `Liquidación ${data.numero} registrada y asentada: ${money(data.monto_neto)} acreditados al banco.` }
    dialog.value = null
    await cargar()
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
  }
}

/** Abre una liquidación para conciliarla y precarga lo ya asignado. */
async function conciliar(l: any) {
  activa.value = l
  seleccion.value = []
  await cargar()
}

async function sugerir() {
  if (!activa.value) return
  const { data } = await api.get(`/card/settlements/${activa.value.id}/sugerir`)
  seleccion.value = pendientes.value.filter((t) => data.transaction_ids.includes(t.id))
  msg.value = data.cuadra
    ? { type: 'success', text: `Se encontraron ${data.transaction_ids.length} cobros que suman exactamente ${money(data.suma)}.` }
    : { type: 'warn', text: `Lo más cercano suma ${money(data.suma)} de ${money(data.objetivo)}. Revisa manualmente.` }
}

async function confirmar() {
  if (!activa.value) return
  try {
    await api.post(`/card/settlements/${activa.value.id}/asignar`,
      { transaction_ids: seleccion.value.map((t) => t.id) })
    msg.value = diferencia.value === 0
      ? { type: 'success', text: 'Liquidación conciliada: los cobros cuadran con el depósito.' }
      : { type: 'warn', text: `Guardado, pero queda una diferencia de ${money(Math.abs(diferencia.value))}.` }
    activa.value = null
    seleccion.value = []
    await cargar()
  } catch { msg.value = { type: 'error', text: 'No se pudo guardar la conciliación.' } }
}

async function eliminar(l: any) {
  try {
    await api.delete('/card/settlements/' + l.id)
    msg.value = { type: 'success', text: 'Liquidación eliminada; sus cobros vuelven a pendientes.' }
    if (activa.value?.id === l.id) activa.value = null
    await cargar()
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo eliminar.' }
  }
}

watch(() => company.activeId, cargar)
onMounted(cargar)
</script>

<template>
  <div class="cr">
    <header class="cr-head">
      <div>
        <h2>Conciliación de tarjetas</h2>
        <p>Cruza los cobros con tarjeta contra lo que efectivamente depositó el procesador.</p>
      </div>
      <Button label="Registrar liquidación" icon="pi pi-plus" size="small" @click="nuevaLiquidacion" />
    </header>

    <Message v-if="msg" :severity="msg.type" closable style="margin-bottom:14px;" @close="msg = null">
      {{ msg.text }}
    </Message>

    <div class="cr-kpis">
      <div class="k"><span>PENDIENTE DE DEPÓSITO</span><b>{{ money(resumen.monto_pendiente) }}</b>
        <i>{{ resumen.pendientes ?? 0 }} cobros</i></div>
      <div class="k"><span>YA CONCILIADO</span><b>{{ money(resumen.conciliado) }}</b><i>&nbsp;</i></div>
      <div class="k"><span>COMISIONES</span><b class="neg">{{ money(resumen.comisiones) }}</b>
        <i>lo que cobra el procesador</i></div>
      <div class="k"><span>LIQUIDACIONES ABIERTAS</span><b>{{ resumen.liquidaciones_abiertas ?? 0 }}</b><i>&nbsp;</i></div>
    </div>

    <div class="cr-grid">
      <!-- Liquidaciones -->
      <section class="panel">
        <h3>Liquidaciones del procesador</h3>
        <DataTable :value="liquidaciones" :loading="loading" size="small" stripedRows
                   :rowClass="(d) => activa?.id === d.id ? 'fila-activa' : ''">
          <Column header="Fecha" style="width:100px"><template #body="{ data }">{{ fecha(data.fecha) }}</template></Column>
          <Column header="N.º" style="width:112px"><template #body="{ data }"><span class="mono" style="white-space:nowrap">{{ data.numero ?? '—' }}</span></template></Column>
          <Column field="procesador" header="Procesador" />
          <Column header="Bruto" style="width:96px"><template #body="{ data }">{{ money(data.monto_bruto) }}</template></Column>
          <Column header="Comisión" style="width:96px"><template #body="{ data }">
            <span class="neg">{{ money(data.comision) }}</span></template></Column>
          <Column header="Depositado" style="width:104px"><template #body="{ data }">
            <b>{{ money(data.monto_neto) }}</b></template></Column>
          <Column header="Estado" style="width:120px"><template #body="{ data }">
            <Tag :value="data.estado === 'conciliada' ? 'Conciliada' : 'Abierta'"
                 :severity="data.estado === 'conciliada' ? 'success' : 'warn'" /></template></Column>
          <Column header="" style="width:150px"><template #body="{ data }">
            <Button label="Conciliar" size="small" text @click="conciliar(data)" />
            <Button icon="pi pi-trash" size="small" text severity="danger" @click="eliminar(data)" />
          </template></Column>
          <template #empty><div class="vacio">
            Registra la liquidación que te envía Datafast, Medianet o tu banco.</div></template>
        </DataTable>
      </section>

      <!-- Cobros pendientes -->
      <section class="panel">
        <h3>
          Cobros con tarjeta pendientes
          <small v-if="activa">· conciliando {{ activa.procesador }} {{ fecha(activa.fecha) }}</small>
        </h3>

        <div v-if="activa" class="cr-barra">
          <div>
            Seleccionado <b>{{ money(sumaSeleccion) }}</b> de <b>{{ money(activa.monto_bruto) }}</b>
            <span :class="['dif', diferencia === 0 ? 'ok' : 'no']">
              {{ diferencia === 0 ? 'cuadra' : (diferencia > 0 ? 'sobran ' : 'faltan ') + money(Math.abs(diferencia)) }}
            </span>
          </div>
          <div>
            <Button label="Sugerir" icon="pi pi-bolt" size="small" outlined @click="sugerir" />
            <Button label="Confirmar" icon="pi pi-check" size="small" @click="confirmar" />
            <Button label="Cancelar" size="small" text @click="activa = null; seleccion = []" />
          </div>
        </div>

        <DataTable v-model:selection="seleccion" :value="pendientes" :loading="loading"
                   size="small" stripedRows dataKey="id" :disabled="!activa">
          <Column selectionMode="multiple" style="width:44px" />
          <Column header="Fecha" style="width:100px"><template #body="{ data }">{{ fecha(data.fecha) }}</template></Column>
          <Column header="Factura"><template #body="{ data }">
            <span class="mono">{{ data.invoice?.numero ?? '—' }}</span></template></Column>
          <Column header="Monto" style="width:100px"><template #body="{ data }">
            <b>{{ money(data.monto) }}</b></template></Column>
          <template #empty><div class="vacio">
            No hay cobros con tarjeta pendientes de depósito.</div></template>
        </DataTable>
      </section>
    </div>

    <Dialog :visible="!!dialog" modal header="Registrar liquidación del procesador"
            style="width:520px" @update:visible="dialog = null">
      <div v-if="dialog">
        <p class="ayuda">Copia los datos del comprobante que te envía el procesador o del depósito en tu banco.
          Al guardar se asienta: Debe Bancos (neto), Comisiones y Retenciones / Haber Tarjetas por liquidar (bruto).</p>
        <fieldset class="kvs-fieldset">
          <legend>Datos del depósito</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Banco:</label>
            <Select v-model="dialog.bank_id" :options="bancos" optionLabel="nombre" optionValue="id" placeholder="Banco donde se acreditó" class="kvs-in" data-testid="liq-banco" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Fecha:</label>
            <DatePicker v-model="dialog.fecha" dateFormat="dd/mm/yy" showIcon class="kvs-in" style="max-width:180px" />
            <label class="kvs-lbl" style="margin-left:14px;"><span class="req-star">*</span> Procesador:</label>
            <InputText v-model="dialog.procesador" class="kvs-in" placeholder="Datafast, Medianet…" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Lote:</label>
            <InputText v-model="dialog.lote" class="kvs-in" style="max-width:160px" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Monto bruto:</label>
            <InputNumber v-model="dialog.monto_bruto" mode="currency" currency="USD" @focus="($event: FocusEvent) => ($event.target as HTMLInputElement).select()" locale="en-US"
                         class="kvs-in" style="max-width:170px" />
            <label class="kvs-lbl" style="margin-left:14px;">Comisión:</label>
            <InputNumber v-model="dialog.comision" mode="currency" currency="USD" @focus="($event: FocusEvent) => ($event.target as HTMLInputElement).select()" locale="en-US"
                         class="kvs-in" style="max-width:170px" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Retención IVA:</label>
            <InputNumber v-model="dialog.retencion_iva" mode="currency" currency="USD" @focus="($event: Event) => ($event.target as HTMLInputElement).select()" locale="en-US"
                         class="kvs-in" style="max-width:170px" />
            <label class="kvs-lbl" style="margin-left:14px;">Retención renta:</label>
            <InputNumber v-model="dialog.retencion_renta" mode="currency" currency="USD" @focus="($event: Event) => ($event.target as HTMLInputElement).select()" locale="en-US"
                         class="kvs-in" style="max-width:170px" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Neto depositado:</label>
            <InputNumber v-model="dialog.monto_neto" mode="currency" currency="USD" locale="en-US" class="kvs-in" style="max-width:170px" data-testid="liq-neto"
                         @focus="($event: Event) => ($event.target as HTMLInputElement).select()" @input="dialog.neto_manual = true" />
            <Button label="Calcular" size="small" text @click="dialog.neto_manual = false; dialog.monto_neto = dialog.monto_bruto ? netoCalculado : null" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Notas:</label>
            <InputText v-model="dialog.notas" class="kvs-in" />
          </div>
        </fieldset>
        <p class="neto">Se depositará: <b>{{ money(dialog.monto_neto ?? netoCalculado) }}</b>
          <span v-if="descuadre !== 0" class="dif no" data-testid="liq-descuadre">No cuadra: el bruto debe ser neto + comisión + retenciones (diferencia {{ money(Math.abs(descuadre) / 100) }})</span></p>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog = null" />
        <Button label="Guardar" icon="pi pi-save" :disabled="!dialog || !dialog.bank_id || !dialog.monto_bruto || descuadre !== 0" @click="guardarLiquidacion" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.cr { padding: 20px; }
.cr-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
.cr-head h2 { margin: 0 0 3px; font-size: 20px; }
.cr-head p { color: #8fa2ad; font-size: 13px; margin: 0; }
.cr-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
.k { background: #fff; border: 1px solid #e2e9ed; border-radius: 10px; padding: 13px 15px; }
.k span { font-size: 10.5px; letter-spacing: .06em; color: #8fa2ad; font-weight: 700; display: block; }
.k b { font-size: 21px; font-weight: 800; letter-spacing: -.4px; display: block; margin: 3px 0 1px; }
.k i { font-style: normal; font-size: 11.5px; color: #94a3b8; }
.neg { color: #d93025; }
.cr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
.panel { background: #fff; border: 1px solid #e2e9ed; border-radius: 10px; padding: 14px; }
.panel h3 { margin: 0 0 10px; font-size: 15px; }
.panel h3 small { font-weight: 400; color: #8fa2ad; font-size: 12.5px; }
.cr-barra { display: flex; justify-content: space-between; align-items: center; gap: 12px;
  background: #f4f8f9; border: 1px solid #dbe6ea; border-radius: 8px; padding: 9px 12px;
  margin-bottom: 10px; font-size: 13px; flex-wrap: wrap; }
.dif { margin-left: 10px; font-weight: 700; padding: 2px 9px; border-radius: 99px; font-size: 12px; }
.dif.ok { background: #e3f5ef; color: #0b7c72; }
.dif.no { background: #fdeceb; color: #d93025; }
.vacio { padding: 22px; text-align: center; color: #94a3b8; font-size: 13px; }
.mono { font-family: ui-monospace, Menlo, monospace; font-size: 12.5px; }
.ayuda { font-size: 13px; color: #64748b; margin: 0 0 12px; }
.neto { margin: 12px 0 0; font-size: 14px; }
:deep(.fila-activa) { background: #eef6f6 !important; box-shadow: inset 3px 0 0 #0f9b8e; }
@media (max-width: 1100px) { .cr-grid { grid-template-columns: 1fr; } .cr-kpis { grid-template-columns: 1fr 1fr; } }
</style>
