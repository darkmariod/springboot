<script setup lang="ts">
import { onMounted, ref } from 'vue'
import TreeTable from 'primevue/treetable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

interface Account { id: number; codigo: string; nombre: string; tipo: string }
interface Node { key: string; data: Account; children: Node[] }

const company = useCompanyStore()
const nodes = ref<Node[]>([])
const loading = ref(true)

const tipoSeverity: Record<string, string> = {
  activo: 'info', pasivo: 'warn', patrimonio: 'secondary', ingreso: 'success', gasto: 'danger',
}

// Arma el árbol a partir del código (1 → 1.1 → 1.1.01).
function buildTree(accounts: Account[]): Node[] {
  const sorted = [...accounts].sort((a, b) => a.codigo.localeCompare(b.codigo))
  const map = new Map<string, Node>()
  const roots: Node[] = []

  for (const acc of sorted) {
    const node: Node = { key: acc.codigo, data: acc, children: [] }
    map.set(acc.codigo, node)
    const parentCodigo = acc.codigo.includes('.')
      ? acc.codigo.slice(0, acc.codigo.lastIndexOf('.'))
      : null
    const parent = parentCodigo ? map.get(parentCodigo) : null
    if (parent) parent.children.push(node)
    else roots.push(node)
  }
  return roots
}

onMounted(async () => {
  loading.value = true
  try {
    const res = await api.get(`/accounts?company_id=${company.activeId}`)
    const list: Account[] = res.data.data ?? res.data
    nodes.value = buildTree(list)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="accounts">
    <div class="head">
      <div>
        <h2>Plan de cuentas</h2>
        <p class="sub">Catálogo contable en árbol, por niveles</p>
      </div>
    </div>

    <TreeTable :value="nodes" :loading="loading" size="small" scrollable
               :indentation="1.2" class="tree">
      <Column field="codigo" header="Código" expander style="width: 40%" />
      <Column field="nombre" header="Nombre" style="width: 40%" />
      <Column field="tipo" header="Tipo" style="width: 20%">
        <template #body="{ node }">
          <Tag :value="node.data.tipo" :severity="tipoSeverity[node.data.tipo] ?? 'secondary'" />
        </template>
      </Column>
    </TreeTable>

    <p v-if="!loading && !nodes.length" class="empty">
      Sin cuentas todavía. Se crean automáticamente al facturar, o se cargan aquí.
    </p>
  </div>
</template>

<style scoped>
.accounts { padding: 24px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.accounts h2 { margin: 0; font-size: 20px; color: #1f2733; }
.sub { color: #94a3b8; font-size: 13px; margin: 4px 0 0; }
.empty { color: #94a3b8; text-align: center; padding: 30px; }
</style>
