<script setup lang="ts">
/**
 * Períodos contables (T4.3a): los 12 meses del año con su estado y lo que tienen dentro, cerrar un mes, reabrirlo
 * (solo el administrador, con motivo) y cerrar el ejercicio.
 * Un mes cerrado no admite asientos nuevos ni cambios en los que ya tiene: ni una factura, una compra, un cobro, una nota
 * o una anulación con fecha de ese mes. Para mover algo ahí hay que reabrir el período.
 */
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Textarea from 'primevue/textarea'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useAuthStore } from '../stores/auth'

interface Bloqueo { tipo: string; numero: string; concepto: string | null; motivo: string }
interface Mes {
  mes: number; periodo: string; nombre: string; estado: 'abierto' | 'cerrado'
  cerrado_at: string | null; cerrado_por: string | null; reabierto_at: string | null; motivo_reapertura: string | null
  asientos: { borrador: number; pendiente: number; mayorizado: number; total: number }
  descuadrados: number; total_debe: number; total_haber: number; puede_cerrar: boolean; bloqueos: Bloqueo[]
}

const company = useCompanyStore()
const auth = useAuthStore()
// Cerrar es del administrador y del contador; reabrir, solo del administrador (el servidor lo exige igual)
const puedeCerrar = computed(() => ['admin', 'contador'].includes(auth.user?.rol ?? ''))
const puedeReabrir = computed(() => auth.user?.rol === 'admin')

const hoy = new Date().getFullYear()
const anio = ref(hoy)
const opcionesAnio = Array.from({ length: 8 }, (_, i) => hoy + 1 - i).map((y) => ({ label: String(y), value: y }))
const datos = ref<{ meses: Mes[]; ejercicio: any } | null>(null)
const cargando = ref(false)
const trabajando = ref(false)
const aviso = ref<{ tipo: 'success' | 'error' | 'warn'; texto: string } | null>(null)

const money = (n: any) => '$' + Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// Una fecha sola ("2026-12-31") se arma tal cual: pasarla por Date la corre un día hacia atrás en Ecuador (UTC-5)
const fecha = (iso: string | null) => {
  if (!iso) return ''
  return /^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso.split('-').reverse().join('/') : new Date(iso).toLocaleDateString('es-EC')
}

function textoError(e: any, defecto: string) {
  const errores = e?.response?.data?.errors
  return errores ? (Object.values(errores).flat() as string[]).join(' · ') : (e?.response?.data?.message ?? defecto)
}

async function cargar() {
  cargando.value = true
  try {
    datos.value = (await api.get('/accounting/periodos', { params: { company_id: company.activeId, anio: anio.value } })).data
  } catch (e: any) {
    aviso.value = { tipo: 'error', texto: textoError(e, 'No se pudieron cargar los períodos.') }
  } finally {
    cargando.value = false
  }
}

// ─── Cerrar un mes ───
const porCerrar = ref<Mes | null>(null)
const verCierre = ref(false)
const bloqueos = ref<Bloqueo[]>([])
const errorCierre = ref('')
function pedirCierre(m: Mes) {
  porCerrar.value = m
  bloqueos.value = []
  errorCierre.value = ''
  verCierre.value = true
}
async function cerrarMes() {
  const m = porCerrar.value
  if (!m) return
  trabajando.value = true
  errorCierre.value = ''
  bloqueos.value = []
  try {
    const res = await api.post('/accounting/periodos/cerrar', { company_id: company.activeId, anio: anio.value, mes: m.mes })
    verCierre.value = false
    aviso.value = {
      tipo: 'success',
      texto: `Período ${res.data.periodo} cerrado. ` + (res.data.mayorizados
        ? `Se mayorizaron ${res.data.mayorizados} ${res.data.mayorizados === 1 ? 'asiento' : 'asientos'}.`
        : 'No había asientos pendientes.'),
    }
    await cargar()
  } catch (e: any) {
    // 422: lo que impide cerrar (borradores o asientos descuadrados) viene en `bloqueos`
    errorCierre.value = textoError(e, 'No se pudo cerrar el período.')
    bloqueos.value = e?.response?.data?.bloqueos ?? []
  } finally {
    trabajando.value = false
  }
}

// ─── Reabrir un mes ───
const porReabrir = ref<Mes | null>(null)
const verReapertura = ref(false)
const motivo = ref('')
const errorReapertura = ref('')
function pedirReapertura(m: Mes) {
  porReabrir.value = m
  motivo.value = ''
  errorReapertura.value = ''
  verReapertura.value = true
}
async function reabrirMes() {
  const m = porReabrir.value
  if (!m) return
  if (motivo.value.trim().length < 3) {
    errorReapertura.value = 'Escribe el motivo de la reapertura.'
    return
  }
  trabajando.value = true
  errorReapertura.value = ''
  try {
    const res = await api.post('/accounting/periodos/reabrir', { company_id: company.activeId, anio: anio.value, mes: m.mes, motivo: motivo.value.trim() })
    verReapertura.value = false
    aviso.value = { tipo: 'warn', texto: `Período ${res.data.periodo} reabierto. Queda registrado quién lo reabrió y por qué; recuerda cerrarlo de nuevo al terminar.` }
    await cargar()
  } catch (e: any) {
    errorReapertura.value = textoError(e, 'No se pudo reabrir el período.')
  } finally {
    trabajando.value = false
  }
}

// ─── Cierre del ejercicio ───
const verEjercicio = ref(false)
const errorEjercicio = ref('')
const ejercicio = computed(() => datos.value?.ejercicio ?? null)
function pedirEjercicio() {
  errorEjercicio.value = ''
  verEjercicio.value = true
}
async function cerrarEjercicio() {
  trabajando.value = true
  errorEjercicio.value = ''
  try {
    const res = await api.post('/accounting/cierre-ejercicio', { company_id: company.activeId, anio: anio.value })
    verEjercicio.value = false
    const r = Number(res.data.resultado)
    aviso.value = {
      tipo: 'success',
      texto: `Ejercicio ${res.data.anio} cerrado con el asiento ${res.data.asiento.numero} del 31/12: ${r < 0 ? 'pérdida' : 'utilidad'} de ${money(Math.abs(r))} llevada a Resultado del ejercicio.`,
    }
    await cargar()
  } catch (e: any) {
    errorEjercicio.value = textoError(e, 'No se pudo cerrar el ejercicio.')
  } finally {
    trabajando.value = false
  }
}

const hayMesesAbiertos = computed(() => (ejercicio.value?.meses_abiertos?.length ?? 0) > 0)
watch(anio, () => { aviso.value = null; cargar() })
onMounted(cargar)
</script>

<template>
  <div class="pc">
    <div class="encabezado">
      <div>
        <h2>Períodos contables</h2>
        <p class="sub">
          Cierra cada mes cuando esté revisado: se mayorizan sus asientos y ya no se puede registrar ni modificar nada con fecha de ese mes.
        </p>
      </div>
      <label class="anio">Año <Select v-model="anio" :options="opcionesAnio" optionLabel="label" optionValue="value" style="width: 110px;" aria-label="Año" data-test="anio" /></label>
    </div>

    <Message v-if="aviso" :severity="aviso.tipo" :closable="true" @close="aviso = null" style="margin-bottom: 10px;" data-test="aviso">{{ aviso.texto }}</Message>

    <DataTable :value="datos?.meses ?? []" :loading="cargando" size="small" stripedRows dataKey="mes" data-test="tabla">
      <Column header="Mes" style="min-width: 130px;">
        <template #body="{ data }"><b>{{ data.nombre }}</b> <small class="gris">{{ data.periodo }}</small></template>
      </Column>
      <Column header="Estado" style="min-width: 150px;">
        <template #body="{ data }">
          <Tag v-if="data.estado === 'cerrado'" value="Cerrado" severity="secondary" icon="pi pi-lock" :data-estado="data.periodo"
               v-tooltip.top="'Cerrado ' + fecha(data.cerrado_at) + (data.cerrado_por ? ' por ' + data.cerrado_por : '')" />
          <Tag v-else value="Abierto" severity="success" icon="pi pi-lock-open" :data-estado="data.periodo" />
          <div v-if="data.estado === 'abierto' && data.motivo_reapertura" class="gris" style="font-size: 11px;">
            Reabierto: {{ data.motivo_reapertura }}
          </div>
        </template>
      </Column>
      <Column header="Borrador" style="width: 90px; text-align: center;">
        <template #body="{ data }"><span :class="{ alerta: data.asientos.borrador > 0 }">{{ data.asientos.borrador }}</span></template>
      </Column>
      <Column header="Pendiente" style="width: 90px; text-align: center;">
        <template #body="{ data }">{{ data.asientos.pendiente }}</template>
      </Column>
      <Column header="Mayorizado" style="width: 100px; text-align: center;">
        <template #body="{ data }">{{ data.asientos.mayorizado }}</template>
      </Column>
      <Column header="Total debe" style="min-width: 110px; text-align: right;">
        <template #body="{ data }">{{ money(data.total_debe) }}</template>
      </Column>
      <Column header="Total haber" style="min-width: 110px; text-align: right;">
        <template #body="{ data }">{{ money(data.total_haber) }}</template>
      </Column>
      <Column header="" style="min-width: 210px;">
        <template #body="{ data }">
          <Button v-if="data.estado === 'abierto' && puedeCerrar" label="Cerrar período" icon="pi pi-lock" size="small" severity="secondary"
                  :disabled="trabajando || ejercicio?.cerrado" :data-cerrar="data.periodo" @click="pedirCierre(data)" />
          <Button v-if="data.estado === 'cerrado' && puedeReabrir && !ejercicio?.cerrado" label="Reabrir" icon="pi pi-lock-open" size="small" text severity="warn"
                  :disabled="trabajando" :data-reabrir="data.periodo" @click="pedirReapertura(data)" />
          <small v-if="data.estado === 'abierto' && !data.puede_cerrar" class="alerta" style="margin-left: 6px;"
                 v-tooltip.top="'Tiene borradores o asientos descuadrados que impiden el cierre'">
            <i class="pi pi-exclamation-triangle"></i> {{ data.bloqueos.length }}
          </small>
        </template>
      </Column>
    </DataTable>

    <!-- Cierre del ejercicio -->
    <div class="ejercicio" v-if="ejercicio" data-test="ejercicio">
      <div>
        <div class="titulo">Cierre del ejercicio {{ anio }}</div>
        <p v-if="ejercicio.cerrado" class="gris" style="margin: 2px 0 0;">
          <Tag value="Ejercicio cerrado" severity="success" icon="pi pi-check" />
          Asiento de cierre {{ ejercicio.asiento?.numero }} del {{ fecha(ejercicio.asiento?.fecha) }}. Los meses de este año ya no se pueden reabrir.
        </p>
        <p v-else-if="hayMesesAbiertos" class="gris" style="margin: 2px 0 0;">
          Cierra primero los 12 meses para poder cerrar el ejercicio. Faltan {{ ejercicio.meses_abiertos.length }}.
        </p>
        <p v-else class="gris" style="margin: 2px 0 0;">
          Los 12 meses están cerrados. El cierre crea un asiento del 31/12 que lleva a cero los ingresos y los gastos del año contra Resultado del ejercicio.
        </p>
      </div>
      <Button v-if="puedeCerrar" label="Cierre del ejercicio" icon="pi pi-calendar-times" :disabled="!ejercicio.puede_cerrar || trabajando"
              @click="pedirEjercicio" data-test="cerrar-ejercicio" />
    </div>

    <!-- Confirmar el cierre de un mes -->
    <Dialog v-model:visible="verCierre" modal :header="'Cerrar el período ' + (porCerrar?.periodo ?? '')" style="width: 560px;">
      <template v-if="porCerrar">
        <p style="margin-top: 0;">
          Se mayorizarán <b>{{ porCerrar.asientos.pendiente }}</b> {{ porCerrar.asientos.pendiente === 1 ? 'asiento pendiente' : 'asientos pendientes' }}
          de {{ porCerrar.nombre }} {{ anio }} y el mes quedará cerrado: no se podrán registrar ni modificar movimientos con fecha de ese mes
          (facturas, compras, cobros, notas ni anulaciones) hasta que el administrador lo reabra.
        </p>
        <Message v-if="errorCierre" severity="error" :closable="false" data-test="error-cierre">{{ errorCierre }}</Message>
        <ul v-if="bloqueos.length" class="bloqueos" data-test="bloqueos">
          <li v-for="b in bloqueos" :key="b.numero + b.tipo">
            <b>{{ b.numero }}</b> <span v-if="b.concepto">— {{ b.concepto }}</span><br />
            <small>{{ b.motivo }}</small>
          </li>
        </ul>
      </template>
      <template #footer>
        <Button label="Cancelar" text @click="verCierre = false" />
        <Button label="Cerrar período" icon="pi pi-lock" :loading="trabajando" @click="cerrarMes" data-test="confirmar-cierre" />
      </template>
    </Dialog>

    <!-- Reabrir -->
    <Dialog v-model:visible="verReapertura" modal :header="'Reabrir el período ' + (porReabrir?.periodo ?? '')" style="width: 520px;">
      <p style="margin-top: 0;">
        Al reabrir el mes se podrá volver a registrar y modificar movimientos con fecha de ese mes. Queda registrado quién lo reabrió y por qué.
      </p>
      <label class="campo">Motivo *
        <Textarea v-model="motivo" rows="3" fluid placeholder="Por ejemplo: falta registrar una factura de proveedor" data-test="motivo" />
      </label>
      <Message v-if="errorReapertura" severity="error" :closable="false" style="margin-top: 8px;" data-test="error-reapertura">{{ errorReapertura }}</Message>
      <template #footer>
        <Button label="Cancelar" text @click="verReapertura = false" />
        <Button label="Reabrir período" icon="pi pi-lock-open" severity="warn" :loading="trabajando" @click="reabrirMes" data-test="confirmar-reapertura" />
      </template>
    </Dialog>

    <!-- Cierre del ejercicio -->
    <Dialog v-model:visible="verEjercicio" modal :header="'Cerrar el ejercicio ' + anio" style="width: 540px;">
      <p style="margin-top: 0;">
        Se creará <b>un asiento del 31/12/{{ anio }}</b>, ya mayorizado, que lleva a cero cada cuenta de ingresos y de gastos del año y pasa la diferencia
        (utilidad o pérdida) a <b>Resultado del ejercicio</b>. El estado de resultados de {{ anio }} seguirá mostrando lo vendido y lo gastado.
      </p>
      <p>Esto no se puede deshacer desde el sistema y los meses de {{ anio }} dejarán de poder reabrirse.</p>
      <Message v-if="errorEjercicio" severity="error" :closable="false" data-test="error-ejercicio">{{ errorEjercicio }}</Message>
      <template #footer>
        <Button label="Cancelar" text @click="verEjercicio = false" />
        <Button label="Cerrar el ejercicio" icon="pi pi-calendar-times" :loading="trabajando" @click="cerrarEjercicio" data-test="confirmar-ejercicio" />
      </template>
    </Dialog>
  </div>
</template>

<style scoped>
.pc { padding: 20px; max-width: 1100px; }
.encabezado { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.pc h2 { margin: 0 0 4px; color: #1f2733; }
.sub { color: #94a3b8; font-size: 13px; margin: 0; max-width: 640px; }
.anio { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: #475569; }
.gris { color: #94a3b8; }
.alerta { color: #b45309; font-weight: 600; }
.ejercicio { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 6px; flex-wrap: wrap; }
.ejercicio .titulo { font-weight: 700; color: #1f2733; }
.bloqueos { margin: 8px 0 0; padding-left: 18px; max-height: 220px; overflow: auto; font-size: 13px; }
.bloqueos li { margin-bottom: 6px; }
.campo { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
</style>
