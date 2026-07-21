import axios from 'axios'

// Cliente HTTP hacia la API Laravel. /api se redirige al backend vía proxy de Vite.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api',
  headers: { Accept: 'application/json' },
})

// Adjunta el token Sanctum en cada request.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Si el token expira (401), limpia y manda al login.
api.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      if (location.pathname !== '/login') location.href = '/login'
    }
    if (error.response?.status === 402) {
      alert(error.response.data.message ?? 'Su plan no incluye este módulo.')
    }
    return Promise.reject(error)
  },
)

export default api
