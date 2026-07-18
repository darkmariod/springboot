<script setup lang="ts">
import { ref } from 'vue'
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

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

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
const anio = ref<number>(new Date().getFullYear())
const atsLoading = ref(false)
const atsData = ref<any>(null)
const atsError = ref<string | null>(null)

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
              <label class="kvs-lbl" style="min-width: auto;"><span class="req">*</span> Desde:</label>
              <DatePicker v-model="f104Desde" dateFormat="dd/mm/yy" showIcon style="width: 160px;" />
            </div>
            <div class="kvs-row" style="margin-bottom: 0;">
              <label class="kvs-lbl" style="min-width: auto;"><span class="req">*</span> Hasta:</label>
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
              <DataTable :value="[
                { concepto: 'Total ventas', valor: f104Data.total_ventas },
                { concepto: 'Ventas al 0%', valor: f104Data.ventas_0 },
                { concepto: 'Ventas al 12%', valor: f104Data.ventas_12 },
                { concepto: 'IVA cobrado', valor: f104Data.iva_cobrado },
              ]" size="small" stripedRows>
                <Column field="concepto" header="Concepto" />
                <Column header="Valor">
                  <template #body="{ data }">{{ money(data.valor) }}</template>
                </Column>
              </DataTable>
            </template>
          </Card>

          <!-- Compras -->
          <Card>
            <template #title>Compras</template>
            <template #content>
              <DataTable :value="[
                { concepto: 'Total compras', valor: f104Data.total_compras },
                { concepto: 'Compras al 0%', valor: f104Data.compras_0 },
                { concepto: 'Compras al 12%', valor: f104Data.compras_12 },
                { concepto: 'IVA pagado', valor: f104Data.iva_pagado },
              ]" size="small" stripedRows>
                <Column field="concepto" header="Concepto" />
                <Column header="Valor">
                  <template #body="{ data }">{{ money(data.valor) }}</template>
                </Column>
              </DataTable>
            </template>
          </Card>

          <!-- Retenciones -->
          <Card>
            <template #title>Retenciones</template>
            <template #content>
              <DataTable :value="[
                { concepto: 'Retenciones recibidas', valor: f104Data.retenciones_recibidas },
                { concepto: 'Retenciones emitidas', valor: f104Data.retenciones_emitidas },
              ]" size="small" stripedRows>
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
              <div style="text-align: center; padding: 12px 0;">
                <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px;">
                  {{ (f104Data.saldo_iva ?? 0) >= 0 ? 'A pagar' : 'Devolución' }}
                </div>
                <div :style="{
                  fontSize: '28px',
                  fontWeight: '700',
                  color: (f104Data.saldo_iva ?? 0) >= 0 ? '#ef4444' : '#22c55e',
                }">
                  {{ money(Math.abs(f104Data.saldo_iva ?? 0)) }}
                </div>
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
              <label class="kvs-lbl" style="min-width: auto;"><span class="req">*</span> Año:</label>
              <InputNumber v-model="anio" :min="2010" :max="2099" style="width: 120px;" />
            </div>
            <Button label="Consultar" icon="pi pi-search" :loading="atsLoading" @click="consultarATS" />
          </div>
        </fieldset>

        <Message v-if="atsError" severity="error" :closable="false" style="margin-bottom: 14px;">
          {{ atsError }}
        </Message>

        <DataTable v-if="atsData?.meses" :value="atsData.meses" size="small" stripedRows>
          <Column field="mes" header="Mes" />
          <Column header="Ventas">
            <template #body="{ data }">{{ money(data.ventas) }}</template>
          </Column>
          <Column header="Compras">
            <template #body="{ data }">{{ money(data.compras) }}</template>
          </Column>
          <Column header="Ret. recibidas">
            <template #body="{ data }">{{ money(data.retenciones_recibidas) }}</template>
          </Column>
          <Column header="Ret. emitidas">
            <template #body="{ data }">{{ money(data.retenciones_emitidas) }}</template>
          </Column>
          <Column header="Saldo IVA">
            <template #body="{ data }">
              <span :style="{ fontWeight: '600', color: (data.saldo_iva ?? 0) >= 0 ? '#ef4444' : '#22c55e' }">
                {{ money(data.saldo_iva) }}
              </span>
            </template>
          </Column>
        </DataTable>
      </TabPanel>
    </TabView>
  </div>
</template>
