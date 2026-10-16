<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import EnviarCorreoDialog from '../components/EnviarCorreoDialog.vue'

const company = useCompanyStore()
const rows = ref<any[]>([])
// Los últimos autorizados: son los que se mandan al cliente, aquí se ve si el correo ya salió
const autorizados = ref<any[]>([])
const seleccion = ref<any[]>([])
const loading = ref(true)
const procesando = ref<'seleccion' | 'todos' | null>(null)
const msg = ref<any>(null)
// Resultado de la última corrida: un renglón por comprobante (autorizado / pendiente / error + mensaje)
const resultado = ref<any>(null)

const estadoSev = (e: string) => {
  const x = String(e ?? '').toLowerCase()
  if (x === 'enviado' || x === 'firmado') return 'info'
  if (x.includes('no autorizado') || x === 'devuelta' || x === 'rechazado') return 'danger'
  return 'warn'
}
const resultadoSev: Record<string, string> = { autorizado: 'success', pendiente: 'warn', error: 'danger' }
// El servidor guarda el tipo con el nombre técnico del SRI; en pantalla se muestra el nombre de siempre
const tipoTxt: Record<string, string> = {
  factura: 'Factura', notaCredito: 'Nota de crédito', notaDebito: 'Nota de débito',
  comprobanteRetencion: 'Retención', liquidacionCompra: 'Liquidación de compra', guiaRemision: 'Guía de remisión',
}
const resultadoTxt: Record<string, string> = { autorizado: 'Autorizado', pendiente: 'Pendiente', error: 'Con error' }
const hayPendientes = computed(() => rows.value.length > 0)

// Estado del correo de cada comprobante (lo calcula el servidor con una sola consulta para toda la lista)
const correoTxt: Record<string, string> = { enviado: 'Enviado', error: 'Error', sin_enviar: 'Sin enviar' }
const correoSev: Record<string, string> = { enviado: 'success', error: 'danger', sin_enviar: 'secondary' }
function correoTitulo(c: any) {
  if (!c) return ''
  if (c.estado === 'enviado') return 'Enviado a ' + (c.destinatarios ?? []).join(', ')
  if (c.estado === 'error') return c.motivo ?? 'No se pudo enviar el correo'
  return 'Todavía no se ha enviado por correo'
}

// Diálogo "Enviar por correo" del comprobante elegido
const correoVisible = ref(false)
const correoDe = ref<any>(null)
function abrirCorreo(fila: any) {
  if (!fila.comprobante) return
  correoDe.value = { tipo: fila.comprobante.tipo, id: fila.comprobante.id, etiqueta: (tipoTxt[fila.tipo_comprobante] ?? 'Comprobante') + ' ' + (fila.numero ?? '') }
  correoVisible.value = true
}

async function load() {
  loading.value = true
  try {
    rows.value = (await api.get('/sri-documents/pending?company_id=' + company.activeId)).data
    autorizados.value = (await api.get('/sri-documents/authorized?company_id=' + company.activeId)).data
  } finally {
    loading.value = false
  }
}

// Cada comprobante recorre los pasos que le faltan: firmar (si ya hay certificado) → enviar al SRI → consultar la autorización.
// Sin ids se procesan todos los pendientes de la empresa.
async function ejecutar(ids: number[] | null) {
  procesando.value = ids ? 'seleccion' : 'todos'
  msg.value = null
  resultado.value = null
  try {
    const res = await api.post('/sri-documents/authorize-batch', { company_id: company.activeId, ...(ids ? { ids } : {}) })
    const d = res.data
    resultado.value = d
    const partes = [
      'Procesados: ' + d.procesados,
      'Autorizados: ' + d.autorizados,
      'Pendientes de respuesta del SRI: ' + d.pendientes,
      'Con error: ' + d.fallidos,
    ]
    msg.value = {
      type: d.procesados > 0 && d.autorizados === d.procesados ? 'success' : (d.fallidos > 0 ? 'warn' : 'info'),
      text: partes.join(' · ') + (d.mensaje ? ' — ' + d.mensaje : ''),
    }
  } catch (e: any) {
    const errs = e?.response?.data?.errors
    msg.value = { type: 'error', text: errs ? Object.values(errs).flat().join(' · ') : (e?.response?.data?.message ?? 'No se pudo procesar los comprobantes.') }
  } finally {
    procesando.value = null
    seleccion.value = []
    await load()
  }
}
const reenviarSeleccionados = () => ejecutar(seleccion.value.map((r: any) => r.id))
const autorizarTodos = () => ejecutar(null)

onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px; flex-wrap:wrap;">
      <div><h2 style="margin:0;">Documentos SRI</h2>
        <p style="color:#94a3b8; font-size:13px; margin:4px 0 0;">
          Comprobantes que el SRI aún no autoriza. Cada uno recorre los pasos que le faltan: firmar, enviar y consultar la autorización.</p></div>
      <div style="display:flex; gap:8px;">
        <Button :label="'Reenviar seleccionados' + (seleccion.length ? ' (' + seleccion.length + ')' : '')" icon="pi pi-send" outlined
                :loading="procesando === 'seleccion'" :disabled="!seleccion.length || !!procesando" @click="reenviarSeleccionados" />
        <Button label="Autorizar todos" icon="pi pi-check-square"
                :loading="procesando === 'todos'" :disabled="!hayPendientes || !!procesando" @click="autorizarTodos" />
      </div>
    </div>
    <Message v-if="msg" :severity="msg.type" :closable="true" style="margin-bottom:14px;" @close="msg = null">{{ msg.text }}</Message>

    <!-- Resultado de la última corrida, comprobante por comprobante -->
    <div v-if="resultado && resultado.resultados.length" style="margin-bottom:18px;">
      <h4 style="margin:0 0 6px;">Resultado</h4>
      <DataTable :value="resultado.resultados" size="small" stripedRows>
        <Column header="Número"><template #body="{ data }">{{ data.numero ?? '—' }}</template></Column>
        <Column header="Tipo"><template #body="{ data }">{{ tipoTxt[data.tipo_comprobante] ?? data.tipo_comprobante }}</template></Column>
        <Column header="Estado"><template #body="{ data }"><Tag :value="data.estado" :severity="estadoSev(data.estado)" /></template></Column>
        <Column header="Resultado"><template #body="{ data }">
          <Tag :value="resultadoTxt[data.resultado] ?? data.resultado" :severity="resultadoSev[data.resultado] ?? 'secondary'" />
        </template></Column>
        <Column header="Mensaje"><template #body="{ data }"><span style="font-size:12px;">{{ data.mensaje }}</span></template></Column>
      </DataTable>
    </div>

    <h4 style="margin:0 0 6px;">Por autorizar</h4>
    <DataTable v-model:selection="seleccion" :value="rows" :loading="loading" size="small" stripedRows dataKey="id">
      <Column selectionMode="multiple" headerStyle="width:3rem" />
      <Column header="Número"><template #body="{ data }">{{ data.numero ?? '—' }}</template></Column>
      <Column header="Tipo"><template #body="{ data }">{{ tipoTxt[data.tipo_comprobante] ?? data.tipo_comprobante }}</template></Column>
      <Column header="Clave de acceso"><template #body="{ data }"><span style="font-family:monospace; font-size:11px;">{{ data.clave_acceso }}</span></template></Column>
      <Column header="Estado"><template #body="{ data }"><Tag :value="data.estado" :severity="estadoSev(data.estado)" /></template></Column>
      <Column header="Último mensaje"><template #body="{ data }"><span style="font-size:12px; color:#b45309;">{{ data.detalle ?? '' }}</span></template></Column>
      <Column header="Correo"><template #body="{ data }">
        <template v-if="data.correo">
          <Tag :value="correoTxt[data.correo.estado]" :severity="correoSev[data.correo.estado]" :title="correoTitulo(data.correo)" data-test="correo-estado" />
          <Button icon="pi pi-envelope" size="small" text title="Enviar por correo" aria-label="Enviar por correo" @click="abrirCorreo(data)" />
        </template>
        <span v-else style="color:#94a3b8;" title="Este comprobante no se envía por correo">—</span>
      </template></Column>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha_emision).slice(0,10) }}</template></Column>
    </DataTable>
    <p v-if="!loading && !rows.length" style="color:#22a06b; text-align:center; padding:20px;">
      ✓ Todos los comprobantes están autorizados.</p>

    <!-- Autorizados: el correo sale al cliente con el RIDE y el XML autorizado -->
    <h4 style="margin:22px 0 2px;">Autorizados recientes</h4>
    <p style="color:#94a3b8; font-size:12.5px; margin:0 0 6px;">
      Los últimos comprobantes que el SRI ya autorizó y el estado de su correo al cliente.</p>
    <DataTable :value="autorizados" :loading="loading" size="small" stripedRows dataKey="id" data-test="tabla-autorizados">
      <Column header="Número"><template #body="{ data }">{{ data.numero ?? '—' }}</template></Column>
      <Column header="Tipo"><template #body="{ data }">{{ tipoTxt[data.tipo_comprobante] ?? data.tipo_comprobante }}</template></Column>
      <Column header="Cliente"><template #body="{ data }">{{ data.cliente ?? '—' }}</template></Column>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha_emision).slice(0,10) }}</template></Column>
      <Column header="Correo"><template #body="{ data }">
        <template v-if="data.correo">
          <Tag :value="correoTxt[data.correo.estado]" :severity="correoSev[data.correo.estado]" :title="correoTitulo(data.correo)" data-test="correo-estado" />
          <Button :label="data.correo.estado === 'enviado' ? 'Reenviar' : 'Enviar'" icon="pi pi-envelope" size="small" text
                  data-test="correo-accion" @click="abrirCorreo(data)" />
        </template>
        <span v-else style="color:#94a3b8;" title="Este comprobante no se envía por correo">—</span>
      </template></Column>
      <template #empty>Todavía no hay comprobantes autorizados.</template>
    </DataTable>

    <EnviarCorreoDialog v-if="correoDe" v-model:visible="correoVisible" :tipo="correoDe.tipo" :comprobanteId="correoDe.id"
                        :etiqueta="correoDe.etiqueta" @enviado="load" />
  </div>
</template>
