<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import FormasPago from '../components/FormasPago.vue'

const company = useCompanyStore()
const data = ref<any>({ cartera: [], total: 0 })
const banks = ref<any[]>([])
const loading = ref(true)
const pago = ref<any>(null)
const errorPago = ref('')
const aviso = ref('')
const money = (n: any) => '$' + Number(n).toFixed(2)
const redondear = (n: number) => Math.round(n * 100) / 100

async function load() {
  loading.value = true
  data.value = (await api.get('/payables?company_id=' + company.activeId)).data
  banks.value = (await api.get('/banks?company_id=' + company.activeId)).data
  seleccion.value = []
  loading.value = false
}

function mensajeError(err: any, porDefecto: string) {
  const e = err.response?.data?.errors
  return e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? porDefecto)
}

// ---- Pago de una compra
async function abrirPago(r: any) {
  errorPago.value = ''
  aviso.value = ''
  pago.value = {
    purchase: r,
    pagos: [{ id: 1, tipo: 'efectivo', fecha: '', valor: r.saldo, bank_id: null, documento: null, cuenta: null, documento_cruce: null }],
    anticipos: 0,
  }
  try {
    const res = await api.get('/credits/available-supplier', { params: { company_id: company.activeId, contact_id: r.contact_id } })
    if (pago.value?.purchase.id === r.id) pago.value.anticipos = Number(res.data.total) || 0
  } catch {
    // sin anticipos que mostrar: el pago sigue igual
  }
}
async function pagar() {
  errorPago.value = ''
  try {
    await api.post('/payables/' + pago.value.purchase.id + '/pay', {
      pagos: pago.value.pagos.map((p: any) => ({
        tipo: p.tipo, valor: p.valor, bank_id: p.bank_id, documento: p.documento, documento_cruce: p.documento_cruce,
      })),
    })
  } catch (err: any) {
    errorPago.value = mensajeError(err, 'No se pudo registrar el pago.')
    return
  }
  pago.value = null; load()
}

// ---- Pago a varios proveedores: se marcan las compras abiertas y se paga todo con un solo comprobante
const seleccion = ref<any[]>([])
const multi = ref<any>(null)
const errorMulti = ref('')
const enviandoMulti = ref(false)
const totalSeleccion = computed(() => redondear(seleccion.value.reduce((s, r) => s + Number(r.saldo), 0)))

function abrirMulti() {
  errorMulti.value = ''
  aviso.value = ''
  multi.value = {
    filas: seleccion.value.map(r => ({ id: r.id, numero: r.numero, proveedor: r.proveedor, saldo: Number(r.saldo), monto: Number(r.saldo) })),
    pagos: [{ id: 1, tipo: 'efectivo', fecha: '', valor: totalSeleccion.value, bank_id: null, documento: null, cuenta: null }],
  }
}
const totalMulti = computed(() => redondear((multi.value?.filas ?? []).reduce((s: number, f: any) => s + (Number(f.monto) || 0), 0)))
const formasValidas = computed(() => (multi.value?.pagos ?? []).filter((p: any) => p.tipo && Number(p.valor) > 0))
const sumaFormas = computed(() => redondear(formasValidas.value.reduce((s: number, p: any) => s + Number(p.valor), 0)))
const multiCuadra = computed(() => totalMulti.value > 0 && Math.abs(sumaFormas.value - totalMulti.value) < 0.005)
// Con una sola forma de pago, su valor sigue al total mientras se editan los montos
watch(totalMulti, (t) => {
  if (multi.value?.pagos.length === 1) multi.value.pagos[0].valor = t
})
function montoMulti(f: any, v: number | null) {
  // Nunca por encima del saldo de la compra ni por debajo de cero
  f.monto = Math.min(Math.max(Number(v) || 0, 0), f.saldo)
}
async function pagarMulti() {
  errorMulti.value = ''
  enviandoMulti.value = true
  try {
    const res = await api.post('/payables/pay-multiple', {
      company_id: company.activeId,
      pagos: multi.value.filas.filter((f: any) => f.monto > 0).map((f: any) => ({ purchase_id: f.id, monto: f.monto })),
      formas: formasValidas.value.map((p: any) => ({ tipo: p.tipo, valor: p.valor, bank_id: p.bank_id, documento: p.documento })),
    })
    aviso.value = 'Pago registrado: ' + res.data.facturas + ' compra(s) por ' + money(res.data.pagado) + '.'
  } catch (err: any) {
    errorMulti.value = mensajeError(err, 'No se pudo registrar el pago.')
    enviandoMulti.value = false
    return
  }
  enviandoMulti.value = false
  multi.value = null; load()
}

// ---- Usar un anticipo entregado al proveedor (como "Usar saldo" en Cuentas por cobrar)
const anticipos = ref<any>({ saldos: [], total: 0 })
const usarDialog = ref<any>(null)
const errorAnticipo = ref('')

async function abrirUsarAnticipo(r: any) {
  errorAnticipo.value = ''
  aviso.value = ''
  const res = await api.get('/credits/available-supplier', { params: { company_id: company.activeId, contact_id: r.contact_id } })
  anticipos.value = res.data
  pago.value = null
  usarDialog.value = { purchase: r, seleccion: null, monto: 0 }
}
function elegirAnticipo(a: any) {
  if (!usarDialog.value) return
  usarDialog.value.seleccion = a
  // Por defecto, lo que alcance: el menor entre el saldo de la compra y lo disponible del anticipo
  usarDialog.value.monto = a ? Math.min(Number(usarDialog.value.purchase.saldo), Number(a.disponible)) : 0
}
const montoAnticipoMax = computed(() => usarDialog.value?.seleccion
  ? Math.min(Number(usarDialog.value.purchase.saldo), Number(usarDialog.value.seleccion.disponible)) : 0)
async function aplicarAnticipo() {
  errorAnticipo.value = ''
  const s = usarDialog.value.seleccion
  try {
    await api.post('/credits/apply-purchase/' + usarDialog.value.purchase.id, { id: s.id, monto: usarDialog.value.monto })
  } catch (err: any) {
    errorAnticipo.value = mensajeError(err, 'No se pudo aplicar el anticipo.')
    return
  }
  aviso.value = 'Anticipo aplicado a la compra ' + usarDialog.value.purchase.numero + ': ' + money(usarDialog.value.monto) + '.'
  usarDialog.value = null; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div><h2 style="margin:0;">Cuentas por pagar</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">Cartera de proveedores. Marca varias compras para pagarlas juntas.</p></div>
      <div style="text-align:right;"><div style="font-size:11px; color:#94a3b8;">TOTAL POR PAGAR</div>
        <div style="font-size:20px; font-weight:700;">{{ money(data.total) }}</div></div>
    </div>
    <Message v-if="aviso" severity="success" :closable="true" style="margin-bottom:12px;" @close="aviso = ''">{{ aviso }}</Message>
    <div v-if="seleccion.length" style="display:flex; align-items:center; gap:12px; margin-bottom:10px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e5ea; border-radius:8px;">
      <span><b>{{ seleccion.length }}</b> compra(s) seleccionada(s) · saldo {{ money(totalSeleccion) }}</span>
      <Button label="Pagar seleccionadas" icon="pi pi-wallet" size="small" @click="abrirMulti" />
      <Button label="Quitar selección" text size="small" @click="seleccion = []" />
    </div>
    <DataTable v-model:selection="seleccion" :value="data.cartera" :loading="loading" dataKey="id" size="small" stripedRows>
      <Column selectionMode="multiple" headerStyle="width:3rem" />
      <Column field="numero" header="Factura" />
      <Column field="proveedor" header="Proveedor" />
      <Column field="fecha" header="Fecha" />
      <Column header="Total"><template #body="{ data: r }">{{ money(r.total) }}</template></Column>
      <Column header="Saldo"><template #body="{ data: r }"><b>{{ money(r.saldo) }}</b></template></Column>
      <Column header=""><template #body="{ data: r }">
        <Button label="Pagar" size="small" @click="abrirPago(r)" />
        <Button label="Usar anticipo" size="small" outlined style="margin-left:6px;" @click="abrirUsarAnticipo(r)" />
      </template></Column>
    </DataTable>

    <Dialog :visible="!!pago" modal header="Pagar al proveedor" style="width:760px" @update:visible="pago=null">
      <div v-if="pago" style="display:flex; flex-direction:column; gap:12px;">
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          {{ pago.purchase.numero }} — {{ pago.purchase.proveedor }} · saldo {{ money(pago.purchase.saldo) }}</div>
        <Message v-if="pago.anticipos > 0" severity="info" :closable="false">
          Este proveedor tiene {{ money(pago.anticipos) }} en anticipos sin usar.
          <Button label="Usar anticipo" size="small" text @click="abrirUsarAnticipo(pago.purchase)" />
        </Message>
        <Message v-if="errorPago" severity="error" :closable="false">{{ errorPago }}</Message>
        <FormasPago v-model="pago.pagos" :total="pago.purchase.saldo" :banks="banks"
                    permite-cruce :contact-id="pago.purchase.contact_id" lado="pago" />
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="pago=null" />
        <Button label="Pagar" @click="pagar" />
      </template>
    </Dialog>

    <Dialog :visible="!!multi" modal header="Pagar compras seleccionadas" style="width:860px" @update:visible="multi=null">
      <div v-if="multi" style="display:flex; flex-direction:column; gap:12px;">
        <DataTable :value="multi.filas" size="small" stripedRows>
          <Column field="numero" header="Factura" />
          <Column field="proveedor" header="Proveedor" />
          <Column header="Saldo"><template #body="{ data: f }">{{ money(f.saldo) }}</template></Column>
          <Column header="Monto a pagar" style="width:180px;">
            <template #body="{ data: f }">
              <InputNumber :modelValue="f.monto" mode="currency" currency="USD" :min="0" :max="f.saldo" fluid
                           @focus="($event: Event) => ($event.target as HTMLInputElement).select()"
                           @update:modelValue="(v: number | null) => montoMulti(f, v)" />
            </template>
          </Column>
          <template #footer>
            <div style="display:flex; justify-content:flex-end; font-size:13px;">
              Total a pagar: <b style="margin-left:6px;">{{ money(totalMulti) }}</b>
            </div>
          </template>
        </DataTable>
        <Message v-if="errorMulti" severity="error" :closable="false">{{ errorMulti }}</Message>
        <FormasPago v-model="multi.pagos" :total="totalMulti" :banks="banks" />
        <small v-if="totalMulti > 0 && !multiCuadra" style="color:#d97706;">
          La suma de las formas de pago ({{ money(sumaFormas) }}) debe ser igual al total a pagar ({{ money(totalMulti) }}).
        </small>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="multi=null" />
        <Button label="Pagar" :disabled="!multiCuadra" :loading="enviandoMulti" @click="pagarMulti" />
      </template>
    </Dialog>

    <Dialog :visible="!!usarDialog" modal header="Usar anticipo del proveedor" style="width:480px" @update:visible="usarDialog=null">
      <div v-if="usarDialog" style="display:flex; flex-direction:column; gap:12px;">
        <div style="background:#f8fafc; padding:10px; border-radius:8px;">
          Compra <b>{{ usarDialog.purchase.numero }}</b> — {{ usarDialog.purchase.proveedor }} · saldo {{ money(usarDialog.purchase.saldo) }}
        </div>
        <Message v-if="errorAnticipo" severity="error" :closable="false">{{ errorAnticipo }}</Message>
        <p v-if="!anticipos.saldos.length" style="color:#94a3b8;">Este proveedor no tiene anticipos sin usar.</p>
        <DataTable v-else :value="anticipos.saldos" size="small" selectionMode="single" dataKey="id"
                   :selection="usarDialog.seleccion" @update:selection="elegirAnticipo">
          <Column field="fecha" header="Fecha" />
          <Column field="detalle" header="Detalle" />
          <Column header="Disponible"><template #body="{ data: a }">{{ money(a.disponible) }}</template></Column>
        </DataTable>
        <div v-if="usarDialog.seleccion" class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Monto a aplicar:</label>
          <InputNumber v-model="usarDialog.monto" mode="currency" currency="USD" :min="0" :max="montoAnticipoMax"
                       @focus="($event: Event) => ($event.target as HTMLInputElement).select()" class="kvs-in" />
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="usarDialog=null" />
        <Button label="Aplicar" :disabled="!usarDialog?.seleccion || !usarDialog?.monto" @click="aplicarAnticipo" />
      </template>
    </Dialog>
  </div>
</template>
