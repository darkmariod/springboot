<script setup lang="ts">
/**
 * Estado de cuenta de clientes y de proveedores (T3.1).
 *
 * Dos pestañas, "Clientes" y "Proveedores", con los mismos filtros (fecha de emisión, búsqueda, solo con saldo):
 *  - Resumen: una fila por cliente o proveedor, con totales y antigüedad de la deuda (días desde la emisión).
 *  - Al hacer clic en una fila se abre el detalle: cada factura (o compra) con su número, las notas de crédito y
 *    débito, las retenciones, los cobros o pagos y cuánto se debe; y los movimientos con saldo corrido.
 * El saldo de cada documento es el mismo de Cuentas por cobrar / por pagar y del libro mayor. Si el desglose no
 * suma ese saldo, la diferencia sale como "Otros ajustes" en vez de esconderse.
 * La pantalla abre en la pestaña que indica el título del menú ("... de proveedores" abre Proveedores).
 */
import { computed, onMounted, ref, watch } from 'vue'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

type Lado = 'clientes' | 'proveedores'

const props = defineProps<{ titulo?: string }>()
const company = useCompanyStore()

const LADOS: Lado[] = ['clientes', 'proveedores']
const tab = ref(/proveedor/i.test(props.titulo ?? '') ? 1 : 0)
const lado = computed<Lado>(() => LADOS[tab.value] ?? 'clientes')

const filtro = ref({ desde: '', hasta: '', buscar: '', conSaldo: false })
const datos = ref<Record<Lado, any>>({ clientes: null, proveedores: null })
const cargando = ref(false)
const error = ref('')

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const tramos = ['0-30', '31-60', '61-90', '90+']
const tramoTitulo = (t: string) => (t === '90+' ? 'Más de 90 días' : t + ' días')

const totales = computed(() => datos.value[lado.value]?.totales ?? null)
const filasClientes = computed<any[]>(() => datos.value.clientes?.clientes ?? [])
const filasProveedores = computed<any[]>(() => datos.value.proveedores?.proveedores ?? [])
const tc = computed(() => datos.value.clientes?.totales ?? null)
const tp = computed(() => datos.value.proveedores?.totales ?? null)

function baseParams(): any {
  const p: any = { company_id: company.activeId }
  if (filtro.value.desde) p.desde = filtro.value.desde
  if (filtro.value.hasta) p.hasta = filtro.value.hasta
  return p
}

function textoError(e: any, defecto: string) {
  const errores = e?.response?.data?.errors
  return errores ? (Object.values(errores).flat() as string[]).join(' · ') : (e?.response?.data?.message ?? defecto)
}

async function consultar() {
  error.value = ''
  cargando.value = true
  try {
    const params = baseParams()
    if (filtro.value.buscar.trim()) params.buscar = filtro.value.buscar.trim()
    if (filtro.value.conSaldo) params.con_saldo = 1
    datos.value[lado.value] = (await api.get('/estado-cuenta/' + lado.value, { params })).data
  } catch (e: any) {
    error.value = textoError(e, 'No se pudo consultar el estado de cuenta.')
  } finally {
    cargando.value = false
  }
}

function limpiar() {
  filtro.value = { desde: '', hasta: '', buscar: '', conSaldo: false }
  consultar()
}

// ─── Detalle de un cliente o proveedor ───
const detalle = ref<any>(null)
const verDetalle = ref(false)
const cargandoDetalle = ref(false)
const vistaDetalle = ref(0)       // 0 = documentos, 1 = movimientos
const soloConSaldo = ref(false)
const errorDetalle = ref('')

async function abrir(fila: any) {
  errorDetalle.value = ''
  detalle.value = null
  vistaDetalle.value = 0
  soloConSaldo.value = false
  verDetalle.value = true
  cargandoDetalle.value = true
  try {
    const data = (await api.get('/estado-cuenta/' + lado.value + '/' + fila.contact_id, { params: baseParams() })).data
    detalle.value = { ...data, lado: lado.value, contactId: fila.contact_id, nombre: fila.cliente ?? fila.proveedor }
  } catch (e: any) {
    errorDetalle.value = textoError(e, 'No se pudo cargar el detalle.')
  } finally {
    cargandoDetalle.value = false
  }
}

const documentos = computed<any[]>(() => {
  const docs: any[] = detalle.value?.documentos ?? []
  return soloConSaldo.value ? docs.filter((d) => Math.abs(d.saldo) >= 0.005) : docs
})
const sumaDocs = (campo: string) => documentos.value.reduce((s, d) => s + Number(d[campo] ?? 0), 0)

const cajas = computed(() => {
  const r = detalle.value?.resumen
  if (!r) return []
  if (detalle.value.lado === 'clientes') {
    return [
      { t: 'Facturado', v: r.facturado }, { t: 'Notas de débito', v: r.notas_debito }, { t: 'Notas de crédito', v: r.notas_credito },
      { t: 'Retenciones', v: r.retenciones }, { t: 'Cobros', v: r.cobros }, { t: 'Saldo', v: r.saldo, fuerte: true },
      { t: 'Notas de crédito a favor', v: r.saldo_favor_nc }, { t: 'Anticipos sin aplicar', v: r.anticipos_sin_aplicar },
      { t: 'Saldo neto', v: r.saldo_neto, fuerte: true },
    ]
  }
  return [
    { t: 'Comprado', v: r.comprado }, { t: 'Retenciones', v: r.retenciones }, { t: 'Pagos', v: r.pagos },
    { t: 'Anticipos aplicados', v: r.anticipos_aplicados }, { t: 'Saldo', v: r.saldo, fuerte: true },
    { t: 'Anticipos sin aplicar', v: r.anticipos_sin_aplicar }, { t: 'Saldo neto', v: r.saldo_neto, fuerte: true },
  ]
})

const TIPOS: Record<string, string> = {
  factura: 'Factura', compra: 'Compra', nota_debito: 'Nota de débito', nota_credito: 'Nota de crédito', retencion: 'Retención',
  cobro: 'Cobro', pago: 'Pago', cruce: 'Cruce de saldos', anticipo: 'Anticipo', ajuste: 'Otros ajustes',
}
const tipoMovimiento = (t: string) => TIPOS[t] ?? t

function severidadDias(d: any) {
  if (d.saldo <= 0) return 'secondary'
  return d.dias <= 30 ? 'success' : d.dias <= 90 ? 'warn' : 'danger'
}

// ─── Exportar ───
async function descargar(formato: 'excel' | 'pdf', porContacto = false) {
  error.value = ''
  errorDetalle.value = ''
  try {
    const params: any = { ...baseParams(), formato }
    let url = '/estado-cuenta/' + lado.value
    let nombre = 'estado-cuenta-' + lado.value
    if (porContacto && detalle.value) {
      url += '/' + detalle.value.contactId
      nombre += '-detalle'
      if (vistaDetalle.value === 1) {
        params.vista = 'movimientos'
        nombre += '-movimientos'
      }
    } else {
      if (filtro.value.buscar.trim()) params.buscar = filtro.value.buscar.trim()
      if (filtro.value.conSaldo) params.con_saldo = 1
    }
    const res = await api.get(url, { params, responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: formato === 'pdf' ? 'application/pdf' : 'text/csv' }))
    a.download = nombre + (formato === 'pdf' ? '.pdf' : '.csv')
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    const mensaje = 'No se pudo generar el archivo.'
    if (porContacto) errorDetalle.value = mensaje
    else error.value = mensaje
  }
}

watch(tab, () => {
  verDetalle.value = false
  consultar()
})
onMounted(consultar)
</script>

<template>
  <div style="padding: 20px;">
    <h2 style="margin: 0 0 4px;">Estado de cuenta</h2>
    <p style="color: #94a3b8; font-size: 13px; margin: 0 0 14px;">
      Por documento: la nota de crédito que lo afecta, la retención, los cobros o pagos y cuánto se debe. El saldo es el mismo de Cuentas por cobrar y por pagar.
    </p>

    <fieldset class="kvs-fieldset" style="margin-bottom: 14px;">
      <legend>Filtros (fecha de emisión del documento)</legend>
      <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <div class="kvs-row" style="margin-bottom: 0;">
          <label class="kvs-lbl" style="min-width: auto;">Desde:</label>
          <InputText v-model="filtro.desde" type="date" style="width: 160px;" />
        </div>
        <div class="kvs-row" style="margin-bottom: 0;">
          <label class="kvs-lbl" style="min-width: auto;">Hasta:</label>
          <InputText v-model="filtro.hasta" type="date" style="width: 160px;" />
        </div>
        <div class="kvs-row" style="margin-bottom: 0;">
          <label class="kvs-lbl" style="min-width: auto;">Buscar:</label>
          <InputText v-model="filtro.buscar" placeholder="Nombre o identificación" style="width: 220px;" @keyup.enter="consultar" />
        </div>
        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
          <Checkbox v-model="filtro.conSaldo" binary input-id="ec-con-saldo" />
          <span>Solo con saldo</span>
        </label>
        <Button label="Consultar" icon="pi pi-search" size="small" :loading="cargando" @click="consultar" />
        <Button label="Limpiar" icon="pi pi-eraser" size="small" text @click="limpiar" />
        <span style="flex: 1;" />
        <Button label="Descargar Excel" icon="pi pi-file-excel" size="small" outlined @click="descargar('excel')" />
        <Button label="Descargar PDF" icon="pi pi-print" size="small" outlined @click="descargar('pdf')" />
      </div>
    </fieldset>

    <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 12px;">{{ error }}</Message>

    <!-- Totales y antigüedad de la pestaña activa -->
    <div v-if="totales" style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-bottom: 14px;">
      <div style="border: 2px solid #2c3e50; border-radius: 8px; padding: 10px; background: #f8fafc;">
        <div style="font-size: 11px; color: #94a3b8;">{{ lado === 'clientes' ? 'POR COBRAR' : 'POR PAGAR' }}</div>
        <b>{{ money(totales.saldo) }}</b>
      </div>
      <div v-for="t in tramos" :key="t" style="border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px; background: #fff;">
        <div style="font-size: 11px; color: #94a3b8;">{{ tramoTitulo(t).toUpperCase() }}</div>
        <b>{{ money(totales.antiguedad?.[t]) }}</b>
      </div>
      <div style="border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px; background: #fff;">
        <div style="font-size: 11px; color: #94a3b8;">SALDO NETO</div>
        <b>{{ money(totales.saldo_neto) }}</b>
      </div>
    </div>

    <TabView v-model:activeIndex="tab">
      <!-- ═══ Clientes ═══ -->
      <TabPanel header="Clientes" value="clientes">
        <div style="overflow-x: auto;">
          <DataTable :value="filasClientes" :loading="cargando" size="small" stripedRows selectionMode="single" dataKey="contact_id"
                     :paginator="filasClientes.length > 20" :rows="20" @row-click="abrir($event.data)">
            <Column header="Cliente" :footer="'TOTAL (' + filasClientes.length + ')'" style="min-width: 220px;">
              <template #body="{ data }">
                <b>{{ data.cliente }}</b><br><small style="color: #94a3b8;">{{ data.identificacion }}</small>
              </template>
            </Column>
            <Column header="Facturado" :footer="money(tc?.facturado)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.facturado) }}</template>
            </Column>
            <Column header="N. débito" :footer="money(tc?.notas_debito)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.notas_debito) }}</template>
            </Column>
            <Column header="N. crédito" :footer="money(tc?.notas_credito)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.notas_credito) }}</template>
            </Column>
            <Column header="Retenciones" :footer="money(tc?.retenciones)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.retenciones) }}</template>
            </Column>
            <Column header="Cobros" :footer="money(tc?.cobros)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.cobros) }}</template>
            </Column>
            <Column header="Saldo" :footer="money(tc?.saldo)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }"><b>{{ money(data.saldo) }}</b></template>
            </Column>
            <Column v-for="t in tramos" :key="t" :header="tramoTitulo(t)" :footer="money(tc?.antiguedad?.[t])"
                    body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.antiguedad?.[t]) }}</template>
            </Column>
            <Column header="A favor sin aplicar" :footer="money((tc?.anticipos_sin_aplicar ?? 0) + (tc?.saldo_favor_nc ?? 0))"
                    body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.anticipos_sin_aplicar + data.saldo_favor_nc) }}</template>
            </Column>
            <Column header="Saldo neto" :footer="money(tc?.saldo_neto)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }"><b>{{ money(data.saldo_neto) }}</b></template>
            </Column>
            <template #empty>No hay clientes con documentos en esta consulta.</template>
          </DataTable>
        </div>
        <p style="color: #94a3b8; font-size: 12px; margin: 8px 0 0;">Haz clic en un cliente para ver cada factura con sus notas, retenciones y cobros.</p>
      </TabPanel>

      <!-- ═══ Proveedores ═══ -->
      <TabPanel header="Proveedores" value="proveedores">
        <div style="overflow-x: auto;">
          <DataTable :value="filasProveedores" :loading="cargando" size="small" stripedRows selectionMode="single" dataKey="contact_id"
                     :paginator="filasProveedores.length > 20" :rows="20" @row-click="abrir($event.data)">
            <Column header="Proveedor" :footer="'TOTAL (' + filasProveedores.length + ')'" style="min-width: 220px;">
              <template #body="{ data }">
                <b>{{ data.proveedor }}</b><br><small style="color: #94a3b8;">{{ data.identificacion }}</small>
              </template>
            </Column>
            <Column header="Compras" :footer="money(tp?.comprado)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.comprado) }}</template>
            </Column>
            <Column header="Retenciones" :footer="money(tp?.retenciones)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.retenciones) }}</template>
            </Column>
            <Column header="Pagos" :footer="money(tp?.pagos)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.pagos) }}</template>
            </Column>
            <Column header="Anticipos aplicados" :footer="money(tp?.anticipos_aplicados)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.anticipos_aplicados) }}</template>
            </Column>
            <Column header="Saldo" :footer="money(tp?.saldo)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }"><b>{{ money(data.saldo) }}</b></template>
            </Column>
            <Column v-for="t in tramos" :key="t" :header="tramoTitulo(t)" :footer="money(tp?.antiguedad?.[t])"
                    body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.antiguedad?.[t]) }}</template>
            </Column>
            <Column header="Anticipos sin aplicar" :footer="money(tp?.anticipos_sin_aplicar)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }">{{ money(data.anticipos_sin_aplicar) }}</template>
            </Column>
            <Column header="Saldo neto" :footer="money(tp?.saldo_neto)" body-style="text-align: right" footer-style="text-align: right">
              <template #body="{ data }"><b>{{ money(data.saldo_neto) }}</b></template>
            </Column>
            <template #empty>No hay proveedores con documentos en esta consulta.</template>
          </DataTable>
        </div>
        <p style="color: #94a3b8; font-size: 12px; margin: 8px 0 0;">Haz clic en un proveedor para ver cada compra con sus retenciones y pagos.</p>
      </TabPanel>
    </TabView>

    <!-- ═══ Detalle de un cliente o proveedor ═══ -->
    <Dialog v-model:visible="verDetalle" modal :header="'Estado de cuenta — ' + (detalle?.nombre ?? '')" :style="{ width: '1100px', maxWidth: '96vw' }">
      <Message v-if="errorDetalle" severity="error" :closable="false" style="margin-bottom: 12px;">{{ errorDetalle }}</Message>
      <p v-if="cargandoDetalle" style="color: #94a3b8;">Cargando…</p>

      <div v-if="detalle" style="display: flex; flex-direction: column; gap: 14px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px;">
          <div v-for="c in cajas" :key="c.t"
               :style="{ border: c.fuerte ? '2px solid #2c3e50' : '1px solid #e2e5ea', borderRadius: '8px', padding: '8px 10px', background: c.fuerte ? '#f8fafc' : '#fff' }">
            <div style="font-size: 11px; color: #94a3b8;">{{ c.t.toUpperCase() }}</div>
            <b>{{ money(c.v) }}</b>
          </div>
        </div>

        <TabView v-model:activeIndex="vistaDetalle">
          <!-- Documento por documento -->
          <TabPanel :header="detalle.lado === 'clientes' ? 'Facturas' : 'Compras'" value="documentos">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; margin-bottom: 8px; cursor: pointer;">
              <Checkbox v-model="soloConSaldo" binary input-id="ec-solo-saldo" />
              <span>Solo documentos con saldo</span>
            </label>

            <!-- Facturas -->
            <DataTable v-if="detalle.lado === 'clientes'" :value="documentos" size="small" stripedRows>
              <Column field="fecha" header="Fecha" footer="TOTAL" style="width: 95px;" />
              <Column field="numero" header="Factura" :footer="documentos.length + ' documento(s)'" style="min-width: 150px;" />
              <Column header="Total" :footer="money(sumaDocs('total'))" body-style="text-align: right" footer-style="text-align: right">
                <template #body="{ data }">{{ money(data.total) }}</template>
              </Column>
              <Column header="Notas de crédito" :footer="'− ' + money(sumaDocs('notas_credito_total'))" style="min-width: 170px;">
                <template #body="{ data }">
                  <div v-for="n in data.notas_credito" :key="n.via + n.id">
                    {{ n.numero }} <b>− {{ money(n.aplicado) }}</b>
                    <small v-if="n.via === 'saldo_favor'" style="color: #94a3b8;"> (saldo a favor)</small>
                    <small v-else-if="n.aplicado < n.total" style="color: #94a3b8;"> (de {{ money(n.total) }})</small>
                  </div>
                  <span v-if="!data.notas_credito.length" style="color: #cbd5e1;">—</span>
                </template>
              </Column>
              <Column header="Notas de débito" :footer="'+ ' + money(sumaDocs('notas_debito_total'))" style="min-width: 150px;">
                <template #body="{ data }">
                  <div v-for="n in data.notas_debito" :key="n.id">{{ n.numero }} <b>+ {{ money(n.total) }}</b></div>
                  <span v-if="!data.notas_debito.length" style="color: #cbd5e1;">—</span>
                </template>
              </Column>
              <Column header="Retenciones" :footer="'− ' + money(sumaDocs('retenciones_total'))" style="min-width: 150px;">
                <template #body="{ data }">
                  <div v-for="w in data.retenciones" :key="w.numero">{{ w.numero }} <b>− {{ money(w.valor) }}</b></div>
                  <span v-if="!data.retenciones.length" style="color: #cbd5e1;">—</span>
                </template>
              </Column>
              <Column header="Cobros" :footer="'− ' + money(sumaDocs('cobros_total'))" style="min-width: 130px;">
                <template #body="{ data }">
                  <div v-for="(c, i) in data.cobros" :key="i">{{ c.forma_label }} <b>− {{ money(c.monto) }}</b></div>
                  <span v-if="!data.cobros.length" style="color: #cbd5e1;">—</span>
                </template>
              </Column>
              <Column header="Anticipos" :footer="'− ' + money(sumaDocs('anticipos_total'))" body-style="text-align: right" footer-style="text-align: right">
                <template #body="{ data }">{{ data.anticipos_total ? '− ' + money(data.anticipos_total) : '—' }}</template>
              </Column>
              <Column header="Saldo" :footer="money(sumaDocs('saldo'))" body-style="text-align: right" footer-style="text-align: right">
                <template #body="{ data }">
                  <b>{{ money(data.saldo) }}</b>
                  <div v-if="Math.abs(data.ajustes) >= 0.005" style="font-size: 11px; color: #b45309;">incluye ajustes {{ money(data.ajustes) }}</div>
                </template>
              </Column>
              <Column header="Antigüedad">
                <template #body="{ data }"><Tag :value="data.dias + ' días'" :severity="severidadDias(data)" /></template>
              </Column>
              <template #empty>No hay facturas para mostrar.</template>
            </DataTable>

            <!-- Compras -->
            <DataTable v-else :value="documentos" size="small" stripedRows>
              <Column field="fecha" header="Fecha" footer="TOTAL" style="width: 95px;" />
              <Column field="numero" header="Compra" :footer="documentos.length + ' documento(s)'" style="min-width: 150px;" />
              <Column header="Total" :footer="money(sumaDocs('total'))" body-style="text-align: right" footer-style="text-align: right">
                <template #body="{ data }">{{ money(data.total) }}</template>
              </Column>
              <Column header="Retenciones" :footer="'− ' + money(sumaDocs('retenciones_total'))" style="min-width: 150px;">
                <template #body="{ data }">
                  <div v-for="w in data.retenciones" :key="w.numero">{{ w.numero }} <b>− {{ money(w.valor) }}</b></div>
                  <span v-if="!data.retenciones.length" style="color: #cbd5e1;">—</span>
                </template>
              </Column>
              <Column header="Pagos" :footer="'− ' + money(sumaDocs('pagos_total'))" style="min-width: 190px;">
                <template #body="{ data }">
                  <div v-for="(p, i) in data.pagos" :key="i">
                    {{ p.forma_label }} <b>− {{ money(p.monto) }}</b>
                    <small v-if="p.documento" style="color: #94a3b8;"> ({{ p.documento }})</small>
                  </div>
                  <span v-if="!data.pagos.length" style="color: #cbd5e1;">—</span>
                </template>
              </Column>
              <Column header="Anticipos aplicados" :footer="'− ' + money(sumaDocs('anticipos_total'))" style="min-width: 150px;">
                <template #body="{ data }">
                  <div v-for="(a, i) in data.anticipos" :key="i">{{ a.documento }} <b>− {{ money(a.monto) }}</b></div>
                  <span v-if="!data.anticipos.length" style="color: #cbd5e1;">—</span>
                </template>
              </Column>
              <Column header="Saldo" :footer="money(sumaDocs('saldo'))" body-style="text-align: right" footer-style="text-align: right">
                <template #body="{ data }">
                  <b>{{ money(data.saldo) }}</b>
                  <div v-if="Math.abs(data.ajustes) >= 0.005" style="font-size: 11px; color: #b45309;">incluye ajustes {{ money(data.ajustes) }}</div>
                </template>
              </Column>
              <Column header="Antigüedad">
                <template #body="{ data }"><Tag :value="data.dias + ' días'" :severity="severidadDias(data)" /></template>
              </Column>
              <template #empty>No hay compras para mostrar.</template>
            </DataTable>
          </TabPanel>

          <!-- Movimientos con saldo corrido -->
          <TabPanel header="Movimientos" value="movimientos">
            <DataTable :value="detalle.movimientos" size="small" stripedRows :paginator="detalle.movimientos.length > 25" :rows="25">
              <Column field="fecha" header="Fecha" style="width: 95px;" />
              <Column header="Tipo" style="width: 140px;">
                <template #body="{ data }">{{ tipoMovimiento(data.tipo) }}</template>
              </Column>
              <Column field="documento" header="Documento" style="min-width: 150px;" />
              <Column field="referencia" :header="detalle.lado === 'clientes' ? 'Factura' : 'Compra'" style="min-width: 150px;" />
              <Column field="detalle" header="Detalle" style="min-width: 200px;" />
              <Column header="Cargo" body-style="text-align: right">
                <template #body="{ data }">{{ data.cargo ? money(data.cargo) : '' }}</template>
              </Column>
              <Column header="Abono" body-style="text-align: right">
                <template #body="{ data }">{{ data.abono ? money(data.abono) : '' }}</template>
              </Column>
              <Column header="Saldo" body-style="text-align: right">
                <template #body="{ data }"><b>{{ money(data.saldo) }}</b></template>
              </Column>
              <template #empty>No hay movimientos para mostrar.</template>
            </DataTable>
          </TabPanel>
        </TabView>

        <!-- Lo que se tiene a favor y todavía no se aplicó: no baja ninguna factura, por eso va aparte -->
        <div v-if="detalle.anticipos_sin_aplicar.length || detalle.notas_credito_a_favor?.length"
             style="border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px 12px; background: #fff;">
          <b style="font-size: 13px;">{{ detalle.lado === 'clientes' ? 'Saldos a favor del cliente sin aplicar' : 'Anticipos entregados sin aplicar' }}</b>
          <div v-for="a in detalle.anticipos_sin_aplicar" :key="'a' + a.id" style="font-size: 13px; margin-top: 4px;">
            {{ a.fecha }} · Anticipo ANT-{{ a.id }} <span v-if="a.nota">({{ a.nota }})</span> — queda <b>{{ money(a.saldo) }}</b> de {{ money(a.monto) }}
          </div>
          <div v-for="n in detalle.notas_credito_a_favor ?? []" :key="'n' + n.id" style="font-size: 13px; margin-top: 4px;">
            {{ n.fecha }} · Nota de crédito {{ n.numero }} — a favor <b>{{ money(n.saldo) }}</b> de {{ money(n.total) }}
          </div>
          <div style="font-size: 13px; margin-top: 8px;">
            Saldo neto (saldo menos lo que está a favor): <b>{{ money(detalle.resumen.saldo_neto) }}</b>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Descargar Excel" icon="pi pi-file-excel" size="small" outlined :disabled="!detalle" @click="descargar('excel', true)" />
        <Button label="Descargar PDF" icon="pi pi-print" size="small" outlined :disabled="!detalle" @click="descargar('pdf', true)" />
        <Button label="Cerrar" text @click="verDetalle = false" />
      </template>
    </Dialog>
  </div>
</template>
