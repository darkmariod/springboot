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

/* KVS classes are global in style.css */
