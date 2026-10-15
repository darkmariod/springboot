<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import Checkbox from 'primevue/checkbox'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useTabsStore } from '../stores/tabs'

/**
 * Toma física masiva: se cuentan muchos productos de una bodega a la vez y al aplicar se ajustan todos juntos
 * (un movimiento por diferencia y un solo asiento). Lo que se deja en blanco NO se ajusta.
 * Dos vistas: la lista de tomas y el editor de una toma (borrador editable; aplicada, solo lectura).
 */
const company = useCompanyStore()
const tabs = useTabsStore()

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const cant = (n: any) => Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 })
const redondear = (n: number, d = 4) => Math.round((n + Number.EPSILON) * 10 ** d) / 10 ** d

function mensajeDeError(err: any, porDefecto: string) {
  const e = err?.response?.data?.errors
  return e ? (Object.values(e).flat() as string[]).join(' · ') : (err?.response?.data?.message ?? porDefecto)
}

// ── Estado general ───────────────────────────────────────────────────────────
const conteos = ref<any[]>([])
const bodegas = ref<any[]>([])
const cargando = ref(true)
const msg = ref<{ tipo: 'success' | 'error' | 'warn' | 'info'; texto: string } | null>(null)
const toma = ref<any>(null)          // la toma abierta en el editor (null = se ve la lista)
const lineas = ref<any[]>([])        // sus líneas, editables mientras es borrador

const esBorrador = computed(() => toma.value?.estado === 'borrador')

async function cargarLista() {
  cargando.value = true
  try {
    const [c, b] = await Promise.all([
      api.get('/physical-counts', { params: { company_id: company.activeId } }),
      api.get('/warehouses', { params: { company_id: company.activeId } }),
    ])
    conteos.value = c.data
    bodegas.value = b.data
  } finally {
    cargando.value = false
  }
}

// ── Nueva toma ───────────────────────────────────────────────────────────────
const dialogNueva = ref(false)
const creando = ref(false)
const errorNueva = ref('')
const nueva = ref({ warehouse_id: null as number | null, buscar: '', motivo: '', incluir_sin_stock: false })

function abrirNueva() {
  errorNueva.value = ''
  nueva.value = { warehouse_id: bodegas.value.find((b: any) => b.por_defecto)?.id ?? bodegas.value[0]?.id ?? null, buscar: '', motivo: '', incluir_sin_stock: false }
  dialogNueva.value = true
}
async function crear() {
  if (!nueva.value.warehouse_id) return
  creando.value = true
  errorNueva.value = ''
  try {
    const { data } = await api.post('/physical-counts', {
      company_id: company.activeId,
      warehouse_id: nueva.value.warehouse_id,
      buscar: nueva.value.buscar.trim() || null,
      motivo: nueva.value.motivo.trim() || null,
      incluir_sin_stock: nueva.value.incluir_sin_stock,
    })
    dialogNueva.value = false
    msg.value = data.lineas.length
      ? { tipo: 'success', texto: `Toma física ${data.numero} abierta con ${data.lineas.length} producto(s). Cuenta y guarda el borrador cuando quieras.` }
      : { tipo: 'warn', texto: `Toma física ${data.numero} abierta, pero la bodega no tiene productos con ese filtro. Elimínala y prueba sin filtro, o marca «Incluir productos sin stock».` }
    mostrar(data)
    await cargarLista()
  } catch (err: any) {
    errorNueva.value = mensajeDeError(err, 'No se pudo abrir la toma física.')
  } finally {
    creando.value = false
  }
}

// ── Abrir, guardar y cerrar el editor ────────────────────────────────────────
const sucias = ref<Set<number>>(new Set())      // product_id de lo que se tocó y todavía no se guardó
const guardando = ref(false)
const first = ref(0)
const filasPorPagina = ref(50)
const soloDiferencia = ref(false)
const soloNoContados = ref(false)
const escaneo = ref('')
const resaltado = ref<number | null>(null)
const ciego = ref(false)

function mostrar(data: any) {
  toma.value = data
  lineas.value = data.lineas
  sucias.value = new Set()
  first.value = 0
  soloDiferencia.value = false
  soloNoContados.value = false
  escaneo.value = ''
  resaltado.value = null
  if (data.estado === 'borrador') nextTick(() => document.getElementById('escaner-toma')?.focus())
}
async function abrir(c: any) {
  msg.value = null
  try {
    mostrar((await api.get(`/physical-counts/${c.id}`, { params: { company_id: company.activeId } })).data)
  } catch (err: any) {
    msg.value = { tipo: 'error', texto: mensajeDeError(err, 'No se pudo abrir la toma física.') }
  }
}
/** Guarda lo contado (solo lo que cambió). Devuelve false si el servidor lo rechazó. */
async function guardar(avisar = true): Promise<boolean> {
  if (!toma.value || !esBorrador.value) return true
  guardando.value = true
  try {
    const { data } = await api.put(`/physical-counts/${toma.value.id}`, {
      company_id: company.activeId,
      lineas: lineas.value.filter((l: any) => sucias.value.has(l.product_id)).map((l: any) => ({ product_id: l.product_id, contado: l.contado ?? null })),
    })
    toma.value = data
    lineas.value = data.lineas
    sucias.value = new Set()
    if (avisar) msg.value = { tipo: 'success', texto: 'Borrador guardado.' }
    return true
  } catch (err: any) {
    msg.value = { tipo: 'error', texto: mensajeDeError(err, 'No se pudo guardar el borrador.') }
    return false
  } finally {
    guardando.value = false
  }
}
async function volver() {
  if (esBorrador.value && sucias.value.size && !(await guardar(false))) return
  toma.value = null
  lineas.value = []
  await cargarLista()
}

// ── Cálculo de diferencias (en borrador se compara con la foto del sistema; aplicada trae lo calculado) ────
const contada = (l: any) => l.contado !== null && l.contado !== undefined
const stockDe = (l: any) => (esBorrador.value ? l.stock_sistema : (l.stock_al_aplicar ?? l.stock_sistema))
function difDe(l: any): number | null {
  if (!esBorrador.value) return l.diferencia
  return contada(l) ? redondear(Number(l.contado) - Number(l.stock_sistema)) : null
}
function valorDe(l: any): number | null {
  if (!esBorrador.value) return l.valor
  const d = difDe(l)
  return d === null ? null : redondear(d * Number(l.costo_promedio), 2)
}

const visibles = computed(() => {
  const t = escaneo.value.trim().toLowerCase()
  return lineas.value.filter((l: any) => {
    if (soloNoContados.value && contada(l)) return false
    if (soloDiferencia.value && !(difDe(l) !== null && difDe(l) !== 0)) return false
    if (t && !(`${l.codigo} ${l.descripcion} ${(l.codigos_alternos ?? []).join(' ')}`.toLowerCase().includes(t))) return false
    return true
  })
})

const totales = computed(() => {
  let sobrantes = 0, faltantes = 0, conDiferencia = 0, contados = 0
  for (const l of lineas.value) {
    if (contada(l)) contados++
    const v = valorDe(l)
    const d = difDe(l)
    if (v !== null && d !== null && d !== 0) {
      conDiferencia++
      if (v > 0) sobrantes += v
      else faltantes += -v
    }
  }
  return { sobrantes: redondear(sobrantes, 2), faltantes: redondear(faltantes, 2), conDiferencia, contados, productos: lineas.value.length }
})
const stockCambio = computed(() => esBorrador.value ? lineas.value.filter((l: any) => l.stock_cambio && contada(l)) : [])

function marcar(l: any) { sucias.value.add(l.product_id); sucias.value = new Set(sucias.value) }

// ── Lector de códigos: escribir o escanear un código suma 1 a ese producto y lleva el foco a su "Contado" ────
const indice = computed(() => {
  const m = new Map<string, any>()
  for (const l of lineas.value) {
    m.set(String(l.codigo).toLowerCase(), l)
    for (const c of l.codigos_alternos ?? []) if (!m.has(String(c).toLowerCase())) m.set(String(c).toLowerCase(), l)
  }
  return m
})
async function irA(l: any) {
  // Si un filtro la dejaría fuera de la vista, se quitan los filtros para que se vea lo que se acaba de contar
  if (!visibles.value.includes(l)) { soloDiferencia.value = false; soloNoContados.value = false; escaneo.value = '' }
  await nextTick()
  const pos = visibles.value.indexOf(l)
  first.value = Math.floor(Math.max(pos, 0) / filasPorPagina.value) * filasPorPagina.value
  resaltado.value = l.product_id
  await nextTick()
  const campo = document.getElementById('contado-' + l.product_id) as HTMLInputElement | null
  campo?.focus()
  campo?.select()
  setTimeout(() => { if (resaltado.value === l.product_id) resaltado.value = null }, 1800)
}
function escanear() {
  const t = escaneo.value.trim()
  if (!t || !esBorrador.value) return
  let l = indice.value.get(t.toLowerCase())
  if (!l && visibles.value.length === 1) l = visibles.value[0]      // una sola coincidencia al escribir parte del nombre
  if (!l) {
    msg.value = { tipo: 'warn', texto: `No hay un producto con el código «${t}» en esta toma física.` }
    return
  }
  msg.value = null
  l.contado = redondear((contada(l) ? Number(l.contado) : 0) + 1)
  marcar(l)
  escaneo.value = ''
  irA(l)
}
/** Enter dentro de un "Contado" devuelve el foco al lector para seguir con el siguiente producto. */
function volverAlLector() { document.getElementById('escaner-toma')?.focus() }

// ── Aplicar ──────────────────────────────────────────────────────────────────
const dialogAplicar = ref(false)
const aplicando = ref(false)
const resumenAplicar = computed(() => {
  const dif = lineas.value.filter((l: any) => { const d = difDe(l); return d !== null && d !== 0 })
  return {
    faltan: dif.filter((l: any) => difDe(l)! < 0).length,
    sobran: dif.filter((l: any) => difDe(l)! > 0).length,
    sinCambio: lineas.value.filter((l: any) => contada(l) && difDe(l) === 0).length,
    noContados: lineas.value.filter((l: any) => !contada(l)).length,
  }
})
async function aplicar() {
  aplicando.value = true
  msg.value = null
  try {
    if (!(await guardar(false))) { dialogAplicar.value = false; return }
    const { data } = await api.post(`/physical-counts/${toma.value.id}/aplicar`, { company_id: company.activeId })
    dialogAplicar.value = false
    const cambios = (data.productos_con_cambio ?? []) as any[]
    msg.value = {
      tipo: cambios.length ? 'warn' : 'success',
      texto: `Toma física ${data.numero} aplicada: ${data.movimientos} movimiento(s), asiento ${data.asiento}. Sobrantes ${money(data.valor_sobrantes)} · faltantes ${money(data.valor_faltantes)}.`
        + (cambios.length ? ` El stock cambió mientras contabas en ${cambios.map((c) => c.codigo).join(', ')}: se ajustó contra el stock actual.` : ''),
    }
    mostrar((await api.get(`/physical-counts/${toma.value.id}`, { params: { company_id: company.activeId } })).data)
  } catch (err: any) {
    dialogAplicar.value = false
    msg.value = { tipo: 'error', texto: mensajeDeError(err, 'No se pudo aplicar la toma física.') }
  } finally {
    aplicando.value = false
  }
}

// ── Eliminar un borrador ─────────────────────────────────────────────────────
const eliminarTarget = ref<any>(null)
const eliminando = ref(false)
async function eliminar() {
  if (!eliminarTarget.value) return
  eliminando.value = true
  try {
    await api.delete(`/physical-counts/${eliminarTarget.value.id}`, { params: { company_id: company.activeId } })
    msg.value = { tipo: 'success', texto: `Borrador ${eliminarTarget.value.numero} eliminado.` }
    eliminarTarget.value = null
    await cargarLista()
  } catch (err: any) {
    msg.value = { tipo: 'error', texto: mensajeDeError(err, 'No se pudo eliminar el borrador.') }
    eliminarTarget.value = null
  } finally {
    eliminando.value = false
  }
}

// ── Hoja de conteo para llevar a la bodega ───────────────────────────────────
const exportando = ref(false)
async function exportarHoja(formato: 'pdf' | 'excel') {
  if (!toma.value || exportando.value) return
  exportando.value = true
  try {
    const res = await api.get(`/physical-counts/${toma.value.id}/hoja`, {
      params: { company_id: company.activeId, formato, ciego: ciego.value ? 1 : 0 },
      responseType: 'blob',
    })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: formato === 'pdf' ? 'application/pdf' : 'text/csv;charset=utf-8' }))
    a.download = `hoja-conteo-${toma.value.numero}.${formato === 'pdf' ? 'pdf' : 'csv'}`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    msg.value = { tipo: 'error', texto: 'No se pudo generar la hoja de conteo. Inténtalo de nuevo.' }
  } finally {
    exportando.value = false
  }
}

function irAAjuste() {
  tabs.open({ key: 'inventory-adjustment', label: 'Ajuste Inventario', icon: 'pi pi-sliders-h', component: 'InventoryAdjustment' })
}

const colorDif = (n: number | null) => n === null || n === 0 ? '#64748b' : n < 0 ? '#991b1b' : '#166534'
const signo = (n: number) => (n > 0 ? '+' : '')
const rowClass = (l: any) => (l.product_id === resaltado.value ? 'fila-resaltada' : '')

onMounted(cargarLista)
</script>

<template>
  <div style="padding: 20px;">
    <!-- ═══════════════ Lista de tomas ═══════════════ -->
    <template v-if="!toma">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h2 style="margin:0;">Toma física de inventario</h2>
        <Button label="Nueva toma física" icon="pi pi-plus" @click="abrirNueva" />
      </div>
      <Message severity="info" :closable="false" style="margin-bottom:14px;">
        Cuenta muchos productos de una bodega a la vez. Lo que dejes <b>en blanco no se ajusta</b>. Al aplicar, el sistema registra
        un movimiento por cada diferencia y <b>un solo asiento</b> para toda la toma. Los productos con series se ajustan por serie en Ajuste de inventario.
      </Message>
      <Message v-if="msg" :severity="msg.tipo" :closable="true" style="margin-bottom:14px;" @close="msg = null">{{ msg.texto }}</Message>

      <DataTable :value="conteos" :loading="cargando" size="small" stripedRows selectionMode="single" dataKey="id" @row-click="(e: any) => abrir(e.data)">
        <Column field="numero" header="Número" style="width:120px"><template #body="{ data }"><b>{{ data.numero }}</b></template></Column>
        <Column header="Bodega"><template #body="{ data }">{{ data.warehouse?.nombre }}</template></Column>
        <Column header="Estado" style="width:110px"><template #body="{ data }">
          <Tag :value="data.estado === 'aplicada' ? 'Aplicada' : 'Borrador'" :severity="data.estado === 'aplicada' ? 'success' : 'warn'" />
        </template></Column>
        <Column header="Fecha" style="width:110px"><template #body="{ data }">{{ data.fecha }}</template></Column>
        <Column header="Productos" style="width:130px"><template #body="{ data }">{{ data.contados }} de {{ data.productos }} contados</template></Column>
        <Column header="Faltantes" style="width:110px"><template #body="{ data }"><span :style="{ color: data.valor_faltantes > 0 ? '#991b1b' : '#64748b' }">{{ money(data.valor_faltantes) }}</span></template></Column>
        <Column header="Sobrantes" style="width:110px"><template #body="{ data }"><span :style="{ color: data.valor_sobrantes > 0 ? '#166534' : '#64748b' }">{{ money(data.valor_sobrantes) }}</span></template></Column>
        <Column header="Motivo"><template #body="{ data }">{{ data.motivo ?? '—' }}</template></Column>
        <Column header="" style="width:60px"><template #body="{ data }">
          <Button v-if="data.estado === 'borrador'" icon="pi pi-trash" text severity="danger" size="small" aria-label="Eliminar borrador"
                  @click.stop="eliminarTarget = data" />
        </template></Column>
        <template #empty>Todavía no hay tomas físicas. Crea la primera con «Nueva toma física».</template>
      </DataTable>
    </template>

    <!-- ═══════════════ Editor de una toma ═══════════════ -->
    <template v-else>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px;">
          <Button icon="pi pi-arrow-left" text aria-label="Volver a la lista" label="Lista" @click="volver" />
          <h2 style="margin:0;">Toma física {{ toma.numero }}</h2>
          <Tag :value="esBorrador ? 'Borrador' : 'Aplicada'" :severity="esBorrador ? 'warn' : 'success'" />
        </div>
        <div style="font-size:13px; color:#475569;">
          Bodega <b>{{ toma.warehouse?.nombre }}</b> · {{ toma.fecha }}<span v-if="toma.motivo"> · {{ toma.motivo }}</span>
          <span v-if="!esBorrador && toma.asiento"> · Asiento <b>{{ toma.asiento }}</b></span>
        </div>
      </div>

      <Message v-if="msg" :severity="msg.tipo" :closable="true" style="margin-bottom:10px;" @close="msg = null">{{ msg.texto }}</Message>
      <Message v-if="toma.omitidos_series?.length" severity="info" :closable="false" style="margin-bottom:10px;">
        {{ toma.omitidos_series.length }} producto(s) con series no entran en esta toma:
        {{ toma.omitidos_series.map((o: any) => `${o.codigo} (${cant(o.stock)})`).join(', ') }}.
        Cada unidad tiene su número de serie; se ajustan por serie en
        <a href="#" @click.prevent="irAAjuste">Ajuste de inventario</a>.
      </Message>
      <Message v-if="stockCambio.length" severity="warn" :closable="false" style="margin-bottom:10px;">
        El stock cambió desde que abriste esta toma en {{ stockCambio.length }} producto(s) contado(s)
        ({{ stockCambio.slice(0, 6).map((l: any) => l.codigo).join(', ') }}{{ stockCambio.length > 6 ? '…' : '' }}).
        Al aplicar, la diferencia se calcula contra el stock actual de la bodega, no contra el de la columna «Stock del sistema».
      </Message>

      <!-- Totales -->
      <div class="barra-totales">
        <div><span>Faltantes</span><b style="color:#991b1b;">{{ money(totales.faltantes) }}</b></div>
        <div><span>Sobrantes</span><b style="color:#166534;">{{ money(totales.sobrantes) }}</b></div>
        <div><span>Contados</span><b>{{ totales.contados }} de {{ totales.productos }}</b></div>
        <div><span>Con diferencia</span><b>{{ totales.conDiferencia }}</b></div>
        <div v-if="esBorrador"><span>Sin guardar</span><b :style="{ color: sucias.size ? '#b45309' : '#64748b' }">{{ sucias.size }}</b></div>
      </div>

      <!-- Herramientas -->
      <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px 16px; margin:10px 0;">
        <InputText v-if="esBorrador" id="escaner-toma" v-model="escaneo" placeholder="Escanea o escribe un código y presiona Enter (suma 1)"
                   style="width:380px;" aria-label="Lector de códigos" @keydown.enter.prevent="escanear" />
        <InputText v-else v-model="escaneo" placeholder="Buscar un producto" style="width:280px;" aria-label="Buscar un producto" />
        <label class="chk"><Checkbox v-model="soloDiferencia" binary input-id="solo-dif" /> Solo con diferencia</label>
        <label class="chk"><Checkbox v-model="soloNoContados" binary input-id="solo-nc" /> Solo no contados</label>
        <span style="font-size:12px; color:#94a3b8;">{{ visibles.length }} de {{ lineas.length }} productos</span>
      </div>

      <DataTable :value="visibles" size="small" stripedRows paginator v-model:first="first" :rows="filasPorPagina"
                 :rowsPerPageOptions="[25, 50, 100, 200]" dataKey="product_id" :rowClass="rowClass" @update:rows="(v: number) => (filasPorPagina = v)">
        <Column field="codigo" header="Código" style="width:130px" />
        <Column field="descripcion" header="Producto" />
        <Column header="Stock del sistema" style="width:140px">
          <template #body="{ data }"><div style="text-align:right;">{{ cant(stockDe(data)) }}</div></template>
        </Column>
        <Column header="Contado" style="width:150px">
          <template #body="{ data }">
            <InputNumber v-if="esBorrador" v-model="data.contado" :inputId="'contado-' + data.product_id" :min="0" :maxFractionDigits="4"
                         :useGrouping="false" placeholder="—" :inputStyle="{ width: '110px', textAlign: 'right' }"
                         @update:modelValue="marcar(data)" @keydown.enter="volverAlLector" />
            <div v-else style="text-align:right;">{{ contada(data) ? cant(data.contado) : '—' }}</div>
          </template>
        </Column>
        <Column header="Diferencia" style="width:120px">
          <template #body="{ data }">
            <div v-if="difDe(data) !== null" style="text-align:right; font-weight:700;" :style="{ color: colorDif(difDe(data)) }">
              {{ signo(difDe(data)!) }}{{ cant(difDe(data)) }}
            </div>
            <div v-else style="text-align:right; color:#cbd5e1;">—</div>
          </template>
        </Column>
        <Column header="Valor" style="width:110px">
          <template #body="{ data }">
            <div v-if="valorDe(data) !== null && difDe(data) !== 0" style="text-align:right;" :style="{ color: colorDif(valorDe(data)) }">
              {{ valorDe(data)! < 0 ? '-' : '' }}{{ money(Math.abs(valorDe(data)!)) }}
            </div>
            <div v-else style="text-align:right; color:#cbd5e1;">—</div>
          </template>
        </Column>
        <template #empty>No hay productos con ese filtro.</template>
      </DataTable>

      <!-- Acciones -->
      <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px; margin-top:14px;">
        <template v-if="esBorrador">
          <Button label="Guardar borrador" icon="pi pi-save" :loading="guardando" outlined @click="guardar()" />
          <Button label="Aplicar toma física" icon="pi pi-check" :disabled="!totales.contados" @click="dialogAplicar = true" />
        </template>
        <span style="flex:1;"></span>
        <label class="chk" title="Quita la columna de stock para que quien cuenta no se guíe por el sistema">
          <Checkbox v-model="ciego" binary input-id="hoja-ciega" /> A ciegas (sin stock)
        </label>
        <Button label="Hoja de conteo PDF" icon="pi pi-file-pdf" outlined size="small" :loading="exportando" @click="exportarHoja('pdf')" />
        <Button label="Hoja de conteo Excel" icon="pi pi-file-excel" outlined size="small" :loading="exportando" @click="exportarHoja('excel')" />
      </div>
    </template>

    <!-- Nueva toma -->
    <Dialog v-model:visible="dialogNueva" modal header="Nueva toma física" style="width:520px">
      <Message v-if="errorNueva" severity="error" :closable="false" style="margin-bottom:10px;">{{ errorNueva }}</Message>
      <small v-if="!cargando && !bodegas.length" style="color:#b45309;">Esta empresa aún no tiene bodegas. Crea una en Inventario → Bodegas.</small>
      <template v-else>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Bodega:</label>
          <Select v-model="nueva.warehouse_id" :options="bodegas" optionLabel="nombre" optionValue="id" placeholder="Elige la bodega a contar" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Solo productos que contengan:</label>
          <InputText v-model="nueva.buscar" placeholder="Opcional: código o nombre (una línea, una familia...)" class="kvs-in" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Motivo:</label>
          <InputText v-model="nueva.motivo" placeholder="Ej: Inventario de fin de mes" class="kvs-in" />
        </div>
        <label class="chk" style="margin-top:4px;">
          <Checkbox v-model="nueva.incluir_sin_stock" binary input-id="incl-sin-stock" />
          Incluir también los productos que el sistema dice que no hay en esa bodega
        </label>
        <p style="font-size:12px; color:#64748b; margin:10px 0 0;">
          Se cargan los productos con stock en la bodega. Los servicios, los combos y los productos con series no entran.
        </p>
      </template>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="dialogNueva = false" />
          <Button label="Abrir toma física" icon="pi pi-check" :loading="creando" :disabled="!nueva.warehouse_id" @click="crear" />
        </div>
      </template>
    </Dialog>

    <!-- Confirmar la aplicación -->
    <Dialog v-model:visible="dialogAplicar" modal header="Aplicar toma física" style="width:520px">
      <p style="margin:0 0 10px; font-size:13px;">
        Se ajustará el inventario de la bodega <b>{{ toma?.warehouse?.nombre }}</b> con lo que contaste. Esto no se puede deshacer.
      </p>
      <ul style="margin:0 0 10px; padding-left:18px; font-size:13px; line-height:1.7;">
        <li><b>{{ resumenAplicar.faltan }}</b> producto(s) con faltante por <b style="color:#991b1b;">{{ money(totales.faltantes) }}</b></li>
        <li><b>{{ resumenAplicar.sobran }}</b> producto(s) con sobrante por <b style="color:#166534;">{{ money(totales.sobrantes) }}</b></li>
        <li>{{ resumenAplicar.sinCambio }} contado(s) coinciden con el sistema y no se tocan</li>
        <li>{{ resumenAplicar.noContados }} sin contar: <b>no se ajustan</b></li>
      </ul>
      <p style="margin:0; font-size:12px; color:#64748b;">
        Se crea un movimiento por cada diferencia y un solo asiento con fecha de hoy. Si el mes de hoy está cerrado, no se aplicará.
      </p>
      <Message v-if="stockCambio.length" severity="warn" :closable="false" style="margin-top:10px;">
        El stock cambió en {{ stockCambio.length }} producto(s) desde que abriste la toma: se calcula contra el stock actual.
      </Message>
      <Message v-if="!resumenAplicar.faltan && !resumenAplicar.sobran" severity="info" :closable="false" style="margin-top:10px;">
        No hay diferencias que ajustar con lo contado hasta ahora.
      </Message>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="dialogAplicar = false" />
          <Button label="Aplicar toma física" icon="pi pi-check" :loading="aplicando" @click="aplicar" />
        </div>
      </template>
    </Dialog>

    <!-- Eliminar un borrador -->
    <Dialog :visible="!!eliminarTarget" modal header="Eliminar borrador" style="width:420px" @update:visible="eliminarTarget = null">
      <p v-if="eliminarTarget" style="margin:0; font-size:13px;">
        ¿Eliminar el borrador <b>{{ eliminarTarget.numero }}</b>? Se pierde lo contado; el inventario no cambia.
      </p>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="eliminarTarget = null" />
          <Button label="Eliminar" icon="pi pi-trash" severity="danger" :loading="eliminando" @click="eliminar" />
        </div>
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.barra-totales {
  display: flex; flex-wrap: wrap; gap: 8px 28px; align-items: baseline;
  background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 14px;
}
.barra-totales > div { display: flex; flex-direction: column; font-size: 11px; color: #64748b; text-transform: uppercase; }
.barra-totales b { font-size: 16px; color: #1f2733; text-transform: none; }
.chk { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
:deep(.fila-resaltada) { background: #fef9c3 !important; transition: background 0.4s; }
</style>
