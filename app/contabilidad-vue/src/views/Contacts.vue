<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({})

const tipos = [
  { label: 'Cédula', value: '05' }, { label: 'RUC', value: '04' },
  { label: 'Pasaporte', value: '06' }, { label: 'Consumidor final', value: '07' },
]

async function load() {
  loading.value = true
  rows.value = (await api.get(`/contacts?company_id=${company.activeId}`)).data
  loading.value = false
}
function nuevo() { form.value = { tipo_identificacion: '05', es_cliente: true, es_proveedor: false }; dialog.value = true }
function editar(r: any) { form.value = { ...r }; dialog.value = true }
async function guardar() {
  if (form.value.id) await api.put(`/contacts/${form.value.id}`, form.value)
  else await api.post('/contacts', { ...form.value, company_id: company.activeId })
  dialog.value = false; load()
}
async function eliminar(r: any) {
  if (!confirm(`¿Eliminar ${r.razon_social}?`)) return
  await api.delete(`/contacts/${r.id}`); load()
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Contactos</h2>
      <Button label="Nuevo" icon="pi pi-plus" @click="nuevo" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column field="identificacion" header="Identificación" />
      <Column field="razon_social" header="Razón social" />
      <Column header="Tipo">
        <template #body="{ data }">
          <Tag v-if="data.es_cliente" value="Cliente" severity="info" style="margin-right:4px" />
          <Tag v-if="data.es_proveedor" value="Proveedor" severity="warn" />
        </template>
      </Column>
      <Column field="telefono" header="Teléfono" />
      <Column header="Acciones" style="width:120px">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text @click="editar(data)" />
          <Button icon="pi pi-trash" text severity="danger" @click="eliminar(data)" />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Contacto" style="width: 460px;">
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Tipo de contacto</legend>
        <div class="kvs-row">
          <div style="display:flex; gap:16px; padding-left:4px;">
            <label><Checkbox v-model="form.es_cliente" :binary="true" /> Cliente</label>
            <label><Checkbox v-model="form.es_proveedor" :binary="true" /> Proveedor</label>
          </div>
        </div>
      </fieldset>
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos del contacto</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Tipo de identificación:</label>
          <Select v-model="form.tipo_identificacion" :options="tipos" optionLabel="label" optionValue="value" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Identificación:</label>
          <InputText v-model="form.identificacion" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Razón social:</label>
          <InputText v-model="form.razon_social" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Teléfono:</label>
          <InputText v-model="form.telefono" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Email:</label>
          <InputText v-model="form.email" class="kvs-in" />
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
