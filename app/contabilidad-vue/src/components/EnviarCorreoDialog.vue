<script setup lang="ts">
/**
 * Enviar un comprobante por correo (T10.3): factura, nota de crédito, nota de débito o retención emitida.
 * Va el RIDE en PDF y el XML (el autorizado por el SRI si ya lo autorizó). Los destinatarios salen precargados con los correos del
 * cliente (o del proveedor, en una retención) y se pueden cambiar. Abajo queda el historial de envíos de ese comprobante.
 * El servidor es quien valida y quien envía: aquí solo se arma la lista y se muestra el resultado.
 */
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputChips from 'primevue/inputchips'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const props = defineProps<{
  visible: boolean
  /** factura | nota_credito | nota_debito | retencion */
  tipo: string
  comprobanteId: number | null
  /** Cómo se nombra en el título: "Factura 001-001-000000123" */
  etiqueta?: string
}>()
const emit = defineEmits<{ (e: 'update:visible', v: boolean): void; (e: 'enviado', r: any): void }>()

const company = useCompanyStore()
const cargando = ref(false)
const enviando = ref(false)
const error = ref('')
const resultado = ref<{ tipo: string; texto: string } | null>(null)
const destinatarios = ref<string[]>([])
const historial = ref<any[]>([])
const estado = ref<any>({ autorizado: false, smtp_configurado: true, envio_automatico: false })

const RE_CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const invalidos = computed(() => destinatarios.value.filter(c => !RE_CORREO.test(c.trim())))
const puedeEnviar = computed(() =>
  destinatarios.value.length > 0 && destinatarios.value.length <= 5 && invalidos.value.length === 0 && !enviando.value && !cargando.value)

const estadoSev: Record<string, string> = { enviado: 'success', error: 'danger', omitido: 'warn' }
const estadoTxt: Record<string, string> = { enviado: 'Enviado', error: 'Error', omitido: 'No se envió' }
const fecha = (h: any) => {
  const d = new Date(h.sent_at ?? h.created_at)
  if (isNaN(d.getTime())) return '—'
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`
}

async function cargar() {
  if (!props.comprobanteId) return
  cargando.value = true; error.value = ''; resultado.value = null
  try {
    const { data } = await api.get(`/comprobantes/${props.tipo}/${props.comprobanteId}/correos`, { params: { company_id: company.activeId } })
    destinatarios.value = [...(data.sugeridos ?? [])]
    historial.value = data.correos ?? []
    estado.value = { autorizado: !!data.autorizado, smtp_configurado: !!data.smtp_configurado, envio_automatico: !!data.envio_automatico }
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'No se pudo consultar el historial de correos. Inténtalo de nuevo.'
  } finally {
    cargando.value = false
  }
}

async function enviar() {
  if (!puedeEnviar.value) return
  enviando.value = true; error.value = ''; resultado.value = null
  try {
    const { data } = await api.post(`/comprobantes/${props.tipo}/${props.comprobanteId}/enviar-correo`, {
      company_id: company.activeId, destinatarios: destinatarios.value.map(c => c.trim()),
    })
    resultado.value = { tipo: data.estado === 'enviado' ? 'success' : (data.estado === 'error' ? 'error' : 'warn'), texto: data.mensaje }
    emit('enviado', data)
    const h = await api.get(`/comprobantes/${props.tipo}/${props.comprobanteId}/correos`, { params: { company_id: company.activeId } })
    historial.value = h.data.correos ?? []
  } catch (e: any) {
    const errs = e?.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' · ') : (e?.response?.data?.message ?? 'No se pudo enviar el correo. Inténtalo de nuevo.')
  } finally {
    enviando.value = false
  }
}

// immediate: el diálogo puede montarse ya visible (v-if en la pantalla que lo abre)
watch(() => props.visible, (v) => { if (v) cargar() }, { immediate: true })
</script>

<template>
  <Dialog :visible="visible" modal :header="'Enviar por correo' + (etiqueta ? ' — ' + etiqueta : '')" style="width: 680px"
          @update:visible="(v: boolean) => emit('update:visible', v)">
    <Message v-if="error" severity="error" :closable="false" style="margin-bottom: 10px;" data-testid="correo-error">{{ error }}</Message>
    <Message v-if="!estado.smtp_configurado" severity="warn" :closable="false" style="margin-bottom: 10px;" data-testid="correo-sin-smtp">
      La empresa todavía no tiene configurado el servidor de correo. Configúralo en e-documents (servidor, usuario y clave) para poder enviar.
    </Message>
    <Message v-if="!estado.autorizado" severity="info" :closable="false" style="margin-bottom: 10px;" data-testid="correo-pendiente">
      El SRI todavía no autoriza este comprobante. Puedes enviarlo igual: el RIDE lleva la leyenda de pendiente de autorización y el correo
      lo dice, sin número de autorización.
    </Message>

    <label class="etiqueta" for="correo-destinatarios">Enviar a</label>
    <InputChips v-model="destinatarios" inputId="correo-destinatarios" separator="," addOnBlur :allowDuplicate="false"
                placeholder="Escribe un correo y presiona Enter" class="chips" data-testid="correo-chips" />
    <p v-if="invalidos.length" class="aviso" data-testid="correo-invalidos">
      {{ invalidos.length === 1 ? 'Este correo no es válido: ' : 'Estos correos no son válidos: ' }}{{ invalidos.join(', ') }}. Corrígelo o quítalo.
    </p>
    <p v-else-if="destinatarios.length > 5" class="aviso">Puedes enviarlo hasta a 5 correos.</p>
    <p v-else-if="!destinatarios.length && !cargando" class="ayuda">
      Este comprobante no tiene un correo guardado: escribe uno para enviarlo.
    </p>

    <p class="ayuda" style="margin-top: 10px;" data-testid="correo-adjuntos">
      Se adjuntan el <b>RIDE en PDF</b> y el <b>XML</b>{{ estado.autorizado ? ' autorizado por el SRI' : ' (todavía sin autorización del SRI)' }}.
      <template v-if="estado.envio_automatico"> La empresa tiene activo el envío automático al autorizarse.</template>
    </p>

    <Message v-if="resultado" :severity="resultado.tipo" :closable="false" style="margin: 10px 0;" data-testid="correo-resultado">{{ resultado.texto }}</Message>

    <h4 style="margin: 16px 0 6px; font-size: 13px;">Envíos de este comprobante</h4>
    <table class="hist" data-testid="correo-historial">
      <thead><tr><th>Fecha</th><th>Para</th><th>Cómo</th><th>Estado</th></tr></thead>
      <tbody>
        <tr v-for="h in historial" :key="h.id">
          <td style="white-space: nowrap;">{{ fecha(h) }}</td>
          <td>{{ (h.destinatarios ?? []).join(', ') || '—' }}</td>
          <td>{{ h.automatico ? 'Automático' : 'Manual' }}</td>
          <td>
            <Tag :value="estadoTxt[h.estado] ?? h.estado" :severity="estadoSev[h.estado] ?? 'secondary'" size="small" />
            <div v-if="h.motivo" class="motivo">{{ h.motivo }}</div>
          </td>
        </tr>
        <tr v-if="!historial.length">
          <td colspan="4" class="vacio">{{ cargando ? 'Cargando...' : 'Todavía no se ha enviado por correo.' }}</td>
        </tr>
      </tbody>
    </table>

    <template #footer>
      <Button label="Cerrar" text @click="emit('update:visible', false)" />
      <Button label="Enviar" icon="pi pi-send" :disabled="!puedeEnviar" :loading="enviando" data-testid="correo-enviar" @click="enviar" />
    </template>
  </Dialog>
</template>

<style scoped>
.etiqueta { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.chips { width: 100%; }
.ayuda { margin: 6px 0 0; font-size: 12.5px; color: #64748b; }
.aviso { margin: 6px 0 0; font-size: 12.5px; color: #d93025; }
.hist { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.hist th { text-align: left; background: #f4f8f9; padding: 5px 8px; border-bottom: 1px solid #dbe6ea; }
.hist td { padding: 5px 8px; border-bottom: 1px solid #eef2f4; vertical-align: top; }
.hist .vacio { text-align: center; color: #94a3b8; padding: 12px; }
.motivo { margin-top: 3px; font-size: 11.5px; color: #b45309; max-width: 260px; }
</style>
