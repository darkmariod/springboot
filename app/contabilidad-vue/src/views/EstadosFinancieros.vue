<script setup lang="ts">
/**
 * Estados financieros por período (T4.3b): estado de situación financiera (balance general) y estado de resultados,
 * con el detalle por cuenta en un árbol (1 → 1.1 → 1.1.01), subtotales por nivel, totales y, en el balance, la
 * verificación contable: Activo = Pasivo + Patrimonio + Resultado del período.
 * Cuentan los mismos asientos que el libro mayor (pendientes y mayorizados; los borradores no). El asiento de cierre del
 * ejercicio no entra al estado de resultados del año que cierra: el resultado de ese año sigue visible.
 */
import { computed, onMounted, ref, watch } from 'vue'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import TreeTable from 'primevue/treetable'
import Column from 'primevue/column'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

interface Nodo {
  codigo: string; nombre: string; tipo: string; nivel: number
  debe: number; haber: number; saldo: number; es_encabezado: boolean; hijos: Nodo[]
}
interface Seccion { tipo: string; titulo: string; total: number; cuentas: Nodo[] }

const company = useCompanyStore()
const tab = ref(0)                       // 0 = situación financiera, 1 = resultados
const cargando = ref(false)
const error = ref('')

const hoy = new Date()
const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
const opcionesMes = [{ label: 'Todo el año', value: null }, ...meses.map((m, i) => ({ label: m, value: i + 1 }))]
const opcionesAnio = Array.from({ length: 8 }, (_, i) => hoy.getFullYear() + 1 - i).map((y) => ({ label: String(y), value: y }))

const mes = ref<number | null>(null)
const anio = ref<number>(hoy.getFullYear())
const desde = ref('')
const hasta = ref('')

const dos = (n: number) => String(n).padStart(2, '0')
/** Mes y año elegidos → desde / hasta (primer y último día). Los cuadros de fecha se pueden ajustar a mano después. */
function aplicarPeriodo() {
  const a = anio.value
  if (!a) return
  if (mes.value) {
    const ultimo = new Date(a, mes.value, 0).getDate()
    desde.value = `${a}-${dos(mes.value)}-01`
    hasta.value = `${a}-${dos(mes.value)}-${dos(ultimo)}`
  } else {
    desde.value = `${a}-01-01`
    hasta.value = `${a}-12-31`
  }
}

const balance = ref<any>(null)
const resultados = ref<any>(null)

function textoError(e: any, defecto: string) {
  const errores = e?.response?.data?.errors
  return errores ? (Object.values(errores).flat() as string[]).join(' · ') : (e?.response?.data?.message ?? defecto)
}

function parametros(formato?: 'excel' | 'pdf') {
  const p: Record<string, any> = { company_id: company.activeId }
  if (tab.value === 0) {
    if (hasta.value) p.hasta = hasta.value
  } else {
    if (desde.value) p.desde = desde.value
    if (hasta.value) p.hasta = hasta.value
  }
  if (formato) p.formato = formato
  return p
}

async function consultar() {
  error.value = ''
  cargando.value = true
  try {
    if (tab.value === 0) balance.value = (await api.get('/accounting/balance-general', { params: parametros() })).data
    else resultados.value = (await api.get('/accounting/estado-resultados', { params: parametros() })).data
  } catch (e: any) {
    error.value = textoError(e, 'No se pudo consultar el estado financiero.')
  } finally {
    cargando.value = false
  }
}

async function descargar(formato: 'excel' | 'pdf') {
  error.value = ''
  try {
    const url = tab.value === 0 ? '/accounting/balance-general' : '/accounting/estado-resultados'
    const res = await api.get(url, { params: parametros(formato), responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: formato === 'pdf' ? 'application/pdf' : 'text/csv' }))
    const nombre = tab.value === 0 ? 'balance-general' : 'estado-resultados' + (desde.value ? '-' + desde.value : '')
    a.download = nombre + (hasta.value ? '-' + hasta.value : '') + (formato === 'pdf' ? '.pdf' : '.csv')
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    error.value = 'No se pudo generar el archivo.'
  }
}

// ─── Árbol ───
interface NodoTabla { key: string; data: Nodo; children: NodoTabla[] }
const aTabla = (nodos: Nodo[]): NodoTabla[] => nodos.map((n) => ({ key: n.codigo, data: n, children: aTabla(n.hijos) }))
function todasLasClaves(nodos: Nodo[], acc: Record<string, boolean> = {}) {
  for (const n of nodos) {
    if (n.hijos.length) { acc[n.codigo] = true; todasLasClaves(n.hijos, acc) }
  }
  return acc
}
// Qué ramas están abiertas, por sección (la clave es "tipo:código" porque el mismo código no se repite entre secciones)
const abiertas = ref<Record<string, Record<string, boolean>>>({})

const secciones = computed<Seccion[]>(() => (tab.value === 0 ? balance.value?.secciones : resultados.value?.secciones) ?? [])
watch(secciones, (lista) => {
  const nuevo: Record<string, Record<string, boolean>> = {}
  for (const s of lista) nuevo[s.tipo] = todasLasClaves(s.cuentas)
  abiertas.value = nuevo
}, { immediate: true })
function expandirTodo(abrir: boolean) {
  const nuevo: Record<string, Record<string, boolean>> = {}
  for (const s of secciones.value) nuevo[s.tipo] = abrir ? todasLasClaves(s.cuentas) : {}
  abiertas.value = nuevo
}

// ─── Presentación ───
const dinero = (n: any) => {
  const v = Number(n ?? 0)
  const t = Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return (v < -0.004 ? '-$' : '$') + t
}
const t = computed(() => balance.value?.totales ?? null)
const r = computed(() => resultados.value?.totales ?? null)
const fechaLarga = (iso: string | null) => (iso ? iso.split('-').reverse().join('/') : '')
const subtituloBalance = computed(() => (balance.value?.hasta ? 'Al ' + fechaLarga(balance.value.hasta) : 'A la fecha'))
const subtituloResultados = computed(() => {
  const x = resultados.value
  if (!x) return ''
  if (x.desde && x.hasta) return `Del ${fechaLarga(x.desde)} al ${fechaLarga(x.hasta)}`
  return x.hasta ? 'Hasta el ' + fechaLarga(x.hasta) : x.desde ? 'Desde el ' + fechaLarga(x.desde) : 'Todo el historial'
})

watch(tab, () => {
  if ((tab.value === 0 && !balance.value) || (tab.value === 1 && !resultados.value)) consultar()
})
watch([mes, anio], () => { aplicarPeriodo(); consultar() })

onMounted(() => {
  aplicarPeriodo()
  consultar()
})
</script>

<template>
  <div class="ef">
    <h2>Estados financieros</h2>
    <p class="sub">
      Cada cuenta con su saldo y los subtotales por nivel. Cuentan los asientos pendientes y mayorizados; los borradores no.
    </p>

    <fieldset class="filtros">
      <legend>Período</legend>
      <div class="fila">
        <label>Mes <Select v-model="mes" :options="opcionesMes" optionLabel="label" optionValue="value" placeholder="Todo el año" style="width: 150px;" aria-label="Mes" /></label>
        <label>Año <Select v-model="anio" :options="opcionesAnio" optionLabel="label" optionValue="value" style="width: 110px;" aria-label="Año" /></label>
        <label v-if="tab === 1">Desde <InputText v-model="desde" type="date" style="width: 160px;" aria-label="Desde" data-test="desde" /></label>
        <label>{{ tab === 1 ? 'Hasta' : 'Al' }} <InputText v-model="hasta" type="date" style="width: 160px;" aria-label="Fecha de corte" data-test="hasta" /></label>
        <Button label="Consultar" icon="pi pi-search" size="small" :loading="cargando" @click="consultar" data-test="consultar" />
        <span style="flex: 1;"></span>
        <Button label="Excel" icon="pi pi-file-excel" size="small" severity="secondary" :disabled="cargando" @click="descargar('excel')" data-test="excel" />
        <Button label="PDF" icon="pi pi-file-pdf" size="small" severity="secondary" :disabled="cargando" @click="descargar('pdf')" data-test="pdf" />
      </div>
    </fieldset>

    <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 10px;">{{ error }}</Message>

    <TabView v-model:activeIndex="tab">
      <!-- Estado de situación financiera -->
      <TabPanel header="Estado de situación financiera" value="situacion">
        <div v-if="balance" data-test="balance">
          <div class="cabecera">
            <div>
              <div class="titulo">Estado de situación financiera</div>
              <div class="sub">{{ subtituloBalance }}</div>
            </div>
            <Tag v-if="t" :value="t.cuadra ? 'Cuadra' : 'Diferencia ' + dinero(t.diferencia)" :severity="t.cuadra ? 'success' : 'danger'" data-test="cuadra"
                 v-tooltip.top="'Activo = Pasivo + Patrimonio + Resultado del período'" />
            <span style="flex: 1;"></span>
            <Button label="Expandir todo" text size="small" @click="expandirTodo(true)" />
            <Button label="Contraer" text size="small" @click="expandirTodo(false)" />
          </div>

          <div v-for="s in balance.secciones" :key="s.tipo" class="seccion">
            <div class="seccion-titulo">{{ s.titulo }}</div>
            <TreeTable v-if="s.cuentas.length" :value="aTabla(s.cuentas)" v-model:expandedKeys="abiertas[s.tipo]" size="small" :indentation="1.2">
              <Column field="codigo" header="Código" expander style="width: 26%" />
              <Column field="nombre" header="Cuenta" style="width: 46%" />
              <Column header="Saldo" style="width: 28%; text-align: right;">
                <template #body="{ node }">
                  <span :class="{ fuerte: node.data.es_encabezado }" :data-saldo="node.data.codigo">{{ dinero(node.data.saldo) }}</span>
                </template>
              </Column>
            </TreeTable>
            <p v-else class="vacio">Sin movimientos en este período.</p>
            <div class="total"><span>Total {{ s.titulo.toLowerCase() }}</span><b :data-total="s.tipo">{{ dinero(s.total) }}</b></div>
          </div>

          <div class="ecuacion" v-if="t">
            <div class="total"><span>Resultado del período</span><b data-total="resultado">{{ dinero(t.resultado_periodo) }}</b></div>
            <div class="total"><span>Total pasivo + patrimonio + resultado del período</span><b data-total="derecha">{{ dinero(t.pasivo_patrimonio_resultado) }}</b></div>
            <div class="total"><span>Total activo</span><b data-total="activo-final">{{ dinero(t.activo) }}</b></div>
            <Message :severity="t.cuadra ? 'success' : 'error'" :closable="false" style="margin-top: 8px;">
              <template v-if="t.cuadra">Cuadra: el activo es igual a la suma del pasivo, el patrimonio y el resultado del período.</template>
              <template v-else>No cuadra: hay una diferencia de {{ dinero(t.diferencia) }} entre el activo y el pasivo más patrimonio más resultado. Revisa los asientos descuadrados en Períodos contables.</template>
            </Message>
            <p class="nota">El resultado del período es lo que ingresos menos gastos suman hasta la fecha y todavía no pasó a patrimonio con el cierre del ejercicio.</p>
          </div>
        </div>
        <p v-else-if="!cargando" class="vacio">Elige el período y pulsa Consultar.</p>
      </TabPanel>

      <!-- Estado de resultados -->
      <TabPanel header="Estado de resultados" value="resultados">
        <div v-if="resultados" data-test="resultados">
          <div class="cabecera">
            <div>
              <div class="titulo">Estado de resultados</div>
              <div class="sub">{{ subtituloResultados }}</div>
            </div>
            <span style="flex: 1;"></span>
            <Button label="Expandir todo" text size="small" @click="expandirTodo(true)" />
            <Button label="Contraer" text size="small" @click="expandirTodo(false)" />
          </div>

          <div v-for="s in resultados.secciones" :key="s.tipo" class="seccion">
            <div class="seccion-titulo">{{ s.titulo }}</div>
            <TreeTable v-if="s.cuentas.length" :value="aTabla(s.cuentas)" v-model:expandedKeys="abiertas[s.tipo]" size="small" :indentation="1.2">
              <Column field="codigo" header="Código" expander style="width: 26%" />
              <Column field="nombre" header="Cuenta" style="width: 46%" />
              <Column header="Saldo" style="width: 28%; text-align: right;">
                <template #body="{ node }">
                  <span :class="{ fuerte: node.data.es_encabezado }" :data-saldo="node.data.codigo">{{ dinero(node.data.saldo) }}</span>
                </template>
              </Column>
            </TreeTable>
            <p v-else class="vacio">Sin movimientos en este período.</p>
            <div class="total"><span>Total {{ s.titulo.toLowerCase() }}</span><b :data-total="s.tipo">{{ dinero(s.total) }}</b></div>
          </div>

          <div class="ecuacion" v-if="r">
            <div class="total resultado" :class="{ perdida: r.resultado < 0 }">
              <span>{{ r.resultado < 0 ? 'Pérdida del período' : 'Utilidad del período' }}</span><b data-total="resultado">{{ dinero(r.resultado) }}</b>
            </div>
            <p class="nota">El asiento de cierre del ejercicio no entra aquí: el año cerrado sigue mostrando lo que se vendió y lo que se gastó.</p>
          </div>
        </div>
        <p v-else-if="!cargando" class="vacio">Elige el período y pulsa Consultar.</p>
      </TabPanel>
    </TabView>
  </div>
</template>

<style scoped>
.ef { padding: 20px; max-width: 980px; }
.ef h2 { margin: 0 0 4px; color: #1f2733; }
.sub { color: #94a3b8; font-size: 13px; margin: 0 0 12px; }
.filtros { border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px 12px; margin: 0 0 12px; }
.filtros legend { font-size: 12px; color: #64748b; padding: 0 6px; }
.fila { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.fila label { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: #475569; }
.cabecera { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
.titulo { font-weight: 700; font-size: 16px; color: #1f2733; }
.cabecera .sub { margin: 0; }
.seccion { margin-bottom: 14px; }
.seccion-titulo { font-weight: 700; color: #1e5bb8; text-transform: uppercase; font-size: 12px; margin-bottom: 4px; letter-spacing: .04em; }
.total { display: flex; justify-content: space-between; padding: 6px 12px; border-top: 2px solid #334155; font-size: 14px; }
.ecuacion { margin-top: 8px; }
.ecuacion .total { border-top: 1px solid #cbd5e1; }
.total.resultado { border-top: 2px solid #166534; color: #166534; font-size: 15px; }
.total.resultado.perdida { border-top-color: #b91c1c; color: #b91c1c; }
.fuerte { font-weight: 700; }
.vacio { color: #94a3b8; text-align: center; padding: 14px; margin: 0; }
.nota { color: #94a3b8; font-size: 12px; margin: 6px 0 0; }
/* La columna Saldo va pegada a la derecha, igual que los totales */
:deep(.p-treetable-tbody > tr > td:last-child .p-treetable-body-cell-content) { justify-content: flex-end; }
:deep(.p-treetable-thead > tr > th:last-child .p-treetable-column-header-content) { justify-content: flex-end; }
</style>
