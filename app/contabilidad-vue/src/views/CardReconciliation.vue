<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const loading = ref(false)
const msg = ref<any>(null)

const ventas = ref<any[]>([])
const depositos = ref<any[]>([])
const matched = ref<any[]>([])

const nuevoDeposito = ref({
  fecha: new Date().toISOString().slice(0, 10),
  monto: 0,
  comision: 0,
  referencia: '',
})

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

const totalVentas = computed(() => ventas.value.reduce((s, v) => s + Number(v.total ?? v.importe_total ?? 0), 0))
const totalDepositado = computed(() => depositos.value.reduce((s, d) => s + Number(d.monto), 0))
const totalComision = computed(() => depositos.value.reduce((s, d) => s + Number(d.comision ?? 0), 0))
const netoDepositado = computed(() => totalDepositado.value - totalComision.value)

async function load() {
  loading.value = true
  try {
    const invRes = await api.get('/invoices?company_id=' + company.activeId)
    ventas.value = invRes.data.filter((i: any) => i.forma_pago === 'tarjeta')
  } catch { /* empty */ }
  loading.value = false
}

function agregarDeposito() {
  if (nuevoDeposito.value.monto <= 0) return
  depositos.value.push({
    id: Date.now(),
    fecha: nuevoDeposito.value.fecha,
    monto: nuevoDeposito.value.monto,
    comision: nuevoDeposito.value.comision,
    referencia: nuevoDeposito.value.referencia,
    conciliado: false,
  })
  nuevoDeposito.value = { fecha: new Date().toISOString().slice(0, 10), monto: 0, comision: 0, referencia: '' }
}

function toggleConciliado(d: any) {
  d.conciliado = !d.conciliado
  if (d.conciliado) {
    matched.value.push({ ...d })
    msg.value = { type: 'success', text: `Deposito de ${money(d.monto)} conciliado.` }
  } else {
    matched.value = matched.value.filter(m => m.id !== d.id)
  }
}

function quitarDeposito(d: any) {
  depositos.value = depositos.value.filter(dep => dep.id !== d.id)
  matched.value = matched.value.filter(m => m.id !== d.id)
}

onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <h2 style="margin: 0 0 14px 0;">Conciliación de Tarjetas</h2>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 14px;">{{ msg.text }}</Message>

    <Message severity="info" :closable="false" style="margin-bottom: 14px;">
      Compare las ventas realizadas con tarjeta contra los depósitos del procesador de pagos.
      Marque los depósitos conciliados para cuadrar.
    </Message>

    <!-- Resumen -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px;">
      <div style="border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px; background: #fff;">
        <div style="font-size: 11px; color: #94a3b8;">VENTAS TARJETA</div>
        <b>{{ money(totalVentas) }}</b>
      </div>
      <div style="border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px; background: #fff;">
        <div style="font-size: 11px; color: #22a06b;">DEPOSITADO</div>
        <b>{{ money(totalDepositado) }}</b>
      </div>
      <div style="border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px; background: #fff;">
        <div style="font-size: 11px; color: #d93025;">COMISIONES</div>
        <b>{{ money(totalComision) }}</b>
      </div>
      <div style="border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px; background: #f8fafc;">
        <div style="font-size: 11px; color: #94a3b8;">NETO RECIBIDO</div>
        <b>{{ money(netoDepositado) }}</b>
      </div>
    </div>

    <!-- Dos columnas -->
    <div style="display: flex; gap: 16px;">
      <!-- Ventas con tarjeta -->
      <div style="flex: 1;">
        <h3 style="margin: 0 0 8px; font-size: 14px;">Ventas con Tarjeta</h3>
        <DataTable :value="ventas" :loading="loading" size="small" stripedRows :paginator="true" :rows="10">
          <Column header="Fecha"><template #body="{ data }">{{ data.fecha_emision?.slice?.(0, 10) ?? data.fecha_emision }}</template></Column>
          <Column header="Nº Factura"><template #body="{ data }">{{ data.numero }}</template></Column>
          <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social ?? '—' }}</template></Column>
          <Column header="Total" style="text-align: right;">
            <template #body="{ data }">{{ money(data.importe_total) }}</template>
          </Column>
          <Column header="Estado">
            <template #body="{ data }">
              <Tag :value="data.estado" :severity="data.estado === 'emitida' ? 'success' : 'warn'" />
            </template>
          </Column>
        </DataTable>
      </div>

      <!-- Depósitos del procesador -->
      <div style="flex: 1;">
        <h3 style="margin: 0 0 8px; font-size: 14px;">Depósitos del Procesador</h3>

        <fieldset class="kvs-fieldset" style="margin-bottom: 12px;">
          <legend>Agregar Depósito</legend>
          <div class="kvs-row">
            <label class="kvs-lbl">Fecha:</label>
            <InputText v-model="nuevoDeposito.fecha" type="date" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Monto bruto:</label>
            <InputNumber v-model="nuevoDeposito.monto" mode="currency" currency="USD" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Comisión:</label>
            <InputNumber v-model="nuevoDeposito.comision" mode="currency" currency="USD" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Referencia:</label>
            <InputText v-model="nuevoDeposito.referencia" class="kvs-in" />
          </div>
          <div style="display: flex; justify-content: flex-end; margin-top: 8px;">
            <Button label="Agregar" icon="pi pi-plus" size="small" @click="agregarDeposito" />
          </div>
        </fieldset>

        <DataTable :value="depositos" size="small" stripedRows>
          <Column header="Fecha"><template #body="{ data }">{{ data.fecha }}</template></Column>
          <Column header="Bruto" style="text-align: right;">
            <template #body="{ data }">{{ money(data.monto) }}</template>
          </Column>
          <Column header="Comisión" style="text-align: right;">
            <template #body="{ data }">{{ money(data.comision) }}</template>
          </Column>
          <Column header="Neto" style="text-align: right;">
            <template #body="{ data }">{{ money(data.monto - data.comision) }}</template>
          </Column>
          <Column header="Ref"><template #body="{ data }">{{ data.referencia }}</template></Column>
          <Column header="Conciliado" style="width: 80px;">
            <template #body="{ data }">
              <Checkbox :modelValue="data.conciliado" :binary="true" @update:modelValue="toggleConciliado(data)" />
            </template>
          </Column>
          <Column header="" style="width: 40px;">
            <template #body="{ data }">
              <Button icon="pi pi-times" text size="small" severity="danger" @click="quitarDeposito(data)" />
            </template>
          </Column>
        </DataTable>
      </div>
    </div>
  </div>
</template>
