import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../lib/api'

export interface Company {
  id: number
  ruc: string
  razon_social: string
  nombre_comercial?: string
  dir_matriz?: string
  ambiente?: number
  plan?: string
  plan_vence?: string | null
}

export const useCompanyStore = defineStore('company', () => {
  const companies = ref<Company[]>([])
  const activeId = ref<number | null>(
    localStorage.getItem('company_id') ? Number(localStorage.getItem('company_id')) : null,
  )

  // Empresa activa y su RUC real (las cabeceras de las pantallas lo muestran; activeId es solo el número interno)
  const active = computed(() => companies.value.find((c) => c.id === activeId.value) ?? null)
  const activeRuc = computed(() => active.value?.ruc ?? '')

  // El servidor solo devuelve las empresas que el usuario puede abrir: el superadministrador, todas; el usuario de un
  // cliente, la suya. Con una sola no hay nada que cambiar y el selector del menú no se muestra.
  const puedeCambiar = computed(() => companies.value.length > 1)

  async function load() {
    const res = await api.get('/companies')
    companies.value = res.data
    // Una empresa guardada de otra sesión que ya no está en la lista (otro usuario, otro cliente) se descarta
    if (!activeId.value || !companies.value.some((c) => c.id === activeId.value)) {
      setActive(companies.value[0]?.id ?? null)
    }
  }

  function setActive(id: number | null) {
    activeId.value = id
    if (id) localStorage.setItem('company_id', String(id))
    else localStorage.removeItem('company_id')
  }

  return { companies, activeId, active, activeRuc, puedeCambiar, load, setActive }
})
