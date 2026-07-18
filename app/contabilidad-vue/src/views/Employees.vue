<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ activo: true, fondos_reserva: false, sueldo: 470 })
const money = (n: any) => '$' + Number(n).toFixed(2)

async function load() {
  loading.value = true
  rows.value = (await api.get('/employees?company_id=' + company.activeId)).data
  loading.value = false
}
function nuevo() { form.value = { activo: true, fondos_reserva: false, sueldo: 470 }; dialog.value = true }
function editar(r: any) {
  form.value = { ...r, fecha_ingreso: r.fecha_ingreso ? new Date(r.fecha_ingreso) : null }
  dialog.value = true
}
async function guardar() {
  const payload = {
    ...form.value,
    company_id: company.activeId,
    fecha_ingreso: form.value.fecha_ingreso instanceof Date
      ? form.value.fecha_ingreso.toISOString().slice(0, 10)
      : form.value.fecha_ingreso,
  }
  if (form.value.id) await api.put('/employees/' + form.value.id, payload)
  else await api.post('/employees', payload)
  dialog.value = false; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Empleados</h2>
      <Button label="Nuevo empleado" icon="pi pi-plus" @click="nuevo" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="cedula" header="Cédula" />
      <Column field="nombres" header="Nombres" />
      <Column field="cargo" header="Cargo" />
      <Column header="Ingreso"><template #body="{ data }">{{ String(data.fecha_ingreso).slice(0,10) }}</template></Column>
      <Column header="Sueldo"><template #body="{ data }">{{ money(data.sueldo) }}</template></Column>
      <Column header="F. reserva"><template #body="{ data }">
        <Tag :value="data.fondos_reserva ? 'Sí' : 'No'" :severity="data.fondos_reserva ? 'success':'secondary'" /></template></Column>
      <Column header=""><template #body="{ data }">
        <Button icon="pi pi-pencil" text @click="editar(data)" /></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Empleado" style="width:460px">
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos personales</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Cédula:</label>
          <InputText v-model="form.cedula" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Nombres:</label>
          <InputText v-model="form.nombres" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Cargo:</label>
          <InputText v-model="form.cargo" class="kvs-in" />
        </div>
      </fieldset>
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos laborales</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Fecha de ingreso:</label>
          <DatePicker v-model="form.fecha_ingreso" dateFormat="yy-mm-dd" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Sueldo:</label>
          <InputNumber v-model="form.sueldo" mode="currency" currency="USD" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label style="display:flex; align-items:center; gap:8px;">
            <Checkbox v-model="form.fondos_reserva" :binary="true" /> Recibe fondos de reserva (más de 1 año)
          </label>
        </div>
      </fieldset>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="dialog=false" />
          <Button label="Guardar" @click="guardar" />
        </div>
      </template>
    </Dialog>
  </div>
</template>
