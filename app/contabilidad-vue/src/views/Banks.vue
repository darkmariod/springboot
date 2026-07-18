<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({})

async function load() {
  loading.value = true
  rows.value = (await api.get(`/banks?company_id=${company.activeId}`)).data
  loading.value = false
}
function nuevo() { form.value = {}; dialog.value = true }
function editar(r: any) { form.value = { ...r }; dialog.value = true }
async function guardar() {
  if (form.value.id) await api.put(`/banks/${form.value.id}`, form.value)
  else await api.post('/banks', { ...form.value, company_id: company.activeId })
  dialog.value = false; load()
}
async function eliminar(r: any) {
  if (!confirm(`¿Eliminar ${r.nombre}?`)) return
  await api.delete(`/banks/${r.id}`); load()
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Bancos</h2>
      <Button label="Nuevo" icon="pi pi-plus" @click="nuevo" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column field="nombre" header="Banco" />
      <Column field="numero_cuenta" header="Número de cuenta" />
      <Column field="cuenta_contable" header="Cuenta contable" />
      <Column header="Acciones" style="width:120px">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text @click="editar(data)" />
          <Button icon="pi pi-trash" text severity="danger" @click="eliminar(data)" />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Banco" style="width: 420px;">
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Datos del banco</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Nombre:</label>
          <InputText v-model="form.nombre" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Número de cuenta:</label>
          <InputText v-model="form.numero_cuenta" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Cuenta contable:</label>
          <InputText v-model="form.cuenta_contable" placeholder="1.1.02" class="kvs-in" />
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
