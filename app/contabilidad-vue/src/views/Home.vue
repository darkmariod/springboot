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
/* Fondo tech pedido por el cliente. Si /fondo-tech.jpg no existe, queda el degradado solo. */
.launcher {
  padding: 36px;
  height: 100%;
  overflow: auto;
  background:
    linear-gradient(rgba(10, 25, 45, 0.82), rgba(10, 25, 45, 0.92)),
    url('/fondo-tech.jpg') center / cover no-repeat fixed,
    linear-gradient(135deg, #0a192d 0%, #123a5c 100%);
}
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, 132px);
  gap: 24px;
  justify-content: start;
}
/* El nombre va DENTRO del cuadrado (lo que pidió el cliente) */
.tile {
  width: 132px;
  height: 118px;
  border-radius: 14px;
  background: linear-gradient(160deg, #2f7676, #245f5f);
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
.grupo-titulo { margin: 14px 0 24px; font-size: 18px; color: #fff; }
.volver {
  border: 0; background: transparent; color: #7fd1d1; font-weight: 600; font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 4px 0;
}
</style>
