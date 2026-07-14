<script setup lang="ts">
import { onMounted, onUnmounted, computed } from 'vue'
import Menu from 'primevue/menu'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { useAuthStore } from '../stores/auth'
import { useCompanyStore } from '../stores/company'
import { useTabsStore, type WorkTab } from '../stores/tabs'
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

const auth = useAuthStore()
const company = useCompanyStore()
const tabs = useTabsStore()

// Mapa nombre → componente para renderizar cada pestaña.
const componentMap: Record<string, any> = {
  Dashboard, Accounts, Placeholder, Contacts, Products, Banks, Pos, Invoices, Purchases,
  Inventory, Accounting, Cash,
}

// Módulos del menú lateral, alineados con lo que pidió la contadora (videos KVS/MicroPlus).
// Cada uno abre una pestaña de trabajo. Los que dicen 'Placeholder' se construyen por fase.
const modules: { label: string; items: WorkTab[] }[] = [
  {
    label: 'Principal',
    items: [{ key: 'dashboard', label: 'Dashboard', icon: 'pi pi-home', component: 'Dashboard' }],
  },
  {
    label: 'Catálogo',
    items: [
      { key: 'contacts', label: 'Contactos', icon: 'pi pi-users', component: 'Contacts' },
      { key: 'products', label: 'Productos y servicios', icon: 'pi pi-box', component: 'Products' },
      { key: 'accounts', label: 'Plan de cuentas', icon: 'pi pi-sitemap', component: 'Accounts' },
    ],
  },
  {
    label: 'Ventas',
    items: [
      { key: 'pos', label: 'Punto de Venta', icon: 'pi pi-shopping-cart', component: 'Pos' },
      { key: 'invoices', label: 'Facturas', icon: 'pi pi-file', component: 'Invoices' },
      { key: 'quotes', label: 'Cotizaciones', icon: 'pi pi-file-edit', component: 'Placeholder' },
      { key: 'receivables', label: 'Cuentas por cobrar', icon: 'pi pi-wallet', component: 'Placeholder' },
      { key: 'sales-ret', label: 'Retenciones recibidas', icon: 'pi pi-percentage', component: 'Placeholder' },
      { key: 'documents', label: 'Documentos SRI', icon: 'pi pi-check-square', component: 'Placeholder' },
    ],
  },
  {
    label: 'Compras',
    items: [
      { key: 'purchases', label: 'Compras', icon: 'pi pi-shopping-bag', component: 'Purchases' },
      { key: 'suppliers', label: 'Proveedores', icon: 'pi pi-truck', component: 'Placeholder' },
      { key: 'payables', label: 'Cuentas por pagar', icon: 'pi pi-credit-card', component: 'Placeholder' },
    ],
  },
  {
    label: 'Inventario',
    items: [{ key: 'inventory', label: 'Inventario y kardex', icon: 'pi pi-database', component: 'Inventory' }],
  },
  {
    label: 'Caja y Bancos',
    items: [
      { key: 'cash', label: 'Caja', icon: 'pi pi-money-bill', component: 'Cash' },
      { key: 'banks', label: 'Bancos', icon: 'pi pi-building-columns', component: 'Banks' },
      { key: 'reconciliation', label: 'Conciliación bancaria', icon: 'pi pi-sync', component: 'Placeholder' },
    ],
  },
  {
    label: 'Contabilidad',
    items: [
      { key: 'journal', label: 'Libro diario', icon: 'pi pi-book', component: 'Accounting' },
      { key: 'ledger', label: 'Libro mayor', icon: 'pi pi-list', component: 'Placeholder' },
      { key: 'statements', label: 'Estados financieros', icon: 'pi pi-chart-line', component: 'Placeholder' },
    ],
  },
  {
    label: 'Nómina',
    items: [{ key: 'payroll', label: 'Roles de pago', icon: 'pi pi-id-card', component: 'Placeholder' }],
  },
  {
    label: 'Reportes',
    items: [{ key: 'reports', label: 'Reportes', icon: 'pi pi-chart-bar', component: 'Placeholder' }],
  },
  {
    label: 'Administración',
    items: [
      { key: 'companies', label: 'Empresas', icon: 'pi pi-building', component: 'Placeholder' },
      { key: 'emission', label: 'Puntos de emisión', icon: 'pi pi-hashtag', component: 'Placeholder' },
      { key: 'users', label: 'Usuarios y roles', icon: 'pi pi-shield', component: 'Placeholder' },
      { key: 'audit', label: 'Auditoría', icon: 'pi pi-history', component: 'Placeholder' },
    ],
  },
]

// Formato para el PanelMenu de PrimeVue.
const menuModel = computed(() =>
  modules.map((group) => ({
    label: group.label,
    items: group.items.map((it) => ({
      label: it.label,
      icon: it.icon,
      command: () => tabs.open(it),
    })),
  })),
)

onMounted(async () => {
  await auth.fetchUser()
  await company.load()
  tabs.open(modules[0].items[0]) // Abre el Dashboard por defecto.
  window.addEventListener('keydown', onKeydown)
})
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

// Atajos de teclado tipo escritorio. Ctrl+W solo se captura confiable como PWA instalada;
// en pestaña normal del navegador, Ctrl+W lo reserva el navegador. Ctrl+P y Ctrl+F sí funcionan.
function onKeydown(e: KeyboardEvent) {
  if (!(e.ctrlKey || e.metaKey)) return
  const k = e.key.toLowerCase()
  if (k === 'w' && tabs.activeKey) { e.preventDefault(); tabs.close(tabs.activeKey) }
  else if (k === 'p') { e.preventDefault(); window.print() }
  else if (k === 'f') { e.preventDefault(); document.querySelector<HTMLInputElement>('.p-datatable input')?.focus() }
}
</script>

<template>
  <div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="brand">
        <i class="pi pi-calculator" />
        <span>Sistema Contable</span>
      </div>
      <Menu :model="menuModel" class="sidebar-menu" />
    </aside>

    <!-- Columna principal -->
    <div class="main">
      <!-- Topbar -->
      <header class="topbar">
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
          <span class="user">{{ auth.user?.name ?? 'Usuario' }}</span>
          <Button icon="pi pi-sign-out" text rounded severity="secondary" @click="auth.logout()" />
        </div>
      </header>

      <!-- Pestañas de trabajo (multi-ventana) -->
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
        </div>

        <div class="tabcontent">
          <template v-for="t in tabs.tabs" :key="t.key">
            <KeepAlive>
              <component :is="componentMap[t.component]" v-show="tabs.activeKey === t.key" />
            </KeepAlive>
          </template>
          <div v-if="!tabs.tabs.length" class="empty">
            Abrí un módulo del menú para empezar.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.layout { display: flex; height: 100vh; overflow: hidden; }
.sidebar {
  width: 232px; background: #1f2733; color: #cdd5e0; display: flex; flex-direction: column;
  flex-shrink: 0;
}
.brand {
  display: flex; align-items: center; gap: 10px; padding: 16px 18px; font-weight: 600;
  color: #fff; border-bottom: 1px solid #2c3644;
}
.sidebar-menu { flex: 1; overflow-y: auto; border: 0; background: transparent; width: 100%; }
.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.topbar {
  height: 52px; background: #fff; border-bottom: 1px solid #e2e5ea; display: flex;
  align-items: center; justify-content: space-between; padding: 0 16px; flex-shrink: 0;
}
.company-select { min-width: 260px; }
.topbar-right { display: flex; align-items: center; gap: 8px; }
.user { font-size: 13px; color: #475569; }
.workspace { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #eef1f5; }
.tabbar {
  display: flex; gap: 2px; background: #dde2ea; padding: 6px 8px 0; overflow-x: auto;
  flex-shrink: 0;
}
.worktab {
  display: flex; align-items: center; gap: 7px; padding: 7px 12px; border: 0;
  background: #eef1f5; color: #64748b; border-radius: 6px 6px 0 0; cursor: pointer;
  font-size: 13px; white-space: nowrap;
}
.worktab.active { background: #fff; color: #1f2733; font-weight: 600; }
.worktab .close { font-size: 11px; opacity: 0.6; }
.worktab .close:hover { opacity: 1; color: #d93025; }
.tabcontent { flex: 1; overflow: auto; background: #fff; }
.empty { padding: 60px; text-align: center; color: #94a3b8; }
</style>
