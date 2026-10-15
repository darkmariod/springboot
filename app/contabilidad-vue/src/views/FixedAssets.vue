<script setup lang="ts">
/**
 * Activos fijos (T7.2): la ficha de cada activo (código, grupo, responsable, costo, valor residual, vida útil), su cronograma de
 * depreciación mensual en línea recta, la depreciación del mes y la baja. Solo contador y administrador, plan Completo.
 *
 * Qué asienta cada acción (el servidor decide; aquí solo se explica):
 *  - Crear la ficha con «Registrar el alta»: Debe Activos fijos / Haber la cuenta elegida. Ligada a una compra de activo fijo
 *    (sustento 03 o 04) ya capitalizada no genera asiento: la compra ya llevó el costo a Activos fijos.
 *  - Depreciar el mes: UN asiento por mes, Debe Gasto por depreciación / Haber Depreciación acumulada.
 *  - Dar de baja: Debe Depreciación acumulada (toda) y lo cobrado si se vendió, Haber Activos fijos, con pérdida o utilidad.
 */
import { computed, onMounted, ref, watch } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useCuentasStore } from '../stores/cuentas'
import { onShortcut } from '../composables/useShortcuts'
import DepreciacionDialog from '../components/DepreciacionDialog.vue'

const company = useCompanyStore()
const cuentas = useCuentasStore()

// Vidas útiles que se SUGIEREN por grupo (en meses). Son una ayuda de captura: el servidor no impone mínimos. La contadora debe
// confirmarlas con la norma tributaria vigente antes de usarlas.
const VIDA_SUGERIDA: Record<string, number> = {
  muebles_enseres: 120, maquinaria_equipo: 120, vehiculos: 60, equipos_computacion: 36, edificios: 240,
}
// Cuentas que se mueven solo desde su documento (cartera y anticipos) y el propio activo: no sirven de contrapartida del alta
const NO_CONTRAPARTIDA = ['1.1.03', '1.1.09', '1.1.10', '1.1.11', '2.1.01', '2.1.11', '1.2.01']

const filas = ref<any[]>([])
const totales = ref<any>({ costo: 0, depreciacion_acumulada: 0, valor_en_libros: 0, activos: 0, dados_de_baja: 0 })
const grupos = ref<{ valor: string; nombre: string }[]>([])
const cargando = ref(false)
const filtro = ref<{ grupo: string | null; estado: string | null; q: string }>({ grupo: null, estado: null, q: '' })
const aviso = ref<{ tipo: 'success' | 'error' | 'warn'; texto: string } | null>(null)
const empleados = ref<any[]>([])
const compras = ref<any[]>([])

const money = (n: any) => '$' + Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
// Una fecha sola ("2026-12-31") se arma tal cual: pasarla por Date la corre un día hacia atrás en Ecuador (UTC-5)
const fecha = (iso: string | null | undefined) => (iso ? String(iso).slice(0, 10).split('-').reverse().join('/') : '')
const hoyIso = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function textoError(e: any, defecto: string) {
  const errores = e?.response?.data?.errors
  return errores ? (Object.values(errores).flat() as string[]).join(' · ') : (e?.response?.data?.message ?? defecto)
}

const opcionesEstado = [
  { label: 'Activos', value: 'activo' },
  { label: 'Dados de baja', value: 'dado_de_baja' },
]

async function cargar() {
  if (!company.activeId) return
  cargando.value = true
  try {
    const params: any = { company_id: company.activeId }
    if (filtro.value.grupo) params.grupo = filtro.value.grupo
    if (filtro.value.estado) params.estado = filtro.value.estado
    if (filtro.value.q.trim()) params.q = filtro.value.q.trim()
    const { data } = await api.get('/fixed-assets', { params })
    filas.value = data.activos
    totales.value = data.totales
    grupos.value = data.grupos
  } catch (e: any) {
    aviso.value = { tipo: 'error', texto: textoError(e, 'No se pudieron cargar los activos fijos.') }
  } finally {
    cargando.value = false
  }
}

async function cargarApoyo() {
  try { empleados.value = (await api.get('/employees', { params: { company_id: company.activeId } })).data.filter((e: any) => e.activo !== false) }
  catch { empleados.value = [] }
  await cuentas.cargar(true)
}

async function cargarCompras() {
  try { compras.value = (await api.get('/fixed-assets/compras', { params: { company_id: company.activeId } })).data }
  catch { compras.value = [] }
}

// ─── Ficha (crear y editar) ───
const verFicha = ref(false)
const form = ref<any>({})
const guardando = ref(false)
const errorFicha = ref('')
const editando = computed(() => !!form.value.id)
const bloqueado = computed(() => editando.value && !form.value.puede_editar_costos)
const compraElegida = computed(() => compras.value.find((c) => c.id === form.value.purchase_id) ?? null)
const contrapartidas = computed(() => cuentas.detalle.filter((c) => !NO_CONTRAPARTIDA.includes(String(c.codigo))))
const cuotaPrevista = computed(() => {
  const costo = Number(form.value.costo ?? 0), residual = Number(form.value.valor_residual ?? 0), vida = Number(form.value.vida_util_meses ?? 0)
  return vida > 0 && costo > residual ? Math.round(((costo - residual) / vida) * 100) / 100 : 0
})
// Con una compra ya capitalizada el costo está asentado: no se pide contrapartida
const pideContrapartida = computed(() => !editando.value && form.value.registrar_alta && !(compraElegida.value && compraElegida.value.capitalizada))
const vidaSugerida = computed(() => VIDA_SUGERIDA[form.value.grupo] ?? null)

function fichaVacia() {
  const hoy = hoyIso()
  return {
    id: null, codigo: '', nombre: '', descripcion: '', grupo: null, departamento: '', employee_id: null, serie: '', observacion: '',
    fecha_adquisicion: hoy, inicio_mes: hoy.slice(0, 7), costo: null, valor_residual: 0, vida_util_meses: null, metodo: 'linea_recta',
    purchase_id: null, registrar_alta: true, cuenta_contrapartida_id: null, puede_editar_costos: true,
  }
}

async function nuevo() {
  errorFicha.value = ''
  form.value = fichaVacia()
  await Promise.all([cargarCompras(), cuentas.cargar()])
  form.value.cuenta_contrapartida_id = cuentas.detalle.find((c) => c.codigo === '1.1.02')?.id ?? null
  try { form.value.codigo = (await api.get('/fixed-assets/siguiente-codigo', { params: { company_id: company.activeId } })).data.codigo } catch { /* el servidor lo asigna */ }
  verFicha.value = true
}

function editar(r: any) {
  errorFicha.value = ''
  form.value = { ...r, inicio_mes: String(r.fecha_inicio_depreciacion).slice(0, 7), registrar_alta: false, cuenta_contrapartida_id: null }
  verFicha.value = true
}

// El grupo sugiere la vida útil mientras no se haya escrito otra
watch(() => form.value.grupo, (nuevoGrupo, anterior) => {
  if (editando.value || !nuevoGrupo) return
  const sugerida = VIDA_SUGERIDA[nuevoGrupo]
  const previa = anterior ? VIDA_SUGERIDA[anterior] : undefined
  if (sugerida && (form.value.vida_util_meses == null || form.value.vida_util_meses === previa)) form.value.vida_util_meses = sugerida
})
// La depreciación empieza por defecto en el mes de la adquisición
watch(() => form.value.fecha_adquisicion, (f) => { if (!editando.value && f) form.value.inicio_mes = String(f).slice(0, 7) })

function elegirCompra(id: number | null) {
  form.value.purchase_id = id
  const c = compras.value.find((x) => x.id === id)
  if (!c) return
  form.value.costo = c.disponible
  form.value.fecha_adquisicion = c.fecha_emision
  if (!form.value.observacion) form.value.observacion = `Compra ${c.numero}${c.proveedor ? ' · ' + c.proveedor : ''}`
}

async function guardar() {
  errorFicha.value = ''
  const f = form.value
  if (!f.nombre?.trim() || !f.grupo || !(Number(f.costo) > 0) || !(Number(f.vida_util_meses) > 0)) {
    errorFicha.value = 'Completa el nombre, el grupo, el costo y la vida útil.'
    return
  }
  const payload: any = {
    company_id: company.activeId, codigo: f.codigo?.trim() || null, nombre: f.nombre.trim(), descripcion: f.descripcion || null, grupo: f.grupo,
    departamento: f.departamento || null, employee_id: f.employee_id || null, serie: f.serie || null, observacion: f.observacion || null,
    fecha_adquisicion: f.fecha_adquisicion, costo: f.costo, valor_residual: f.valor_residual ?? 0, vida_util_meses: f.vida_util_meses,
    metodo: 'linea_recta', fecha_inicio_depreciacion: f.inicio_mes + '-01',
  }
  if (!editando.value) {
    payload.purchase_id = f.purchase_id || null
    payload.registrar_alta = !!f.registrar_alta
    payload.cuenta_contrapartida_id = pideContrapartida.value ? f.cuenta_contrapartida_id : null
  }
  guardando.value = true
  try {
    if (editando.value) await api.put('/fixed-assets/' + f.id, payload)
    else await api.post('/fixed-assets', payload)
    verFicha.value = false
    aviso.value = { tipo: 'success', texto: editando.value ? 'Activo actualizado.' : 'Activo creado.' }
    await cargar()
  } catch (e: any) {
    errorFicha.value = textoError(e, 'No se pudo guardar el activo.')
  } finally {
    guardando.value = false
  }
}

async function eliminar() {
  if (!form.value.id || !confirm('¿Eliminar el activo ' + form.value.codigo + '? Se reversa su asiento de alta.')) return
  try {
    await api.delete('/fixed-assets/' + form.value.id, { params: { company_id: company.activeId } })
    verFicha.value = false
    aviso.value = { tipo: 'success', texto: 'Activo eliminado.' }
    await cargar()
  } catch (e: any) {
    errorFicha.value = textoError(e, 'No se pudo eliminar el activo.')
  }
}

// ─── Cronograma de depreciación ───
const verCronograma = ref(false)
const detalle = ref<any>(null)
const cargandoDetalle = ref(false)
async function abrirCronograma(r: any) {
  detalle.value = null
  verCronograma.value = true
  cargandoDetalle.value = true
  try { detalle.value = (await api.get('/fixed-assets/' + r.id, { params: { company_id: company.activeId } })).data }
  catch (e: any) { verCronograma.value = false; aviso.value = { tipo: 'error', texto: textoError(e, 'No se pudo cargar el cronograma.') } }
  finally { cargandoDetalle.value = false }
}

// ─── Baja ───
const verBaja = ref(false)
const baja = ref<any>({})
const dandoBaja = ref(false)
const errorBaja = ref('')
function abrirBaja(r: any) {
  errorBaja.value = ''
  baja.value = { activo: r, fecha: hoyIso(), motivo: '', vendido: false, valor_venta: null, cuenta_cobro: 'bancos' }
  verBaja.value = true
}
async function darDeBaja() {
  const b = baja.value
  if (!b.motivo?.trim()) { errorBaja.value = 'Escribe el motivo de la baja.'; return }
  if (b.vendido && !(Number(b.valor_venta) > 0)) { errorBaja.value = 'Escribe el valor de la venta.'; return }
  dandoBaja.value = true
  errorBaja.value = ''
  try {
    const { data } = await api.post(`/fixed-assets/${b.activo.id}/baja`, {
      company_id: company.activeId, fecha: b.fecha, motivo: b.motivo.trim(),
      valor_venta: b.vendido ? b.valor_venta : null, cuenta_cobro: b.vendido ? b.cuenta_cobro : null,
    })
    verBaja.value = false
    const r = Number(data.resultado)
    const resultado = r < 0 ? `pérdida de ${money(-r)}` : r > 0 ? `utilidad de ${money(r)}` : 'sin pérdida ni utilidad'
    aviso.value = {
      tipo: 'success',
      texto: `${b.activo.codigo} dado de baja con el asiento ${data.asiento.numero}: ${resultado}.`
        + (data.depreciaciones.meses ? ` Antes se depreciaron ${money(data.depreciaciones.total)} pendientes.` : ''),
    }
    await cargar()
  } catch (e: any) {
    errorBaja.value = textoError(e, 'No se pudo dar de baja el activo.')
  } finally {
    dandoBaja.value = false
  }
}

// ─── Depreciar el mes ───
const verDepreciar = ref(false)
function depreciado(res: any) {
  aviso.value = { tipo: 'success', texto: res.mensaje }
  cargar()
}

const etiquetaEstado = (e: string) => (e === 'activo' ? 'Activo' : 'Dado de baja')

let esperando: ReturnType<typeof setTimeout> | null = null
watch(() => [filtro.value.grupo, filtro.value.estado], cargar)
watch(() => filtro.value.q, () => { if (esperando) clearTimeout(esperando); esperando = setTimeout(cargar, 300) })
onMounted(() => { cargar(); cargarApoyo() })

onShortcut('nuevo', () => nuevo())
onShortcut('guardar', () => { if (verFicha.value) guardar() })
onShortcut('buscar', () => document.querySelector<HTMLInputElement>('[data-test="fa-buscar"]')?.focus())
</script>

<template>
  <div class="fa">
    <div class="encabezado">
      <div>
        <h2>Activos fijos</h2>
        <p class="sub">
          Registra cada activo con su costo, valor residual y vida útil: el sistema calcula la depreciación mensual en línea recta y la contabiliza
          en un solo asiento por mes.
        </p>
      </div>
      <div class="acciones-cab">
        <Button label="Depreciar mes" icon="pi pi-calendar-plus" severity="secondary" @click="verDepreciar = true" data-test="depreciar-mes" />
        <Button label="Nuevo activo" icon="pi pi-plus" @click="nuevo" data-test="nuevo-activo" />
      </div>
    </div>

    <Message v-if="aviso" :severity="aviso.tipo" :closable="true" @close="aviso = null" style="margin-bottom: 10px;" data-test="fa-aviso">{{ aviso.texto }}</Message>

    <div class="filtros">
      <Select v-model="filtro.grupo" :options="grupos" optionLabel="nombre" optionValue="valor" placeholder="Todos los grupos" showClear
              style="width: 220px;" data-test="fa-grupo" />
      <Select v-model="filtro.estado" :options="opcionesEstado" optionLabel="label" optionValue="value" placeholder="Todos los estados" showClear
              style="width: 190px;" data-test="fa-estado" />
      <InputText v-model="filtro.q" placeholder="Buscar por código, nombre o serie" style="width: 280px;" data-test="fa-buscar" />
    </div>

    <div class="totales" data-test="fa-totales">
      <div><span>Activos en libros</span><b>{{ totales.activos }}</b></div>
      <div><span>Costo</span><b>{{ money(totales.costo) }}</b></div>
      <div><span>Depreciación acumulada</span><b>{{ money(totales.depreciacion_acumulada) }}</b></div>
      <div><span>Valor en libros</span><b>{{ money(totales.valor_en_libros) }}</b></div>
      <div v-if="totales.dados_de_baja"><span>Dados de baja</span><b>{{ totales.dados_de_baja }}</b></div>
    </div>

    <DataTable :value="filas" :loading="cargando" size="small" stripedRows dataKey="id" data-test="fa-tabla">
      <template #empty><div class="vacio">Todavía no hay activos fijos. Toca <b>Nuevo activo</b> para registrar el primero.</div></template>
      <Column field="codigo" header="Código" style="width: 105px;" />
      <Column header="Nombre" style="min-width: 190px;">
        <template #body="{ data }">
          <div><b>{{ data.nombre }}</b></div>
          <small v-if="data.serie" class="gris">Serie/placa: {{ data.serie }}</small>
        </template>
      </Column>
      <Column field="grupo_nombre" header="Grupo" style="min-width: 150px;" />
      <Column header="Responsable" style="min-width: 140px;">
        <template #body="{ data }">{{ data.responsable ?? '—' }}</template>
      </Column>
      <Column header="Adquisición" style="width: 105px;">
        <template #body="{ data }">{{ fecha(data.fecha_adquisicion) }}</template>
      </Column>
      <Column header="Costo" style="width: 105px; text-align: right;">
        <template #body="{ data }">{{ money(data.costo) }}</template>
      </Column>
      <Column header="Dep. acumulada" style="width: 120px; text-align: right;">
        <template #body="{ data }">{{ money(data.depreciacion_acumulada) }}</template>
      </Column>
      <Column header="Valor en libros" style="width: 120px; text-align: right;">
        <template #body="{ data }"><b>{{ money(data.valor_en_libros) }}</b></template>
      </Column>
      <Column header="Estado" style="width: 115px;">
        <template #body="{ data }">
          <Tag :value="etiquetaEstado(data.estado)" :severity="data.estado === 'activo' ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column header="" style="width: 130px;">
        <template #body="{ data }">
          <Button icon="pi pi-pencil" text size="small" v-tooltip.top="'Editar'" :data-editar="data.codigo" @click="editar(data)" />
          <Button icon="pi pi-list" text size="small" severity="secondary" v-tooltip.top="'Ver depreciación'" :data-cronograma="data.codigo" @click="abrirCronograma(data)" />
          <Button v-if="data.estado === 'activo'" icon="pi pi-ban" text size="small" severity="danger" v-tooltip.top="'Dar de baja'" :data-baja="data.codigo" @click="abrirBaja(data)" />
        </template>
      </Column>
    </DataTable>

    <!-- Nuevo / editar -->
    <Dialog v-model:visible="verFicha" modal :header="editando ? 'Editar activo ' + form.codigo : 'Nuevo activo fijo'" style="width: 700px;" data-test="fa-ficha">
      <Message v-if="errorFicha" severity="error" :closable="false" style="margin-bottom: 10px;" data-test="fa-error">{{ errorFicha }}</Message>
      <Message v-if="bloqueado" severity="info" :closable="false" style="margin-bottom: 10px;">
        Este activo ya tiene depreciación contabilizada o está dado de baja: el costo, el valor residual, la vida útil y las fechas no se pueden cambiar.
        Los demás datos sí.
      </Message>

      <fieldset class="kvs-fieldset">
        <legend>Datos del activo</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Código:</label>
          <InputText v-model="form.codigo" class="kvs-in" maxlength="30" placeholder="Se asigna solo si lo dejas vacío" data-test="fa-codigo" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Nombre:</label>
          <InputText v-model="form.nombre" class="kvs-in" maxlength="150" data-test="fa-nombre" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Grupo:</label>
          <Select v-model="form.grupo" :options="grupos" optionLabel="nombre" optionValue="valor" placeholder="Elige el grupo" class="kvs-in" data-test="fa-form-grupo" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Descripción:</label>
          <InputText v-model="form.descripcion" class="kvs-in" maxlength="2000" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Departamento:</label>
          <InputText v-model="form.departamento" class="kvs-in" maxlength="100" placeholder="Departamento o ubicación" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Responsable:</label>
          <Select v-model="form.employee_id" :options="empleados" optionLabel="nombres" optionValue="id" filter showClear placeholder="Sin responsable"
                  class="kvs-in" data-test="fa-responsable" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Serie / placa:</label>
          <InputText v-model="form.serie" class="kvs-in" maxlength="80" />
        </div>
      </fieldset>

      <fieldset class="kvs-fieldset" :disabled="bloqueado">
        <legend>Costo y depreciación</legend>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Fecha de adquisición:</label>
          <InputText v-model="form.fecha_adquisicion" type="date" class="kvs-in" style="max-width: 180px;" data-test="fa-fecha" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Costo:</label>
          <InputNumber v-model="form.costo" mode="currency" currency="USD" locale="en-US" class="kvs-in" style="max-width: 180px;"
                       @focus="($event: Event) => ($event.target as HTMLInputElement).select()" inputId="fa-costo" data-test="fa-costo" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Valor residual:</label>
          <InputNumber v-model="form.valor_residual" mode="currency" currency="USD" locale="en-US" class="kvs-in" style="max-width: 180px;"
                       @focus="($event: Event) => ($event.target as HTMLInputElement).select()" inputId="fa-residual" data-test="fa-residual" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Vida útil (meses):</label>
          <InputNumber v-model="form.vida_util_meses" :min="1" :max="1200" :useGrouping="false" class="kvs-in" style="max-width: 120px;"
                       @focus="($event: Event) => ($event.target as HTMLInputElement).select()" inputId="fa-vida" data-test="fa-vida" />
          <small v-if="vidaSugerida && !bloqueado" class="gris" data-test="fa-vida-sugerida">
            Sugerida para este grupo: {{ vidaSugerida }} meses ({{ vidaSugerida / 12 }} años). Confírmala con tu contador según la normativa tributaria vigente.
          </small>
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Inicia a depreciar en:</label>
          <InputText v-model="form.inicio_mes" type="month" class="kvs-in" style="max-width: 180px;" data-test="fa-inicio" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl">Método:</label>
          <Select :modelValue="'linea_recta'" :options="[{ l: 'Línea recta', v: 'linea_recta' }]" optionLabel="l" optionValue="v" disabled class="kvs-in" style="max-width: 180px;" />
        </div>
        <p class="cuota" data-test="fa-cuota">
          Depreciación mensual: <b>{{ money(cuotaPrevista) }}</b>
          <span class="gris"> = (costo − valor residual) ÷ vida útil. El último mes absorbe los centavos del redondeo.</span>
        </p>
      </fieldset>

      <fieldset v-if="!editando" class="kvs-fieldset">
        <legend>Origen del costo</legend>
        <div class="kvs-row">
          <label class="kvs-lbl">Desde una compra:</label>
          <Select :modelValue="form.purchase_id" :options="compras" optionValue="id" showClear placeholder="Sin compra (alta manual)" class="kvs-in"
                  @update:modelValue="elegirCompra" data-test="fa-compra">
            <template #option="{ option }">{{ option.numero }} · {{ option.proveedor }} · {{ money(option.disponible) }}</template>
            <template #value="{ value }">
              <span v-if="value">{{ compraElegida?.numero }} · {{ compraElegida?.proveedor }} · disponible {{ money(compraElegida?.disponible) }}</span>
              <span v-else class="gris">Sin compra (alta manual)</span>
            </template>
          </Select>
        </div>
        <p v-if="compraElegida && compraElegida.capitalizada" class="nota" data-test="fa-nota-capitalizada">
          Esa compra ya llevó el costo a Activos fijos: la ficha solo lo reparte y no genera otro asiento.
        </p>
        <template v-else>
          <p v-if="compraElegida" class="nota nota--aviso" data-test="fa-nota-no-capitalizada">
            Esa compra no se capitalizó (se importó del XML o tiene otro sustento). Elige la cuenta donde se asentó —Inventario, Compras o Gastos—
            y el alta la reclasifica a Activos fijos.
          </p>
          <div class="kvs-row">
            <label style="display: flex; align-items: center; gap: 8px;">
              <Checkbox v-model="form.registrar_alta" :binary="true" inputId="fa-alta" data-test="fa-registrar-alta" />
              <span>Registrar el alta (Debe Activos fijos / Haber la cuenta que elijas)</span>
            </label>
          </div>
          <div v-if="form.registrar_alta" class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Contrapartida:</label>
            <Select v-model="form.cuenta_contrapartida_id" :options="contrapartidas" optionLabel="label" optionValue="id" filter
                    placeholder="Bancos, Caja, Capital..." class="kvs-in" data-test="fa-contrapartida" />
          </div>
          <p v-else class="gris" style="margin: 0 0 4px 4px; font-size: 12.5px;">
            Marca esta opción desmarcada solo si el costo de este activo ya está en la contabilidad (por ejemplo, en los saldos iniciales).
          </p>
        </template>
      </fieldset>

      <div class="kvs-row">
        <label class="kvs-lbl">Observación:</label>
        <InputText v-model="form.observacion" class="kvs-in" maxlength="2000" />
      </div>

      <template #footer>
        <Button v-if="editando && form.puede_eliminar" label="Eliminar" icon="pi pi-trash" severity="danger" text style="margin-right: auto;" @click="eliminar" />
        <Button label="Cancelar" text @click="verFicha = false" />
        <Button label="Guardar" icon="pi pi-save" :loading="guardando" @click="guardar" data-test="fa-guardar" />
      </template>
    </Dialog>

    <!-- Cronograma -->
    <Dialog v-model:visible="verCronograma" modal :header="'Depreciación de ' + (detalle?.codigo ?? '')" style="width: 760px;" data-test="fa-cronograma">
      <template v-if="detalle">
        <p class="resumen">
          <b>{{ detalle.nombre }}</b> · costo {{ money(detalle.costo) }} · residual {{ money(detalle.valor_residual) }} · {{ detalle.vida_util_meses }} meses
          · cuota {{ money(detalle.cuota_mensual) }}<br />
          Depreciado {{ money(detalle.depreciacion_acumulada) }} ({{ detalle.meses_depreciados }} {{ detalle.meses_depreciados === 1 ? 'mes' : 'meses' }}) ·
          valor en libros <b>{{ money(detalle.valor_en_libros) }}</b>
          <span v-if="detalle.estado === 'dado_de_baja'"> · dado de baja el {{ fecha(detalle.fecha_baja) }}</span>
        </p>
        <DataTable :value="detalle.cronograma" size="small" stripedRows scrollable scrollHeight="360px" dataKey="numero_mes">
          <Column field="numero_mes" header="N.º" style="width: 55px;" />
          <Column field="periodo" header="Período" style="width: 90px;" />
          <Column header="Depreciación" style="text-align: right;"><template #body="{ data }">{{ money(data.monto) }}</template></Column>
          <Column header="Acumulada" style="text-align: right;"><template #body="{ data }">{{ money(data.acumulada) }}</template></Column>
          <Column header="Valor en libros" style="text-align: right;"><template #body="{ data }">{{ money(data.valor_en_libros) }}</template></Column>
          <Column header="Estado" style="width: 130px;">
            <template #body="{ data }">
              <Tag :value="data.estado === 'contabilizado' ? 'Contabilizado' : 'Pendiente'" :severity="data.estado === 'contabilizado' ? 'success' : 'secondary'" />
            </template>
          </Column>
          <Column header="Asiento" style="width: 100px;"><template #body="{ data }">{{ data.asiento?.numero ?? '' }}</template></Column>
        </DataTable>
      </template>
      <p v-else-if="cargandoDetalle" class="gris">Cargando...</p>
      <template #footer><Button label="Cerrar" text @click="verCronograma = false" /></template>
    </Dialog>

    <!-- Baja -->
    <Dialog v-model:visible="verBaja" modal :header="'Dar de baja ' + (baja.activo?.codigo ?? '')" style="width: 560px;" data-test="fa-baja">
      <template v-if="baja.activo">
        <p style="margin-top: 0;">
          <b>{{ baja.activo.nombre }}</b> · costo {{ money(baja.activo.costo) }} · depreciado {{ money(baja.activo.depreciacion_acumulada) }} ·
          valor en libros {{ money(baja.activo.valor_en_libros) }}.
        </p>
        <p class="gris" style="font-size: 12.5px;">
          Se deprecian los meses pendientes hasta el mes de la baja y un asiento saca el costo y toda la depreciación acumulada. Si lo vendiste por más
          o por menos que su valor en libros, la diferencia queda como utilidad o pérdida en baja de activos.
        </p>
        <Message v-if="errorBaja" severity="error" :closable="false" style="margin-bottom: 10px;" data-test="fa-baja-error">{{ errorBaja }}</Message>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Fecha de la baja:</label>
          <InputText v-model="baja.fecha" type="date" class="kvs-in" style="max-width: 180px;" data-test="fa-baja-fecha" />
        </div>
        <div class="kvs-row">
          <label class="kvs-lbl"><span class="req-star">*</span> Motivo:</label>
          <InputText v-model="baja.motivo" class="kvs-in" maxlength="250" placeholder="Vendido, obsoleto, robo..." data-test="fa-baja-motivo" />
        </div>
        <div class="kvs-row">
          <label style="display: flex; align-items: center; gap: 8px;">
            <Checkbox v-model="baja.vendido" :binary="true" inputId="fa-vendido" data-test="fa-vendido" />
            <span>Se vendió</span>
          </label>
        </div>
        <template v-if="baja.vendido">
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Valor de venta:</label>
            <InputNumber v-model="baja.valor_venta" mode="currency" currency="USD" locale="en-US" class="kvs-in" style="max-width: 180px;"
                         @focus="($event: Event) => ($event.target as HTMLInputElement).select()" inputId="fa-venta" data-test="fa-venta" />
          </div>
          <div class="kvs-row">
            <label class="kvs-lbl">Se cobró en:</label>
            <Select v-model="baja.cuenta_cobro" :options="[{ l: 'Bancos', v: 'bancos' }, { l: 'Caja', v: 'caja' }]" optionLabel="l" optionValue="v"
                    class="kvs-in" style="max-width: 180px;" data-test="fa-cobro" />
          </div>
        </template>
      </template>
      <template #footer>
        <Button label="Cancelar" text @click="verBaja = false" />
        <Button label="Dar de baja" icon="pi pi-ban" severity="danger" :loading="dandoBaja" @click="darDeBaja" data-test="fa-confirmar-baja" />
      </template>
    </Dialog>

    <DepreciacionDialog v-model:visible="verDepreciar" @done="depreciado" />
  </div>
</template>

<style scoped>
.fa { padding: 20px; max-width: 1300px; }
.encabezado { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.fa h2 { margin: 0 0 4px; color: #1f2733; }
.sub { color: #94a3b8; font-size: 13px; margin: 0; max-width: 680px; }
.acciones-cab { display: flex; gap: 8px; flex-wrap: wrap; }
.filtros { display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.totales { display: flex; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
.totales > div { background: #f4f8f9; border: 1px solid #dbe6ea; border-radius: 8px; padding: 8px 14px; display: flex; flex-direction: column; min-width: 150px; }
.totales span { font-size: 11.5px; color: #64748b; }
.totales b { font-size: 16px; color: #1f2733; }
.gris { color: #94a3b8; }
.vacio { padding: 18px; text-align: center; color: #64748b; }
.cuota { margin: 2px 0 6px 4px; font-size: 13.5px; }
.nota { margin: 0 0 8px 4px; font-size: 12.5px; color: #475569; }
.nota--aviso { color: #b45309; }
.resumen { margin-top: 0; font-size: 13px; line-height: 1.6; }
</style>
