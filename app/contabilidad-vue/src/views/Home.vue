<script setup lang="ts">
import { computed, ref } from 'vue'
import { modulesPara } from '../modules'
import { useTabsStore } from '../stores/tabs'
import { usePlanStore } from '../stores/plan'

// Module launcher styled after KVS: teal square tiles with white icons.
// First level: module groups. Clicking a group drills into its items.
const tabs = useTabsStore()
const plan = usePlanStore()
const grupoActivo = ref<any>(null)

const grupos = computed(() => modulesPara(plan.tiene))

// One representative icon per group (matches the KVS launcher look)
const iconoGrupo: Record<string, string> = {
  'Catálogo': 'pi pi-list',
  'Ventas': 'pi pi-chart-line',
  'Compras': 'pi pi-shopping-cart',
  'Inventario': 'pi pi-box',
  'Caja y Bancos': 'pi pi-wallet',
  'Contabilidad': 'pi pi-calculator',
  'Nómina': 'pi pi-users',
  'EDocuments': 'pi pi-file-edit',
  'Administración': 'pi pi-cog',
}
function abrir(item: any) {
  tabs.open(item)
}
</script>

<template>
  <div style="padding:36px; height:100%; overflow:auto; background:#f4f6f8;">
    <!-- Nivel 2: items del grupo elegido -->
    <template v-if="grupoActivo">
      <button class="volver" @click="grupoActivo = null">
        <i class="pi pi-arrow-left" /> Módulos
      </button>
      <h2 style="margin:14px 0 24px; font-size:18px; color:#1f2733;">{{ grupoActivo.label }}</h2>
      <div class="tiles">
        <div v-for="item in grupoActivo.items" :key="item.key" class="tile-wrap" @click="abrir(item)">
          <div class="tile"><i :class="item.icon" /></div>
          <span class="tile-label">{{ item.label }}</span>
        </div>
      </div>
    </template>

    <!-- Nivel 1: los grupos, como el lanzador de KVS -->
    <template v-else>
      <div class="tiles">
        <div v-for="g in grupos" :key="g.label" class="tile-wrap"
             @click="g.items.length === 1 ? abrir(g.items[0]) : (grupoActivo = g)">
          <div class="tile"><i :class="iconoGrupo[g.label] ?? 'pi pi-th-large'" /></div>
          <span class="tile-label">{{ g.label }}</span>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, 116px);
  gap: 26px 30px;
  justify-content: start;
}
.tile-wrap {
  display: flex; flex-direction: column; align-items: center; gap: 9px;
  cursor: pointer; width: 116px;
}
.tile {
  width: 96px; height: 96px; border-radius: 16px;
  background: #2a6b6b;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
  transition: transform 0.12s ease, box-shadow 0.12s ease;
}
.tile i { font-size: 2.5rem; color: #fff; }
.tile-wrap:hover .tile { transform: translateY(-3px); box-shadow: 0 6px 14px rgba(0, 0, 0, 0.22); }
.tile-label {
  font-size: 12.5px; font-weight: 600; color: #37474f; text-align: center; line-height: 1.25;
}
.volver {
  border: 0; background: transparent; color: #2a6b6b; font-weight: 600; font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 4px 0;
}
</style>
