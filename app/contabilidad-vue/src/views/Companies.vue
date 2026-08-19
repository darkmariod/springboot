<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Password from 'primevue/password'
import Message from 'primevue/message'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { usePlanStore } from '../stores/plan'

const companyStore = useCompanyStore()
const planStore = usePlanStore()
const rows = ref<any[]>([])
const loading = ref(true)
const certDialog = ref<any>(null)
const logoDialog = ref<any>(null)
const clave = ref('')
const msg = ref<any>(null)
const fileRef = ref<HTMLInputElement>()
// Deben coincidir con config/planes.php (backend)
const planes = [
  { label: 'Emprendedor — $49 (POS + inventario, sin factura SRI)', value: 'emprendedor' },
  { label: 'Negocio — $99 (con facturación electrónica SRI)', value: 'negocio' },
  { label: 'Profesional — $149 (todo + series y contabilidad)', value: 'profesional' },
  { label: 'Empresarial — personalizado', value: 'empresarial' },
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
// ── Edición de la empresa ──
// El RUC debe ser el del titular del .p12, si no el SRI rechaza la factura.
const editDialog = ref<any>(null)
const guardando = ref(false)
const ambientes = [
  { label: 'PRUEBAS (celcer)', value: 1 },
  { label: 'PRODUCCIÓN (cel)', value: 2 },
]
function abrirEditar(row: any) {
  editDialog.value = {
    id: row.id,
    ruc: row.ruc, razon_social: row.razon_social, nombre_comercial: row.nombre_comercial,
    dir_matriz: row.dir_matriz, estab: row.estab, pto_emi: row.pto_emi,
    secuencial: Number(row.secuencial ?? 1), regimen: row.regimen,
    obligado_contabilidad: !!row.obligado_contabilidad,
    telefonos: row.telefonos, agente_retencion: row.agente_retencion,
    contribuyente_especial: row.contribuyente_especial,
    sitio_web: row.sitio_web, nota_pie: row.nota_pie,
    ambiente: Number(row.ambiente ?? 1), email_envio: row.email_envio,
  }
}
async function guardarEmpresa() {
  guardando.value = true
  msg.value = null
  try {
    const { id, ...datos } = editDialog.value
    const res = await api.put('/companies/' + id, datos)
    msg.value = { type: 'success', text: res.data.mensaje ?? 'Empresa actualizada.' }
    editDialog.value = null
    await load()
    await companyStore.load()
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
  } finally {
    guardando.value = false
  }
}

// ── Logo del RIDE ──
// Se imprime en la factura; cada cliente sube el suyo.
const logoRef = ref<HTMLInputElement>()
async function subirLogo(row: any) {
  const file = logoRef.value?.files?.[0]
  if (!file) { msg.value = { type: 'error', text: 'Elegí una imagen (PNG o JPG).' }; return }
  const form = new FormData()
  form.append('logo', file)
  try {
    const res = await api.post('/companies/' + row.id + '/logo', form)
    msg.value = { type: 'success', text: res.data.mensaje }
    await load(); await companyStore.load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.errors?.logo?.[0] ?? 'No se pudo subir el logo.' }
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
      <Column header="Logo (sale en la factura)" style="width:210px">
        <template #body="{ data }">
          <div style="display:flex; align-items:center; gap:8px;">
            <img v-if="data.logo" :src="data.logo" alt=""
                 style="max-width:64px; max-height:30px; object-fit:contain; border:1px solid #e2e5ea; border-radius:4px;" />
            <span v-else style="font-size:12px; color:#94a3b8;">sin logo</span>
            <Button label="Cambiar" icon="pi pi-image" size="small" outlined @click="logoDialog = data" />
          </div>
        </template>
      </Column>
      <Column header="Certificado .p12">
        <template #body="{ data }">
          <Button label="Cargar .p12" icon="pi pi-shield" size="small" outlined @click="certDialog = data" />
        </template>
      </Column>
      <Column header="" style="width:110px">
        <template #body="{ data }">
          <Button label="Editar" icon="pi pi-pencil" size="small" @click="abrirEditar(data)" />
        </template>
      </Column>
    </DataTable>

    <!-- Editar datos de la empresa -->
    <Dialog :visible="!!editDialog" modal header="Editar empresa" style="width:640px" @update:visible="editDialog=null">
      <div v-if="editDialog">
        <Message severity="warn" :closable="false" style="margin-bottom:12px;">
          El <b>RUC</b> debe ser el del titular del certificado <b>.p12</b>. Si no coincide, el SRI rechaza la factura.
        </Message>

        <fieldset class="kvs-fieldset">
          <legend>Datos de la empresa</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> RUC:</label>
            <InputText v-model="editDialog.ruc" maxlength="13" class="kvs-in" style="max-width:190px" />
            <label class="kvs-lbl" style="margin-left:14px;">Régimen:</label>
            <InputText v-model="editDialog.regimen" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Razón social:</label>
            <InputText v-model="editDialog.razon_social" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Nombre comercial:</label>
            <InputText v-model="editDialog.nombre_comercial" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Dir. matriz:</label>
            <InputText v-model="editDialog.dir_matriz" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Correo de envío:</label>
            <InputText v-model="editDialog.email_envio" class="kvs-in" />
            <label class="kvs-lbl" style="margin-left:14px;">Teléfonos:</label>
            <InputText v-model="editDialog.telefonos" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Sitio web:</label>
            <InputText v-model="editDialog.sitio_web" class="kvs-in" placeholder="www.miempresa.com" />
          </div>
        </fieldset>

        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Datos que salen impresos en la factura (RIDE)</legend>
          <div class="kvs-row">
            <label class="kvs-lbl">Agente de retención Nro.:</label>
            <InputText v-model="editDialog.agente_retencion" class="kvs-in" placeholder="Resolución NAC-..." />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Contribuyente especial Nro.:</label>
            <InputText v-model="editDialog.contribuyente_especial" class="kvs-in" placeholder="Resolución Nro." />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Nota al pie:</label>
            <InputText v-model="editDialog.nota_pie" class="kvs-in"
                       placeholder="NOTA: SALIDA LA MERCADERÍA NO SE ACEPTA CAMBIOS NI DEVOLUCIONES" />
          </div>
        </fieldset>

        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Facturación electrónica</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Establecimiento:</label>
            <InputText v-model="editDialog.estab" maxlength="3" class="kvs-in" style="max-width:90px" />
            <label class="kvs-lbl" style="margin-left:14px;"><span class="req">*</span> Pto. emisión:</label>
            <InputText v-model="editDialog.pto_emi" maxlength="3" class="kvs-in" style="max-width:90px" />
            <label class="kvs-lbl" style="margin-left:14px;">Secuencial:</label>
            <InputNumber v-model="editDialog.secuencial" :useGrouping="false" class="kvs-in" style="max-width:120px" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Ambiente:</label>
            <Select v-model="editDialog.ambiente" :options="ambientes" optionLabel="label" optionValue="value"
                    class="kvs-in" style="max-width:230px" />
            <label style="display:flex; align-items:center; gap:7px; margin-left:18px; font-size:13px; white-space:nowrap;">
              <Checkbox v-model="editDialog.obligado_contabilidad" :binary="true" /> Obligado a llevar contabilidad
            </label>
          </div>
        </fieldset>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="editDialog=null" />
        <Button label="Guardar" icon="pi pi-save" :loading="guardando" @click="guardarEmpresa" />
      </template>
    </Dialog>

    <Dialog :visible="!!logoDialog" modal header="Logo que se imprime en la factura" style="width:430px"
            @update:visible="logoDialog=null">
      <div style="display:flex; flex-direction:column; gap:12px;">
        <p style="margin:0; font-size:13px; color:#64748b;">
          PNG o JPG, máximo 1 MB. Aparece arriba a la izquierda del comprobante.
        </p>
        <img v-if="logoDialog?.logo" :src="logoDialog.logo" alt=""
             style="max-width:180px; max-height:70px; object-fit:contain; border:1px solid #e2e5ea; border-radius:6px; padding:6px;" />
        <input ref="logoRef" type="file" accept="image/png,image/jpeg" />
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="logoDialog=null" />
        <Button label="Subir logo" icon="pi pi-upload" @click="subirLogo(logoDialog); logoDialog=null" />
      </template>
    </Dialog>

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
