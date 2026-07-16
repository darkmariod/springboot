<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Select from 'primevue/select'
import Password from 'primevue/password'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

// Pantalla "Configuración de firma electrónica" — replica la del creador de KVS:
// cargar la firma (.p12), su clave, el correo de envío y los endpoints del SRI.
const company = useCompanyStore()
const companies = ref<any[]>([])
const companyId = ref<number | null>(null)
const clave = ref('')
const email = ref('')
const ambiente = ref(2)
const msg = ref<any>(null)
const cargando = ref(false)
const fileRef = ref<HTMLInputElement>()

const ambientes = [
  { label: 'Pruebas (SRI celcer)', value: 1 },
  { label: 'Producción (SRI cel)', value: 2 },
]
// Endpoints del SRI (los mismos que usa la librería para enviar y autorizar)
const endpoints = computed(() => ({
  recepcion: ambiente.value === 1
    ? 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl'
    : 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl',
  autorizacion: ambiente.value === 1
    ? 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'
    : 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
}))
const empresaActual = computed(() => companies.value.find((c) => c.id === companyId.value))

async function load() {
  companies.value = (await api.get('/companies')).data
  companyId.value = company.activeId ?? companies.value[0]?.id ?? null
  const c = empresaActual.value
  if (c) { email.value = c.email_envio ?? ''; ambiente.value = Number(c.ambiente ?? 2) }
}
async function guardar() {
  const file = fileRef.value?.files?.[0]
  if (!file) { msg.value = { type: 'error', text: 'Seleccioná el archivo de firma (.p12)' }; return }
  if (!clave.value) { msg.value = { type: 'error', text: 'Ingresá la clave de la firma' }; return }
  cargando.value = true; msg.value = null
  const form = new FormData()
  form.append('certificado', file)
  form.append('clave', clave.value)
  form.append('email_envio', email.value)
  form.append('ambiente', String(ambiente.value))
  try {
    const res = await api.post('/companies/' + companyId.value + '/certificate', form)
    msg.value = { type: 'success', text: res.data.mensaje ?? 'Firma configurada. La facturación ya firma y envía al SRI.' }
    clave.value = ''
    load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.errors?.clave?.[0] ?? err.response?.data?.message ?? 'No se pudo cargar la firma.' }
  } finally { cargando.value = false }
}
onMounted(load)
</script>

<template>
  <div style="padding:24px; max-width:760px;">
    <h2 style="margin:0 0 4px;">Configuración de firma electrónica</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 20px;">
      Se configura una sola vez por empresa. Con la firma cargada, cada factura se firma y se
      envía al SRI automáticamente. Solo hay que renovarla cuando venza el certificado.
    </p>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:16px;">{{ msg.text }}</Message>

    <div style="background:#fff; border:1px solid #e2e5ea; border-radius:12px; padding:20px; display:flex; flex-direction:column; gap:16px;">
      <label style="display:flex; flex-direction:column; gap:6px;">
        <span style="font-weight:600; font-size:13px;">Empresa</span>
        <Select v-model="companyId" :options="companies" optionLabel="razon_social" optionValue="id" @change="load" fluid />
      </label>

      <div style="border-top:1px solid #f1f3f6; padding-top:16px;">
        <p style="margin:0 0 12px; font-weight:600; color:#4a3220;">1. Firma electrónica (.p12)</p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Archivo de firma *</span>
            <input ref="fileRef" type="file" accept=".p12,.pfx" />
          </label>
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Clave de la firma *</span>
            <Password v-model="clave" :feedback="false" toggleMask fluid />
          </label>
        </div>
        <div v-if="empresaActual" style="margin-top:10px;">
          <Tag v-if="empresaActual.cert_sujeto || empresaActual.certificado_p12"
               :value="'Firma cargada' + (empresaActual.cert_valido_hasta ? ' · vence ' + String(empresaActual.cert_valido_hasta).slice(0,10) : '')"
               severity="success" />
          <Tag v-else value="Sin firma cargada" severity="warn" />
        </div>
      </div>

      <div style="border-top:1px solid #f1f3f6; padding-top:16px;">
        <p style="margin:0 0 12px; font-weight:600; color:#4a3220;">2. Envío</p>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Correo desde el que se envían las facturas</span>
            <InputText v-model="email" placeholder="facturacion@tuempresa.com" fluid />
          </label>
          <label style="display:flex; flex-direction:column; gap:6px;">
            <span style="font-size:13px;">Ambiente</span>
            <Select v-model="ambiente" :options="ambientes" optionLabel="label" optionValue="value" fluid />
          </label>
        </div>
      </div>

      <div style="border-top:1px solid #f1f3f6; padding-top:16px;">
        <p style="margin:0 0 12px; font-weight:600; color:#4a3220;">3. Webservices del SRI (automáticos)</p>
        <div style="display:flex; flex-direction:column; gap:8px; font-size:12px; color:#64748b;">
          <div><b>Recepción:</b> <span style="font-family:monospace;">{{ endpoints.recepcion }}</span></div>
          <div><b>Autorización:</b> <span style="font-family:monospace;">{{ endpoints.autorizacion }}</span></div>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; padding-top:8px;">
        <Button label="Guardar configuración" icon="pi pi-shield" :loading="cargando" @click="guardar" />
      </div>
    </div>
  </div>
</template>
