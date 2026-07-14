<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Card from 'primevue/card'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const stats = ref({ facturas: 0, ingresos: 0, activos: 0, utilidad: 0 })

onMounted(async () => {
  if (!company.activeId) return
  try {
    const [inc, bal] = await Promise.all([
      api.get(`/income-statement?company_id=${company.activeId}`),
      api.get(`/balance-sheet?company_id=${company.activeId}`),
    ])
    stats.value.ingresos = inc.data.ingresos ?? 0
    stats.value.utilidad = inc.data.utilidad_ejercicio ?? 0
    stats.value.activos = bal.data.activos ?? 0
  } catch {
    /* silencioso en fase 0 */
  }
})

const money = (n: number) => `$${Number(n).toFixed(2)}`
</script>

<template>
  <div class="dash">
    <h2>Dashboard</h2>
    <p class="sub">Resumen del sistema contable</p>

    <div class="grid">
      <Card class="stat"><template #content><span>Facturas emitidas</span><b>{{ stats.facturas }}</b></template></Card>
      <Card class="stat"><template #content><span>Ingresos</span><b>{{ money(stats.ingresos) }}</b></template></Card>
      <Card class="stat"><template #content><span>Activos</span><b>{{ money(stats.activos) }}</b></template></Card>
      <Card class="stat"><template #content><span>Utilidad del ejercicio</span><b>{{ money(stats.utilidad) }}</b></template></Card>
    </div>
  </div>
</template>

<style scoped>
.dash { padding: 24px; }
.dash h2 { margin: 0; font-size: 20px; color: #1f2733; }
.sub { color: #94a3b8; font-size: 13px; margin: 4px 0 18px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
.stat :deep(.p-card-content) { display: flex; flex-direction: column; gap: 4px; padding: 4px 0; }
.stat span { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; }
.stat b { font-size: 24px; color: #1f2733; }
</style>
