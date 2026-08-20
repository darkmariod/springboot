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
import { useTabsStore } from '../stores/tabs'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()
const tabs = useTabsStore()
const rows = ref<any[]>([])
const loading = ref(true)
const preview = ref<any>(null)
const seleccion = ref<any>(null)
const docRef = ref<HTMLElement>()
const anularTarget = ref<any>(null)
const anularBusy = ref(false)

// Filtros
const filtro = ref({ anio: '', numero: '', cliente: '', identificacion: '', valor: '' })

const estadoSev: Record<string, string> = {
  generado: 'warn', firmado: 'info', enviado: 'info', AUTORIZADO: 'success', autorizado: 'success',
}
const facturaSev: Record<string, string> = { emitida: 'success', anulada: 'danger', pendiente: 'warn' }
const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const empresa = computed(() => company.companies.find((c: any) => c.id === company.activeId))

const secuencia = (r: any) => String(r.numero ?? '').slice(-9)
const serie = (r: any) => String(r.numero ?? '').slice(0, 7)
const fechaCorta = (d: any) => d ? String(d).replace('T', ' ').slice(0, 16) : '—'

const anios = computed(() => {
  const set = new Set(rows.value.map((r: any) => String(r.fecha_emision).slice(0, 4)))
  return [...set].sort().reverse().map((a) => ({ label: a, value: a }))
})

const filtrados = computed(() => rows.value.filter((r: any) => {
  const a = filtro.value.anio
  const n = filtro.value.numero.toLowerCase()
  const c = filtro.value.cliente.toLowerCase()
  const id = filtro.value.identificacion.toLowerCase()
  const v = filtro.value.valor
  return (!a || String(r.fecha_emision).startsWith(a))
    && (!n || (r.numero ?? '').toLowerCase().includes(n))
    && (!c || (r.contact?.razon_social ?? '').toLowerCase().includes(c))
    && (!id || (r.contact?.identificacion ?? '').toLowerCase().includes(id))
    && (!v || Number(r.importe_total) >= Number(v))
}))

async function load() {
  loading.value = true
  rows.value = (await api.get('/invoices?company_id=' + company.activeId)).data
  loading.value = false
}

function imprimir() {
  if (!docRef.value) return
  const w = window.open('', '_blank', 'width=880,height=1000')
  if (!w) return

  // La ventana nueva arranca sin estilos: hay que llevarle las hojas de estilo
  // de la app, o el RIDE sale como texto plano sin bordes ni columnas.
  const estilos = Array.from(
    document.querySelectorAll('link[rel="stylesheet"], style'),
  ).map((n) => n.outerHTML).join('\n')

  // outerHTML (no innerHTML) para conservar la clase .ride y el atributo de
  // scope que usan las reglas del componente.
  w.document.write(
    '<!doctype html><html><head><meta charset="utf-8">' +
    '<title>Factura ' + preview.value.numero + '</title>' + estilos +
    '<style>@page{size:A4;margin:10mm} html,body{margin:0;padding:0;background:#fff}' +
    '.ride{width:100%}</style></head><body>' +
    docRef.value.outerHTML +
    '</body></html>',
  )
  w.document.close()

  // Esperar a que carguen las hojas de estilo antes de abrir el diálogo de impresión.
  const lanzar = () => { w.focus(); w.print() }
  if (w.document.readyState === 'complete') setTimeout(lanzar, 350)
  else w.onload = () => setTimeout(lanzar, 350)
}

// Fecha y hora de autorización, en el formato del RIDE (dd/mm/aaaa hh:mm:ss).
function fechaHora(v: any) {
  if (!v) return '—'
  const d = new Date(v)
  if (isNaN(d.getTime())) return String(v)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

function ver(r: any) { seleccion.value = r; preview.value = r }
function editar(r: any) { seleccion.value = r; ver(r) }
function nuevo() {
  tabs.open({ key: 'pos', label: 'Ventas (POS)', icon: 'pi pi-shopping-cart', component: 'Pos' })
}
function salir() { tabs.close(tabs.activeKey ?? 'invoices') }

function pedirAnular(r: any) { anularTarget.value = r }
async function confirmarAnular() {
  if (!anularTarget.value || anularBusy.value) return
  anularBusy.value = true
  try {
    await api.post(`/invoices/${anularTarget.value.id}/anular`)
    anularTarget.value = null
    await load()
  } catch (e: any) {
    alert(e?.response?.data?.message ?? 'No se pudo anular la factura.')
  } finally {
    anularBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="invoices-layout">
    <KvsModuleHeader module-name="Facturas de Venta" :company="{ ruc: company.activeId, razon_social: 'Listado' }" subtitle="Electrónicas SRI" />
    <!-- ══ Cabecera de filtros ══ -->
    <div class="invoices-toolbar">
      <div class="invoices-filters">
        <span class="invoices-title">Facturas de Venta</span>
        <Select v-model="filtro.anio" :options="anios" optionLabel="label" optionValue="value"
                placeholder="Año" size="small" showClear style="width:90px" />
        <InputText v-model="filtro.numero" placeholder="Número" size="small" style="width:100px" />
        <InputText v-model="filtro.cliente" placeholder="Cliente" size="small" style="width:160px" />
        <InputText v-model="filtro.identificacion" placeholder="CI/RUC" size="small" style="width:120px" />
        <InputText v-model="filtro.valor" placeholder="Valor" size="small" style="width:90px" />
        <div style="flex:1"></div>
        <Button label="Nuevo" icon="pi pi-plus" size="small" @click="nuevo" />
        <Button label="Editar" icon="pi pi-pencil" size="small" outlined
                :disabled="!seleccion" @click="seleccion && editar(seleccion)" />
        <Button label="Anular" icon="pi pi-ban" size="small" outlined severity="danger"
                :disabled="!seleccion || seleccion?.estado === 'anulado'" @click="seleccion && pedirAnular(seleccion)" />
        <Button label="Salir" icon="pi pi-times" size="small" text @click="salir" />
      </div>
    </div>

    <!-- ══ Grilla densa ══ -->
    <div class="invoices-grid-wrap">
      <table class="kvs-table invoices-table">
        <thead>
          <tr>
            <th style="width:88px">Fecha</th>
            <th style="width:76px">Secuencia</th>
            <th style="width:74px">Serie</th>
            <th style="width:112px">Número</th>
            <th style="width:80px">Precio</th>
            <th class="col-cliente">Cliente</th>
            <th style="width:110px">CI/RUC</th>
            <th style="width:92px" class="der">Total</th>
            <th style="width:86px">Estado</th>
            <th style="width:112px">Estado SRI</th>
            <th style="width:82px">Vendedor</th>
            <th style="width:90px">Referencia</th>
            <th style="width:80px">Pago</th>
            <th style="width:60px">Asiento</th>
            <th style="width:104px">Creado</th>
            <th style="width:104px">Editado</th>
            <th style="width:52px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in filtrados" :key="r.id" class="invoices-row"
              :class="{ 'invoices-row--sel': seleccion?.id === r.id, 'invoices-row--anulada': r.estado === 'anulado' }"
              @click="seleccion = r" :dblclick="() => ver(r)">
            <td>{{ String(r.fecha_emision).slice(0, 10) }}</td>
            <td>{{ secuencia(r) }}</td>
            <td>{{ serie(r) }}</td>
            <td><b>{{ r.numero }}</b></td>
            <td style="font-size:11px;">{{ r.tipo_precio ?? (r.items?.[0]?.tipo_precio ?? '—') }}</td>
            <td class="col-cliente">{{ r.contact?.razon_social }}</td>
            <td style="font-family:monospace; font-size:11.5px;">{{ r.contact?.identificacion }}</td>
            <td class="der"><b>{{ money(r.importe_total) }}</b></td>
            <td>
              <Tag :value="r.estado ?? '—'" :severity="facturaSev[r.estado] ?? 'secondary'" size="small" />
            </td>
            <td>
              <Tag :value="r.sri_document?.estado ?? '—'"
                   :severity="estadoSev[r.sri_document?.estado] ?? 'secondary'" size="small" />
            </td>
            <td style="font-size:11px; color:#64748b;">{{ r.vendedor ?? '—' }}</td>
            <td style="font-size:11px; color:#64748b;">{{ r.referencia ?? '—' }}</td>
            <td style="font-size:11px;">{{ r.forma_pago }}</td>
            <td style="font-size:11px; color:#64748b;">{{ r.journal_entries_count ? 'Sí' : '—' }}</td>
            <td style="font-size:11px; color:#64748b;">{{ fechaCorta(r.created_at) }}</td>
            <td style="font-size:11px; color:#64748b;">{{ fechaCorta(r.updated_at) }}</td>
            <td>
              <Button icon="pi pi-eye" size="small" text @click.stop="ver(r)" title="Ver RIDE" />
            </td>
          </tr>
          <tr v-if="!filtrados.length">
            <td colspan="17" class="vacio">{{ loading ? 'Cargando...' : 'Sin facturas para este filtro' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ══ Pie ══ -->
    <div class="invoices-foot">
      Mostrando {{ filtrados.length }} de {{ rows.length }} facturas
    </div>

    <!-- ══ Confirmar anulación ══ -->
    <Dialog :visible="!!anularTarget" modal header="Anular factura" style="width:420px"
            @update:visible="anularTarget = null">
      <div v-if="anularTarget">
        <p style="margin:0 0 10px; font-size:13px;">
          ¿Anular la factura <b>{{ anularTarget.numero }}</b> por <b>{{ money(anularTarget.importe_total) }}</b>?
        </p>
        <p style="margin:0; font-size:12px; color:#64748b;">
          El documento fiscal no se borra: quedará con estado <b>anulado</b>, se revertirá el asiento contable y se devolverá el stock y las series.
        </p>
      </div>
      <template #footer>
        <div class="kvs-footer">
          <Button label="Cancelar" text @click="anularTarget = null" />
          <Button label="Anular factura" icon="pi pi-ban" severity="danger"
                  :loading="anularBusy" @click="confirmarAnular" />
        </div>
      </template>
    </Dialog>

    <!-- ══ Preview RIDE ══ -->
    <Dialog :visible="!!preview" modal :header="'Factura ' + (preview?.numero ?? '')"
            style="width:760px" @update:visible="preview = null">
      <div v-if="preview" style="display:flex; justify-content:flex-end; margin-bottom:10px;">
        <Button label="Imprimir / PDF" icon="pi pi-print" size="small" outlined @click="imprimir" />
      </div>

      <div v-if="preview" ref="docRef" class="ride">
        <!-- ══ Cabecera: emisor (con logo del cliente) + datos del comprobante ══ -->
        <div class="ride-top">
          <div class="ride-emisor">
            <img v-if="empresa?.logo" :src="empresa.logo" class="ride-logo" alt="" />
            <div class="ride-rs">{{ empresa?.razon_social }}</div>
            <div v-if="empresa?.nombre_comercial" class="ride-nc">{{ empresa.nombre_comercial }}</div>
            <div class="ride-l"><b>Matriz:</b> {{ empresa?.dir_matriz }}</div>
            <div v-if="empresa?.telefonos" class="ride-l"><b>Teléfonos:</b> {{ empresa.telefonos }}</div>
            <div v-if="empresa?.email_envio" class="ride-l"><b>E-Mail:</b> {{ empresa.email_envio }}</div>
            <div v-if="empresa?.agente_retencion" class="ride-l">
              <b>AGENTE DE RETENCIÓN RESOLUCIÓN NRO.</b> {{ empresa.agente_retencion }}
            </div>
            <div v-if="empresa?.contribuyente_especial" class="ride-l">
              <b>CONTRIBUYENTE ESPECIAL NRO.</b> {{ empresa.contribuyente_especial }}
            </div>
            <div class="ride-l"><b>OBLIGADO A LLEVAR CONTABILIDAD -</b>
              {{ empresa?.obligado_contabilidad ? 'SI' : 'NO' }}</div>
            <div v-if="empresa?.sitio_web" class="ride-l">{{ empresa.sitio_web }}</div>
          </div>

          <div class="ride-doc">
            <div class="ride-ruc">R.U.C.: {{ empresa?.ruc }}</div>
            <div class="ride-tipo">FACTURA</div>
            <div class="ride-num">No. {{ preview.numero }}</div>

            <div class="ride-f"><span>NÚMERO DE AUTORIZACIÓN</span>
              <em class="mono">{{ preview.sri_document?.numero_autorizacion ?? 'PENDIENTE DE AUTORIZACIÓN' }}</em></div>
            <div class="ride-2c">
              <div class="ride-f"><span>AMBIENTE DE AUTORIZACIÓN</span>
                <em>{{ Number(empresa?.ambiente) === 2 ? 'PRODUCCIÓN' : 'PRUEBAS' }}</em></div>
              <div class="ride-f"><span>TIPO DE EMISIÓN</span><em>NORMAL</em></div>
            </div>
            <div class="ride-f"><span>FECHA Y HORA DE AUTORIZACIÓN</span>
              <em>{{ fechaHora(preview.sri_document?.updated_at ?? preview.fecha_emision) }}</em></div>
            <div class="ride-f"><span>CLAVE DE ACCESO</span>
              <em class="mono">{{ preview.sri_document?.clave_acceso ?? '—' }}</em></div>
            <div class="ride-f"><span>GUÍA DE REMISIÓN</span><em>NO ENVIADA</em></div>
          </div>
        </div>

        <!-- ══ Datos del cliente ══ -->
        <div class="ride-cli">
          <div class="ride-f"><span>RUC / CI</span><em>{{ preview.contact?.identificacion }}</em></div>
          <div class="ride-f ride-w2"><span>NOMBRE O RAZÓN SOCIAL DEL CLIENTE</span>
            <em>{{ preview.contact?.razon_social }}</em></div>
          <div class="ride-f"><span>FECHA DE EMISIÓN</span>
            <em>{{ String(preview.fecha_emision).slice(0,10).split('-').reverse().join('/') }}</em></div>
          <div class="ride-f ride-w2"><span>DIRECCIÓN</span><em>{{ preview.contact?.direccion ?? '—' }}</em></div>
          <div class="ride-f"><span>TELÉFONO</span><em>{{ preview.contact?.telefono ?? '—' }}</em></div>
          <div class="ride-f"><span>CORREO ELECTRÓNICO</span><em>{{ preview.contact?.email ?? '—' }}</em></div>
        </div>

        <!-- ══ Detalle ══ -->
        <table class="ride-tbl">
          <thead>
            <tr><th>CÓDIGO</th><th class="c">CANT</th><th>DESCRIPCIÓN</th>
                <th class="c">UNI</th><th class="d">P.UNITARIO</th><th class="d">P.TOTAL</th></tr>
          </thead>
          <tbody>
            <tr v-for="(it, i) in (preview.items ?? [])" :key="i">
              <td class="mono">{{ it.codigo_principal }}</td>
              <td class="c">{{ Number(it.cantidad).toFixed(2) }}</td>
              <td>{{ it.descripcion }}</td>
              <td class="c">U</td>
              <td class="d">{{ Number(it.precio_unitario).toFixed(2) }}</td>
              <td class="d">{{ (Number(it.cantidad) * Number(it.precio_unitario)).toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>

        <!-- ══ Pie: pagos + totales ══ -->
        <div class="ride-pie">
          <div class="ride-pagos">
            <div class="ride-l"><b>FORMA DE PAGO:</b> {{ (preview.forma_pago ?? '').toUpperCase() }}</div>
            <div class="ride-l"><b>CONDICIONES DE PAGO:</b>
              {{ preview.forma_pago === 'credito' ? 'CRÉDITO' : 'CONTADO' }}</div>
            <div class="ride-l"><b>DETALLE DE PAGO:</b></div>
            <div class="ride-l"><b>BODEGA:</b> {{ preview.bodega ?? 'BODEGA PRINCIPAL' }}
              &nbsp;&nbsp; <b>VENDEDOR:</b> {{ preview.vendedor ?? '—' }}</div>
            <div class="ride-adic"><b>INFORMACIÓN ADICIONAL</b></div>
          </div>

          <table class="ride-tot">
            <tr><td>SUBTOTAL 15%</td><td class="d mono">{{ Number(preview.total_sin_impuestos ?? 0).toFixed(2) }}</td></tr>
            <tr><td>SUBTOTAL 0%</td><td class="d mono">0.00</td></tr>
            <tr><td>SUBTOTAL</td><td class="d mono">{{ Number(preview.total_sin_impuestos ?? 0).toFixed(2) }}</td></tr>
            <tr><td>DESCUENTO</td><td class="d mono">0.00</td></tr>
            <tr><td>IVA 15%</td><td class="d mono">{{ Number(preview.total_impuesto ?? 0).toFixed(2) }}</td></tr>
            <tr class="ride-total"><td>VALOR TOTAL</td>
              <td class="d mono">{{ Number(preview.importe_total ?? 0).toFixed(2) }}</td></tr>
          </table>
        </div>

        <div v-if="empresa?.nota_pie" class="ride-nota">{{ empresa.nota_pie }}</div>
      </div>
    </Dialog>
  </div>
</template>

<style scoped>
/* ══ RIDE: mismo formato que la factura impresa del SRI ══
   Bordes finos y tipografía compacta; se imprime tal cual se ve. */
.ride { border: 1px solid #000; font-size: 10.5px; color: #000; background: #fff; line-height: 1.35; }
.ride .mono { font-family: "SF Mono", ui-monospace, Menlo, Consolas, monospace; }
.ride .c { text-align: center; }
.ride .d { text-align: right; }

.ride-top { display: flex; border-bottom: 1px solid #000; }
.ride-emisor { flex: 1.05; padding: 10px 12px; border-right: 1px solid #000; }
.ride-logo { max-width: 150px; max-height: 62px; object-fit: contain; margin-bottom: 8px; display: block; }
.ride-rs { font-size: 12.5px; font-weight: 700; text-transform: uppercase; }
.ride-nc { font-size: 11px; margin-bottom: 5px; }
.ride-l { margin-top: 2px; }
.ride-doc { flex: 1; padding: 10px 12px; }
.ride-ruc { font-weight: 700; font-size: 12px; }
.ride-tipo { font-weight: 700; font-size: 14px; margin-top: 4px; letter-spacing: .5px; }
.ride-num { font-weight: 700; font-size: 11.5px; margin-bottom: 7px; }

/* Campo con etiqueta arriba y valor recuadrado, como el formulario del SRI */
.ride-f { margin-bottom: 5px; }
.ride-f > span { display: block; font-size: 7.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
.ride-f > em { display: block; font-style: normal; border: 1px solid #000; padding: 2px 5px;
  min-height: 16px; word-break: break-all; font-size: 9.5px; }
.ride-2c { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }

.ride-cli { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px 8px;
  padding: 8px 12px; border-bottom: 1px solid #000; }
.ride-cli .ride-w2 { grid-column: span 2; }

.ride-tbl { width: 100%; border-collapse: collapse; }
.ride-tbl th { border-bottom: 1px solid #000; border-top: 1px solid #000;
  padding: 4px 6px; font-size: 8px; letter-spacing: .04em; text-align: left; background: #f2f2f2; }
.ride-tbl td { padding: 3px 6px; border-bottom: 1px solid #dcdcdc; font-size: 9.5px; }
.ride-tbl tbody tr:last-child td { border-bottom: 1px solid #000; }

.ride-pie { display: flex; }
.ride-pagos { flex: 1.35; padding: 8px 12px; border-right: 1px solid #000; }
.ride-adic { margin-top: 8px; padding-top: 6px; border-top: 1px solid #bbb; font-size: 8px; }
.ride-tot { flex: 1; border-collapse: collapse; align-self: flex-start; width: 100%; }
.ride-tot td { padding: 3px 10px; border-bottom: 1px solid #dcdcdc; font-size: 9.5px; }
.ride-tot tr:last-child td { border-bottom: 0; }
.ride-total td { font-weight: 700; font-size: 11px; border-top: 1px solid #000; }
.ride-nota { border-top: 1px solid #000; padding: 6px 12px; font-size: 8.5px; font-weight: 700; }

@media print { .ride { border: 1px solid #000; } }

.invoices-layout { display: flex; flex-direction: column; height: 100%; background: #eef1f5; }

.invoices-toolbar {
  background: var(--hr-gradient); padding: 8px 12px; flex-shrink: 0;
}
.invoices-filters {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.invoices-title { color: #fff; font-weight: 600; font-size: 13px; margin-right: 12px; }

.invoices-grid-wrap { flex: 1; overflow: auto; padding: 0; }
.invoices-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.invoices-table th {
  text-align: left; background: #f2f4f7; border-bottom: 1px solid #b9c2cc;
  padding: 9px 12px; font-weight: 600; position: sticky; top: 0; z-index: 1;
}
.invoices-table td { padding: 9px 12px; border-bottom: 1px solid #eef1f5; vertical-align: middle; }
/* Los datos cortos no deben partirse en dos líneas; solo el cliente puede envolver. */
.invoices-table td, .invoices-table th { white-space: nowrap; }
.invoices-table .col-cliente { white-space: normal; min-width: 190px; line-height: 1.35; }
.invoices-table .der { text-align: right; }
.invoices-table .vacio { color: #94a3b8; text-align: center; padding: 20px; }
.invoices-row { cursor: pointer; }
.invoices-row:hover { background: #eef6f6; }
.invoices-row--sel { background: #e0eef0; box-shadow: inset 3px 0 0 var(--hr-navy); }
.invoices-row--sel:hover { background: #d8e9ec; }
.invoices-row--anulada { opacity: 0.55; }
.invoices-row--anulada td { text-decoration: line-through; }

.invoices-foot {
  font-size: 11.5px; color: #64748b; padding: 5px 12px;
  border-top: 1px solid #e2e5ea; background: #f7f9fb; flex-shrink: 0;
}
</style>
