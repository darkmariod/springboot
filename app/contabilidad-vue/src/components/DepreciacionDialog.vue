<script setup lang="ts">
/**
 * Depreciar el mes (T7.2). Primero «Simular» muestra qué activos se depreciarían y por cuánto, sin registrar nada; «Contabilizar»
 * lo registra en UN asiento por mes (Debe Gasto por depreciación / Haber Depreciación acumulada, fechado el último día del mes).
 * Si algún activo tiene meses anteriores sin depreciar, se ofrece incluirlos: los meses se depreciaron siempre en orden.
 * El servidor es quien decide; aquí solo se muestra su respuesta.
 */
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Select from 'primevue/select'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const props = defineProps<{ visible: boolean }>()
const emit = defineEmits<{ (e: 'update:visible', v: boolean): void; (e: 'done', respuesta: any): void }>()

const company = useCompanyStore()
const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
const ahora = new Date()
const anioActual = ahora.getFullYear()
const opcionesAnio = Array.from({ length: 8 }, (_, i) => anioActual - i).map((y) => ({ label: String(y), value: y }))
const opcionesMes = MESES.map((m, i) => ({ label: m, value: i + 1 }))

const anio = ref(anioActual)
const mes = ref(ahora.getMonth() + 1)
const incluirAnteriores = ref(false)
const pendienteDesde = ref<string | null>(null)
const resultado = ref<any>(null)      // la última simulación
const simulado = ref('')              // con qué parámetros se simuló: contabilizar solo vale si no cambiaron
const trabajando = ref(false)
const error = ref('')

const money = (n: any) => '$' + Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fecha = (iso: string) => String(iso).slice(0, 10).split('-').reverse().join('/')
const periodo = computed(() => `${anio.value}-${String(mes.value).padStart(2, '0')}`)
const clave = computed(() => `${periodo.value}|${incluirAnteriores.value ? pendienteDesde.value : ''}`)
const puedeContabilizar = computed(() => !!resultado.value && resultado.value.depreciados > 0 && simulado.value === clave.value && !trabajando.value)

function textoError(e: any, defecto: string) {
  const errores = e?.response?.data?.errors
  return errores ? (Object.values(errores).flat() as string[]).join(' · ') : (e?.response?.data?.message ?? defecto)
}

function cuerpo() {
  const base: any = { company_id: company.activeId }
  if (incluirAnteriores.value && pendienteDesde.value) return { ...base, desde: pendienteDesde.value, hasta: periodo.value }
  return { ...base, anio: anio.value, mes: mes.value }
}

function inicializar() {
  // Por defecto, el mes que acaba de terminar
  const previo = new Date(ahora.getFullYear(), ahora.getMonth() - 1, 1)
  anio.value = previo.getFullYear()
  mes.value = previo.getMonth() + 1
  incluirAnteriores.value = false
  pendienteDesde.value = null
  resultado.value = null
  simulado.value = ''
  error.value = ''
}

async function simular() {
  trabajando.value = true
  error.value = ''
  try {
    const clavePedida = clave.value
    const { data } = await api.post('/fixed-assets/depreciar', cuerpo(), { params: { simular: 1 } })
    resultado.value = data
    simulado.value = clavePedida
    if (data.pendiente_desde) pendienteDesde.value = data.pendiente_desde
  } catch (e: any) {
    resultado.value = null
    error.value = textoError(e, 'No se pudo simular la depreciación.')
  } finally {
    trabajando.value = false
  }
}

async function contabilizar() {
  if (!puedeContabilizar.value) return
  trabajando.value = true
  error.value = ''
  try {
    const { data } = await api.post('/fixed-assets/depreciar', cuerpo())
    emit('done', data)
    emit('update:visible', false)
  } catch (e: any) {
    error.value = textoError(e, 'No se pudo contabilizar la depreciación.')
  } finally {
    trabajando.value = false
  }
}

// Si cambia el período o la casilla, la simulación anterior ya no vale
watch([anio, mes], () => { resultado.value = null; simulado.value = ''; incluirAnteriores.value = false; pendienteDesde.value = null })
watch(incluirAnteriores, () => { resultado.value = null; simulado.value = ''; if (props.visible) simular() })
watch(() => props.visible, (v) => { if (v) inicializar() })
</script>

<template>
  <Dialog :visible="visible" modal header="Depreciar mes" style="width: 680px" @update:visible="(v: boolean) => emit('update:visible', v)" data-test="dep-dialogo">
    <p style="margin: 0 0 12px; font-size: 13px; color: #64748b;">
      Calcula la depreciación en línea recta de todos los activos que siguen en libros y la registra en un solo asiento por mes:
      Debe Gasto por depreciación / Haber Depreciación acumulada, fechado el último día del mes. Simula primero para revisar.
    </p>

    <div class="selector">
      <label>Año
        <Select v-model="anio" :options="opcionesAnio" optionLabel="label" optionValue="value" style="width: 110px;" data-test="dep-anio" />
      </label>
      <label>Mes
        <Select v-model="mes" :options="opcionesMes" optionLabel="label" optionValue="value" style="width: 160px;" data-test="dep-mes" />
      </label>
      <Button label="Simular" icon="pi pi-eye" severity="secondary" :loading="trabajando && !puedeContabilizar" @click="simular" data-test="dep-simular" />
    </div>

    <Message v-if="error" severity="error" :closable="false" style="margin: 10px 0;" data-test="dep-error">{{ error }}</Message>

    <Message v-if="pendienteDesde" severity="warn" :closable="false" style="margin: 10px 0;" data-test="dep-pendientes">
      <div>Hay activos con meses anteriores sin depreciar (desde {{ pendienteDesde }}). Se depreciaron siempre en orden, así que hay que incluirlos.</div>
      <label style="display: flex; align-items: center; gap: 8px; margin-top: 6px;">
        <Checkbox v-model="incluirAnteriores" :binary="true" inputId="dep-anteriores" data-test="dep-anteriores" />
        <span>Incluir los meses desde {{ pendienteDesde }} hasta {{ periodo }}</span>
      </label>
    </Message>

    <div v-if="resultado" class="resultado" data-test="dep-resultado">
      <p v-if="!resultado.depreciados" class="vacio" data-test="dep-vacio">{{ resultado.mensaje }}</p>
      <template v-else>
        <div v-for="m in resultado.meses" :key="m.periodo" class="mes">
          <div class="mes-cab"><b>{{ m.periodo }}</b> · asiento del {{ fecha(m.fecha) }} <span class="der">{{ money(m.total) }}</span></div>
          <table class="tabla">
            <thead><tr><th>Código</th><th>Activo</th><th class="num">Depreciación</th><th class="num">Acumulada</th><th class="num">Valor en libros</th></tr></thead>
            <tbody>
              <tr v-for="a in m.activos" :key="a.id">
                <td>{{ a.codigo }}</td><td>{{ a.nombre }}</td>
                <td class="num">{{ money(a.monto) }}</td><td class="num">{{ money(a.acumulada_despues) }}</td><td class="num">{{ money(a.valor_en_libros_despues) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="total" data-test="dep-total">
          Total a depreciar: <b>{{ money(resultado.total) }}</b> ({{ resultado.depreciados }} {{ resultado.depreciados === 1 ? 'depreciación' : 'depreciaciones' }})
        </div>
      </template>
    </div>

    <template #footer>
      <Button label="Cancelar" text @click="emit('update:visible', false)" />
      <Button label="Contabilizar" icon="pi pi-check" :disabled="!puedeContabilizar" :loading="trabajando && puedeContabilizar" @click="contabilizar" data-test="dep-contabilizar" />
    </template>
  </Dialog>
</template>

<style scoped>
.selector { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.selector label { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: #475569; }
.resultado { margin-top: 12px; max-height: 340px; overflow: auto; }
.mes { margin-bottom: 10px; }
.mes-cab { background: #f4f8f9; border: 1px solid #dbe6ea; border-radius: 6px 6px 0 0; padding: 6px 10px; font-size: 13px; display: flex; gap: 6px; }
.der { margin-left: auto; font-weight: 700; }
.tabla { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tabla th, .tabla td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
.tabla .num { text-align: right; }
.total { padding: 8px 4px; font-size: 14px; }
.vacio { padding: 12px; color: #64748b; font-size: 13px; }
</style>
