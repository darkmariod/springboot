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
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsDocGrid from '../components/kvs/KvsDocGrid.vue'
import KvsToolbar from '../components/kvs/KvsToolbar.vue'
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

function buscarClientePorId() {
  const id = identificacionBusqueda.value.trim()
  if (!id) return
  const match = contacts.value.find((c: any) => c.identificacion === id)
  if (match) {
    contactId.value = match.id
    msg.value = { type: 'success', text: `Cliente encontrado: ${match.razon_social}` }
  } else {
    nuevoClienteForm.value = { tipo_identificacion: '05', identificacion: id, es_cliente: true, es_proveedor: false }
    nuevoClienteDialog.value = true
  }
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

// Emitir factura
async function emitir() {
  if (!items.value.length) { msg.value = { type: 'warn', text: 'Agregá al menos un producto.' }; return }
  if (!contactId.value) { msg.value = { type: 'warn', text: 'Seleccioná un cliente.' }; return }

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
  <div class="pos-layout">
    <!-- ══ Panel izquierdo: productos ══ -->
    <section class="pos-products">
      <div class="pos-products-title">Productos</div>
      <input
        placeholder="Escanear código de barras o serie... (Enter)"
        class="pos-scan-input"
        @keydown.enter.prevent="escanear(($event.target as HTMLInputElement).value); ($event.target as HTMLInputElement).value=''"
      />
      <div class="pos-product-grid">
        <button v-for="p in products" :key="p.id" class="pos-product-card"
                @click="addItemFromProduct(p)">
          <div v-if="p.imagen" class="pos-product-img">
            <img :src="p.imagen" alt="" />
          </div>
          <div v-else class="pos-product-img pos-product-img--empty">
            <i class="pi pi-box" />
          </div>
          <div class="pos-product-name">{{ p.descripcion }}</div>
          <div class="pos-product-price">{{ money(Number(p.precio)) }}</div>
        </button>
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
      </div>

      <!-- Grilla de ítems -->
      <div class="pos-grid-wrap">
        <KvsDocGrid
          :items="items"
          :product-options="products"
          show-series
          show-discount
          @update:items="updateItems"
          @add-item="addItem"
          @remove-item="removeItem"
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
      <KvsToolbar align="end">
        <Button label="Nuevo" icon="pi pi-plus" size="small" text @click="onAction('nuevo')" />
        <Button label="Cotización" icon="pi pi-file-edit" size="small" text
                :disabled="!items.length || !contactId" @click="onAction('cotizar')" />
        <Button label="Emitir Factura" icon="pi pi-check-circle" size="small"
                :loading="emitiendo" :disabled="!items.length" @click="onAction('emitir')" />
      </KvsToolbar>
    </section>
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
</template>

<style scoped>
.pos-layout { display: flex; height: 100%; background: #eef1f5; }

/* ── Panel productos ── */
.pos-products {
  width: 420px; flex-shrink: 0; display: flex; flex-direction: column;
  background: #fff; border-right: 1px solid #b9c2cc;
}
.pos-products-title {
  background: linear-gradient(#3d8b8b, #2a6b6b); color: #fff; font-weight: 600;
  font-size: 12.5px; padding: 5px 10px;
}
.pos-scan-input {
  width: 100%; padding: 8px 12px; border: 0; border-bottom: 1px solid #e2e5ea;
  font-size: 13px; outline: none;
}
.pos-scan-input:focus { background: #f0faf8; }
.pos-product-grid {
  flex: 1; overflow: auto; padding: 10px;
  display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px;
}
.pos-product-card {
  border: 1px solid #e2e5ea; border-radius: 6px; background: #fff;
  padding: 6px; cursor: pointer; text-align: left; transition: border-color 0.15s;
}
.pos-product-card:hover { border-color: #3d8b8b; }
.pos-product-img { height: 60px; border-radius: 4px; overflow: hidden; margin-bottom: 4px; }
.pos-product-img img { width: 100%; height: 100%; object-fit: cover; }
.pos-product-img--empty {
  background: #f1f3f6; display: flex; align-items: center; justify-content: center;
  color: #cbd5e1;
}
.pos-product-name { font-size: 12px; color: #37474f; line-height: 1.3; }
.pos-product-price { font-size: 13px; color: #22a06b; font-weight: 600; margin-top: 2px; }

/* ── Panel documento ── */
.pos-doc {
  flex: 1; display: flex; flex-direction: column; overflow: hidden;
}
.pos-doc-title {
  background: linear-gradient(#3d8b8b, #2a6b6b); color: #fff; font-weight: 600;
  font-size: 12.5px; padding: 5px 10px;
}
.pos-header { padding: 10px 12px; border-bottom: 1px solid #e2e5ea; background: #f7f9fb; }
.pos-grid-wrap { flex: 1; overflow: auto; padding: 10px 12px; }
.pos-pago {
  display: flex; align-items: center; gap: 10px; padding: 8px 12px;
  border-top: 1px solid #e2e5ea; background: #f7f9fb;
}
.pos-pago-label { font-size: 12px; color: #546e7a; font-weight: 600; text-transform: uppercase; }
.pos-pago-btns { display: flex; gap: 6px; }

/* ── KVS rows dentro del POS ── */
.kvs-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.kvs-lbl { font-size: 13px; color: #37474f; white-space: nowrap; min-width: 110px; text-align: right; }
.kvs-lbl .req { color: #d93025; font-weight: 700; }
.kvs-in { flex: 1; min-width: 0; }
</style>
