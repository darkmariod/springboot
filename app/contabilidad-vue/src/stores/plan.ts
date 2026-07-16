import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../lib/api'

export const usePlanStore = defineStore('plan', () => {
  const features = ref<string[]>([])
  const plan = ref('')
  const nombre = ref('')
  const vence = ref<string | null>(null)
  const vencido = ref(false)

  async function load(companyId: number) {
    try {
      const { data } = await api.get('/companies/' + companyId + '/plan')
      plan.value = data.plan
      nombre.value = data.nombre
      features.value = data.features
      vence.value = data.vence
      vencido.value = data.vencido
    } catch {
      // Si el plan no carga, no escondemos nada (fail-open): mejor mostrar todo
      // que un lanzador vacío en pleno demo.
      features.value = []
    }
  }
  function tiene(feature?: string) {
    if (!feature) return true
    // Sin plan cargado = mostrar todo (fail-open)
    if (!features.value.length) return true
    return features.value.includes(feature)
  }
  return { plan, nombre, features, vence, vencido, load, tiene }
})
