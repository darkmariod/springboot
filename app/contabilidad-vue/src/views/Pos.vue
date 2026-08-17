<script setup lang="ts">
/**
 * Pos.vue — Punto de Venta estilo KBS.
 * Cabecera fiscal + grilla densa de ítems + toolbar de acciones.
 */
import { computed, onMounted, ref } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Dialog from 'primevue/dialog'
import Checkbox from 'primevue/checkbox'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsDocGrid from '../components/kvs/KvsDocGrid.vue'
import KvsToolbar from '../components/kvs/KvsToolbar.vue'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'
import ClienteForm from '../components/ClienteForm.vue'

const company = useCompanyStore()
const products = ref<any[]>([])
const contacts = ref<any[]>([])
const emissionPoints = ref<any[]>([])
const loading = ref(true)
const emitiendo = ref(false)
const msg = ref<any>(null)
const ok = ref<any>(null)

// Cabecera
const contactId = ref<number | null>(null)
const emissionPointId = ref<number | null>(null)
const formaPago = ref('efectivo')
const fechaVenta = ref(new Date().toISOString().slice(0, 10))
const referencia = ref('')

// Grilla
const items = ref<any[]>([])

// Inline client creation
const nuevoClienteDialog = ref(false)
const nuevoClienteForm = ref<any>({ tipo_identificacion: '05', es_cliente: true, es_proveedor: false })
const sriLoading = ref(false)
const identificacionBusqueda = ref('')

// Selector de series (PANTALLA 5)
const seriesDialog = ref(false)
const seriesItemIndex = ref<number | null>(null)
const seriesDisponibles = ref<any[]>([])
const seriesSeleccionadas = ref<string[]>([])
const seriesCargando = ref(false)

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

const contactOptions = computed(() =>
  contacts.value.map((c: any) => ({ id: c.id, label: `${c.razon_social} — ${c.identificacion ?? ''}` }))
)

const formasPago = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Transferencia', value: 'transferencia' },
  { label: 'Tarjeta', value: 'tarjeta' },
  { label: 'Crédito', value: 'credito' },
]

async function load() {
  loading.value = true
  const [pRes, cRes, epRes] = await Promise.all([
    api.get(`/products?company_id=${company.activeId}`),
    api.get(`/contacts?company_id=${company.activeId}`),
    api.get('/emission-points').catch(() => ({ data: [] })),
  ])
  products.value = pRes.data
  contacts.value = cRes.data
  emissionPoints.value = epRes.data
  if (contacts.value.length && !contactId.value) contactId.value = contacts.value[0]?.id ?? null
  if (emissionPoints.value.length) emissionPointId.value = emissionPoints.value[0]?.id ?? null
  loading.value = false
}

function detectarTipoId(id: string): string {
  const digits = id.replace(/\D/g, '')
  return digits.length === 13 ? '04' : '05'  // 04=RUC, 05=cédula
}

async function buscarClientePorId() {
  const raw = identificacionBusqueda.value.trim()
  const id = raw.replace(/\D/g, '')
  if (!id) return

  // El SRI solo consulta con el número COMPLETO: cédula = 10 dígitos, RUC = 13.
  // Sin este aviso, un número a medias abría el diálogo vacío y parecía que "no trae nada".
  if (id.length !== 10 && id.length !== 13) {
    msg.value = {
      type: 'warn',
      text: `Escribí el número completo para consultar al SRI: cédula = 10 dígitos, RUC = 13 (llevás ${id.length}).`,
    }
    return
  }

  const tipo = detectarTipoId(id)

  // 1) Buscar en la lista local
  const match = contacts.value.find((c: any) => c.identificacion === id)
  if (match) {
    contactId.value = match.id
    msg.value = { type: 'success', text: `Cliente encontrado: ${match.razon_social}` }
    return
  }

  // 2) No encontrado → consultar SRI para autocompletar
  sriLoading.value = true
  let sriData: any = { tipo_identificacion: tipo, identificacion: id }
  try {
    const res = await api.get(`/sri/consulta?identificacion=${id}`)
    if (res.data.encontrado) {
      sriData = {
        tipo_identificacion: res.data.tipo_identificacion ?? tipo,
        identificacion: id,
        razon_social: res.data.razon_social ?? '',
        nombre_comercial: res.data.nombre_comercial ?? '',
        email: '',
        telefono: '',
        es_cliente: true,
        es_proveedor: false,
      }
      msg.value = { type: 'success', text: `SRI: ${sriData.razon_social}` }
    } else {
      sriData.es_cliente = true
      sriData.es_proveedor = false
      msg.value = { type: 'warn', text: res.data.mensaje ?? 'No encontrado en SRI. Cargá los datos a mano.' }
    }
  } catch (err: any) {
    sriData.es_cliente = true
    sriData.es_proveedor = false
    msg.value = {
      type: 'warn',
      text: err.response?.data?.error ?? 'No se pudo consultar el SRI ahora. Cargá los datos a mano.',
    }
  } finally {
    sriLoading.value = false
  }

  nuevoClienteForm.value = sriData
  nuevoClienteDialog.value = true
}

async function guardarNuevoCliente() {
  try {
    const res = await api.post('/contacts', { ...nuevoClienteForm.value, company_id: company.activeId })
    contacts.value.push(res.data)
    contactId.value = res.data.id
    nuevoClienteDialog.value = false
    msg.value = { type: 'success', text: 'Cliente creado: ' + res.data.razon_social }
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
  }
}

// Escaneo de código de barras / serie
async function escanear(valor: string) {
  const v = valor.trim()
  if (!v) return
  // Intentar por serie
  try {
    const res = await api.get('/series/lookup?company_id=' + company.activeId + '&serie=' + encodeURIComponent(v))
    const p = res.data.product
    addItemFromProduct(p, res.data.serie)
    return
  } catch { /* no es serie */ }
  // Intentar por código
  const p = products.value.find((x) => x.codigo === v)
  if (p) addItemFromProduct(p)
  else msg.value = { type: 'warn', text: 'No se encontró serie ni código: ' + v }
}

function addItemFromProduct(p: any, serie?: string) {
  const existing = items.value.find((i) => i.producto_id === p.id && (!serie || i.series?.includes(serie)))
  if (existing) {
    existing.cantidad = Number(existing.cantidad) + 1
    if (serie) existing.series = [...(existing.series ?? []), serie]
  } else {
    items.value.push({
      producto_id: p.id,
      codigo_principal: p.codigo,
      descripcion: p.descripcion,
      cantidad: 1,
      precio_unitario: Number(p.precio ?? 0),
      tarifa: Number(p.tarifa_iva ?? 15),
      unidad: 'UNI',
      pct_descuento: 0,
      warehouse_id: null,
      series: serie ? [serie] : [],
    })
    // Producto con maneja_series: abrir el selector de series de inmediato
    if (p.maneja_series && !serie) {
      abrirSelectorSeries(items.value.length - 1)
    }
  }
}

function updateItems(newItems: any[]) {
  items.value = newItems
}

function removeItem(index: number) {
  items.value.splice(index, 1)
}

function addItem() {
  items.value.push({
    producto_id: null,
    codigo_principal: '',
    descripcion: '',
    cantidad: 1,
    precio_unitario: 0,
    tarifa: 15,
    unidad: 'UNI',
    pct_descuento: 0,
    warehouse_id: null,
    series: [],
  })
}

// ── PANTALLA 5: selector de series por artículo ──
async function abrirSelectorSeries(index: number) {
  const item = items.value[index]
  if (!item?.producto_id) {
    msg.value = { type: 'warn', text: 'Primero elegí el artículo para poder seleccionar series.' }
    return
  }
  seriesItemIndex.value = index
  seriesSeleccionadas.value = [...(item.series ?? [])]
  seriesDisponibles.value = []
  seriesCargando.value = true
  seriesDialog.value = true
  try {
    const res = await api.get(`/series?company_id=${company.activeId}&product_id=${item.producto_id}&estado=disponible`)
    seriesDisponibles.value = res.data
    if (!seriesDisponibles.value.length) {
      msg.value = { type: 'warn', text: 'No hay series disponibles para este artículo.' }
    }
  } catch {
    msg.value = { type: 'error', text: 'No se pudieron cargar las series.' }
  } finally {
    seriesCargando.value = false
  }
}

function toggleSerie(serie: string) {
  const idx = seriesSeleccionadas.value.indexOf(serie)
  if (idx >= 0) seriesSeleccionadas.value.splice(idx, 1)
  else seriesSeleccionadas.value.push(serie)
}

function confirmarSeries() {
  const idx = seriesItemIndex.value
  if (idx === null) return
  const newItems = [...items.value]
  newItems[idx] = { ...newItems[idx], series: [...seriesSeleccionadas.value] }
  items.value = newItems
  seriesDialog.value = false
  seriesItemIndex.value = null
}

// Emitir factura
async function emitir() {
  if (!items.value.length) { msg.value = { type: 'warn', text: 'Agregá al menos un producto.' }; return }
  if (!contactId.value) { msg.value = { type: 'warn', text: 'Seleccioná un cliente.' }; return }

  // Validación client-side: productos con maneja_series exigen serie antes de emitir
  const sinSeries = items.value.find((i: any) => {
    const prod = products.value.find((x: any) => x.codigo === i.codigo_principal)
    return prod?.maneja_series && !(i.series ?? []).length
  })
  if (sinSeries) {
    msg.value = { type: 'warn', text: `El artículo ${sinSeries.codigo_principal} maneja series; seleccioná las series antes de emitir.` }
    return
  }

  emitiendo.value = true
  msg.value = null
  ok.value = null
  try {
    const res = await api.post('/invoices', {
      company_id: company.activeId,
      contact_id: contactId.value,
      emission_point_id: emissionPointId.value,
      forma_pago: formaPago.value,
      items: items.value.map((i) => ({
        codigo_principal: i.codigo_principal,
        descripcion: i.descripcion,
        cantidad: Number(i.cantidad),
        precio_unitario: Number(i.precio_unitario),
        tarifa: Number(i.tarifa),
        series: i.series ?? [],
      })),
    })
    ok.value = res.data.invoice
    items.value = []
    msg.value = { type: 'success', text: `Factura ${res.data.invoice.numero} emitida.` }
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? err.response?.data?.errors ? Object.values(err.response.data.errors).flat().join(' · ') : 'No se pudo emitir.' }
  } finally {
    emitiendo.value = false
  }
}

async function guardarCotizacion() {
  if (!items.value.length || !contactId.value) return
  emitiendo.value = true
  try {
    await api.post('/quotes', {
      company_id: company.activeId, contact_id: contactId.value,
      items: items.value.map((i) => ({
        codigo_principal: i.codigo_principal, descripcion: i.descripcion,
        cantidad: Number(i.cantidad), precio_unitario: Number(i.precio_unitario),
        tarifa: Number(i.tarifa),
      })),
    })
    ok.value = { numero: 'Cotización guardada' }
    items.value = []
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'No se pudo guardar.' }
  } finally { emitiendo.value = false }
}

function onAction(action: string) {
  switch (action) {
    case 'emitir': emitir(); break
    case 'cotizar': guardarCotizacion(); break
    case 'nuevo': items.value = []; ok.value = null; msg.value = null; break
  }
}

onMounted(load)
</script>

<template>
  <div class="pos-root">
    <KvsModuleHeader module-name="Ventas" :company="{ ruc: company.activeId, razon_social: 'Punto de Venta' }" subtitle="Facturación" />
    <div class="pos-layout">
    <!-- ══ Panel izquierdo: productos ══ -->
    <section class="pos-products">
      <div class="pos-products-title">Productos</div>
      <input
        placeholder="Escanear código de barras o serie... (Enter)"
        class="pos-scan-input"
        @keydown.enter.prevent="escanear(($event.target as HTMLInputElement).value); ($event.target as HTMLInputElement).value=''"
      />
      <div class="pos-product-list">
        <button v-for="p in products" :key="p.id" class="pos-product-row"
                @click="addItemFromProduct(p)" :title="p.descripcion">
          <img v-if="p.imagen" :src="p.imagen" alt="" class="pos-product-thumb" />
          <i v-else class="pi pi-box pos-product-icon" />
          <div class="pos-product-info">
            <div class="pos-product-name">{{ p.descripcion }}</div>
            <div class="pos-product-meta">
              <span class="pos-product-code">{{ p.codigo }}</span>
              <span v-if="p.tipo !== 'servicio'" class="pos-product-stock"
                    :class="{ 'is-low': Number(p.stock ?? 0) <= 0 }">
                stock {{ Number(p.stock ?? 0) }}
              </span>
            </div>
          </div>
          <div class="pos-product-price">{{ money(Number(p.precio)) }}</div>
        </button>
        <div v-if="!products.length" class="pos-product-empty">
          No hay productos cargados.
        </div>
      </div>
    </section>

    <!-- ══ Panel derecho: documento de venta ══ -->
    <section class="pos-doc">
      <div class="pos-doc-title">VENTAS — Punto de Venta</div>

      <!-- Cabecera -->
      <div class="pos-header">
        <div class="kvs-row">
          <label class="kvs-lbl">Buscar por ID:</label>
          <div style="display:flex; gap:6px; flex:1;">
            <InputText v-model="identificacionBusqueda" placeholder="Cédula o RUC..." 
                       @keydown.enter.prevent="buscarClientePorId" style="flex:1" />
            <Button icon="pi pi-search" size="small" @click="buscarClientePorId" />
          </div>
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Cliente:</label>
          <Select v-model="contactId" :options="contactOptions" optionValue="id"
                  optionLabel="label" placeholder="Seleccionar cliente" filter
                  class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Punto Emisión:</label>
          <Select v-model="emissionPointId" :options="emissionPoints" optionValue="id"
                  optionLabel="nombre" placeholder="Punto" class="kvs-in" style="max-width:220px" />
          <label class="kvs-lbl" style="margin-left:8px;">Fecha:</label>
          <InputText v-model="fechaVenta" type="date" class="kvs-in" style="max-width:150px" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Referencia:</label>
          <InputText v-model="referencia" placeholder="Opcional" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Vendedor:</label>
          <InputText value="" placeholder="Nombre del vendedor" class="kvs-in" style="max-width:200px" disabled />
          <label class="kvs-lbl" style="margin-left:8px;">Lista Precio:</label>
          <InputText value="GENERAL" class="kvs-in" style="max-width:120px" disabled />
          <label class="kvs-lbl" style="margin-left:8px;">Caja:</label>
          <InputText value="Caja Principal" class="kvs-in" style="max-width:140px" disabled />
        </div>
      </div>

      <!-- Grilla de ítems -->
      <div class="pos-grid-wrap">
        <KvsDocGrid
          :items="items"
          :product-options="products"
          show-series
          show-discount
          show-kardex
          show-cost-center
          @update:items="updateItems"
          @add-item="addItem"
          @remove-item="removeItem"
          @scan-serie="abrirSelectorSeries"
        />
      </div>

      <!-- Mensajes -->
      <Message v-if="msg" :severity="msg.type" :closable="true" style="margin:8px 12px;"
               @close="msg = null">
        {{ msg.text }}
      </Message>
      <Message v-if="ok" severity="success" :closable="true" style="margin:8px 12px;"
               @close="ok = null">
        {{ ok.numero ? 'Factura ' + ok.numero + ' emitida' : ok.numero ?? 'Operación exitosa' }}
      </Message>

      <!-- Forma de pago -->
      <div class="pos-pago">
        <span class="pos-pago-label">Forma de pago:</span>
        <div class="pos-pago-btns">
          <Button v-for="fp in formasPago" :key="fp.value"
                  :label="fp.label" size="small"
                  :outlined="formaPago !== fp.value"
                  @click="formaPago = fp.value" />
        </div>
      </div>

      <!-- Toolbar -->
      <div class="pos-toolbar">
        <KvsToolbar align="end">
          <Button label="Nuevo" icon="pi pi-plus" size="small" text @click="onAction('nuevo')" />
          <Button label="Cotización" icon="pi pi-file-edit" size="small" text
                  :disabled="!items.length || !contactId" @click="onAction('cotizar')" />
          <Button label="Emitir Factura" icon="pi pi-check-circle" size="small"
                  :loading="emitiendo" :disabled="!items.length" @click="onAction('emitir')" />
        </KvsToolbar>
      </div>
    </section>
    </div>
  </div>

  <Dialog v-model:visible="nuevoClienteDialog" modal header="Nuevo Cliente" style="width:520px;">
    <ClienteForm v-model="nuevoClienteForm" />
    <template #footer>
      <div class="kvs-footer">
        <Button label="Cancelar" text @click="nuevoClienteDialog=false" />
        <Button label="Guardar cliente" @click="guardarNuevoCliente" />
      </div>
    </template>
  </Dialog>

  <!-- PANTALLA 5: selector de series disponibles -->
  <Dialog v-model:visible="seriesDialog" modal header="Series por Artículo" style="width:460px;">
    <div v-if="seriesCargando" class="kvs-dg-cell" style="padding:16px; text-align:center; color:#64748b;">
      <i class="pi pi-spin pi-spinner" /> Cargando series...
    </div>
    <div v-else-if="seriesDisponibles.length" style="max-height:320px; overflow:auto;">
      <label
        v-for="s in seriesDisponibles"
        :key="s.id"
        style="display:flex; align-items:center; gap:10px; padding:8px 6px; border-bottom:1px solid #eef1f5; cursor:pointer;"
      >
        <Checkbox
          :model-value="seriesSeleccionadas.includes(s.serie)"
          :binary="true"
          @update:model-value="toggleSerie(s.serie)"
        />
        <span style="font-family:monospace; font-size:13px; font-weight:600;">{{ s.serie }}</span>
        <span style="font-size:11px; color:#64748b;">{{ s.product?.codigo ?? '' }}</span>
      </label>
    </div>
    <div v-else style="padding:16px; text-align:center; color:#94a3b8;">
      No hay series disponibles para este artículo.
    </div>
    <template #footer>
      <div class="kvs-footer">
        <Button label="Cancelar" text @click="seriesDialog=false" />
        <Button label="Aplicar" icon="pi pi-check" :disabled="!seriesSeleccionadas.length" @click="confirmarSeries" />
      </div>
    </template>
  </Dialog>
</template>

<style scoped>
.pos-root { display: flex; flex-direction: column; height: 100%; overflow: hidden; }
.pos-layout { display: flex; height: 100%; background: #eef1f5; flex: 1; min-height: 0; overflow: hidden; }

/* ── Panel productos ── */
.pos-products {
  width: 420px; flex-shrink: 0; display: flex; flex-direction: column;
  background: #fff; border-right: 1px solid #b9c2cc;
}
.pos-products-title {
  background: var(--hr-gradient); color: #fff; font-weight: 600;
  font-size: 12.5px; padding: 5px 10px;
}
.pos-scan-input {
  width: 100%; padding: 8px 12px; border: 0; border-bottom: 1px solid #e2e5ea;
  font-size: 13px; outline: none;
}
.pos-scan-input:focus { background: #f0faf8; }
/* Lista densa de productos (estilo ERP), en vez de tarjetas con imagen vacía */
.pos-product-list { flex: 1; overflow: auto; padding: 0; }
.pos-product-row {
  width: 100%; display: flex; align-items: center; gap: 9px;
  padding: 7px 10px; border: 0; border-bottom: 1px solid #eef1f5;
  background: #fff; cursor: pointer; text-align: left; transition: background 0.12s;
}
.pos-product-row:hover { background: #eef4fc; }
.pos-product-thumb {
  width: 34px; height: 34px; object-fit: cover; border-radius: 4px; flex-shrink: 0;
  border: 1px solid #e2e5ea;
}
.pos-product-icon {
  width: 34px; height: 34px; flex-shrink: 0; border-radius: 4px; background: #f1f3f6;
  color: #94a3b8; font-size: 15px; display: flex; align-items: center; justify-content: center;
}
.pos-product-info { flex: 1; min-width: 0; }
.pos-product-name {
  font-size: 12.5px; color: #1f2733; font-weight: 500; line-height: 1.25;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pos-product-meta { display: flex; gap: 8px; margin-top: 2px; font-size: 11px; color: #8a94a6; }
.pos-product-code { font-family: "SF Mono", Menlo, monospace; }
.pos-product-stock { color: #64748b; }
.pos-product-stock.is-low { color: #d93025; font-weight: 600; }
.pos-product-price {
  font-size: 13px; color: #16a34a; font-weight: 700; white-space: nowrap; flex-shrink: 0;
}
.pos-product-empty { padding: 24px 12px; text-align: center; color: #94a3b8; font-size: 12.5px; }

/* ── Panel documento ── */
.pos-doc {
  flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0;
}
.pos-doc-title {
  background: var(--hr-gradient); color: #fff; font-weight: 600;
  font-size: 12.5px; padding: 5px 10px; flex-shrink: 0;
}
.pos-header { padding: 10px 12px; border-bottom: 1px solid #e2e5ea; background: #f7f9fb; flex-shrink: 0; max-height: 40vh; overflow-y: auto; }
.pos-grid-wrap { flex: 1; overflow: auto; padding: 10px 12px; min-height: 0; }
.pos-pago {
  display: flex; align-items: center; gap: 10px; padding: 8px 12px;
  border-top: 1px solid #e2e5ea; background: #f7f9fb; flex-shrink: 0;
}
.pos-pago-label { font-size: 12px; color: #546e7a; font-weight: 600; text-transform: uppercase; }
.pos-pago-btns { display: flex; gap: 6px; }
.pos-toolbar { flex-shrink: 0; border-top: 1px solid #e2e5ea; background: #f7f9fb; }


</style>
