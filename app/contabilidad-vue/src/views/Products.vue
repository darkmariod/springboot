<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Textarea from 'primevue/textarea'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

// "Listado de Artículos" + "Detalle Artículo" con pestañas — igual al sistema del creador.
const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const seleccion = ref<any>(null)
const form = ref<any>({})
const editando = ref(false)
const tab = ref('general')
const msg = ref<any>(null)
const filtro = ref({ codigo: '', nombre: '' })

// Extras de la ficha
const precios = ref<any[]>([])
const codigos = ref<any[]>([])
const componentes = ref<any[]>([])
const nuevoPrecio = ref<any>({ nombre: 'PRECIO VENTA AL PUBLICO', precio: 0 })
const nuevoCodigo = ref('')
const nuevoComponente = ref<any>({ component_id: null, cantidad: 1 })

const tabs = [
  { key: 'general', label: 'General' },
  { key: 'precios', label: 'Listado Precios' },
  { key: 'minmax', label: 'Máximos/Mínimos' },
  { key: 'codigos', label: 'CódigosAlternos' },
  { key: 'descripcion', label: 'Descripción/Memo' },
  { key: 'ubicacion', label: 'Ubicación Física' },
  { key: 'combo', label: 'Componentes' },
  { key: 'imagen', label: 'Imágenes' },
]
const tipos = [{ label: 'Bien (lleva stock)', value: 'bien' }, { label: 'Servicio', value: 'servicio' }]
const ivas = [{ label: '15%', value: 15 }, { label: '5%', value: 5 }, { label: '0%', value: 0 }]
// Igual que el dropdown del creador
const manejos = [
  { label: 'Ninguno', value: 'ninguno' },
  { label: 'Serie', value: 'serie' },
  { label: 'Lote General', value: 'lote_general' },
  { label: 'Lote Código Único', value: 'lote_unico' },
]
const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

const filtrados = computed(() => rows.value.filter((r: any) => {
  const c = filtro.value.codigo.toLowerCase()
  const n = filtro.value.nombre.toLowerCase()
  return (!c || (r.codigo ?? '').toLowerCase().includes(c))
    && (!n || (r.descripcion ?? '').toLowerCase().includes(n))
}))

async function load() {
  loading.value = true
  rows.value = (await api.get('/products?company_id=' + company.activeId)).data
  loading.value = false
}
function manejoDe(r: any) { return r?.maneja_series ? 'serie' : 'ninguno' }

async function seleccionar(r: any) {
  seleccion.value = r
  editando.value = false
  form.value = { ...r, precio: Number(r.precio), tarifa_iva: Number(r.tarifa_iva),
    manejo: manejoDe(r), es_combo: !!r.es_combo,
    stock_minimo: Number(r.stock_minimo ?? 0), stock_maximo: Number(r.stock_maximo ?? 0) }
  await cargarExtras()
}
function nuevo() {
  seleccion.value = null
  editando.value = true
  tab.value = 'general'
  form.value = { tipo: 'bien', tarifa_iva: 15, precio: 0, manejo: 'ninguno',
    es_combo: false, stock_minimo: 0, stock_maximo: 0, ubicacion: '', codigo: '', descripcion: '' }
  precios.value = []; codigos.value = []; componentes.value = []
}
async function cargarExtras() {
  precios.value = []; codigos.value = []; componentes.value = []
  if (!form.value.id) return
  const id = form.value.id
  // Los extras son secundarios: si fallan, la ficha igual se ve.
  try {
    const [p, c, k] = await Promise.all([
      api.get('/products/' + id + '/prices'),
      api.get('/products/' + id + '/codes'),
      api.get('/products/' + id + '/components'),
    ])
    precios.value = p.data; codigos.value = c.data; componentes.value = k.data
  } catch { /* pestañas de extras quedan vacías */ }
}
async function guardar() {
  msg.value = null
  const payload = { ...form.value, company_id: company.activeId,
    maneja_series: form.value.manejo === 'serie' }
  // El try solo cubre el guardado: si algo falla DESPUÉS (recargar, extras),
  // el artículo ya quedó guardado y no hay que decir lo contrario.
  try {
    if (form.value.id) await api.put('/products/' + form.value.id, payload)
    else {
      const res = await api.post('/products', payload)
      form.value.id = res.data.id
    }
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ') : 'No se pudo guardar.' }
    return
  }
  msg.value = { type: 'success', text: 'Artículo guardado.' }
  editando.value = false
  await load()
  const fresco = rows.value.find((r: any) => r.id === form.value.id)
  if (fresco) await seleccionar(fresco)
}
async function eliminar() {
  if (!form.value.id || !confirm('¿Eliminar ' + form.value.descripcion + '?')) return
  await api.delete('/products/' + form.value.id)
  seleccion.value = null; form.value = {}; load()
}
function cancelar() {
  editando.value = false
  if (seleccion.value) seleccionar(seleccion.value)
  else form.value = {}
}
// Extras
async function agregarPrecio() {
  if (!nuevoPrecio.value.nombre || !form.value.id) return
  await api.post('/products/' + form.value.id + '/prices', nuevoPrecio.value)
  nuevoPrecio.value = { nombre: '', precio: 0 }; cargarExtras()
}
async function quitarPrecio(p: any) { await api.delete('/product-prices/' + p.id); cargarExtras() }
async function agregarCodigo() {
  if (!nuevoCodigo.value.trim() || !form.value.id) return
  await api.post('/products/' + form.value.id + '/codes', { codigo: nuevoCodigo.value.trim() })
  nuevoCodigo.value = ''; cargarExtras()
}
async function quitarCodigo(c: any) { await api.delete('/product-codes/' + c.id); cargarExtras() }
async function agregarComponente() {
  if (!nuevoComponente.value.component_id || !form.value.id) return
  await api.post('/products/' + form.value.id + '/components', nuevoComponente.value)
  nuevoComponente.value = { component_id: null, cantidad: 1 }; cargarExtras()
}
async function quitarComponente(c: any) { await api.delete('/product-components/' + c.id); cargarExtras() }
onMounted(load)
</script>

<template>
  <div class="kvs-split">
    <!-- ══ Listado de Artículos ══ -->
    <section class="kvs-panel" style="width:440px; flex-shrink:0;">
      <div class="kvs-panel-title">Listado de Artículos</div>
      <div class="kvs-search">
        <span style="font-size:12px; color:#546e7a;">Búsqueda</span>
        <InputText v-model="filtro.codigo" placeholder="Código" size="small" style="width:90px" />
        <InputText v-model="filtro.nombre" placeholder="Nombre" size="small" style="flex:1" />
        <Button icon="pi pi-plus" size="small" text @click="nuevo" title="Nuevo artículo" />
      </div>
      <DataTable :value="filtrados" :loading="loading" size="small" scrollable scrollHeight="flex"
                 selectionMode="single" :selection="seleccion" dataKey="id" stripedRows
                 @row-select="(e) => seleccionar(e.data)" class="kvs-grid">
        <Column field="codigo" header="Código" style="width:80px" />
        <Column field="descripcion" header="Nombre" />
        <Column header="Stock" style="width:70px">
          <template #body="{ data }">{{ Number(data.stock ?? 0) }}</template>
        </Column>
      </DataTable>
      <div class="kvs-panel-foot">Mostrando {{ filtrados.length }} de {{ rows.length }}</div>
    </section>

    <!-- ══ Detalle Artículo ══ -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Detalle Artículo</div>

      <div v-if="!form.id && !editando" class="kvs-empty">
        Elegí un artículo del listado, o tocá <b>+</b> para crear uno nuevo.
      </div>

      <template v-else>
        <!-- Pestañas del detalle -->
        <div class="kvs-tabs">
          <button v-for="t in tabs" :key="t.key" class="kvs-tab"
                  :class="{ active: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="kvs-tabbody">
          <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>

          <!-- General -->
          <div v-show="tab === 'general'">
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Código:</label>
              <InputText v-model="form.codigo" :disabled="!editando" class="kvs-in" style="max-width:180px" />
              <label class="kvs-lbl" style="margin-left:16px;">Referencia:</label>
              <InputText v-model="form.referencia" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Nombre:</label>
              <InputText v-model="form.descripcion" :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Tipo:</label>
              <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value"
                      :disabled="!editando" class="kvs-in" />
              <label class="kvs-lbl" style="margin-left:16px;"><span class="req">*</span> Aplica Serie/Lote:</label>
              <Select v-model="form.manejo" :options="manejos" optionLabel="label" optionValue="value"
                      :disabled="!editando" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Porcentaje IVA:</label>
              <Select v-model="form.tarifa_iva" :options="ivas" optionLabel="label" optionValue="value"
                      :disabled="!editando" class="kvs-in" style="max-width:120px" />
              <label class="kvs-lbl" style="margin-left:16px;"><span class="req">*</span> Precio venta:</label>
              <InputNumber v-model="form.precio" mode="currency" currency="USD"
                           :disabled="!editando" class="kvs-in" style="max-width:160px" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl">Días Garantía:</label>
              <div style="width:120px; flex-shrink:0;">
                <InputNumber v-model="form.dias_garantia" :useGrouping="false" :disabled="!editando" fluid />
              </div>
              <label style="display:flex; align-items:center; gap:7px; margin-left:24px; font-size:13px; white-space:nowrap;">
                <Checkbox v-model="form.es_combo" :binary="true" :disabled="!editando" /> Es combo
              </label>
            </div>
            <div v-if="form.id" class="kvs-row" style="margin-top:12px;">
              <label class="kvs-lbl">Estado:</label>
              <Tag :value="'Stock: ' + Number(form.stock ?? 0) + ' · Costo prom.: ' + money(form.costo_promedio)"
                   severity="info" />
            </div>
          </div>

          <!-- Listado Precios -->
          <div v-show="tab === 'precios'">
            <p class="kvs-hint">Varias listas de precios por artículo (mayorista, distribuidor…).</p>
            <table class="kvs-table">
              <thead><tr><th>Tipo</th><th class="der">Precio Neto</th><th class="der">Precio + IVA</th><th style="width:40px"></th></tr></thead>
              <tbody>
                <tr v-for="p in precios" :key="p.id">
                  <td>{{ p.nombre }}</td>
                  <td class="der">{{ money(p.precio) }}</td>
                  <td class="der">{{ money(Number(p.precio) * (1 + Number(form.tarifa_iva) / 100)) }}</td>
                  <td><Button icon="pi pi-times" text size="small" severity="danger" @click="quitarPrecio(p)" /></td>
                </tr>
                <tr v-if="!precios.length"><td colspan="4" class="vacio">Sin listas de precios</td></tr>
              </tbody>
            </table>
            <div class="kvs-row" style="margin-top:8px;">
              <InputText v-model="nuevoPrecio.nombre" placeholder="PRECIO VENTA AL PUBLICO" style="flex:1" />
              <InputNumber v-model="nuevoPrecio.precio" mode="currency" currency="USD" style="width:140px" />
              <Button icon="pi pi-plus" size="small" :disabled="!form.id" @click="agregarPrecio" />
            </div>
          </div>

          <!-- Máximos/Mínimos -->
          <div v-show="tab === 'minmax'">
            <p class="kvs-hint">Con mínimo y máximo, el sistema le avisa qué reponer y cuánto pedir.</p>
            <div class="kvs-row">
              <label class="kvs-lbl">Stock mínimo:</label>
              <InputNumber v-model="form.stock_minimo" :useGrouping="false" :disabled="!editando"
                           class="kvs-in" style="max-width:130px" />
              <label class="kvs-lbl" style="margin-left:16px;">Stock máximo:</label>
              <InputNumber v-model="form.stock_maximo" :useGrouping="false" :disabled="!editando"
                           class="kvs-in" style="max-width:130px" />
            </div>
            <Message v-if="form.id && Number(form.stock) < Number(form.stock_minimo)"
                     severity="warn" :closable="false" style="margin-top:10px;">
              Stock bajo mínimo: hay {{ Number(form.stock) }} y el mínimo es {{ Number(form.stock_minimo) }}.
              Sugerido comprar: <b>{{ Math.max(0, Number(form.stock_maximo) - Number(form.stock)) }}</b>
            </Message>
          </div>

          <!-- Códigos alternos -->
          <div v-show="tab === 'codigos'">
            <p class="kvs-hint">El código del proveedor u otros códigos de barras. El POS también busca por estos.</p>
            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
              <Tag v-for="c in codigos" :key="c.id" severity="secondary">
                {{ c.codigo }}
                <i class="pi pi-times" style="cursor:pointer; font-size:10px; margin-left:6px;" @click="quitarCodigo(c)" />
              </Tag>
              <span v-if="!codigos.length" style="font-size:13px; color:#94a3b8;">Sin códigos alternos</span>
            </div>
            <div class="kvs-row">
              <InputText v-model="nuevoCodigo" placeholder="Escaneá o escribí el código" style="flex:1"
                         @keydown.enter.prevent="agregarCodigo" />
              <Button icon="pi pi-plus" size="small" :disabled="!form.id" @click="agregarCodigo" />
            </div>
          </div>

          <!-- Descripción / Memo -->
          <div v-show="tab === 'descripcion'">
            <label class="kvs-lbl" style="text-align:left; display:block; margin-bottom:4px;">Descripción</label>
            <Textarea v-model="form.descripcion_larga" :disabled="!editando" rows="5" style="width:100%" />
            <label class="kvs-lbl" style="text-align:left; display:block; margin:10px 0 4px;">Observación</label>
            <Textarea v-model="form.observacion" :disabled="!editando" rows="3" style="width:100%" />
          </div>

          <!-- Ubicación física -->
          <div v-show="tab === 'ubicacion'">
            <p class="kvs-hint">Dónde está el artículo en la bodega (fila y columna), para encontrarlo rápido.</p>
            <div class="kvs-row">
              <label class="kvs-lbl">Ubicación física:</label>
              <InputText v-model="form.ubicacion" placeholder="Fila 3, Columna B" :disabled="!editando" class="kvs-in" />
            </div>
          </div>

          <!-- Componentes del combo -->
          <div v-show="tab === 'combo'">
            <p class="kvs-hint">
              Si el artículo es un combo, acá se arma con sus partes. Al venderlo, el sistema
              descarga del stock cada componente.
            </p>
            <Message v-if="!form.es_combo" severity="info" :closable="false">
              Marcá "Es combo" en la pestaña General para armarlo con componentes.
            </Message>
            <template v-else>
              <table class="kvs-table">
                <thead><tr><th>Código</th><th>Componente</th><th class="der">Cantidad</th><th style="width:40px"></th></tr></thead>
                <tbody>
                  <tr v-for="c in componentes" :key="c.id">
                    <td>{{ c.component?.codigo }}</td>
                    <td>{{ c.component?.descripcion }}</td>
                    <td class="der">{{ Number(c.cantidad) }}</td>
                    <td><Button icon="pi pi-times" text size="small" severity="danger" @click="quitarComponente(c)" /></td>
                  </tr>
                  <tr v-if="!componentes.length"><td colspan="4" class="vacio">Sin componentes</td></tr>
                </tbody>
              </table>
              <div class="kvs-row" style="margin-top:8px;">
                <Select v-model="nuevoComponente.component_id"
                        :options="rows.filter((r) => r.id !== form.id && !r.es_combo)"
                        optionValue="id" :optionLabel="(r) => r.codigo + ' — ' + r.descripcion"
                        placeholder="Elegir componente" filter style="flex:1" />
                <InputNumber v-model="nuevoComponente.cantidad" :useGrouping="false" style="width:90px" />
                <Button icon="pi pi-plus" size="small" :disabled="!form.id" @click="agregarComponente" />
              </div>
            </template>
          </div>

          <!-- Imágenes -->
          <div v-show="tab === 'imagen'">
            <div class="kvs-row">
              <label class="kvs-lbl">Imagen (URL):</label>
              <InputText v-model="form.imagen" placeholder="https://…" :disabled="!editando" class="kvs-in" />
            </div>
            <img v-if="form.imagen" :src="form.imagen" alt=""
                 style="max-width:220px; max-height:220px; margin-top:12px; border:1px solid #e2e5ea; border-radius:6px;" />
          </div>
        </div>

        <!-- Botonera, como KVS -->
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
