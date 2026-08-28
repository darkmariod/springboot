<script setup lang="ts">
/**
 * Sucursales (multisede). Cada sucursal es un establecimiento ante el SRI:
 * su código (001 la matriz, 002 en adelante) sale impreso en el número de la factura.
 */
import { onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref<any>(null)
const msg = ref<any>(null)
const guardando = ref(false)

async function load() {
  loading.value = true
  try { rows.value = (await api.get('/branches?company_id=' + company.activeId)).data }
  finally { loading.value = false }
}

function nueva() {
  const usados = rows.value.map((r) => Number(r.estab))
  const siguiente = String((usados.length ? Math.max(...usados) : 0) + 1).padStart(3, '0')
  dialog.value = { estab: siguiente, nombre: '', direccion: '', telefono: '', activa: true }
}
const editar = (r: any) => { dialog.value = { ...r } }

async function guardar() {
  guardando.value = true
  msg.value = null
  try {
    const d = { ...dialog.value, company_id: company.activeId }
    if (d.id) await api.put('/branches/' + d.id, d)
    else await api.post('/branches', d)
    msg.value = { type: 'success', text: 'Sucursal guardada.' }
    dialog.value = null
    await load()
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? 'No se pudo guardar.') }
  } finally { guardando.value = false }
}

async function eliminar(r: any) {
  try {
    await api.delete('/branches/' + r.id)
    msg.value = { type: 'success', text: 'Sucursal eliminada.' }
    await load()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'No se pudo eliminar.' }
  }
}

watch(() => company.activeId, load)
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
      <div>
        <h2 style="margin:0 0 3px;">Sucursales</h2>
        <p style="color:#8fa2ad; font-size:13px; margin:0;">
          Cada sucursal es un establecimiento ante el SRI. Su código aparece en el número de cada factura.
        </p>
      </div>
      <Button label="Nueva sucursal" icon="pi pi-plus" size="small" @click="nueva" />
    </div>

    <Message v-if="msg" :severity="msg.type" :closable="true" style="margin-bottom:14px;"
             @close="msg = null">{{ msg.text }}</Message>

    <DataTable :value="rows" :loading="loading" size="small" stripedRows>
      <Column header="Establecimiento" style="width:150px">
        <template #body="{ data }">
          <b style="font-family:ui-monospace,Menlo,monospace;">{{ data.estab }}</b>
          <Tag v-if="data.es_matriz" value="Matriz" severity="info" style="margin-left:8px;" />
        </template>
      </Column>
      <Column field="nombre" header="Nombre" />
      <Column field="direccion" header="Dirección" />
      <Column field="telefono" header="Teléfono" style="width:130px" />
      <Column header="Bodegas" style="width:90px">
        <template #body="{ data }">{{ data.warehouses_count ?? 0 }}</template>
      </Column>
      <Column header="Facturas" style="width:90px">
        <template #body="{ data }">{{ data.invoices_count ?? 0 }}</template>
      </Column>
      <Column header="Estado" style="width:100px">
        <template #body="{ data }">
          <Tag :value="data.activa ? 'Activa' : 'Inactiva'" :severity="data.activa ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column header="" style="width:150px">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" size="small" text @click="editar(data)" />
          <Button v-if="!data.es_matriz" icon="pi pi-trash" size="small" text severity="danger"
                  @click="eliminar(data)" />
        </template>
      </Column>
      <template #empty>
        <div style="padding:26px; text-align:center; color:#94a3b8; font-size:13px;">
          Todavía no hay sucursales. Creá la matriz con el establecimiento 001.
        </div>
      </template>
    </DataTable>

    <Dialog :visible="!!dialog" modal :header="dialog?.id ? 'Editar sucursal' : 'Nueva sucursal'"
            style="width:520px" @update:visible="dialog = null">
      <div v-if="dialog">
        <Message severity="warn" :closable="false" style="margin-bottom:12px;">
          El <b>establecimiento</b> debe estar registrado en el SRI para esta sucursal.
          Si no coincide, el comprobante es rechazado.
        </Message>
        <fieldset class="kvs-fieldset">
          <legend>Datos de la sucursal</legend>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req">*</span> Establecimiento:</label>
            <InputText v-model="dialog.estab" maxlength="3" class="kvs-in" style="max-width:100px" />
            <label class="kvs-lbl" style="margin-left:14px;"><span class="req">*</span> Nombre:</label>
            <InputText v-model="dialog.nombre" class="kvs-in" placeholder="Matriz, Sucursal Norte…" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Dirección:</label>
            <InputText v-model="dialog.direccion" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Teléfono:</label>
            <InputText v-model="dialog.telefono" class="kvs-in" style="max-width:190px" />
            <label style="display:flex; align-items:center; gap:7px; margin-left:18px; font-size:13px;">
              <Checkbox v-model="dialog.activa" :binary="true" /> Activa
            </label>
          </div>
        </fieldset>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog = null" />
        <Button label="Guardar" icon="pi pi-save" :loading="guardando" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
