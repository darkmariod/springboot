<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({})

async function load() {
  loading.value = true
  rows.value = (await api.get('/warehouses?company_id=' + company.activeId)).data
  loading.value = false
}
function nuevo() { form.value = { por_defecto: false }; dialog.value = true }
function editar(r: any) { form.value = { ...r }; dialog.value = true }
async function guardar() {
  if (form.value.id) await api.put('/warehouses/' + form.value.id, form.value)
  else await api.post('/warehouses', { ...form.value, company_id: company.activeId })
  dialog.value = false; load()
}
async function eliminar(r: any) {
  if (r.por_defecto) { alert('No se puede eliminar la bodega por defecto.'); return }
  if (!confirm('¿Desea eliminar la bodega "' + r.nombre + '"?')) return
  await api.delete('/warehouses/' + r.id); load()
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Bodegas</h2>
      <Button label="Nueva Bodega" icon="pi pi-plus" @click="nuevo" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column field="codigo" header="Código" style="width:100px" />
      <Column field="nombre" header="Nombre" />
      <Column header="Por Defecto" style="width:120px">
        <template #body="{ data }">
          <Tag :value="data.por_defecto ? 'Sí' : 'No'" :severity="data.por_defecto ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column header="Activa" style="width:90px">
        <template #body="{ data }">
          <Tag :value="data.activa !== false ? 'Sí' : 'No'" :severity="data.activa !== false ? 'success' : 'danger'" />
        </template>
      </Column>
      <Column header="Acciones" style="width:100px">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text @click="editar(data)" />
          <Button icon="pi pi-trash" text severity="danger" @click="eliminar(data)" />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal :header="form.id ? 'Editar Bodega' : 'Nueva Bodega'" style="width: 420px;">
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos de la bodega</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Código:</label>
          <InputText v-model="form.codigo" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Nombre:</label>
          <InputText v-model="form.nombre" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label style="display:flex; align-items:center; gap:8px;">
            <Checkbox v-model="form.por_defecto" :binary="true" />
            <span>Por defecto</span>
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
