<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { onShortcut } from '../composables/useShortcuts'

const company = useCompanyStore()
const rows = ref<any[]>([])
const puntos = ref<any[]>([])
const loading = ref(true)
const seleccion = ref<any>(null)
const form = ref<any>({})
const editando = ref(false)
const tab = ref('datos')
const msg = ref<any>(null)
const filtro = ref({ name: '', email: '' })

const tabs = [
  { key: 'datos', label: 'Datos' },
  { key: 'permisos', label: 'Permisos' },
]

const roles = [
  { label: 'Administrador (todo)', value: 'admin' },
  { label: 'Contador (todo menos administración)', value: 'contador' },
  { label: 'Cajero / Recepción (solo vende)', value: 'cajero' },
]
const sevRol: Record<string, string> = { admin: 'danger', contador: 'info', cajero: 'secondary' }

const filtrados = computed(() => rows.value.filter((r: any) => {
  const n = filtro.value.name.toLowerCase()
  const e = filtro.value.email.toLowerCase()
  return (!n || (r.name ?? '').toLowerCase().includes(n))
    && (!e || (r.email ?? '').toLowerCase().includes(e))
}))

async function load() {
  loading.value = true
  try {
    rows.value = (await api.get('/users?company_id=' + company.activeId)).data
    puntos.value = (await api.get('/emission-points?company_id=' + company.activeId)).data
  } catch (err: any) {
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo cargar la lista de usuarios.') }
  } finally {
    loading.value = false
  }
}

/**
 * Lo que se le muestra a la persona cuando una llamada falla: el mensaje del servidor si es una respuesta de negocio (4xx:
 * «tiene documentos registrados», datos inválidos, sin permiso). Un error interno (5xx) o la falta de red nunca se muestran
 * tal cual: pueden traer SQL o rutas del servidor.
 */
function textoDeError(err: any, porDefecto: string): string {
  const r = err?.response
  if (!r || r.status >= 500) return porDefecto
  const errores = r.data?.errors
  if (errores) return Object.values(errores).flat().join(' · ')
  return r.data?.message ?? porDefecto
}

function seleccionar(r: any) {
  seleccion.value = r
  editando.value = false
  // La casilla solo reconoce true/false: se normaliza por si el servidor devolviera 1/0
  form.value = { ...r, password: '', activo: Boolean(r.activo) }
}

function nuevo() {
  seleccion.value = null
  editando.value = true
  tab.value = 'datos'
  form.value = { name: '', email: '', password: '', rol: 'cajero', activo: true, emission_point_id: null }
}

async function eliminar() {
  if (!form.value.id) return
  if (!confirm('¿Eliminar al usuario ' + form.value.name + '? Perderá el acceso al sistema.')) return
  try {
    await api.delete('/users/' + form.value.id)
    seleccion.value = null
    form.value = {}
    await load()
    msg.value = { type: 'success', text: 'Usuario eliminado.' }
  } catch (err: any) {
    // Con historial el servidor responde 422 «Desactívalo en lugar de eliminarlo»: se muestra en pantalla y el usuario se conserva
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo eliminar.') }
  }
}

async function guardar() {
  msg.value = null
  const payload = { ...form.value, company_id: company.activeId }
  try {
    if (form.value.id) await api.put('/users/' + form.value.id, payload)
    else {
      const res = await api.post('/users', payload)
      form.value.id = res.data.id
    }
  } catch (err: any) {
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo guardar.') }
    return
  }
  msg.value = { type: 'success', text: 'Usuario guardado.' }
  editando.value = false
  await load()
  const fresco = rows.value.find((r: any) => r.id === form.value.id)
  if (fresco) seleccionar(fresco)
}

function cancelar() {
  editando.value = false
  if (seleccion.value) seleccionar(seleccion.value)
  else form.value = {}
}

onMounted(load)

// Atajos de teclado (ver composables/useShortcuts): solo actúan con esta pestaña al frente.
onShortcut('nuevo', () => nuevo())
onShortcut('guardar', () => { if (editando.value) guardar() })
onShortcut('cancelar', () => { if (editando.value) cancelar() })
onShortcut('buscar', () => document.querySelector<HTMLInputElement>('.kvs-search input')?.focus())

</script>

<template>
  <div class="kvs-split">
    <!-- ══ Listado de Usuarios ══ -->
    <section class="kvs-panel" style="width:440px; flex-shrink:0;">
      <div class="kvs-panel-title">Listado de Usuarios</div>
      <div class="kvs-search">
        <span style="font-size:12px; color:#546e7a;">Búsqueda</span>
        <InputText v-model="filtro.name" placeholder="Nombre" size="small" style="flex:1" />
        <InputText v-model="filtro.email" placeholder="Email" size="small" style="flex:1" />
        <Button icon="pi pi-plus" size="small" text @click="nuevo" title="Nuevo usuario" />
      </div>
      <DataTable :value="filtrados" :loading="loading" size="small" scrollable scrollHeight="flex"
                 selectionMode="single" :selection="seleccion" dataKey="id" stripedRows
                 @row-select="(e: any) => seleccionar(e.data)" class="kvs-grid">
        <Column field="name" header="Nombre" />
        <Column field="email" header="Email" />
        <Column header="Rol" style="width:90px">
          <template #body="{ data }">
            <Tag :value="data.rol" :severity="sevRol[data.rol]" />
          </template>
        </Column>
        <Column header="Punto" style="width:100px">
          <template #body="{ data }">
            <span v-if="data.emission_point">{{ data.emission_point.estab }}-{{ data.emission_point.punto }}</span>
            <span v-else style="color:#94a3b8;">Todos</span>
          </template>
        </Column>
        <Column header="Estado" style="width:80px">
          <template #body="{ data }">
            <Tag :value="data.activo ? 'Activo' : 'Inactivo'" :severity="data.activo ? 'success' : 'secondary'" />
          </template>
        </Column>
      </DataTable>
      <div class="kvs-panel-foot">Mostrando {{ filtrados.length }} de {{ rows.length }}</div>
    </section>

    <!-- ══ Detalle Usuario ══ -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Detalle Usuario</div>

      <!-- Sin nada seleccionado también se ve el resultado de la última acción (eliminado, error al cargar) -->
      <Message v-if="msg && !form.id && !editando" :severity="msg.type" :closable="false" style="margin:10px;">{{ msg.text }}</Message>
      <div v-if="!form.id && !editando" class="kvs-empty">
        Elige un usuario del listado, o toca <b>+</b> para crear uno nuevo.
      </div>

      <template v-else>
        <div class="kvs-tabs">
          <button v-for="t in tabs" :key="t.key" class="kvs-tab"
                  :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="kvs-tabbody">
          <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>

          <!-- Datos -->
          <div v-show="tab === 'datos'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Nombre:</label>
              <InputText v-model="form.name" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Email:</label>
              <InputText v-model="form.email" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span v-if="!form.id" class="req-star">*</span> Contraseña:</label>
              <Password v-model="form.password" :feedback="false" toggleMask :disabled="!editando" class="kvs-in" />
            </div>
          </div>

          <!-- Permisos -->
          <div v-show="tab === 'permisos'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Rol:</label>
              <Select v-model="form.rol" :options="roles" optionLabel="label" optionValue="value"
                      :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Punto de emisión:</label>
              <Select v-model="form.emission_point_id" :options="puntos" optionValue="id" showClear
                      :optionLabel="(p: any) => p.estab + '-' + p.punto + ' (' + p.nombre + ')'"
                      :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label style="display:flex; align-items:center; gap:8px;">
                <Checkbox v-model="form.activo" :binary="true" :disabled="!editando" />
                <span>Usuario activo</span>
              </label>
            </div>
          </div>
        </div>

        <div class="kvs-footer">
          <Button v-if="editando" label="Cancelar" icon="pi pi-times" size="small" text @click="cancelar" />
          <Button v-if="editando" label="Guardar" icon="pi pi-save" size="small" @click="guardar" />
          <Button v-if="!editando" label="Editar" icon="pi pi-pencil" size="small" @click="editando = true" />
          <Button v-if="!editando && form.id" label="Eliminar" icon="pi pi-trash" size="small"
                  severity="danger" outlined @click="eliminar" />
        </div>
      </template>
    </section>
  </div>
</template>

/* KVS classes are global in style.css */
