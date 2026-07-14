import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      // Redirige /api al backend Laravel (contabilidad-api en el puerto 8000)
      '/api': 'http://127.0.0.1:8000',
    },
  },
})
