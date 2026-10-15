<script setup lang="ts">
/**
 * PurchaseEntry.vue — Registro/Modificación de Compras estilo KBS.
 * Layout master-detail con grilla densa de ítems.
 * La compra se RECIBE (no se emite al SRI). La retención se emite aparte.
 */
import { computed, onMounted, ref } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import ClienteForm from '../components/ClienteForm.vue'
import RetencionCompra from '../components/RetencionCompra.vue'
import SeriesInput from '../components/SeriesInput.vue'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { usePlanStore } from '../stores/plan'
import KvsDocGrid from '../components/kvs/KvsDocGrid.vue'
import KvsToolbar from '../components/kvs/KvsToolbar.vue'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()
const plan = usePlanStore()
const rows = ref<any[]>([])
const loading = ref(true)
const seleccion = ref<any>(null)
const editando = ref(false)
const tab = ref('datos')
const msg = ref<any>(null)

// Catálogos
const contacts = ref<any[]>([])
const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const sustentos = ref<any[]>([])
const tiposComprobante = ref<any[]>([
  { value: 'factura', label: 'Factura' },
  { value: 'nota_venta', label: 'Nota de venta' },
])
// Justo después de guardar una compra nueva se pregunta si se le aplica retención
const preguntarRetencion = ref(false)

// Formulario de cabecera
const form = ref<any>({})
// Ítems de la grilla
const items = ref<any[]>([])

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

// Crear proveedor sin salir de esta pantalla: si al comprar no existe,
// antes había que ir a Compras → Proveedores, crearlo y volver.
const nuevoProveedorDialog = ref(false)
const nuevoProveedorForm = ref<any>({ tipo_identificacion: '04', es_proveedor: true, es_cliente: false })

function nuevoProveedor() {
  nuevoProveedorForm.value = { tipo_identificacion: '04', es_proveedor: true, es_cliente: false }
  nuevoProveedorDialog.value = true
}

async function guardarNuevoProveedor() {
  try {
    const res = await api.post('/contacts', { ...nuevoProveedorForm.value, company_id: company.activeId })
    contacts.value.push(res.data)
    form.value.contact_id = res.data.id
    nuevoProveedorDialog.value = false
    msg.value = { type: 'success', text: 'Proveedor creado: ' + res.data.razon_social }
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
  }
}

// La retención se emite con el comprobante electrónico: solo con el módulo del SRI y con la compra ya guardada
const puedeRetener = computed(() => !!form.value.id && plan.tiene('facturacion_sri'))
const tabs = computed(() => [
  { key: 'datos', label: 'Datos del Comprobante' },
  { key: 'items', label: 'Detalle de Artículos' },
  { key: 'pagos', label: 'Pagos' },
  ...(puedeRetener.value ? [{ key: 'retencion', label: 'Retención' }] : []),
])

// ── Filtros del listado ──
const filtro = ref({ numero: '', proveedor: '' })
const filtrados = computed(() => rows.value.filter((r: any) => {
  const n = filtro.value.numero.toLowerCase()
  const p = filtro.value.proveedor.toLowerCase()
  return (!n || (r.numero ?? '').toLowerCase().includes(n))
    && (!p || (r.contact?.razon_social ?? '').toLowerCase().includes(p))
}))

// toolbarButtons removed — inline toolbar in template handles actions

async function load() {
  loading.value = true
  const [purchases, contactsRes, productsRes, warehousesRes, sustentosRes, tiposRes] = await Promise.all([
    api.get('/purchases?company_id=' + company.activeId),
    api.get('/contacts?company_id=' + company.activeId),
    api.get('/products?company_id=' + company.activeId),
    api.get('/warehouses?company_id=' + company.activeId).catch(() => ({ data: [] })),
    api.get('/catalogos/sustentos').catch(() => ({ data: [] })),
    api.get('/catalogos/tipos-comprobante-compra').catch(() => ({ data: [] })),
  ])
  rows.value = purchases.data
  contacts.value = contactsRes.data
  products.value = productsRes.data
  warehouses.value = warehousesRes.data
  sustentos.value = sustentosRes.data
  if (tiposRes.data.length) tiposComprobante.value = tiposRes.data
  loading.value = false
}

function seleccionar(r: any) {
  seleccion.value = r
  editando.value = false
  tab.value = 'datos'
  preguntarRetencion.value = false
  form.value = {
    id: r.id,
    contact_id: r.contact_id,
    numero: r.numero,
    clave_acceso: r.clave_acceso ?? '',
    fecha_emision: r.fecha_emision?.slice(0, 10) ?? '',
    establecimiento: r.establecimiento ?? '001',
    punto_emision: r.punto_emision ?? '001',
    autorizacion: r.autorizacion ?? '',
    sustento_tributario: r.sustento_tributario ?? '01',
    tipo_comprobante: r.tipo_comprobante === 'nota_venta' ? 'nota_venta' : 'factura',
    warehouse_id: r.warehouse_id ?? null,
    observacion: r.observacion ?? '',
  }
  items.value = (r.items ?? []).map((it: any) => ({ ...it }))
}

function nuevo() {
  seleccion.value = null
  editando.value = true
  tab.value = 'datos'
  msg.value = null
  form.value = {
    id: null,
    contact_id: null,
    numero: '',
    clave_acceso: '',
    fecha_emision: new Date().toISOString().slice(0, 10),
    establecimiento: '001',
    punto_emision: '001',
    autorizacion: '',
    sustento_tributario: '01',
    tipo_comprobante: 'factura',
    warehouse_id: null,
    observacion: '',
  }
  items.value = []
}

function agregarItem() {
  items.value.push({
    codigo_principal: '',
    descripcion: '',
    cantidad: 1,
    precio_unitario: 0,
    tarifa: 15,
    unidad: 'UNI',
    pct_descuento: 0,
    warehouse_id: form.value.warehouse_id,
    series: [],
  })
}

function removeItem(index: number) {
  items.value.splice(index, 1)
}

function updateItems(newItems: any[]) {
  items.value = newItems
}

// ── Series: un producto que las maneja entra con una serie por unidad ──
const productoDe = (it: any) =>
  products.value.find((p: any) => p.id === it.producto_id || p.codigo === it.codigo_principal)
const manejaSeries = (it: any) => {
  const p = productoDe(it)
  return !!p?.maneja_series && p.tipo !== 'servicio'
}
// Compra nueva: las líneas con series incompletas bloquean el guardado (una compra ya guardada solo cambia su cabecera)
const seriesPendientes = computed(() => {
  if (form.value.id) return []
  return items.value
    .map((it: any, indice: number) => ({ it, indice }))
    .filter(({ it }: any) => manejaSeries(it) && Number(it.cantidad) > 0 && (it.series ?? []).length !== Number(it.cantidad))
    .map(({ it, indice }: any) => ({ indice, codigo: it.codigo_principal, tiene: (it.series ?? []).length, necesita: Number(it.cantidad) }))
})

const seriesDialog = ref<{ index: number; series: string[]; valido: boolean } | null>(null)
const lineaDeSeries = computed(() => (seriesDialog.value ? items.value[seriesDialog.value.index] : null))
function abrirSeries(index: number) {
  const it = items.value[index]
  if (!it) return
  if (!manejaSeries(it)) {
    msg.value = {
      type: 'warn',
      text: 'Este artículo no maneja series. Si debe manejarlas, activa «Aplica Serie» en el artículo.',
    }
    return
  }
  seriesDialog.value = { index, series: [...(it.series ?? [])], valido: false }
}
function setSeriesValidas(v: boolean) {
  if (seriesDialog.value) seriesDialog.value.valido = v
}
function aceptarSeries() {
  if (!seriesDialog.value) return
  const nuevos = [...items.value]
  nuevos[seriesDialog.value.index] = { ...nuevos[seriesDialog.value.index], series: [...seriesDialog.value.series] }
  items.value = nuevos
  seriesDialog.value = null
}

async function guardar() {
  msg.value = null
  if (!form.value.contact_id) {
    msg.value = { type: 'warn', text: 'Selecciona un proveedor.' }
    return
  }
  if (!items.value.length) {
    msg.value = { type: 'warn', text: 'Agrega al menos un ítem.' }
    return
  }
  if (seriesPendientes.value.length) {
    tab.value = 'items'
    msg.value = { type: 'warn', text: 'Faltan series: ' + seriesPendientes.value.map((x: any) => `${x.codigo} (${x.tiene} de ${x.necesita})`).join(', ') + '.' }
    return
  }

  // Calcular totales
  let totalSinImpuestos = 0
  let totalImpuesto = 0
  const calcItems = items.value.map((it: any) => {
    const cant = Number(it.cantidad ?? 0)
    const precio = Number(it.precio_unitario ?? 0)
    const tarifa = Number(it.tarifa ?? 15)
    const dcto = cant * precio * Number(it.pct_descuento ?? 0) / 100
    const base = cant * precio - dcto
    const iva = base * tarifa / 100
    totalSinImpuestos += base
    totalImpuesto += iva
    return {
      codigo_principal: it.codigo_principal,
      descripcion: it.descripcion,
      cantidad: cant,
      precio_unitario: precio,
      tarifa,
      unidad: it.unidad ?? 'UNI',
      descuento: dcto,
      series: it.series ?? [],
    }
  })

  const payload = {
    company_id: company.activeId,
    contact_id: form.value.contact_id,
    numero: form.value.numero,
    fecha_emision: form.value.fecha_emision,
    establecimiento: form.value.establecimiento,
    punto_emision: form.value.punto_emision,
    autorizacion: form.value.autorizacion,
    clave_acceso: form.value.clave_acceso,
    sustento_tributario: form.value.sustento_tributario,
    tipo_comprobante: form.value.tipo_comprobante,
    warehouse_id: form.value.warehouse_id,
    observacion: form.value.observacion,
    items: calcItems,
    total_sin_impuestos: totalSinImpuestos,
    total_impuesto: totalImpuesto,
    importe_total: totalSinImpuestos + totalImpuesto,
  }

  try {
    const eraNueva = !form.value.id
    if (form.value.id) {
      await api.put('/purchases/' + form.value.id, payload)
      msg.value = { type: 'success', text: 'Compra actualizada.' }
    } else {
      const res = await api.post('/purchases', payload)
      form.value.id = res.data.id
      msg.value = { type: 'success', text: 'Compra registrada.' }
    }
    editando.value = false
    await load()
    const fresco = rows.value.find((r: any) => r.id === form.value.id)
    if (fresco) seleccionar(fresco)
    // Compra nueva: el paso que sigue es decidir si se retiene
    if (eraNueva && fresco && puedeRetener.value) {
      preguntarRetencion.value = true
      tab.value = 'retencion'
      msg.value = { type: 'success', text: 'Compra registrada. ¿Se le aplica retención?' }
    }
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar la compra.' }
  }
}

function editar() {
  editando.value = true
}

// Registrada una retención: el saldo de la compra cambió, se refresca sin salir de la pestaña
async function retencionRegistrada() {
  const id = form.value.id
  await load()
  const fresco = rows.value.find((r: any) => r.id === id)
  if (fresco) seleccion.value = fresco
}

async function eliminar() {
  if (!form.value.id) return
  if (!confirm('¿Eliminar la compra ' + (form.value.numero ?? '') + '? Esto revierte el stock que ingresó.')) return
  try {
    await api.delete('/purchases/' + form.value.id)
    seleccion.value = null
    form.value = {}
    items.value = []
    await load()
  } catch (err: any) {
    alert(err.response?.data?.message ?? 'No se pudo eliminar la compra.')
  }
}

function cancelar() {
  editando.value = false
  if (seleccion.value) seleccionar(seleccion.value)
  else { form.value = {}; items.value = [] }
}

// onToolbar removed — template handles actions inline

onMounted(load)
</script>

<template>
  <div style="display:flex; flex-direction:column; height:100%;">
    <KvsModuleHeader module-name="Compras" :company="{ ruc: company.activeRuc, razon_social: 'Compras' }" subtitle="Registro/Modificación" />
    <div class="kvs-split" style="flex:1; min-height:0;">
    <!-- ══ Listado de Compras ══ -->
    <section class="kvs-panel kvs-panel--list">
      <div class="kvs-panel-title">Listado de Compras</div>
      <div class="kvs-search">
        <span class="kvs-search-label">Búsqueda</span>
        <InputText v-model="filtro.numero" placeholder="No. Comprobante" size="small" style="width:110px" />
        <InputText v-model="filtro.proveedor" placeholder="Proveedor" size="small" style="flex:1" />
        <Button icon="pi pi-plus" size="small" text @click="nuevo" title="Nueva compra" />
      </div>
      <div class="kvs-grid-wrap">
        <table class="kvs-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>No.</th>
              <th>Proveedor</th>
              <th class="der">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filtrados" :key="r.id"
                :class="{ 'kvs-row-sel': seleccion?.id === r.id }"
                @click="seleccionar(r)">
              <td>{{ String(r.fecha_emision).slice(0, 10) }}</td>
              <td>{{ r.numero }}</td>
              <td>{{ r.contact?.razon_social }}</td>
              <td class="der">{{ money(r.importe_total) }}</td>
            </tr>
            <tr v-if="!filtrados.length">
              <td colspan="4" class="vacio">{{ loading ? 'Cargando...' : 'Sin compras registradas' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="kvs-panel-foot">Mostrando {{ filtrados.length }} de {{ rows.length }}</div>
    </section>

    <!-- ══ Detalle de Compra ══ -->
    <section class="kvs-panel kvs-panel--detail">
      <div class="kvs-panel-title">Detalle de Compra</div>

      <div v-if="!form.id && !editando" class="kvs-empty">
        Elige una compra del listado, o toca <b>+</b> para registrar una nueva.
      </div>

      <template v-else>
        <Message v-if="msg" :severity="msg.type" :closable="false" style="margin:8px 10px 0;">{{ msg.text }}</Message>
        <div class="kvs-tabs">
          <button v-for="t in tabs" :key="t.key" class="kvs-tab"
                  :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="kvs-tabbody">
          <!-- Datos del Comprobante -->
          <div v-show="tab === 'datos'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Proveedor:</label>
              <Select v-model="form.contact_id" :options="contacts" optionValue="id"
                      optionLabel="razon_social" placeholder="Buscar proveedor..." filter
                      :disabled="!editando" class="kvs-in" style="flex:1" />
              <Button v-if="editando" icon="pi pi-plus" size="small" text
                      title="Proveedor nuevo" @click="nuevoProveedor" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> No. Comprobante:</label>
              <InputText v-model="form.numero" :disabled="!editando" class="kvs-in" style="max-width:180px" />
              <label class="kvs-lbl" style="margin-left:12px;">Fecha Emisión:</label>
              <InputText v-model="form.fecha_emision" type="date" :disabled="!editando" class="kvs-in" style="max-width:160px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Establecimiento:</label>
              <InputText v-model="form.establecimiento" :disabled="!editando" class="kvs-in" style="max-width:80px" />
              <label class="kvs-lbl" style="margin-left:12px;">Punto Emisión:</label>
              <InputText v-model="form.punto_emision" :disabled="!editando" class="kvs-in" style="max-width:80px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">No. Autorización:</label>
              <InputText v-model="form.autorizacion" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Clave Acceso:</label>
              <InputText v-model="form.clave_acceso" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Sustento Tributario:</label>
              <Select v-model="form.sustento_tributario" :options="sustentos" optionLabel="label"
                      optionValue="value" :disabled="!editando" class="kvs-in" style="max-width:340px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Tipo de Comprobante:</label>
              <Select v-model="form.tipo_comprobante" :options="tiposComprobante" optionLabel="label"
                      optionValue="value" :disabled="!editando" class="kvs-in" style="max-width:220px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Bodega:</label>
              <Select v-model="form.warehouse_id" :options="warehouses" optionValue="id"
                      optionLabel="nombre" placeholder="General" :disabled="!editando" class="kvs-in" style="max-width:220px" />
            </div>
          </div>

          <!-- Detalle de Artículos -->
          <div v-show="tab === 'items'">
            <Message v-if="editando && seriesPendientes.length" severity="warn" :closable="false" style="margin-bottom:8px;">
              Los artículos con serie necesitan una serie por unidad antes de guardar:
              <Button v-for="x in seriesPendientes" :key="x.indice" size="small" outlined icon="pi pi-tag" style="margin:4px 6px 0 0;"
                      :label="`Series de ${x.codigo} (${x.tiene} de ${x.necesita})`" :data-series-linea="x.indice"
                      @click="abrirSeries(x.indice)" />
            </Message>
            <KvsDocGrid
              :items="items"
              :readonly="!editando"
              :product-options="products"
              :warehouse-options="warehouses"
              show-series
              :show-warehouse="false"
              show-discount
              @update:items="updateItems"
              @add-item="agregarItem"
              @remove-item="removeItem"
              @scan-serie="abrirSeries"
            />
          </div>

          <!-- Pagos -->
          <div v-show="tab === 'pagos'">
            <p class="kvs-hint">
              Los pagos se registran desde Cuentas por pagar. Si la compra tiene saldo pendiente,
              aparecerá en esa sección.
            </p>
            <div v-if="form.id && Number(seleccion?.saldo_pendiente ?? 0) > 0" class="kvs-row">
              <label class="kvs-lbl">Saldo pendiente:</label>
              <Tag :value="money(seleccion.saldo_pendiente)" severity="warn" />
            </div>
          </div>

          <!-- Retención al proveedor -->
          <div v-if="tab === 'retencion' && puedeRetener && seleccion">
            <RetencionCompra :purchase="seleccion" :preguntar="preguntarRetencion" @registrada="retencionRegistrada" />
          </div>
        </div>

        <KvsToolbar
          :buttons="[
            { icon: 'pi pi-times', label: 'Cancelar', action: 'cancelar', visible: editando },
            { icon: 'pi pi-save', label: 'Guardar', action: 'guardar', disabled: !editando || seriesPendientes.length > 0 },
            { icon: 'pi pi-pencil', label: 'Editar', action: 'editar', disabled: editando || !seleccion },
            { icon: 'pi pi-trash', label: 'Eliminar', action: 'eliminar', severity: 'danger',
              visible: !editando, disabled: !seleccion },
          ]"
          align="end"
          @action="(a: string) => { if (a === 'cancelar') cancelar(); else if (a === 'guardar') guardar(); else if (a === 'editar') editar(); else if (a === 'eliminar') eliminar() }"
        />
      </template>
    </section>
    </div>
  </div>

  <Dialog :visible="!!seriesDialog" modal :header="'Series de ' + (lineaDeSeries?.descripcion || lineaDeSeries?.codigo_principal || 'el artículo')"
          style="width:520px;" @update:visible="(v: boolean) => { if (!v) seriesDialog = null }">
    <template v-if="seriesDialog && lineaDeSeries">
      <p style="margin:0 0 10px; font-size:13px; color:#475569;">
        <b>{{ lineaDeSeries.codigo_principal }}</b> · {{ Number(lineaDeSeries.cantidad) }} unidad(es): una serie por unidad.
      </p>
      <SeriesInput v-if="editando && !form.id" v-model="seriesDialog.series" :cantidad="Number(lineaDeSeries.cantidad)"
                   @valido="setSeriesValidas" />
      <ul v-else style="margin:0; padding-left:18px; font-family:monospace; font-size:13px;">
        <li v-for="s in seriesDialog.series" :key="s">{{ s }}</li>
        <li v-if="!seriesDialog.series.length" style="list-style:none; margin-left:-18px; font-family:inherit; color:#64748b;">
          Esta compra no tiene series guardadas.
        </li>
      </ul>
    </template>
    <template #footer>
      <div class="kvs-footer">
        <Button :label="editando && !form.id ? 'Cancelar' : 'Cerrar'" text @click="seriesDialog = null" />
        <Button v-if="editando && !form.id" label="Aceptar series" icon="pi pi-check"
                :disabled="!seriesDialog?.valido" @click="aceptarSeries" />
      </div>
    </template>
  </Dialog>

  <Dialog v-model:visible="nuevoProveedorDialog" modal header="Nuevo Proveedor" style="width:520px;">
    <ClienteForm v-model="nuevoProveedorForm" />
    <template #footer>
      <div class="kvs-footer">
        <Button label="Cancelar" text @click="nuevoProveedorDialog=false" />
        <Button label="Guardar proveedor" @click="guardarNuevoProveedor" />
      </div>
    </template>
  </Dialog>
</template>

<style scoped>
.kvs-grid-wrap { flex: 1; overflow: auto; }
.kvs-table tr { cursor: pointer; }
.kvs-table tr:hover { background: #eef6f6; }
.kvs-table tr.kvs-row-sel { background: #d4e4f7; font-weight: 600; }
</style>
