import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../lib/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('token'))
  const user = ref<{ name: string; email: string } | null>(null)

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

  function logout() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
    location.href = '/login'
  }

  return { token, user, login, fetchUser, logout }
})
