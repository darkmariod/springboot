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
]

const filtrados = computed(() => rows.value.filter((r: any) => {
  const i = filtro.value.identificacion.toLowerCase()
  const n = filtro.value.razon_social.toLowerCase()
  return (!i || (r.identificacion ?? '').toLowerCase().includes(i))
    && (!n || (r.razon_social ?? '').toLowerCase().includes(n))
}))

async function load() {
  loading.value = true
  rows.value = (await api.get('/contacts?company_id=' + company.activeId)).data
  loading.value = false
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
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
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
  await api.delete('/contacts/' + form.value.id)
  seleccion.value = null; form.value = {}; load()
}

function cancelar() {
  editando.value = false
  if (seleccion.value) seleccionar(seleccion.value)
  else form.value = {}
}

onMounted(load)
</script>

<template>
  <div class="kvs-split">
    <!-- Listado de Contactos -->
    <section class="kvs-panel" style="width:440px; flex-shrink:0;">
      <div class="kvs-panel-title">Listado de Contactos</div>
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

      <div v-if="!form.id && !editando" class="kvs-empty">
        Elegí un contacto del listado, o tocá <b>+</b> para crear uno nuevo.
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

<style scoped>
.kvs-split { display: flex; gap: 10px; height: 100%; padding: 10px; background: #eef1f5; }
.kvs-panel {
  display: flex; flex-direction: column; background: #fff; border: 1px solid #b9c2cc;
  border-radius: 4px; overflow: hidden;
}
.kvs-panel-title {
  background: linear-gradient(#3d8b8b, #2a6b6b); color: #fff; font-weight: 600; font-size: 12.5px;
  padding: 5px 10px; flex-shrink: 0;
}
.kvs-search {
  display: flex; align-items: center; gap: 6px; padding: 7px 8px; border-bottom: 1px solid #e2e5ea;
  flex-shrink: 0;
}
.kvs-grid { flex: 1; min-height: 0; }
.kvs-panel-foot {
  font-size: 11.5px; color: #64748b; padding: 5px 10px; border-top: 1px solid #e2e5ea;
  background: #f7f9fb; flex-shrink: 0;
}
.kvs-empty { padding: 50px 20px; text-align: center; color: #94a3b8; font-size: 13px; }
.kvs-tabs {
  display: flex; gap: 1px; background: #dde2ea; padding: 5px 6px 0; overflow-x: auto;
  flex-shrink: 0; scrollbar-width: thin;
}
.kvs-tab {
  border: 0; background: #eef1f5; color: #64748b; padding: 5px 11px; font-size: 12px;
  border-radius: 4px 4px 0 0; cursor: pointer; white-space: nowrap;
}
.kvs-tab.active { background: #fff; color: #1f2733; font-weight: 600; }
.kvs-tabbody { flex: 1; overflow: auto; padding: 14px; }
.kvs-row { display: flex; align-items: center; gap: 8px; margin-bottom: 9px; }
.kvs-lbl { font-size: 13px; color: #37474f; white-space: nowrap; min-width: 118px; text-align: right; }
.kvs-lbl .req { color: #d93025; font-weight: 700; }
.kvs-in { flex: 1; }
.kvs-hint { margin: 0 0 10px; font-size: 12px; color: #64748b; }
.kvs-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.kvs-table th {
  text-align: left; background: #f2f4f7; border-bottom: 1px solid #b9c2cc; padding: 5px 8px;
  font-weight: 600;
}
.kvs-table td { padding: 5px 8px; border-bottom: 1px solid #eef1f5; }
.kvs-table .der { text-align: right; }
.kvs-table .vacio { color: #94a3b8; text-align: center; padding: 14px; }
.kvs-footer {
  display: flex; justify-content: flex-end; gap: 8px; padding: 8px 12px;
  border-top: 1px solid #e2e5ea; background: #f7f9fb; flex-shrink: 0;
}
</style>
