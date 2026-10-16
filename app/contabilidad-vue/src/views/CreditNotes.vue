<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import ToggleSwitch from 'primevue/toggleswitch'
import Checkbox from 'primevue/checkbox'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { usePlanStore } from '../stores/plan'
import EnviarCorreoDialog from '../components/EnviarCorreoDialog.vue'

const company = useCompanyStore()
const plan = usePlanStore()
// Sin facturación electrónica del SRI solo existen las notas internas (regulan saldos y no se envían)
const sinSri = computed(() => !plan.tiene('facturacion_sri'))
const rows = ref<any[]>([])
const contacts = ref<any[]>([])
const facturas = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const saving = ref(false)
const error = ref('')
const msg = ref('')
const msgOk = ref(true)
const emitiendo = ref<number | null>(null)
const anularTarget = ref<any>(null)
const anularBusy = ref(false)
// Si el SRI ya la autorizó, anularla aquí no basta: hay que confirmarlo y recordar el portal SRI en línea
const confirmaSri = ref(false)
// Aviso que queda en pantalla hasta que se cierre (la respuesta del servidor dice si hace falta anular también en el SRI)
const avisoAnulacion = ref<{ texto: string; requiere: boolean } | null>(null)
const filtroEstado = ref('')
const estados = [
  { label: 'Todas', value: '' },
  { label: 'Vigentes', value: 'vigentes' },
  { label: 'Anuladas', value: 'anuladas' },
]

// La nota corrige UNA factura: se elige el cliente, luego su factura, y las líneas salen de esa factura.
const nuevoForm = () => ({
  contact_id: null as number | null,
  invoice_id: null as number | null,
  interna: !plan.tiene('facturacion_sri'),
  devuelve_stock: false,
  motivo: '',
  lineas: [] as any[],
})
const form = ref<any>(nuevoForm())

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const redondear = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100
const esAutorizada = (r: any) => String(r?.sri_document?.estado ?? '').toUpperCase() === 'AUTORIZADO'
const filas = computed(() => rows.value.filter((r: any) =>
  !filtroEstado.value || (filtroEstado.value === 'anuladas' ? r.tipo === 'anulado' : r.tipo !== 'anulado')))
const clientes = computed(() => contacts.value.filter((c: any) => c.es_cliente !== false))
// Solo facturas vigentes de ese cliente; la etiqueta deja buscar por número, fecha o monto
const facturasDelCliente = computed(() => facturas.value
  .filter((f: any) => f.estado !== 'anulado')
  .map((f: any) => ({
    ...f,
    etiqueta: `${f.numero} · ${String(f.fecha_emision ?? '').slice(0, 10)} · total ${money(f.importe_total)} · saldo ${money(f.saldo_pendiente)}`,
  })))
const facturaElegida = computed(() => facturasDelCliente.value.find((f: any) => f.id === form.value.invoice_id) ?? null)

// El descuento de la línea de la factura baja en proporción si se acredita menos cantidad
const descuentoLinea = (l: any) => l.maximo > 0 ? redondear((l.descuento || 0) * ((l.cantidad || 0) / l.maximo)) : 0
const subtotalLinea = (l: any) => redondear((l.cantidad || 0) * (l.precio_unitario || 0) - descuentoLinea(l))
const ivaLinea = (l: any) => redondear(subtotalLinea(l) * (l.tarifa ?? 15) / 100)
const subtotal = computed(() => redondear(form.value.lineas.reduce((s: number, l: any) => s + subtotalLinea(l), 0)))
const iva = computed(() => redondear(form.value.lineas.reduce((s: number, l: any) => s + ivaLinea(l), 0)))
const total = computed(() => redondear(subtotal.value + iva.value))
// Lo que baja de la factura y lo que sobra a favor del cliente
const saldoFactura = computed(() => Number(facturaElegida.value?.saldo_pendiente ?? 0))
const baja = computed(() => redondear(Math.min(total.value, Math.max(saldoFactura.value, 0))))
const saldoDespues = computed(() => redondear(saldoFactura.value - baja.value))
const sobra = computed(() => redondear(total.value - baja.value))
// Productos con series: al devolver mercadería hay que elegir CUÁLES series regresan (tantas como unidades).
// Una línea tiene `seriesVendidas` cuando la factura la vendió con series que siguen vendidas.
const llevaSeries = (l: any) => (l.seriesVendidas?.length ?? 0) > 0
const hayLineasConSeries = computed(() => form.value.lineas.some(llevaSeries))
const pideSeries = computed(() => form.value.devuelve_stock && hayLineasConSeries.value)
// Las que otra línea del mismo producto ya eligió no se ofrecen otra vez
const opcionesDe = (l: any) => (l.seriesVendidas ?? []).filter((s: string) =>
  !form.value.lineas.some((o: any) => o !== l && o.codigo_principal === l.codigo_principal && (o.series ?? []).includes(s)))
const seriesOk = (l: any) => !(form.value.devuelve_stock && llevaSeries(l))
  || (Number.isInteger(l.cantidad) && (l.series ?? []).length === l.cantidad)
const seriesCompletas = computed(() => form.value.lineas.every(seriesOk))
/** Devolver TODO lo vendido con series marca todas; con una cantidad menor se conservan solo las que caben. */
function ajustarSeries(l: any, cantidad: number) {
  const todas: string[] = l.seriesVendidas ?? []
  if (!todas.length) return
  if (cantidad === todas.length) l.series = [...todas]
  else l.series = (l.series ?? []).filter((s: string) => todas.includes(s)).slice(0, Math.max(0, Math.floor(cantidad || 0)))
}
const puedeGuardar = computed(() => !!form.value.contact_id && !!form.value.invoice_id && form.value.motivo.trim() !== ''
  && form.value.lineas.length > 0 && total.value > 0 && seriesCompletas.value)

function mensajeDeError(err: any, porDefecto: string) {
  const e = err?.response?.data?.errors
  return e ? Object.values(e).flat().join(' · ') : (err?.response?.data?.message ?? err?.response?.data?.error ?? porDefecto)
}

async function load() {
  loading.value = true
  rows.value = (await api.get('/credit-notes?company_id=' + company.activeId)).data
  contacts.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  loading.value = false
}
function abrir() {
  error.value = ''
  facturas.value = []
  form.value = nuevoForm()
  dialog.value = true
}
async function alElegirCliente() {
  form.value.invoice_id = null
  form.value.lineas = []
  facturas.value = []
  if (!form.value.contact_id) return
  facturas.value = (await api.get('/invoices', { params: { company_id: company.activeId, contact_id: form.value.contact_id } })).data
}
// Las líneas salen de la factura: se puede quitar una o bajar su cantidad (nunca subirla)
let seqFactura = 0
async function alElegirFactura() {
  const f = facturaElegida.value
  const seq = ++seqFactura
  form.value.lineas = (f?.items ?? []).map((it: any) => ({
    codigo_principal: it.codigo_principal,
    descripcion: it.descripcion,
    cantidad: Number(it.cantidad),
    maximo: Number(it.cantidad),
    precio_unitario: Number(it.precio_unitario),
    descuento: Number(it.descuento ?? 0),
    tarifa: it.tarifa ?? 15,
    seriesVendidas: [] as string[],
    series: [] as string[],
  }))
  if (!form.value.motivo) form.value.motivo = 'Corrección de la factura ' + f?.numero
  await cargarSeriesVendidas(seq)
}
/** Las series que esta factura vendió y siguen vendidas, agrupadas por producto, para elegir las que regresan. */
async function cargarSeriesVendidas(seq: number) {
  if (!form.value.invoice_id || !plan.tiene('series')) return
  try {
    const { data } = await api.get('/series', { params: { company_id: company.activeId, invoice_id: form.value.invoice_id, estado: 'vendida' } })
    if (seq !== seqFactura) return           // se eligió otra factura mientras llegaba la respuesta
    const porProducto: Record<string, string[]> = {}
    for (const s of data as any[]) (porProducto[s.product?.codigo] ??= []).push(s.serie)
    for (const l of form.value.lineas) {
      l.seriesVendidas = porProducto[l.codigo_principal] ?? []
      ajustarSeries(l, l.cantidad)
    }
  } catch {
    // Sin la lista no se ofrece el selector; el servidor valida igual y responde con las series vendidas si falta alguna
  }
}
async function guardar() {
  error.value = ''
  saving.value = true
  try {
    const { data } = await api.post('/credit-notes', {
      company_id: company.activeId,
      contact_id: form.value.contact_id,
      invoice_id: form.value.invoice_id,
      tipo: form.value.interna ? 'interna' : 'sri',
      motivo: form.value.motivo,
      devuelve_stock: form.value.devuelve_stock,
      items: form.value.lineas.map((l: any) => ({
        codigo_principal: l.codigo_principal, descripcion: l.descripcion, cantidad: l.cantidad,
        precio_unitario: l.precio_unitario, descuento: descuentoLinea(l), tarifa: l.tarifa,
        ...(form.value.devuelve_stock && llevaSeries(l) ? { series: l.series } : {}),
      })),
    })
    dialog.value = false
    msg.value = `Nota de crédito guardada. La factura queda con saldo ${money(data.saldo_factura)}.`
    msgOk.value = true
    await load()
  } catch (err: any) {
    error.value = mensajeDeError(err, 'No se pudo guardar la nota de crédito.')
  } finally {
    saving.value = false
  }
}
async function emitir(r: any) {
  emitiendo.value = r.id
  msg.value = ''
  try {
    const { data } = await api.post(`/credit-notes/${r.id}/emit`)
    msg.value = data.mensaje ?? 'Nota de crédito emitida.'
    msgOk.value = true
    await load()
  } catch (err: any) {
    msg.value = mensajeDeError(err, 'No se pudo emitir la nota de crédito.')
    msgOk.value = false
  } finally {
    emitiendo.value = null
  }
}
function pedirAnular(r: any) { confirmaSri.value = false; anularTarget.value = r }
async function confirmarAnular() {
  if (!anularTarget.value || anularBusy.value) return
  if (esAutorizada(anularTarget.value) && !confirmaSri.value) return
  anularBusy.value = true
  try {
    const { data } = await api.post(`/credit-notes/${anularTarget.value.id}/anular`)
    msg.value = `Nota de crédito anulada. La factura vuelve a tener saldo ${money(data.saldo_factura)}.`
    msgOk.value = true
    // El servidor dice si hace falta anularla también en el SRI: la pantalla no lo adivina
    avisoAnulacion.value = { texto: data.aviso_sri ?? '', requiere: !!data.requiere_anulacion_sri }
    anularTarget.value = null
    await load()
  } catch (err: any) {
    msg.value = mensajeDeError(err, 'No se pudo anular la nota de crédito.')
    msgOk.value = false
    anularTarget.value = null
  } finally {
    anularBusy.value = false
  }
}
const estadoSri = (r: any) => r.sri_document?.estado ?? null

// RIDE en PDF (lo arma el servidor con los datos del emisor y de la autorización). Una nota interna no tiene comprobante del SRI.
const rideBusy = ref<number | null>(null)
async function descargarRide(r: any) {
  if (r.interna || rideBusy.value !== null) return
  rideBusy.value = r.id
  try {
    const res = await api.get(`/credit-notes/${r.id}/ride`, { params: { company_id: company.activeId }, responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    a.download = 'RIDE-' + (r.numero ?? r.id) + '.pdf'
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    msg.value = 'No se pudo generar el RIDE en PDF. Inténtalo de nuevo.'
    msgOk.value = false
  } finally {
    rideBusy.value = null
  }
}

// Enviar la nota por correo (RIDE en PDF + XML). Una nota interna no se envía: no es un comprobante del SRI.
const correoVisible = ref(false)
const correoDe = ref<any>(null)
function enviarCorreo(r: any) {
  correoDe.value = r
  correoVisible.value = true
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Notas de crédito</h2>
      <Button label="Nueva nota" icon="pi pi-plus" @click="abrir" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Toda nota de crédito corrige una <b>factura</b>: al emitirla, el saldo de esa factura baja de inmediato.
      La nota <b>SRI</b> es un comprobante electrónico; la <b>interna</b> no se envía al SRI y solo regula los saldos contables.
    </Message>
    <Message v-if="msg" :severity="msgOk ? 'success' : 'error'" :closable="true" style="margin-bottom:14px;" @close="msg = ''">{{ msg }}</Message>
    <Message v-if="avisoAnulacion && avisoAnulacion.texto" :severity="avisoAnulacion.requiere ? 'warn' : 'info'" :closable="true"
             style="margin-bottom:14px;" @close="avisoAnulacion = null">{{ avisoAnulacion.texto }}</Message>
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
      <span style="font-size:12px; color:#64748b;">Mostrar:</span>
      <Select v-model="filtroEstado" :options="estados" optionLabel="label" optionValue="value" size="small" style="width:130px"
              aria-label="Estado de la nota" />
      <span style="font-size:12px; color:#94a3b8;">{{ filas.length }} de {{ rows.length }} notas</span>
    </div>
    <DataTable :value="filas" :loading="loading" size="small" stripedRows>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
      <Column field="numero" header="Número"><template #body="{ data }">{{ data.numero ?? '—' }}</template></Column>
      <Column header="Clase"><template #body="{ data }">
        <Tag v-if="data.tipo === 'anulado'" value="Anulada" severity="danger" />
        <Tag v-else-if="data.interna" value="Interna" severity="secondary" />
        <Tag v-else value="SRI" severity="info" />
        <Tag v-if="data.tipo === 'anulado' && data.interna" value="Interna" severity="secondary" style="margin-left:4px;" />
      </template></Column>
      <Column header="Factura"><template #body="{ data }">{{ data.invoice?.numero ?? '—' }}</template></Column>
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column field="motivo" header="Motivo" />
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Saldo a favor"><template #body="{ data }"><b>{{ money(data.saldo_disponible) }}</b></template></Column>
      <Column header="SRI"><template #body="{ data }">
        <span v-if="data.interna" style="color:#94a3b8;">No aplica</span>
        <Tag v-else-if="estadoSri(data)" :value="estadoSri(data)" severity="success" />
        <span v-else style="color:#94a3b8;">Sin emitir</span>
      </template></Column>
      <Column header="" style="width:340px"><template #body="{ data }">
        <Button v-if="data.tipo === 'sri' && !data.sri_document" label="Emitir" icon="pi pi-send" size="small"
                :loading="emitiendo === data.id" @click="emitir(data)" />
        <!-- El span lleva el aviso: un botón deshabilitado no recibe el ratón -->
        <span :title="data.interna ? 'Una nota interna no se envía al SRI, por eso no tiene RIDE.' : 'Descargar RIDE (PDF)'">
          <Button icon="pi pi-file-pdf" size="small" text aria-label="Descargar RIDE (PDF)" label="RIDE"
                  :loading="rideBusy === data.id" :disabled="data.interna" @click="descargarRide(data)" />
        </span>
        <!-- Solo la nota del SRI ya emitida se envía por correo; el span lleva el aviso porque un botón deshabilitado no recibe el ratón -->
        <span v-if="data.tipo !== 'anulado'" :title="data.interna ? 'Las notas internas no se envían por correo.' : (data.sri_document ? 'Enviar la nota por correo al cliente (RIDE y XML)' : 'Emite la nota para poder enviarla por correo.')">
          <Button icon="pi pi-envelope" size="small" text aria-label="Enviar por correo" label="Correo" data-test="fila-enviar-correo"
                  :disabled="data.interna || !data.sri_document" @click="enviarCorreo(data)" />
        </span>
        <Button v-if="data.tipo !== 'anulado'" label="Anular" icon="pi pi-ban" size="small" severity="danger" text
                @click="pedirAnular(data)" />
      </template></Column>
      <template #empty>Todavía no hay notas de crédito.</template>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Nueva nota de crédito" :style="{ width: pideSeries ? '980px' : '720px' }">
      <Message v-if="error" severity="error" :closable="false" style="margin-bottom:10px;">{{ error }}</Message>
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Factura que corriges</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Cliente:</label>
          <Select v-model="form.contact_id" :options="clientes" optionLabel="razon_social" optionValue="id" filter
                  placeholder="Elige el cliente" class="kvs-in" @change="alElegirCliente" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Factura:</label>
          <Select v-model="form.invoice_id" :options="facturasDelCliente" optionLabel="etiqueta" optionValue="id" filter
                  :disabled="!form.contact_id" placeholder="Elige la factura que corriges" class="kvs-in"
                  emptyMessage="Este cliente no tiene facturas vigentes" @change="alElegirFactura" />
        </div>
        <div v-if="facturaElegida" style="font-size:12px; color:#64748b; margin:-2px 0 6px 0;">
          Factura {{ facturaElegida.numero }}: total {{ money(facturaElegida.importe_total) }}, saldo actual {{ money(facturaElegida.saldo_pendiente) }}.
        </div>
      </fieldset>

      <fieldset class="kvs-fieldset" style="margin-top:10px;">
        <legend>Datos de la nota</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Interna (no se envía al SRI):</label>
          <ToggleSwitch v-model="form.interna" :disabled="sinSri" />
          <span style="font-size:12px; color:#64748b;">
            <template v-if="sinSri">Tu plan no incluye la facturación electrónica: la nota es interna, solo regula saldos.</template>
            <template v-else>{{ form.interna ? 'Solo regula saldos: sin comprobante electrónico ni secuencial del SRI.' : 'Comprobante electrónico: se emite al SRI desde la lista.' }}</template>
          </span>
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Motivo:</label>
          <InputText v-model="form.motivo" placeholder="Devolución de mercadería / descuento" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Devuelve mercadería:</label>
          <ToggleSwitch v-model="form.devuelve_stock" />
          <span style="font-size:12px; color:#64748b;">Actívalo solo si el cliente devolvió los productos: vuelven al inventario.</span>
        </div>
      </fieldset>

      <fieldset v-if="form.lineas.length" class="kvs-fieldset" style="margin-top:10px;">
        <legend>Líneas que se acreditan</legend>
        <DataTable :value="form.lineas" size="small">
          <Column field="descripcion" header="Descripción" />
          <Column header="Cantidad" style="width:120px"><template #body="{ data }">
            <InputNumber v-model="data.cantidad" :min="0.01" :max="data.maximo" :maxFractionDigits="4" style="width:100px"
                         @update:modelValue="(v: number) => ajustarSeries(data, v)" />
          </template></Column>
          <Column v-if="pideSeries" header="Series que regresan" style="width:270px"><template #body="{ data }">
            <template v-if="llevaSeries(data)">
              <MultiSelect v-model="data.series" :options="opcionesDe(data)" filter display="chip" :maxSelectedLabels="2"
                           :selectionLimit="Math.floor(data.cantidad || 0)" placeholder="Elige las series" style="width:250px"
                           :aria-label="'Series que regresan de ' + data.descripcion" />
              <div style="font-size:11px; margin-top:2px;" :style="{ color: seriesOk(data) ? '#166534' : '#b45309' }">
                {{ (data.series ?? []).length }} de {{ data.cantidad }} serie(s)
              </div>
            </template>
            <span v-else style="color:#94a3b8; font-size:12px;">Sin series</span>
          </template></Column>
          <Column header="Precio" style="width:120px"><template #body="{ data }">
            <InputNumber v-model="data.precio_unitario" :min="0" :minFractionDigits="2" :maxFractionDigits="4" style="width:100px" />
          </template></Column>
          <Column header="IVA" style="width:70px"><template #body="{ data }">{{ data.tarifa }}%</template></Column>
          <Column header="Subtotal" style="width:90px"><template #body="{ data }">{{ money(subtotalLinea(data)) }}</template></Column>
          <Column header="" style="width:50px"><template #body="{ index }">
            <Button icon="pi pi-trash" text severity="danger" size="small" @click="form.lineas.splice(index, 1)" />
          </template></Column>
        </DataTable>
        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:2px; margin-top:10px; font-size:13px;">
          <div>Subtotal: <b>{{ money(subtotal) }}</b></div>
          <div>IVA: <b>{{ money(iva) }}</b></div>
          <div style="font-size:15px;">Total de la nota: <b>{{ money(total) }}</b></div>
        </div>
        <Message v-if="pideSeries && !seriesCompletas" severity="warn" :closable="false" style="margin-top:10px;">
          Los productos con series necesitan que elijas <b>exactamente</b> las series que regresan: una por cada unidad devuelta.
        </Message>
        <Message v-if="total > 0" severity="info" :closable="false" style="margin-top:10px;">
          La factura {{ facturaElegida?.numero }} debe hoy {{ money(saldoFactura) }} y quedará debiendo <b>{{ money(saldoDespues) }}</b>.
          <span v-if="sobra > 0"> Sobran <b>{{ money(sobra) }}</b> a favor del cliente, que se pueden usar en otra factura desde Cuentas por cobrar.</span>
        </Message>
      </fieldset>

      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="dialog=false" />
          <Button label="Guardar" :loading="saving" :disabled="!puedeGuardar" @click="guardar" />
        </div>
      </template>
    </Dialog>

    <Dialog :visible="!!anularTarget" modal header="Anular nota de crédito" style="width:440px" @update:visible="anularTarget = null">
      <div v-if="anularTarget">
        <p style="margin:0 0 10px; font-size:13px;">
          ¿Anular la nota <b>{{ anularTarget.numero ?? '#' + anularTarget.id }}</b> por <b>{{ money(anularTarget.importe_total) }}</b>?
        </p>
        <p style="margin:0; font-size:12px; color:#64748b;">
          El documento no se borra: queda anulado, se revierte su asiento contable, la factura vuelve a deber lo que la nota le había quitado
          y la mercadería devuelta sale del inventario.
        </p>
        <Message v-if="esAutorizada(anularTarget)" severity="warn" :closable="false" style="margin-top:12px;">
          El SRI ya autorizó esta nota de crédito. Anularla aquí <b>no la anula en el SRI</b>: después tendrás que anularla también en el portal SRI en línea;
          mientras no lo hagas, el SRI la seguirá considerando válida.
        </Message>
        <label v-if="esAutorizada(anularTarget)" style="display:flex; gap:8px; align-items:center; margin-top:10px; font-size:12.5px; cursor:pointer;">
          <Checkbox v-model="confirmaSri" binary input-id="confirma-sri-nc" />
          <span>Entiendo que también debo anularla en el portal SRI en línea.</span>
        </label>
      </div>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="anularTarget = null" />
          <Button label="Anular nota" icon="pi pi-ban" severity="danger" :loading="anularBusy"
                  :disabled="esAutorizada(anularTarget) && !confirmaSri" @click="confirmarAnular" />
        </div>
      </template>
    </Dialog>

    <EnviarCorreoDialog v-if="correoDe" v-model:visible="correoVisible" tipo="nota_credito" :comprobanteId="correoDe.id"
                        :etiqueta="'Nota de crédito ' + (correoDe.numero ?? '')" />
  </div>
</template>
