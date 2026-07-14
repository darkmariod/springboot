<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({})

const tipos = [{ label: 'Bien (lleva stock)', value: 'bien' }, { label: 'Servicio', value: 'servicio' }]
const ivas = [{ label: '15%', value: 15 }, { label: '0%', value: 0 }]

async function load() {
  loading.value = true
  rows.value = (await api.get(`/products?company_id=${company.activeId}`)).data
  loading.value = false
}
function nuevo() { form.value = { tipo: 'bien', tarifa_iva: 15, precio: 0 }; dialog.value = true }
function editar(r: any) { form.value = { ...r, precio: Number(r.precio), tarifa_iva: Number(r.tarifa_iva) }; dialog.value = true }
async function guardar() {
  if (form.value.id) await api.put(`/products/${form.value.id}`, form.value)
  else await api.post('/products', { ...form.value, company_id: company.activeId })
  dialog.value = false; load()
}
async function eliminar(r: any) {
  if (!confirm(`¿Eliminar ${r.descripcion}?`)) return
  await api.delete(`/products/${r.id}`); load()
}
onMounted(load)
const money = (n: any) => `$${Number(n).toFixed(2)}`
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Productos y servicios</h2>
      <Button label="Nuevo" icon="pi pi-plus" @click="nuevo" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column field="codigo" header="Código" />
      <Column field="descripcion" header="Descripción" />
      <Column header="Tipo">
        <template #body="{ data }"><Tag :value="data.tipo" :severity="data.tipo==='bien'?'info':'secondary'" /></template>
      </Column>
      <Column header="Precio"><template #body="{ data }">{{ money(data.precio) }}</template></Column>
      <Column header="IVA"><template #body="{ data }">{{ data.tarifa_iva }}%</template></Column>
      <Column header="Acciones" style="width:120px">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text @click="editar(data)" />
          <Button icon="pi pi-trash" text severity="danger" @click="eliminar(data)" />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Producto / Servicio" style="width: 460px;">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <label style="display:flex; flex-direction:column; gap:4px;">Tipo
          <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Código *<InputText v-model="form.codigo" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Descripción *<InputText v-model="form.descripcion" fluid /></label>
        <label style="display:flex; flex-direction:column; gap:4px;">Foto (URL)<InputText v-model="form.imagen" placeholder="https://…" fluid /></label>
        <div style="display:flex; gap:12px;">
          <label style="flex:1; display:flex; flex-direction:column; gap:4px;">Precio *<InputNumber v-model="form.precio" mode="currency" currency="USD" fluid /></label>
          <label style="flex:1; display:flex; flex-direction:column; gap:4px;">IVA
            <Select v-model="form.tarifa_iva" :options="ivas" optionLabel="label" optionValue="value" fluid /></label>
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog=false" />
        <Button label="Guardar" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
