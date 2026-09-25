<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useAuthStore } from '../stores/auth'
import { useCompanyStore } from '../stores/company'
import { useTabsStore } from '../stores/tabs'
import { usePlanStore } from '../stores/plan'
import Home from '../views/Home.vue'
import SignatureConfig from '../views/SignatureConfig.vue'
import Dashboard from '../views/Dashboard.vue'
import Accounts from '../views/Accounts.vue'
import Placeholder from '../views/Placeholder.vue'
import Contacts from '../views/Contacts.vue'
import Products from '../views/Products.vue'
import Banks from '../views/Banks.vue'
import Pos from '../views/Pos.vue'
import Invoices from '../views/Invoices.vue'
import Purchases from '../views/Purchases.vue'
import Inventory from '../views/Inventory.vue'
import Accounting from '../views/Accounting.vue'
import Cash from '../views/Cash.vue'
import Receivables from '../views/Receivables.vue'
import Payables from '../views/Payables.vue'
import Withholdings from '../views/Withholdings.vue'
import SriDocuments from '../views/SriDocuments.vue'
import Quotes from '../views/Quotes.vue'
import Reconciliation from '../views/Reconciliation.vue'
import Ledger from '../views/Ledger.vue'
import Companies from '../views/Companies.vue'
import Branches from '../views/Branches.vue'
import EmissionPoints from '../views/EmissionPoints.vue'
import Suppliers from '../views/Suppliers.vue'
import InventoryReports from '../views/InventoryReports.vue'
import Series from '../views/Series.vue'
import Users from '../views/Users.vue'
import Audit from '../views/Audit.vue'
import Advances from '../views/Advances.vue'
import CreditNotes from '../views/CreditNotes.vue'
import BatchImport from '../views/BatchImport.vue'
import Employees from '../views/Employees.vue'
import Payroll from '../views/Payroll.vue'
import Warehouses from '../views/Warehouses.vue'
import Taxes from '../views/Taxes.vue'
import InventoryAdjustment from '../views/InventoryAdjustment.vue'
import InventoryTransfer from '../views/InventoryTransfer.vue'
import PurchaseEntry from '../views/PurchaseEntry.vue'
import ReportViewer from '../views/ReportViewer.vue'
import ArticleConversion from '../views/ArticleConversion.vue'
import CardReconciliation from '../views/CardReconciliation.vue'
import MassInvoicing from '../views/MassInvoicing.vue'
import LiquidacionCompra from '../views/LiquidacionCompra.vue'
import NotaDebito from '../views/NotaDebito.vue'
import GuiaRemision from '../views/GuiaRemision.vue'
import Fractionation from '../views/Fractionation.vue'
import StockReservations from '../views/StockReservations.vue'
import SideRail from '../components/SideRail.vue'
import { useUiStore } from '../stores/ui'
import { actionFor, emitShortcut, LAYOUT_ACTIONS } from '../composables/useShortcuts'

const auth = useAuthStore()
const inicial = computed(() => (auth.user?.name ?? 'U').trim().charAt(0).toUpperCase())
const company = useCompanyStore()
const tabs = useTabsStore()
const plan = usePlanStore()
const ui = useUiStore()

const HOME = { key: 'home', label: 'Módulos', icon: 'pi pi-th-large', component: 'Home' }

const maximized = ref(false)

const componentMap: Record<string, any> = {
  Home, SignatureConfig, Dashboard, Accounts, Placeholder, Contacts, Products, Banks, Pos,
  Branches, Invoices, Purchases, PurchaseEntry, Inventory, Accounting, Cash, Receivables, Payables, Withholdings,
  SriDocuments, Quotes, Reconciliation, Ledger, Companies, EmissionPoints, Suppliers, InventoryReports,
  Series, Users, Audit, Advances, CreditNotes, BatchImport, Employees, Payroll, Warehouses,
  Taxes, InventoryAdjustment, InventoryTransfer, ReportViewer, ArticleConversion,
  CardReconciliation, MassInvoicing, LiquidacionCompra, NotaDebito, GuiaRemision,
  Fractionation, StockReservations,
}

onMounted(async () => {
  await auth.fetchUser()
  await company.load()
  if (company.activeId) await plan.load(company.activeId)
  // Al entrar se abre el lanzador de módulos, como el escritorio de un sistema
  // de ventanas. El resumen del negocio es un módulo más dentro del lanzador.
  tabs.open(HOME)
  window.addEventListener('keydown', onKeydown)
})
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && maximized.value) { maximized.value = false; return }

  const hit = actionFor(e)
  if (!hit) return

  // Esc dentro de un campo lo maneja el campo (cierra listas desplegables),
  // no la pantalla: nadie quiere perder el formulario por cerrar un combo.
  const t = e.target as HTMLElement | null
  const escribiendo = !!t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)
  if (hit.action === 'cancelar' && escribiendo) return

  if (LAYOUT_ACTIONS.includes(hit.action)) {
    e.preventDefault()
    atajoDeLayout(hit.action, hit.payload)
    return
  }

  // Solo se le quita el atajo al navegador si alguna pantalla lo está escuchando.
  if (emitShortcut(hit.action, hit.payload)) e.preventDefault()
}

/**
 * Cambiar de empresa tiene que recargar el plan: cada una tiene el suyo, y si no
 * se recarga quedan a la vista módulos que esa empresa no contrató.
 * Las pestañas abiertas son de la empresa anterior, así que se cierran.
 */
async function cambiarEmpresa(id: number) {
  company.setActive(id)
  await plan.load(id)
  tabs.tabs.splice(0)
  tabs.activeKey = null
  tabs.open(HOME)
  nextTick(() => emitShortcut('buscar'))
}

function atajoDeLayout(action: string, payload?: unknown) {
  if (action === 'modulos') {
    tabs.open(HOME)
    // Si ya se estaba en Módulos, abrirlo no reactiva el componente: hay que
    // pedirle el foco del buscador a mano, o las teclas se pierden.
    nextTick(() => emitShortcut('buscar'))
  }
  else if (action === 'cerrar-pestana' && tabs.activeKey) tabs.close(tabs.activeKey)
  else if (action === 'menu-lateral') ui.toggleSidebar()
  else if (action === 'pestana') {
    const t = tabs.tabs[payload as number]
    if (t) tabs.activeKey = t.key
  }
}
</script>

<template>
  <div class="layout" :class="{ maximized }">
    <div class="main">
      <header class="topbar">
        <div class="brand">
          <img src="/logo-marca-blanca.png" alt="" class="hr-logo" />
          <span class="hr-wordmark">HasReset</span>
        </div>
        <Button label="Módulos" icon="pi pi-th-large" text size="small"
                @click="tabs.open(HOME)" />
        <Select
          v-model="company.activeId"
          :options="company.companies"
          :optionLabel="(c) => c.razon_social || 'Empresa sin configurar'"
          optionValue="id"
          placeholder="Empresa"
          class="company-select"
          @change="(e) => cambiarEmpresa(e.value)"
        />
        <div class="topbar-right">
          <Tag v-if="plan.vencido" value="Plan vencido" severity="danger" />
          <span class="user">
            <span class="avatar">{{ inicial }}</span>
            {{ auth.user?.name ?? 'Usuario' }}
          </span>
          <Button icon="pi pi-sign-out" text rounded severity="secondary"
                  v-tooltip.bottom="'Cerrar sesión'" @click="auth.logout()" />
        </div>
      </header>

      <div class="body">
        <SideRail v-if="ui.sidebar" />
        <div class="workspace">
        <div v-if="tabs.tabs.length" class="tabbar">
          <button
            v-for="t in tabs.tabs"
            :key="t.key"
            class="worktab"
            :class="{ active: tabs.activeKey === t.key }"
            @click="tabs.activeKey = t.key"
          >
            <i :class="t.icon" />
            <span>{{ t.label }}</span>
            <i class="pi pi-times close" @click.stop="tabs.close(t.key)" />
          </button>
          <button
            class="maximizar"
            :title="maximized ? 'Restaurar (Esc)' : 'Maximizar la ventana de trabajo'"
            @click="maximized = !maximized"
          >
            <i :class="maximized ? 'pi pi-window-minimize' : 'pi pi-window-maximize'" />
          </button>
        </div>

        <div class="tabcontent">
          <template v-for="t in tabs.tabs" :key="t.key">
            <KeepAlive>
              <component v-if="tabs.activeKey === t.key" :is="componentMap[t.component] ?? Placeholder"
                         :titulo="t.label" />
            </KeepAlive>
          </template>
          <div v-if="!tabs.tabs.length" class="empty">
            Abrá un módulo del menú para empezar.
          </div>
        </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.layout { display: flex; height: 100vh; overflow: hidden; }
.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
/* La marca vive en la barra superior; el menú lateral global vive en .body */
.topbar {
  height: 56px; background: var(--hr-navy); border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  display: flex; align-items: center; gap: 8px; padding: 0 18px; flex-shrink: 0;
}
/* La marca se separa del resto con una línea, no con aire suelto */
.brand {
  display: flex; align-items: center; height: 28px;
  padding-right: 16px; margin-right: 6px;
  border-right: 1px solid rgba(255, 255, 255, 0.14);
}
.brand { gap: 9px; }
.hr-logo { height: 24px; width: auto; object-fit: contain; }
.hr-wordmark {
  color: #fff; font-size: 15px; font-weight: 600; letter-spacing: -0.01em;
}

/* Botón de módulos: mismo lenguaje que el resto de la barra oscura */
.topbar :deep(.p-button-text) { color: #c8d3e2; font-weight: 500; }
.topbar :deep(.p-button-text:hover) { background: rgba(255, 255, 255, 0.09); color: #fff; }

/* El selector de empresa era una píldora blanca que partía la barra en dos.
   Ahora es un campo oscuro que se funde con el fondo. */
.company-select { min-width: 300px; }
.topbar :deep(.company-select) {
  background: rgba(255, 255, 255, 0.07);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 8px; box-shadow: none;
}
.topbar :deep(.company-select:hover) {
  background: rgba(255, 255, 255, 0.11); border-color: rgba(255, 255, 255, 0.28);
}
.topbar :deep(.company-select.p-focus) {
  border-color: var(--hr-green, #38bdf8);
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
}
.topbar :deep(.company-select .p-select-label) {
  color: #fff; font-size: 13px; font-weight: 500; padding: 7px 4px 7px 12px;
}
.topbar :deep(.company-select .p-select-dropdown) { color: #8fa3bd; width: 32px; }

.topbar-right { display: flex; align-items: center; gap: 10px; margin-left: auto; }
.topbar .user { display: flex; align-items: center; gap: 9px; color: #cdd5e0; font-size: 13px; }
.avatar {
  width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center;
  background: rgba(255, 255, 255, 0.13); color: #fff; font-size: 12px; font-weight: 600;
}
.user { font-size: 13px; color: #475569; }
.workspace { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #eef1f5; }
.body { flex: 1; display: flex; overflow: hidden; }
.tabbar {
  --tab-accent: #1e5bb8;
  display: flex; gap: 3px; background: #e4e9f1; padding: 7px 10px 0;
  flex-shrink: 0; align-items: stretch; min-height: 42px;
  /* overflow-y hidden: sin esto, la barra de scroll corta las pestañas por la mitad */
  overflow-x: auto; overflow-y: hidden;
  scrollbar-width: thin; scrollbar-color: #b8c0cc transparent;
}
.tabbar::-webkit-scrollbar { height: 4px; }
.tabbar::-webkit-scrollbar-thumb { background: #b8c0cc; border-radius: 2px; }
.tabbar::-webkit-scrollbar-track { background: transparent; }
.worktab {
  position: relative;
  display: flex; align-items: center; gap: 8px; padding: 8px 11px 9px; border: 0;
  background: transparent; color: #5a6675; border-radius: 8px 8px 0 0; cursor: pointer;
  font-size: 13px; white-space: nowrap; transition: background 0.12s, color 0.12s;
  /* Más compactas → entran más antes de scrollear; al mínimo, la barra scrollea. */
  flex: 0 1 auto; min-width: 104px; max-width: 178px;
}
.worktab:hover { background: #eef2f8; color: #1f2733; }
.worktab > span {
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;
}
.worktab > i:first-child { flex-shrink: 0; font-size: 13px; opacity: 0.75; }
.worktab.active {
  background: #fff; color: #16202e; font-weight: 600;
  box-shadow: inset 0 3px 0 var(--tab-accent);
}
.worktab.active > i:first-child { opacity: 1; color: var(--tab-accent); }
.worktab .close {
  flex-shrink: 0; font-size: 11px; opacity: 0; border-radius: 4px; padding: 2px;
  transition: opacity 0.12s, background 0.12s;
}
.worktab:hover .close, .worktab.active .close { opacity: 0.5; }
.worktab .close:hover { opacity: 1 !important; color: #d93025; background: #fbe3e1; }
.tabcontent { flex: 1; overflow: auto; background: #fff; }
.empty { padding: 60px; text-align: center; color: #94a3b8; }

/* Sticky: con muchas pestañas abiertas sigue visible a la derecha */
.maximizar {
  margin-left: auto; align-self: center; border: 0; flex-shrink: 0;
  color: #64748b; cursor: pointer; padding: 6px 8px; border-radius: 6px;
  position: sticky; right: 0; background: #dde2ea;
  box-shadow: -8px 0 8px -3px rgba(0, 0, 0, 0.1);
}
.maximizar:hover { background: #eef1f5; color: #1f2733; }

.layout.maximized .topbar { display: none; }
.layout.maximized .body :deep(.rail) { display: none; }
</style>
