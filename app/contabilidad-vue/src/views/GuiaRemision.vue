<template>
  <div style="padding: 20px">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
      <h3 style="margin: 0">Guías de Remisión</h3>
      <Button label="Nueva Guía" icon="pi pi-plus" @click="openNew" />
    </div>

    <DataTable :value="rows" :loading="loading" stripedRows size="small">
      <Column field="numero_autorizacion" header="Número" />
      <Column field="estado" header="Estado" />
      <Column field="fecha_emision" header="Fecha">
        <template #body="{ data }">{{ data.fecha_emision?.slice(0, 10) }}</template>
      </Column>
      <Column header="Acciones" style="width: 120px">
        <template #body="{ data }">
          <Button icon="pi pi-send" size="small" label="Emitir" @click="emitir(data)" :loading="emitting === data.id" />
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="dialog" header="Nueva Guía de Remisión" modal style="width: 700px">
      <div class="kvs-fieldset">
        <h4>Transportista</h4>
        <div class="kvs-row">
          <label class="kvs-lbl">RUC Transportista</label>
          <InputText v-model="form.transportista_ruc" class="kvs-in" maxlength="13" placeholder="13 dígitos" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Nombre</label>
          <InputText v-model="form.transportista_nombre" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Placa</label>
          <InputText v-model="form.transportista_placa" class="kvs-in" />
        </div>

        <h4>Traslado</h4>
        <div class="kvs-row">
          <label class="kvs-lbl">Dir. Partida</label>
          <InputText v-model="form.dir_partida" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Fecha Inicio</label>
          <InputText v-model="form.fecha_ini_transporte" type="date" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Fecha Fin</label>
          <InputText v-model="form.fecha_fin_transporte" type="date" class="kvs-in" />
        </div>

        <h4>Destinatarios</h4>
        <div v-for="(dest, i) in form.destinatarios" :key="i" style="border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-bottom: 12px">
          <div class="kvs-row">
            <label class="kvs-lbl">Identificación</label>
            <InputText v-model="dest.identificacion" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Razón Social</label>
            <InputText v-model="dest.razon_social" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Dirección</label>
            <InputText v-model="dest.direccion" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Motivo Traslado</label>
            <InputText v-model="dest.motivo_traslado" class="kvs-in" />
          </div>

          <div v-for="(det, j) in dest.detalles" :key="j" class="kvs-row" style="gap: 8px">
            <div style="flex: 1">
              <label class="kvs-lbl">Código</label>
              <InputText v-model="det.codigo_interno" class="kvs-in" />
            </div>
            <div style="flex: 2">
              <label class="kvs-lbl">Descripción</label>
              <InputText v-model="det.descripcion" class="kvs-in" />
            </div>
            <div style="flex: 1">
              <label class="kvs-lbl">Cantidad</label>
              <InputNumber v-model="det.cantidad" class="kvs-in" :min="1" />
            </div>
          </div>
          <Button label="Agregar ítem" icon="pi pi-plus" text size="small" @click="dest.detalles.push({ codigo_interno: '', descripcion: '', cantidad: 1 })" />
        </div>
        <Button label="Agregar destinatario" icon="pi pi-plus" text @click="addDestinatario" />
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog = false" />
        <Button label="Guardar" @click="guardar" :loading="saving" />
      </template>
    </Dialog>

    <Message v-if="msg" :severity="msgOk ? 'success' : 'error'" :closable="false" style="margin-top: 12px">{{ msg }}</Message>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(false)
const dialog = ref(false)
const saving = ref(false)
const emitting = ref<number | null>(null)
const msg = ref('')
const msgOk = ref(true)

function blankDest() {
  return { identificacion: '', razon_social: '', direccion: '', motivo_traslado: 'Venta de mercadería', detalles: [{ codigo_interno: '', descripcion: '', cantidad: 1 }] }
}

const form = ref({
  transportista_ruc: '',
  transportista_nombre: '',
  transportista_placa: '',
  dir_partida: '',
  fecha_ini_transporte: new Date().toISOString().slice(0, 10),
  fecha_fin_transporte: new Date().toISOString().slice(0, 10),
  destinatarios: [blankDest()],
})

function openNew() {
  form.value = {
    transportista_ruc: '',
    transportista_nombre: '',
    transportista_placa: '',
    dir_partida: '',
    fecha_ini_transporte: new Date().toISOString().slice(0, 10),
    fecha_fin_transporte: new Date().toISOString().slice(0, 10),
    destinatarios: [blankDest()],
  }
  dialog.value = true
}

function addDestinatario() {
  form.value.destinatarios.push(blankDest())
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/guias-remision?company_id=' + company.activeId)
    rows.value = data
  } finally {
    loading.value = false
  }
}

async function guardar() {
  saving.value = true
  msg.value = ''
  try {
    await api.post('/guias-remision', { company_id: company.activeId, ...form.value })
    dialog.value = false
    msg.value = 'Guía guardada'
    msgOk.value = true
    await load()
  } catch (e: any) {
    msg.value = e?.response?.data?.message || 'Error al guardar'
    msgOk.value = false
  } finally {
    saving.value = false
  }
}

async function emitir(row: any) {
  emitting.value = row.id
  msg.value = ''
  try {
    const { data } = await api.post(`/guias-remision/${row.id}/emit`, { ...form.value })
    msg.value = data.mensaje || 'Emitida'
    msgOk.value = true
    await load()
  } catch (e: any) {
    msg.value = e?.response?.data?.message || 'Error al emitir'
    msgOk.value = false
  } finally {
    emitting.value = null
  }
}

onMounted(load)
</script>
