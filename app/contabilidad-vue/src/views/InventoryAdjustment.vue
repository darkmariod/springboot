<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
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
const stockActual = computed(() => selectedProduct.value?.stock ?? 0)
const diferencia = computed(() => {
  if (form.value.stock_fisico == null || form.value.stock_fisico === stockActual.value) return 0
  return form.value.stock_fisico - stockActual.value
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

async function ajustar() {
  msg.value = null
  if (!form.value.product_id || !form.value.warehouse_id || form.value.stock_fisico == null) {
    msg.value = { type: 'warn', text: 'Complete todos los campos antes de ajustar.' }
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
    })
    msg.value = { type: 'success', text: 'Ajuste registrado correctamente.' }
    form.value = { product_id: null, warehouse_id: null, stock_fisico: null, motivo: '' }
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
            <span style="font-size: 12px; color: #64748b;">Stock actual:</span>
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
      </div>
      <div class="kvs-footer">
        <Button
          label="Ajustar"
          icon="pi pi-check"
          :loading="submitting"
          :disabled="!form.product_id || !form.warehouse_id || form.stock_fisico == null || !form.motivo"
          @click="ajustar"
        />
      </div>
    </div>
  </div>
</template>
