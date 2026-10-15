<script setup lang="ts">
/**
 * SeriesInput.vue — captura de números de serie, una por unidad.
 *
 * Dos formas de llenarla, que se pueden mezclar:
 *   - un cuadro de texto, una serie por línea (también se pegan desde Excel);
 *   - un campo para el lector de código de barras: se escanea y el Enter la agrega a la lista.
 * Muestra el avance ("3 de 5 series") y avisa de las series repetidas en la lista y de las que ya existen en el sistema
 * (las consulta al servidor mientras se escriben). El padre decide cuándo dejar guardar con el evento `valido`:
 * verdadero solo cuando el número de series es igual a la cantidad y no hay repetidas ni existentes.
 * El servidor valida lo mismo al guardar: este aviso es para no perder tiempo.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const props = withDefaults(defineProps<{
  /** Las series capturadas (una por unidad). */
  modelValue?: string[]
  /** Cuántas series se esperan (las unidades). */
  cantidad: number
  /** Consultar al servidor cuáles ya existen (compras y sobrantes: sí; elegir entre las existentes: no). */
  verificar?: boolean
  filas?: number
}>(), { modelValue: () => [], verificar: true, filas: 6 })

const emit = defineEmits<{
  (e: 'update:modelValue', v: string[]): void
  (e: 'valido', v: boolean): void
}>()

const company = useCompanyStore()

const limpiar = (t: string): string[] => t.split(/[\n\r,;]+/).map((s) => s.trim()).filter(Boolean)

const texto = ref((props.modelValue ?? []).join('\n'))
const escaneo = ref('')
const avisoEscaneo = ref('')
const campoEscaneo = ref<any>(null)

const lista = computed(() => limpiar(texto.value))
const esperadas = computed(() => Number(props.cantidad ?? 0))
const cantidadEntera = computed(() => Number.isInteger(esperadas.value) && esperadas.value > 0)
const repetidas = computed(() => [...new Set(lista.value.filter((s, i) => lista.value.indexOf(s) !== i))])

// Series que el servidor dice que ya existen: serie -> { producto, estado } o null si está libre
const conocidas = ref<Record<string, { serie: string; producto: string | null; estado: string } | null>>({})
const yaExisten = computed(() =>
  [...new Set(lista.value)].map((s) => conocidas.value[s]).filter((x): x is NonNullable<typeof x> => !!x),
)

const completo = computed(() => cantidadEntera.value && lista.value.length === esperadas.value)
const valido = computed(() => completo.value && !repetidas.value.length && !yaExisten.value.length)
const diferencia = computed(() => lista.value.length - esperadas.value)

const color = computed(() => {
  if (repetidas.value.length || yaExisten.value.length || diferencia.value > 0) return '#991b1b'
  return completo.value ? '#166534' : '#b45309'
})
const resumen = computed(() => {
  if (!cantidadEntera.value) return 'La cantidad debe ser un número entero de unidades.'
  const base = `${lista.value.length} de ${esperadas.value} series`
  if (diferencia.value > 0) return `${base} · sobra${diferencia.value === 1 ? '' : 'n'} ${diferencia.value}`
  if (diferencia.value < 0) return `${base} · falta${diferencia.value === -1 ? '' : 'n'} ${-diferencia.value}`
  return base
})

function agregar() {
  const nuevas = limpiar(escaneo.value)
  escaneo.value = ''
  if (!nuevas.length) return
  avisoEscaneo.value = ''
  const ya = new Set(lista.value)
  const repetidasEscaneo: string[] = []
  const aAgregar: string[] = []
  for (const s of nuevas) {
    if (ya.has(s)) repetidasEscaneo.push(s)
    else { ya.add(s); aAgregar.push(s) }
  }
  if (aAgregar.length) texto.value = [...lista.value, ...aAgregar].join('\n')
  if (repetidasEscaneo.length) {
    avisoEscaneo.value = `La serie ${repetidasEscaneo.join(', ')} ya está en la lista.`
  }
  nextTick(() => campoEscaneo.value?.$el?.focus?.())
}

// Hacia el padre
watch(lista, (l) => {
  if (l.join('\n') !== (props.modelValue ?? []).join('\n')) emit('update:modelValue', [...l])
})
watch(() => props.modelValue, (v) => {
  if ((v ?? []).join('\n') !== lista.value.join('\n')) texto.value = (v ?? []).join('\n')
})
watch(valido, (v) => emit('valido', v), { immediate: true })

// Consulta al servidor cuáles ya existen (sin insistir con las que ya se consultaron)
let temporizador: ReturnType<typeof setTimeout> | undefined
async function consultarExistentes() {
  const nuevas = [...new Set(lista.value)].filter((s) => !(s in conocidas.value))
  if (!nuevas.length || !company.activeId) return
  try {
    for (let i = 0; i < nuevas.length; i += 500) {
      const lote = nuevas.slice(i, i + 500)
      const res = await api.post('/series/existentes', { company_id: company.activeId, series: lote })
      const halladas = new Map<string, any>((res.data.existentes ?? []).map((e: any) => [e.serie, e]))
      for (const s of lote) conocidas.value[s] = halladas.get(s) ?? null
    }
  } catch {
    // Sin la consulta no se avisa de las existentes: el servidor las rechaza igual al guardar.
  }
}
watch(lista, () => {
  if (!props.verificar) return
  clearTimeout(temporizador)
  temporizador = setTimeout(consultarExistentes, 400)
}, { immediate: true })
onBeforeUnmount(() => clearTimeout(temporizador))
</script>

<template>
  <div class="series-input">
    <div class="si-scan">
      <InputText
        ref="campoEscaneo"
        v-model="escaneo"
        placeholder="Escanea una serie y presiona Enter"
        class="si-scan-input"
        aria-label="Escanear o escribir una serie"
        @keydown.enter.prevent="agregar"
      />
      <Button icon="pi pi-plus" size="small" label="Agregar" :disabled="!escaneo.trim()" @click="agregar" />
    </div>
    <small v-if="avisoEscaneo" class="si-aviso">{{ avisoEscaneo }}</small>

    <Textarea
      v-model="texto"
      :rows="filas"
      class="si-texto"
      placeholder="O escríbelas aquí, una por línea (también puedes pegarlas desde Excel)"
      aria-label="Series, una por línea"
    />

    <div class="si-resumen" :style="{ color }" role="status">
      <i :class="valido ? 'pi pi-check-circle' : 'pi pi-info-circle'" /> {{ resumen }}
    </div>
    <div v-if="repetidas.length" class="si-problema">
      Repetidas en la lista: {{ repetidas.join(', ') }}.
    </div>
    <div v-if="yaExisten.length" class="si-problema">
      Ya existen en el sistema:
      <span v-for="(e, i) in yaExisten" :key="e.serie">{{ i ? ', ' : ' ' }}<b>{{ e.serie }}</b> ({{ e.producto ?? 'otro producto' }}, {{ e.estado }}){{ i === yaExisten.length - 1 ? '.' : '' }}</span>
    </div>
  </div>
</template>

<style scoped>
.series-input { display: flex; flex-direction: column; gap: 8px; }
.si-scan { display: flex; gap: 6px; }
.si-scan-input { flex: 1; font-family: monospace; }
.si-texto { width: 100%; font-family: monospace; font-size: 12px; }
.si-resumen { font-size: 13px; font-weight: 600; }
.si-problema { font-size: 12px; color: #991b1b; }
.si-aviso { color: #b45309; }
</style>
