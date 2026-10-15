<script setup lang="ts">
/**
 * Series / Lotes.
 *
 * Tres cosas en una pantalla:
 *   1. Buscar una serie por un pedazo de su número (en cualquier producto) y ver su historial:
 *      compra → venta → devolución o baja.
 *   2. Ver y administrar las series de un producto (estado, bodega, baja).
 *   3. Registrar series de stock que ya está en el kárdex (lo normal es que entren con la compra).
 *
 * El stock sigue viniendo del kárdex: si las series disponibles no coinciden con el stock, se avisa.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import { useConfirm } from 'primevue/useconfirm'
import ConfirmDialog from 'primevue/confirmdialog'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const confirm = useConfirm()

const productos = ref<any[]>([])
const bodegas = ref<any[]>([])
const productoId = ref<number | null>(null)
const bodegaRegistro = ref<number | null>(null)
const series = ref<any[]>([])
const cargando = ref(false)
const msg = ref<{ type: string; text: string } | null>(null)

const filtro = ref({ serie: '', estado: null as string | null })
const nuevas = ref('')

const estados = [
  { label: 'Disponible', value: 'disponible' },
  { label: 'Vendida', value: 'vendida' },
  { label: 'Devuelto', value: 'devuelto' },
  { label: 'Dañado', value: 'danado' },
]

const producto = computed(() => productos.value.find((p) => p.id === productoId.value))

const filtradas = computed(() =>
  series.value.filter(
    (s) =>
      (!filtro.value.serie || String(s.serie).toLowerCase().includes(filtro.value.serie.toLowerCase())) &&
      (!filtro.value.estado || s.estado === filtro.value.estado),
  ),
)

const disponibles = computed(() => series.value.filter((s) => s.estado === 'disponible').length)

/** El kárdex manda: si las series no cuadran con el stock, hay que avisarlo. */
const descuadre = computed(() => {
  if (!producto.value) return null
  const stock = Number(producto.value.stock ?? 0)
  return Math.abs(stock - disponibles.value) < 0.0005 ? null : { stock, disponibles: disponibles.value }
})

function aviso(type: string, text: string) {
  msg.value = { type, text }
  setTimeout(() => (msg.value = null), 6000)
}

async function cargarProductos() {
  const data = (await api.get('/products?company_id=' + company.activeId)).data
  productos.value = data.filter((p: any) => p.maneja_series)
  if (productos.value.length && !productos.value.some((p) => p.id === productoId.value)) {
    productoId.value = productos.value[0].id
  }
}

async function cargarBodegas() {
  try {
    bodegas.value = (await api.get('/warehouses?company_id=' + company.activeId)).data
  } catch {
    bodegas.value = []
  }
  if (!bodegas.value.some((b) => b.id === bodegaRegistro.value)) {
    bodegaRegistro.value = (bodegas.value.find((b) => b.por_defecto) ?? bodegas.value[0])?.id ?? null
  }
}

async function cargarSeries() {
  if (!productoId.value) {
    series.value = []
    return
  }
  cargando.value = true
  try {
    series.value = (
      await api.get('/series?company_id=' + company.activeId + '&product_id=' + productoId.value)
    ).data
  } finally {
    cargando.value = false
  }
}

async function registrar() {
  const lista = nuevas.value
    .split(/[\n,;]+/)
    .map((s) => s.trim())
    .filter(Boolean)
  if (!productoId.value) return aviso('warn', 'Elige primero un producto.')
  if (!lista.length) return aviso('warn', 'Escribe al menos una serie, una por línea.')

  const repetidas = lista.filter((s, i) => lista.indexOf(s) !== i)
  if (repetidas.length) {
    return aviso('error', 'Hay series repetidas en la lista: ' + [...new Set(repetidas)].join(', '))
  }

  try {
    const creadas = (
      await api.post('/series', {
        company_id: company.activeId,
        product_id: productoId.value,
        series: lista,
        ...(bodegaRegistro.value ? { warehouse_id: bodegaRegistro.value } : {}),
      })
    ).data
    nuevas.value = ''
    await cargarSeries()
    aviso('success', `${creadas.length} serie(s) registrada(s).`)
  } catch (e: any) {
    aviso('error', e.response?.data?.message ?? 'No se pudieron registrar las series.')
  }
}

/** El servidor responde lo mismo (422) si se intenta por la API: la baja de una serie disponible va por el Ajuste de inventario. */
const BAJA_POR_AJUSTE = 'Para dar de baja una serie usa Ajuste de inventario.'

async function cambiarEstado(fila: any, estado: string) {
  try {
    await api.put('/series/' + fila.id, { estado })
    await cargarSeries()
    aviso('success', `Serie ${fila.serie} marcada como ${estado}.`)
  } catch (e: any) {
    aviso('error', e.response?.data?.message ?? 'No se pudo cambiar el estado.')
  }
}

function eliminar(fila: any) {
  confirm.require({
    message: `¿Eliminar la serie ${fila.serie}? Esto no se puede deshacer.`,
    header: 'Eliminar serie',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: 'Eliminar',
    rejectLabel: 'Cancelar',
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await api.delete('/series/' + fila.id)
        await cargarSeries()
        aviso('success', `Serie ${fila.serie} eliminada.`)
      } catch (e: any) {
        aviso('error', e.response?.data?.message ?? 'No se pudo eliminar.')
      }
    },
  })
}

/* ── Búsqueda por número de serie ─────────────────────────────────────── */
const busqueda = ref('')
const resultados = ref<any[] | null>(null) // null = no hay búsqueda activa
const errorBusqueda = ref('')
const buscando = ref(false)
const detalle = ref<any>(null)
let temporizador: ReturnType<typeof setTimeout> | undefined
let busquedaSeq = 0

async function buscar() {
  clearTimeout(temporizador)
  const q = busqueda.value.trim()
  errorBusqueda.value = ''
  if (!q) {
    resultados.value = null
    return
  }
  if (q.length < 3) {
    resultados.value = null
    errorBusqueda.value = 'Escribe al menos 3 caracteres del número de serie.'
    return
  }
  const seq = ++busquedaSeq
  buscando.value = true
  try {
    const res = (await api.get('/series/buscar', { params: { company_id: company.activeId, q } })).data
    if (seq !== busquedaSeq) return // llegó tarde: ya se buscó otra cosa
    resultados.value = res
    // Una sola coincidencia con el número completo: se abre su historial de una vez (típico de escanear)
    if (res.length === 1 && res[0].serie.toLowerCase() === q.toLowerCase()) detalle.value = res[0]
  } catch (e: any) {
    if (seq !== busquedaSeq) return
    resultados.value = null
    errorBusqueda.value = e.response?.data?.errors?.q?.[0] ?? e.response?.data?.message ?? 'No se pudo buscar la serie.'
  } finally {
    if (seq === busquedaSeq) buscando.value = false
  }
}
function limpiarBusqueda() {
  busqueda.value = ''
  resultados.value = null
  errorBusqueda.value = ''
}
// Mientras se escribe busca sola (con una pausa); Enter busca de inmediato
watch(busqueda, () => {
  clearTimeout(temporizador)
  errorBusqueda.value = ''
  if (busqueda.value.trim().length >= 3) temporizador = setTimeout(buscar, 350)
  else resultados.value = null
})
onBeforeUnmount(() => clearTimeout(temporizador))

const color: Record<string, string> = {
  disponible: 'success',
  vendida: 'info',
  devuelto: 'warn',
  danado: 'danger',
}
const etiquetaEstado: Record<string, string> = {
  disponible: 'Disponible',
  vendida: 'Vendida',
  devuelto: 'Devuelto',
  danado: 'Dañada',
}
const iconoHistorial: Record<string, string> = {
  compra: 'pi pi-shopping-cart',
  venta: 'pi pi-receipt',
  devolucion: 'pi pi-undo',
  baja: 'pi pi-exclamation-triangle',
}
const tituloHistorial: Record<string, string> = {
  compra: 'Compra',
  venta: 'Venta',
  devolucion: 'Devolución',
  baja: 'Baja',
}

watch(productoId, cargarSeries)
watch(() => company.activeId, async () => { await cargarProductos(); await cargarBodegas(); await cargarSeries(); limpiarBusqueda() })
onMounted(async () => {
  await cargarProductos()
  await cargarBodegas()
  await cargarSeries()
})
</script>

<template>
  <div class="kvs-split">
    <ConfirmDialog />

    <!-- ══ Buscar y listar series ══ -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Series / Lotes</div>

      <!-- Búsqueda por número de serie: la herramienta principal de la pantalla -->
      <div class="serie-busqueda">
        <label for="serie-buscar" class="serie-busqueda-lbl">Buscar por número de serie</label>
        <div class="serie-busqueda-fila">
          <span class="p-input-icon-left" style="flex:1; position:relative;">
            <i class="pi pi-search" style="position:absolute; left:12px; top:50%; margin-top:-7px; color:#64748b;" />
            <InputText
              id="serie-buscar"
              v-model="busqueda"
              placeholder="Escribe o escanea parte de la serie (mínimo 3 caracteres): DS-2CD, IMEI…"
              style="width:100%; padding-left:34px; font-family:monospace;"
              @keydown.enter.prevent="buscar"
            />
          </span>
          <Button icon="pi pi-search" label="Buscar" :loading="buscando" @click="buscar" />
          <Button v-if="resultados !== null || busqueda" icon="pi pi-times" text title="Limpiar la búsqueda" @click="limpiarBusqueda" />
        </div>
        <small v-if="errorBusqueda" style="color:#b45309;">{{ errorBusqueda }}</small>
      </div>

      <!-- Resultados de la búsqueda -->
      <template v-if="resultados !== null">
        <div class="kvs-panel-title" style="background:#eef6f6;">
          {{ resultados.length }} coincidencia(s) para «{{ busqueda.trim() }}»
          <span v-if="resultados.length >= 100" style="font-weight:400; color:#b45309;"> · se muestran las primeras 100: escribe más de la serie</span>
        </div>
        <DataTable :value="resultados" size="small" scrollable scrollHeight="flex" stripedRows class="kvs-grid"
                   selectionMode="single" dataKey="id" @row-select="(e) => (detalle = e.data)">
          <template #empty>
            <div class="kvs-empty">Ninguna serie contiene «{{ busqueda.trim() }}».</div>
          </template>
          <Column header="Serie / IMEI" style="min-width:170px">
            <template #body="{ data }"><span style="font-family:monospace; font-weight:600;">{{ data.serie }}</span></template>
          </Column>
          <Column header="Artículo" style="min-width:200px">
            <template #body="{ data }">
              <span style="font-family:monospace">{{ data.producto?.codigo }}</span> · {{ data.producto?.descripcion }}
            </template>
          </Column>
          <Column header="Estado" style="width:110px">
            <template #body="{ data }"><Tag :value="etiquetaEstado[data.estado] ?? data.estado" :severity="color[data.estado] ?? 'secondary'" /></template>
          </Column>
          <Column header="Bodega" style="width:150px">
            <template #body="{ data }">{{ data.bodega?.nombre ?? '—' }}</template>
          </Column>
          <Column header="Compra" style="width:190px">
            <template #body="{ data }">
              {{ data.compra?.numero ?? '—' }}
              <div v-if="data.compra?.proveedor" style="font-size:11px; color:#64748b;">{{ data.compra.proveedor }}</div>
            </template>
          </Column>
          <Column header="Venta" style="width:190px">
            <template #body="{ data }">
              {{ data.venta?.numero ?? '—' }}
              <div v-if="data.venta?.cliente" style="font-size:11px; color:#64748b;">{{ data.venta.cliente }}</div>
            </template>
          </Column>
          <Column header="Devolución" style="width:120px">
            <template #body="{ data }">{{ data.devolucion?.numero ?? '—' }}</template>
          </Column>
          <Column header="" style="width:60px">
            <template #body="{ data }">
              <Button icon="pi pi-history" size="small" text title="Ver el historial de la serie" @click.stop="detalle = data" />
            </template>
          </Column>
        </DataTable>
      </template>

      <!-- Series de un producto -->
      <template v-else>
        <div class="kvs-search">
          <span style="font-size:12px; color:#546e7a;">Producto</span>
          <Select
            v-model="productoId"
            :options="productos"
            optionLabel="codigo"
            optionValue="id"
            placeholder="Elige un producto con series"
            size="small"
            style="min-width:260px"
          >
            <template #option="{ option }">
              <span style="font-family:monospace">{{ option.codigo }}</span> — {{ option.descripcion }}
            </template>
          </Select>
          <InputText v-model="filtro.serie" placeholder="Filtrar la lista" size="small" style="width:150px" />
          <Select
            v-model="filtro.estado"
            :options="estados"
            optionLabel="label"
            optionValue="value"
            placeholder="Estado"
            size="small"
            showClear
            style="width:130px"
          />
        </div>

        <Message v-if="msg" :severity="msg.type" :closable="false" style="margin:8px 10px;">
          {{ msg.text }}
        </Message>

        <Message v-if="descuadre" severity="warn" :closable="false" style="margin:8px 10px;">
          El stock dice {{ descuadre.stock }} y hay {{ descuadre.disponibles }} serie(s) disponible(s).
          Cada unidad en stock debe tener su serie.
        </Message>

        <div v-if="!productos.length" class="kvs-empty">
          Ningún producto maneja series todavía. Ve a Bienes (inventario), abre el artículo y pon
          <b>Aplica Serie/Lote: Serie</b>.
        </div>

        <DataTable
          v-else
          :value="filtradas"
          :loading="cargando"
          size="small"
          scrollable
          scrollHeight="flex"
          stripedRows
          class="kvs-grid"
        >
          <Column field="serie" header="Serie / IMEI" style="min-width:160px">
            <template #body="{ data }">
              <span style="font-family:monospace">{{ data.serie }}</span>
            </template>
          </Column>
          <Column header="Estado" style="width:120px">
            <template #body="{ data }">
              <Tag :value="data.estado" :severity="color[data.estado] ?? 'secondary'" />
            </template>
          </Column>
          <Column header="Bodega" style="width:150px">
            <template #body="{ data }">{{ data.bodega ?? '—' }}</template>
          </Column>
          <Column header="Compra" style="width:130px">
            <template #body="{ data }">{{ data.purchase?.numero ?? '—' }}</template>
          </Column>
          <Column header="Factura" style="width:150px">
            <template #body="{ data }">{{ data.invoice?.numero ?? '—' }}</template>
          </Column>
          <Column header="Acciones" style="width:150px">
            <template #body="{ data }">
              <Button
                icon="pi pi-history"
                size="small"
                text
                title="Ver el historial de la serie"
                @click="busqueda = data.serie"
              />
              <!-- Una serie disponible es una unidad del stock: darla de baja mueve el stock, y eso lo hace el Ajuste de inventario.
                   El span lleva el aviso porque un botón deshabilitado no recibe el ratón. -->
              <span v-if="data.estado === 'disponible'" :title="BAJA_POR_AJUSTE">
                <Button
                  icon="pi pi-exclamation-triangle"
                  size="small"
                  text
                  severity="warn"
                  disabled
                  aria-label="Para dar de baja una serie usa Ajuste de inventario"
                />
              </span>
              <Button
                v-if="data.estado !== 'disponible' && data.estado !== 'vendida'"
                icon="pi pi-undo"
                size="small"
                text
                title="Volver a disponible"
                @click="cambiarEstado(data, 'disponible')"
              />
              <span :title="data.estado === 'vendida' ? 'Una serie vendida no se elimina' : data.estado === 'disponible' ? BAJA_POR_AJUSTE : 'Eliminar'">
                <Button
                  icon="pi pi-trash"
                  size="small"
                  text
                  severity="danger"
                  :disabled="data.estado === 'vendida' || data.estado === 'disponible'"
                  aria-label="Eliminar serie"
                  @click="eliminar(data)"
                />
              </span>
            </template>
          </Column>
        </DataTable>

        <div class="kvs-panel-foot">
          Mostrando {{ filtradas.length }} de {{ series.length }} · {{ disponibles }} disponible(s)
        </div>
      </template>
    </section>

    <!-- ══ Registrar ══ -->
    <section class="kvs-panel" style="width:400px; flex-shrink:0;">
      <div class="kvs-panel-title">Registrar series</div>

      <div style="padding:12px;">
        <div style="font-size:12px; color:#64748b; margin-bottom:8px;">
          Las series de un producto nuevo se escriben al registrar la compra, una por unidad. Aquí solo se registran las de
          stock que ya está en el kárdex sin series, hasta completar las unidades de la bodega. Una por línea; puedes
          escanearlas seguidas o pegarlas desde Excel.
        </div>
        <label style="display:flex; flex-direction:column; gap:4px; margin-bottom:8px; font-size:12px; color:#64748b;">
          Bodega donde están
          <Select
            v-model="bodegaRegistro"
            :options="bodegas"
            optionLabel="nombre"
            optionValue="id"
            placeholder="Bodega principal"
            size="small"
            :disabled="!productoId"
          />
        </label>
        <Textarea
          v-model="nuevas"
          rows="8"
          style="width:100%; font-family:monospace; font-size:12px;"
          placeholder="IMEI-000001&#10;IMEI-000002&#10;IMEI-000003"
          :disabled="!productoId"
        />
        <Button
          label="Registrar series"
          icon="pi pi-plus"
          size="small"
          style="margin-top:8px; width:100%"
          :disabled="!productoId"
          @click="registrar"
        />
      </div>
    </section>

    <!-- ══ Historial de una serie ══ -->
    <Dialog :visible="!!detalle" modal :header="'Serie ' + (detalle?.serie ?? '')" style="width:560px"
            @update:visible="(v: boolean) => { if (!v) detalle = null }">
      <div v-if="detalle" style="display:flex; flex-direction:column; gap:12px;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <Tag :value="etiquetaEstado[detalle.estado] ?? detalle.estado" :severity="color[detalle.estado] ?? 'secondary'" />
          <span><b>{{ detalle.producto?.codigo }}</b> — {{ detalle.producto?.descripcion }}</span>
        </div>
        <div style="font-size:13px;">
          <b>Bodega:</b> {{ detalle.bodega?.nombre ?? 'sin bodega' }}
          <span v-if="detalle.bodega?.inferida" style="color:#64748b;"> (serie anterior al control por bodega: se toma la principal)</span>
        </div>

        <div class="serie-linea" role="list">
          <div v-for="(h, i) in detalle.historial" :key="i" class="serie-paso" role="listitem">
            <i :class="iconoHistorial[h.tipo] ?? 'pi pi-circle'" class="serie-paso-icono" />
            <div>
              <div style="font-weight:600;">
                {{ tituloHistorial[h.tipo] ?? h.tipo }}
                <span v-if="h.documento" style="font-family:monospace; font-weight:400;"> · {{ h.documento }}</span>
                <span v-if="h.fecha" style="font-weight:400; color:#64748b;"> · {{ h.fecha }}</span>
              </div>
              <div v-if="h.contraparte" style="font-size:13px;">{{ h.contraparte }}</div>
              <div style="font-size:12px; color:#64748b;">{{ h.detalle }}</div>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <Button label="Cerrar" text @click="detalle = null" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.serie-busqueda {
  padding: 12px 14px;
  background: #f1f7f7;
  border-bottom: 1px solid #d5e3e3;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.serie-busqueda-lbl { font-size: 13px; font-weight: 700; color: #0f4c4c; }
.serie-busqueda-fila { display: flex; gap: 8px; align-items: center; }
.serie-linea { display: flex; flex-direction: column; gap: 14px; border-left: 2px solid #cbd5e1; margin-left: 12px; padding-left: 18px; }
.serie-paso { display: flex; gap: 12px; position: relative; }
.serie-paso-icono {
  position: absolute; left: -33px; top: 0;
  width: 28px; height: 28px; border-radius: 50%;
  background: #e0f2f1; color: #0f766e; display: flex; align-items: center; justify-content: center; font-size: 13px;
}
</style>
