<script setup lang="ts">
import { ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rol = ref<any>(null)
const anio = ref(new Date().getFullYear())
const mes = ref(new Date().getMonth() + 1)
const cargando = ref(false)
const msg = ref<any>(null)
const meses = [
  { label: 'Enero', value: 1 }, { label: 'Febrero', value: 2 }, { label: 'Marzo', value: 3 },
  { label: 'Abril', value: 4 }, { label: 'Mayo', value: 5 }, { label: 'Junio', value: 6 },
  { label: 'Julio', value: 7 }, { label: 'Agosto', value: 8 }, { label: 'Septiembre', value: 9 },
  { label: 'Octubre', value: 10 }, { label: 'Noviembre', value: 11 }, { label: 'Diciembre', value: 12 },
]
const money = (n: any) => '$' + Number(n).toFixed(2)

async function generar() {
  cargando.value = true; msg.value = null
  try {
    rol.value = (await api.post('/payrolls/generate', {
      company_id: company.activeId, anio: anio.value, mes: mes.value,
    })).data
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.message ?? 'No se pudo generar.' }
  } finally { cargando.value = false }
}
async function cerrar() {
  const res = await api.post('/payrolls/' + rol.value.id + '/close')
  msg.value = { type: 'success', text: res.data.mensaje }
  generar()
}
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 4px;">Rol de pagos</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">
      El rol descuenta el 9.45% de IESS al empleado. Las provisiones (patronal 11.15%, décimos,
      fondos, vacaciones) son costo de la empresa y no se le descuentan.
    </p>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>

    <div style="display:flex; gap:10px; align-items:flex-end; margin-bottom:16px; background:#fff; border:1px solid #e2e5ea; border-radius:10px; padding:14px;">
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Mes
        <Select v-model="mes" :options="meses" optionLabel="label" optionValue="value" /></label>
      <label style="display:flex; flex-direction:column; gap:4px; font-size:12px;">Año
        <InputNumber v-model="anio" :useGrouping="false" /></label>
      <Button label="Generar rol" icon="pi pi-cog" :loading="cargando" @click="generar" />
      <Button v-if="rol && rol.estado === 'abierto'" label="Cerrar y contabilizar"
              icon="pi pi-check" severity="secondary" @click="cerrar" />
      <Tag v-if="rol" :value="rol.estado" :severity="rol.estado === 'cerrado' ? 'success' : 'warn'" />
    </div>

    <div v-if="rol" style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#94a3b8;">INGRESOS</div><b>{{ money(rol.total_ingresos) }}</b></div>
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#d93025;">EGRESOS</div><b>{{ money(rol.total_egresos) }}</b></div>
      <div style="border:2px solid #2c3e50; border-radius:8px; padding:10px; background:#f8fafc;">
        <div style="font-size:11px; color:#94a3b8;">NETO A PAGAR</div><b>{{ money(rol.total_neto) }}</b></div>
      <div style="border:1px solid #e2e5ea; border-radius:8px; padding:10px; background:#fff;">
        <div style="font-size:11px; color:#94a3b8;">PROVISIONES</div><b>{{ money(rol.total_provisiones) }}</b></div>
    </div>

    <DataTable v-if="rol" :value="rol.lines" size="small" stripedRows scrollable>
      <Column header="Empleado"><template #body="{ data }">{{ data.employee?.nombres }}</template></Column>
      <Column header="Sueldo"><template #body="{ data }">{{ money(data.sueldo) }}</template></Column>
      <Column header="H. extra"><template #body="{ data }">{{ money(data.horas_extra) }}</template></Column>
      <Column header="IESS 9.45%"><template #body="{ data }">
        <span style="color:#d93025;">-{{ money(data.aporte_personal) }}</span></template></Column>
      <Column header="Neto"><template #body="{ data }"><b>{{ money(data.neto) }}</b></template></Column>
      <Column header="Patronal"><template #body="{ data }">{{ money(data.aporte_patronal) }}</template></Column>
      <Column header="Déc. 13"><template #body="{ data }">{{ money(data.decimo_tercero) }}</template></Column>
      <Column header="Déc. 14"><template #body="{ data }">{{ money(data.decimo_cuarto) }}</template></Column>
      <Column header="F. reserva"><template #body="{ data }">{{ money(data.fondos_reserva) }}</template></Column>
      <Column header="Vacac."><template #body="{ data }">{{ money(data.vacaciones) }}</template></Column>
    </DataTable>
  </div>
</template>
