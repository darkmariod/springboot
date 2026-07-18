<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Select from 'primevue/select'
import Password from 'primevue/password'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

// "Administración configuración e-documents" — mismo formulario que el creador de KVS.
// Se configura una vez por cliente: credenciales SRI, firma, tiempos y correo.
const company = useCompanyStore()
const companies = ref<any[]>([])
const companyId = ref<number | null>(null)
const cfg = ref<any>({})
const claveFirma = ref('')
const claveSri = ref('')
const clavecorreo = ref('')
const msg = ref<any>(null)
const guardando = ref(false)
const probando = ref(false)
const fileRef = ref<HTMLInputElement>()

const ambientes = [
  { label: 'PRUEBAS', value: 1 },
  { label: 'PRODUCCION', value: 2 },
]
const tokens = [
  { label: 'ANF CERTIFICADO EXPORTADO', value: 'ANF' },
  { label: 'SECURITY DATA', value: 'SECURITY_DATA' },
  { label: 'UANATACA', value: 'UANATACA' },
  { label: 'BANCO CENTRAL', value: 'BCE' },
  { label: 'LAZZATE / OTRO', value: 'OTRO' },
]
const estados = [
  { label: 'ACTIVO', value: 'ACTIVO' },
  { label: 'INACTIVO', value: 'INACTIVO' },
]
const _empresaActual = computed(() => companies.value.find((c: any) => c.id === companyId.value))
const firmaVencida = computed(() => {
  if (!cfg.value.cert_valido_hasta) return false
  return new Date(cfg.value.cert_valido_hasta) < new Date()
})

async function load() {
  companies.value = (await api.get('/companies')).data
  companyId.value = companyId.value ?? company.activeId ?? companies.value[0]?.id ?? null
  if (companyId.value) {
    cfg.value = (await api.get('/companies/' + companyId.value + '/edoc-config')).data
  }
  claveFirma.value = ''; claveSri.value = ''; clavecorreo.value = ''
}

async function guardar() {
  guardando.value = true; msg.value = null
  const form = new FormData()
  const campos = ['sri_usuario', 'sri_url_produccion', 'sri_url_pruebas', 'tipo_token',
    'tiempo_generar', 'tiempo_firmar', 'tiempo_enviar', 'tiempo_autorizar',
    'smtp_host', 'smtp_port', 'smtp_user', 'email_envio', 'ambiente', 'edoc_estado']
  for (const k of campos) {
    if (cfg.value[k] !== null && cfg.value[k] !== undefined) form.append(k, String(cfg.value[k]))
  }
  form.append('smtp_ssl', cfg.value.smtp_ssl ? '1' : '0')
  form.append('modo_online', cfg.value.modo_online ? '1' : '0')
  if (claveSri.value) form.append('sri_clave', claveSri.value)
  if (clavecorreo.value) form.append('smtp_password', clavecorreo.value)
  const file = fileRef.value?.files?.[0]
  if (file) {
    form.append('certificado', file)
    form.append('clave_firma', claveFirma.value)
  }
  try {
    const res = await api.post('/companies/' + companyId.value + '/edoc-config', form)
    cfg.value = res.data.config
    msg.value = { type: 'success', text: res.data.mensaje }
    if (fileRef.value) fileRef.value.value = ''
    claveFirma.value = ''; claveSri.value = ''; clavecorreo.value = ''
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
  } finally { guardando.value = false }
}

async function probarCorreo() {
  const destino = prompt('¿A qué correo mando la prueba?', cfg.value.email_envio)
  if (!destino) return
  probando.value = true; msg.value = null
  try {
    const res = await api.post('/companies/' + companyId.value + '/smtp/test', { destinatario: destino })
    msg.value = { type: 'success', text: res.data.mensaje }
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.error ?? 'Falló el envío. Revisá servidor, puerto y clave.' }
  } finally { probando.value = false }
}
onMounted(load)
</script>

<template>
  <div style="padding:18px; height:100%; overflow:auto;">
    <div class="kvs-window">
      <div class="kvs-window-title">Administración configuración e-documents</div>
      <p class="kvs-window-sub">
        El formulario le permite administrar la información de la configuración para e-documents.
        Se configura <b>una sola vez por empresa</b>.
      </p>

      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin:0 12px 12px;">{{ msg.text }}</Message>

      <div style="padding:0 12px 12px;">
        <!-- Identificación -->
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req">*</span> Código:</label>
          <InputText :modelValue="cfg.codigo" disabled class="kvs-in" style="max-width:120px" />
          <label class="kvs-lbl" style="margin-left:20px;"><span class="req">*</span> Nombre:</label>
          <Select v-model="companyId" :options="companies" optionLabel="razon_social" optionValue="id"
                  class="kvs-in" style="flex:1" @change="load" />
        </div>

        <!-- Credenciales del portal SRI -->
        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Credenciales SRI</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Usuario Sri:</label>
            <InputText v-model="cfg.sri_usuario" placeholder="RUC" class="kvs-in" />
            <label class="kvs-lbl" style="margin-left:20px;">Clave Sri:</label>
            <Password v-model="claveSri" :feedback="false" toggleMask class="kvs-in"
                      placeholder="vacía = no cambiar" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> URL producción:</label>
            <InputText v-model="cfg.sri_url_produccion" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> URL pruebas:</label>
            <InputText v-model="cfg.sri_url_pruebas" class="kvs-in" />
          </div>
        </fieldset>

        <!-- Firma electrónica -->
        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Firma electrónica</legend>
          <div class="kvs-row">
            <label class="kvs-lbl">Archivo firma:</label>
            <input ref="fileRef" type="file" accept=".p12,.pfx" style="flex:1; font-size:13px;" />
            <label class="kvs-lbl" style="margin-left:20px;">Clave Firma:</label>
            <Password v-model="claveFirma" :feedback="false" toggleMask class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">F. Emisión Firma:</label>
            <InputText :modelValue="cfg.cert_emitido_desde ?? '—'" disabled class="kvs-in" style="max-width:150px" />
            <label class="kvs-lbl" style="margin-left:20px;">F. Vencimiento Firma:</label>
            <InputText :modelValue="cfg.cert_valido_hasta ?? '—'" disabled class="kvs-in" style="max-width:150px" />
            <div style="margin-left:16px;">
              <Tag v-if="firmaVencida" value="FIRMA VENCIDA — renovar" severity="danger" />
              <Tag v-else-if="cfg.firma_cargada" :value="'Firma cargada: ' + (cfg.cert_sujeto ?? '')" severity="success" />
              <Tag v-else value="Sin firma cargada" severity="warn" />
            </div>
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Tipo Token:</label>
            <Select v-model="cfg.tipo_token" :options="tokens" optionLabel="label" optionValue="value"
                    class="kvs-in" placeholder="Elegí el proveedor de la firma" />
          </div>
        </fieldset>

        <!-- Tiempos de espera -->
        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Tiempos de espera (milisegundos)</legend>
          <div class="kvs-row">
            <label class="kvs-lbl">Tiempo generar:</label>
            <InputNumber v-model="cfg.tiempo_generar" :useGrouping="false" class="kvs-in" style="max-width:130px" />
            <label class="kvs-lbl" style="margin-left:20px;">Tiempo firmar:</label>
            <InputNumber v-model="cfg.tiempo_firmar" :useGrouping="false" class="kvs-in" style="max-width:130px" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Tiempo enviar:</label>
            <InputNumber v-model="cfg.tiempo_enviar" :useGrouping="false" class="kvs-in" style="max-width:130px" />
            <label class="kvs-lbl" style="margin-left:20px;">Tiempo autorizar:</label>
            <InputNumber v-model="cfg.tiempo_autorizar" :useGrouping="false" class="kvs-in" style="max-width:130px" />
          </div>
        </fieldset>

        <!-- Servidor de correo -->
        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Servidor de correo</legend>
          <p style="margin:0 0 10px; font-size:12px; color:#64748b;">
            Con esto la factura llega sola al correo del cliente (XML + PDF). El SRI lo exige.
            Con Gmail usá una <b>contraseña de aplicación</b>, no la clave normal de la cuenta.
          </p>
          <div class="kvs-row">
            <label class="kvs-lbl">Servidor correo:</label>
            <InputText v-model="cfg.smtp_host" placeholder="smtp.gmail.com" class="kvs-in" />
            <label class="kvs-lbl" style="margin-left:20px;">Puerto:</label>
            <InputNumber v-model="cfg.smtp_port" :useGrouping="false" class="kvs-in" style="max-width:100px" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Usuario correo:</label>
            <InputText v-model="cfg.smtp_user" placeholder="facturacion@suempresa.com" class="kvs-in" />
            <label class="kvs-lbl" style="margin-left:20px;">Clave:</label>
            <Password v-model="clavecorreo" :feedback="false" toggleMask class="kvs-in"
                      placeholder="vacía = no cambiar" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Correo de envío:</label>
            <InputText v-model="cfg.email_envio" placeholder="facturas@suempresa.com" class="kvs-in" />
            <label style="display:flex; align-items:center; gap:7px; margin-left:20px; font-size:13px;">
              <Checkbox v-model="cfg.smtp_ssl" :binary="true" /> Usar SSL
            </label>
          </div>
        </fieldset>

        <!-- Estado y ambiente -->
        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Estado</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Tipo Ambiente:</label>
            <Select v-model="cfg.ambiente" :options="ambientes" optionLabel="label" optionValue="value" class="kvs-in" />
            <label class="kvs-lbl" style="margin-left:20px;"><span class="req">*</span> Estado:</label>
            <Select v-model="cfg.edoc_estado" :options="estados" optionLabel="label" optionValue="value" class="kvs-in" />
          </div>
        </fieldset>
      </div>

      <!-- Botonera al pie, como KVS -->
      <div class="kvs-footer">
        <Button label="Probar correo" icon="pi pi-send" size="small" outlined :loading="probando" @click="probarCorreo" />
        <Button label="Guardar" icon="pi pi-save" size="small" :loading="guardando" @click="guardar" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.kvs-window {
  background: #fff; border: 1px solid #b9c2cc; border-radius: 4px; max-width: 1080px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
}
.kvs-window-title {
  background: linear-gradient(#3d8b8b, #2a6b6b); color: #fff; font-weight: 600; font-size: 13px;
  padding: 6px 12px; border-radius: 3px 3px 0 0;
}
.kvs-window-sub { margin: 10px 12px; font-size: 12px; color: #64748b; }
.kvs-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.kvs-lbl { font-size: 13px; color: #37474f; white-space: nowrap; min-width: 132px; text-align: right; }
.kvs-lbl .req { color: #d93025; font-weight: 700; }
.kvs-in { flex: 1; }
.kvs-footer {
  display: flex; justify-content: flex-end; gap: 8px; padding: 10px 12px;
  border-top: 1px solid #e2e5ea; background: #f7f9fb; border-radius: 0 0 3px 3px;
}
</style>
