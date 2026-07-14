import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface WorkTab {
  key: string        // identificador único (ej. "accounts")
  label: string      // título de la pestaña
  icon: string       // clase PrimeIcon
  component: string  // nombre del componente a renderizar
}

// Maneja las ventanas/pestañas abiertas en el área de trabajo.
// Varias pantallas abiertas a la vez, sin cerrarse (estilo KVS/MicroPlus).
export const useTabsStore = defineStore('tabs', () => {
  const tabs = ref<WorkTab[]>([])
  const activeKey = ref<string | null>(null)

  function open(tab: WorkTab) {
    if (!tabs.value.some((t) => t.key === tab.key)) {
      tabs.value.push(tab)
    }
    activeKey.value = tab.key
  }

  function close(key: string) {
    const idx = tabs.value.findIndex((t) => t.key === key)
    if (idx === -1) return
    tabs.value.splice(idx, 1)
    if (activeKey.value === key) {
      activeKey.value = tabs.value[Math.max(0, idx - 1)]?.key ?? null
    }
  }

  return { tabs, activeKey, open, close }
})
