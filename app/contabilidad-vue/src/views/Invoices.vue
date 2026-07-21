<script setup lang="ts">
/**
 * Invoices.vue — Listado de Facturas estilo KBS.
 * Grilla densa con filtros + toolbar + preview RIDE.
 */
import { computed, onMounted, ref } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const preview = ref<any>(null)
const docRef = ref<HTMLElement>()

// Filtros
const filtro = ref({ anio: '', numero: '', cliente: '', identificacion: '' })

const estadoSev: Record<string, string> = {
  generado: 'warn', firmado: 'info', enviado: 'info', AUTORIZADO: 'success', autorizado: 'success',
}
const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const empresa = computed(() => company.companies.find((c: any) => c.id === company.activeId))

const anios = computed(() => {
  const set = new Set(rows.value.map((r: any) => String(r.fecha_emision).slice(0, 4)))
  return [...set].sort().reverse().map((a) => ({ label: a, value: a }))
})

const filtrados = computed(() => rows.value.filter((r: any) => {
  const a = filtro.value.anio
  const n = filtro.value.numero.toLowerCase()
  const c = filtro.value.cliente.toLowerCase()
  const id = filtro.value.identificacion.toLowerCase()
  return (!a || String(r.fecha_emision).startsWith(a))
    && (!n || (r.numero ?? '').toLowerCase().includes(n))
    && (!c || (r.contact?.razon_social ?? '').toLowerCase().includes(c))
    && (!id || (r.contact?.identificacion ?? '').toLowerCase().includes(id))
}))

async function load() {
  loading.value = true
  rows.value = (await api.get('/invoices?company_id=' + company.activeId)).data
  loading.value = false
}

function imprimir() {
  if (!docRef.value) return
  const w = window.open('', '_blank', 'width=820,height=900')
  if (!w) return
  w.document.write('<html><head><title>Factura ' + preview.value.numero + '</title></head><body style="margin:0; font-family: Arial, Helvetica, sans-serif;">'
    + docRef.value.innerHTML + '</body></html>')
  w.document.close()
  w.focus()
  w.print()
}

function ver(r: any) { preview.value = r }
function nuevo() { /* navegar a POS */ }

onMounted(load)
</script>

<template>
  <div class="invoices-layout">
    <!-- ══ Cabecera de filtros ══ -->
    <div class="invoices-toolbar">
      <div class="invoices-filters">
        <span class="invoices-title">Facturas de Venta</span>
        <Select v-model="filtro.anio" :options="anios" optionLabel="label" optionValue="value"
                placeholder="Año" size="small" showClear style="width:90px" />
        <InputText v-model="filtro.numero" placeholder="Número" size="small" style="width:100px" />
        <InputText v-model="filtro.cliente" placeholder="Cliente" size="small" style="width:160px" />
        <InputText v-model="filtro.identificacion" placeholder="CI/RUC" size="small" style="width:120px" />
        <Button label="Nuevo" icon="pi pi-plus" size="small" @click="nuevo" />
      </div>
    </div>

    <!-- ══ Grilla densa ══ -->
    <div class="invoices-grid-wrap">
      <table class="kvs-table invoices-table">
        <thead>
          <tr>
            <th style="width:85px">Fecha</th>
            <th style="width:70px">Secuencia</th>
            <th style="width:100px">Serie</th>
            <th style="width:110px">Número</th>
            <th>Cliente</th>
            <th style="width:110px">CI/RUC</th>
            <th style="width:90px" class="der">Total</th>
            <th style="width:80px">Pago</th>
            <th style="width:100px">Estado SRI</th>
            <th>Observación</th>
            <th style="width:70px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in filtrados" :key="r.id" class="invoices-row">
            <td>{{ String(r.fecha_emision).slice(0, 10) }}</td>
            <td>{{ r.numero }}</td>
            <td>{{ r.sri_document?.numero_autorizacion ? String(r.numero).replace(/\d{3}$/, '') : '—' }}</td>
            <td><b>{{ r.numero }}</b></td>
            <td>{{ r.contact?.razon_social }}</td>
            <td style="font-family:monospace; font-size:11.5px;">{{ r.contact?.identificacion }}</td>
            <td class="der"><b>{{ money(r.importe_total) }}</b></td>
            <td>{{ r.forma_pago }}</td>
            <td>
              <Tag :value="r.sri_document?.estado ?? '—'"
                   :severity="estadoSev[r.sri_document?.estado] ?? 'secondary'" size="small" />
            </td>
            <td style="font-size:11px; color:#64748b;">{{ r.sri_document?.numero_autorizacion ? 'Aut: ' + String(r.sri_document.numero_autorizacion).slice(0, 16) + '...' : '' }}</td>
            <td>
              <Button icon="pi pi-eye" size="small" text @click="ver(r)" title="Ver RIDE" />
            </td>
          </tr>
          <tr v-if="!filtrados.length">
            <td colspan="11" class="vacio">{{ loading ? 'Cargando...' : 'Sin facturas para este filtro' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ══ Pie ══ -->
    <div class="invoices-foot">
      Mostrando {{ filtrados.length }} de {{ rows.length }} facturas
    </div>

    <!-- ══ Preview RIDE ══ -->
    <Dialog :visible="!!preview" modal :header="'Factura ' + (preview?.numero ?? '')"
            style="width:760px" @update:visible="preview = null">
      <div v-if="preview" style="display:flex; justify-content:flex-end; margin-bottom:10px;">
        <Button label="Imprimir / PDF" icon="pi pi-print" size="small" outlined @click="imprimir" />
      </div>

      <div v-if="preview" ref="docRef">
        <div style="border:1.5px solid #444; border-radius:6px; padding:0; font-size:12px; color:#222;">
          <!-- Cabecera: emisor + datos del comprobante -->
          <div style="display:flex; border-bottom:1.5px solid #444;">
            <div style="flex:1; padding:14px; border-right:1.5px solid #444;">
              <div style="font-size:17px; font-weight:800;">{{ empresa?.razon_social }}</div>
              <div style="margin-top:6px;"><b>RUC:</b> {{ empresa?.ruc }}</div>
              <div><b>Matriz:</b> {{ empresa?.dir_matriz }}</div>
              <div><b>Obligado a llevar contabilidad:</b> SI</div>
            </div>
            <div style="flex:1; padding:14px;">
              <div style="font-size:15px; font-weight:800;">FACTURA</div>
              <div><b>No.:</b> {{ preview.numero }}</div>
              <div style="margin-top:6px;"><b>NÚMERO DE AUTORIZACIÓN:</b></div>
              <div style="font-family:monospace; font-size:10px; word-break:break-all;">
                {{ preview.sri_document?.numero_autorizacion ?? preview.sri_document?.clave_acceso ?? 'PENDIENTE DE AUTORIZACIÓN' }}
              </div>
              <div style="margin-top:6px;"><b>AMBIENTE:</b> {{ Number(empresa?.ambiente) === 2 ? 'PRODUCCIÓN' : 'PRUEBAS' }}</div>
              <div><b>EMISIÓN:</b> NORMAL</div>
              <div style="margin-top:6px;"><b>CLAVE DE ACCESO:</b></div>
              <div style="font-family:monospace; font-size:10px; word-break:break-all;">{{ preview.sri_document?.clave_acceso }}</div>
            </div>
          </div>

          <!-- Cliente -->
          <div style="padding:10px 14px; border-bottom:1.5px solid #444;">
            <div><b>Razón Social / Nombres:</b> {{ preview.contact?.razon_social }}</div>
            <div style="display:flex; gap:24px;">
              <span><b>RUC / CI:</b> {{ preview.contact?.identificacion }}</span>
              <span><b>Fecha Emisión:</b> {{ String(preview.fecha_emision).slice(0,10) }}</span>
            </div>
          </div>

          <!-- Detalle -->
          <table style="width:100%; border-collapse:collapse; font-size:11.5px;">
            <thead>
              <tr style="border-bottom:1px solid #444; background:#f2f2f2;">
                <th style="text-align:left; padding:6px 10px;">Código</th>
                <th style="text-align:left; padding:6px 10px;">Cant.</th>
                <th style="text-align:left; padding:6px 10px;">Descripción</th>
                <th style="text-align:right; padding:6px 10px;">Precio Unitario</th>
                <th style="text-align:right; padding:6px 10px;">Valor Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(it, i) in (preview.items ?? [])" :key="i" style="border-bottom:1px solid #ddd;">
                <td style="padding:5px 10px;">{{ it.codigo_principal }}</td>
                <td style="padding:5px 10px;">{{ it.cantidad }}</td>
                <td style="padding:5px 10px;">{{ it.descripcion }}
                  <span v-if="it.series?.length" style="color:#555;"> (Serie: {{ it.series.join(', ') }})</span></td>
                <td style="text-align:right; padding:5px 10px;">{{ money(it.precio_unitario) }}</td>
                <td style="text-align:right; padding:5px 10px;">{{ money(it.cantidad * it.precio_unitario) }}</td>
              </tr>
            </tbody>
          </table>

          <!-- Totales + forma de pago -->
          <div style="display:flex; border-top:1.5px solid #444;">
            <div style="flex:1; padding:10px 14px; border-right:1.5px solid #444;">
              <b>Forma de pago:</b> {{ preview.forma_pago?.toUpperCase() }}
              <div v-if="Number(preview.saldo_pendiente) > 0" style="margin-top:4px;">
                <b>Saldo pendiente:</b> {{ money(preview.saldo_pendiente) }}
              </div>
            </div>
            <div style="width:260px; padding:10px 14px;">
              <div style="display:flex; justify-content:space-between;">
                <span>SUBTOTAL 15%</span><span>{{ money(preview.total_sin_impuestos) }}</span></div>
              <div style="display:flex; justify-content:space-between;">
                <span>IVA 15%</span><span>{{ money(preview.total_impuesto) }}</span></div>
              <div style="display:flex; justify-content:space-between; font-weight:800; border-top:1px solid #444; margin-top:4px; padding-top:4px;">
                <span>VALOR TOTAL</span><span>{{ money(preview.importe_total) }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </Dialog>
  </div>
</template>

<style scoped>
.invoices-layout { display: flex; flex-direction: column; height: 100%; background: #eef1f5; }

.invoices-toolbar {
  background: linear-gradient(#3d8b8b, #2a6b6b); padding: 8px 12px; flex-shrink: 0;
}
.invoices-filters {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.invoices-title { color: #fff; font-weight: 600; font-size: 13px; margin-right: 12px; }

.invoices-grid-wrap { flex: 1; overflow: auto; padding: 0; }
.invoices-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.invoices-table th {
  text-align: left; background: #f2f4f7; border-bottom: 1px solid #b9c2cc;
  padding: 6px 8px; font-weight: 600; position: sticky; top: 0; z-index: 1;
}
.invoices-table td { padding: 5px 8px; border-bottom: 1px solid #eef1f5; }
.invoices-table .der { text-align: right; }
.invoices-table .vacio { color: #94a3b8; text-align: center; padding: 20px; }
.invoices-row { cursor: pointer; }
.invoices-row:hover { background: #eef6f6; }

.invoices-foot {
  font-size: 11.5px; color: #64748b; padding: 5px 12px;
  border-top: 1px solid #e2e5ea; background: #f7f9fb; flex-shrink: 0;
}
</style>
