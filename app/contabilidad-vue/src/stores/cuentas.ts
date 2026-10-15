import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../lib/api'
import { useCompanyStore } from './company'

export interface CuentaOpcion {
  id: number
  codigo: string
  nombre: string
  tipo: string
  label: string
}

/**
 * Plan de cuentas de la empresa activa para los selectores de contrapartida (comprobantes de caja, movimientos de banco).
 * Solo ofrece cuentas de detalle: las que tienen subcuentas son encabezados y el servidor no les admite movimientos.
 */
export const useCuentasStore = defineStore('cuentas', () => {
  const lista = ref<any[]>([])
  const empresa = ref<number | null>(null)
  const cargando = ref(false)

  async function cargar(forzar = false) {
    const id = useCompanyStore().activeId
    if (!id) {
      lista.value = []
      empresa.value = null
      return
    }
    if (!forzar && empresa.value === id && lista.value.length) return
    cargando.value = true
    try {
      lista.value = (await api.get('/accounts', { params: { company_id: id } })).data
      empresa.value = id
    } finally {
      cargando.value = false
    }
  }

  const detalle = computed<CuentaOpcion[]>(() => {
    const padres = new Set(lista.value.map((a) => a.parent_id).filter(Boolean))
    const codigos: string[] = lista.value.map((a) => String(a.codigo))
    return lista.value
      .filter((a) => !padres.has(a.id) && !codigos.some((c) => c.startsWith(a.codigo + '.')))
      .map((a) => ({ id: a.id, codigo: a.codigo, nombre: a.nombre, tipo: a.tipo, label: `${a.codigo} · ${a.nombre}` }))
  })

  return { lista, detalle, cargando, cargar }
})
