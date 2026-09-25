<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useTabsStore } from '../stores/tabs'

const company = useCompanyStore()
const tabs = useTabsStore()

/** Abre en una pestaña la pantalla donde sí se puede cambiar el stock. */
function ir(key: string, label: string, icon: string, component: string) {
  tabs.open({ key, label, icon, component })
}
const data = ref<any>({ items: [], valor_total: 0 })
const loading = ref(true)
const kardex = ref<any>(null)

const money = (n: any) => `$${Number(n).toFixed(2)}`

async function load() {
  loading.value = true
  data.value = (await api.get(`/inventory/stock?company_id=${company.activeId}`)).data
  loading.value = false
}
async function verKardex(p: any) {
  kardex.value = { loading: true, producto: p }
  kardex.value = (await api.get(`/inventory/kardex/${p.id}`)).data
}
onMounted(load)
</script>

<template>
  <div style="padding: 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h2 style="margin:0;">Inventario</h2>
      <div style="text-align:right;">
        <div style="font-size:11px; color:#94a3b8; text-transform:uppercase;">Valor del inventario</div>
        <div style="font-size:20px; font-weight:700;">{{ money(data.valor_total) }}</div>
      </div>
    </div>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 10px;">
      Existencias al costo promedio ponderado. Clic en un producto para ver su kardex.
    </p>

    <!-- Esta pantalla es el resultado, no el origen: el stock sale de los
         movimientos. Sin estos accesos la gente busca aquí el botón de "nuevo". -->
    <div class="ayuda-inv">
      <span>El stock de esta pantalla sale de los movimientos. Para cambiarlo:</span>
      <Button label="Crear un artículo" icon="pi pi-plus" size="small" text
              @click="ir('products', 'Productos y servicios', 'pi pi-box', 'Products')" />
      <Button label="Registrar una compra" icon="pi pi-shopping-bag" size="small" text
              @click="ir('purchase-entry', 'Registro de Compras', 'pi pi-file-edit', 'PurchaseEntry')" />
      <Button label="Ajustar existencias" icon="pi pi-sliders-h" size="small" text
              @click="ir('inventory-adjustment', 'Ajuste Inventario', 'pi pi-sliders-h', 'InventoryAdjustment')" />
    </div>
    <DataTable :value="data.items" :loading="loading" size="small" stripedRows @row-click="(e) => verKardex(e.data)" selectionMode="single" dataKey="id">
      <Column field="codigo" header="Código" />
      <Column field="descripcion" header="Producto" />
      <Column header="Stock"><template #body="{ data }">{{ Number(data.stock).toLocaleString() }}</template></Column>
      <Column header="Costo prom."><template #body="{ data }">{{ money(data.costo_promedio) }}</template></Column>
      <Column header="Valor"><template #body="{ data }">{{ money(data.valor) }}</template></Column>
    </DataTable>

    <Dialog :visible="!!kardex" modal :header="'Kardex — ' + (kardex?.producto?.descripcion ?? '')" style="width:640px" @update:visible="kardex=null">
      <DataTable v-if="kardex?.movimientos" :value="kardex.movimientos" size="small" stripedRows>
        <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha).slice(0,10) }}</template></Column>
        <Column field="tipo" header="Tipo" />
        <Column field="concepto" header="Concepto" />
        <Column header="Cant."><template #body="{ data }">{{ data.tipo==='egreso'?'-':'+' }}{{ Number(data.cantidad) }}</template></Column>
        <Column header="Saldo"><template #body="{ data }">{{ Number(data.saldo_cantidad) }}</template></Column>
        <Column header="C. prom."><template #body="{ data }">{{ money(data.saldo_costo_promedio) }}</template></Column>
      </DataTable>
    </Dialog>
  </div>
</template>

<style scoped>
.ayuda-inv {
  display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px;
  background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px;
  padding: 8px 12px; margin-bottom: 14px; font-size: 13px; color: #475569;
}
</style>
