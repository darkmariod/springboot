<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'
import KvsModuleHeader from '../components/kvs/KvsModuleHeader.vue'

const company = useCompanyStore()
const session = ref<any>(null)
const saldoInicial = ref(200)
const mov = ref({ tipo: 'ingreso', monto: 0, concepto: '' })
const arqueo = ref(0)
const cierre = ref<any>(null)
const tipos = [{ label: 'Ingreso', value: 'ingreso' }, { label: 'Egreso', value: 'egreso' }]

const money = (n: any) => `$${Number(n ?? 0).toFixed(2)}`
const esperado = computed(() => session.value ? Number(session.value.saldo_inicial) + Number(session.value.ingresos) - Number(session.value.egresos) : 0)

async function load() { session.value = (await api.get(`/cash/current?company_id=${company.activeId}`)).data }
async function abrir() { session.value = (await api.post('/cash/open', { company_id: company.activeId, saldo_inicial: saldoInicial.value })).data }
async function agregar() {
  session.value = (await api.post(`/cash/${session.value.id}/movements`, mov.value)).data
  mov.value = { tipo: 'ingreso', monto: 0, concepto: '' }
}
async function cerrar() {
  cierre.value = (await api.post(`/cash/${session.value.id}/close`, { saldo_final_contado: arqueo.value })).data
  session.value = null
}
onMounted(load)
</script>

<template>
  <div style="display: flex; flex-direction: column; height: 100%;">
    <KvsModuleHeader module-name="Caja y Bancos" :company="{ ruc: company.activeId, razon_social: 'Cuadre de Caja' }" subtitle="Apertura, movimientos y cierre" />
    <div style="padding: 16px; flex: 1; overflow: auto;">
    <div class="kvs-window" style="max-width: 780px;">
      <div class="kvs-window-title">Caja Diaria</div>

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
            <label class="kvs-lbl"><span class="req">*</span> Saldo inicial:</label>
            <InputNumber v-model="saldoInicial" mode="currency" currency="USD" class="kvs-in" />
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
              <label class="kvs-lbl"><span class="req">*</span> Tipo:</label>
              <Select v-model="mov.tipo" :options="tipos" optionLabel="label" optionValue="value" class="kvs-in" />
              <label class="kvs-lbl" style="margin-left: 12px;"><span class="req">*</span> Monto:</label>
              <InputNumber v-model="mov.monto" mode="currency" currency="USD" class="kvs-in" />
            </div>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Concepto:</label>
              <InputText v-model="mov.concepto" placeholder="Descripción del movimiento" class="kvs-in" />
            </div>
          </fieldset>
        </div>
        <div class="kvs-footer">
          <Button label="Agregar" icon="pi pi-plus" size="small" @click="agregar" />
        </div>

        <!-- Movimientos -->
        <div style="padding: 0 14px 14px;">
          <DataTable :value="session.movements" size="small" stripedRows>
            <Column field="tipo" header="Tipo" style="width: 90px;">
              <template #body="{ data }">
                <Tag :value="data.tipo" :severity="data.tipo === 'ingreso' ? 'success' : 'danger'" />
              </template>
            </Column>
            <Column field="concepto" header="Concepto" />
            <Column header="Monto" style="text-align: right;">
              <template #body="{ data }">
                <span :style="{ color: data.tipo === 'ingreso' ? '#22a06b' : '#d93025' }">
                  {{ data.tipo === 'ingreso' ? '+' : '-' }}{{ money(data.monto) }}
                </span>
              </template>
            </Column>
          </DataTable>
        </div>

        <!-- Cierre -->
        <div style="padding: 0 14px 14px;">
          <fieldset class="kvs-fieldset">
            <legend>Cerrar Caja</legend>
            <div class="kvs-row">
              <label class="kvs-lbl"><span class="req">*</span> Arqueo (contado físico):</label>
              <InputNumber v-model="arqueo" mode="currency" currency="USD" class="kvs-in" />
            </div>
          </fieldset>
        </div>
        <div class="kvs-footer">
          <Button label="Cerrar caja" icon="pi pi-lock" severity="secondary" @click="cerrar" />
        </div>
      </template>
    </div>
    </div>
  </div>
</template>
