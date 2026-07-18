<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({ estab: '001', punto: '', nombre: '' })

async function load() {
  loading.value = true
  rows.value = (await api.get('/emission-points?company_id=' + company.activeId)).data
  loading.value = false
}
async function guardar() {
  await api.post('/emission-points', { ...form.value, company_id: company.activeId })
  dialog.value = false; form.value = { estab: '001', punto: '', nombre: '' }; load()
}
async function eliminar(r: any) {
  if (!confirm('¿Eliminar el punto ' + r.estab + '-' + r.punto + '?')) return
  await api.delete('/emission-points/' + r.id); load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Puntos de emisión</h2>
      <Button label="Nuevo punto" icon="pi pi-plus" @click="dialog = true" />
    </div>
    <Message severity="info" :closable="false" style="margin-bottom:14px;">
      Cada caja factura con su propio punto y su propia secuencia (ej. 001-901 caja, 001-902 farmacia).
      Los puntos deben existir primero en el SRI. Cada usuario se restringe a su punto.
    </Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column header="Punto"><template #body="{ data }"><b>{{ data.estab }}-{{ data.punto }}</b></template></Column>
      <Column field="nombre" header="Nombre" />
      <Column field="secuencial" header="Próx. secuencial" />
      <Column header=""><template #body="{ data }">
        <Button icon="pi pi-trash" text severity="danger" @click="eliminar(data)" /></template></Column>
    </DataTable>

    <Dialog v-model:visible="dialog" modal header="Nuevo punto de emisión" style="width:380px">
      <fieldset class="kvs-fieldset" style="margin-top:14px;">
        <legend>Nuevo punto de emisión</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Establecimiento:</label>
          <InputText v-model="form.estab" maxlength="3" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Punto (ej. 901):</label>
          <InputText v-model="form.punto" maxlength="3" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Nombre (ej. Caja):</label>
          <InputText v-model="form.nombre" class="kvs-in" />
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
