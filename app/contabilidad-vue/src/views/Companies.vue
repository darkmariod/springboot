<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
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
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'
import { useCompanyStore } from '../stores/company'
import { usePlanStore } from '../stores/plan'

const auth = useAuthStore()
const companyStore = useCompanyStore()
const planStore = usePlanStore()
// El superadministrador (operador de la plataforma) ve todas las empresas, crea las nuevas y cambia plan y vencimiento. El
// administrador de un cliente ve solo la suya, sin controles de plan ni alta: el servidor lo hace cumplir (403), esto solo lo oculta.
const esSuper = computed(() => auth.esSuperadmin)
const miEmpresa = computed(() => rows.value[0] ?? null)
const rows = ref<any[]>([])
const loading = ref(true)
const certDialog = ref<any>(null)
const logoDialog = ref<any>(null)
const clave = ref('')
const msg = ref<any>(null)
const fileRef = ref<HTMLInputElement>()
// Deben coincidir con config/planes.php (backend)
const planes = [
  { label: 'Básico — $85 (inventario y punto de venta)', value: 'basico' },
  { label: 'Negocio — $145 (con facturación electrónica SRI)', value: 'negocio' },
  { label: 'Completo — $225 (con contabilidad y sucursales)', value: 'completo' },
]

async function load() {
  loading.value = true
  try {
    rows.value = (await api.get('/companies')).data
  } finally {
    loading.value = false
  }
}

/** Lo que dice el servidor cuando rechaza algo: sus errores de validación o su mensaje; si no hay nada que mostrar, el texto de respaldo. */
function textoDeError(err: any, respaldo: string): string {
  const e = err?.response?.data?.errors
  if (e) return Object.values(e).flat().join(' · ')
  return err?.response?.data?.message ?? respaldo
}

// ── Plan y vencimiento (solo el superadministrador) ──
const soloFecha = (v?: string | null) => (v ? String(v).slice(0, 10) : '')
function diasParaVencer(v?: string | null): number | null {
  const f = soloFecha(v)
  if (!f) return null
  const hoy = new Date(); hoy.setHours(0, 0, 0, 0)
  return Math.round((new Date(f + 'T00:00:00').getTime() - hoy.getTime()) / 86400000)
}
/** Vigente / Vence en N días / Vencido. Igual que el servidor, el plan deja de servir desde el día del vencimiento. */
function estadoPlan(row: any): { texto: string; severity: string } {
  const d = diasParaVencer(row.plan_vence)
  if (d === null) return { texto: 'Sin vencimiento', severity: 'secondary' }
  if (d <= 0) return { texto: 'Vencido', severity: 'danger' }
  if (d <= 30) return { texto: d === 1 ? 'Vence en 1 día' : `Vence en ${d} días`, severity: 'warn' }
  return { texto: 'Vigente', severity: 'success' }
}
const nombrePlan = (plan?: string) => planes.find((p) => p.value === plan)?.label.split(' — ')[0] ?? plan ?? ''

// ── Empresa nueva (solo el superadministrador) ──
const nuevaDialog = ref<any>(null)
const creando = ref(false)
const errorNueva = ref('')
function enUnAnio(): string {
  const d = new Date(); d.setDate(d.getDate() + 365)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function abrirNueva() {
  errorNueva.value = ''
  nuevaDialog.value = {
    ruc: '', razon_social: '', nombre_comercial: '', dir_matriz: '', plan: 'basico', plan_vence: enUnAnio(),
    admin_nombre: '', admin_email: '', admin_password: '', admin_password_confirmation: '',
  }
}
async function crearEmpresa() {
  const f = nuevaDialog.value
  errorNueva.value = ''
  if (!/^\d{13}$/.test(f.ruc)) { errorNueva.value = 'El RUC debe tener exactamente 13 dígitos.'; return }
  if (!f.razon_social.trim() || !f.dir_matriz.trim()) { errorNueva.value = 'Escribe la razón social y la dirección de la matriz.'; return }
  if (!f.admin_nombre.trim() || !f.admin_email.trim()) { errorNueva.value = 'Escribe el nombre y el correo del administrador.'; return }
  if (f.admin_password.length < 10) { errorNueva.value = 'La contraseña debe tener al menos 10 caracteres.'; return }
  if (f.admin_password !== f.admin_password_confirmation) { errorNueva.value = 'Las dos contraseñas no coinciden.'; return }
  creando.value = true
  try {
    const res = await api.post('/companies', {
      ...f,
      nombre_comercial: f.nombre_comercial || null,
      plan_vence: f.plan_vence || null,
    })
    nuevaDialog.value = null
    msg.value = { type: 'success', text: `Empresa «${res.data.company.razon_social}» creada. Su administrador entra con ${res.data.admin.email} y la contraseña que escribiste.` }
    await load()
    await companyStore.load()
  } catch (err: any) {
    errorNueva.value = textoDeError(err, 'No se pudo crear la empresa.')
  } finally {
    creando.value = false
  }
}
async function subirCert() {
  const file = fileRef.value?.files?.[0]
  if (!file) { msg.value = { type: 'error', text: 'Selecciona el archivo .p12' }; return }
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
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo guardar.') }
  } finally {
    guardando.value = false
  }
}

// ── Logo del RIDE ──
// Se imprime en la factura; cada cliente sube el suyo.
const logoRef = ref<HTMLInputElement>()
async function subirLogo(row: any) {
  const file = logoRef.value?.files?.[0]
  if (!file) { msg.value = { type: 'error', text: 'Elige una imagen (PNG o JPG).' }; return }
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
  msg.value = null
  try {
    await api.post('/companies/' + row.id + '/plan', { plan, plan_vence: soloFecha(row.plan_vence) || null })
    msg.value = { type: 'success', text: row.razon_social + ' ahora está en plan ' + nombrePlan(plan) + '.' }
    if (row.id === companyStore.activeId) await planStore.load(row.id)
  } catch (err: any) {
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo cambiar el plan.') }
  }
  load()
}
async function cambiarVencimiento(row: any, fecha: string) {
  msg.value = null
  try {
    await api.post('/companies/' + row.id + '/plan', { plan: row.plan, plan_vence: fecha || null })
    msg.value = { type: 'success', text: fecha ? `${row.razon_social}: el plan vence el ${fecha}.` : `${row.razon_social}: el plan queda sin vencimiento.` }
    if (row.id === companyStore.activeId) await planStore.load(row.id)
  } catch (err: any) {
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo cambiar el vencimiento.') }
  }
  load()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin:0 0 14px;">
      <h2 style="margin:0;" data-testid="titulo-empresas">{{ esSuper ? 'Empresas' : 'Mi empresa' }}</h2>
      <Button v-if="esSuper" label="Nueva empresa" icon="pi pi-plus" data-testid="nueva-empresa" @click="abrirNueva" />
    </div>
    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:14px;" data-testid="mensaje">{{ msg.text }}</Message>

    <!-- Administrador de un cliente: solo su empresa. Sin plan que cambiar ni empresas que crear. -->
    <div v-if="!esSuper" data-testid="mi-empresa">
      <div v-if="miEmpresa" class="mi-empresa">
        <div class="mi-fila"><span class="mi-lbl">Razón social</span><b>{{ miEmpresa.razon_social }}</b></div>
        <div class="mi-fila"><span class="mi-lbl">RUC</span>{{ miEmpresa.ruc }}</div>
        <div class="mi-fila"><span class="mi-lbl">Dirección</span>{{ miEmpresa.dir_matriz }}</div>
        <div class="mi-fila">
          <span class="mi-lbl">Tu plan</span>
          {{ nombrePlan(miEmpresa.plan) }}
          <template v-if="soloFecha(miEmpresa.plan_vence)"> · vence el {{ soloFecha(miEmpresa.plan_vence) }}</template>
          <Tag :value="estadoPlan(miEmpresa).texto" :severity="estadoPlan(miEmpresa).severity" style="margin-left:8px;" />
        </div>
        <div class="mi-fila">
          <span class="mi-lbl">Logo (sale en la factura)</span>
          <img v-if="miEmpresa.logo" :src="miEmpresa.logo" alt=""
               style="max-width:64px; max-height:30px; object-fit:contain; border:1px solid #e2e5ea; border-radius:4px;" />
          <span v-else style="font-size:12px; color:#94a3b8;">sin logo</span>
        </div>
        <div style="display:flex; gap:8px; margin-top:16px; flex-wrap:wrap;">
          <Button label="Editar datos" icon="pi pi-pencil" @click="abrirEditar(miEmpresa)" />
          <Button label="Cambiar logo" icon="pi pi-image" outlined @click="logoDialog = miEmpresa" />
          <Button label="Cargar .p12" icon="pi pi-shield" outlined @click="certDialog = miEmpresa" />
        </div>
        <p style="margin:14px 0 0; font-size:12px; color:#64748b;">Para cambiar de plan escribe a tu asesor de HasReset.</p>
      </div>
      <p v-else-if="!loading" style="color:#64748b;">No se encontró tu empresa.</p>
    </div>

    <!-- Superadministrador: todas las empresas, con su plan y su vencimiento -->
    <DataTable v-else :value="rows" :loading="loading" size="small" stripedRows data-testid="tabla-empresas">
      <Column field="ruc" header="RUC" />
      <Column field="razon_social" header="Razón social" />
      <Column header="Plan" style="width:170px">
        <template #body="{ data }">
          <Select :modelValue="data.plan" :options="planes" optionLabel="label" optionValue="value"
                  size="small" :data-testid="'plan-' + data.id" @update:modelValue="(v) => cambiarPlan(data, v)" />
        </template>
      </Column>
      <Column header="Vencimiento" style="width:250px">
        <template #body="{ data }">
          <div style="display:flex; align-items:center; gap:8px;">
            <InputText type="date" :modelValue="soloFecha(data.plan_vence)" class="kvs-in" style="max-width:150px"
                       :data-testid="'vence-' + data.id" @change="(e: any) => cambiarVencimiento(data, e.target.value)" />
            <Tag :value="estadoPlan(data).texto" :severity="estadoPlan(data).severity" :data-testid="'estado-' + data.id" />
          </div>
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

    <!-- Empresa nueva (superadministrador) -->
    <Dialog :visible="!!nuevaDialog" modal header="Nueva empresa" style="width:640px" @update:visible="nuevaDialog=null">
      <div v-if="nuevaDialog" data-testid="dialogo-nueva">
        <Message severity="info" :closable="false" style="margin-bottom:12px;">
          Se crea con su plan de cuentas, su bodega principal y el punto de emisión 001-001, en ambiente de <b>pruebas</b> del SRI.
          Además se crea el primer <b>administrador</b> de esa empresa: solo verá la suya.
        </Message>
        <Message v-if="errorNueva" severity="error" :closable="false" style="margin-bottom:12px;" data-testid="error-nueva">{{ errorNueva }}</Message>

        <fieldset class="kvs-fieldset">
          <legend>Datos de la empresa</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> RUC:</label>
            <InputText v-model="nuevaDialog.ruc" maxlength="13" inputmode="numeric" class="kvs-in" style="max-width:190px" data-testid="n-ruc" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Razón social:</label>
            <InputText v-model="nuevaDialog.razon_social" class="kvs-in" data-testid="n-razon" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Nombre comercial:</label>
            <InputText v-model="nuevaDialog.nombre_comercial" class="kvs-in" data-testid="n-comercial" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Dir. matriz:</label>
            <InputText v-model="nuevaDialog.dir_matriz" class="kvs-in" data-testid="n-dir" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Plan:</label>
            <Select v-model="nuevaDialog.plan" :options="planes" optionLabel="label" optionValue="value" class="kvs-in" style="max-width:330px" data-testid="n-plan" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Vence:</label>
            <InputText v-model="nuevaDialog.plan_vence" type="date" class="kvs-in" style="max-width:170px" data-testid="n-vence" />
            <span style="margin-left:10px; font-size:12px; color:#64748b;">Por defecto, dentro de un año.</span>
          </div>
        </fieldset>

        <fieldset class="kvs-fieldset" style="margin-top:14px;">
          <legend>Primer administrador de la empresa</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Nombre:</label>
            <InputText v-model="nuevaDialog.admin_nombre" class="kvs-in" data-testid="n-admin-nombre" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Correo:</label>
            <InputText v-model="nuevaDialog.admin_email" type="email" autocomplete="off" class="kvs-in" data-testid="n-admin-email" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Contraseña:</label>
            <Password v-model="nuevaDialog.admin_password" :feedback="false" toggleMask autocomplete="new-password" class="kvs-in"
                      inputId="n-admin-clave" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Repite la contraseña:</label>
            <Password v-model="nuevaDialog.admin_password_confirmation" :feedback="false" toggleMask autocomplete="new-password" class="kvs-in"
                      inputId="n-admin-clave2" />
          </div>
          <p style="margin:6px 0 0; font-size:12px; color:#64748b;">Mínimo 10 caracteres. Entrégasela a tu cliente por un medio seguro; no se vuelve a mostrar.</p>
        </fieldset>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="nuevaDialog=null" />
        <Button label="Crear empresa" icon="pi pi-check" :loading="creando" data-testid="crear-empresa" @click="crearEmpresa" />
      </template>
    </Dialog>

    <!-- Editar datos de la empresa -->
    <Dialog :visible="!!editDialog" modal header="Editar empresa" style="width:640px" @update:visible="editDialog=null">
      <div v-if="editDialog">
        <Message severity="warn" :closable="false" style="margin-bottom:12px;">
          El <b>RUC</b> debe ser el del titular del certificado <b>.p12</b>. Si no coincide, el SRI rechaza la factura.
        </Message>

        <fieldset class="kvs-fieldset">
          <legend>Datos de la empresa</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> RUC:</label>
            <InputText v-model="editDialog.ruc" maxlength="13" class="kvs-in" style="max-width:190px" />
            <label class="kvs-lbl" style="margin-left:14px;">Régimen:</label>
            <InputText v-model="editDialog.regimen" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Razón social:</label>
            <InputText v-model="editDialog.razon_social" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Nombre comercial:</label>
            <InputText v-model="editDialog.nombre_comercial" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Dir. matriz:</label>
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
            <label class="kvs-lbl"><span class="req-star">*</span> Establecimiento:</label>
            <InputText v-model="editDialog.estab" maxlength="3" class="kvs-in" style="max-width:90px" />
            <label class="kvs-lbl" style="margin-left:14px;"><span class="req-star">*</span> Pto. emisión:</label>
            <InputText v-model="editDialog.pto_emi" maxlength="3" class="kvs-in" style="max-width:90px" />
            <label class="kvs-lbl" style="margin-left:14px;">Secuencial:</label>
            <InputNumber v-model="editDialog.secuencial" :useGrouping="false" class="kvs-in" style="max-width:120px" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Ambiente:</label>
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

<style scoped>
.mi-empresa { max-width: 640px; border: 1px solid #e2e5ea; border-radius: 8px; padding: 18px 20px; background: #fff; }
.mi-fila { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
.mi-fila:last-of-type { border-bottom: 0; }
.mi-lbl { min-width: 190px; color: #64748b; font-size: 13px; }
</style>
