<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Password from 'primevue/password'
import Message from 'primevue/message'
import Select from 'primevue/select'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { usePlanStore } from '../stores/plan'

const companyStore = useCompanyStore()
const planStore = usePlanStore()
const rows = ref<any[]>([])
const loading = ref(true)
const certDialog = ref<any>(null)
const clave = ref('')
const msg = ref<any>(null)
const fileRef = ref<HTMLInputElement>()
const planes = [
  { label: 'Básico', value: 'basico' },
  { label: 'PRO', value: 'pro' },
  { label: 'Corporativo', value: 'corporativo' },
]

async function load() {
  loading.value = true
  rows.value = (await api.get('/companies')).data
  loading.value = false
}
async function subirCert() {
  const file = fileRef.value?.files?.[0]
  if (!file) { msg.value = { type: 'error', text: 'Seleccioná el archivo .p12' }; return }
  const form = new FormData()
  form.append('certificado', file); form.append('clave', clave.value)
  try {
    const res = await api.post('/companies/' + certDialog.value.id + '/certificate', form)
    msg.value = { type: 'success', text: res.data.mensaje }
    certDialog.value = null; clave.value = ''
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.errors?.clave?.[0] ?? 'No se pudo cargar.' }
  }
}
// Cambia el plan en vivo: los tiles del lanzador aparecen/desaparecen al instante
async function cambiarPlan(row: any, plan: string) {
  await api.post('/companies/' + row.id + '/plan', { plan })
  msg.value = { type: 'success', text: row.razon_social + ' ahora está en plan ' + plan + '.' }
  if (row.id === companyStore.activeId) await planStore.load(row.id)
  load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 14px;">Empresas</h2>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;">{{ msg.text }}</Message>
    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column field="ruc" header="RUC" />
      <Column field="razon_social" header="Razón social" />
      <Column header="Plan" style="width:170px">
        <template #body="{ data }">
          <Select :modelValue="data.plan" :options="planes" optionLabel="label" optionValue="value"
                  size="small" @update:modelValue="(v) => cambiarPlan(data, v)" />
        </template>
      </Column>
      <Column field="dir_matriz" header="Dirección" />
      <Column header="Certificado .p12">
        <template #body="{ data }">
          <Button label="Cargar .p12" icon="pi pi-shield" size="small" outlined @click="certDialog = data" />
        </template>
      </Column>
    </DataTable>

    <Dialog :visible="!!certDialog" modal header="Cargar certificado de firma (.p12)" style="width:420px" @update:visible="certDialog=null">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <p style="margin:0; font-size:13px; color:#64748b;">Con el certificado cargado, la facturación firma y envía al SRI automáticamente.</p>
        <input ref="fileRef" type="file" accept=".p12,.pfx" />
        <label style="display:flex; flex-direction:column; gap:4px;">Clave del certificado
          <Password v-model="clave" :feedback="false" toggleMask fluid /></label>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="certDialog=null" />
        <Button label="Cargar y validar" @click="subirCert" />
      </template>
    </Dialog>
  </div>
</template>
