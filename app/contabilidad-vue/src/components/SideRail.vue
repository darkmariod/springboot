<script setup lang="ts">
import { computed } from 'vue'
import { usePlanStore } from '../stores/plan'
import { useTabsStore } from '../stores/tabs'
import { modulesPara } from '../modules'

const plan = usePlanStore()
const tabs = useTabsStore()
const grupos = computed(() => modulesPara(plan.tiene))

function abrir(tab: { key: string; label: string; icon: string; component: string }) {
  tabs.open(tab)
}
const irInicio = () => abrir({ key: 'inicio', label: 'Inicio', icon: 'pi pi-home', component: 'Dashboard' })
</script>

<template>
  <aside class="rail">
    <button class="rail-inicio" :class="{ active: tabs.activeKey === 'inicio' }" @click="irInicio">
      <i class="pi pi-home" /> Inicio
    </button>
    <nav class="rail-nav">
      <section v-for="g in grupos" :key="g.label" class="nav-sec">
        <h5 class="nav-head">{{ g.label }}</h5>
        <button
          v-for="item in g.items"
          :key="item.key"
          class="nav-item"
          :class="{ active: tabs.activeKey === item.key }"
          @click="abrir(item)"
        >
          <i :class="item.icon" class="nav-ico" />
          <span class="nav-label">{{ item.label }}</span>
        </button>
      </section>
    </nav>
  </aside>
</template>

<style scoped>
.rail {
  --rail: #0f2744; --rail-text: #b9c7da; --rail-muted: #6f85a3;
  --rail-hover: rgba(255, 255, 255, 0.08); --accent: #2563eb;
  width: 232px; flex-shrink: 0;
  background: var(--rail); color: var(--rail-text);
  padding: 14px 10px 24px; overflow-y: auto;
  display: flex; flex-direction: column; gap: 16px;
}
.rail-inicio {
  display: flex; align-items: center; gap: 9px;
  width: 100%; padding: 9px 11px; border: 0; border-radius: 8px;
  background: transparent; color: var(--rail-text);
  font-size: 13.5px; font-weight: 600; cursor: pointer; text-align: left;
}
.rail-inicio:hover { background: var(--rail-hover); color: #fff; }
.rail-inicio.active { background: var(--rail-hover); color: #fff; box-shadow: inset 3px 0 0 var(--accent); }
.rail-nav { display: flex; flex-direction: column; gap: 16px; }
.nav-sec { margin-bottom: 2px; }
.nav-head {
  margin: 0 0 4px; padding: 0 11px;
  font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.8px;
  color: var(--rail-muted); font-weight: 700;
}
.nav-item {
  display: flex; align-items: center; gap: 9px;
  width: 100%; padding: 7px 11px; border: 0; border-radius: 7px;
  background: transparent; color: var(--rail-text);
  font-size: 13px; cursor: pointer; text-align: left;
}
.nav-item:hover { background: var(--rail-hover); color: #fff; }
.nav-item.active { background: var(--rail-hover); color: #fff; font-weight: 600; box-shadow: inset 3px 0 0 var(--accent); }
.nav-ico { font-size: 13px; width: 16px; text-align: center; flex-shrink: 0; }
.nav-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

@media (max-width: 860px) { .rail { display: none; } }
</style>
