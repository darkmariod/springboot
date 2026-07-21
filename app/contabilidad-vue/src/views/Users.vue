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
  { label: 'Contador (contabilidad y reportes)', value: 'contador' },
  { label: 'Cajero (solo vende en su punto)', value: 'cajero' },
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
  rows.value = (await api.get('/users?company_id=' + company.activeId)).data
  puntos.value = (await api.get('/emission-points?company_id=' + company.activeId)).data
  loading.value = false
}

function seleccionar(r: any) {
  seleccion.value = r
  editando.value = false
  form.value = { ...r, password: '' }
}

function nuevo() {
  seleccion.value = null
  editando.value = true
  tab.value = 'datos'
  form.value = { name: '', email: '', password: '', rol: 'cajero', activo: true, emission_point_id: null }
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
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
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

      <div v-if="!form.id && !editando" class="kvs-empty">
        Elegí un usuario del listado, o tocá <b>+</b> para crear uno nuevo.
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
              <label class="kvs-lbl"><span class="req">*</span> Nombre:</label>
              <InputText v-model="form.name" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Email:</label>
              <InputText v-model="form.email" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span v-if="!form.id" class="req">*</span> Contraseña:</label>
              <Password v-model="form.password" :feedback="false" toggleMask :disabled="!editando" class="kvs-in" />
            </div>
          </div>

          <!-- Permisos -->
          <div v-show="tab === 'permisos'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Rol:</label>
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
