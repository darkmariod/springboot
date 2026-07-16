<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import RadioButton from 'primevue/radiobutton'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({})

const tipos = [{ label: 'Bien (lleva stock)', value: 'bien' }, { label: 'Servicio', value: 'servicio' }]
const ivas = [{ label: '15%', value: 15 }, { label: '0%', value: 0 }]

async function load() {
  loading.value = true
  rows.value = (await api.get(`/products?company_id=${company.activeId}`)).data
  loading.value = false
}
function nuevo() {
  form.value = { tipo: 'bien', tarifa_iva: 15, precio: 0, maneja_series: false,
    es_combo: false, stock_minimo: 0, stock_maximo: 0, ubicacion: '' }
  dialog.value = true
}
function editar(r: any) {
  form.value = { ...r, precio: Number(r.precio), tarifa_iva: Number(r.tarifa_iva),
    maneja_series: !!r.maneja_series, es_combo: !!r.es_combo,
    stock_minimo: Number(r.stock_minimo ?? 0), stock_maximo: Number(r.stock_maximo ?? 0) }
  dialog.value = true
}
async function guardar() {
  if (form.value.id) await api.put(`/products/${form.value.id}`, form.value)
  else await api.post('/products', { ...form.value, company_id: company.activeId })
  dialog.value = false; load()
}
async function eliminar(r: any) {
  if (!confirm(`¿Eliminar ${r.descripcion}?`)) return
  await api.delete(`/products/${r.id}`); load()
}
onMounted(load)
const money = (n: any) => `$${Number(n).toFixed(2)}`
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Productos y servicios</h2>
      <Button label="Nuevo" icon="pi pi-plus" @click="nuevo" />
    </div>
    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column field="codigo" header="Código" />
      <Column field="descripcion" header="Descripción" />
      <Column header="Tipo">
        <template #body="{ data }"><Tag :value="data.tipo" :severity="data.tipo==='bien'?'info':'secondary'" /></template>
      </Column>
      <Column header="Precio"><template #body="{ data }">{{ money(data.precio) }}</template></Column>
      <Column header="IVA"><template #body="{ data }">{{ data.tarifa_iva }}%</template></Column>
      <Column header="Acciones" style="width:120px">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text @click="editar(data)" />
          <Button icon="pi pi-trash" text severity="danger" @click="eliminar(data)" />
        </template>
      </Column>
    </DataTable>

    <!-- Ficha de producto estilo KVS: datos, manejo, precios, stock y ubicación -->
    <Dialog v-model:visible="dialog" modal header="Creación de artículo" style="width: 620px;">
      <div style="display:flex; flex-direction:column; gap:14px;">

        <fieldset class="kvs-fieldset">
          <legend>Datos del artículo</legend>
          <div style="display:grid; grid-template-columns:150px 1fr; gap:10px;">
            <label class="kvs-field">Código *<InputText v-model="form.codigo" fluid /></label>
            <label class="kvs-field">Descripción *<InputText v-model="form.descripcion" fluid /></label>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:10px;">
            <label class="kvs-field">Tipo
              <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value" fluid /></label>
            <label class="kvs-field">Imagen del artículo (URL)
              <InputText v-model="form.imagen" placeholder="https://…" fluid /></label>
          </div>
        </fieldset>

        <fieldset class="kvs-fieldset">
          <legend>Manejo del producto</legend>
          <div style="display:flex; gap:22px; align-items:center;">
            <label style="display:flex; align-items:center; gap:7px; font-size:13px;">
              <RadioButton v-model="form.maneja_series" :value="false" /> Individual</label>
            <label style="display:flex; align-items:center; gap:7px; font-size:13px;">
              <RadioButton v-model="form.maneja_series" :value="true" /> Series (IMEI / n° de serie)</label>
            <label style="display:flex; align-items:center; gap:7px; font-size:13px; margin-left:auto;">
              <Checkbox v-model="form.es_combo" :binary="true" /> Es combo (componentes)</label>
          </div>
          <p v-if="form.maneja_series" style="margin:8px 0 0; font-size:12px; color:#64748b;">
            Al comprar se registran las series de cada unidad; al vender se escanea la serie que sale.
            Sirve para las garantías: a quién le compró y a quién le vendió esa unidad.
          </p>
        </fieldset>

        <fieldset class="kvs-fieldset">
          <legend>Precios e impuestos</legend>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <label class="kvs-field">Precio venta al público *
              <InputNumber v-model="form.precio" mode="currency" currency="USD" fluid /></label>
            <label class="kvs-field">Tarifa IVA
              <Select v-model="form.tarifa_iva" :options="ivas" optionLabel="label" optionValue="value" fluid /></label>
          </div>
        </fieldset>

        <fieldset v-if="form.tipo === 'bien'" class="kvs-fieldset">
          <legend>Stock y ubicación</legend>
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
            <label class="kvs-field">Mínimo
              <InputNumber v-model="form.stock_minimo" :useGrouping="false" fluid /></label>
            <label class="kvs-field">Máximo
              <InputNumber v-model="form.stock_maximo" :useGrouping="false" fluid /></label>
            <label class="kvs-field">Ubicación física
              <InputText v-model="form.ubicacion" placeholder="Fila 3, Columna B" fluid /></label>
          </div>
          <p style="margin:8px 0 0; font-size:12px; color:#64748b;">
            Con mínimo y máximo, el sistema le avisa qué reponer y cuánto pedir.
          </p>
        </fieldset>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="dialog=false" />
        <Button label="Guardar" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>
