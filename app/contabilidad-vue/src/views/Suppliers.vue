<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({})
const msg = ref<any>(null)
const guardando = ref(false)

const tiposId = [
  { label: 'RUC', value: '04' },
  { label: 'Cédula', value: '05' },
  { label: 'Pasaporte', value: '06' },
]

async function load() {
  loading.value = true
  const all = (await api.get('/contacts?company_id=' + company.activeId)).data
  rows.value = all.filter((c: any) => c.es_proveedor)
  loading.value = false
}
function nuevo() {
  form.value = { tipo_identificacion: '04', es_proveedor: true, es_cliente: false }
  msg.value = null
  dialog.value = true
}
function editar(r: any) {
  form.value = { ...r }
  msg.value = null
  dialog.value = true
}
async function guardar() {
  msg.value = null
  guardando.value = true
  // Un proveedor es un contacto con es_proveedor; puede ser cliente también.
  const payload = { ...form.value, company_id: company.activeId, es_proveedor: true }
  try {
    if (form.value.id) await api.put('/contacts/' + form.value.id, payload)
    else await api.post('/contacts', payload)
    dialog.value = false
    load()
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
  } finally { guardando.value = false }
}
async function eliminar(r: any) {
  if (!confirm('¿Eliminar a ' + r.razon_social + '?')) return
  try {
    await api.delete('/contacts/' + r.id)
    load()
  } catch (err: any) {
    alert(err.response?.data?.message ?? 'No se pudo eliminar (puede tener compras registradas).')
  }
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
      <h2 style="margin:0;">Proveedores</h2>
      <Button label="Nuevo proveedor" icon="pi pi-plus" @click="nuevo" />
    </div>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 14px;">
      Cargalos acá, o se crean solos al importar compras del SRI.
    </p>

    <DataTable :value="rows" :loading="loading" size="small" stripedRows paginator :rows="15">
      <Column field="identificacion" header="RUC / CI" />
      <Column field="razon_social" header="Razón social" />
      <Column field="direccion" header="Dirección" />
      <Column field="telefono" header="Teléfono" />
      <Column field="email" header="Email" />
      <Column header="" style="width:110px">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text size="small" @click="editar(data)" />
          <Button icon="pi pi-trash" text size="small" severity="danger" @click="eliminar(data)" />
        </template>
      </Column>
      <template #empty>
        <div style="text-align:center; color:#94a3b8; padding:20px;">
          Sin proveedores. Tocá <b>Nuevo proveedor</b> o importá una compra del SRI.
        </div>
      </template>
    </DataTable>

    <Dialog v-model:visible="dialog" modal :header="form.id ? 'Editar proveedor' : 'Nuevo proveedor'"
            style="width:480px">
      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:12px;">{{ msg.text }}</Message>
      <div style="display:flex; flex-direction:column; gap:12px;">
        <div style="display:flex; gap:12px;">
          <label style="width:150px; display:flex; flex-direction:column; gap:4px; font-size:13px;">
            Tipo *
            <Select v-model="form.tipo_identificacion" :options="tiposId" optionLabel="label"
                    optionValue="value" fluid />
          </label>
          <label style="flex:1; display:flex; flex-direction:column; gap:4px; font-size:13px;">
            Identificación *
            <InputText v-model="form.identificacion" placeholder="1790012345001" fluid />
          </label>
        </div>
        <label style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
          Razón social *
          <InputText v-model="form.razon_social" fluid />
        </label>
        <label style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
          Dirección
          <InputText v-model="form.direccion" fluid />
        </label>
        <div style="display:flex; gap:12px;">
          <label style="flex:1; display:flex; flex-direction:column; gap:4px; font-size:13px;">
            Teléfono
            <InputText v-model="form.telefono" fluid />
          </label>
          <label style="flex:1; display:flex; flex-direction:column; gap:4px; font-size:13px;">
            Email
            <InputText v-model="form.email" fluid />
          </label>
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog = false" />
        <Button label="Guardar" icon="pi pi-save" :loading="guardando" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
