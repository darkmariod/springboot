/**
 * Desktop-style keyboard shortcuts.
 *
 * One global listener (installed by the layout) turns key combos into named
 * actions. Screens subscribe to the actions they care about with `onShortcut`;
 * the subscription lives only while the screen is the active tab, so F2 in
 * the invoices tab never fires the "new client" handler of a background tab.
 *
 * Key choice matters: Chromium reserves Ctrl+N, Ctrl+T, Ctrl+W, Ctrl+Tab and
 * Ctrl+1..9 (Cmd on Mac) and a page cannot prevent them, so those combos would
 * open windows and close tabs instead of doing our thing. Function keys and
 * Ctrl+S / Ctrl+P / Ctrl+F / Ctrl+B are safe everywhere.
 *
 * Browser combos are only intercepted when some screen is listening, so
 * Ctrl+F still opens the browser search on screens without their own.
 */
import { onActivated, onDeactivated, onMounted, onUnmounted } from 'vue'

export type ShortcutAction =
  | 'nuevo'
  | 'guardar'
  | 'imprimir'
  | 'buscar'
  | 'cancelar'
  | 'modulos'
  | 'cerrar-pestana'
  | 'menu-lateral'
  | 'pestana'

type Handler = (payload?: unknown) => void

const handlers = new Map<ShortcutAction, Set<Handler>>()

/** Human-readable table, used by the launcher's hint bar and the help dialog. */
export const SHORTCUTS: { keys: string; action: ShortcutAction; label: string }[] = [
  { keys: 'F2', action: 'nuevo', label: 'Nuevo registro' },
  { keys: 'Ctrl + S', action: 'guardar', label: 'Guardar' },
  { keys: 'Ctrl + P', action: 'imprimir', label: 'Imprimir' },
  { keys: 'F3', action: 'buscar', label: 'Buscar' },
  { keys: 'Esc', action: 'cancelar', label: 'Cancelar / cerrar' },
  { keys: 'F4', action: 'modulos', label: 'Volver a Módulos' },
  { keys: 'F8', action: 'cerrar-pestana', label: 'Cerrar pestaña' },
  { keys: 'Alt + 1…9', action: 'pestana', label: 'Ir a la pestaña N' },
  { keys: 'Ctrl + B', action: 'menu-lateral', label: 'Mostrar / ocultar menú lateral' },
]

function subscribe(action: ShortcutAction, fn: Handler) {
  if (!handlers.has(action)) handlers.set(action, new Set())
  handlers.get(action)!.add(fn)
}

function unsubscribe(action: ShortcutAction, fn: Handler) {
  handlers.get(action)?.delete(fn)
}

/** Fire an action. Returns true when at least one screen handled it. */
export function emitShortcut(action: ShortcutAction, payload?: unknown): boolean {
  const set = handlers.get(action)
  if (!set || set.size === 0) return false
  set.forEach((fn) => fn(payload))
  return true
}

/**
 * Subscribe a screen to an action for as long as it is visible.
 * Works with <KeepAlive>: pauses when the tab goes to the background.
 */
export function onShortcut(action: ShortcutAction, fn: Handler) {
  onMounted(() => subscribe(action, fn))
  onActivated(() => subscribe(action, fn))
  onDeactivated(() => unsubscribe(action, fn))
  onUnmounted(() => unsubscribe(action, fn))
}

/** Map a keyboard event to an action. Ctrl on Windows/Linux, Cmd or Ctrl on Mac. */
export function actionFor(e: KeyboardEvent): { action: ShortcutAction; payload?: unknown } | null {
  const mod = e.ctrlKey || e.metaKey
  const key = e.key.toLowerCase()

  // Plain keys
  if (key === 'escape') return { action: 'cancelar' }
  if (key === 'f2') return { action: 'nuevo' }
  if (key === 'f3') return { action: 'buscar' }
  if (key === 'f4') return { action: 'modulos' }
  if (key === 'f8') return { action: 'cerrar-pestana' }

  // Alt+digit: e.code so Mac's Alt-symbols (¡™£…) don't get in the way
  if (e.altKey && !mod && /^Digit[1-9]$/.test(e.code)) {
    return { action: 'pestana', payload: Number(e.code.slice(5)) - 1 }
  }

  if (!mod || e.altKey) return null

  // Ctrl/Cmd combos the browser lets us keep
  if (key === 's') return { action: 'guardar' }
  if (key === 'p') return { action: 'imprimir' }
  if (key === 'f') return { action: 'buscar' }
  if (key === 'b') return { action: 'menu-lateral' }

  return null
}

/** Actions the layout itself owns; they always take precedence over the browser. */
export const LAYOUT_ACTIONS: ShortcutAction[] = ['modulos', 'cerrar-pestana', 'menu-lateral', 'pestana']
