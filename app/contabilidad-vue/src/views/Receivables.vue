<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
// Select and InputText available if needed for payment form
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import FormasPago from '../components/FormasPago.vue'

const company = useCompanyStore()
const data = ref<any>({ cartera: [], total: 0, antiguedad: {} })
const loading = ref(true)
const cobro = ref<any>(null)
const banks = ref<any[]>([])
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  data.value = (await api.get('/receivables?company_id=' + company.activeId)).data
  banks.value = (await api.get('/banks?company_id=' + company.activeId)).data
  loading.value = false
}
function abrirCobro(r: any) {
  cobro.value = {
    invoice: r,
    pagos: [{ id: 1, tipo: 'efectivo', fecha: '', valor: r.saldo, bank_id: null, documento: null, cuenta: null }],
  }
}
async function cobrar() {
  await api.post('/receivables/' + cobro.value.invoice.id + '/pay',
    { pagos: cobro.value.pagos.map((p: any) => ({
      tipo: p.tipo, valor: p.valor, bank_id: p.bank_id, documento: p.documento,
    })) })
  cobro.value = null; load()
}
const saldos = ref<any>({ saldos: [], total: 0 })
const usarDialog = ref<any>(null)

async function abrirUsarSaldo(r: any) {
  const res = await api.get('/credits/available?company_id=' + company.activeId +
    '&contact_id=' + r.contact_id)
  saldos.value = res.data
  usarDialog.value = { invoice: r, seleccion: null, monto: 0 }
}
async function aplicarSaldo() {
  const s = usarDialog.value.seleccion
  await api.post('/credits/apply/' + usarDialog.value.invoice.id, {
    tipo: s.tipo, id: s.id, monto: usarDialog.value.monto,
  })
  usarDialog.value = null; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Cuentas por cobrar</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">Quién te debe, cuánto y desde hace cuánto</p>
    <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:16px;">
      <div style="border:2px solid #2c3e50; border-radius:8px; padding:10px; background:#f8fafc;">
        <div style="font-size:11px; color:#94a3b8;">TOTAL</div><b>{{ money(data.total) }}</b></div>
      <div v-for="(v,k) in data.antiguedad" :key="k" style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#94a3b8;">{{ k }} DÍAS</div><b>{{ money(v) }}</b></div>
    </div>
    <DataTable :value="data.cartera" :loading="loading" size="small" stripedRows>
      <Column field="numero" header="Factura" />
      <Column field="cliente" header="Cliente" />
      <Column field="fecha" header="Fecha" />
      <Column header="Antigüedad"><template #body="{ data: r }"><Tag :value="r.dias + ' días'" :severity="r.dias<=30?'success':'warn'" /></template></Column>
      <Column header="Total"><template #body="{ data: r }">{{ money(r.total) }}</template></Column>
      <Column header="Saldo"><template #body="{ data: r }"><b>{{ money(r.saldo) }}</b></template></Column>
      <Column header=""><template #body="{ data: r }">        <Button label="Registrar cobro" size="small" @click="abrirCobro(r)" />
        <Button label="Usar saldo" size="small" outlined @click="abrirUsarSaldo(r)" /></template></Column>
    </DataTable>

    <Dialog :visible="!!cobro" modal header="Registrar cobro" style="width:760px" @update:visible="cobro=null">
      <div v-if="cobro" style="display:flex; flex-direction:column; gap:12px;">
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          Factura <b>{{ cobro.invoice.numero }}</b> — saldo {{ money(cobro.invoice.saldo) }}</div>
        <FormasPago v-model="cobro.pagos" :total="cobro.invoice.saldo" :banks="banks" />
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="cobro=null" />
        <Button label="Cobrar" @click="cobrar" />
      </template>
    </Dialog>

    <Dialog :visible="!!usarDialog" modal header="Usar saldo a favor" style="width:460px" @update:visible="usarDialog=null">
      <div v-if="usarDialog" style="display:flex; flex-direction:column; gap:12px;">
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          Factura <b>{{ usarDialog.invoice.numero }}</b> — saldo {{ money(usarDialog.invoice.saldo) }}
        </div>
        <p v-if="!saldos.saldos.length" style="color:#94a3b8;">Este cliente no tiene saldos a favor.</p>
        <DataTable v-else :value="saldos.saldos" size="small" selectionMode="single"
                   v-model:selection="usarDialog.seleccion" dataKey="id">
          <Column field="tipo" header="Tipo" />
          <Column field="fecha" header="Fecha" />
          <Column field="detalle" header="Detalle" />
          <Column header="Disponible"><template #body="{ data: s }">{{ money(s.disponible) }}</template></Column>
        </DataTable>
        <div v-if="usarDialog.seleccion" class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Monto a cruzar:</label>
          <InputNumber v-model="usarDialog.monto" mode="currency" currency="USD" class="kvs-in" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="usarDialog=null" />
        <Button label="Aplicar" :disabled="!usarDialog?.seleccion || !usarDialog?.monto" @click="aplicarSaldo" />
      </template>
    </Dialog>
  </div>
</template>
