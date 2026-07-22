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

const form = ref({
  product_from_id: null as number | null,
  product_to_id: null as number | null,
  quantity_from: 0,
  quantity_to: 0,
  motivo: '',
})

const productFrom = computed(() => products.value.find(p => p.id === form.value.product_from_id) ?? null)
const productTo = computed(() => products.value.find(p => p.id === form.value.product_to_id) ?? null)
const stockFrom = computed(() => productFrom.value?.stock ?? 0)
const stockTo = computed(() => productTo.value?.stock ?? 0)

async function load() {
  loading.value = true
  products.value = (await api.get('/products?company_id=' + company.activeId)).data
  loading.value = false
}

async function convertir() {
  msg.value = null
  if (!form.value.product_from_id || !form.value.product_to_id || form.value.quantity_from <= 0 || form.value.quantity_to <= 0) {
    msg.value = { type: 'warn', text: 'Complete todos los campos.' }
    return
  }
  submitting.value = true
  try {
    const res = await api.post('/conversions', {
      company_id: company.activeId,
      ...form.value,
    })
    msg.value = { type: 'success', text: `Conversión exitosa: ${res.data.producto_origen} → ${res.data.producto_destino}` }
    historial.value.unshift({
      fecha: new Date().toISOString().slice(0, 19),
      origen: res.data.producto_origen,
      destino: res.data.producto_destino,
      cant_origen: res.data.cantidad_origen,
      cant_destino: res.data.cantidad_destino,
    })
    form.value = { product_from_id: null, product_to_id: null, quantity_from: 0, quantity_to: 0, motivo: '' }
    await load()
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.error ?? 'No se pudo realizar la conversión.' }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Inventario" :company="{ ruc: company.activeId, razon_social: 'Conversión de Artículos' }" subtitle="Conversión / Reversión de Unidades" />
    <div style="padding: 20px; flex: 1; overflow: auto;">

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px;">{{ msg.text }}</Message>

    <div class="kvs-window" style="max-width: 700px;">
      <div class="kvs-window-title">Datos de la Conversión</div>
      <div style="padding: 14px;">
        <fieldset class="kvs-fieldset">
          <legend>Artículo Origen</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Artículo:</label>
            <Select v-model="form.product_from_id" :options="products" optionLabel="descripcion" optionValue="id"
                    filter filterPlaceholder="Buscar..." placeholder="Seleccione artículo" :loading="loading" class="kvs-in" />
          </div>
          <div v-if="productFrom" style="padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-bottom: 9px;">
            <span style="font-size: 12px; color: #64748b;">Stock actual:</span>
            <span style="margin-left: 8px; font-weight: 700;">{{ stockFrom }}</span>
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Cantidad a convertir:</label>
            <InputNumber v-model="form.quantity_from" :min="0.01" :max="stockFrom" class="kvs-in" />
          </div>
        </fieldset>

        <fieldset class="kvs-fieldset" style="margin-top: 14px;">
          <legend>Artículo Destino</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Artículo:</label>
            <Select v-model="form.product_to_id" :options="products.filter(p => p.id !== form.product_from_id)"
                    optionLabel="descripcion" optionValue="id" filter filterPlaceholder="Buscar..."
                    placeholder="Seleccione artículo" :loading="loading" class="kvs-in" />
          </div>
          <div v-if="productTo" style="padding: 8px 10px; background: #f1f5f9; border-radius: 6px; margin-bottom: 9px;">
            <span style="font-size: 12px; color: #64748b;">Stock actual:</span>
            <span style="margin-left: 8px; font-weight: 700;">{{ stockTo }}</span>
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Cantidad resultante:</label>
            <InputNumber v-model="form.quantity_to" :min="0.01" class="kvs-in" />
          </div>
        </fieldset>

        <fieldset class="kvs-fieldset" style="margin-top: 14px;">
          <legend>Observación</legend>
          <div class="kvs-row">
            <label class="kvs-lbl">Motivo:</label>
            <InputText v-model="form.motivo" placeholder="Opcional" class="kvs-in" />
          </div>
        </fieldset>
      </div>
      <div class="kvs-footer">
        <Button label="Convertir" icon="pi pi-refresh" :loading="submitting" @click="convertir" />
      </div>
    </div>

    <div v-if="historial.length" style="margin-top: 20px;">
      <h3 style="margin: 0 0 10px;">Historial de Conversiones</h3>
      <DataTable :value="historial" size="small" stripedRows>
        <Column header="Fecha"><template #body="{ data }">{{ data.fecha }}</template></Column>
        <Column field="origen" header="Origen" />
        <Column header="Cant. Origen"><template #body="{ data }">{{ data.cant_origen }}</template></Column>
        <Column field="destino" header="Destino" />
        <Column header="Cant. Destino"><template #body="{ data }">{{ data.cant_destino }}</template></Column>
      </DataTable>
    </div>
    </div>
  </div>
</template>
