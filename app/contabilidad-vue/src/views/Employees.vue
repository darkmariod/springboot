<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
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
const filtro = ref({ cedula: '', nombres: '' })
const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

const tabs = [
  { key: 'general', label: 'General' },
  { key: 'laboral', label: 'Laboral' },
]

const filtrados = computed(() => rows.value.filter((r: any) => {
  const c = filtro.value.cedula.toLowerCase()
  const n = filtro.value.nombres.toLowerCase()
  return (!c || (r.cedula ?? '').toLowerCase().includes(c))
    && (!n || (r.nombres ?? '').toLowerCase().includes(n))
}))

async function load() {
  loading.value = true
  rows.value = (await api.get('/employees?company_id=' + company.activeId)).data
  loading.value = false
}

function seleccionar(r: any) {
  seleccion.value = r
  editando.value = false
  form.value = { ...r, fecha_ingreso: r.fecha_ingreso ? String(r.fecha_ingreso).slice(0, 10) : '' }
}

function nuevo() {
  seleccion.value = null
  editando.value = true
  tab.value = 'general'
  form.value = { cedula: '', nombres: '', cargo: '', fecha_ingreso: '', sueldo: 470, fondos_reserva: false }
}

async function guardar() {
  msg.value = null
  const payload = { ...form.value, company_id: company.activeId, fecha_ingreso: form.value.fecha_ingreso }
  try {
    if (form.value.id) await api.put('/employees/' + form.value.id, payload)
    else {
      const res = await api.post('/employees', payload)
      form.value.id = res.data.id
    }
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
    return
  }
  msg.value = { type: 'success', text: 'Empleado guardado.' }
  editando.value = false
  await load()
  const fresco = rows.value.find((r: any) => r.id === form.value.id)
  if (fresco) seleccionar(fresco)
}

async function eliminar() {
  if (!form.value.id || !confirm('¿Eliminar ' + form.value.nombres + '?')) return
  await api.delete('/employees/' + form.value.id)
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
    <!-- ══ Listado de Empleados ══ -->
    <section class="kvs-panel" style="width:440px; flex-shrink:0;">
      <div class="kvs-panel-title">Listado de Empleados</div>
      <div class="kvs-search">
        <span style="font-size:12px; color:#546e7a;">Búsqueda</span>
        <InputText v-model="filtro.cedula" placeholder="Cédula" size="small" style="width:90px" />
        <InputText v-model="filtro.nombres" placeholder="Nombres" size="small" style="flex:1" />
        <Button icon="pi pi-plus" size="small" text @click="nuevo" title="Nuevo empleado" />
      </div>
      <DataTable :value="filtrados" :loading="loading" size="small" scrollable scrollHeight="flex"
                 selectionMode="single" :selection="seleccion" dataKey="id" stripedRows
                 @row-select="(e: any) => seleccionar(e.data)" class="kvs-grid">
        <Column field="cedula" header="Cédula" style="width:90px" />
        <Column field="nombres" header="Nombres" />
        <Column field="cargo" header="Cargo" />
        <Column header="Sueldo" style="width:90px">
          <template #body="{ data }">{{ money(data.sueldo) }}</template>
        </Column>
      </DataTable>
      <div class="kvs-panel-foot">Mostrando {{ filtrados.length }} de {{ rows.length }}</div>
    </section>

    <!-- ══ Detalle Empleado ══ -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Detalle Empleado</div>

      <div v-if="!form.id && !editando" class="kvs-empty">
        Elegí un empleado del listado, o tocá <b>+</b> para crear uno nuevo.
      </div>

      <template v-else>
        <div class="kvs-tabs">
          <button v-for="t in tabs" :key="t.key" class="kvs-tab"
                  :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="kvs-tabbody">
          <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>

          <!-- General -->
          <div v-show="tab === 'general'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Cédula:</label>
              <InputText v-model="form.cedula" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Nombres:</label>
              <InputText v-model="form.nombres" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Cargo:</label>
              <InputText v-model="form.cargo" :disabled="!editando" class="kvs-in" />
            </div>
          </div>

          <!-- Laboral -->
          <div v-show="tab === 'laboral'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Fecha de ingreso:</label>
              <InputText v-model="form.fecha_ingreso" type="date" :disabled="!editando" class="kvs-in" style="max-width:180px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Sueldo:</label>
              <InputNumber v-model="form.sueldo" mode="currency" currency="USD" :disabled="!editando" class="kvs-in" style="max-width:160px" />
            </div>
            <div class="kvs-row">
              <label style="display:flex; align-items:center; gap:8px;">
                <Checkbox v-model="form.fondos_reserva" :binary="true" :disabled="!editando" />
                <span>Recibe fondos de reserva (más de 1 año)</span>
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
