<script setup lang="ts">
import { computed, ref } from 'vue'
import RadioButton from 'primevue/radiobutton'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import api from '../lib/api'
import { useCompanyStore } from '../stores/company'

// Reportes de inventario con parámetros, al estilo KVS: panel de parámetros + reporte documento.
const company = useCompanyStore()
const tipo = ref('existencias')       // existencias | valorado | series
const filtroArticulo = ref('')
const existenciaMinima = ref<number | null>(null)
const generado = ref(false)
const cargando = ref(false)
const filas = ref<any[]>([])
const totales = ref<any>({})

const money = (n: any) => '$' + Number(n).toFixed(2)
const hoy = new Date().toISOString().slice(0, 10)
const empresa = computed(() => company.companies.find((c: any) => c.id === company.activeId))

const titulos: Record<string, string> = {
  existencias: 'RESUMEN DE EXISTENCIAS POR BODEGA',
  valorado: 'INVENTARIO VALORADO AL COSTO PROMEDIO',
  series: 'RESUMEN GENERAL DE EXISTENCIAS POR SERIES',
}

async function generar() {
  cargando.value = true; generado.value = false
  try {
    if (tipo.value === 'series') {
      const series = (await api.get('/series?company_id=' + company.activeId + '&estado=disponible')).data
      const porProducto: Record<string, any> = {}
      for (const s of series) {
        const p = s.product ?? {}
        porProducto[p.id] ??= { codigo: p.codigo, nombre: p.descripcion,
          series: 0, precio: Number(p.precio ?? 0) }
        porProducto[p.id].series++
      }
      filas.value = Object.values(porProducto)
      totales.value = {
        articulos: filas.value.length,
        series: filas.value.reduce((s: number, f: any) => s + f.series, 0),
        valor: filas.value.reduce((s: number, f: any) => s + f.series * f.precio, 0),
      }
    } else {
      const data = (await api.get('/inventory/stock?company_id=' + company.activeId)).data
      filas.value = data.items
      totales.value = { articulos: data.items.length,
        unidades: data.items.reduce((s: number, i: any) => s + Number(i.stock), 0),
        valor: data.valor_total }
    }
    // Filtros del panel de parámetros
    if (filtroArticulo.value.trim()) {
      const q = filtroArticulo.value.trim().toLowerCase()
      filas.value = filas.value.filter((f: any) =>
        (f.codigo ?? '').toLowerCase().includes(q) ||
        (f.descripcion ?? f.nombre ?? '').toLowerCase().includes(q))
    }
    if (existenciaMinima.value !== null) {
      filas.value = filas.value.filter((f: any) => Number(f.stock ?? f.series) > existenciaMinima.value!)
    }
    generado.value = true
  } finally { cargando.value = false }
}
function resetear() {
  tipo.value = 'existencias'; filtroArticulo.value = ''; existenciaMinima.value = null
  generado.value = false; filas.value = []
}
function imprimir() { window.print() }
function exportarCsv() {
  const cab = tipo.value === 'series'
    ? ['Codigo', 'Nombre', 'Series', 'Precio venta', 'Precio total']
    : ['Codigo', 'Descripcion', 'Stock', 'Costo promedio', 'Valor']
  const cuerpo = filas.value.map((f: any) => tipo.value === 'series'
    ? [f.codigo, f.nombre, f.series, f.precio, (f.series * f.precio).toFixed(2)]
    : [f.codigo, f.descripcion, f.stock, f.costo_promedio, f.valor])
  const csv = [cab, ...cuerpo].map((r) => r.join(';')).join('\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
  a.download = 'reporte-inventario-' + tipo.value + '.csv'
  a.click()
}
</script>

<template>
  <div style="display:flex; height:100%;">
    <!-- Panel de parámetros (como KVS) -->
    <aside class="no-print" style="width:290px; border-right:1px solid #e2e5ea; background:#fff; padding:16px; overflow:auto; flex-shrink:0;">
      <p style="margin:0 0 10px; font-weight:700; font-size:13px; color:#4a3220;">Parámetros</p>

      <p style="margin:0 0 6px; font-size:11px; text-transform:uppercase; color:#94a3b8;">Tipo</p>
      <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:16px;">
        <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
          <RadioButton v-model="tipo" value="existencias" /> Existencias Bodega</label>
        <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
          <RadioButton v-model="tipo" value="valorado" /> Existencias Valorado</label>
        <label style="display:flex; align-items:center; gap:8px; font-size:13px;">
          <RadioButton v-model="tipo" value="series" /> Existencia - Resumido Series</label>
      </div>

      <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; margin-bottom:12px;">
        Artículo
        <InputText v-model="filtroArticulo" placeholder="Código o nombre" fluid />
      </label>
      <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; margin-bottom:12px;">
        Existencia mayor que
        <InputNumber v-model="existenciaMinima" :useGrouping="false" fluid />
      </label>
      <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; margin-bottom:18px;">
        Hasta
        <InputText :modelValue="hoy" disabled fluid />
      </label>

      <div style="display:flex; gap:8px;">
        <Button label="Generar" icon="pi pi-cog" size="small" :loading="cargando" @click="generar" />
        <Button label="Resetear" icon="pi pi-eraser" size="small" text @click="resetear" />
      </div>
    </aside>

    <!-- Reporte -->
    <div style="flex:1; overflow:auto; background:#eef1f5; padding:18px;">
      <div v-if="generado" class="no-print" style="display:flex; gap:8px; margin-bottom:12px; align-items:center;">
        <span style="font-size:12px; color:#64748b;">Exportar:</span>
        <Button label="Imprimir / PDF" icon="pi pi-print" size="small" outlined @click="imprimir" />
        <Button label="CSV / Excel" icon="pi pi-file-excel" size="small" outlined @click="exportarCsv" />
      </div>

      <div v-if="!generado" style="color:#94a3b8; text-align:center; padding:80px 20px;">
        Elegí el tipo de reporte y presioná <b>Generar</b>.
      </div>

      <!-- Documento -->
      <div v-else class="report-doc" style="background:#fff; max-width:860px; margin:0 auto; padding:32px 40px; box-shadow:0 1px 4px rgba(0,0,0,0.12);">
        <div style="text-align:center; margin-bottom:18px;">
          <div style="font-weight:800; font-size:17px;">{{ empresa?.razon_social }}</div>
          <div style="font-weight:700; font-size:14px; margin-top:2px;">{{ titulos[tipo] }}</div>
        </div>
        <div style="font-size:12px; margin-bottom:12px;">
          <div><b>Hasta el:</b> {{ hoy }}</div>
          <div><b>Bodega:</b> BODEGA GENERAL</div>
        </div>

        <table style="width:100%; border-collapse:collapse; font-size:12px;">
          <thead>
            <tr style="border-top:2px solid #1f2733; border-bottom:2px solid #1f2733;">
              <th style="text-align:left; padding:5px 6px;">Código</th>
              <th style="text-align:left; padding:5px 6px;">Nombre</th>
              <template v-if="tipo === 'series'">
                <th style="text-align:right; padding:5px 6px;"># Series</th>
                <th style="text-align:right; padding:5px 6px;">Precio venta</th>
                <th style="text-align:right; padding:5px 6px;">Precio total</th>
              </template>
              <template v-else>
                <th style="text-align:right; padding:5px 6px;">Existencia</th>
                <th style="text-align:right; padding:5px 6px;">Costo prom.</th>
                <th style="text-align:right; padding:5px 6px;">Valor</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in filas" :key="f.codigo" style="border-bottom:1px solid #eee;">
              <td style="padding:4px 6px;">{{ f.codigo }}</td>
              <td style="padding:4px 6px;">{{ f.descripcion ?? f.nombre }}</td>
              <template v-if="tipo === 'series'">
                <td style="text-align:right; padding:4px 6px;">{{ f.series }}</td>
                <td style="text-align:right; padding:4px 6px;">{{ money(f.precio) }}</td>
                <td style="text-align:right; padding:4px 6px;">{{ money(f.series * f.precio) }}</td>
              </template>
              <template v-else>
                <td style="text-align:right; padding:4px 6px;">{{ Number(f.stock).toFixed(2) }}</td>
                <td style="text-align:right; padding:4px 6px;">{{ money(f.costo_promedio) }}</td>
                <td style="text-align:right; padding:4px 6px;">{{ money(f.valor) }}</td>
              </template>
            </tr>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid #1f2733; font-weight:700;">
              <td colspan="2" style="padding:6px;">Totales: {{ totales.articulos }} artículos</td>
              <td style="text-align:right; padding:6px;">{{ tipo === 'series' ? totales.series : Number(totales.unidades ?? 0).toFixed(2) }}</td>
              <td></td>
              <td style="text-align:right; padding:6px;">{{ money(totales.valor ?? 0) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>
