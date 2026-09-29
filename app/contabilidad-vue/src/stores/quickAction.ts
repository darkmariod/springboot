import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Puente mínimo para pasar "abrí esta pantalla ya con este dato elegido"
 * entre dos vistas que no se conocen entre sí (por ejemplo, el atajo
 * "Ajustar stock" de la fila de un producto abre Ajuste de Inventario con
 * ese producto ya seleccionado, en vez de obligar a buscarlo de nuevo).
 *
 * A propósito no viaja por WorkTab: eso obligaría a tocar MainLayout, que
 * renderiza las 49 pantallas, solo para un caso puntual.
 */
export const useQuickActionStore = defineStore('quickAction', () => {
  const pendingAdjustProductId = ref<number | null>(null)

  function requestAdjust(productId: number) {
    pendingAdjustProductId.value = productId
  }

  /** La pantalla destino la llama al leer el dato, para no reusarlo por error. */
  function consumeAdjust(): number | null {
    const id = pendingAdjustProductId.value
    pendingAdjustProductId.value = null
    return id
  }

  return { pendingAdjustProductId, requestAdjust, consumeAdjust }
})
