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
  <div class="launcher">
    <!-- Nivel 2: items del grupo elegido -->
    <template v-if="grupoActivo">
      <button class="volver" @click="grupoActivo = null">
        <i class="pi pi-arrow-left" /> Módulos
      </button>
      <h2 class="grupo-titulo">{{ grupoActivo.label }}</h2>
      <div class="tiles">
        <div v-for="item in grupoActivo.items" :key="item.key" class="tile" @click="abrir(item)">
          <i :class="item.icon" />
          <span class="tile-label">{{ item.label }}</span>
        </div>
      </div>
    </template>

    <!-- Nivel 1: los grupos, como el lanzador de KVS -->
    <template v-else>
      <div class="launcher-header">
        <img src="/logo-has-reset.png" alt="HasReset" class="hr-logo" />
        <span class="launcher-subtitle">Sistema Contable</span>
      </div>
      <div class="tiles">
        <div v-for="g in grupos" :key="g.label" class="tile"
             @click="g.items.length === 1 ? abrir(g.items[0]) : (grupoActivo = g)">
          <i :class="iconoGrupo[g.label] ?? 'pi pi-th-large'" />
          <span class="tile-label">{{ g.label }}</span>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.launcher {
  padding: 36px;
  height: 100%;
  overflow: auto;
  position: relative;
  background:
    linear-gradient(rgba(10, 25, 41, 0.82), rgba(10, 25, 41, 0.92)),
    url('/fondo-tech.jpg') center / cover no-repeat fixed,
    linear-gradient(135deg, var(--hr-navy) 0%, var(--hr-navy-light) 100%);
}
.launcher::before,
.launcher::after {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.launcher::before {
  background:
    radial-gradient(1.5px 1.5px at 8% 15%, rgba(255,255,255,0.45) 50%, transparent 50%),
    radial-gradient(1px 1px at 25% 55%, rgba(255,255,255,0.3) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 50% 10%, rgba(255,255,255,0.35) 50%, transparent 50%),
    radial-gradient(1px 1px at 68% 75%, rgba(255,255,255,0.25) 50%, transparent 50%),
    radial-gradient(2px 2px at 82% 25%, rgba(255,255,255,0.4) 50%, transparent 50%),
    radial-gradient(1px 1px at 15% 85%, rgba(255,255,255,0.2) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 42% 45%, rgba(255,255,255,0.3) 50%, transparent 50%),
    radial-gradient(1px 1px at 88% 60%, rgba(255,255,255,0.25) 50%, transparent 50%);
}
.launcher::after {
  background:
    radial-gradient(1px 1px at 12% 40%, rgba(255,255,255,0.25) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 38% 80%, rgba(255,255,255,0.3) 50%, transparent 50%),
    radial-gradient(1px 1px at 58% 30%, rgba(255,255,255,0.2) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 72% 8%, rgba(255,255,255,0.35) 50%, transparent 50%),
    radial-gradient(1px 1px at 48% 65%, rgba(255,255,255,0.18) 50%, transparent 50%),
    radial-gradient(1px 1px at 92% 70%, rgba(255,255,255,0.25) 50%, transparent 50%);
}
.launcher-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 28px;
  position: relative;
  z-index: 1;
}
.hr-logo { width: 120px; height: auto; object-fit: contain; }
.launcher-subtitle { font-size: 12px; color: var(--hr-silver); margin-top: 4px; }
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, 132px);
  gap: 24px;
  justify-content: start;
  position: relative;
  z-index: 1;
}
.tile {
  width: 132px;
  height: 118px;
  border-radius: 14px;
  background: linear-gradient(160deg, var(--hr-blue), var(--hr-blue-dark));
  border: 1px solid rgba(255, 255, 255, 0.12);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 8px;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
  transition: transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
}
.tile i { font-size: 2.1rem; color: #fff; }
.tile-label {
  font-size: 12px; font-weight: 600; color: #fff; text-align: center; line-height: 1.2;
}
.tile:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.45);
  border-color: rgba(255, 255, 255, 0.35);
}
.grupo-titulo { margin: 14px 0 24px; font-size: 18px; color: #fff; position: relative; z-index: 1; }
.volver {
  border: 0; background: transparent; color: var(--hr-silver); font-weight: 600; font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 4px 0;
  position: relative; z-index: 1;
}
</style>
