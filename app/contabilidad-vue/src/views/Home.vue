<script setup lang="ts">
import { computed, nextTick, onActivated, ref, watch } from 'vue'
import { modulesPara } from '../modules'
import { useTabsStore } from '../stores/tabs'
import { usePlanStore } from '../stores/plan'
import { onShortcut, SHORTCUTS } from '../composables/useShortcuts'
import { useAuthStore } from '../stores/auth'

// Module launcher styled after KVS: teal square tiles with white icons.
// First level: module groups. Clicking a group drills into its items.
const tabs = useTabsStore()
const plan = usePlanStore()
const auth = useAuthStore()
const grupoActivo = ref<any>(null)

const grupos = computed(() => modulesPara(plan.tiene, auth.user?.rol))

// One representative icon per group (matches the KVS launcher look)
const iconoGrupo: Record<string, string> = {
  'Inicio': 'pi pi-home',
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
const busqueda = ref('')
const cajaBusqueda = ref<HTMLInputElement>()
const marcado = ref(0)

/** Todas las pantallas del plan, aplanadas: la búsqueda cruza los grupos. */
const todas = computed(() =>
  grupos.value.flatMap((g) => g.items.map((i) => ({ ...i, grupo: g.label }))),
)

function normalizar(t: string) {
  return t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
}

/** Lo que se muestra: resultados de la búsqueda, pantallas del grupo, o los grupos. */
const resultados = computed(() => {
  const q = normalizar(busqueda.value.trim())
  if (!q) return []
  return todas.value.filter(
    (i) => normalizar(i.label).includes(q) || normalizar(i.grupo).includes(q),
  )
})

const visibles = computed(() =>
  busqueda.value.trim() ? resultados.value : grupoActivo.value ? grupoActivo.value.items : grupos.value,
)

function abrir(item: any) {
  tabs.open(item)
  busqueda.value = ''
}

/** Un grupo con una sola pantalla se abre directo; los demás entran al segundo nivel. */
function elegir(g: any) {
  if (g.items) g.items.length === 1 ? abrir(g.items[0]) : (grupoActivo.value = g)
  else abrir(g)
}

function mover(paso: number) {
  const n = visibles.value.length
  if (n) marcado.value = (marcado.value + paso + n) % n
}

function activarMarcado() {
  const el: any = visibles.value[marcado.value]
  if (el) elegir(el)
}

function enfocarBusqueda() {
  nextTick(() => cajaBusqueda.value?.focus())
}

watch([busqueda, grupoActivo], () => { marcado.value = 0 })

// Esc limpia la búsqueda; si no hay búsqueda, vuelve al primer nivel.
onShortcut('cancelar', () => {
  if (busqueda.value) busqueda.value = ''
  else if (grupoActivo.value) grupoActivo.value = null
})
onShortcut('buscar', enfocarBusqueda)

// Al volver a Módulos se conserva el grupo donde se estaba: quien abre Clientes
// desde Catálogo casi siempre vuelve a abrir otra pantalla del mismo grupo.
// Solo se limpia la búsqueda, que es de un momento.
function alVolver() {
  busqueda.value = ''
  marcado.value = 0
  enfocarBusqueda()
}

watch(() => tabs.activeKey, (k) => { if (k === 'home') alVolver() })
onActivated(alVolver)
</script>

<template>
  <div class="launcher" @keydown.down.prevent="mover(1)" @keydown.up.prevent="mover(-1)">
    <!-- Cabecera: marca y buscador. El buscador cruza los 10 grupos. -->
    <div class="launcher-header">
      <div class="launcher-brand">
        <img src="/logo-marca-blanca.png" alt="" class="hr-logo" />
        <span class="launcher-name">HasReset</span>
      </div>

      <div class="buscador">
        <i class="pi pi-search" />
        <input
          ref="cajaBusqueda"
          v-model="busqueda"
          type="text"
          placeholder="Escribe para buscar un módulo…"
          spellcheck="false"
          autocomplete="off"
          @keydown.enter.prevent="activarMarcado"
          @keydown.esc.stop.prevent="busqueda = ''"
        />
        <button v-if="busqueda" class="limpiar" title="Limpiar (Esc)" @click="busqueda = ''">
          <i class="pi pi-times" />
        </button>
      </div>
    </div>

    <!-- Migas: solo cuando se entró a un grupo -->
    <div v-if="grupoActivo && !busqueda" class="migas">
      <button class="volver" @click="grupoActivo = null">
        <i class="pi pi-arrow-left" /> Módulos
      </button>
      <span class="migas-sep">/</span>
      <span class="migas-actual">{{ grupoActivo.label }}</span>
    </div>

    <!-- Cuadros: resultados de búsqueda, pantallas del grupo, o los grupos -->
    <div class="tiles">
      <div
        v-for="(item, i) in visibles"
        :key="item.key ?? item.label"
        class="tile"
        :class="{ 'tile--disabled': item.disabled, 'tile--marcado': i === marcado }"
        @click="item.disabled ? null : elegir(item)"
        @mouseenter="marcado = i"
      >
        <i :class="item.icon ?? iconoGrupo[item.label] ?? 'pi pi-th-large'" />
        <span class="tile-label">{{ item.label }}</span>
        <span v-if="item.grupo" class="tile-grupo">{{ item.grupo }}</span>
        <span v-if="item.disabled" class="tile-prox">Próximamente</span>
      </div>
    </div>

    <div v-if="busqueda && !visibles.length" class="sin-resultados">
      No hay ningún módulo que se llame «{{ busqueda }}».
    </div>

    <div class="atajos">
      <span v-for="a in SHORTCUTS" :key="a.action" class="atajo">
        <kbd>{{ a.keys }}</kbd> {{ a.label }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.launcher {
  padding: 36px;
  height: 100%;
  overflow: auto;
  position: relative;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
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
  align-items: center;
  gap: 28px;
  margin-bottom: 26px;
  position: relative;
  z-index: 1;
}
.launcher-brand { display: flex; align-items: center; gap: 12px; }
.hr-logo { height: 44px; width: auto; object-fit: contain; }
.launcher-name { font-size: 26px; font-weight: 600; color: #fff; letter-spacing: -0.01em; }
/* Buscador: con 49 pantallas, teclear es más rápido que dar clics. */
.buscador {
  flex: 1; max-width: 480px; display: flex; align-items: center; gap: 10px;
  background: rgba(255, 255, 255, 0.07);
  border: 1px solid rgba(255, 255, 255, 0.16);
  border-radius: 10px; padding: 0 12px; height: 42px;
  transition: border-color 0.12s ease, background 0.12s ease;
}
.buscador:focus-within {
  background: rgba(255, 255, 255, 0.11);
  border-color: rgba(120, 180, 255, 0.55);
  box-shadow: 0 0 0 3px rgba(56, 130, 246, 0.16);
}
.buscador > i { color: #8fa3bd; font-size: 15px; }
.buscador input {
  flex: 1; min-width: 0; background: transparent; border: 0; outline: none;
  color: #fff; font-size: 14px; font-family: inherit;
}
.buscador input::placeholder { color: #7e90a8; }
.limpiar {
  border: 0; background: transparent; color: #8fa3bd; cursor: pointer;
  display: grid; place-items: center; padding: 4px; border-radius: 6px;
}
.limpiar:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }

.migas {
  display: flex; align-items: center; gap: 10px; margin-bottom: 18px;
  position: relative; z-index: 1;
}
.migas-sep { color: #56708f; }
.migas-actual { color: #fff; font-weight: 600; font-size: 15px; }

.sin-resultados {
  color: var(--hr-silver); font-size: 14px; padding: 28px 2px;
  position: relative; z-index: 1;
}
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, 150px);
  gap: 26px;
  justify-content: start;
  position: relative;
  z-index: 1;
}
.tile {
  width: 150px;
  height: 132px;
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
.tile i { font-size: 2.4rem; color: #fff; }
.tile-label {
  font-size: 12px; font-weight: 600; color: #fff; text-align: center; line-height: 1.2;
}
.tile:hover,
.tile--marcado {
  transform: translateY(-3px);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.45);
  border-color: rgba(255, 255, 255, 0.35);
}
/* En los resultados de búsqueda se muestra de qué grupo viene cada pantalla. */
.tile-grupo {
  font-size: 10px; color: #b7c6d8; text-transform: uppercase;
  letter-spacing: 0.4px; margin-top: -2px;
}
.tile--disabled {
  cursor: not-allowed;
  background: linear-gradient(160deg, #3b4a5f, #2b3748);
  border-color: rgba(255, 255, 255, 0.06);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
.tile--disabled:hover { transform: none; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25); border-color: rgba(255,255,255,0.06); }
.tile--disabled i { color: #7a8aa0; }
.tile--disabled .tile-label { color: #aebccd; }
.tile-prox {
  font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
  color: #c9d4e0; background: rgba(255,255,255,0.12); border-radius: 8px; padding: 2px 7px;
}
.atajos {
  margin-top: auto; padding-top: 28px; display: flex; flex-wrap: wrap; gap: 6px 18px;
  font-size: 11.5px; color: var(--hr-silver); position: relative; z-index: 1;
}
.atajo { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
.atajo kbd {
  font-family: inherit; font-size: 10.5px; font-weight: 600; color: #fff;
  background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 5px; padding: 1px 6px;
}
.volver {
  border: 0; background: transparent; color: var(--hr-silver); font-weight: 600; font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 4px 0;
  position: relative; z-index: 1;
}
</style>
