<template>
  <div style="padding: 20px">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
      <h3 style="margin: 0">Notas de Débito</h3>
      <Button label="Nueva Nota de Débito" icon="pi pi-plus" @click="openNew" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom: 14px">
      Toda nota de débito (interés, mora, cargo extra) se carga a una <b>factura</b>: su saldo sube y queda el asiento contable.
      La nota <b>SRI</b> es un comprobante electrónico; la <b>interna</b> no se envía al SRI y solo regula los saldos.
    </Message>
    <Message v-if="msg" :severity="msgOk ? 'success' : 'error'" :closable="true" style="margin-bottom: 12px" @close="msg = ''">{{ msg }}</Message>
    <Message v-if="avisoAnulacion && avisoAnulacion.texto" :severity="avisoAnulacion.requiere ? 'warn' : 'info'" :closable="true"
             style="margin-bottom: 12px" @close="avisoAnulacion = null">{{ avisoAnulacion.texto }}</Message>
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px">
      <span style="font-size: 12px; color: #64748b">Mostrar:</span>
      <Select v-model="filtroEstado" :options="estados" optionLabel="label" optionValue="value" size="small" style="width: 130px"
              aria-label="Estado de la nota" />
      <span style="font-size: 12px; color: #94a3b8">{{ filas.length }} de {{ rows.length }} notas</span>
    </div>

    <DataTable :value="filas" :loading="loading" stripedRows size="small">
      <Column field="numero" header="Número" />
      <Column header="Clase">
        <template #body="{ data }">
          <Tag v-if="data.estado === 'anulado'" value="Anulada" severity="danger" />
          <Tag v-else-if="data.interna" value="Interna" severity="secondary" />
          <Tag v-else value="SRI" severity="info" />
          <Tag v-if="data.estado === 'anulado' && data.interna" value="Interna" severity="secondary" style="margin-left: 4px" />
        </template>
      </Column>
      <Column field="numero_referencia" header="Factura" />
      <Column field="contact.razon_social" header="Cliente" />
      <Column header="Fecha">
        <template #body="{ data }">{{ String(data.fecha_emision ?? '').slice(0, 10) }}</template>
      </Column>
      <Column field="importe_total" header="Total">
        <template #body="{ data }">$ {{ Number(data.importe_total).toFixed(2) }}</template>
      </Column>
      <Column header="SRI">
        <template #body="{ data }">
          <span v-if="data.interna" style="color: #94a3b8">No aplica</span>
          <Tag v-else-if="data.sri_document?.estado" :value="data.sri_document.estado" severity="success" />
          <span v-else style="color: #94a3b8">Sin emitir</span>
        </template>
      </Column>
      <Column header="Acciones" style="width: 200px">
        <template #body="{ data }">
          <Button v-if="!data.interna && data.estado !== 'anulado' && !data.sri_document" icon="pi pi-send" size="small" label="Emitir"
                  @click="emitir(data)" :loading="emitting === data.id" />
          <Button v-if="data.estado !== 'anulado'" icon="pi pi-ban" size="small" label="Anular" severity="danger" text
                  @click="pedirAnular(data)" />
        </template>
      </Column>
      <template #empty>Todavía no hay notas de débito.</template>
    </DataTable>

    <Dialog v-model:visible="dialog" header="Nueva Nota de Débito" modal style="width: 650px">
      <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 10px">{{ error }}</Message>
      <div class="kvs-fieldset">
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Factura a la que se carga</label>
          <Select v-model="form.invoice_id" :options="facturasVigentes" optionLabel="etiqueta" optionValue="id" filter
                  class="kvs-in" placeholder="Elige la factura" emptyMessage="No hay facturas vigentes" />
        </div>
        <div v-if="facturaElegida" style="font-size: 12px; color: #64748b; margin: -2px 0 6px 0">
          Factura {{ facturaElegida.numero }}: total {{ money(facturaElegida.importe_total) }}, saldo actual {{ money(facturaElegida.saldo_pendiente) }}.
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Interna (no se envía al SRI)</label>
          <ToggleSwitch v-model="form.interna" />
          <span style="font-size: 12px; color: #64748b">
            {{ form.interna ? 'Solo regula saldos: sin comprobante electrónico ni secuencial del SRI.' : 'Comprobante electrónico: se emite al SRI desde la lista.' }}
          </span>
        </div>

        <h4 style="margin-top: 16px">Motivos del débito</h4>
        <div v-for="(mot, i) in form.motivos" :key="i" class="kvs-row" style="gap: 8px; align-items: end">
          <div style="flex: 2">
            <label class="kvs-lbl">Razón</label>
            <InputText v-model="mot.razon" class="kvs-in" placeholder="Ej: Interés por mora" />
          </div>
          <div style="flex: 1">
            <label class="kvs-lbl">Valor</label>
            <InputNumber v-model="mot.valor" class="kvs-in" :minFractionDigits="2" />
          </div>
          <div style="width: 90px">
            <label class="kvs-lbl">IVA</label>
            <Select v-model="mot.tarifa" :options="tarifasIva" optionLabel="label" optionValue="value" class="kvs-in" />
          </div>
          <Button icon="pi pi-trash" text severity="danger" @click="form.motivos.splice(i, 1)" :disabled="form.motivos.length <= 1" />
        </div>
        <Button label="Agregar motivo" icon="pi pi-plus" text size="small" @click="form.motivos.push({ razon: '', valor: 0, tarifa: 0 })" style="margin-top: 8px" />

        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 2px; margin-top: 12px; font-size: 13px">
          <div>Subtotal: <b>{{ money(subtotal) }}</b></div>
          <div>IVA: <b>{{ money(iva) }}</b></div>
          <div style="font-size: 15px">Total de la nota: <b>{{ money(totalMotivos) }}</b></div>
        </div>
        <Message v-if="facturaElegida && totalMotivos > 0" severity="info" :closable="false" style="margin-top: 10px">
          La factura {{ facturaElegida.numero }} debe hoy {{ money(facturaElegida.saldo_pendiente) }} y pasará a deber
          <b>{{ money(Number(facturaElegida.saldo_pendiente) + totalMotivos) }}</b>.
        </Message>
        <div v-if="!form.interna" class="kvs-row" style="margin-top: 12px">
          <label class="kvs-lbl">Forma de Pago</label>
          <Select v-model="form.forma_pago" :options="formasPago" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog = false" />
        <Button label="Guardar" @click="guardar" :loading="saving" :disabled="!puedeGuardar" />
      </template>
    </Dialog>

    <Dialog :visible="!!anularTarget" modal header="Anular nota de débito" style="width: 440px" @update:visible="anularTarget = null">
      <div v-if="anularTarget">
        <p style="margin: 0 0 10px; font-size: 13px">
          ¿Anular la nota <b>{{ anularTarget.numero }}</b> por <b>{{ money(anularTarget.importe_total) }}</b>?
        </p>
        <p style="margin: 0; font-size: 12px; color: #64748b">
          El documento no se borra: queda anulado, se revierte su asiento contable y el saldo de la factura baja lo que la nota le había subido.
        </p>
        <Message v-if="esAutorizada(anularTarget)" severity="warn" :closable="false" style="margin-top: 12px">
          El SRI ya autorizó esta nota de débito. Anularla aquí <b>no la anula en el SRI</b>: después tendrás que anularla también en el portal SRI en línea;
          mientras no lo hagas, el SRI la seguirá considerando válida.
        </Message>
        <label v-if="esAutorizada(anularTarget)" style="display: flex; gap: 8px; align-items: center; margin-top: 10px; font-size: 12.5px; cursor: pointer">
          <Checkbox v-model="confirmaSri" binary input-id="confirma-sri-nd" />
          <span>Entiendo que también debo anularla en el portal SRI en línea.</span>
        </label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="anularTarget = null" />
        <Button label="Anular nota" icon="pi pi-ban" severity="danger" :loading="anularBusy"
                :disabled="esAutorizada(anularTarget) && !confirmaSri" @click="confirmarAnular" />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import ToggleSwitch from 'primevue/toggleswitch'
import Checkbox from 'primevue/checkbox'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const invoices = ref<any[]>([])
const loading = ref(false)
const dialog = ref(false)
const saving = ref(false)
const emitting = ref<number | null>(null)
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
const msg = ref('')
const msgOk = ref(true)
const error = ref('')

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const esAutorizada = (r: any) => String(r?.sri_document?.estado ?? '').toUpperCase() === 'AUTORIZADO'
const filas = computed(() => rows.value.filter((r: any) =>
  !filtroEstado.value || (filtroEstado.value === 'anuladas' ? r.estado === 'anulado' : r.estado !== 'anulado')))
const redondear = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100

const formasPago = [
  { label: 'Efectivo', value: '01' },
  { label: 'Transferencia', value: '20' },
  { label: 'Tarjeta de crédito', value: '19' },
]
// Los intereses y la mora van a 0%; un cargo administrativo puede llevar IVA
const tarifasIva = [
  { label: '0%', value: 0 },
  { label: '15%', value: 15 },
]

const nuevoForm = () => ({
  invoice_id: null as number | null,
  interna: false,
  motivos: [{ razon: '', valor: 0, tarifa: 0 }],
  forma_pago: '01',
})
const form = ref<any>(nuevoForm())

// Solo facturas vigentes; la etiqueta deja buscar por número, cliente o fecha
const facturasVigentes = computed(() => invoices.value
  .filter((f: any) => f.estado !== 'anulado')
  .map((f: any) => ({
    ...f,
    etiqueta: `${f.numero} · ${f.contact?.razon_social ?? ''} · ${String(f.fecha_emision ?? '').slice(0, 10)} · saldo ${money(f.saldo_pendiente)}`,
  })))
const facturaElegida = computed(() => facturasVigentes.value.find((f: any) => f.id === form.value.invoice_id) ?? null)

const subtotal = computed(() => redondear(form.value.motivos.reduce((s: number, m: any) => s + (m.valor || 0), 0)))
const iva = computed(() => redondear(form.value.motivos.reduce((s: number, m: any) => s + redondear((m.valor || 0) * (m.tarifa || 0) / 100), 0)))
const totalMotivos = computed(() => redondear(subtotal.value + iva.value))
const puedeGuardar = computed(() => !!form.value.invoice_id && totalMotivos.value > 0
  && form.value.motivos.every((m: any) => String(m.razon).trim() !== '' && m.valor > 0))

function mensajeDeError(e: any, porDefecto: string) {
  const errs = e?.response?.data?.errors
  return errs ? Object.values(errs).flat().join(' · ') : (e?.response?.data?.message ?? porDefecto)
}

function openNew() {
  form.value = nuevoForm()
  error.value = ''
  dialog.value = true
  loadInvoices()
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/notas-debito?company_id=' + company.activeId)
    rows.value = data
  } finally {
    loading.value = false
  }
}

async function loadInvoices() {
  const { data } = await api.get('/invoices?company_id=' + company.activeId)
  invoices.value = data
}

async function guardar() {
  if (!puedeGuardar.value) return
  saving.value = true
  msg.value = ''
  error.value = ''
  try {
    const { data } = await api.post('/notas-debito', {
      company_id: company.activeId,
      invoice_id: form.value.invoice_id,
      tipo: form.value.interna ? 'interna' : 'sri',
      motivos: form.value.motivos,
      // La forma de pago solo va en el comprobante del SRI
      forma_pago: form.value.interna ? null : form.value.forma_pago,
    })
    dialog.value = false
    msg.value = `Nota de débito guardada. La factura queda con saldo ${money(data.saldo_factura)}.`
    msgOk.value = true
    await load()
  } catch (e: any) {
    error.value = mensajeDeError(e, 'No se pudo guardar la nota de débito.')
  } finally {
    saving.value = false
  }
}

async function emitir(row: any) {
  emitting.value = row.id
  msg.value = ''
  try {
    const { data } = await api.post(`/notas-debito/${row.id}/emit`)
    msg.value = data.mensaje || 'Emitida'
    msgOk.value = true
    await load()
  } catch (e: any) {
    msg.value = mensajeDeError(e, 'No se pudo emitir la nota de débito.')
    msgOk.value = false
  } finally {
    emitting.value = null
  }
}

function pedirAnular(r: any) { confirmaSri.value = false; anularTarget.value = r }
async function confirmarAnular() {
  if (!anularTarget.value || anularBusy.value) return
  if (esAutorizada(anularTarget.value) && !confirmaSri.value) return
  anularBusy.value = true
  try {
    const { data } = await api.post(`/notas-debito/${anularTarget.value.id}/anular`)
    msg.value = data.mensaje || 'Nota de débito anulada.'
    msgOk.value = true
    // El servidor dice si hace falta anularla también en el SRI: la pantalla no lo adivina
    avisoAnulacion.value = { texto: data.aviso_sri ?? '', requiere: !!data.requiere_anulacion_sri }
    anularTarget.value = null
    await load()
  } catch (e: any) {
    msg.value = mensajeDeError(e, 'No se pudo anular la nota de débito.')
    msgOk.value = false
    anularTarget.value = null
  } finally {
    anularBusy.value = false
  }
}

onMounted(() => { load() })
</script>
