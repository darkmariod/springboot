import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../lib/api'

export interface Company {
  id: number
  ruc: string
  razon_social: string
  nombre_comercial?: string
}

export const useCompanyStore = defineStore('company', () => {
  const companies = ref<Company[]>([])
  const activeId = ref<number | null>(
    localStorage.getItem('company_id') ? Number(localStorage.getItem('company_id')) : null,
  )

  async function load() {
    const res = await api.get('/companies')
    companies.value = res.data
    if (!activeId.value || !companies.value.some((c) => c.id === activeId.value)) {
      setActive(companies.value[0]?.id ?? null)
    }
  }

  function setActive(id: number | null) {
    activeId.value = id
    if (id) localStorage.setItem('company_id', String(id))
    else localStorage.removeItem('company_id')
  }

  return { companies, activeId, load, setActive }
})
