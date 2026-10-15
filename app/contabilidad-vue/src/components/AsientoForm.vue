<script setup lang="ts">
// Formulario de un asiento contable: nuevo asiento manual o corrección de uno existente (manual o generado por un documento).
// Las reglas de verdad están en el servidor (POST/PUT /journal-entries); aquí se avisa antes de enviar y se muestra el 422 tal cual.
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

interface Cuenta { id: number; codigo: string; nombre: string; parent_id?: number | null }
interface Fila { uid: number; account_id: number | null; debe: number | null; haber: number | null; referencia: string | null }

const props = defineProps<{
  visible: boolean
  asiento: any | null   // null = asiento nuevo
  cuentas: Cuenta[]
}>()
const emit = defineEmits<{
  'update:visible': [value: boolean]
  guardado: [mensaje: string]
}>()

const company = useCompanyStore()
const fecha = ref<Date | null>(null)
const concepto = ref('')
const filas = ref<Fila[]>([])
const error = ref<string | null>(null)
const guardando = ref(false)
let contador = 0

const esNuevo = computed(() => !props.asiento)
const esGenerado = computed(() => !!props.asiento && !props.asiento.manual)
const esBorrador = computed(() => props.asiento?.estado === 'borrador')

const dos = (n: number) => n.toFixed(2)
const centavos = (n: any) => Math.round((Number(n) || 0) * 100)
const aTexto = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const deTexto = (t: string) => {
  const [y, m, d] = String(t).slice(0, 10).split('-').map(Number)
  return new Date(y, (m || 1) - 1, d || 1)
}

function filaVacia(): Fila {
  return { uid: ++contador, account_id: null, debe: null, haber: null, referencia: null }
}

function iniciar() {
  error.value = null
  guardando.value = false
  if (props.asiento) {
    fecha.value = deTexto(props.asiento.fecha)
    concepto.value = props.asiento.concepto ?? ''
    filas.value = (props.asiento.lines ?? []).map((l: any) => ({
      uid: ++contador,
      account_id: l.account_id,
      debe: Number(l.debe) || null,
      haber: Number(l.haber) || null,
      referencia: l.referencia ?? null,
    }))
    while (filas.value.length < 2) filas.value.push(filaVacia())
  } else {
    fecha.value = new Date()
    concepto.value = ''
    filas.value = [filaVacia(), filaVacia()]
  }
}
watch(() => props.visible, (v) => { if (v) iniciar() })

// Cuentas que reciben movimientos: las que no tienen subcuentas (por código: 1.1 contiene a 1.1.01, o por cuenta padre).
// Al editar se conservan las cuentas que el asiento ya usa, aunque hoy sean encabezados.
const encabezados = computed(() => {
  const s = new Set<string>()
  const padres = new Set<number>()
  for (const c of props.cuentas) {
    const partes = c.codigo.split('.')
    for (let i = 1; i < partes.length; i++) s.add(partes.slice(0, i).join('.'))
    if (c.parent_id) padres.add(c.parent_id)
  }
  return { codigos: s, padres }
})
const opciones = computed(() => {
  const usadas = new Set(filas.value.map(f => f.account_id))
  return props.cuentas
    .filter(c => (!encabezados.value.codigos.has(c.codigo) && !encabezados.value.padres.has(c.id)) || usadas.has(c.id))
    .map(c => ({ id: c.id, etiqueta: `${c.codigo} — ${c.nombre}` }))
    .sort((a, b) => a.etiqueta.localeCompare(b.etiqueta, undefined, { numeric: true }))
})

const totalDebe = computed(() => filas.value.reduce((s, f) => s + centavos(f.debe), 0))
const totalHaber = computed(() => filas.value.reduce((s, f) => s + centavos(f.haber), 0))
const diferencia = computed(() => totalDebe.value - totalHaber.value)
const cuadra = computed(() => totalDebe.value > 0 && diferencia.value === 0)
const claseTotales = computed(() => cuadra.value ? 'ok' : (totalDebe.value === 0 && totalHaber.value === 0 ? 'vacio' : 'mal'))

// El aviso de un intento anterior ya no vale cuando la persona vuelve a cambiar el asiento
watch([totalDebe, totalHaber, concepto], () => { error.value = null })

function agregarLinea() { filas.value.push(filaVacia()) }
function quitarLinea(uid: number) { filas.value = filas.value.filter(f => f.uid !== uid) }
// En una línea el valor va en Debe o en Haber, no en los dos
function alDebe(f: Fila, v: number | null) { f.debe = v; if (v) f.haber = null }
function alHaber(f: Fila, v: number | null) { f.haber = v; if (v) f.debe = null }

function mensajeError(err: any, defecto: string): string {
  const d = err.response?.data
  if (d?.errors) return (Object.values(d.errors).flat() as string[]).join(' · ')
  return d?.message ?? defecto
}

async function guardar(estado: 'borrador' | 'pendiente' | null) {
  error.value = null
  if (!fecha.value) { error.value = 'Escribe la fecha del asiento.'; return }
  if (!concepto.value.trim()) { error.value = 'Escribe el concepto del asiento.'; return }
  // Las filas sin valor (vacías, o con IVA en cero de un asiento generado) no viajan
  const conValor = filas.value.filter(f => centavos(f.debe) > 0 || centavos(f.haber) > 0)
  if (conValor.length < 2) { error.value = 'El asiento necesita al menos dos líneas con valor.'; return }
  const sinCuenta = conValor.findIndex(f => !f.account_id)
  if (sinCuenta >= 0) { error.value = `Línea ${filas.value.indexOf(conValor[sinCuenta]) + 1}: elige la cuenta.`; return }
  if (totalDebe.value !== totalHaber.value) {
    error.value = `El asiento no cuadra: debe ${dos(totalDebe.value / 100)}, haber ${dos(totalHaber.value / 100)}`
    return
  }

  const payload: any = {
    company_id: company.activeId,
    fecha: aTexto(fecha.value),
    concepto: concepto.value.trim(),
    lines: conValor.map(f => ({
      account_id: f.account_id,
      debe: centavos(f.debe) / 100,
      haber: centavos(f.haber) / 100,
      referencia: f.referencia?.trim() || null,
    })),
  }
  if (estado) payload.estado = estado

  guardando.value = true
  try {
    const res = props.asiento
      ? await api.put(`/journal-entries/${props.asiento.id}`, payload)
      : await api.post('/journal-entries', payload)
    const numero = res.data?.numero ?? props.asiento?.numero ?? ''
    let texto: string
    if (esNuevo.value) texto = estado === 'borrador' ? `Asiento ${numero} guardado como borrador.` : `Asiento ${numero} guardado y contabilizado.`
    else if (esGenerado.value) texto = `Asiento ${numero} corregido. Sigue ligado a su documento y el cambio quedó en la auditoría.`
    else texto = estado === 'pendiente' && esBorrador.value ? `Asiento ${numero} guardado y contabilizado.` : `Asiento ${numero} actualizado.`
    emit('guardado', texto)
    emit('update:visible', false)
  } catch (err: any) {
    error.value = mensajeError(err, 'No se pudo guardar el asiento.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Dialog :visible="visible" @update:visible="(v: boolean) => emit('update:visible', v)" modal
          :header="esNuevo ? 'Nuevo asiento' : `Editar asiento ${asiento.numero}`"
          :style="{ width: '960px', maxWidth: '96vw' }">
    <Message v-if="error" severity="error" :closable="false" style="margin-bottom:12px;" data-test="asiento-error">{{ error }}</Message>
    <Message v-if="esGenerado" severity="info" :closable="false" style="margin-bottom:12px;">
      Este asiento lo generó un documento. Puedes corregir las cuentas y los valores: seguirá ligado a su documento
      y el cambio queda en la auditoría (quién, antes y después). El asiento debe seguir cuadrando.
    </Message>

    <div class="cabecera">
      <label class="campo">
        <span>Fecha <span class="req-star">*</span></span>
        <DatePicker v-model="fecha" dateFormat="dd/mm/yy" showIcon fluid inputId="asiento-fecha" />
      </label>
      <label class="campo concepto">
        <span>Concepto <span class="req-star">*</span></span>
        <InputText v-model="concepto" placeholder="Ej.: Aporte de capital del socio" maxlength="255" fluid data-test="asiento-concepto" />
      </label>
    </div>

    <table class="lineas">
      <thead>
        <tr>
          <th style="width:42%;">Cuenta</th>
          <th style="width:16%;">Debe</th>
          <th style="width:16%;">Haber</th>
          <th>Referencia</th>
          <th style="width:44px;"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(f, i) in filas" :key="f.uid">
          <td>
            <Select v-model="f.account_id" :options="opciones" optionLabel="etiqueta" optionValue="id"
                    filter placeholder="Busca por código o nombre" fluid :aria-label="`Cuenta de la línea ${i + 1}`" />
          </td>
          <td>
            <InputNumber :modelValue="f.debe" @update:modelValue="(v: number | null) => alDebe(f, v)"
                         mode="decimal" :minFractionDigits="2" :maxFractionDigits="2" :min="0" fluid
                         :inputProps="{ 'aria-label': `Debe de la línea ${i + 1}` }" />
          </td>
          <td>
            <InputNumber :modelValue="f.haber" @update:modelValue="(v: number | null) => alHaber(f, v)"
                         mode="decimal" :minFractionDigits="2" :maxFractionDigits="2" :min="0" fluid
                         :inputProps="{ 'aria-label': `Haber de la línea ${i + 1}` }" />
          </td>
          <td><InputText v-model="f.referencia" placeholder="Opcional" maxlength="255" fluid /></td>
          <td>
            <Button icon="pi pi-trash" severity="danger" text rounded size="small" :disabled="filas.length <= 2"
                    :aria-label="`Quitar la línea ${i + 1}`" @click="quitarLinea(f.uid)" />
          </td>
        </tr>
      </tbody>
    </table>
    <Button label="Agregar línea" icon="pi pi-plus" text size="small" @click="agregarLinea" style="margin-top:6px;" />

    <div class="totales" :class="claseTotales" data-test="asiento-totales">
      <div>Debe <b>${{ dos(totalDebe / 100) }}</b></div>
      <div>Haber <b>${{ dos(totalHaber / 100) }}</b></div>
      <div>Diferencia <b>${{ dos(Math.abs(diferencia) / 100) }}</b></div>
      <div class="veredicto">
        <i :class="cuadra ? 'pi pi-check-circle' : 'pi pi-exclamation-circle'"></i>
        {{ cuadra ? 'El asiento cuadra' : (claseTotales === 'vacio' ? 'Escribe los valores' : 'El asiento no cuadra') }}
      </div>
    </div>

    <template #footer>
      <Button label="Cancelar" text @click="emit('update:visible', false)" />
      <template v-if="esNuevo || (!esGenerado && esBorrador)">
        <Button label="Guardar borrador" icon="pi pi-save" severity="secondary" :loading="guardando" @click="guardar('borrador')" />
        <Button label="Guardar y contabilizar" icon="pi pi-check" :loading="guardando" @click="guardar('pendiente')" />
      </template>
      <Button v-else label="Guardar cambios" icon="pi pi-check" :loading="guardando" @click="guardar(esGenerado ? null : 'pendiente')" />
    </template>
  </Dialog>
</template>

<style scoped>
.cabecera { display: grid; grid-template-columns: 200px 1fr; gap: 12px; margin-bottom: 14px; }
.campo { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.lineas { width: 100%; border-collapse: collapse; font-size: 13px; }
.lineas th { text-align: left; padding: 6px 6px; color: #64748b; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
.lineas td { padding: 6px 6px; vertical-align: middle; }
.totales { display: flex; gap: 28px; align-items: center; margin-top: 14px; padding: 10px 14px; border-radius: 8px; border: 1px solid; font-size: 14px; }
.totales .veredicto { margin-left: auto; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.totales.ok { background: #ecfdf5; border-color: #059669; color: #047857; }
.totales.mal { background: #fef2f2; border-color: #dc2626; color: #b91c1c; }
.totales.vacio { background: #f8fafc; border-color: #cbd5e1; color: #64748b; }
@media (max-width: 720px) { .cabecera { grid-template-columns: 1fr; } .totales { flex-wrap: wrap; gap: 12px; } }
</style>
