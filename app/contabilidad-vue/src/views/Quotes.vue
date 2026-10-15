<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import Checkbox from 'primevue/checkbox'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const contacts = ref<any[]>([])
const productos = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const guardando = ref(false)
const msg = ref<any>(null)
const detalle = ref<any>(null)
const exito = ref('')

const form = ref<any>({ contact_id: null, items: [] })

// ── Varias cotizaciones → UNA factura ──
const seleccion = ref<number[]>([])
const unir = ref(false)
const unirCargando = ref(false)
const unirEmitiendo = ref(false)
const unirMsg = ref<any>(null)
const vista = ref<any>(null)
const unirContacto = ref<number | null>(null)
const unirForma = ref('efectivo')
const formasPago = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Transferencia', value: 'transferencia' },
  { label: 'Tarjeta', value: 'tarjeta' },
  { label: 'Crédito', value: 'credito' },
]
const pendientes = computed(() => rows.value.filter((q: any) => q.estado === 'pendiente'))
const todasSeleccionadas = computed(() => pendientes.value.length > 0 && pendientes.value.every((q: any) => seleccion.value.includes(q.id)))
function alternar(id: number) {
  seleccion.value = seleccion.value.includes(id) ? seleccion.value.filter((x) => x !== id) : [...seleccion.value, id]
}
function alternarTodas() {
  seleccion.value = todasSeleccionadas.value ? [] : pendientes.value.map((q: any) => q.id)
}

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

const base = computed(() => form.value.items.reduce(
  (s: number, i: any) => s + (i.cantidad ?? 0) * (i.precio_unitario ?? 0), 0))
const iva = computed(() => form.value.items.reduce(
  (s: number, i: any) => s + (i.cantidad ?? 0) * (i.precio_unitario ?? 0) * ((i.tarifa ?? 0) / 100), 0))
const total = computed(() => base.value + iva.value)

async function load() {
  loading.value = true
  const [q, c, p] = await Promise.all([
    api.get('/quotes?company_id=' + company.activeId),
    api.get('/contacts?company_id=' + company.activeId),
    api.get('/products?company_id=' + company.activeId),
  ])
  rows.value = q.data
  // Lo que ya no está pendiente (se facturó o se borró) sale de la selección
  seleccion.value = seleccion.value.filter((id) => rows.value.some((r: any) => r.id === id && r.estado === 'pendiente'))
  contacts.value = c.data.filter((x: any) => x.es_cliente)
  productos.value = p.data
  loading.value = false
}
function nueva() {
  form.value = { contact_id: contacts.value[0]?.id ?? null, items: [] }
  agregarItem()
  msg.value = null
  dialog.value = true
}
function agregarItem() {
  form.value.items.push({ codigo_principal: '', descripcion: '', cantidad: 1, precio_unitario: 0, tarifa: 15 })
}
function quitarItem(i: number) { form.value.items.splice(i, 1) }
// Al elegir el código se completan descripción, precio e IVA del producto
function elegirProducto(fila: any, codigo: string) {
  const p = productos.value.find((x: any) => x.codigo === codigo)
  if (!p) return
  fila.descripcion = p.descripcion
  fila.precio_unitario = Number(p.precio) || 0
  fila.tarifa = Number(p.tarifa_iva) || 15
}
function editar(q: any) {
  form.value = {
    id: q.id,
    contact_id: q.contact_id,
    items: (q.items ?? []).map((i: any) => ({ ...i })),
  }
  msg.value = null
  dialog.value = true
}

async function guardar() {
  msg.value = null
  const items = form.value.items.filter((i: any) => i.codigo_principal && i.cantidad > 0)
  if (!form.value.contact_id) { msg.value = { type: 'error', text: 'Elige el cliente.' }; return }
  if (!items.length) { msg.value = { type: 'error', text: 'Agrega al menos un artículo.' }; return }
  guardando.value = true
  try {
    if (form.value.id) {
      await api.put('/quotes/' + form.value.id, { contact_id: form.value.contact_id, items })
    } else {
      await api.post('/quotes', {
        company_id: company.activeId,
        contact_id: form.value.contact_id,
        items,
      })
    }
    dialog.value = false
    load()
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
  } finally { guardando.value = false }
}
async function convertir(q: any) {
  const forma = prompt('Forma de pago para la factura (efectivo, transferencia, tarjeta, credito):', 'efectivo')
  if (!forma) return
  try {
    const res = await api.post('/quotes/' + q.id + '/convert', { forma_pago: forma })
    alert('Factura ' + res.data.invoice.numero + ' emitida.')
    load()
  } catch (err: any) {
    alert(err.response?.data?.message ?? 'No se pudo convertir.')
  }
}
function mensajeDe(err: any, porDefecto: string) {
  const e = err.response?.data?.errors
  return err.response?.data?.message ?? (e ? Object.values(e).flat().join(' · ') : porDefecto)
}

// Abre el diálogo: el servidor une las líneas (mismo artículo y precio suman cantidad) y dice el cliente por defecto
async function abrirUnir() {
  if (!seleccion.value.length) return
  unirMsg.value = null
  exito.value = ''
  vista.value = null
  unirContacto.value = null
  unirForma.value = 'efectivo'
  unir.value = true
  unirCargando.value = true
  try {
    const res = await api.post('/quotes/merge-preview', { company_id: company.activeId, quote_ids: seleccion.value })
    vista.value = res.data
    unirContacto.value = res.data.contact_id ?? null
    if (!res.data.contact_id) {
      unirMsg.value = { type: 'warn', text: 'Las cotizaciones son de clientes distintos: elige a quién se factura.' }
    }
  } catch (err: any) {
    unirMsg.value = { type: 'error', text: mensajeDe(err, 'No se pudieron unir las cotizaciones.') }
  } finally {
    unirCargando.value = false
  }
}

async function emitirUnidas() {
  if (!vista.value || unirEmitiendo.value) return
  if (!unirContacto.value) { unirMsg.value = { type: 'error', text: 'Elige el cliente al que se factura.' }; return }
  unirEmitiendo.value = true
  unirMsg.value = null
  try {
    const res = await api.post('/quotes/convert-many', {
      company_id: company.activeId,
      quote_ids: seleccion.value,
      contact_id: unirContacto.value,
      forma_pago: unirForma.value,
    })
    unir.value = false
    seleccion.value = []
    exito.value = 'Factura ' + res.data.invoice.numero + ' emitida con ' + res.data.cotizaciones + ' cotizaciones.'
    load()
  } catch (err: any) {
    unirMsg.value = { type: 'error', text: mensajeDe(err, 'No se pudo emitir la factura.') }
  } finally {
    unirEmitiendo.value = false
  }
}

async function eliminar(q: any) {
  if (!confirm('¿Eliminar la cotización #' + q.id + '?')) return
  try {
    await api.delete('/quotes/' + q.id)
    load()
  } catch (err: any) {
    alert(err.response?.data?.message ?? 'No se pudo eliminar.')
  }
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
      <h2 style="margin:0;">Cotizaciones</h2>
      <Button label="Nueva cotización" icon="pi pi-plus" @click="nueva" />
    </div>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">
      Créala aquí o desde el Punto de Venta con "Guardar cotización". Si el cliente aprueba,
      se convierte en factura con un clic.
    </p>

    <Message v-if="exito" severity="success" :closable="true" style="margin-bottom:12px;" @close="exito = ''">{{ exito }}</Message>

    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
      <Button label="Convertir seleccionadas en una factura" icon="pi pi-copy" size="small"
              :disabled="!seleccion.length" @click="abrirUnir" />
      <span style="font-size:12.5px; color:#64748b;">
        {{ seleccion.length ? seleccion.length + ' seleccionada(s)' : 'Marca las cotizaciones pendientes que quieres facturar juntas.' }}
      </span>
    </div>

    <DataTable :value="rows" :loading="loading" size="small" stripedRows dataKey="id">
      <Column style="width:44px">
        <template #header>
          <Checkbox :modelValue="todasSeleccionadas" binary :disabled="!pendientes.length"
                    aria-label="Seleccionar todas las pendientes" @update:modelValue="alternarTodas" />
        </template>
        <template #body="{ data }">
          <Checkbox v-if="data.estado === 'pendiente'" :modelValue="seleccion.includes(data.id)" binary
                    :aria-label="'Seleccionar la cotización #' + data.id" @update:modelValue="alternar(data.id)" />
        </template>
      </Column>
      <Column field="id" header="#" style="width:70px" />
      <Column header="Fecha"><template #body="{ data }">{{ String(data.created_at ?? '').slice(0,10) }}</template></Column>
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Estado">
        <template #body="{ data }">
          <Tag :value="data.estado" :severity="data.estado === 'facturada' ? 'success' : 'warn'" />
          <span v-if="data.invoice?.numero" style="font-size:11px; color:#64748b; margin-left:6px;">{{ data.invoice.numero }}</span>
        </template>
      </Column>
      <Column header="" style="width:280px">
        <template #body="{ data }">
          <Button icon="pi pi-eye" text size="small" title="Ver detalle" @click="detalle = data" />
          <Button v-if="data.estado === 'pendiente'" label="Convertir en factura" size="small"
                  @click="convertir(data)" />
          <Button v-if="data.estado === 'pendiente'" icon="pi pi-pencil" text size="small"
                  title="Editar" @click="editar(data)" />
          <Button v-if="data.estado === 'pendiente'" icon="pi pi-trash" text size="small"
                  severity="danger" title="Eliminar" @click="eliminar(data)" />
        </template>
      </Column>
      <template #empty>
        <div style="text-align:center; color:#94a3b8; padding:20px;">
          Sin cotizaciones. Toca <b>Nueva cotización</b> para crear la primera.
        </div>
      </template>
    </DataTable>

    <!-- Alta de cotización -->
    <Dialog v-model:visible="dialog" modal :header="form.id ? 'Editar cotización #' + form.id : 'Nueva cotización'" style="width:820px">
      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:12px;">{{ msg.text }}</Message>

      <div class="kvs-row" style="margin-bottom:14px;">
        <label class="kvs-lbl" style="min-width:60px;"><span class="req-star">*</span> Cliente:</label>
        <Select v-model="form.contact_id" :options="contacts" optionValue="id"
                :optionLabel="(c) => c.identificacion + ' — ' + c.razon_social"
                filter placeholder="Elige el cliente" class="kvs-in" />
      </div>

      <DataTable :value="form.items" size="small">
        <Column header="Código" style="width:200px">
          <template #body="{ data }">
            <Select v-model="data.codigo_principal" :options="productos" optionValue="codigo"
                    :optionLabel="(p) => p.codigo + ' — ' + p.descripcion" filter
                    placeholder="Artículo" style="width:100%"
                    @update:modelValue="(v) => elegirProducto(data, v)" />
          </template>
        </Column>
        <Column header="Descripción">
          <template #body="{ data }"><InputText v-model="data.descripcion" style="width:100%" /></template>
        </Column>
        <Column header="Cant." style="width:90px">
          <template #body="{ data }"><InputNumber v-model="data.cantidad" :useGrouping="false" fluid /></template>
        </Column>
        <Column header="P. Unit." style="width:130px">
          <template #body="{ data }">
            <InputNumber v-model="data.precio_unitario" mode="currency" currency="USD" @focus="($event: FocusEvent) => ($event.target as HTMLInputElement).select()" fluid /></template>
        </Column>
        <Column header="IVA%" style="width:80px">
          <template #body="{ data }"><InputNumber v-model="data.tarifa" :useGrouping="false" fluid /></template>
        </Column>
        <Column header="Total" style="width:100px">
          <template #body="{ data }">{{ money((data.cantidad ?? 0) * (data.precio_unitario ?? 0)) }}</template>
        </Column>
        <Column style="width:44px">
          <template #body="{ index }">
            <Button icon="pi pi-times" text size="small" severity="danger" @click="quitarItem(index)" />
          </template>
        </Column>
      </DataTable>
      <Button label="Agregar artículo" icon="pi pi-plus" size="small" outlined
              style="margin-top:8px" @click="agregarItem" />

      <div style="display:flex; justify-content:flex-end; margin-top:14px;">
        <table style="font-size:13px;">
          <tbody>
            <tr><td style="padding:2px 14px 2px 0; text-align:right;">Subtotal:</td>
                <td style="text-align:right; min-width:110px;">{{ money(base) }}</td></tr>
            <tr><td style="padding:2px 14px 2px 0; text-align:right;">IVA:</td>
                <td style="text-align:right;">{{ money(iva) }}</td></tr>
            <tr style="font-weight:800; border-top:1px solid #444;">
                <td style="padding:4px 14px 4px 0; text-align:right;">Total:</td>
                <td style="text-align:right;">{{ money(total) }}</td></tr>
          </tbody>
        </table>
      </div>

      <template #footer>
        <Button label="Cancelar" text @click="dialog = false" />
        <Button label="Guardar cotización" icon="pi pi-save" :loading="guardando" @click="guardar" />
      </template>
    </Dialog>

    <!-- Varias cotizaciones → una factura -->
    <Dialog v-model:visible="unir" modal header="Convertir cotizaciones en una factura" style="width:820px">
      <Message v-if="unirMsg" :severity="unirMsg.type" :closable="false" style="margin-bottom:12px;">{{ unirMsg.text }}</Message>
      <div v-if="unirCargando" style="color:#94a3b8; padding:20px; text-align:center;">Uniendo las cotizaciones...</div>

      <div v-if="vista">
        <p style="margin:0 0 10px; font-size:13px; color:#64748b;">
          {{ vista.cotizaciones }} cotizaciones se unen en <b>una sola factura</b>. El mismo artículo con el mismo precio
          suma sus cantidades. La factura sale como cualquier otra: baja el inventario, genera su asiento y su comprobante del SRI.
        </p>

        <div class="kvs-row" style="margin-bottom:12px;">
          <label class="kvs-lbl" style="min-width:110px;"><span class="req-star">*</span> Facturar a:</label>
          <Select v-model="unirContacto" :options="contacts" optionValue="id"
                  :optionLabel="(c: any) => c.identificacion + ' — ' + c.razon_social"
                  filter placeholder="Elige el cliente" class="kvs-in" />
        </div>
        <div class="kvs-row" style="margin-bottom:12px;">
          <label class="kvs-lbl" style="min-width:110px;">Forma de pago:</label>
          <Select v-model="unirForma" :options="formasPago" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>

        <DataTable :value="vista.items" size="small" stripedRows>
          <Column field="codigo_principal" header="Código" style="width:110px" />
          <Column field="descripcion" header="Descripción" />
          <Column header="Cant." style="width:80px"><template #body="{ data }">{{ Number(data.cantidad) }}</template></Column>
          <Column header="P. Unit." style="width:100px"><template #body="{ data }">{{ money(data.precio_unitario) }}</template></Column>
          <Column header="Desc." style="width:90px"><template #body="{ data }">{{ money(data.descuento) }}</template></Column>
          <Column header="IVA%" style="width:70px"><template #body="{ data }">{{ data.tarifa ?? 15 }}</template></Column>
          <Column header="Subtotal" style="width:100px">
            <template #body="{ data }">{{ money(Number(data.cantidad) * Number(data.precio_unitario) - Number(data.descuento ?? 0)) }}</template>
          </Column>
        </DataTable>

        <div style="display:flex; justify-content:flex-end; margin-top:12px;">
          <table style="font-size:13px;">
            <tbody>
              <tr><td style="padding:2px 14px 2px 0; text-align:right;">Subtotal:</td>
                  <td style="text-align:right; min-width:110px;">{{ money(vista.total_sin_impuestos) }}</td></tr>
              <tr><td style="padding:2px 14px 2px 0; text-align:right;">IVA:</td>
                  <td style="text-align:right;">{{ money(vista.total_impuesto) }}</td></tr>
              <tr style="font-weight:800; border-top:1px solid #444;">
                  <td style="padding:4px 14px 4px 0; text-align:right;">Total:</td>
                  <td style="text-align:right;">{{ money(vista.importe_total) }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <template #footer>
        <Button label="Cancelar" text @click="unir = false" />
        <Button label="Emitir factura" icon="pi pi-check" :loading="unirEmitiendo"
                :disabled="!vista || !unirContacto" @click="emitirUnidas" />
      </template>
    </Dialog>

    <!-- Detalle -->
    <Dialog :visible="!!detalle" modal :header="'Cotización #' + (detalle?.id ?? '')"
            style="width:680px" @update:visible="detalle = null">
      <div v-if="detalle">
        <p style="margin:0 0 10px;"><b>Cliente:</b> {{ detalle.contact?.razon_social }}</p>
        <DataTable :value="detalle.items ?? []" size="small" stripedRows>
          <Column field="codigo_principal" header="Código" />
          <Column field="descripcion" header="Descripción" />
          <Column field="cantidad" header="Cant." />
          <Column header="P. Unit.">
            <template #body="{ data }">{{ money(data.precio_unitario) }}</template></Column>
          <Column header="Total">
            <template #body="{ data }">{{ money(data.cantidad * data.precio_unitario) }}</template></Column>
        </DataTable>
        <div style="text-align:right; margin-top:10px; font-weight:700;">
          Total: {{ money(detalle.importe_total) }}
        </div>
      </div>
    </Dialog>
  </div>
</template>
