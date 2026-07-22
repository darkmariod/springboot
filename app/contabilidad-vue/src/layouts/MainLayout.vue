<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
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

const auth = useAuthStore()
const company = useCompanyStore()
const tabs = useTabsStore()
const plan = usePlanStore()

const maximized = ref(false)

const componentMap: Record<string, any> = {
  Home, SignatureConfig, Dashboard, Accounts, Placeholder, Contacts, Products, Banks, Pos,
  Invoices, Purchases, PurchaseEntry, Inventory, Accounting, Cash, Receivables, Payables, Withholdings,
  SriDocuments, Quotes, Reconciliation, Ledger, Companies, EmissionPoints, Suppliers, InventoryReports,
  Series, Users, Audit, Advances, CreditNotes, BatchImport, Employees, Payroll, Warehouses,
  Taxes, InventoryAdjustment, InventoryTransfer, ReportViewer, ArticleConversion,
  CardReconciliation, MassInvoicing,
}

onMounted(async () => {
  await auth.fetchUser()
  await company.load()
  if (company.activeId) await plan.load(company.activeId)
  // Al entrar se abre el lanzador de módulos (tiles, como KVS)
  tabs.open({ key: 'home', label: 'Módulos', icon: 'pi pi-th-large', component: 'Home' })
  window.addEventListener('keydown', onKeydown)
})
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && maximized.value) { maximized.value = false; return }
  if (!(e.ctrlKey || e.metaKey)) return
  const k = e.key.toLowerCase()
  if (k === 'w' && tabs.activeKey) { e.preventDefault(); tabs.close(tabs.activeKey) }
  else if (k === 'p') { e.preventDefault(); window.print() }
  else if (k === 'f') { e.preventDefault(); document.querySelector<HTMLInputElement>('.p-datatable input')?.focus() }
}
</script>

<template>
  <div class="layout" :class="{ maximized }">
    <div class="main">
      <header class="topbar">
        <div class="brand">
          <img src="/logo-has-reset.png" alt="HasReset" class="hr-logo" />
        </div>
        <Button label="Módulos" icon="pi pi-th-large" text size="small"
                @click="tabs.open({ key: 'home', label: 'Módulos', icon: 'pi pi-th-large', component: 'Home' })" />
        <Select
          v-model="company.activeId"
          :options="company.companies"
          optionLabel="razon_social"
          optionValue="id"
          placeholder="Empresa"
          class="company-select"
          @change="(e) => company.setActive(e.value)"
        />
        <div class="topbar-right">
          <Tag v-if="plan.vencido" value="Plan vencido" severity="danger" />
          <span class="user">{{ auth.user?.name ?? 'Usuario' }}</span>
          <Button icon="pi pi-sign-out" text rounded severity="secondary" @click="auth.logout()" />
        </div>
      </header>

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
              <component :is="componentMap[t.component]" v-show="tabs.activeKey === t.key" />
            </KeepAlive>
          </template>
          <div v-if="!tabs.tabs.length" class="empty">
            Abrá un módulo del menú para empezar.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.layout { display: flex; height: 100vh; overflow: hidden; }
.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
/* Sin menú lateral (como KVS): la marca vive en la barra superior */
.topbar {
  height: 52px; background: var(--hr-navy); border-bottom: 1px solid var(--hr-blue-dark); display: flex;
  align-items: center; gap: 14px; padding: 0 16px; flex-shrink: 0;
}
.brand {
  display: flex; align-items: center; gap: 10px; font-weight: 600; color: #fff;
  white-space: nowrap;
}
.hr-logo { width: 40px; height: auto; object-fit: contain; }
.company-select { min-width: 260px; margin-left: 8px; }
.topbar-right { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.topbar .user { color: #cdd5e0; }
.user { font-size: 13px; color: #475569; }
.workspace { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #eef1f5; }
.tabbar {
  display: flex; gap: 2px; background: #dde2ea; padding: 6px 8px 0;
  flex-shrink: 0; align-items: stretch; min-height: 40px;
  /* overflow-y hidden: sin esto, la barra de scroll corta las pestañas por la mitad */
  overflow-x: auto; overflow-y: hidden;
  scrollbar-width: thin; scrollbar-color: #b8c0cc transparent;
}
.tabbar::-webkit-scrollbar { height: 4px; }
.tabbar::-webkit-scrollbar-thumb { background: #b8c0cc; border-radius: 2px; }
.tabbar::-webkit-scrollbar-track { background: transparent; }
.worktab {
  display: flex; align-items: center; gap: 7px; padding: 7px 12px; border: 0;
  background: #eef1f5; color: #64748b; border-radius: 6px 6px 0 0; cursor: pointer;
  font-size: 13px; white-space: nowrap;
  /* Se achican en vez de desbordarse, pero nunca por debajo de lo legible:
     al llegar al mínimo, la barra scrollea. */
  flex: 0 1 auto; min-width: 128px; max-width: 190px;
}
.worktab > span {
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.worktab > i { flex-shrink: 0; }
.worktab.active { background: #fff; color: #1f2733; font-weight: 600; }
.worktab .close { font-size: 11px; opacity: 0.6; }
.worktab .close:hover { opacity: 1; color: #d93025; }
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
</style>
