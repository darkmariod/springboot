<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()
const products = ref<any[]>([])
const contacts = ref<any[]>([])
const reservations = ref<any[]>([])
const loading = ref(false)
const submitting = ref(false)
const msg = ref<any>(null)

const form = ref({
  product_id: null as number | null,
  contact_id: null as number | null,
  quantity: 1,
  motivo: '',
  expires_at: '',
})

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

async function load() {
  loading.value = true
  const [pRes, cRes, rRes] = await Promise.all([
    api.get('/products?company_id=' + company.activeId),
    api.get('/contacts?company_id=' + company.activeId),
    api.get('/stock-reservations?company_id=' + company.activeId).catch(() => ({ data: [] })),
  ])
  products.value = pRes.data
  contacts.value = cRes.data
  reservations.value = rRes.data
  loading.value = false
}

async function crear() {
  msg.value = null
  if (!form.value.product_id || form.value.quantity <= 0) {
    msg.value = { type: 'warn', text: 'Seleccione artículo y cantidad.' }
    return
  }
  submitting.value = true
  try {
    const payload: any = {
      company_id: company.activeId,
      product_id: form.value.product_id,
      quantity: form.value.quantity,
    }
    if (form.value.contact_id) payload.contact_id = form.value.contact_id
    if (form.value.motivo) payload.motivo = form.value.motivo
    if (form.value.expires_at) payload.expires_at = form.value.expires_at

    const res = await api.post('/stock-reservations', payload)
    reservations.value.unshift(res.data)
    msg.value = { type: 'success', text: `Reserva #${res.data.id} creada: ${res.data.quantity} unidades.` }
    form.value = { product_id: null, contact_id: null, quantity: 1, motivo: '', expires_at: '' }
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.error ?? 'No se pudo crear la reserva.' }
  } finally {
    submitting.value = false
  }
}

async function cancelar(reservation: any) {
  try {
    await api.post(`/stock-reservations/${reservation.id}/cancel`)
    reservation.estado = 'cancelada'
    msg.value = { type: 'success', text: `Reserva #${reservation.id} cancelada.` }
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.error ?? 'No se pudo cancelar.' }
  }
}

onMounted(load)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Inventario" :company="{ ruc: company.activeId, razon_social: 'Reservas de Stock' }" subtitle="Reservar unidades para pedidos pendientes" />
    <div style="padding: 20px; flex: 1; overflow: auto;">
      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px;">{{ msg.text }}</Message>

      <div class="kvs-window" style="max-width: 700px; margin-bottom: 20px;">
        <div class="kvs-window-title">Nueva Reserva</div>
        <div style="padding: 14px;">
          <fieldset class="kvs-fieldset">
            <legend>Datos de la reserva</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Artículo:</label>
              <Select v-model="form.product_id" :options="products" optionLabel="descripcion" optionValue="id"
                      filter filterPlaceholder="Buscar..." placeholder="Seleccione artículo" :loading="loading" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Cliente:</label>
              <Select v-model="form.contact_id" :options="contacts" optionLabel="razon_social" optionValue="id"
                      filter placeholder="Opcional" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Cantidad:</label>
              <InputNumber v-model="form.quantity" :min="0.01" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Motivo:</label>
              <InputText v-model="form.motivo" placeholder="Pedido pendiente..." class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Vence:</label>
              <InputText v-model="form.expires_at" type="date" class="kvs-in" />
            </div>
          </fieldset>
        </div>
        <div class="kvs-footer">
          <Button label="Crear Reserva" icon="pi pi-bookmark" :loading="submitting" @click="crear" />
        </div>
      </div>

      <DataTable :value="reservations" :loading="loading" size="small" stripedRows :paginator="true" :rows="15">
        <Column field="id" header="#" style="width: 60px;" />
        <Column header="Producto"><template #body="{ data }">{{ data.product?.codigo }} — {{ data.product?.descripcion }}</template></Column>
        <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social ?? '—' }}</template></Column>
        <Column header="Cantidad" style="text-align: right;"><template #body="{ data }">{{ data.quantity }}</template></Column>
        <Column field="motivo" header="Motivo" />
        <Column header="Vence"><template #body="{ data }">{{ data.expires_at?.slice(0, 10) ?? '—' }}</template></Column>
        <Column header="Estado" style="width: 100px;">
          <template #body="{ data }">
            <Tag :value="data.estado" :severity="data.estado === 'activa' ? 'info' : data.estado === 'cumplida' ? 'success' : 'warn'" />
          </template>
        </Column>
        <Column header="" style="width: 60px;">
          <template #body="{ data }">
            <Button v-if="data.estado === 'activa'" icon="pi pi-times" text size="small" severity="danger" @click="cancelar(data)" />
          </template>
        </Column>
      </DataTable>
    </div>
  </div>
</template>
