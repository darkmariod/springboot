<script setup lang="ts">
/**
 * RetencionCompra.vue — "¿Aplicar retención?" de una compra.
 *
 * Una retención lleva varias líneas (renta o IVA, código del SRI, base, porcentaje, valor).
 * El porcentaje sale del código; solo los códigos de "otros porcentajes" se digitan.
 * Al registrar, el sistema asienta la retención, baja el saldo de la compra y genera el
 * comprobante electrónico; cuando la empresa no tiene certificado de firma queda "generado"
 * y se enviará al SRI cuando se habilite.
 */
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const props = defineProps<{
  /** Compra guardada: id, numero, total_sin_impuestos, total_impuesto, importe_total, saldo_pendiente. */
  purchase: any
  /** true justo después de guardar la compra: la pregunta queda sin responder. */
  preguntar?: boolean
}>()
const emit = defineEmits<{ (e: 'registrada', data: any): void }>()

const company = useCompanyStore()
const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)
const redondear = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100

interface Linea {
  tipo: 'renta' | 'iva'
  codigo: string | null
  base: number
  porcentaje: number
}

const catalogo = ref<{ renta: any[]; iva: any[] }>({ renta: [], iva: [] })
const resumen = ref<any>(null)
const aplicar = ref<'si' | 'no' | null>(props.preguntar ? null : 'no')
const lineas = ref<Linea[]>([])
const guardando = ref(false)
const reintentando = ref<number | null>(null)
const msg = ref<{ type: string; text: string } | null>(null)

const opcionesSiNo = [
  { label: 'Sí', value: 'si' },
  { label: 'No', value: 'no' },
]
const tiposLinea = [
  { label: 'Renta', value: 'renta' },
  { label: 'IVA', value: 'iva' },
]

// Código — nombre (porcentaje): lo que ve la persona en el selector
const opciones = computed(() => {
  const armar = (lista: any[]) =>
    lista.map((c: any) => ({
      codigo: c.codigo,
      etiqueta: `${c.codigo} — ${c.nombre} (${Number(c.porcentaje) > 0 ? c.porcentaje + '%' : 'porcentaje variable'})`,
    }))
  return { renta: armar(catalogo.value.renta), iva: armar(catalogo.value.iva) }
})

function entrada(l: Linea) {
  return (catalogo.value[l.tipo] ?? []).find((c: any) => c.codigo === l.codigo)
}
const esVariable = (l: Linea) => !!entrada(l) && Number(entrada(l).porcentaje) === 0
const porcentajeDe = (l: Linea) => (esVariable(l) ? Number(l.porcentaje || 0) : Number(entrada(l)?.porcentaje ?? 0))
const valorDe = (l: Linea) => redondear((Number(l.base || 0) * porcentajeDe(l)) / 100)

const baseSugerida = (tipo: 'renta' | 'iva') =>
  Number(tipo === 'renta' ? props.purchase?.total_sin_impuestos : props.purchase?.total_impuesto) || 0

const saldo = computed(() => Number(resumen.value?.saldo_pendiente ?? props.purchase?.saldo_pendiente ?? 0))
const totalRetenido = computed(() => redondear(lineas.value.reduce((s, l) => s + valorDe(l), 0)))
const aPagar = computed(() => redondear(saldo.value - totalRetenido.value))
const excede = computed(() => totalRetenido.value > saldo.value + 0.004)
const comprobantes = computed<any[]>(() => resumen.value?.comprobantes ?? [])
const hayGenerados = computed(() => comprobantes.value.some((c: any) => c.estado_sri === 'generado'))

const problema = computed(() => {
  if (!lineas.value.length) return 'Agrega al menos una línea de retención.'
  for (const l of lineas.value) {
    if (!l.codigo) return 'Elige el código de retención de cada línea.'
    if (!(Number(l.base) > 0)) return 'La base imponible de cada línea debe ser mayor a cero.'
    if (esVariable(l) && !(Number(l.porcentaje) > 0)) return `Indica el porcentaje del código ${l.codigo}.`
    if (!(valorDe(l) > 0)) return 'Cada línea debe retener un valor mayor a cero.'
  }
  if (excede.value) return `La retención (${money(totalRetenido.value)}) supera el saldo pendiente de la compra (${money(saldo.value)}).`
  return ''
})

function agregarLinea(tipo: 'renta' | 'iva' = 'renta') {
  lineas.value.push({ tipo, codigo: null, base: baseSugerida(tipo), porcentaje: 0 })
}
function quitarLinea(i: number) {
  lineas.value.splice(i, 1)
}
function cambiaTipo(l: Linea) {
  l.codigo = null
  l.porcentaje = 0
  l.base = baseSugerida(l.tipo)
}
function cambiaCodigo(l: Linea) {
  l.porcentaje = esVariable(l) ? 0 : Number(entrada(l)?.porcentaje ?? 0)
}

function elegir(valor: 'si' | 'no' | null) {
  aplicar.value = valor
  if (valor === 'si' && !lineas.value.length) agregarLinea('renta')
}

async function cargar() {
  if (!props.purchase?.id) return
  try {
    if (!catalogo.value.renta.length) {
      catalogo.value = (await api.get('/catalogos/retenciones')).data
    }
    resumen.value = (await api.get(`/purchases/${props.purchase.id}/withholdings`, { params: { company_id: company.activeId } })).data
  } catch {
    msg.value = { type: 'warn', text: 'No se pudieron cargar las retenciones de esta compra.' }
  }
}

async function registrar() {
  if (problema.value) {
    msg.value = { type: 'warn', text: problema.value }
    return
  }
  guardando.value = true
  msg.value = null
  try {
    const { data } = await api.post('/withholdings-emitted', {
      company_id: company.activeId,
      purchase_id: props.purchase.id,
      lineas: lineas.value.map((l) => ({
        tipo: l.tipo,
        codigo: l.codigo,
        base_imponible: l.base,
        ...(esVariable(l) ? { porcentaje: l.porcentaje } : {}),
      })),
    })
    msg.value = {
      type: data.emision?.estado === 'error' ? 'warn' : 'success',
      text: `Retención ${data.numero} registrada por ${money(data.total_retenido)}. ${data.emision?.mensaje ?? ''}`,
    }
    lineas.value = []
    aplicar.value = 'no'
    await cargar()
    emit('registrada', data)
  } catch (err: any) {
    const e = err.response?.data?.errors
    msg.value = {
      type: 'error',
      text: e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? 'No se pudo registrar la retención.'),
    }
  } finally {
    guardando.value = false
  }
}

async function reintentar(c: any) {
  reintentando.value = c.id
  msg.value = null
  try {
    const { data } = await api.post(`/withholdings-emitted/${c.id}/emit`, { company_id: company.activeId })
    msg.value = { type: data.emision?.estado === 'error' ? 'warn' : 'success', text: data.emision?.mensaje ?? 'Listo.' }
    await cargar()
  } catch (err: any) {
    msg.value = { type: 'error', text: err.response?.data?.message ?? 'No se pudo generar el comprobante.' }
  } finally {
    reintentando.value = null
  }
}

function etiquetaEstado(estado: string | null) {
  if (!estado) return 'Sin comprobante'
  const e = estado.toLowerCase()
  if (e === 'generado') return 'Generado'
  if (e === 'firmado') return 'Firmado'
  if (e === 'enviado') return 'Enviado al SRI'
  if (e === 'autorizado') return 'Autorizado'
  return estado
}
function severidadEstado(estado: string | null): 'success' | 'info' | 'warn' {
  const e = (estado ?? '').toLowerCase()
  if (e === 'autorizado') return 'success'
  if (e === 'generado') return 'info'
  return 'warn'
}

watch(
  () => props.purchase?.id,
  () => {
    lineas.value = []
    msg.value = null
    aplicar.value = props.preguntar ? null : 'no'
    cargar()
  },
)
onMounted(cargar)
</script>

<template>
  <div class="ret">
    <p class="kvs-hint">
      Retención al proveedor de la compra <b>{{ purchase?.numero }}</b>. El porcentaje sale del código; el valor retenido
      baja lo que se le debe al proveedor.
    </p>

    <div class="ret-resumen">
      <div><span>Total de la compra</span><b>{{ money(resumen?.importe_total ?? purchase?.importe_total) }}</b></div>
      <div><span>Ya retenido</span><b>{{ money(resumen?.total_retenido ?? 0) }}</b></div>
      <div><span>Saldo por pagar</span><b>{{ money(saldo) }}</b></div>
    </div>

    <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px;">{{ msg.text }}</Message>

    <!-- Retenciones ya registradas en esta compra -->
    <template v-if="comprobantes.length">
      <div class="ret-titulo">Retenciones registradas</div>
      <table class="kvs-table">
        <thead>
          <tr>
            <th>Número</th>
            <th>Fecha</th>
            <th>Detalle</th>
            <th class="der">Retenido</th>
            <th>Comprobante electrónico</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in comprobantes" :key="c.numero">
            <td>{{ c.numero }}</td>
            <td>{{ String(c.fecha ?? '').slice(0, 10) }}</td>
            <td>
              <div v-for="l in c.lineas" :key="l.id" class="ret-det">
                {{ l.tipo === 'iva' ? 'IVA' : 'Renta' }} {{ l.codigo }} · base {{ money(l.base_imponible) }} · {{ l.porcentaje }}%
              </div>
            </td>
            <td class="der">{{ money(c.total_retenido) }}</td>
            <td>
              <Tag :value="etiquetaEstado(c.estado_sri)" :severity="severidadEstado(c.estado_sri)" />
              <Button v-if="!c.estado_sri" label="Generar" icon="pi pi-refresh" size="small" text
                      :loading="reintentando === c.id" @click="reintentar(c)" />
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="hayGenerados" class="kvs-hint" style="margin-top:6px;">
        El comprobante electrónico queda generado; el envío al SRI se hará cuando la empresa cargue su certificado de firma.
      </p>
    </template>

    <!-- ¿Aplicar retención? -->
    <div v-if="saldo > 0" class="kvs-row" style="margin-top:14px;">
      <label class="kvs-lbl" style="min-width:150px;"><b>¿Aplicar retención?</b></label>
      <SelectButton :modelValue="aplicar" :options="opcionesSiNo" optionLabel="label" optionValue="value"
                    :allowEmpty="true" @update:modelValue="elegir" />
    </div>
    <p v-else-if="!comprobantes.length" class="kvs-hint" style="margin-top:14px;">
      Esta compra ya no tiene saldo pendiente: no se puede retener.
    </p>

    <!-- Líneas de la retención -->
    <template v-if="aplicar === 'si'">
      <table class="kvs-table ret-lineas" style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width:100px;">Tipo</th>
            <th>Código de retención</th>
            <th style="width:140px;">Base imponible</th>
            <th style="width:110px;">Porcentaje</th>
            <th class="der" style="width:110px;">Valor retenido</th>
            <th style="width:40px;"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lineas" :key="i">
            <td>
              <Select v-model="l.tipo" :options="tiposLinea" optionLabel="label" optionValue="value"
                      size="small" style="width:100%" @change="cambiaTipo(l)" />
            </td>
            <td>
              <Select v-model="l.codigo" :options="opciones[l.tipo]" optionLabel="etiqueta" optionValue="codigo"
                      placeholder="Elige el código" filter size="small" style="width:100%" @change="cambiaCodigo(l)" />
            </td>
            <td>
              <InputNumber v-model="l.base" :minFractionDigits="2" :maxFractionDigits="2" :min="0" size="small" style="width:100%" />
            </td>
            <td>
              <InputNumber v-if="esVariable(l)" v-model="l.porcentaje" :minFractionDigits="2" :maxFractionDigits="2"
                           :min="0" :max="100" suffix=" %" size="small" style="width:100%" />
              <span v-else>{{ l.codigo ? porcentajeDe(l) + ' %' : '—' }}</span>
            </td>
            <td class="der">{{ money(valorDe(l)) }}</td>
            <td>
              <Button icon="pi pi-trash" text severity="danger" size="small" title="Quitar línea" @click="quitarLinea(i)" />
            </td>
          </tr>
        </tbody>
      </table>
      <div style="display:flex; gap:8px; margin-top:6px;">
        <Button label="Agregar renta" icon="pi pi-plus" text size="small" @click="agregarLinea('renta')" />
        <Button label="Agregar IVA" icon="pi pi-plus" text size="small" @click="agregarLinea('iva')" />
      </div>

      <div class="ret-total">
        <div><span>Total retenido</span><b>{{ money(totalRetenido) }}</b></div>
        <div :class="{ 'ret-mal': excede }"><span>Valor a pagar al proveedor</span><b>{{ money(aPagar) }}</b></div>
      </div>
      <Message v-if="problema && lineas.length" severity="warn" :closable="false" style="margin-top:8px;">{{ problema }}</Message>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
        <Button label="Cancelar" text @click="elegir('no')" />
        <Button label="Registrar retención" icon="pi pi-check" :loading="guardando" :disabled="!!problema" @click="registrar" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.ret-resumen { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.ret-resumen > div, .ret-total > div {
  display: flex; flex-direction: column; gap: 2px; padding: 8px 14px;
  background: #f3f6fa; border: 1px solid #e2e5ea; border-radius: 4px; min-width: 150px;
}
.ret-resumen span, .ret-total span { font-size: 11px; color: #64748b; }
.ret-resumen b, .ret-total b { font-size: 15px; }
.ret-titulo { font-weight: 700; font-size: 12.5px; margin: 6px 0; color: #4a3220; }
.ret-det { font-size: 12px; color: #475569; }
.ret-lineas td { vertical-align: middle; }
.ret-total { display: flex; gap: 12px; justify-content: flex-end; margin-top: 12px; }
.ret-mal { border-color: #dc2626 !important; }
.ret-mal b { color: #dc2626; }
</style>
