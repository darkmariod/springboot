<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import ClienteForm from '../components/ClienteForm.vue'
import { onShortcut } from '../composables/useShortcuts'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const seleccion = ref<any>(null)
const form = ref<any>({})
const editando = ref(false)
const tab = ref('general')
const msg = ref<any>(null)
const filtro = ref({ identificacion: '', razon_social: '' })
const sriLoading = ref(false)

const tabs = [
  { key: 'general', label: 'General' },
  { key: 'contacto', label: 'Contacto' },
  { key: 'direccion', label: 'Dirección' },
  { key: 'tributario', label: 'Tributario' },
]

const filtrados = computed(() => rows.value.filter((r: any) => {
  const i = filtro.value.identificacion.toLowerCase()
  const n = filtro.value.razon_social.toLowerCase()
  return (!i || (r.identificacion ?? '').toLowerCase().includes(i))
    && (!n || (r.razon_social ?? '').toLowerCase().includes(n))
}))

async function load() {
  loading.value = true
  try {
    rows.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  } catch (err: any) {
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo cargar la lista de clientes.') }
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
  form.value = { ...r }
}

function nuevo() {
  seleccion.value = null
  editando.value = true
  tab.value = 'general'
  form.value = { tipo_identificacion: '05', es_cliente: true, es_proveedor: false }
}

async function guardar() {
  msg.value = null
  const payload = { ...form.value, company_id: company.activeId }
  try {
    if (form.value.id) await api.put('/contacts/' + form.value.id, payload)
    else {
      const res = await api.post('/contacts', payload)
      form.value.id = res.data.id
    }
  } catch (err: any) {
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo guardar.') }
    return
  }
  msg.value = { type: 'success', text: 'Contacto guardado.' }
  editando.value = false
  await load()
  const fresco = rows.value.find((r: any) => r.id === form.value.id)
  if (fresco) seleccionar(fresco)
}

async function eliminar() {
  if (!form.value.id || !confirm('¿Eliminar ' + form.value.razon_social + '?')) return
  msg.value = null
  try {
    await api.delete('/contacts/' + form.value.id)
  } catch (err: any) {
    // Con documentos registrados el servidor responde 422 «No se puede eliminar…»: se muestra en pantalla y el cliente se conserva
    msg.value = { type: 'error', text: textoDeError(err, 'No se pudo eliminar.') }
    return
  }
  seleccion.value = null; form.value = {}
  await load()
  msg.value = { type: 'success', text: 'Contacto eliminado.' }
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
    <!-- Listado de Contactos -->
    <section class="kvs-panel" style="width:440px; flex-shrink:0;">
      <div class="kvs-panel-title">Listado de Clientes</div>
      <div class="kvs-search">
        <span style="font-size:12px; color:#546e7a;">Búsqueda</span>
        <InputText v-model="filtro.identificacion" placeholder="Identificación" size="small" style="width:110px" />
        <InputText v-model="filtro.razon_social" placeholder="Razón social" size="small" style="flex:1" />
        <Button icon="pi pi-plus" size="small" text @click="nuevo" title="Nuevo contacto" />
      </div>
      <DataTable :value="filtrados" :loading="loading" size="small" scrollable scrollHeight="flex"
                 selectionMode="single" :selection="seleccion" dataKey="id" stripedRows
                 @row-select="(e) => seleccionar(e.data)" class="kvs-grid">
        <Column field="identificacion" header="Identificación" style="width:120px" />
        <Column field="razon_social" header="Razón social" />
        <Column header="Tipo" style="width:120px">
          <template #body="{ data }">
            <Tag v-if="data.es_cliente" value="Cliente" severity="info" style="margin-right:4px" />
            <Tag v-if="data.es_proveedor" value="Proveedor" severity="warn" />
          </template>
        </Column>
      </DataTable>
      <div class="kvs-panel-foot">Mostrando {{ filtrados.length }} de {{ rows.length }}</div>
    </section>

    <!-- Detalle Contacto -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Detalle Contacto</div>

      <!-- Sin nada seleccionado también se ve el resultado de la última acción (eliminado, error al cargar) -->
      <Message v-if="msg && !form.id && !editando" :severity="msg.type" :closable="false" style="margin:10px;">{{ msg.text }}</Message>
      <div v-if="!form.id && !editando" class="kvs-empty">
        Elige un cliente del listado, o toca <b>+</b> para crear uno nuevo.
      </div>

      <template v-else>
        <div class="kvs-tabs">
          <button v-for="t in tabs" :key="t.key" class="kvs-tab"
                  :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="kvs-tabbody">
          <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>

          <!-- General tab -->
          <div v-show="tab === 'general'">
            <ClienteForm v-model="form" :readonly="!editando" :loading="sriLoading" />
          </div>

          <!-- Contacto tab (phone, email, email2) -->
          <div v-show="tab === 'contacto'">
            <div class="kvs-row">
              <label class="kvs-lbl">Teléfono:</label>
              <InputText v-model="form.telefono" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Email:</label>
              <InputText v-model="form.email" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Correo (respaldo):</label>
              <InputText v-model="form.email2" :disabled="!editando" class="kvs-in" />
            </div>
          </div>

          <!-- Direccion tab -->
          <div v-show="tab === 'direccion'">
            <div class="kvs-row">
              <label class="kvs-lbl">Dirección:</label>
              <InputText v-model="form.direccion" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Observación:</label>
              <InputText v-model="form.observacion" :disabled="!editando" class="kvs-in" />
            </div>
          </div>

          <!-- Tributario tab (parte relacionada, clase de contribuyente, cuenta contable del proveedor) -->
          <div v-show="tab === 'tributario'">
            <ClienteForm v-model="form" seccion="tributario" :readonly="!editando" />
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
