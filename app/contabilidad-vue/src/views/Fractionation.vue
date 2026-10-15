<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()
const products = ref<any[]>([])
const warehouses = ref<any[]>([])
// La cabecera muestra la empresa activa (nombre y RUC reales)
const empresa = computed(() => company.companies.find((c: any) => c.id === company.activeId) ?? null)
const loading = ref(false)
const submitting = ref(false)
const msg = ref<{ type: 'success' | 'warn' | 'error'; text: string } | null>(null)
const historial = ref<any[]>([])

const form = ref({
  product_id: null as number | null,        // padre: lo que se compra (la caja)
  child_product_id: null as number | null,  // hijo: lo que se vende suelto (la botella)
  quantity: 1 as number | null,
  units_per_parent: null as number | null,
  warehouse_id: null as number | null,
  motivo: '',
})

const num = (n: any) => Number(n ?? 0)
const cant = (n: number) => n.toLocaleString('es-EC', { maximumFractionDigits: 4 })
const money = (n: number) => '$' + n.toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
const redondear = (n: number, d = 2) => Math.round((n + Number.EPSILON) * 10 ** d) / 10 ** d

// Servicios, combos y productos con series no tienen existencias que se puedan partir: no se ofrecen
const elegibles = computed(() => products.value
  .filter((p: any) => p.tipo !== 'servicio' && !p.es_combo && !p.maneja_series)
  .map((p: any) => ({ ...p, etiqueta: `${p.codigo} · ${p.descripcion} (stock ${cant(num(p.stock))})` })))
const hijos = computed(() => elegibles.value.filter((p: any) => p.id !== form.value.product_id))
const padre = computed(() => elegibles.value.find((p: any) => p.id === form.value.product_id) ?? null)
const hijo = computed(() => elegibles.value.find((p: any) => p.id === form.value.child_product_id) ?? null)

// Stock del padre en cada bodega (el servidor descuenta de la bodega elegida)
const stockBodegas = ref<Record<number, number>>({})
const bodegasOpc = computed(() => warehouses.value.map((w: any) => ({
  id: w.id,
  etiqueta: stockBodegas.value[w.id] !== undefined ? `${w.nombre} (stock ${cant(stockBodegas.value[w.id])})` : w.nombre,
})))
const stockDisponible = computed(() => {
  const w = form.value.warehouse_id
  if (w && stockBodegas.value[w] !== undefined) return stockBodegas.value[w]
  return num(padre.value?.stock)
})

// Lo que va a pasar, con las mismas cuentas que hace el servidor: el valor del padre se reparte entre los hijos
const cantidadHijo = computed(() => redondear((form.value.quantity ?? 0) * (form.value.units_per_parent ?? 0), 4))
const valorTotal = computed(() => redondear((form.value.quantity ?? 0) * num(padre.value?.costo_promedio)))
const costoHijo = computed(() => cantidadHijo.value > 0 ? Math.floor(valorTotal.value / cantidadHijo.value * 10000 + 1e-6) / 10000 : 0)
const hayVistaPrevia = computed(() => !!padre.value && !!hijo.value && (form.value.quantity ?? 0) > 0 && (form.value.units_per_parent ?? 0) > 0)
const sinStock = computed(() => !!padre.value && (form.value.quantity ?? 0) > stockDisponible.value)
const puedeFraccionar = computed(() => hayVistaPrevia.value && !sinStock.value)

function mensajeDeError(e: any, porDefecto: string) {
  const errs = e?.response?.data?.errors
  return errs ? Object.values(errs).flat().join(' · ') : (e?.response?.data?.message ?? e?.response?.data?.error ?? porDefecto)
}

async function load() {
  loading.value = true
  try {
    const [p, w] = await Promise.all([
      api.get('/products?company_id=' + company.activeId),
      api.get('/warehouses?company_id=' + company.activeId),
    ])
    products.value = p.data
    warehouses.value = w.data
    if (!form.value.warehouse_id) {
      form.value.warehouse_id = (w.data.find((x: any) => x.por_defecto) ?? w.data[0])?.id ?? null
    }
  } finally {
    loading.value = false
  }
}

// Si el usuario cambia rápido de producto, solo vale la última respuesta
let stockSeq = 0
async function cargarStock() {
  const seq = ++stockSeq
  stockBodegas.value = {}
  const productId = form.value.product_id
  if (!productId || !warehouses.value.length) return
  try {
    const res = await Promise.all(warehouses.value.map((w: any) =>
      api.get('/inventory/stock-bodega', { params: { product_id: productId, warehouse_id: w.id } })))
    if (seq !== stockSeq) return
    const m: Record<number, number> = {}
    warehouses.value.forEach((w: any, i: number) => { m[w.id] = num(res[i].data.stock_bodega) })
    stockBodegas.value = m
    // Si la bodega elegida no tiene este producto, se pasa a la primera que sí lo tiene
    if (!(m[form.value.warehouse_id ?? 0] > 0)) {
      const con = warehouses.value.find((w: any) => m[w.id] > 0)
      if (con) form.value.warehouse_id = con.id
    }
  } catch {
    // Sin el dato por bodega se usa el stock del producto; el servidor valida igual al fraccionar.
  }
}
watch(() => form.value.product_id, () => {
  if (form.value.child_product_id === form.value.product_id) form.value.child_product_id = null
  cargarStock()
})

async function fraccionar() {
  msg.value = null
  if (!form.value.product_id || !form.value.child_product_id || !(form.value.quantity && form.value.quantity > 0)
      || !(form.value.units_per_parent && form.value.units_per_parent > 0)) {
    msg.value = { type: 'warn', text: 'Completa el producto padre, el producto hijo, la cantidad y las unidades por padre.' }
    return
  }
  if (sinStock.value) {
    msg.value = { type: 'warn', text: `No hay stock suficiente: hay ${cant(stockDisponible.value)} y quieres fraccionar ${cant(form.value.quantity)}.` }
    return
  }
  submitting.value = true
  try {
    const res = await api.post('/fractionations', {
      company_id: company.activeId,
      product_id: form.value.product_id,
      child_product_id: form.value.child_product_id,
      quantity: form.value.quantity,
      units_per_parent: form.value.units_per_parent,
      warehouse_id: form.value.warehouse_id,
      motivo: form.value.motivo || null,
    })
    const d = res.data
    msg.value = {
      type: 'success',
      text: `Fraccionamiento ${d.numero} registrado: salieron ${cant(d.cantidad_origen)} ${d.producto_origen} y entraron ${cant(d.cantidad_destino)} ${d.producto_destino}. `
        + 'El valor del inventario no cambió.',
    }
    historial.value.unshift({
      numero: d.numero,
      fecha: new Date().toISOString().slice(0, 19).replace('T', ' '),
      origen: `${cant(d.cantidad_origen)} ${d.producto_origen}`,
      destino: `${cant(d.cantidad_destino)} ${d.producto_destino}`,
      costo: money(d.costo_unitario_destino),
      valor: money(d.valor_total),
    })
    form.value.quantity = 1
    form.value.units_per_parent = null
    form.value.motivo = ''
    await load()
    await cargarStock()
  } catch (e: any) {
    msg.value = { type: 'error', text: mensajeDeError(e, 'No se pudo fraccionar.') }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Inventario" :company="empresa" subtitle="Abrir una presentación grande y vender sus unidades sueltas" />
    <div style="padding: 20px; flex: 1; overflow: auto;">
      <Message severity="info" :closable="false" style="margin-bottom: 14px; max-width: 700px;">
        Fraccionar es abrir una presentación grande y vender sus unidades sueltas: por ejemplo, abrir una caja de 12 botellas.
        El <b>producto padre</b> (la caja, lo que compras) sale del inventario y el <b>producto hijo</b> (la botella, lo que vendes)
        entra con el costo repartido, así que el valor del inventario no cambia.
      </Message>
      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px; max-width: 700px;">{{ msg.text }}</Message>

      <div class="kvs-window" style="max-width: 700px;">
        <div class="kvs-window-title">Datos del Fraccionamiento</div>
        <div style="padding: 14px;">
          <fieldset class="kvs-fieldset">
            <legend>Producto padre (lo que compras)</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Producto padre:</label>
              <Select v-model="form.product_id" :options="elegibles" optionLabel="etiqueta" optionValue="id"
                      filter filterPlaceholder="Buscar por código o nombre..." placeholder="Elige el producto padre"
                      :loading="loading" class="kvs-in" emptyMessage="No hay productos con existencias para fraccionar" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Bodega:</label>
              <small v-if="!loading && !warehouses.length" class="kvs-in" style="color: #b45309;">
                Esta empresa aún no tiene bodegas. Crea una en Inventario → Bodegas.
              </small>
              <Select v-else v-model="form.warehouse_id" :options="bodegasOpc" optionLabel="etiqueta" optionValue="id"
                      placeholder="Elige la bodega" :loading="loading" class="kvs-in" />
            </div>
            <div v-if="padre" style="padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-bottom: 9px;">
              <span style="font-size: 12px; color: #64748b;">Stock disponible en la bodega:</span>
              <span style="margin-left: 8px; font-weight: 700;">{{ cant(stockDisponible) }} {{ padre.unidad_base ?? 'UND' }}</span>
              <span style="margin-left: 14px; font-size: 12px; color: #64748b;">Costo promedio:</span>
              <span style="margin-left: 8px; font-weight: 700;">{{ money(num(padre.costo_promedio)) }}</span>
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Cantidad a fraccionar:</label>
              <InputNumber v-model="form.quantity" :min="0.01" :maxFractionDigits="4" class="kvs-in" />
            </div>
          </fieldset>

          <fieldset class="kvs-fieldset" style="margin-top: 14px;">
            <legend>Producto hijo (lo que vendes suelto)</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Producto hijo:</label>
              <Select v-model="form.child_product_id" :options="hijos" optionLabel="etiqueta" optionValue="id"
                      filter filterPlaceholder="Buscar por código o nombre..." placeholder="Elige el producto hijo"
                      :loading="loading" class="kvs-in" emptyMessage="No hay otro producto disponible" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Unidades por padre:</label>
              <InputNumber v-model="form.units_per_parent" :min="0.0001" :maxFractionDigits="4" placeholder="Ej: 12" class="kvs-in" />
            </div>
            <div style="font-size: 12px; color: #64748b; margin: -2px 0 6px 0;">
              Cada padre contiene
              <b>{{ form.units_per_parent ? cant(form.units_per_parent) : 'X' }}</b>
              unidades {{ hijo ? 'de ' + hijo.codigo : 'del hijo' }}.
              No aparecen los servicios, los combos ni los productos con series.
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Motivo:</label>
              <InputText v-model="form.motivo" placeholder="Opcional" class="kvs-in" />
            </div>
          </fieldset>

          <div v-if="hayVistaPrevia" style="padding: 10px; background: #f0f7ff; border-radius: 6px; margin-top: 12px; border: 1px solid #bfdbfe;">
            <div style="font-size: 13px;">
              <b>{{ cant(form.quantity ?? 0) }} {{ padre?.descripcion }}</b> ({{ money(num(padre?.costo_promedio)) }})
              →
              <b>{{ cant(cantidadHijo) }} {{ hijo?.descripcion }}</b> ({{ money(costoHijo) }} c/u)
              · valor del inventario: <b>sin cambio</b>
            </div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Valor que pasa de un producto al otro: {{ money(valorTotal) }}</div>
            <div v-if="sinStock" style="font-size: 12px; color: #991b1b; margin-top: 4px;">
              No hay stock suficiente en la bodega: hay {{ cant(stockDisponible) }}.
            </div>
          </div>
        </div>
        <div class="kvs-footer">
          <Button label="Fraccionar" icon="pi pi-scissors" :loading="submitting" :disabled="!puedeFraccionar" @click="fraccionar" />
        </div>
      </div>

      <div v-if="historial.length" style="margin-top: 20px;">
        <h3 style="margin: 0 0 10px;">Fraccionamientos de esta sesión</h3>
        <DataTable :value="historial" size="small" stripedRows>
          <Column field="numero" header="Número" />
          <Column field="fecha" header="Fecha" />
          <Column field="origen" header="Salió" />
          <Column field="destino" header="Entró" />
          <Column field="costo" header="Costo c/u" />
          <Column field="valor" header="Valor" />
        </DataTable>
      </div>
    </div>
  </div>
</template>
