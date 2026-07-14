<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('admin@demo.com')
const password = ref('password123')
const error = ref<string | null>(null)
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = null
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'app' })
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'No se pudo iniciar sesión.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-brand">
        <i class="pi pi-calculator" style="font-size: 1.6rem" />
        <div>
          <h1>Sistema Contable</h1>
          <p>Facturación Electrónica SRI · Ecuador</p>
        </div>
      </div>

      <form @submit.prevent="submit" class="login-form">
        <label>
          <span>Correo</span>
          <InputText v-model="email" type="email" fluid />
        </label>
        <label>
          <span>Contraseña</span>
          <Password v-model="password" :feedback="false" toggleMask fluid />
        </label>

        <small v-if="error" class="login-error">{{ error }}</small>

        <Button type="submit" label="Ingresar" :loading="loading" fluid />
      </form>
    </div>
  </div>
</template>

<style scoped>
.login-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f1f3f6;
}
.login-card {
  width: 360px;
  background: #fff;
  border: 1px solid #e2e5ea;
  border-radius: 12px;
  padding: 28px 26px;
  box-shadow: 0 10px 30px rgba(30, 41, 59, 0.08);
}
.login-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 22px;
  color: #2c3e50;
}
.login-brand h1 { font-size: 18px; margin: 0; }
.login-brand p { font-size: 12px; color: #8a94a6; margin: 2px 0 0; }
.login-form { display: flex; flex-direction: column; gap: 14px; }
.login-form label { display: flex; flex-direction: column; gap: 5px; font-size: 13px; color: #475569; }
.login-error { color: #d93025; font-size: 12px; }
</style>
