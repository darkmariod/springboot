import { createRouter, createWebHistory } from 'vue-router'
import Login from '../views/Login.vue'
import MainLayout from '../layouts/MainLayout.vue'

const router = createRouter({
  history: createWebHistory('/app/'),
  routes: [
    { path: '/login', name: 'login', component: Login },
    { path: '/', name: 'app', component: MainLayout },
  ],
})

// Guard: sin token → al login.
router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  if (to.name !== 'login' && !token) return { name: 'login' }
  if (to.name === 'login' && token) return { name: 'app' }
})

export default router
