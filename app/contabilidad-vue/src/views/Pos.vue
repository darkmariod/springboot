<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const products = ref<any[]>([])
const contacts = ref<any[]>([])
const cart = ref<any[]>([])
const contactId = ref<number | null>(null)
const pago = ref('efectivo')
const emitiendo = ref(false)
const ok = ref<any>(null)

const total = computed(() =>
  cart.value.reduce((s, i) => s + Number(i.precio) * i.qty * (1 + Number(i.tarifa_iva) / 100), 0),
)
const money = (n: number) => `$${n.toFixed(2)}`

function add(p: any) { const e = cart.value.find((i) => i.id === p.id); e ? e.qty++ : cart.value.push({ ...p, qty: 1, series: [] }) }

async function escanear(valor: string) {
  const v = valor.trim()
  if (!v) return
  try {
    const res = await api.get('/series/lookup?company_id=' + company.activeId + '&serie=' + encodeURIComponent(v))
    const p = res.data.product
    const item = cart.value.find((i) => i.id === p.id)
    if (item) { item.qty++; (item.series ??= []).push(res.data.serie) }
    else cart.value.push({ ...p, qty: 1, series: [res.data.serie] })
    return
  } catch { /* no es serie, sigo */ }
  const p = products.value.find((x) => x.codigo === v)
  if (p) add(p)
  else alert('No se encontró serie ni código: ' + v)
}

async function load() {
  products.value = (await api.get(`/products?company_id=${company.activeId}`)).data
  contacts.value = (await api.get(`/contacts?company_id=${company.activeId}`)).data
  contactId.value = contacts.value[0]?.id ?? null
}
async function emitir() {
  if (!cart.value.length || !contactId.value) return
  emitiendo.value = true
  try {
    const res = await api.post('/invoices', {
      company_id: company.activeId, contact_id: contactId.value, forma_pago: pago.value,
      items: cart.value.map((i) => ({ codigo_principal: i.codigo, descripcion: i.descripcion,
        cantidad: i.qty, precio_unitario: Number(i.precio), tarifa: Number(i.tarifa_iva),
        series: i.series ?? [] })),
    })
    ok.value = res.data.invoice; cart.value = []
  } catch (e: any) {
    alert(e.response?.data?.message ?? 'No se pudo emitir.')
  } finally { emitiendo.value = false }
}
async function guardarCotizacion() {
  if (!cart.value.length || !contactId.value) return
  emitiendo.value = true
  try {
    await api.post('/quotes', {
      company_id: company.activeId, contact_id: contactId.value,
      items: cart.value.map((i) => ({ codigo_principal: i.codigo, descripcion: i.descripcion,
        cantidad: i.qty, precio_unitario: Number(i.precio), tarifa: Number(i.tarifa_iva) })),
    })
    ok.value = { numero: 'Cotización guardada' }; cart.value = []
  } finally { emitiendo.value = false }
}
onMounted(load)
</script>

<template>
  <div style="display:flex; height:100%;">
    <div style="flex:1; padding:16px; overflow:auto;">
      <input
        placeholder="Escanear serie o código… (Enter agrega)"
        style="width:100%; padding:10px 12px; border:1px solid #e2e5ea; border-radius:8px; margin-bottom:12px;"
        @keydown.enter.prevent="escanear(($event.target as HTMLInputElement).value); ($event.target as HTMLInputElement).value=''"
      />
      <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px;">
        <button v-for="p in products" :key="p.id" @click="add(p)"
          style="border:1px solid #e2e5ea; border-radius:8px; background:#fff; padding:8px; cursor:pointer; text-align:left;">
          <img v-if="p.imagen" :src="p.imagen" style="width:100%; height:70px; object-fit:cover; border-radius:6px;" />
          <div v-else style="height:70px; background:#f1f3f6; border-radius:6px; display:flex; align-items:center; justify-content:center; color:#cbd5e1;">
            <i class="pi pi-box" style="font-size:1.4rem" />
          </div>
          <div style="font-size:13px; margin-top:6px;">{{ p.descripcion }}</div>
          <div style="color:#22a06b; font-weight:600;">{{ money(Number(p.precio)) }}</div>
        </button>
      </div>
    </div>
    <aside style="width:340px; border-left:1px solid #e2e5ea; background:#fff; padding:16px; display:flex; flex-direction:column;">
      <Select v-model="contactId" :options="contacts" optionLabel="razon_social" optionValue="id" placeholder="Cliente" fluid />
      <div style="flex:1; margin:12px 0; overflow:auto;">
        <div v-if="!cart.length" style="color:#94a3b8; text-align:center; padding:30px 0;">Toca un producto para agregarlo</div>
        <div v-for="i in cart" :key="i.id" style="display:flex; justify-content:space-between; padding:6px 0; font-size:13px; border-bottom:1px solid #f1f3f6;">
          <span>{{ i.qty }}× {{ i.descripcion }}<span v-if="i.series?.length" style="color:#94a3b8; font-size:11px;"> ({{ i.series.join(', ') }})</span></span><span>{{ money(Number(i.precio) * i.qty) }}</span>
        </div>
      </div>
      <p style="font-size:11px; text-transform:uppercase; color:#94a3b8; margin:0 0 6px;">Método de pago</p>
      <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-bottom:10px;">
        <Button label="Efectivo" :outlined="pago!=='efectivo'" size="small" @click="pago='efectivo'" />
        <Button label="Transfer." :outlined="pago!=='transferencia'" size="small" @click="pago='transferencia'" />
        <Button label="Tarjeta" :outlined="pago!=='tarjeta'" size="small" @click="pago='tarjeta'" />
      </div>
      <div style="display:flex; justify-content:space-between; font-weight:700; font-size:18px; margin-bottom:12px;">
        <span>Total</span><span>{{ money(total) }}</span>
      </div>
      <Button label="Emitir factura" icon="pi pi-check-circle" :loading="emitiendo" :disabled="!cart.length" @click="emitir" />
      <Button label="Guardar cotización" icon="pi pi-file-edit" outlined style="margin-top:6px;" :disabled="!cart.length" @click="guardarCotizacion" />
      <p v-if="ok" style="color:#22a06b; font-size:13px; margin-top:10px; text-align:center;">✓ Factura {{ ok.numero }} emitida</p>
    </aside>
  </div>
</template>
