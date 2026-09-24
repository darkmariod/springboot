import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

/**
 * Layout preferences that survive a reload.
 * The side menu is hidden by default: the launcher is the way in, like a desktop.
 */
export const useUiStore = defineStore('ui', () => {
  const KEY = 'hr.ui.sidebar'
  let inicial = false
  try {
    inicial = localStorage.getItem(KEY) === '1'
  } catch {
    /* private mode or blocked storage: fall back to hidden */
  }

  const sidebar = ref(inicial)

  watch(sidebar, (v) => {
    try {
      localStorage.setItem(KEY, v ? '1' : '0')
    } catch {
      /* ignore */
    }
  })

  function toggleSidebar() {
    sidebar.value = !sidebar.value
  }

  return { sidebar, toggleSidebar }
})
