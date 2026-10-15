<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import { useCuentasStore } from '../stores/cuentas'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'
import DepositoDialog from '../components/DepositoDialog.vue'

const company = useCompanyStore()
const cuentas = useCuentasStore()
const session = ref<any>(null)
const saldoInicial = ref(200)
const mov = ref<any>({ tipo: 'ingreso', monto: 0, concepto: '', cuenta_contrapartida_id: null })
const msg = ref<any>(null)
const depositoVisible = ref(false)
const imprimiendo = ref<number | null>(null)
const arqueo = ref(0)
const cierre = ref<any>(null)
const tipos = [{ label: 'Ingreso', value: 'ingreso' }, { label: 'Egreso', value: 'egreso' }]

const money = (n: any) => `$${Number(n ?? 0).toFixed(2)}`
const esperado = computed(() => session.value ? Number(session.value.saldo_inicial) + Number(session.value.ingresos) - Number(session.value.egresos) : 0)

const errorDe = (err: any, defecto: string) => {
  const e = err.response?.data?.errors
  return e ? Object.values(e).flat().join(' · ') : (err.response?.data?.message ?? defecto)
}

async function load() { session.value = (await api.get(`/cash/current?company_id=${company.activeId}`)).data || null }
async function abrir() { session.value = (await api.post('/cash/open', { company_id: company.activeId, saldo_inicial: saldoInicial.value })).data }

// Cada movimiento lleva su número (CI-/CE-), su asiento y la cuenta de contrapartida que elijas
async function agregar() {
  msg.value = null
  if (!mov.value.cuenta_contrapartida_id) {
    msg.value = { type: 'error', text: 'Elige la cuenta de contrapartida del movimiento.' }
    return
  }
  try {
    const { data } = await api.post(`/cash/${session.value.id}/movements`, mov.value)
    const creado = data.movimiento
    session.value = data
    msg.value = { type: 'success', text: `Se registró el ${creado.tipo === 'ingreso' ? 'ingreso' : 'egreso'} ${creado.numero} por ${money(creado.monto)}.` }
    mov.value = { tipo: mov.value.tipo, monto: 0, concepto: '', cuenta_contrapartida_id: null }
  } catch (err: any) {
    msg.value = { type: 'error', text: errorDe(err, 'No se pudo registrar el movimiento.') }
  }
}

async function imprimir(m: any) {
  if (imprimiendo.value) return
  imprimiendo.value = m.id
  try {
    const res = await api.get(`/cash-movements/${m.id}/comprobante`, { params: { company_id: company.activeId }, responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    a.download = `Comprobante-${m.numero ?? 'caja-' + m.id}.pdf`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    msg.value = { type: 'error', text: 'No se pudo generar el comprobante en PDF. Inténtalo de nuevo.' }
  } finally {
    imprimiendo.value = null
  }
}

async function depositoGuardado(d: any) {
  msg.value = { type: 'success', text: `Depósito ${d.numero} registrado por ${money(d.monto)} (asiento ${d.asiento}). Quedan ${money(d.disponible)} disponibles en caja.` }
  if (session.value) await load()
}
async function cerrar() {
  cierre.value = (await api.post(`/cash/${session.value.id}/close`, { saldo_final_contado: arqueo.value })).data
  session.value = null
}
onMounted(() => { load(); cuentas.cargar() })
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Caja y Bancos" :company="{ ruc: company.activeRuc, razon_social: 'Cuadre de Caja' }" subtitle="Apertura, movimientos y cierre" />
    <div style="padding: 16px; flex: 1; overflow: auto;">
    <div class="kvs-window" style="max-width: 780px;">
      <div class="kvs-window-title">Caja Diaria</div>

      <div style="padding: 10px 14px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <Button label="Depositar a banco" icon="pi pi-building-columns" size="small" severity="secondary" outlined data-testid="btn-depositar" @click="depositoVisible = true" />
        <span style="font-size: 12px; color: #64748b;">Lleva el efectivo cobrado de la caja al banco, con su papeleta.</span>
      </div>
      <Message v-if="msg" :severity="msg.type" closable style="margin: 10px 14px 0;" @close="msg = null">{{ msg.text }}</Message>

      <!-- Caja cerrada: mostrar resumen del cierre -->
      <div v-if="cierre && !session" style="padding: 14px;">
        <Tag value="Caja cerrada" severity="success" style="margin-bottom: 12px;" />
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px;">
          <div style="border: 1px solid #e2e5ea; border-radius: 6px; padding: 10px; background: #fff;">
            <div style="font-size: 11px; color: #94a3b8;">ESPERADO</div>
            <b>{{ money(cierre.esperado) }}</b>
          </div>
          <div style="border: 1px solid #e2e5ea; border-radius: 6px; padding: 10px; background: #fff;">
            <div style="font-size: 11px; color: #94a3b8;">CONTADO</div>
            <b>{{ money(cierre.session.saldo_final_contado) }}</b>
          </div>
          <div style="border: 1px solid #e2e5ea; border-radius: 6px; padding: 10px; background: #fff;">
            <div style="font-size: 11px; color: #94a3b8;">DIFERENCIA</div>
            <b :style="{ color: cierre.diferencia === 0 ? '#22a06b' : '#d93025' }">{{ money(cierre.diferencia) }}</b>
          </div>
        </div>
      </div>

      <!-- Apertura -->
      <div v-if="!session && !cierre" style="padding: 14px;">
        <fieldset class="kvs-fieldset">
          <legend>Abrir Caja</legend>
          <p style="margin: 0 0 10px; font-size: 12px; color: #64748b;">No hay caja abierta. Defina el saldo inicial (fondo de caja).</p>
          <div class="kvs-row">
            <label class="kvs-lbl"><span class="req-star">*</span> Saldo inicial:</label>
            <InputNumber v-model="saldoInicial" mode="currency" currency="USD" @focus="($event: FocusEvent) => ($event.target as HTMLInputElement).select()" class="kvs-in" />
          </div>
        </fieldset>
      </div>
      <div v-if="!session && !cierre" class="kvs-footer">
        <Button label="Abrir caja" icon="pi pi-lock-open" @click="abrir" />
      </div>

      <!-- Caja abierta -->
      <template v-if="session">
        <div style="padding: 14px;">
          <!-- Resumen -->
          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px;">
            <div style="border: 1px solid #e2e5ea; border-radius: 6px; padding: 10px; background: #fff;">
              <div style="font-size: 11px; color: #94a3b8;">INICIAL</div>
              <b>{{ money(session.saldo_inicial) }}</b>
            </div>
            <div style="border: 1px solid #e2e5ea; border-radius: 6px; padding: 10px; background: #fff;">
              <div style="font-size: 11px; color: #22a06b;">INGRESOS</div>
              <b style="color: #22a06b;">{{ money(session.ingresos) }}</b>
            </div>
            <div style="border: 1px solid #e2e5ea; border-radius: 6px; padding: 10px; background: #fff;">
              <div style="font-size: 11px; color: #d93025;">EGRESOS</div>
              <b style="color: #d93025;">{{ money(session.egresos) }}</b>
            </div>
            <div style="border: 1px solid #2c3e50; border-radius: 6px; padding: 10px; background: #f8fafc;">
              <div style="font-size: 11px; color: #94a3b8;">ESPERADO</div>
              <b>{{ money(esperado) }}</b>
            </div>
          </div>

          <!-- Registro de movimiento -->
          <fieldset class="kvs-fieldset">
            <legend>Registrar movimiento</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Tipo:</label>
              <Select v-model="mov.tipo" :options="tipos" optionLabel="label" optionValue="value" class="kvs-in" />
              <label class="kvs-lbl" style="margin-left: 12px;"><span class="req-star">*</span> Monto:</label>
              <InputNumber v-model="mov.monto" mode="currency" currency="USD" @focus="($event: FocusEvent) => ($event.target as HTMLInputElement).select()" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Concepto:</label>
              <InputText v-model="mov.concepto" placeholder="Descripción del movimiento" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Contrapartida:</label>
              <Select v-model="mov.cuenta_contrapartida_id" :options="cuentas.detalle" optionLabel="label" optionValue="id" filter
                      :placeholder="mov.tipo === 'ingreso' ? 'Cuenta de donde viene el dinero' : 'Cuenta a la que se carga el gasto o pago'"
                      emptyFilterMessage="Sin coincidencias" class="kvs-in" data-testid="contrapartida" />
            </div>
            <p style="margin: 0; font-size: 11.5px; color: #64748b;">
              {{ mov.tipo === 'ingreso' ? 'Asiento: Debe Caja / Haber la cuenta elegida.' : 'Asiento: Debe la cuenta elegida / Haber Caja.' }}
              Cada movimiento recibe su número ({{ mov.tipo === 'ingreso' ? 'CI-000001' : 'CE-000001' }}…) y su comprobante.
            </p>
          </fieldset>
        </div>
        <div class="kvs-footer">
          <Button label="Agregar" icon="pi pi-plus" size="small" @click="agregar" />
        </div>

        <!-- Movimientos -->
        <div style="padding: 0 14px 14px;">
          <DataTable :value="session.movements" size="small" stripedRows>
            <Column field="numero" header="N.º" style="width: 100px;">
              <template #body="{ data }"><span style="font-family: ui-monospace, Menlo, monospace; font-size: 12px;">{{ data.numero ?? '—' }}</span></template>
            </Column>
            <Column field="tipo" header="Tipo" style="width: 90px;">
              <template #body="{ data }">
                <Tag :value="data.tipo" :severity="data.tipo === 'ingreso' ? 'success' : 'danger'" />
              </template>
            </Column>
            <Column field="concepto" header="Concepto" />
            <Column header="Contrapartida">
              <template #body="{ data }"><span style="font-size: 12px;">{{ data.cuenta ? data.cuenta.codigo + ' · ' + data.cuenta.nombre : '—' }}</span></template>
            </Column>
            <Column header="Monto" style="text-align: right;">
              <template #body="{ data }">
                <span :style="{ color: data.tipo === 'ingreso' ? '#22a06b' : '#d93025' }">
                  {{ data.tipo === 'ingreso' ? '+' : '-' }}{{ money(data.monto) }}
                </span>
              </template>
            </Column>
            <Column header="" style="width: 56px;">
              <template #body="{ data }">
                <Button icon="pi pi-print" size="small" text title="Imprimir comprobante" aria-label="Imprimir comprobante"
                        :loading="imprimiendo === data.id" data-testid="imprimir-comprobante" @click="imprimir(data)" />
              </template>
            </Column>
          </DataTable>
        </div>

        <!-- Cierre -->
        <div style="padding: 0 14px 14px;">
          <fieldset class="kvs-fieldset">
            <legend>Cerrar Caja</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req-star">*</span> Arqueo (contado físico):</label>
              <InputNumber v-model="arqueo" mode="currency" currency="USD" @focus="($event: FocusEvent) => ($event.target as HTMLInputElement).select()" class="kvs-in" />
            </div>
          </fieldset>
        </div>
        <div class="kvs-footer">
          <Button label="Cerrar caja" icon="pi pi-lock" severity="secondary" @click="cerrar" />
        </div>
      </template>
    </div>
    </div>
    <DepositoDialog v-model:visible="depositoVisible" @saved="depositoGuardado" />
  </div>
</template>
