<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const seleccion = ref<any>(null)
const form = ref<any>({})
const editando = ref(false)
const tab = ref('general')
const msg = ref<any>(null)
const filtro = ref({ codigo: '', nombre: '' })

const tabs = [{ key: 'general', label: 'General' }]

const filtrados = computed(() => rows.value.filter((r: any) => {
  const c = filtro.value.codigo.toLowerCase()
  const n = filtro.value.nombre.toLowerCase()
  return (!c || (r.codigo ?? '').toLowerCase().includes(c))
    && (!n || (r.nombre ?? '').toLowerCase().includes(n))
}))

async function load() {
  loading.value = true
  rows.value = (await api.get('/warehouses?company_id=' + company.activeId)).data
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
  form.value = { codigo: '', nombre: '', por_defecto: false, activa: true }
}

async function guardar() {
  msg.value = null
  const payload = { ...form.value, company_id: company.activeId }
  try {
    if (form.value.id) await api.put('/warehouses/' + form.value.id, payload)
    else {
      const res = await api.post('/warehouses', payload)
      form.value.id = res.data.id
    }
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
    return
  }
  msg.value = { type: 'success', text: 'Bodega guardada.' }
  editando.value = false
  await load()
  const fresco = rows.value.find((r: any) => r.id === form.value.id)
  if (fresco) seleccionar(fresco)
}

async function eliminar() {
  if (!form.value.id) return
  if (form.value.por_defecto) { alert('No se puede eliminar la bodega por defecto.'); return }
  if (!confirm('¿Desea eliminar la bodega "' + form.value.nombre + '"?')) return
  await api.delete('/warehouses/' + form.value.id)
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
    <!-- ══ Listado de Bodegas ══ -->
    <section class="kvs-panel" style="width:440px; flex-shrink:0;">
      <div class="kvs-panel-title">Listado de Bodegas</div>
      <div class="kvs-search">
        <span style="font-size:12px; color:#546e7a;">Búsqueda</span>
        <InputText v-model="filtro.codigo" placeholder="Código" size="small" style="width:90px" />
        <InputText v-model="filtro.nombre" placeholder="Nombre" size="small" style="flex:1" />
        <Button icon="pi pi-plus" size="small" text @click="nuevo" title="Nueva bodega" />
      </div>
      <DataTable :value="filtrados" :loading="loading" size="small" scrollable scrollHeight="flex"
                 selectionMode="single" :selection="seleccion" dataKey="id" stripedRows
                 @row-select="(e: any) => seleccionar(e.data)" class="kvs-grid">
        <Column field="codigo" header="Código" style="width:80px" />
        <Column field="nombre" header="Nombre" />
        <Column header="Por Defecto" style="width:100px">
          <template #body="{ data }">
            <Tag :value="data.por_defecto ? 'Sí' : 'No'" :severity="data.por_defecto ? 'success' : 'secondary'" />
          </template>
        </Column>
        <Column header="Activa" style="width:80px">
          <template #body="{ data }">
            <Tag :value="data.activa !== false ? 'Sí' : 'No'" :severity="data.activa !== false ? 'success' : 'danger'" />
          </template>
        </Column>
      </DataTable>
      <div class="kvs-panel-foot">Mostrando {{ filtrados.length }} de {{ rows.length }}</div>
    </section>

    <!-- ══ Detalle Bodega ══ -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Detalle Bodega</div>

      <div v-if="!form.id && !editando" class="kvs-empty">
        Elegí una bodega del listado, o tocá <b>+</b> para crear una nueva.
      </div>

      <template v-else>
        <div class="kvs-tabs">
          <button v-for="t in tabs" :key="t.key" class="kvs-tab"
                  :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="kvs-tabbody">
          <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>

          <div v-show="tab === 'general'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Código:</label>
              <InputText v-model="form.codigo" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Nombre:</label>
              <InputText v-model="form.nombre" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label style="display:flex; align-items:center; gap:8px;">
                <Checkbox v-model="form.por_defecto" :binary="true" :disabled="!editando" />
                <span>Por defecto</span>
              </label>
            </div>
            <div v-if="form.id" class="kvs-row">
              <label style="display:flex; align-items:center; gap:8px;">
                <Checkbox v-model="form.activa" :binary="true" :disabled="!editando" />
                <span>Activa</span>
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
