<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const puntos = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ rol: 'cajero', activo: true })
const roles = [
  { label: 'Administrador (todo)', value: 'admin' },
  { label: 'Contador (contabilidad y reportes)', value: 'contador' },
  { label: 'Cajero (solo vende en su punto)', value: 'cajero' },
]
const sevRol: Record<string, string> = { admin: 'danger', contador: 'info', cajero: 'secondary' }

async function load() {
  loading.value = true
  rows.value = (await api.get('/users?company_id=' + company.activeId)).data
  puntos.value = (await api.get('/emission-points?company_id=' + company.activeId)).data
  loading.value = false
}
function nuevo() { form.value = { rol: 'cajero', activo: true }; dialog.value = true }
function editar(r: any) { form.value = { ...r, password: '' }; dialog.value = true }
async function guardar() {
  const payload = { ...form.value, company_id: company.activeId }
  if (form.value.id) await api.put('/users/' + form.value.id, payload)
  else await api.post('/users', payload)
  dialog.value = false; load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Usuarios y roles</h2>
      <Button label="Nuevo usuario" icon="pi pi-plus" @click="nuevo" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Si asigna un <b>punto de emisión</b> a un usuario, solo podrá facturar con ese punto.
      Así nadie rompe la numeración de otra caja.
    </Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="name" header="Nombre" />
      <Column field="email" header="Email" />
      <Column header="Rol"><template #body="{ data }">
        <Tag :value="data.rol" :severity="sevRol[data.rol]" /></template></Column>
      <Column header="Punto de emisión"><template #body="{ data }">
        <span v-if="data.emission_point">{{ data.emission_point.estab }}-{{ data.emission_point.punto }}
          ({{ data.emission_point.nombre }})</span>
        <span v-else style="color:#94a3b8;">Todos</span></template></Column>
      <Column header="Estado"><template #body="{ data }">
        <Tag :value="data.activo ? 'Activo' : 'Inactivo'" :severity="data.activo ? 'success':'secondary'" /></template></Column>
      <Column header=""><template #body="{ data }">
        <Button icon="pi pi-pencil" text @click="editar(data)" /></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Usuario" style="width:460px">
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos del usuario</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Nombre:</label>
          <InputText v-model="form.name" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Email:</label>
          <InputText v-model="form.email" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span v-if="!form.id" class="req">*</span> {{ form.id ? 'Contraseña:' : 'Contraseña:' }}</label>
          <Password v-model="form.password" :feedback="false" toggleMask class="kvs-in" />
        </div>
      </fieldset>
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Permisos</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Rol:</label>
          <Select v-model="form.rol" :options="roles" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Punto de emisión:</label>
          <Select v-model="form.emission_point_id" :options="puntos" optionValue="id" showClear
                  :optionLabel="(p) => p.estab + '-' + p.punto + ' (' + p.nombre + ')'" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label style="display:flex; align-items:center; gap:8px;">
            <Checkbox v-model="form.activo" :binary="true" /> Usuario activo
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
