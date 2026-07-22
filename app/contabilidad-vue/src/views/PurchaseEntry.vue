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
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsDocGrid from '../components/kvs/KvsDocGrid.vue'
import KvsToolbar from '../components/kvs/KvsToolbar.vue'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()
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

// Formulario de cabecera
const form = ref<any>({})
// Ítems de la grilla
const items = ref<any[]>([])

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

const tabs = [
  { key: 'datos', label: 'Datos del Comprobante' },
  { key: 'items', label: 'Detalle de Artículos' },
  { key: 'pagos', label: 'Pagos' },
]

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
  const [purchases, contactsRes, productsRes, warehousesRes, sustentosRes] = await Promise.all([
    api.get('/purchases?company_id=' + company.activeId),
    api.get('/contacts?company_id=' + company.activeId),
    api.get('/products?company_id=' + company.activeId),
    api.get('/warehouses?company_id=' + company.activeId).catch(() => ({ data: [] })),
    api.get('/catalogos/sustentos').catch(() => ({ data: [] })),
  ])
  rows.value = purchases.data
  contacts.value = contactsRes.data
  products.value = productsRes.data
  warehouses.value = warehousesRes.data
  sustentos.value = sustentosRes.data
  loading.value = false
}

function seleccionar(r: any) {
  seleccion.value = r
  editando.value = false
  tab.value = 'datos'
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

async function guardar() {
  msg.value = null
  if (!form.value.contact_id) {
    msg.value = { type: 'warn', text: 'Seleccioná un proveedor.' }
    return
  }
  if (!items.value.length) {
    msg.value = { type: 'warn', text: 'Agregá al menos un ítem.' }
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
    warehouse_id: form.value.warehouse_id,
    observacion: form.value.observacion,
    items: calcItems,
    total_sin_impuestos: totalSinImpuestos,
    total_impuesto: totalImpuesto,
    importe_total: totalSinImpuestos + totalImpuesto,
  }

  try {
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
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar la compra.' }
  }
}

function editar() {
  editando.value = true
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
    <KvsModuleHeader module-name="Compras" :company="{ ruc: company.activeId, razon_social: 'Compras' }" subtitle="Registro/Modificación" />
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
        Elegí una compra del listado, o tocá <b>+</b> para registrar una nueva.
      </div>

      <template v-else>
        <div class="kvs-tabs">
          <button v-for="t in tabs" :key="t.key" class="kvs-tab"
                  :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="kvs-tabbody">
          <!-- Datos del Comprobante -->
          <div v-show="tab === 'datos'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Proveedor:</label>
              <Select v-model="form.contact_id" :options="contacts" optionValue="id"
                      optionLabel="razon_social" placeholder="Buscar proveedor..." filter
                      :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> No. Comprobante:</label>
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
              <label class="kvs-lbl"><span class="req">*</span> Sustento Tributario:</label>
              <Select v-model="form.sustento_tributario" :options="sustentos" optionLabel="label"
                      optionValue="value" :disabled="!editando" class="kvs-in" style="max-width:340px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Bodega:</label>
              <Select v-model="form.warehouse_id" :options="warehouses" optionValue="id"
                      optionLabel="nombre" placeholder="General" :disabled="!editando" class="kvs-in" style="max-width:220px" />
            </div>
          </div>

          <!-- Detalle de Artículos -->
          <div v-show="tab === 'items'">
            <KvsDocGrid
              :items="items"
              :readonly="!editando"
              :product-options="products"
              :warehouse-options="warehouses"
              show-series
              show-warehouse
              show-discount
              @update:items="updateItems"
              @add-item="agregarItem"
              @remove-item="removeItem"
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
        </div>

        <KvsToolbar
          :buttons="[
            { icon: 'pi pi-times', label: 'Cancelar', action: 'cancelar', visible: editando },
            { icon: 'pi pi-save', label: 'Guardar', action: 'guardar', disabled: !editando },
            { icon: 'pi pi-pencil', label: 'Editar', action: 'editar', disabled: editando || !seleccion },
          ]"
          align="end"
          @action="(a: string) => { if (a === 'cancelar') cancelar(); else if (a === 'guardar') guardar(); else if (a === 'editar') editar() }"
        />
      </template>
    </section>
    </div>
  </div>
</template>

<style scoped>
.kvs-grid-wrap { flex: 1; overflow: auto; }
.kvs-table tr { cursor: pointer; }
.kvs-table tr:hover { background: #eef6f6; }
.kvs-table tr.kvs-row-sel { background: #d4e4f7; font-weight: 600; }
</style>
