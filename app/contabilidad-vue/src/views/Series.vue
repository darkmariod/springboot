<script setup lang="ts">
import { ref } from 'vue'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const serie = ref('')
const resultado = ref<any>(null)
const error = ref('')
const buscando = ref(false)

async function buscar() {
  if (!serie.value.trim()) return
  buscando.value = true; error.value = ''; resultado.value = null
  try {
    resultado.value = (await api.get('/series/trace?company_id=' + company.activeId +
      '&serie=' + encodeURIComponent(serie.value))).data
  } catch {
    error.value = 'No se encontró la serie ' + serie.value
  } finally { buscando.value = false }
}
</script>

<template>
  <div style="padding:24px; max-width:640px;">
    <h2 style="margin:0 0 4px;">Consulta de garantía por serie</h2>
    <p style="color:#94a3b8; font-size:13px; margin:0 0 18px;">
      Escanee o escriba la serie: le indica a qué proveedor le compró esa unidad y a qué cliente se la vendió.
    </p>

    <div style="display:flex; gap:8px; margin-bottom:18px;">
      <InputText v-model="serie" placeholder="Serie / IMEI" style="flex:1"
                 @keydown.enter="buscar" />
      <Button label="Buscar" icon="pi pi-search" :loading="buscando" @click="buscar" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="resultado" style="background:#fff; border:1px solid #e2e5ea; border-radius:12px; padding:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <div>
          <div style="font-size:12px; color:#94a3b8;">PRODUCTO</div>
          <b style="font-size:16px;">{{ resultado.product?.descripcion }}</b>
          <div style="font-family:monospace; color:#64748b;">{{ resultado.serie }}</div>
        </div>
        <Tag :value="resultado.estado" :severity="resultado.estado === 'vendida' ? 'info' : 'success'" />
      </div>

      <div style="border-top:1px solid #f1f3f6; padding-top:14px; margin-bottom:14px;">
        <div style="font-size:12px; color:#94a3b8;">SE LA COMPRÓ A</div>
        <b>{{ resultado.purchase?.contact?.razon_social ?? '— sin compra registrada —' }}</b>
        <div v-if="resultado.purchase" style="color:#64748b; font-size:13px;">
          Factura {{ resultado.purchase.numero }}
        </div>
      </div>

      <div style="border-top:1px solid #f1f3f6; padding-top:14px;">
        <div style="font-size:12px; color:#94a3b8;">SE LA VENDIÓ A</div>
        <b>{{ resultado.invoice?.contact?.razon_social ?? '— todavía en stock —' }}</b>
        <div v-if="resultado.invoice" style="color:#64748b; font-size:13px;">
          Factura {{ resultado.invoice.numero }}
        </div>
      </div>
    </div>
  </div>
</template>
