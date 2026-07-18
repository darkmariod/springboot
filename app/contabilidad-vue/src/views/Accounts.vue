<script setup lang="ts">
import { onMounted, ref } from 'vue'
import TreeTable from 'primevue/treetable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

interface Account {
  id: number; codigo: string; nombre: string; tipo: string;
  parent_id: number | null; movimientos: number
}
interface Node { key: string; data: Account; children: Node[] }

const company = useCompanyStore()
const nodes = ref<Node[]>([])
const lista = ref<Account[]>([])
const loading = ref(true)
const dialog = ref(false)
const form = ref<any>({})
const msg = ref<any>(null)
const guardando = ref(false)

const tipos = [
  { label: 'Activo', value: 'activo' },
  { label: 'Pasivo', value: 'pasivo' },
  { label: 'Patrimonio', value: 'patrimonio' },
  { label: 'Ingreso', value: 'ingreso' },
  { label: 'Gasto', value: 'gasto' },
]
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

async function load() {
  loading.value = true
  try {
    const res = await api.get('/accounts?company_id=' + company.activeId)
    lista.value = res.data.data ?? res.data
    nodes.value = buildTree(lista.value)
  } finally {
    loading.value = false
  }
}
function nueva() {
  form.value = { codigo: '', nombre: '', tipo: 'activo' }
  msg.value = null
  dialog.value = true
}
function editar(a: Account) {
  form.value = { ...a }
  msg.value = null
  dialog.value = true
}
async function guardar() {
  msg.value = null
  guardando.value = true
  const payload = {
    company_id: company.activeId,
    codigo: form.value.codigo,
    nombre: form.value.nombre,
    tipo: form.value.tipo,
  }
  try {
    if (form.value.id) await api.put('/accounts/' + form.value.id, payload)
    else await api.post('/accounts', payload)
    dialog.value = false
    load()
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = { type: 'error', text: e ? Object.values(e).flat().join(' · ')
      : (err.response?.data?.message ?? 'No se pudo guardar.') }
  } finally { guardando.value = false }
}
async function eliminar(a: Account) {
  if (!confirm('¿Eliminar la cuenta ' + a.codigo + ' — ' + a.nombre + '?')) return
  try {
    await api.delete('/accounts/' + a.id)
    load()
  } catch (err: any) {
    alert(err.response?.data?.message ?? 'No se pudo eliminar.')
  }
}
onMounted(load)
</script>

<template>
  <div class="accounts">
    <div class="head">
      <div>
        <h2>Plan de cuentas</h2>
        <p class="sub">
          Catálogo contable en árbol. El código marca el nivel: <b>1</b> → <b>1.1</b> → <b>1.1.01</b>
        </p>
      </div>
      <Button label="Nueva cuenta" icon="pi pi-plus" @click="nueva" />
    </div>

    <TreeTable :value="nodes" :loading="loading" size="small" scrollable
               :indentation="1.2" class="tree">
      <Column field="codigo" header="Código" expander style="width: 28%" />
      <Column field="nombre" header="Nombre" style="width: 36%" />
      <Column header="Tipo" style="width: 14%">
        <template #body="{ node }">
          <Tag :value="node.data.tipo" :severity="tipoSeverity[node.data.tipo] ?? 'secondary'" />
        </template>
      </Column>
      <Column header="Movimientos" style="width: 12%">
        <template #body="{ node }">
          <span :style="{ color: node.data.movimientos ? '#1f2733' : '#cbd5e1' }">
            {{ node.data.movimientos ?? 0 }}
          </span>
        </template>
      </Column>
      <Column header="" style="width: 10%">
        <template #body="{ node }">
          <Button icon="pi pi-pencil" text size="small" @click="editar(node.data)" />
          <Button v-if="!node.data.movimientos" icon="pi pi-trash" text size="small"
                  severity="danger" @click="eliminar(node.data)" />
        </template>
      </Column>
    </TreeTable>

    <p v-if="!loading && !nodes.length" class="empty">
      Sin cuentas todavía. Tocá <b>Nueva cuenta</b>, o se crean solas al facturar.
    </p>

    <Dialog v-model:visible="dialog" modal :header="form.id ? 'Editar cuenta' : 'Nueva cuenta'"
            style="width:460px">
      <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:12px;">{{ msg.text }}</Message>

      <div style="display:flex; flex-direction:column; gap:12px;">
        <label style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
          Código *
          <InputText v-model="form.codigo" placeholder="1.1.01" fluid />
          <small style="color:#94a3b8;">El punto marca el nivel: 1.1.01 cuelga de 1.1</small>
        </label>
        <label style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
          Nombre *
          <InputText v-model="form.nombre" placeholder="Caja" fluid />
        </label>
        <label style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
          Tipo *
          <Select v-model="form.tipo" :options="tipos" optionLabel="label" optionValue="value"
                  :disabled="!!form.movimientos" fluid />
          <small v-if="form.movimientos" style="color:#d97706;">
            No se puede cambiar el tipo: la cuenta tiene {{ form.movimientos }} movimientos.
          </small>
          <small v-else style="color:#94a3b8;">
            Activo y Gasto suman por el debe · Pasivo, Patrimonio e Ingreso por el haber.
          </small>
        </label>
      </div>

      <template #footer>
        <Button label="Cancelar" text @click="dialog = false" />
        <Button label="Guardar" icon="pi pi-save" :loading="guardando" @click="guardar" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.accounts { padding: 24px; }
.head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.accounts h2 { margin: 0; font-size: 20px; color: #1f2733; }
.sub { color: #94a3b8; font-size: 13px; margin: 4px 0 0; }
.empty { color: #94a3b8; text-align: center; padding: 30px; }
</style>
