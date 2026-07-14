<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const session = ref<any>(null)
const saldoInicial = ref(200)
const mov = ref({ tipo: 'ingreso', monto: 0, concepto: '' })
const arqueo = ref(0)
const cierre = ref<any>(null)
const tipos = [{ label: 'Ingreso', value: 'ingreso' }, { label: 'Egreso', value: 'egreso' }]

const money = (n: any) => `$${Number(n).toFixed(2)}`
const esperado = computed(() => session.value ? Number(session.value.saldo_inicial) + Number(session.value.ingresos) - Number(session.value.egresos) : 0)

async function load() { session.value = (await api.get(`/cash/current?company_id=${company.activeId}`)).data }
async function abrir() { session.value = (await api.post('/cash/open', { company_id: company.activeId, saldo_inicial: saldoInicial.value })).data }
async function agregar() {
  session.value = (await api.post(`/cash/${session.value.id}/movements`, mov.value)).data
  mov.value = { tipo: 'ingreso', monto: 0, concepto: '' }
}
async function cerrar() {
  cierre.value = (await api.post(`/cash/${session.value.id}/close`, { saldo_final_contado: arqueo.value })).data
  session.value = null
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px; max-width: 640px;">
    <h2 style="margin:0 0 16px;">Caja diaria</h2>

    <div v-if="!session" style="border:1px solid #e2e5ea; border-radius:10px; padding:20px; background:#fff;">
      <p style="margin:0 0 12px; color:#64748b;">No hay caja abierta. Abrí la caja con el saldo inicial (fondo).</p>
      <div style="display:flex; gap:10px; align-items:flex-end;">
        <label style="display:flex; flex-direction:column; gap:4px;">Saldo inicial
          <InputNumber v-model="saldoInicial" mode="currency" currency="USD" /></label>
        <Button label="Abrir caja" @click="abrir" />
      </div>
      <div v-if="cierre" style="margin-top:16px; padding:12px; background:#f0fdf4; border-radius:8px;">
        <b>Caja cerrada.</b> Esperado: {{ money(cierre.esperado) }} · Contado: {{ money(cierre.session.saldo_final_contado) }} ·
        Diferencia: <b :style="{color: cierre.diferencia===0 ? '#22a06b':'#d93025'}">{{ money(cierre.diferencia) }}</b>
      </div>
    </div>

    <div v-else style="display:flex; flex-direction:column; gap:16px;">
      <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px;">
        <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;"><div style="font-size:11px; color:#94a3b8;">INICIAL</div><b>{{ money(session.saldo_inicial) }}</b></div>
        <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;"><div style="font-size:11px; color:#22a06b;">INGRESOS</div><b>{{ money(session.ingresos) }}</b></div>
        <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;"><div style="font-size:11px; color:#d93025;">EGRESOS</div><b>{{ money(session.egresos) }}</b></div>
        <div style="border:1px solid #2c3e50; border-radius:8px; padding:10px; background:#f8fafc;"><div style="font-size:11px; color:#94a3b8;">ESPERADO</div><b>{{ money(esperado) }}</b></div>
      </div>

      <div style="border:1px solid #e2e5ea; border-radius:10px; padding:16px; background:#fff;">
        <p style="margin:0 0 10px; font-weight:600;">Registrar movimiento</p>
        <div style="display:flex; gap:8px; align-items:flex-end;">
          <label style="display:flex; flex-direction:column; gap:4px;">Tipo<Select v-model="mov.tipo" :options="tipos" optionLabel="label" optionValue="value" /></label>
          <label style="display:flex; flex-direction:column; gap:4px;">Monto<InputNumber v-model="mov.monto" mode="currency" currency="USD" /></label>
          <label style="flex:1; display:flex; flex-direction:column; gap:4px;">Concepto<InputText v-model="mov.concepto" /></label>
          <Button label="Agregar" @click="agregar" />
        </div>
      </div>

      <DataTable :value="session.movements" size="small" stripedRows>
        <Column field="tipo" header="Tipo" />
        <Column field="concepto" header="Concepto" />
        <Column header="Monto"><template #body="{ data }">{{ money(data.monto) }}</template></Column>
      </DataTable>

      <div style="border:1px solid #e2e5ea; border-radius:10px; padding:16px; background:#fff; display:flex; gap:8px; align-items:flex-end;">
        <label style="display:flex; flex-direction:column; gap:4px;">Arqueo (contado físico)<InputNumber v-model="arqueo" mode="currency" currency="USD" /></label>
        <Button label="Cerrar caja" severity="secondary" @click="cerrar" />
      </div>
    </div>
  </div>
</template>
