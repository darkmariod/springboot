<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()
const contacts = ref<any[]>([])
const products = ref<any[]>([])
const loading = ref(false)
const submitting = ref(false)
const msg = ref<any>(null)
const results = ref<any[] | null>(null)

const formasPago = [
  { label: 'Efectivo', value: 'efectivo' },
  { label: 'Transferencia', value: 'transferencia' },
  { label: 'Tarjeta', value: 'tarjeta' },
  { label: 'Crédito', value: 'credito' },
]

interface InvoiceLine {
  codigo_principal: string
  descripcion: string
  cantidad: number
  precio_unitario: number
  tarifa: number
}

interface InvoiceDraft {
  contact_id: number | null
  forma_pago: string
  items: InvoiceLine[]
}

const invoices = ref<InvoiceDraft[]>([])
const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

function addInvoice() {
  invoices.value.push({
    contact_id: null,
    forma_pago: 'efectivo',
    items: [{ codigo_principal: '', descripcion: '', cantidad: 1, precio_unitario: 0, tarifa: 15 }],
  })
}

function removeInvoice(idx: number) {
  invoices.value.splice(idx, 1)
}

function addItem(invIdx: number) {
  invoices.value[invIdx].items.push({ codigo_principal: '', descripcion: '', cantidad: 1, precio_unitario: 0, tarifa: 15 })
}

function removeItem(invIdx: number, itemIdx: number) {
  invoices.value[invIdx].items.splice(itemIdx, 1)
}

function selectProduct(item: InvoiceLine, product: any) {
  item.codigo_principal = product.codigo
  item.descripcion = product.descripcion
  item.precio_unitario = Number(product.precio)
}

function invoiceTotal(inv: InvoiceDraft) {
  return inv.items.reduce((s, i) => {
    const base = i.cantidad * i.precio_unitario
    return s + base + (base * i.tarifa) / 100
  }, 0)
}

const batchTotal = computed(() => invoices.value.reduce((s, inv) => s + invoiceTotal(inv), 0))

async function load() {
  loading.value = true
  const [cRes, pRes] = await Promise.all([
    api.get('/contacts?company_id=' + company.activeId),
    api.get('/products?company_id=' + company.activeId),
  ])
  contacts.value = cRes.data
  products.value = pRes.data
  loading.value = false
}

async function procesar() {
  msg.value = null
  results.value = null

  const valid = invoices.value.filter(inv => inv.contact_id && inv.items.length > 0)
  if (!valid.length) {
    msg.value = { type: 'warn', text: 'Agregue al menos una factura con cliente y artículos.' }
    return
  }

  submitting.value = true
  try {
    const payload = {
      company_id: company.activeId,
      invoices: valid.map(inv => ({
        contact_id: inv.contact_id,
        forma_pago: inv.forma_pago,
        items: inv.items.filter(i => i.codigo_principal),
      })),
    }
    const res = await api.post('/invoices/masiva', payload)
    results.value = res.data.results
    msg.value = {
      type: res.data.fallas > 0 ? 'warn' : 'success',
      text: `Procesadas: ${res.data.exitos} éxitos, ${res.data.fallas} fallas de ${res.data.total} totales.`,
    }
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.message ?? 'Error al procesar lote.' }
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Ventas" :company="{ ruc: company.activeId, razon_social: 'Facturación Masiva' }" subtitle="Emisión de lotes de facturas" />
    <div style="padding: 20px; flex: 1; overflow: auto;">

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px;">{{ msg.text }}</Message>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
      <span style="font-size: 13px; color: #64748b;">{{ invoices.length }} facturas · Total lote: <b>{{ money(batchTotal) }}</b></span>
      <Button label="Agregar Factura" icon="pi pi-plus" size="small" @click="addInvoice" />
    </div>

    <div v-for="(inv, idx) in invoices" :key="idx" class="kvs-window" style="margin-bottom: 14px;">
      <div class="kvs-window-title" style="display: flex; justify-content: space-between; align-items: center;">
        <span>Factura #{{ idx + 1 }}</span>
        <Button icon="pi pi-trash" text size="small" severity="danger" @click="removeInvoice(idx)" />
      </div>
      <div style="padding: 12px;">
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Cliente:</label>
          <Select v-model="inv.contact_id" :options="contacts" optionLabel="razon_social" optionValue="id"
                  filter placeholder="Seleccione cliente" class="kvs-in" />
          <label class="kvs-lbl" style="margin-left: 12px;">Forma pago:</label>
          <Select v-model="inv.forma_pago" :options="formasPago" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>

        <table class="kvs-table" style="margin-top: 10px;">
          <thead>
            <tr>
              <th>Código</th>
              <th>Descripción</th>
              <th style="width: 80px;">Cant.</th>
              <th style="width: 110px;">P. Unitario</th>
              <th style="width: 60px;">IVA %</th>
              <th style="width: 110px;">Subtotal</th>
              <th style="width: 40px;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, iIdx) in inv.items" :key="iIdx">
              <td>
                <Select :modelValue="item.codigo_principal"
                        @update:modelValue="(val) => { const p = products.find(pr => pr.codigo === val); if (p) selectProduct(item, p) }"
                        :options="products" optionLabel="codigo" optionValue="codigo"
                        filter filterPlaceholder="Buscar" placeholder="Código" style="width: 100%;" />
              </td>
              <td>{{ item.descripcion }}</td>
              <td><InputNumber v-model="item.cantidad" :min="0.01" :useGrouping="false" style="width: 100%;" /></td>
              <td><InputNumber v-model="item.precio_unitario" mode="currency" currency="USD" style="width: 100%;" /></td>
              <td><InputNumber v-model="item.tarifa" :useGrouping="false" style="width: 100%;" /></td>
              <td class="der">{{ money((item.cantidad * item.precio_unitario) * (1 + item.tarifa / 100)) }}</td>
              <td><Button icon="pi pi-times" text size="small" severity="danger" @click="removeItem(idx, iIdx)" /></td>
            </tr>
          </tbody>
        </table>
        <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center;">
          <Button icon="pi pi-plus" label="Agregar línea" size="small" text @click="addItem(idx)" />
          <span style="font-size: 13px; font-weight: 600;">Total: {{ money(invoiceTotal(inv)) }}</span>
        </div>
      </div>
    </div>

    <div v-if="!invoices.length" style="color: #94a3b8; text-align: center; padding: 40px;">
      Presione <b>Agregar Factura</b> para iniciar un lote.
    </div>

    <div v-if="invoices.length" style="display: flex; justify-content: flex-end; margin-top: 14px;">
      <Button label="Procesar Lote" icon="pi pi-send" :loading="submitting" :disabled="submitting" @click="procesar" />
    </div>

    <!-- Resultados -->
    <div v-if="results" style="margin-top: 20px;">
      <h3 style="margin: 0 0 10px;">Resultados</h3>
      <DataTable :value="results" size="small" stripedRows>
        <Column field="index" header="#" style="width: 50px;" />
        <Column header="Estado">
          <template #body="{ data }">
            <Tag :value="data.success ? 'Éxito' : 'Falla'" :severity="data.success ? 'success' : 'danger'" />
          </template>
        </Column>
        <Column header="Nº Factura"><template #body="{ data }">{{ data.numero ?? '—' }}</template></Column>
        <Column header="Contacto"><template #body="{ data }">{{ data.contacto }}</template></Column>
        <Column header="Total"><template #body="{ data }">{{ data.total ? money(data.total) : '—' }}</template></Column>
        <Column header="Error"><template #body="{ data }">{{ data.error ?? '' }}</template></Column>
      </DataTable>
    </div>
    </div>
  </div>
</template>
