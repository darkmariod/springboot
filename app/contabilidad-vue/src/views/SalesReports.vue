<script setup lang="ts">
/**
 * Reportes de ventas y compras: Comprobantes (facturas, notas de crédito y de débito), Ventas (por tarifa de IVA y estado SRI),
 * Ventas Detallada, Compras y Compras por sustento tributario.
 * Mismo patrón que InventoryReports.vue (selector de tipo + filtros + exportación),
 * pero para los documentos comerciales en vez del inventario.
 */
import { computed, onMounted, ref } from 'vue'
import RadioButton from 'primevue/radiobutton'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()

const loading = ref(false)
const msg = ref<{ type: string; text: string } | null>(null)
const showTable = ref(false)

const tipo = ref('comprobantes')
const tiposReporte = [
  { label: 'Comprobantes', value: 'comprobantes' },
  { label: 'Ventas', value: 'ventas' },
  { label: 'Ventas Detallada', value: 'ventas-detalle' },
  { label: 'Compras', value: 'compras' },
  { label: 'Compras por sustento tributario', value: 'compras-sustento' },
]

const titulos: Record<string, string> = {
  comprobantes: 'Reporte de Comprobantes',
  ventas: 'Reporte de Ventas',
  'ventas-detalle': 'Ventas Detallada',
  compras: 'Reporte de Compras',
  'compras-sustento': 'Compras por sustento tributario',
}

const filtro = ref({
  tipoComprobante: null as string | null,
  proveedorId: null as number | null,
  desde: '',
  hasta: '',
  // Ventas: por defecto cuenta toda factura vigente (con o sin certificado); marcado, solo las que el SRI autorizó
  soloAutorizadas: false,
})

const tiposComprobante = [
  { label: 'Todos', value: null },
  { label: 'Factura', value: 'factura' },
  { label: 'Nota de Crédito', value: 'nota_credito' },
  { label: 'Nota de Débito', value: 'nota_debito' },
]

const proveedores = ref<any[]>([])
const filas = ref<any[]>([])
const totales = ref<any>(null)
// Compras por sustento: una sección por sustento y otra por tipo de comprobante
const porSustento = ref<any[]>([])
const porTipo = ref<any[]>([])
// Ventas: resumen por tarifa de IVA (solo las tarifas que existen en el período)
const porTarifa = ref<any[]>([])
const esVentas = computed(() => tipo.value === 'ventas' || tipo.value === 'ventas-detalle')

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

function colorEstado(estado: string) {
  if (estado === 'autorizado' || estado === 'emitida') return '#16a34a'
  if (estado === 'anulado' || estado === 'no autorizado' || estado === 'devuelta') return '#dc2626'
  if (estado === 'sin sri') return '#64748b'
  return '#d97706' // generado, firmado, enviado, pendiente — en trámite con el SRI
}

async function cargarProveedores() {
  const all = (await api.get('/contacts?company_id=' + company.activeId)).data
  proveedores.value = all.filter((c: any) => c.es_proveedor)
}

function baseParams(): any {
  const p: any = { company_id: company.activeId }
  if (filtro.value.desde) p.desde = filtro.value.desde
  if (filtro.value.hasta) p.hasta = filtro.value.hasta
  if (tipo.value === 'comprobantes' && filtro.value.tipoComprobante) p.tipo = filtro.value.tipoComprobante
  if ((tipo.value === 'compras' || tipo.value === 'compras-sustento') && filtro.value.proveedorId) p.contact_id = filtro.value.proveedorId
  if (esVentas.value && filtro.value.soloAutorizadas) p.solo_autorizadas = 1
  return p
}

const endpoint = computed(() => '/reportes/' + tipo.value)

async function generar() {
  loading.value = true
  msg.value = null
  showTable.value = false
  try {
    const data = (await api.get(endpoint.value, { params: baseParams() })).data
    filas.value = data.items ?? []
    porSustento.value = data.por_sustento ?? []
    porTipo.value = data.por_tipo ?? []
    porTarifa.value = data.por_tarifa ?? []
    totales.value = data.totales ?? null
    showTable.value = true
  } catch (e: any) {
    msg.value = { type: 'error', text: e.response?.data?.message ?? 'Error al generar el reporte.' }
  } finally {
    loading.value = false
  }
}

async function descargar(formato: 'excel' | 'pdf') {
  try {
    const params = { ...baseParams(), formato }
    const res = await api.get(endpoint.value, { params, responseType: 'blob' })
    const mime = formato === 'pdf' ? 'application/pdf' : 'text/csv'
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: mime }))
    a.download = tipo.value + (formato === 'pdf' ? '.pdf' : '.csv')
    a.click()
  } catch {
    msg.value = { type: 'error', text: 'No se pudo generar el archivo.' }
  }
}

function resetear() {
  filtro.value = { tipoComprobante: null, proveedorId: null, desde: '', hasta: '', soloAutorizadas: false }
  showTable.value = false
  filas.value = []
  porSustento.value = []
  porTipo.value = []
  porTarifa.value = []
  totales.value = null
  msg.value = null
}

onMounted(cargarProveedores)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Reportes de Ventas y Compras" :company="{ razon_social: 'Documentos comerciales' }" subtitle="Parámetros y exportación" />
    <div style="display: flex; flex: 1; min-height: 0;">
      <!-- Panel de parámetros -->
      <aside class="no-print" style="width: 300px; border-right: 1px solid #e2e5ea; background: #fff; padding: 16px; overflow: auto; flex-shrink: 0;">
        <div style="font-weight: 700; font-size: 13px; color: #4a3220; margin-bottom: 12px;">Parámetros del Reporte</div>

        <div style="margin-bottom: 12px;">
          <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 6px;">Tipo de reporte:</div>
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <label v-for="t in tiposReporte" :key="t.value" style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
              <RadioButton v-model="tipo" :value="t.value" :input-id="'sr-' + t.value" @change="showTable = false" />
              <span>{{ t.label }}</span>
            </label>
          </div>
        </div>

        <fieldset class="kvs-fieldset">
          <legend>Filtros</legend>
          <div class="kvs-row">
            <label class="kvs-lbl">Desde:</label>
            <InputText v-model="filtro.desde" type="date" class="kvs-in" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Hasta:</label>
            <InputText v-model="filtro.hasta" type="date" class="kvs-in" />
          </div>
          <div v-if="tipo === 'comprobantes'" class="kvs-row">
            <label class="kvs-lbl">Tipo:</label>
            <Select v-model="filtro.tipoComprobante" :options="tiposComprobante" optionLabel="label" optionValue="value" class="kvs-in" />
          </div>
          <label v-if="esVentas" style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; cursor: pointer; margin-top: 6px;">
            <Checkbox v-model="filtro.soloAutorizadas" binary input-id="sr-solo-aut" />
            <span>Solo autorizadas por el SRI</span>
          </label>
          <div v-if="tipo === 'compras' || tipo === 'compras-sustento'" class="kvs-row">
            <label class="kvs-lbl">Proveedor:</label>
            <Select v-model="filtro.proveedorId" :options="[{ id: null, razon_social: 'Todos' }, ...proveedores]"
                    optionLabel="razon_social" optionValue="id" class="kvs-in" />
          </div>
        </fieldset>

        <div style="display: flex; gap: 8px; margin-top: 14px;">
          <Button label="Filtrar" icon="pi pi-cog" size="small" :loading="loading" @click="generar" />
          <Button label="Resetear" icon="pi pi-eraser" size="small" text @click="resetear" />
        </div>
      </aside>

      <!-- Panel de resultados -->
      <div style="flex: 1; overflow: auto; background: #eef1f5; padding: 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
          <h3 style="margin: 0;">{{ titulos[tipo] }}</h3>
          <div v-if="showTable" style="display: flex; gap: 8px;">
            <Button label="Descargar Excel" icon="pi pi-file-excel" size="small" outlined @click="descargar('excel')" />
            <Button label="Descargar PDF" icon="pi pi-print" size="small" outlined @click="descargar('pdf')" />
          </div>
        </div>

        <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom: 12px;">{{ msg.text }}</Message>

        <div v-if="!showTable && !msg" style="color: #94a3b8; text-align: center; padding: 80px 20px;">
          Elige el tipo de reporte, ajusta las fechas si quieres, y presiona <b>Filtrar</b>.
        </div>

        <!-- Comprobantes -->
        <DataTable v-if="showTable && tipo === 'comprobantes'" :value="filas" size="small" stripedRows :paginator="true" :rows="20">
          <Column field="tipo" header="Tipo" style="width: 120px;" />
          <Column field="numero" header="No." style="width: 150px;" />
          <Column field="cliente" header="Cliente" />
          <Column field="identificacion" header="Identificación" style="width: 130px;" />
          <Column field="fecha_emision" header="Fecha Emisión" style="width: 110px;" />
          <Column field="factura_afectada" header="Factura afectada" style="width: 150px;" />
          <Column field="origen" header="SRI/Interna" style="width: 90px;" />
          <Column header="Estado" style="width: 110px;">
            <template #body="{ data }">
              <span :style="{ color: colorEstado(data.estado), fontWeight: 600, textTransform: 'capitalize' }">
                {{ data.estado }}
              </span>
            </template>
          </Column>
          <Column header="Total" style="width: 110px;">
            <template #body="{ data }">{{ money(data.total) }}</template>
          </Column>
          <template #footer>Total: {{ filas.length }} comprobante(s)</template>
        </DataTable>

        <!-- Ventas -->
        <template v-if="showTable && tipo === 'ventas'">
          <Message severity="info" :closable="false" style="margin-bottom: 10px;">
            {{ filtro.soloAutorizadas
              ? 'Solo facturas autorizadas por el SRI.'
              : 'Incluye toda factura vigente: generada, firmada, enviada o autorizada. No incluye las anuladas ni las que el SRI rechazó, ni las notas de crédito o de débito.' }}
          </Message>

          <h4 style="margin: 0 0 6px;">Por tarifa de IVA</h4>
          <DataTable :value="porTarifa" size="small" stripedRows style="margin-bottom: 16px;">
            <Column field="etiqueta" header="Tarifa" />
            <Column header="Facturas" style="width: 100px;"><template #body="{ data }">{{ data.facturas }}</template></Column>
            <Column header="Base" style="width: 120px;"><template #body="{ data }">{{ money(data.base) }}</template></Column>
            <Column header="IVA" style="width: 120px;"><template #body="{ data }">{{ money(data.iva) }}</template></Column>
            <template #empty>No hay ventas en ese rango de fechas.</template>
          </DataTable>

          <DataTable :value="filas" size="small" stripedRows :paginator="true" :rows="20" scrollable>
            <Column field="numero" header="No." style="width: 150px;" />
            <Column field="cliente" header="Cliente" />
            <Column field="identificacion" header="Identificación" style="width: 130px;" />
            <Column field="fecha_emision" header="Fecha Emisión" style="width: 110px;" />
            <Column header="Estado SRI" style="width: 110px;">
              <template #body="{ data }">
                <span :style="{ color: colorEstado(data.estado_sri), fontWeight: 600, textTransform: 'capitalize' }">{{ data.estado_sri }}</span>
              </template>
            </Column>
            <Column header="Subtotal 15%" style="width: 110px;"><template #body="{ data }">{{ money(data.subtotal_15) }}</template></Column>
            <Column v-if="totales?.subtotal_otras" header="Otras tarifas" style="width: 110px;"><template #body="{ data }">{{ money(data.subtotal_otras) }}</template></Column>
            <Column header="Subtotal 0%" style="width: 110px;"><template #body="{ data }">{{ money(data.subtotal_0) }}</template></Column>
            <Column v-if="totales?.no_objeto" header="No objeto IVA" style="width: 110px;"><template #body="{ data }">{{ money(data.no_objeto) }}</template></Column>
            <Column v-if="totales?.exento" header="Exento" style="width: 100px;"><template #body="{ data }">{{ money(data.exento) }}</template></Column>
            <Column header="IVA" style="width: 100px;"><template #body="{ data }">{{ money(data.iva) }}</template></Column>
            <Column header="Total" style="width: 110px;"><template #body="{ data }">{{ money(data.total) }}</template></Column>
            <template #footer>
              <div v-if="totales" style="display: flex; justify-content: flex-end; gap: 20px; font-weight: 600; flex-wrap: wrap;">
                <span>Subtotal 15%: {{ money(totales.subtotal_15) }}</span>
                <span v-if="totales.subtotal_otras">Otras tarifas: {{ money(totales.subtotal_otras) }}</span>
                <span>Subtotal 0%: {{ money(totales.subtotal_0) }}</span>
                <span v-if="totales.no_objeto">No objeto IVA: {{ money(totales.no_objeto) }}</span>
                <span v-if="totales.exento">Exento: {{ money(totales.exento) }}</span>
                <span>IVA: {{ money(totales.iva) }}</span>
                <span>Total: {{ money(totales.total) }}</span>
              </div>
            </template>
          </DataTable>
        </template>

        <!-- Ventas Detallada -->
        <template v-if="showTable && tipo === 'ventas-detalle'">
          <Message severity="info" :closable="false" style="margin-bottom: 10px;">
            {{ filtro.soloAutorizadas
              ? 'Solo facturas autorizadas por el SRI.'
              : 'Incluye toda factura vigente: generada, firmada, enviada o autorizada. No incluye las anuladas ni las que el SRI rechazó.' }}
          </Message>
          <DataTable :value="filas" size="small" stripedRows :paginator="true" :rows="20">
            <Column field="numero" header="No. Factura" style="width: 150px;" />
            <Column field="cliente" header="Cliente" />
            <Column header="Estado SRI" style="width: 110px;">
              <template #body="{ data }">
                <span :style="{ color: colorEstado(data.estado_sri), fontWeight: 600, textTransform: 'capitalize' }">{{ data.estado_sri }}</span>
              </template>
            </Column>
            <Column field="producto" header="Producto" />
            <Column header="Cant." style="width: 80px;"><template #body="{ data }">{{ Number(data.cantidad).toFixed(2) }}</template></Column>
            <Column header="P. Unit." style="width: 100px;"><template #body="{ data }">{{ money(data.precio_unitario) }}</template></Column>
            <Column header="Descuento" style="width: 100px;"><template #body="{ data }">{{ money(data.descuento) }}</template></Column>
            <Column field="categoria" header="IVA" style="width: 130px;" />
            <Column header="Subtotal" style="width: 100px;"><template #body="{ data }">{{ money(data.subtotal) }}</template></Column>
            <template #footer>Total: {{ filas.length }} línea(s)</template>
          </DataTable>
        </template>

        <!-- Compras -->
        <DataTable v-if="showTable && tipo === 'compras'" :value="filas" size="small" stripedRows :paginator="true" :rows="20">
          <Column field="numero" header="No." style="width: 130px;" />
          <Column field="proveedor" header="Proveedor" />
          <Column field="ruc" header="RUC Proveedor" style="width: 120px;" />
          <Column field="fecha_emision" header="Fecha Emisión" style="width: 110px;" />
          <Column header="Subtotal 15%" style="width: 100px;"><template #body="{ data }">{{ money(data.subtotal_15) }}</template></Column>
          <Column header="Subtotal 0%" style="width: 100px;"><template #body="{ data }">{{ money(data.subtotal_0) }}</template></Column>
          <Column header="IVA" style="width: 90px;"><template #body="{ data }">{{ money(data.iva) }}</template></Column>
          <Column header="Total" style="width: 100px;"><template #body="{ data }">{{ money(data.total) }}</template></Column>
          <Column field="factura_fisica" header="Factura Física" style="width: 100px;" />
          <template #footer>
            <div v-if="totales" style="display: flex; justify-content: flex-end; gap: 20px; font-weight: 600;">
              <span>Subtotal 15%: {{ money(totales.subtotal_15) }}</span>
              <span>Subtotal 0%: {{ money(totales.subtotal_0) }}</span>
              <span>IVA: {{ money(totales.iva) }}</span>
              <span>Total: {{ money(totales.total) }}</span>
            </div>
          </template>
        </DataTable>
        <!-- Compras por sustento tributario -->
        <template v-if="showTable && tipo === 'compras-sustento'">
          <h4 style="margin: 0 0 6px;">Por sustento tributario</h4>
          <DataTable :value="porSustento" size="small" stripedRows>
            <Column field="codigo" header="Código" style="width: 80px;"><template #body="{ data }">{{ data.codigo || '—' }}</template></Column>
            <Column field="nombre" header="Sustento" />
            <Column header="Comprobantes" style="width: 110px;"><template #body="{ data }">{{ data.comprobantes }}</template></Column>
            <Column header="Subtotal 15%" style="width: 110px;"><template #body="{ data }">{{ money(data.subtotal_15) }}</template></Column>
            <Column header="Subtotal 0%" style="width: 110px;"><template #body="{ data }">{{ money(data.subtotal_0) }}</template></Column>
            <Column header="IVA" style="width: 100px;"><template #body="{ data }">{{ money(data.iva) }}</template></Column>
            <Column header="Total" style="width: 110px;"><template #body="{ data }">{{ money(data.total) }}</template></Column>
            <template #empty>No hay compras en ese rango de fechas.</template>
          </DataTable>

          <h4 style="margin: 18px 0 6px;">Por tipo de comprobante</h4>
          <DataTable :value="porTipo" size="small" stripedRows>
            <Column field="codigo" header="Código" style="width: 80px;" />
            <Column field="nombre" header="Tipo de comprobante" />
            <Column header="Comprobantes" style="width: 110px;"><template #body="{ data }">{{ data.comprobantes }}</template></Column>
            <Column header="Subtotal 15%" style="width: 110px;"><template #body="{ data }">{{ money(data.subtotal_15) }}</template></Column>
            <Column header="Subtotal 0%" style="width: 110px;"><template #body="{ data }">{{ money(data.subtotal_0) }}</template></Column>
            <Column header="IVA" style="width: 100px;"><template #body="{ data }">{{ money(data.iva) }}</template></Column>
            <Column header="Total" style="width: 110px;"><template #body="{ data }">{{ money(data.total) }}</template></Column>
            <template #empty>No hay compras en ese rango de fechas.</template>
          </DataTable>

          <div v-if="totales" style="display: flex; justify-content: flex-end; gap: 20px; font-weight: 600; margin-top: 14px; padding: 10px 12px; background: #fff; border: 1px solid #e2e5ea;">
            <span>Total general:</span>
            <span>{{ totales.comprobantes }} comprobante(s)</span>
            <span>Subtotal 15%: {{ money(totales.subtotal_15) }}</span>
            <span>Subtotal 0%: {{ money(totales.subtotal_0) }}</span>
            <span>IVA: {{ money(totales.iva) }}</span>
            <span>Total: {{ money(totales.total) }}</span>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
