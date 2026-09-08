<script setup lang="ts">
/**
 * Series / Lotes.
 *
 * Dos cosas en una pantalla:
 *   1. Registrar y administrar las series de un producto (alta, baja, cambio de estado).
 *   2. Consultar una serie: a quién se la compró y a quién se la vendió.
 *
 * Las series se crean acá o al registrar una compra. El stock sigue viniendo
 * del kárdex: si las series disponibles no coinciden con el stock, se avisa.
 */
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { useConfirm } from 'primevue/useconfirm'
import ConfirmDialog from 'primevue/confirmdialog'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const confirm = useConfirm()

const productos = ref<any[]>([])
const productoId = ref<number | null>(null)
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
      })
    ).data
    nuevas.value = ''
    await cargarSeries()
    aviso('success', `${creadas.length} serie(s) registrada(s).`)
  } catch (e: any) {
    aviso('error', e.response?.data?.message ?? 'No se pudieron registrar las series.')
  }
}

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

/* ── Consulta de garantía ────────────────────────────────────────────── */
const busqueda = ref('')
const trazado = ref<any>(null)
const errorTrace = ref('')

async function rastrear() {
  if (!busqueda.value.trim()) return
  errorTrace.value = ''
  trazado.value = null
  try {
    trazado.value = (
      await api.get(
        '/series/trace?company_id=' + company.activeId + '&serie=' + encodeURIComponent(busqueda.value),
      )
    ).data
  } catch {
    errorTrace.value = 'No se encontró la serie ' + busqueda.value
  }
}

const color: Record<string, string> = {
  disponible: 'success',
  vendida: 'info',
  devuelto: 'warn',
  danado: 'danger',
}

watch(productoId, cargarSeries)
watch(() => company.activeId, async () => { await cargarProductos(); await cargarSeries() })
onMounted(async () => {
  await cargarProductos()
  await cargarSeries()
})
</script>

<template>
  <div class="kvs-split">
    <ConfirmDialog />

    <!-- ══ Series del producto ══ -->
    <section class="kvs-panel" style="flex:1;">
      <div class="kvs-panel-title">Series / Lotes</div>

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
        <InputText v-model="filtro.serie" placeholder="Buscar serie" size="small" style="width:150px" />
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
        Ningún producto maneja series todavía. Ve a Productos y servicios, abre el artículo y pon
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
        <Column header="Compra" style="width:130px">
          <template #body="{ data }">{{ data.purchase?.numero ?? '—' }}</template>
        </Column>
        <Column header="Factura" style="width:150px">
          <template #body="{ data }">{{ data.invoice?.numero ?? '—' }}</template>
        </Column>
        <Column header="Acciones" style="width:150px">
          <template #body="{ data }">
            <Button
              v-if="data.estado === 'disponible'"
              icon="pi pi-exclamation-triangle"
              size="small"
              text
              severity="warn"
              title="Marcar como dañada"
              @click="cambiarEstado(data, 'danado')"
            />
            <Button
              v-if="data.estado !== 'disponible' && data.estado !== 'vendida'"
              icon="pi pi-undo"
              size="small"
              text
              title="Volver a disponible"
              @click="cambiarEstado(data, 'disponible')"
            />
            <Button
              icon="pi pi-trash"
              size="small"
              text
              severity="danger"
              :disabled="data.estado === 'vendida'"
              :title="data.estado === 'vendida' ? 'Una serie vendida no se elimina' : 'Eliminar'"
              @click="eliminar(data)"
            />
          </template>
        </Column>
      </DataTable>

      <div class="kvs-panel-foot">
        Mostrando {{ filtradas.length }} de {{ series.length }} · {{ disponibles }} disponible(s)
      </div>
    </section>

    <!-- ══ Registrar / Consultar ══ -->
    <section class="kvs-panel" style="width:400px; flex-shrink:0;">
      <div class="kvs-panel-title">Registrar series</div>

      <div style="padding:12px;">
        <div style="font-size:12px; color:#64748b; margin-bottom:8px;">
          Una serie por línea. Puedes escanearlas seguidas o pegarlas desde Excel.
        </div>
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

      <div class="kvs-panel-title" style="margin-top:8px;">Consulta de garantía</div>
      <div style="padding:12px;">
        <div style="font-size:12px; color:#64748b; margin-bottom:8px;">
          Escanea o escribe una serie: te dice a qué proveedor se la compró y a qué cliente se la vendió.
        </div>
        <div style="display:flex; gap:6px;">
          <InputText
            v-model="busqueda"
            placeholder="Serie / IMEI"
            size="small"
            style="flex:1"
            @keyup.enter="rastrear"
          />
          <Button icon="pi pi-search" size="small" @click="rastrear" />
        </div>

        <Message v-if="errorTrace" severity="error" :closable="false" style="margin-top:10px;">
          {{ errorTrace }}
        </Message>

        <div v-if="trazado" style="margin-top:12px; font-size:13px;">
          <div style="font-family:monospace; color:#64748b;">{{ trazado.serie }}</div>
          <div style="margin:6px 0;">
            <Tag :value="trazado.estado" :severity="color[trazado.estado] ?? 'secondary'" />
          </div>
          <div><b>Artículo:</b> {{ trazado.product?.codigo }} — {{ trazado.product?.descripcion }}</div>
          <div style="margin-top:6px;">
            <b>Comprada a:</b> {{ trazado.purchase?.contact?.razon_social ?? '—' }}
            <span v-if="trazado.purchase?.numero" style="color:#64748b;"> · {{ trazado.purchase.numero }}</span>
          </div>
          <div>
            <b>Vendida a:</b> {{ trazado.invoice?.contact?.razon_social ?? 'aún no se vende' }}
            <span v-if="trazado.invoice?.numero" style="color:#64748b;"> · {{ trazado.invoice.numero }}</span>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
