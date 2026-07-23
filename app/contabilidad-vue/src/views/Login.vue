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
        <img src="/logo-has-reset.png" alt="HasReset" class="hr-logo" />
        <p class="login-subtitle">Sistema Contable</p>
      </div>

      <form @submit.prevent="submit" class="login-form">
        <label>
          <span>Usuario</span>
          <InputText v-model="email" placeholder="Ingrese su usuario" fluid />
        </label>
        <label>
          <span>Contraseña</span>
          <Password v-model="password" placeholder="Ingrese su contraseña" :feedback="false" toggleMask fluid />
        </label>

        <small v-if="error" class="login-error">{{ error }}</small>

        <Button type="submit" label="Ingresar" :loading="loading" fluid class="login-btn" />
        <a href="#" class="login-forgot" @click.prevent>¿Olvidó su contraseña?</a>
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
  background: linear-gradient(135deg, var(--hr-navy) 0%, var(--hr-navy-light) 100%);
  position: relative;
  overflow: hidden;
}
.login-wrap::before,
.login-wrap::after {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.login-wrap::before {
  background:
    radial-gradient(1.5px 1.5px at 10% 20%, rgba(255,255,255,0.5) 50%, transparent 50%),
    radial-gradient(1px 1px at 30% 65%, rgba(255,255,255,0.35) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 55% 15%, rgba(255,255,255,0.4) 50%, transparent 50%),
    radial-gradient(1px 1px at 70% 80%, rgba(255,255,255,0.3) 50%, transparent 50%),
    radial-gradient(2px 2px at 85% 30%, rgba(255,255,255,0.45) 50%, transparent 50%),
    radial-gradient(1px 1px at 20% 90%, rgba(255,255,255,0.25) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 45% 50%, rgba(255,255,255,0.35) 50%, transparent 50%),
    radial-gradient(1px 1px at 90% 55%, rgba(255,255,255,0.3) 50%, transparent 50%);
}
.login-wrap::after {
  background:
    radial-gradient(1px 1px at 15% 45%, rgba(255,255,255,0.3) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 40% 85%, rgba(255,255,255,0.35) 50%, transparent 50%),
    radial-gradient(1px 1px at 60% 35%, rgba(255,255,255,0.25) 50%, transparent 50%),
    radial-gradient(1.5px 1.5px at 75% 10%, rgba(255,255,255,0.4) 50%, transparent 50%),
    radial-gradient(1px 1px at 50% 70%, rgba(255,255,255,0.2) 50%, transparent 50%),
    radial-gradient(1px 1px at 95% 75%, rgba(255,255,255,0.3) 50%, transparent 50%);
}
.login-card {
  width: 360px;
  background: #fff;
  border: 1px solid #e2e5ea;
  border-radius: 12px;
  padding: 28px 26px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
  position: relative;
  z-index: 1;
}
.login-brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 22px;
}
.hr-logo { width: 140px; height: auto; object-fit: contain; }
.login-subtitle { font-size: 12px; color: #8a94a6; margin: 4px 0 0; text-align: center; }
.login-form { display: flex; flex-direction: column; gap: 14px; }
.login-form label { display: flex; flex-direction: column; gap: 5px; font-size: 13px; color: #475569; }
.login-error { color: #d93025; font-size: 12px; }
/* Botón Ingresar en AZUL de marca (no el verde del tema) — pedido del cliente */
.login-btn {
  background: var(--hr-blue) !important;
  border-color: var(--hr-blue) !important;
  color: #fff !important;
}
.login-btn:hover { background: var(--hr-blue-hover) !important; border-color: var(--hr-blue-hover) !important; }
.login-forgot {
  text-align: center;
  font-size: 12px;
  color: var(--hr-blue);
  text-decoration: none;
  margin-top: -4px;
}
.login-forgot:hover { text-decoration: underline; }
</style>
