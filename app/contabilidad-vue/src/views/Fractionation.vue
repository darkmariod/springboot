<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
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
const loading = ref(false)
const submitting = ref(false)
const msg = ref<any>(null)
const historial = ref<any[]>([])

const fractionUnits = [
  { label: 'Unidad (UND)', value: 'UND' },
  { label: 'Docena (DOC)', value: 'DOC' },
  { label: 'Media docena (MD)', value: 'MD' },
  { label: 'Paquete (PAQ)', value: 'PAQ' },
  { label: 'Kilo (KG)', value: 'KG' },
  { label: 'Gramo (GR)', value: 'GR' },
  { label: 'Litro (LT)', value: 'LT' },
  { label: 'Metro (MT)', value: 'MT' },
  { label: 'Personalizado', value: '_custom' },
]

const form = ref({
  product_id: null as number | null,
  quantity: 1,
  fraction_unit: 'UND',
  fraction_unit_custom: '',
  fraction_qty: 1,
})

const selectedProduct = computed(() => products.value.find(p => p.id === form.value.product_id) ?? null)
const currentUnit = computed(() => form.value.fraction_unit === '_custom' ? form.value.fraction_unit_custom : form.value.fraction_unit)
const resultQty = computed(() => form.value.quantity * form.value.fraction_qty)

async function load() {
  loading.value = true
  products.value = (await api.get('/products?company_id=' + company.activeId)).data
  loading.value = false
}

async function fraccionar() {
  msg.value = null
  if (!form.value.product_id || form.value.quantity <= 0 || form.value.fraction_qty <= 0) {
    msg.value = { type: 'warn', text: 'Complete todos los campos.' }
    return
  }
  if (form.value.fraction_unit === '_custom' && !form.value.fraction_unit_custom) {
    msg.value = { type: 'warn', text: 'Ingrese la unidad personalizada.' }
    return
  }
  submitting.value = true
  try {
    const res = await api.post('/fractionations', {
      company_id: company.activeId,
      product_id: form.value.product_id,
      quantity: form.value.quantity,
      fraction_unit: currentUnit.value,
      fraction_qty: form.value.fraction_qty,
    })
    msg.value = { type: 'success', text: `${res.data.cantidad_origen} ${res.data.unidad_origen} → ${res.data.cantidad_destino} ${res.data.unidad_destino}` }
    historial.value.unshift({
      fecha: new Date().toISOString().slice(0, 19),
      producto: res.data.producto,
      origen: `${res.data.cantidad_origen} ${res.data.unidad_origen}`,
      destino: `${res.data.cantidad_destino} ${res.data.unidad_destino}`,
    })
    form.value = { product_id: null, quantity: 1, fraction_unit: 'UND', fraction_unit_custom: '', fraction_qty: 1 }
    await load()
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.error ?? 'No se pudo fraccionar.' }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Inventario" :company="{ ruc: company.activeId, razon_social: 'Fraccionamiento' }" subtitle="Dividir unidades padre en unidades hijo" />
    <div style="padding: 20px; flex: 1; overflow: auto;">
      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px;">{{ msg.text }}</Message>

      <div class="kvs-window" style="max-width: 700px;">
        <div class="kvs-window-title">Datos del Fraccionamiento</div>
        <div style="padding: 14px;">
          <fieldset class="kvs-fieldset">
            <legend>Artículo a fraccionar</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Artículo:</label>
              <Select v-model="form.product_id" :options="products" optionLabel="descripcion" optionValue="id"
                      filter filterPlaceholder="Buscar..." placeholder="Seleccione artículo" :loading="loading" class="kvs-in" />
            </div>
            <div v-if="selectedProduct" style="padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-bottom: 9px;">
              <span style="font-size: 12px; color: #64748b;">Stock actual:</span>
              <span style="margin-left: 8px; font-weight: 700;">{{ selectedProduct.stock }} {{ selectedProduct.unidad_base ?? 'UND' }}</span>
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Cantidad a fraccionar:</label>
              <InputNumber v-model="form.quantity" :min="0.01" :max="selectedProduct?.stock ?? 9999" class="kvs-in" />
            </div>
          </fieldset>

          <fieldset class="kvs-fieldset" style="margin-top: 14px;">
            <legend>Unidad resultante</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Unidad destino:</label>
              <Select v-model="form.fraction_unit" :options="fractionUnits" optionLabel="label" optionValue="value" class="kvs-in" />
            </div>
            <div v-if="form.fraction_unit === '_custom'" class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Nombre unidad:</label>
              <InputText v-model="form.fraction_unit_custom" placeholder="Ej: pieza, blister..." class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Unidades por padre:</label>
              <InputNumber v-model="form.fraction_qty" :min="1" :max="9999" class="kvs-in" />
            </div>
          </fieldset>

          <div v-if="form.quantity > 0 && form.fraction_qty > 0" style="padding: 10px; background: #f0f7ff; border-radius: 6px; margin-top: 12px; border: 1px solid #bfdbfe;">
            <span style="font-size: 13px;">Resultado: <b>{{ form.quantity }} padre</b> → <b>{{ resultQty }} {{ currentUnit }}</b></span>
          </div>
        </div>
        <div class="kvs-footer">
          <Button label="Fraccionar" icon="pi pi-scissors" :loading="submitting" @click="fraccionar" />
        </div>
      </div>

      <div v-if="historial.length" style="margin-top: 20px;">
        <h3 style="margin: 0 0 10px;">Historial de Fraccionamientos</h3>
        <DataTable :value="historial" size="small" stripedRows>
          <Column header="Fecha"><template #body="{ data }">{{ data.fecha }}</template></Column>
          <Column field="producto" header="Producto" />
          <Column field="origen" header="Origen" />
          <Column field="destino" header="Destino" />
        </DataTable>
      </div>
    </div>
  </div>
</template>
