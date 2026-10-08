<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useQuickActionStore } from '../stores/quickAction'

const company = useCompanyStore()
const quickAction = useQuickActionStore()

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const loading = ref(false)
const submitting = ref(false)
const msg = ref<any>(null)

const form = ref<{
  product_id: number | null
  warehouse_id: number | null
  stock_fisico: number | null
  motivo: string
}>({
  product_id: null,
  warehouse_id: null,
  stock_fisico: null,
  motivo: '',
})

const selectedProduct = computed(() =>
  products.value.find((p) => p.id === form.value.product_id) ?? null,
)

// El ajuste se calcula contra el stock de la BODEGA elegida (igual que el servidor); mientras
// no se conozca, se muestra el stock total del producto.
const stockBodega = ref<number | null>(null)
const stockActual = computed(() => stockBodega.value ?? Number(selectedProduct.value?.stock ?? 0))
const diferencia = computed(() => {
  if (form.value.stock_fisico == null || form.value.stock_fisico === stockActual.value) return 0
  return Math.round((form.value.stock_fisico - stockActual.value) * 10000) / 10000
})

// Productos con series: el faltante da de baja series que existen; el sobrante crea series nuevas.
// La cantidad de series debe coincidir con la diferencia, para que stock y series no se separen.
const seriesDisponibles = ref<string[]>([])
const seriesElegidas = ref<string[]>([])
const seriesNuevas = ref('')
const manejaSeries = computed(() => !!selectedProduct.value?.maneja_series)
const necesitaSeries = computed(
  () => manejaSeries.value && form.value.stock_fisico != null && diferencia.value !== 0,
)
const cantidadSeries = computed(() => Math.abs(diferencia.value))
const esEntero = computed(() => Number.isInteger(cantidadSeries.value))
const listaNuevas = computed(() =>
  seriesNuevas.value
    .split(/[\n,;]+/)
    .map((s) => s.trim())
    .filter(Boolean),
)
const repetidasNuevas = computed(() => [
  ...new Set(listaNuevas.value.filter((s, i) => listaNuevas.value.indexOf(s) !== i)),
])
const seriesCompletas = computed(() => {
  if (!necesitaSeries.value) return true
  if (!esEntero.value) return false
  if (diferencia.value < 0) return seriesElegidas.value.length === cantidadSeries.value
  return listaNuevas.value.length === cantidadSeries.value && repetidasNuevas.value.length === 0
})
const seriesPendientes = computed(() => {
  const hechas = diferencia.value < 0 ? seriesElegidas.value.length : listaNuevas.value.length
  return `${hechas} de ${cantidadSeries.value}`
})

// Si el usuario cambia rápido de producto o bodega, solo vale la última respuesta.
let contextoSeq = 0
async function cargarContexto() {
  const seq = ++contextoSeq
  stockBodega.value = null
  seriesDisponibles.value = []
  seriesElegidas.value = []
  seriesNuevas.value = ''
  const { product_id, warehouse_id } = form.value
  if (!product_id || !warehouse_id) return
  try {
    const [stock, series] = await Promise.all([
      api.get('/inventory/stock-bodega', { params: { product_id, warehouse_id } }),
      // Las series se llevan por producto (no por bodega): se ofrecen las que están disponibles.
      manejaSeries.value
        ? api.get('/series', { params: { company_id: company.activeId, product_id, estado: 'disponible' } })
        : Promise.resolve({ data: [] as any[] }),
    ])
    if (seq !== contextoSeq) return
    stockBodega.value = Number(stock.data.stock_bodega ?? 0)
    seriesDisponibles.value = (series.data as any[]).map((s) => s.serie)
  } catch {
    // Sin el dato de la bodega se usa el stock del producto; el servidor valida igual al ajustar.
  }
}
watch(() => [form.value.product_id, form.value.warehouse_id], cargarContexto)
// Si la diferencia cambia, las series ya elegidas no pueden pasarse de lo que se necesita.
watch(diferencia, () => {
  seriesElegidas.value = diferencia.value < 0 ? seriesElegidas.value.slice(0, cantidadSeries.value) : []
})

async function load() {
  loading.value = true
  const [pRes, wRes] = await Promise.all([
    api.get('/products?company_id=' + company.activeId),
    api.get('/warehouses?company_id=' + company.activeId),
  ])
  products.value = pRes.data
  warehouses.value = wRes.data
  // Llegó desde "Ajustar stock" en Productos: ese producto ya viene elegido.
  const pendiente = quickAction.consumeAdjust()
  if (pendiente) form.value.product_id = pendiente
  loading.value = false
}

async function ajustar() {
  msg.value = null
  if (!form.value.product_id || !form.value.warehouse_id || form.value.stock_fisico == null) {
    msg.value = { type: 'warn', text: 'Complete todos los campos antes de ajustar.' }
    return
  }
  if (!seriesCompletas.value) {
    msg.value = {
      type: 'warn',
      text: esEntero.value
        ? `Este producto maneja series: indique exactamente ${cantidadSeries.value} serie(s).`
        : 'Este producto maneja series: la diferencia debe ser un número entero de unidades.',
    }
    return
  }
  submitting.value = true
  try {
    await api.post('/inventory/ajuste', {
      company_id: company.activeId,
      product_id: form.value.product_id,
      warehouse_id: form.value.warehouse_id,
      stock_fisico: form.value.stock_fisico,
      motivo: form.value.motivo,
      ...(necesitaSeries.value
        ? { series: diferencia.value < 0 ? seriesElegidas.value : listaNuevas.value }
        : {}),
    })
    msg.value = { type: 'success', text: 'Ajuste registrado correctamente.' }
    form.value = { product_id: null, warehouse_id: null, stock_fisico: null, motivo: '' }
    // El stock cambió: se refresca la lista para no mostrar el valor anterior.
    products.value = (await api.get('/products?company_id=' + company.activeId)).data
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'No se pudo registrar el ajuste.' }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <h2 style="margin: 0 0 14px 0;">Ajuste de inventario</h2>

    <Message severity="info" :closable="false" style="margin-bottom: 14px;">
      Registre el <b>stock físico</b> contado en bodega. El sistema calculará la diferencia con el stock actual
      y registrará el movimiento con el motivo indicado.
    </Message>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px;">
      {{ msg.text }}
    </Message>

    <div class="kvs-window" style="max-width: 640px;">
      <div class="kvs-window-title">Datos del ajuste</div>
      <div style="padding: 14px;">
        <fieldset class="kvs-fieldset">
          <legend>Producto y bodega</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Producto:</label>
            <Select
              v-model="form.product_id"
              :options="products"
              optionLabel="descripcion"
              optionValue="id"
              filter
              filterPlaceholder="Buscar producto..."
              placeholder="Seleccione producto"
              :loading="loading"
              class="kvs-in"
            />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Bodega:</label>
            <Select
              v-model="form.warehouse_id"
              :options="warehouses"
              optionLabel="nombre"
              optionValue="id"
              placeholder="Seleccione bodega"
              :loading="loading"
              class="kvs-in"
            />
          </div>
          <div v-if="form.product_id" style="padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-bottom: 9px;">
            <span style="font-size: 12px; color: #64748b;">
              {{ stockBodega != null ? 'Stock actual en la bodega:' : 'Stock actual:' }}
            </span>
            <span style="margin-left: 8px; font-weight: 700; font-size: 15px;">
              {{ stockActual.toLocaleString() }}
            </span>
          </div>
        </fieldset>

        <fieldset class="kvs-fieldset" style="margin-top: 14px;">
          <legend>Conteo físico</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Stock físico:</label>
            <InputNumber v-model="form.stock_fisico" :min="0" class="kvs-in" />
          </div>
          <div v-if="diferencia !== 0 && form.stock_fisico != null"
            style="padding: 8px 10px; border-radius: 6px; margin-bottom: 9px;"
            :style="{ background: diferencia > 0 ? '#dcfce7' : '#fee2e2', color: diferencia > 0 ? '#166534' : '#991b1b' }">
            <span style="font-size: 12px;">Diferencia:</span>
            <span style="margin-left: 8px; font-weight: 700;">
              {{ diferencia > 0 ? '+' : '' }}{{ diferencia.toLocaleString() }}
            </span>
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Motivo:</label>
            <InputText v-model="form.motivo" placeholder="Ej: Conteo de mercadería, rotura, muestreo..." class="kvs-in" />
          </div>
        </fieldset>

        <fieldset v-if="necesitaSeries" class="kvs-fieldset" style="margin-top: 14px;">
          <legend>Series</legend>
          <Message v-if="!esEntero" severity="warn" :closable="false" style="margin-bottom: 9px;">
            Este producto maneja series: la diferencia debe ser un número entero de unidades.
          </Message>
          <template v-else-if="diferencia < 0">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Series que faltan:</label>
              <MultiSelect
                v-model="seriesElegidas"
                :options="seriesDisponibles"
                filter
                display="chip"
                :selectionLimit="cantidadSeries"
                :maxSelectedLabels="3"
                placeholder="Seleccione las series faltantes"
                class="kvs-in"
              />
            </div>
            <div style="font-size: 12px; margin-bottom: 9px;"
              :style="{ color: seriesCompletas ? '#166534' : '#b45309' }">
              Seleccione exactamente {{ cantidadSeries }} serie(s) disponible(s): {{ seriesPendientes }}.
              <span v-if="seriesDisponibles.length < cantidadSeries" style="color: #991b1b;">
                Solo hay {{ seriesDisponibles.length }} serie(s) disponible(s) de este producto.
              </span>
            </div>
          </template>
          <template v-else>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Series nuevas:</label>
              <Textarea
                v-model="seriesNuevas"
                rows="4"
                placeholder="Una serie por línea"
                class="kvs-in"
                style="width: 100%;"
              />
            </div>
            <div style="font-size: 12px; margin-bottom: 9px;"
              :style="{ color: seriesCompletas ? '#166534' : '#b45309' }">
              Ingrese exactamente {{ cantidadSeries }} serie(s) nueva(s), una por línea: {{ seriesPendientes }}.
              <span v-if="repetidasNuevas.length" style="color: #991b1b;">
                Hay series repetidas: {{ repetidasNuevas.join(', ') }}.
              </span>
            </div>
          </template>
        </fieldset>
      </div>
      <div class="kvs-footer">
        <Button
          label="Ajustar"
          icon="pi pi-check"
          :loading="submitting"
          :disabled="!form.product_id || !form.warehouse_id || form.stock_fisico == null || !form.motivo || !seriesCompletas"
          @click="ajustar"
        />
      </div>
    </div>
  </div>
</template>
