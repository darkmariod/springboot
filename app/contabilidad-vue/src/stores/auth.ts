import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../lib/api'

/**
 * Pantalla de inicio de sesión. La aplicación vive bajo /app/ (`base` de Vite, `createWebHistory('/app/')` y el `location /app`
 * de nginx-frontend.conf): '/login' a secas no existe en el nginx y responde 404. BASE_URL termina en '/', así que da '/app/login'.
 */
export const RUTA_LOGIN = `${import.meta.env.BASE_URL}login`

/** Clave donde se deja, para la pantalla de inicio de sesión, el motivo por el que se cerró la sesión (venció, desactivado). */
export const CLAVE_MOTIVO = 'sesion_motivo'

let saliendo = false

/**
 * Lleva al login con una recarga completa: así no queda ninguna pestaña, empresa ni dato de la sesión anterior en memoria.
 * No hace nada si ya está en el login (un 401 del propio login no debe provocar un bucle de recargas).
 */
export function irAlLogin(motivo?: string) {
  localStorage.removeItem('token')
  // La empresa elegida es de la sesión que termina: la siguiente persona (otro cliente, quizá) no debe heredar ese id
  localStorage.removeItem('company_id')
  if (motivo) {
    try { sessionStorage.setItem(CLAVE_MOTIVO, motivo) } catch { /* sin almacenamiento: solo se pierde el aviso */ }
  }
  if (saliendo || location.pathname === RUTA_LOGIN) return
  saliendo = true
  location.href = RUTA_LOGIN
}

/**
 * Respuestas del servidor que significan «esta sesión ya no sirve»: 401 (token vencido o revocado) y el 403 de un usuario
 * desactivado. Las peticiones de login y de logout se salen de esta regla: un 401 allí no es una sesión vencida, y el logout
 * ya cierra por su cuenta.
 *
 * Reemplaza el interceptor de respuesta que trae `lib/api.ts`, que mandaba a '/login' (404 bajo /app/) y nunca avisaba al servidor.
 * Se instala una vez desde main.ts.
 */
export function instalarInterceptoresDeSesion() {
  api.interceptors.response.clear()
  api.interceptors.response.use(
    (res) => res,
    (error) => {
      const status: number | undefined = error.response?.status
      const ruta = String(error.config?.url ?? '').split('?')[0]
      const esAcceso = /\/(login|logout)$/.test(ruta)
      const mensaje = String(error.response?.data?.message ?? '')

      if (!esAcceso && status === 401) {
        irAlLogin('Tu sesión venció. Ingresa de nuevo.')
      } else if (!esAcceso && status === 403 && mensaje.startsWith('Tu usuario está desactivado')) {
        irAlLogin(mensaje)
      }
      if (status === 402) {
        alert(mensaje || 'Tu plan no incluye este módulo.')
      }
      return Promise.reject(error)
    },
  )
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('token'))
  // es_superadmin: el operador de la plataforma (ve todas las empresas, las crea y cambia planes). Solo adapta el menú:
  // el servidor decide de verdad qué empresa se puede abrir (middleware `tenant`).
  const user = ref<{ name: string; email: string; rol?: string; company_id?: number; es_superadmin?: boolean } | null>(null)

  async function login(email: string, password: string) {
    const res = await api.post('/login', { email, password })
    token.value = res.data.token
    localStorage.setItem('token', res.data.token)
    await fetchUser()
  }

  async function fetchUser() {
    try {
      const res = await api.get('/user')
      user.value = res.data
    } catch {
      user.value = null
    }
  }

  /**
   * Cierra la sesión: primero le pide al servidor que revoque el token (sin esto el token seguiría sirviendo hasta vencer) y
   * después limpia lo local y vuelve al login. Si el servidor no responde o el token ya no sirve, igual se cierra aquí.
   */
  async function logout() {
    try {
      await api.post('/logout', null, { timeout: 5000 })
    } catch { /* mejor esfuerzo: abajo siempre se limpia el token local */ }
    token.value = null
    user.value = null
    irAlLogin()
  }

  const esSuperadmin = computed(() => !!user.value?.es_superadmin)

  return { token, user, esSuperadmin, login, fetchUser, logout }
})
