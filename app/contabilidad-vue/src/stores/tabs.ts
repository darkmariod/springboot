import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'

export interface WorkTab {
  key: string        // identificador único (ej. "accounts")
  label: string      // título de la pestaña
  icon: string       // clase PrimeIcon
  component: string  // nombre del componente a renderizar
  // Parámetros opcionales que la pestaña le pasa a su pantalla como props
  // (ej. { tipo: 'bien' } para el catálogo de Bienes). Las demás pestañas no los llevan.
  params?: Record<string, unknown>
}

/**
 * Claves antiguas que otros botones aún usan (Dashboard e Inventario abren 'products').
 * Se abren como la pestaña que las reemplazó, para no duplicar pantallas ni dejar un enlace roto.
 */
const CLAVES_ANTIGUAS: Record<string, WorkTab> = {
  products: { key: 'bienes', label: 'Bienes (inventario)', icon: 'pi pi-box', component: 'Products', params: { tipo: 'bien' } },
}

/**
 * Historial de pantallas visitadas, para los botones Atrás / Adelante.
 *
 * Funciona como el historial de un navegador: `claves` son las pestañas en el orden en que
 * se activaron y `pos` es la que está al frente. Al activar una pestaña nueva se descarta lo
 * que había "adelante". Son funciones puras (no tocan Vue) para poder probarlas solas.
 */
export interface Historial {
  claves: string[]
  pos: number
}

/** Tope de entradas: lo más antiguo se olvida. */
export const HISTORIAL_MAX = 50

export const historialVacio = (): Historial => ({ claves: [], pos: -1 })

/** Registra que `key` pasó al frente. Si ya era la actual no cambia nada. */
export function registrarActivacion(h: Historial, key: string, max = HISTORIAL_MAX): Historial {
  if (h.claves[h.pos] === key) return h
  const claves = [...h.claves.slice(0, h.pos + 1), key]
  const exceso = Math.max(0, claves.length - max)
  return { claves: claves.slice(exceso), pos: claves.length - exceso - 1 }
}

/**
 * Deja en el historial solo las pestañas que siguen abiertas (`vivas`), sin repetir dos
 * veces seguidas la misma. Si la actual se cerró, la posición cae en la anterior que sigue abierta.
 */
export function depurarHistorial(h: Historial, vivas: Iterable<string>): Historial {
  const abiertas = new Set(vivas)
  const claves: string[] = []
  let pos = -1
  h.claves.forEach((k, i) => {
    if (abiertas.has(k) && claves[claves.length - 1] !== k) claves.push(k)
    if (i === h.pos) pos = claves.length - 1
  })
  if (claves.length && pos < 0) pos = 0
  return { claves, pos: claves.length ? pos : -1 }
}

export const puedeRetroceder = (h: Historial) => h.pos > 0
export const puedeAvanzar = (h: Historial) => h.pos >= 0 && h.pos < h.claves.length - 1

/** Mueve la posición `paso` lugares (-1 atrás, +1 adelante). `null` si no hay a dónde ir. */
export function moverHistorial(h: Historial, paso: number): { historial: Historial; key: string } | null {
  const pos = h.pos + paso
  if (pos < 0 || pos >= h.claves.length) return null
  return { historial: { claves: h.claves, pos }, key: h.claves[pos] }
}

// Maneja las ventanas/pestañas abiertas en el área de trabajo.
// Varias pantallas abiertas a la vez, sin cerrarse (estilo KVS/MicroPlus).
export const useTabsStore = defineStore('tabs', () => {
  const tabs = ref<WorkTab[]>([])
  const activeKey = ref<string | null>(null)
  const historial = ref<Historial>(historialVacio())
  // Verdadero mientras Atrás / Adelante cambian la pestaña: ese cambio no es una visita nueva.
  let navegando = false

  // Cualquier cambio de pestaña al frente (clic, Alt+N, abrir desde un botón…) queda en el historial.
  // Es síncrono para que dos cambios seguidos no se junten en uno.
  watch(activeKey, (k) => {
    if (navegando || !k) return
    historial.value = registrarActivacion(historial.value, k)
  }, { flush: 'sync' })

  // Una pestaña cerrada, por cualquier camino (cerrar, cerrar todas, cambio de empresa),
  // sale del historial: Atrás nunca apunta a una pantalla que ya no existe.
  watch(() => tabs.value.map((t) => t.key).join('\u0000'), () => {
    historial.value = depurarHistorial(historial.value, tabs.value.map((t) => t.key))
  }, { flush: 'sync' })

  function open(entrada: WorkTab) {
    const tab = CLAVES_ANTIGUAS[entrada.key] ?? entrada
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

  /** Cierra todo menos la pestaña dada (por defecto, todo). Para "demasiadas pestañas abiertas". */
  function closeAll(exceptKey?: string) {
    const keep = exceptKey ? tabs.value.find((t) => t.key === exceptKey) : undefined
    tabs.value = keep ? [keep] : []
    activeKey.value = keep?.key ?? null
  }

  /** Cierra todas las pestañas sin abrir nada (cambio de empresa: las abiertas eran de la anterior). */
  function reset() {
    tabs.value = []
    activeKey.value = null
  }

  function ir(paso: number) {
    const destino = moverHistorial(historial.value, paso)
    if (!destino || !tabs.value.some((t) => t.key === destino.key)) return
    historial.value = destino.historial
    navegando = true
    try { activeKey.value = destino.key } finally { navegando = false }
  }
  const atras = () => ir(-1)
  const adelante = () => ir(1)
  const puedeAtras = computed(() => puedeRetroceder(historial.value))
  const puedeAdelante = computed(() => puedeAvanzar(historial.value))

  return { tabs, activeKey, historial, open, close, closeAll, reset, atras, adelante, puedeAtras, puedeAdelante }
})
