<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
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
const filtro = ref({ nombre: '' })

const tabs = [{ key: 'general', label: 'General' }]

const filtrados = computed(() => rows.value.filter((r: any) => {
  const n = filtro.value.nombre.toLowerCase()
  return (!n || (r.nombre ?? '').toLowerCase().includes(n))
}))

async function load() {
  loading.value = true
  rows.value = (await api.get('/emission-points?company_id=' + company.activeId)).data
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
  form.value = { estab: '001', punto: '', nombre: '' }
}

async function guardar() {
  msg.value = null
  const payload = { ...form.value, company_id: company.activeId }
  try {
    const res = await api.post('/emission-points', payload)
    form.value.id = res.data.id
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
    return
  }
  msg.value = { type: 'success', text: 'Punto de emisión guardado.' }
  editando.value = false
  await load()
  const fresco = rows.value.find((r: any) => r.id === form.value.id)
  if (fresco) seleccionar(fresco)
}

async function eliminar() {
  if (!form.value.id) return
  if (!confirm('¿Eliminar el punto ' + form.value.estab + '-' + form.value.punto + '?')) return
  await api.delete('/emission-points/' + form.value.id)
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
    <!-- ══ Listado de Puntos de Emisión ══ -->
    <section class="kvs-panel" style="width:440px; flex-shrink:0;">
      <div class="kvs-panel-title">Puntos de Emisión</div>
      <div class="kvs-search">
        <span style="font-size:12px; color:#546e7a;">Búsqueda</span>
        <InputText v-model="filtro.nombre" placeholder="Nombre" size="small" style="flex:1" />
        <Button icon="pi pi-plus" size="small" text @click="nuevo" title="Nuevo punto" />
      </div>
      <DataTable :value="filtrados" :loading="loading" size="small" scrollable scrollHeight="flex"
                 selectionMode="single" :selection="seleccion" dataKey="id" stripedRows
                 @row-select="(e: any) => seleccionar(e.data)" class="kvs-grid">
        <Column header="Punto" style="width:100px">
          <template #body="{ data }"><b>{{ data.estab }}-{{ data.punto }}</b></template>
        </Column>
        <Column field="nombre" header="Nombre" />
        <Column field="secuencial" header="Secuencial" style="width:90px" />
      </DataTable>
      <div class="kvs-panel-foot">Mostrando {{ filtrados.length }} de {{ rows.length }}</div>
    </section>

    <!-- ══ Detalle Punto de Emisión ══ -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Detalle Punto de Emisión</div>

      <div v-if="!form.id && !editando" class="kvs-empty">
        Elegí un punto del listado, o tocá <b>+</b> para crear uno nuevo.
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
              <label class="kvs-lbl">Establecimiento:</label>
              <InputText v-model="form.estab" maxlength="3" :disabled="!editando" class="kvs-in" style="max-width:100px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Punto (ej. 901):</label>
              <InputText v-model="form.punto" maxlength="3" :disabled="!editando" class="kvs-in" style="max-width:100px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Nombre (ej. Caja):</label>
              <InputText v-model="form.nombre" :disabled="!editando" class="kvs-in" />
            </div>
          </div>
        </div>

        <div class="kvs-footer">
          <Button v-if="editando" label="Cancelar" icon="pi pi-times" size="small" text @click="cancelar" />
          <Button v-if="editando" label="Guardar" icon="pi pi-save" size="small" @click="guardar" />
          <Button v-if="!editando" label="Eliminar" icon="pi pi-trash" size="small"
                  severity="danger" outlined @click="eliminar" />
        </div>
      </template>
    </section>
  </div>
</template>

/* KVS classes are global in style.css */
