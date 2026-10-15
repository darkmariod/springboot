<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import Card from 'primevue/card'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()

const products = ref<any[]>([])
const warehouses = ref<any[]>([])
const loading = ref(false)
const submitting = ref(false)
const msg = ref<any>(null)

const form = ref<{
  product_id: number | null
  warehouse_origen_id: number | null
  warehouse_destino_id: number | null
  cantidad: number | null
  motivo: string
}>({
  product_id: null,
  warehouse_origen_id: null,
  warehouse_destino_id: null,
  cantidad: null,
  motivo: '',
})

const selectedProduct = computed(() =>
  products.value.find((p) => p.id === form.value.product_id) ?? null,
)
// Stock del producto en la bodega de ORIGEN (el que valida el servidor); mientras no se conozca, el total del producto.
const stockBodega = ref<number | null>(null)
const stockOrigen = computed(() => stockBodega.value ?? selectedProduct.value?.stock ?? 0)

// Producto con series: se eligen las series concretas que viajan (tantas como unidades), entre las disponibles en la bodega de origen.
const manejaSeries = computed(() => !!selectedProduct.value?.maneja_series)
const seriesDisponibles = ref<string[]>([])
const seriesElegidas = ref<string[]>([])
const cantidadEntera = computed(() => form.value.cantidad != null && Number.isInteger(form.value.cantidad))
const seriesCompletas = computed(
  () => !manejaSeries.value || (cantidadEntera.value && seriesElegidas.value.length === form.value.cantidad),
)

// Si cambia rápido de producto o de bodega, solo vale la última respuesta.
let contextoSeq = 0
async function cargarContexto() {
  const seq = ++contextoSeq
  stockBodega.value = null
  seriesDisponibles.value = []
  seriesElegidas.value = []
  const { product_id, warehouse_origen_id } = form.value
  if (!product_id || !warehouse_origen_id) return
  try {
    const [stock, series] = await Promise.all([
      api.get('/inventory/stock-bodega', { params: { product_id, warehouse_id: warehouse_origen_id } }),
      manejaSeries.value
        ? api.get('/series', { params: { company_id: company.activeId, product_id, estado: 'disponible', warehouse_id: warehouse_origen_id } })
        : Promise.resolve({ data: [] as any[] }),
    ])
    if (seq !== contextoSeq) return
    stockBodega.value = Number(stock.data.stock_bodega ?? 0)
    seriesDisponibles.value = (series.data as any[]).map((s) => s.serie)
  } catch {
    // Sin el dato de la bodega se usa el stock del producto; el servidor valida igual al transferir.
  }
}
watch(() => [form.value.product_id, form.value.warehouse_origen_id], cargarContexto)
// Si baja la cantidad, las series elegidas no pueden pasarse
watch(() => form.value.cantidad, (c) => {
  if (c != null && seriesElegidas.value.length > c) seriesElegidas.value = seriesElegidas.value.slice(0, c)
})

async function load() {
  loading.value = true
  const [pRes, wRes] = await Promise.all([
    api.get('/products?company_id=' + company.activeId),
    api.get('/warehouses?company_id=' + company.activeId),
  ])
  products.value = pRes.data
  warehouses.value = wRes.data
  loading.value = false
}

async function transferir() {
  msg.value = null
  if (!form.value.product_id || !form.value.warehouse_origen_id || !form.value.warehouse_destino_id || !form.value.cantidad) {
    msg.value = { type: 'warn', text: 'Complete todos los campos antes de transferir.' }
    return
  }
  if (form.value.warehouse_origen_id === form.value.warehouse_destino_id) {
    msg.value = { type: 'warn', text: 'La bodega de origen y destino no pueden ser la misma.' }
    return
  }
  if (!seriesCompletas.value) {
    msg.value = {
      type: 'warn',
      text: cantidadEntera.value
        ? `Este producto maneja series: elige exactamente ${form.value.cantidad} serie(s) de la bodega de origen.`
        : 'Este producto maneja series: la cantidad debe ser un número entero de unidades.',
    }
    return
  }
  submitting.value = true
  try {
    await api.post('/inventory/transferencia', {
      company_id: company.activeId,
      product_id: form.value.product_id,
      warehouse_origen_id: form.value.warehouse_origen_id,
      warehouse_destino_id: form.value.warehouse_destino_id,
      cantidad: form.value.cantidad,
      motivo: form.value.motivo,
      ...(manejaSeries.value ? { series: seriesElegidas.value } : {}),
    })
    msg.value = { type: 'success', text: 'Transferencia registrada correctamente.' }
    form.value = { product_id: null, warehouse_origen_id: null, warehouse_destino_id: null, cantidad: null, motivo: '' }
    // El stock cambió: se refresca la lista para no mostrar el valor anterior.
    products.value = (await api.get('/products?company_id=' + company.activeId)).data
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? err.response?.data?.error ?? 'No se pudo registrar la transferencia.' }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <h2 style="margin: 0 0 14px 0;">Transferencia entre bodegas</h2>

    <Message severity="info" :closable="false" style="margin-bottom: 14px;">
      Registre el movimiento de un producto de una bodega a otra. La cantidad transferida se descontará
      del stock de la bodega de origen y se sumará al stock de la bodega de destino.
    </Message>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px;">
      {{ msg.text }}
    </Message>

    <Card style="max-width: 600px;">
      <template #title>Datos de la transferencia</template>
      <template #content>
        <div style="display: flex; flex-direction: column; gap: 14px;">
          <label style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; color: #94a3b8;">Producto</span>
            <Select
              v-model="form.product_id"
              :options="products"
              optionLabel="descripcion"
              optionValue="id"
              filter
              filterPlaceholder="Buscar producto..."
              placeholder="Seleccione producto"
              :loading="loading"
              fluid
            />
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; color: #94a3b8;">Bodega de origen</span>
            <Select
              v-model="form.warehouse_origen_id"
              :options="warehouses.filter((w) => w.id !== form.warehouse_destino_id)"
              optionLabel="nombre"
              optionValue="id"
              placeholder="Seleccione bodega origen"
              :loading="loading"
              fluid
            />
          </label>

          <label style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; color: #94a3b8;">Bodega de destino</span>
            <Select
              v-model="form.warehouse_destino_id"
              :options="warehouses.filter((w) => w.id !== form.warehouse_origen_id)"
              optionLabel="nombre"
              optionValue="id"
              placeholder="Seleccione bodega destino"
              :loading="loading"
              fluid
            />
          </label>

          <div v-if="form.product_id" style="padding: 10px; background: #f1f5f9; border-radius: 6px;">
            <span style="font-size: 12px; color: #94a3b8;">
              {{ stockBodega != null ? 'Stock en la bodega de origen:' : 'Stock actual del producto:' }}
            </span>
            <span style="margin-left: 8px; font-weight: 700; font-size: 16px;">
              {{ stockOrigen.toLocaleString() }}
            </span>
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; color: #94a3b8;">Cantidad a transferir</span>
            <InputNumber v-model="form.cantidad" :min="1" fluid />
          </label>

          <div v-if="manejaSeries && form.warehouse_origen_id" style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; color: #94a3b8;">Series que se transfieren</span>
            <MultiSelect
              v-model="seriesElegidas"
              :options="seriesDisponibles"
              filter
              display="chip"
              :selectionLimit="form.cantidad ?? 0"
              :maxSelectedLabels="3"
              placeholder="Seleccione las series de la bodega de origen"
              :disabled="!form.cantidad"
              fluid
            />
            <div v-if="form.cantidad" style="font-size: 12px;"
              :style="{ color: seriesCompletas ? '#166534' : '#b45309' }">
              <template v-if="!cantidadEntera">La cantidad debe ser un número entero de unidades.</template>
              <template v-else>
                Elige exactamente {{ form.cantidad }} serie(s) disponible(s) en esta bodega: {{ seriesElegidas.length }} de {{ form.cantidad }}.
                <span v-if="seriesDisponibles.length < form.cantidad" style="color: #991b1b;">
                  Solo hay {{ seriesDisponibles.length }} serie(s) disponible(s) en la bodega de origen.
                </span>
              </template>
            </div>
            <div v-else style="font-size: 12px; color: #64748b;">
              Este producto maneja series: escribe la cantidad y elige qué series viajan.
            </div>
          </div>

          <label style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; color: #94a3b8;">Motivo</span>
            <InputText v-model="form.motivo" placeholder="Ej: Reabastecimiento, rotación de bodega..." fluid />
          </label>

          <div style="text-align: right;">
            <Button
              label="Transferir"
              icon="pi pi-arrow-right-arrow-left"
              :loading="submitting"
              :disabled="!form.product_id || !form.warehouse_origen_id || !form.warehouse_destino_id || !form.cantidad || !form.motivo || !seriesCompletas"
              @click="transferir"
            />
          </div>
        </div>
      </template>
    </Card>
  </div>
</template>
