<script setup lang="ts">
import { computed, ref } from 'vue'
import TabView from 'primevue/tabview'
import TabPanel from 'primevue/tabpanel'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Card from 'primevue/card'
import Message from 'primevue/message'
import DatePicker from 'primevue/datepicker'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()

const money = (n: any) => (Number(n ?? 0) < 0 ? '-$' : '$') + Math.abs(Number(n ?? 0)).toFixed(2)

// La API del 104 devuelve los datos agrupados (ventas, compras, retenciones, calculo_iva, resumen).
// `ventas.*` ya viene neto de notas de crédito (lo que se declara); `ventas_12`/`compras_12` son nombres históricos:
// hoy corresponden a la tarifa general (15%).
interface Fila104 { concepto: string; valor: any; fuerte?: boolean }
const v104 = computed(() => f104Data.value?.ventas ?? {})
const c104 = computed(() => f104Data.value?.compras ?? {})
const r104 = computed(() => f104Data.value?.retenciones ?? {})
const calc104 = computed(() => f104Data.value?.calculo_iva ?? {})
const fac104 = computed(() => v104.value.facturas ?? {})
const notas104 = computed(() => v104.value.notas_credito ?? {})
const neg = (n: any) => -Number(n ?? 0)

const filasVentas104 = computed<Fila104[]>(() => [
  { concepto: 'Facturas del período (con IVA)', valor: fac104.value.total_ventas },
  { concepto: 'Menos: notas de crédito del período (con IVA)', valor: neg(notas104.value.total) },
  { concepto: 'Ventas netas (con IVA)', valor: v104.value.total_ventas, fuerte: true },
  { concepto: 'Base neta con tarifa general (15%)', valor: v104.value.ventas_12 },
  { concepto: 'Base neta con tarifa 0%', valor: v104.value.ventas_0 },
])
const filasCompras104 = computed<Fila104[]>(() => [
  { concepto: 'Total compras (con IVA)', valor: c104.value.total_compras },
  { concepto: 'Base con tarifa general (15%)', valor: c104.value.compras_12 },
  { concepto: 'Base con tarifa 0%', valor: c104.value.compras_0 },
  { concepto: 'IVA pagado en compras (crédito tributario)', valor: c104.value.iva_pagado, fuerte: true },
])
// Las dos primeras entran al saldo del IVA; las demás son informativas (renta: ver el formulario 103 y el impuesto a la renta)
const filasRetenciones104 = computed<Fila104[]>(() => {
  const filas: Fila104[] = [
    { concepto: 'Retenciones de IVA recibidas (restan del IVA)', valor: r104.value.recibidas_iva },
    { concepto: 'Retenciones de IVA emitidas como agente de retención (se pagan con esta declaración)', valor: r104.value.emitidas_iva },
    { concepto: 'Retenciones de renta recibidas (crédito para el impuesto a la renta; no afectan el IVA)', valor: r104.value.recibidas_renta },
    { concepto: 'Retenciones de renta emitidas (van al formulario 103; no afectan el IVA)', valor: r104.value.emitidas_renta },
  ]
  if (Number(r104.value.recibidas_otras ?? 0) > 0) {
    filas.push({ concepto: 'Retenciones recibidas sin clasificar (revisa el comprobante; no afectan el IVA)', valor: r104.value.recibidas_otras })
  }
  return filas
})
// El saldo línea por línea: las filas suman exactamente el valor "A pagar"
const filasSaldo104 = computed<Fila104[]>(() => [
  { concepto: 'IVA cobrado en facturas', valor: calc104.value.iva_cobrado_facturas },
  { concepto: 'Menos: IVA de las notas de crédito del período', valor: neg(calc104.value.menos_iva_notas_credito) },
  { concepto: 'IVA cobrado neto', valor: calc104.value.iva_cobrado_neto, fuerte: true },
  { concepto: 'Menos: IVA pagado en compras (crédito tributario)', valor: neg(calc104.value.menos_credito_tributario_compras) },
  { concepto: 'Menos: retenciones de IVA recibidas', valor: neg(calc104.value.menos_retenciones_iva_recibidas) },
  { concepto: 'Más: retenciones de IVA emitidas como agente de retención', valor: calc104.value.mas_retenciones_iva_emitidas },
])
const saldo104 = computed(() => Number(calc104.value.a_pagar ?? f104Data.value?.saldo_iva ?? 0))

// ─── Formulario 104 ───
const f104Desde = ref<Date | null>(null)
const f104Hasta = ref<Date | null>(null)
const f104Loading = ref(false)
const f104Data = ref<any>(null)
const f104Error = ref<string | null>(null)

function fmtDate(d: Date | null) {
  if (!d) return ''
  return d.toISOString().slice(0, 10)
}

async function consultar104() {
  f104Error.value = null
  f104Data.value = null
  if (!f104Desde.value || !f104Hasta.value) {
    f104Error.value = 'Debe seleccionar fecha desde y hasta.'
    return
  }
  f104Loading.value = true
  try {
    const res = await api.get('/taxes/formulario104', {
      params: {
        company_id: company.activeId,
        desde: fmtDate(f104Desde.value),
        hasta: fmtDate(f104Hasta.value),
      },
    })
    f104Data.value = res.data
  } catch (err: any) {
    f104Error.value = err.response?.data?.message ?? 'No se pudo consultar el formulario 104.'
  } finally {
    f104Loading.value = false
  }
}

// ─── ATS Anual ───
const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
const anio = ref<number>(new Date().getFullYear())
const atsLoading = ref(false)
const atsData = ref<any>(null)
const atsError = ref<string | null>(null)

// `ventas_mensuales` y `compras_mensuales` llegan como objetos indexados por el número de mes
const atsMeses = computed(() => {
  const ventas: Record<string, any> = atsData.value?.ventas_mensuales ?? {}
  const compras: Record<string, any> = atsData.value?.compras_mensuales ?? {}
  return Array.from({ length: 12 }, (_, i) => {
    const v = ventas[i + 1] ?? ventas[String(i + 1)] ?? {}
    const c = compras[i + 1] ?? compras[String(i + 1)] ?? {}
    const ivaCobrado = Number(v.iva_cobrado ?? 0)
    const ivaPagado = Number(c.iva_pagado ?? 0)
    return {
      mes: MESES[i],
      num_facturas: Number(v.num_facturas ?? 0),
      total_ventas: Number(v.total_ventas ?? 0),
      iva_cobrado: ivaCobrado,
      num_compras: Number(c.num_compras ?? 0),
      total_compras: Number(c.total_compras ?? 0),
      iva_pagado: ivaPagado,
      saldo_iva: Math.round((ivaCobrado - ivaPagado) * 100) / 100,
    }
  })
})
const atsResumen = computed(() => atsData.value?.resumen_anual ?? null)

async function consultarATS() {
  atsError.value = null
  atsData.value = null
  atsLoading.value = true
  try {
    const res = await api.get('/taxes/ats', {
      params: { company_id: company.activeId, anio: anio.value },
    })
    atsData.value = res.data
  } catch (err: any) {
    atsError.value = err.response?.data?.message ?? 'No se pudo consultar el ATS anual.'
  } finally {
    atsLoading.value = false
  }
}
</script>

<template>
  <div style="padding: 20px;">
    <h2 style="margin: 0 0 14px 0;">Impuestos</h2>

    <TabView>
      <!-- ═══ Formulario 104 ═══ -->
      <TabPanel header="Formulario 104" value="f104">
        <fieldset class="kvs-fieldset" style="margin-bottom: 14px;">
          <legend>Consulta por período</legend>
          <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <div class="kvs-row" style="margin-bottom: 0;">
              <label class="kvs-lbl" style="min-width: auto;"><span class="req-star">*</span> Desde:</label>
              <DatePicker v-model="f104Desde" dateFormat="dd/mm/yy" showIcon style="width: 160px;" />
            </div>
            <div class="kvs-row" style="margin-bottom: 0;">
              <label class="kvs-lbl" style="min-width: auto;"><span class="req-star">*</span> Hasta:</label>
              <DatePicker v-model="f104Hasta" dateFormat="dd/mm/yy" showIcon style="width: 160px;" />
            </div>
            <Button label="Consultar" icon="pi pi-search" :loading="f104Loading" @click="consultar104" />
          </div>
        </fieldset>

        <Message v-if="f104Error" severity="error" :closable="false" style="margin-bottom: 14px;">
          {{ f104Error }}
        </Message>

        <div v-if="f104Data" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
          <!-- Ventas -->
          <Card>
            <template #title>Ventas</template>
            <template #content>
              <DataTable :value="filasVentas104" size="small" stripedRows>
                <Column field="concepto" header="Concepto" />
                <Column header="Valor">
                  <template #body="{ data }"><strong v-if="data.fuerte">{{ money(data.valor) }}</strong><span v-else>{{ money(data.valor) }}</span></template>
                </Column>
              </DataTable>
            </template>
          </Card>

          <!-- Compras -->
          <Card>
            <template #title>Compras</template>
            <template #content>
              <DataTable :value="filasCompras104" size="small" stripedRows>
                <Column field="concepto" header="Concepto" />
                <Column header="Valor">
                  <template #body="{ data }"><strong v-if="data.fuerte">{{ money(data.valor) }}</strong><span v-else>{{ money(data.valor) }}</span></template>
                </Column>
              </DataTable>
            </template>
          </Card>

          <!-- Retenciones -->
          <Card>
            <template #title>Retenciones</template>
            <template #content>
              <DataTable :value="filasRetenciones104" size="small" stripedRows>
                <Column field="concepto" header="Concepto" />
                <Column header="Valor">
                  <template #body="{ data }">{{ money(data.valor) }}</template>
                </Column>
              </DataTable>
            </template>
          </Card>

          <!-- Saldo IVA -->
          <Card>
            <template #title>Saldo del IVA</template>
            <template #content>
              <Message v-if="Number(notas104.sin_emitir ?? 0) > 0" severity="warn" :closable="false" style="margin-bottom: 8px;">
                Hay {{ notas104.sin_emitir }} nota(s) de crédito del SRI guardada(s) sin emitir: ya restan en este cálculo, pero el SRI aún no las conoce. Emítelas antes de declarar.
              </Message>
              <DataTable :value="filasSaldo104" size="small" stripedRows>
                <Column field="concepto" header="Concepto" />
                <Column header="Valor">
                  <template #body="{ data }"><strong v-if="data.fuerte">{{ money(data.valor) }}</strong><span v-else>{{ money(data.valor) }}</span></template>
                </Column>
              </DataTable>
              <div style="text-align: center; padding: 12px 0;">
                <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px;">
                  {{ saldo104 >= 0 ? 'A pagar' : 'Saldo a favor' }}
                </div>
                <div :style="{
                  fontSize: '28px',
                  fontWeight: '700',
                  color: saldo104 >= 0 ? '#ef4444' : '#22c55e',
                }">
                  {{ money(Math.abs(saldo104)) }}
                </div>
              </div>
              <div style="font-size: 12px; color: #64748b; line-height: 1.6;">
                Retenciones de renta recibidas: {{ money(r104.recibidas_renta) }}. Son crédito para el impuesto a la renta y no restan del IVA.
              </div>
            </template>
          </Card>
        </div>
      </TabPanel>

      <!-- ═══ ATS Anual ═══ -->
      <TabPanel header="ATS Anual" value="ats">
        <fieldset class="kvs-fieldset" style="margin-bottom: 14px;">
          <legend>Consulta ATS anual</legend>
          <div style="display: flex; gap: 12px; align-items: center;">
            <div class="kvs-row" style="margin-bottom: 0;">
              <label class="kvs-lbl" style="min-width: auto;"><span class="req-star">*</span> Año:</label>
              <InputNumber v-model="anio" :min="2010" :max="2099" style="width: 120px;" />
            </div>
            <Button label="Consultar" icon="pi pi-search" :loading="atsLoading" @click="consultarATS" />
          </div>
        </fieldset>

        <Message v-if="atsError" severity="error" :closable="false" style="margin-bottom: 14px;">
          {{ atsError }}
        </Message>

        <template v-if="atsData?.ventas_mensuales">
          <DataTable :value="atsMeses" size="small" stripedRows>
            <Column field="mes" header="Mes" />
            <Column field="num_facturas" header="N.° facturas" />
            <Column header="Ventas">
              <template #body="{ data }">{{ money(data.total_ventas) }}</template>
            </Column>
            <Column header="IVA cobrado">
              <template #body="{ data }">{{ money(data.iva_cobrado) }}</template>
            </Column>
            <Column field="num_compras" header="N.° compras" />
            <Column header="Compras">
              <template #body="{ data }">{{ money(data.total_compras) }}</template>
            </Column>
            <Column header="IVA pagado">
              <template #body="{ data }">{{ money(data.iva_pagado) }}</template>
            </Column>
            <Column header="Saldo IVA">
              <template #body="{ data }">
                <span :style="{ fontWeight: '600', color: (data.saldo_iva ?? 0) >= 0 ? '#ef4444' : '#22c55e' }">
                  {{ money(data.saldo_iva) }}
                </span>
              </template>
            </Column>
          </DataTable>

          <Card v-if="atsResumen" style="margin-top: 14px;">
            <template #title>Resumen anual {{ atsData.anio }}</template>
            <template #content>
              <DataTable :value="[
                { concepto: 'Total ventas', valor: atsResumen.total_ventas },
                { concepto: 'IVA cobrado', valor: atsResumen.total_iva_cobrado },
                { concepto: 'Total compras', valor: atsResumen.total_compras },
                { concepto: 'IVA pagado', valor: atsResumen.total_iva_pagado },
                { concepto: 'Saldo de IVA del año', valor: atsResumen.saldo_iva_anual },
              ]" size="small" stripedRows>
                <Column field="concepto" header="Concepto" />
                <Column header="Valor">
                  <template #body="{ data }">{{ money(data.valor) }}</template>
                </Column>
              </DataTable>
            </template>
          </Card>
        </template>
      </TabPanel>
    </TabView>
  </div>
</template>
