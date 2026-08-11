<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useTabsStore } from '../stores/tabs'

interface Resumen {
  periodo: { desde: string; hasta: string }
  ventas_mes: number
  ventas_mes_anterior: number
  ventas_variacion_pct: number | null
  cobrado_mes: number
  cobrado_pct: number | null
  por_cobrar: number
  facturas_por_cobrar: number
  ventas_serie: { fecha: string; total: number }[]
  documentos: { tipo: string; numero: string; cliente: string; valor: number; estado_sri: { label: string; chip: string }; fecha: string }[]
  acciones: { key: string; etiqueta: string; cantidad: number }[]
  actividad: { tipo: string; texto: string; detalle: string; cuando: string; usuario: string }[]
}

const company = useCompanyStore()
const tabs = useTabsStore()

const resumen = ref<Resumen | null>(null)
const loading = ref(true)
const error = ref(false)
const tema = ref<'auto' | 'light' | 'dark'>('auto')

const empresa = computed(() => company.companies.find((c) => c.id === company.activeId))

const periodo = computed(() => {
  if (!resumen.value) return ''
  const d = new Date(resumen.value.periodo.desde + 'T00:00:00')
  return d.toLocaleDateString('es-EC', { month: 'long', year: 'numeric' })
})

async function cargar() {
  loading.value = true
  error.value = false
  try {
    if (!company.activeId) return
    const { data } = await api.get(`/dashboard/resumen?company_id=${company.activeId}`)
    resumen.value = data
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}
onMounted(cargar)
// Si cambian de empresa con la pestaña abierta, recargar el resumen
watch(() => company.activeId, () => { if (resumen.value) cargar() })

const money = (n: number | null | undefined) =>
  '$' + Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const variacionInfo = computed(() => {
  const v = resumen.value?.ventas_variacion_pct
  if (v === null || v === undefined) return { clase: 'flat', icono: 'pi pi-minus', texto: 'vs mes anterior' }
  if (v > 0) return { clase: 'up', icono: 'pi pi-arrow-up-right', texto: `${v.toFixed(1)}% vs mes anterior` }
  if (v < 0) return { clase: 'down', icono: 'pi pi-arrow-down-right', texto: `${v.toFixed(1)}% vs mes anterior` }
  return { clase: 'flat', icono: 'pi pi-minus', texto: 'sin cambios vs mes anterior' }
})

const seriePath = computed(() => {
  const vals = (resumen.value?.ventas_serie ?? []).map((p) => Number(p.total) || 0)
  const n = vals.length
  if (n < 2) return null
  const max = Math.max(...vals, 1)
  const w = 120, h = 34, pad = 2
  const pts = vals.map((v, i) => {
    const x = pad + (i * (w - pad * 2)) / (n - 1)
    const y = h - pad - (v / max) * (h - pad * 2)
    return [x, y] as const
  })
  const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const area = `${line} L${(w - pad).toFixed(1)},${h} L${pad.toFixed(1)},${h} Z`
  return { line, area }
})

function abrir(tab: { key: string; label: string; icon: string; component: string }) {
  tabs.open(tab)
}

const destinoAccion: Record<string, { key: string; label: string; icon: string; component: string }> = {
  sri: { key: 'documents', label: 'Documentos SRI', icon: 'pi pi-check-square', component: 'SriDocuments' },
  conciliaciones: { key: 'reconciliation', label: 'Conciliación bancaria', icon: 'pi pi-sync', component: 'Reconciliation' },
  vencidas: { key: 'receivables', label: 'Cuentas por cobrar', icon: 'pi pi-wallet', component: 'Receivables' },
}

const chipIcono: Record<string, string> = { good: 'pi pi-check-circle', warn: 'pi pi-clock', crit: 'pi pi-exclamation-circle' }
const chipClase: Record<string, string> = { good: 'chip-good', warn: 'chip-warn', crit: 'chip-crit' }

const tipoActividad: Record<string, { icono: string; clase: string }> = {
  sri: { icono: 'pi pi-check-circle', clase: 'act-sri' },
  cobro: { icono: 'pi pi-arrow-down', clase: 'act-cobro' },
  inventario: { icono: 'pi pi-box', clase: 'act-inv' },
}

function tiempoRelativo(iso: string) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const s = Math.floor((Date.now() - d.getTime()) / 1000)
  if (s < 60) return 'ahora mismo'
  const m = Math.floor(s / 60)
  if (m < 60) return `hace ${m} min`
  const h = Math.floor(m / 60)
  if (h < 24) return `hace ${h} h`
  const dias = Math.floor(h / 24)
  if (dias < 7) return `hace ${dias} d`
  return d.toLocaleDateString('es-EC', { day: '2-digit', month: 'short' })
}

function toggleTema() {
  tema.value = tema.value === 'auto' ? 'light' : tema.value === 'light' ? 'dark' : 'auto'
}
const temaIcono = computed(() => (tema.value === 'auto' ? 'pi pi-circle' : tema.value === 'light' ? 'pi pi-sun' : 'pi pi-moon'))
const temaTexto = computed(() => (tema.value === 'auto' ? 'Auto' : tema.value === 'light' ? 'Claro' : 'Oscuro'))
</script>

<template>
  <div class="dash" :data-theme="tema">
    <main class="main">
      <header class="head">
        <div>
          <div class="crumb"><i class="pi pi-home" /> Inicio</div>
          <h1 class="page-title">Resumen del negocio</h1>
          <p class="page-sub">
            {{ periodo }}
            <template v-if="empresa"> · {{ empresa.razon_social }} · RUC {{ empresa.ruc }}</template>
          </p>
        </div>
        <button class="theme-btn" :title="'Tema: ' + temaTexto" @click="toggleTema">
          <i :class="temaIcono" /> {{ temaTexto }}
        </button>
      </header>

      <div v-if="error" class="error-panel">
        <i class="pi pi-exclamation-triangle" />
        <p>No se pudo cargar el resumen del negocio.</p>
        <button class="retry" @click="cargar"><i class="pi pi-refresh" /> Reintentar</button>
      </div>

      <template v-else>
        <section class="kpis">
          <div class="kpi">
            <span class="kpi-label">Ventas del mes</span>
            <div v-if="loading" class="sk sk-lg" />
            <div v-else class="kpi-row">
              <div class="kpi-cols">
                <b class="kpi-num">{{ money(resumen?.ventas_mes) }}</b>
                <span class="chip" :class="'chip-' + variacionInfo.clase">
                  <i :class="variacionInfo.icono" /> {{ variacionInfo.texto }}
                </span>
              </div>
              <svg v-if="seriePath" class="spark" viewBox="0 0 120 34" preserveAspectRatio="none" aria-hidden="true">
                <path :d="seriePath.area" class="spark-area" />
                <path :d="seriePath.line" class="spark-line" />
              </svg>
            </div>
          </div>

          <div class="kpi">
            <span class="kpi-label">Cobrado del mes</span>
            <div v-if="loading" class="sk sk-lg" />
            <div v-else class="kpi-row">
              <b class="kpi-num">{{ money(resumen?.cobrado_mes) }}</b>
              <span v-if="resumen?.cobrado_pct != null" class="chip chip-info">
                <i class="pi pi-percentage" /> {{ resumen.cobrado_pct.toFixed(1) }}% de lo facturado
              </span>
            </div>
          </div>

          <div class="kpi kpi-accent">
            <span class="kpi-label">Por cobrar</span>
            <div v-if="loading" class="sk sk-lg" />
            <div v-else class="kpi-row">
              <b class="kpi-num">{{ money(resumen?.por_cobrar) }}</b>
              <button class="chip chip-link" @click="abrir(destinoAccion.vencidas)">
                <i class="pi pi-wallet" /> {{ resumen?.facturas_por_cobrar ?? 0 }} facturas abiertas
              </button>
            </div>
          </div>
        </section>

        <div class="grid-2">
          <section class="panel">
            <div class="panel-head">
              <h3><i class="pi pi-file" /> Documentos recientes</h3>
              <button class="link-btn" @click="abrir({ key: 'invoices', label: 'Facturas', icon: 'pi pi-file', component: 'Invoices' })">
                Ver todo
              </button>
            </div>
            <div v-if="loading">
              <div v-for="i in 4" :key="i" class="sk sk-row" />
            </div>
            <table v-else-if="resumen?.documentos.length" class="doc-table">
              <thead>
                <tr><th>Fecha</th><th>N°</th><th>Cliente</th><th class="r">Valor</th><th>Estado</th></tr>
              </thead>
              <tbody>
                <tr v-for="d in resumen.documentos" :key="d.numero + d.fecha">
                  <td class="muted">{{ d.fecha }}</td>
                  <td><b>{{ d.numero }}</b></td>
                  <td class="cell-cliente">{{ d.cliente }}</td>
                  <td class="r num">{{ money(d.valor) }}</td>
                  <td>
                    <span class="chip" :class="chipClase[d.estado_sri.chip] || 'chip-warn'">
                      <i :class="chipIcono[d.estado_sri.chip] || 'pi pi-clock'" /> {{ d.estado_sri.label }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-else class="empty"><i class="pi pi-inbox" /> Aún no hay facturas registradas.</div>
          </section>

          <section class="panel">
            <div class="panel-head"><h3><i class="pi pi-list-check" /> Acciones pendientes</h3></div>
            <div v-if="loading">
              <div v-for="i in 3" :key="i" class="sk sk-row" />
            </div>
            <div v-else class="acciones">
              <button v-for="a in resumen?.acciones ?? []" :key="a.key" class="accion" @click="abrir(destinoAccion[a.key])">
                <span class="accion-ico" :class="'acc-' + a.key"><i :class="destinoAccion[a.key]?.icon" /></span>
                <span class="accion-label">{{ a.etiqueta }}</span>
                <span class="badge" :class="{ 'badge-zero': !a.cantidad }">{{ a.cantidad }}</span>
                <i class="pi pi-chevron-right accion-go" />
              </button>
            </div>
          </section>
        </div>

        <section class="panel feed">
          <div class="panel-head"><h3><i class="pi pi-history" /> Actividad reciente</h3></div>
          <div v-if="loading">
            <div v-for="i in 4" :key="i" class="sk sk-row" />
          </div>
          <div v-else-if="resumen?.actividad.length" class="feed-list">
            <div v-for="(a, i) in resumen.actividad" :key="i" class="feed-item">
              <span class="feed-dot" :class="tipoActividad[a.tipo]?.clase ?? 'act-inv'">
                <i :class="tipoActividad[a.tipo]?.icono ?? 'pi pi-box'" />
              </span>
              <div class="feed-body">
                <span class="feed-text">{{ a.texto }}</span>
                <span class="feed-meta">{{ a.detalle }} · {{ tiempoRelativo(a.cuando) }} · {{ a.usuario }}</span>
              </div>
            </div>
          </div>
          <div v-else class="empty"><i class="pi pi-inbox" /> Sin actividad reciente.</div>
        </section>
      </template>
    </main>
  </div>
</template>

<style scoped>
.dash {
  --ground: #eef1f5;
  --panel: #ffffff;
  --border: #e2e5ea;
  --ink: #1f2733;
  --muted: #64748b;
  --faint: #94a3b8;
  --hover: #f3f6fa;
  --good: #16a34a; --good-bg: #eaf7ee;
  --warn: #d97706; --warn-bg: #fff6e6;
  --crit: #d93025; --crit-bg: #fdeceb;
  --info: #2563eb; --info-bg: #eaf1fe;
  display: grid;
  grid-template-columns: 1fr;
  min-height: 100%;
  background: var(--ground);
  color: var(--ink);
  font-size: 14px;
}
.dash[data-theme='dark'] {
  --ground: #0b1220;
  --panel: #131c2b;
  --border: #233046;
  --ink: #e7ecf3;
  --muted: #93a3b8;
  --faint: #5d6b82;
  --hover: #1a2436;
  --good-bg: #122a1a;
  --warn-bg: #2a2110;
  --crit-bg: #2b1514;
  --info-bg: #14243f;
}
@media (prefers-color-scheme: dark) {
  .dash:not([data-theme='light']):not([data-theme='dark']) {
    --ground: #0b1220;
    --panel: #131c2b;
    --border: #233046;
    --ink: #e7ecf3;
    --muted: #93a3b8;
    --faint: #5d6b82;
    --hover: #1a2436;
    --good-bg: #122a1a;
    --warn-bg: #2a2110;
    --crit-bg: #2b1514;
    --info-bg: #14243f;
  }
}

/* ── Contenido ───────────────────────────────────── */
.main { padding: 22px 24px 40px; overflow: hidden; }
.head {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;
  margin-bottom: 18px;
}
.crumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; color: var(--faint); margin-bottom: 2px;
}
.crumb i { font-size: 11px; }
.page-title { margin: 0; font-size: 21px; font-weight: 700; }
.page-sub { margin: 3px 0 0; font-size: 13px; color: var(--muted); }
.theme-btn {
  display: inline-flex; align-items: center; gap: 7px;
  border: 1px solid var(--border); background: var(--panel); color: var(--muted);
  padding: 7px 12px; border-radius: 8px; font-size: 12.5px; cursor: pointer;
  flex-shrink: 0;
}
.theme-btn:hover { color: var(--ink); border-color: var(--faint); }

/* ── KPIs ────────────────────────────────────────── */
.kpis {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
  margin-bottom: 16px;
}
.kpi {
  background: var(--panel); border: 1px solid var(--border); border-radius: 12px;
  padding: 16px 16px 14px; min-height: 128px; box-shadow: 0 1px 2px rgba(15, 39, 68, 0.05);
}
.kpi-accent { border-left: 4px solid var(--warn); }
.kpi-label {
  font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.5px;
  color: var(--faint); font-weight: 700;
}
.kpi-row { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
.kpi-cols { display: flex; flex-direction: column; gap: 6px; }
.kpi-num {
  font-size: 26px; font-weight: 700; letter-spacing: -0.3px;
  font-variant-numeric: tabular-nums; line-height: 1.15;
}
.spark { width: 100%; height: 34px; display: block; margin-top: 2px; }
.spark-line { fill: none; stroke: var(--good); stroke-width: 1.8; stroke-linecap: round; }
.spark-area { fill: var(--good); opacity: 0.1; }

/* ── Chips ───────────────────────────────────────── */
.chip {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 999px;
  width: fit-content; white-space: nowrap;
}
.chip-up { color: var(--good); background: var(--good-bg); }
.chip-down { color: var(--crit); background: var(--crit-bg); }
.chip-flat { color: var(--muted); background: var(--hover); }
.chip-info { color: var(--info); background: var(--info-bg); }
.chip-good { color: var(--good); background: var(--good-bg); }
.chip-warn { color: var(--warn); background: var(--warn-bg); }
.chip-crit { color: var(--crit); background: var(--crit-bg); }
.chip-link { border: 0; cursor: pointer; color: var(--info); background: var(--info-bg); }
.chip-link:hover { filter: brightness(0.95); }

/* ── Paneles ─────────────────────────────────────── */
.grid-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 14px; margin-bottom: 16px; }
.panel {
  background: var(--panel); border: 1px solid var(--border); border-radius: 12px;
  padding: 16px; box-shadow: 0 1px 2px rgba(15, 39, 68, 0.05);
}
.panel-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 10px;
}
.panel-head h3 { margin: 0; font-size: 14.5px; display: flex; align-items: center; gap: 8px; }
.panel-head h3 i { color: var(--info); font-size: 14px; }
.link-btn {
  border: 0; background: none; color: var(--info); font-size: 12.5px; font-weight: 600;
  cursor: pointer;
}
.link-btn:hover { text-decoration: underline; }

/* ── Tabla de documentos ─────────────────────────── */
.doc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.doc-table th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
  color: var(--faint); font-weight: 700; padding: 6px 8px; border-bottom: 1px solid var(--border);
}
.doc-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); }
.doc-table tbody tr:last-child td { border-bottom: 0; }
.doc-table tbody tr:hover { background: var(--hover); }
.doc-table .r { text-align: right; }
.doc-table .num { font-variant-numeric: tabular-nums; font-weight: 600; }
.cell-cliente { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.muted { color: var(--muted); }

/* ── Acciones pendientes ─────────────────────────── */
.acciones { display: flex; flex-direction: column; gap: 6px; }
.accion {
  display: flex; align-items: center; gap: 11px;
  width: 100%; padding: 9px 10px; border: 0; border-radius: 9px;
  background: transparent; color: var(--ink); font-size: 13px; text-align: left;
  cursor: pointer;
}
.accion:hover { background: var(--hover); }
.accion-ico {
  width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center; font-size: 14px;
}
.acc-sri { color: var(--warn); background: var(--warn-bg); }
.acc-conciliaciones { color: var(--info); background: var(--info-bg); }
.acc-vencidas { color: var(--crit); background: var(--crit-bg); }
.accion-label { flex: 1; }
.badge {
  min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--info); color: #fff; font-size: 12px; font-weight: 700;
}
.badge-zero { background: var(--hover); color: var(--faint); }
.accion-go { color: var(--faint); font-size: 11px; }

/* ── Actividad ───────────────────────────────────── */
.feed { margin-bottom: 0; }
.feed-list { display: flex; flex-direction: column; }
.feed-item { display: flex; gap: 12px; padding: 10px 4px; border-bottom: 1px solid var(--border); }
.feed-item:last-child { border-bottom: 0; }
.feed-dot {
  width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center; font-size: 13px;
}
.act-sri { color: var(--good); background: var(--good-bg); }
.act-cobro { color: var(--info); background: var(--info-bg); }
.act-inv { color: var(--warn); background: var(--warn-bg); }
.feed-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.feed-text { font-size: 13.5px; }
.feed-meta { font-size: 12px; color: var(--faint); }

/* ── Estados vacíos / error ──────────────────────── */
.empty {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 26px 10px; color: var(--faint); font-size: 13px; text-align: center;
}
.empty i { font-size: 22px; }
.error-panel {
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  padding: 60px 20px; color: var(--crit); text-align: center;
}
.error-panel i { font-size: 30px; }
.error-panel p { margin: 0; color: var(--muted); }
.retry {
  display: inline-flex; align-items: center; gap: 7px;
  border: 1px solid var(--border); background: var(--panel); color: var(--ink);
  padding: 8px 14px; border-radius: 8px; font-size: 13px; cursor: pointer;
}
.retry:hover { border-color: var(--info); color: var(--info); }

/* ── Skeleton (shimmer CSS) ──────────────────────── */
.sk {
  background: linear-gradient(90deg, var(--border) 25%, var(--hover) 50%, var(--border) 75%);
  background-size: 200% 100%; animation: shim 1.2s infinite; border-radius: 6px;
}
.sk-lg { height: 26px; width: 170px; margin-top: 8px; }
.sk-row { height: 18px; margin: 11px 0; }
@keyframes shim {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ── Responsive ──────────────────────────────────── */
@media (max-width: 1080px) {
  .grid-2 { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
  .main { padding: 16px; }
  .kpis { grid-template-columns: 1fr; }
}
</style>
