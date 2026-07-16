<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

const company = useCompanyStore()
const rows = ref<any[]>([])
const loading = ref(true)
const preview = ref<any>(null)
const docRef = ref<HTMLElement>()

const estadoSev: Record<string, string> = {
  generado: 'warn', firmado: 'info', enviado: 'info', AUTORIZADO: 'success', autorizado: 'success',
}
const money = (n: any) => '$' + Number(n).toFixed(2)
const empresa = computed(() => company.companies.find((c: any) => c.id === company.activeId))

async function load() {
  loading.value = true
  rows.value = (await api.get('/invoices?company_id=' + company.activeId)).data
  loading.value = false
}
// Print the RIDE document alone, in its own window (like the PDF of the video)
function imprimir() {
  if (!docRef.value) return
  const w = window.open('', '_blank', 'width=820,height=900')
  if (!w) return
  w.document.write('<html><head><title>Factura ' + preview.value.numero + '</title></head><body style="margin:0; font-family: Arial, Helvetica, sans-serif;">'
    + docRef.value.innerHTML + '</body></html>')
  w.document.close()
  w.focus()
  w.print()
}
onMounted(load)
</script>

<template>
  <div style="padding:20px;">
    <h2 style="margin:0 0 14px;">Facturas</h2>
    <DataTable :value="rows" :loading="loading" size="small" paginator :rows="15" stripedRows>
      <Column field="numero" header="Número" />
      <Column header="Cliente"><template #body="{ data }">{{ data.contact?.razon_social }}</template></Column>
      <Column header="Fecha"><template #body="{ data }">{{ String(data.fecha_emision).slice(0,10) }}</template></Column>
      <Column header="Total"><template #body="{ data }">{{ money(data.importe_total) }}</template></Column>
      <Column header="Pago"><template #body="{ data }">{{ data.forma_pago }}</template></Column>
      <Column header="Estado SRI">
        <template #body="{ data }">
          <Tag :value="data.sri_document?.estado ?? '—'" :severity="estadoSev[data.sri_document?.estado] ?? 'secondary'" />
        </template>
      </Column>
      <Column header=""><template #body="{ data }">
        <Button label="Ver" icon="pi pi-eye" size="small" text @click="preview = data" />
      </template></Column>
    </DataTable>

    <!-- Preview RIDE (como el PDF del video) -->
    <Dialog :visible="!!preview" modal :header="'Factura ' + (preview?.numero ?? '')"
            style="width:760px" @update:visible="preview = null">
      <div v-if="preview" style="display:flex; justify-content:flex-end; margin-bottom:10px;">
        <Button label="Imprimir / PDF" icon="pi pi-print" size="small" outlined @click="imprimir" />
      </div>

      <div v-if="preview" ref="docRef">
        <div style="border:1.5px solid #444; border-radius:6px; padding:0; font-size:12px; color:#222;">
          <!-- Cabecera: emisor + datos del comprobante -->
          <div style="display:flex; border-bottom:1.5px solid #444;">
            <div style="flex:1; padding:14px; border-right:1.5px solid #444;">
              <div style="font-size:17px; font-weight:800;">{{ empresa?.razon_social }}</div>
              <div style="margin-top:6px;"><b>RUC:</b> {{ empresa?.ruc }}</div>
              <div><b>Matriz:</b> {{ empresa?.dir_matriz }}</div>
              <div><b>Obligado a llevar contabilidad:</b> SI</div>
            </div>
            <div style="flex:1; padding:14px;">
              <div style="font-size:15px; font-weight:800;">FACTURA</div>
              <div><b>No.:</b> {{ preview.numero }}</div>
              <div style="margin-top:6px;"><b>NÚMERO DE AUTORIZACIÓN:</b></div>
              <div style="font-family:monospace; font-size:10px; word-break:break-all;">
                {{ preview.sri_document?.numero_autorizacion ?? preview.sri_document?.clave_acceso ?? 'PENDIENTE DE AUTORIZACIÓN' }}
              </div>
              <div style="margin-top:6px;"><b>AMBIENTE:</b> {{ Number(empresa?.ambiente) === 2 ? 'PRODUCCIÓN' : 'PRUEBAS' }}</div>
              <div><b>EMISIÓN:</b> NORMAL</div>
              <div style="margin-top:6px;"><b>CLAVE DE ACCESO:</b></div>
              <div style="font-family:monospace; font-size:10px; word-break:break-all;">{{ preview.sri_document?.clave_acceso }}</div>
            </div>
          </div>

          <!-- Cliente -->
          <div style="padding:10px 14px; border-bottom:1.5px solid #444;">
            <div><b>Razón Social / Nombres:</b> {{ preview.contact?.razon_social }}</div>
            <div style="display:flex; gap:24px;">
              <span><b>RUC / CI:</b> {{ preview.contact?.identificacion }}</span>
              <span><b>Fecha Emisión:</b> {{ String(preview.fecha_emision).slice(0,10) }}</span>
            </div>
          </div>

          <!-- Detalle -->
          <table style="width:100%; border-collapse:collapse; font-size:11.5px;">
            <thead>
              <tr style="border-bottom:1px solid #444; background:#f2f2f2;">
                <th style="text-align:left; padding:6px 10px;">Código</th>
                <th style="text-align:left; padding:6px 10px;">Cant.</th>
                <th style="text-align:left; padding:6px 10px;">Descripción</th>
                <th style="text-align:right; padding:6px 10px;">Precio Unitario</th>
                <th style="text-align:right; padding:6px 10px;">Valor Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(it, i) in (preview.items ?? [])" :key="i" style="border-bottom:1px solid #ddd;">
                <td style="padding:5px 10px;">{{ it.codigo_principal }}</td>
                <td style="padding:5px 10px;">{{ it.cantidad }}</td>
                <td style="padding:5px 10px;">{{ it.descripcion }}
                  <span v-if="it.series?.length" style="color:#555;"> (Serie: {{ it.series.join(', ') }})</span></td>
                <td style="text-align:right; padding:5px 10px;">{{ money(it.precio_unitario) }}</td>
                <td style="text-align:right; padding:5px 10px;">{{ money(it.cantidad * it.precio_unitario) }}</td>
              </tr>
            </tbody>
          </table>

          <!-- Totales + forma de pago -->
          <div style="display:flex; border-top:1.5px solid #444;">
            <div style="flex:1; padding:10px 14px; border-right:1.5px solid #444;">
              <b>Forma de pago:</b> {{ preview.forma_pago?.toUpperCase() }}
              <div v-if="Number(preview.saldo_pendiente) > 0" style="margin-top:4px;">
                <b>Saldo pendiente:</b> {{ money(preview.saldo_pendiente) }}
              </div>
            </div>
            <div style="width:260px; padding:10px 14px;">
              <div style="display:flex; justify-content:space-between;">
                <span>SUBTOTAL 15%</span><span>{{ money(preview.total_sin_impuestos) }}</span></div>
              <div style="display:flex; justify-content:space-between;">
                <span>IVA 15%</span><span>{{ money(preview.total_impuesto) }}</span></div>
              <div style="display:flex; justify-content:space-between; font-weight:800; border-top:1px solid #444; margin-top:4px; padding-top:4px;">
                <span>VALOR TOTAL</span><span>{{ money(preview.importe_total) }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </Dialog>
  </div>
</template>
