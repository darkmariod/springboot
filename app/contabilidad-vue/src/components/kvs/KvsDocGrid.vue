<script setup lang="ts">
/**
 * KvsDocGrid — Grilla densa editable de ítems para documentos (Compras/Ventas).
 *
 * Props:
 *   items       — array de ítems del documento
 *   readonly    — si true, solo lectura
 *   showSeries  — mostrar columna de series
 *   showWarehouse — mostrar columna de bodega
 *   showDiscount — mostrar columnas de descuento
 *   showCostCenter — mostrar columna de centro de costos
 *   productOptions — productos disponibles para autocompletado
 *   warehouseOptions — bodegas disponibles
 *   unitOptions — unidades de medida
 *
 * Emits:
 *   update:items — items modificados
 *   add-item — solicitar agregar ítem
 *   remove-item(index) — eliminar ítem
 *   scan-serie(index) — escanear serie para un ítem
 */
import { computed } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'

const props = withDefaults(defineProps<{
  items?: any[]
  readonly?: boolean
  showSeries?: boolean
  showWarehouse?: boolean
  showDiscount?: boolean
  showCostCenter?: boolean
  productOptions?: any[]
  warehouseOptions?: any[]
  unitOptions?: { label: string; value: string }[]
  ivaOptions?: { label: string; value: number }[]
}>(), {
  items: () => [],
  readonly: false,
  showSeries: true,
  showWarehouse: true,
  showDiscount: true,
  showCostCenter: false,
  productOptions: () => [],
  warehouseOptions: () => [],
  unitOptions: () => [
    { label: 'UNI', value: 'UNI' },
    { label: 'PAR', value: 'PAR' },
    { label: 'DOC', value: 'DOC' },
    { label: 'KG', value: 'KG' },
    { label: 'LT', value: 'LT' },
    { label: 'M', value: 'M' },
    { label: 'M2', value: 'M2' },
    { label: 'M3', value: 'M3' },
  ],
  ivaOptions: () => [
    { label: '15%', value: 15 },
    { label: '5%', value: 5 },
    { label: '0%', value: 0 },
  ],
})

const emit = defineEmits<{
  (e: 'update:items', v: any[]): void
  (e: 'add-item'): void
  (e: 'remove-item', index: number): void
  (e: 'scan-serie', index: number): void
}>()

const money = (n: any) => '$' + Number(n ?? 0).toFixed(2)

// ── Cálculos de totales ──
const totals = computed(() => {
  let subtotal = 0
  let gravado15 = 0
  let gravado5 = 0
  let base0 = 0
  let exento = 0
  let noObjeto = 0
  let iva15 = 0
  let iva5 = 0
  let descuento = 0

  for (const item of props.items) {
    const cant = Number(item.cantidad ?? 0)
    const precio = Number(item.precio_unitario ?? 0)
    const tarifa = Number(item.tarifa ?? 15)
    const dcto = Number(item.descuento ?? 0)
    const base = cant * precio - dcto

    subtotal += base
    descuento += dcto

    if (tarifa === 15) {
      gravado15 += base
      iva15 += base * 0.15
    } else if (tarifa === 5) {
      gravado5 += base
      iva5 += base * 0.05
    } else if (tarifa === 0) {
      base0 += base
    }
  }

  const totalIva = iva15 + iva5
  const total = subtotal + totalIva

  return {
    subtotal, gravado15, gravado5, base0, exento, noObjeto,
    iva15, iva5, totalIva, descuento, total,
  }
})

function updateItem(index: number, field: string, value: any) {
  const newItems = [...props.items]
  newItems[index] = { ...newItems[index], [field]: value }
  emit('update:items', newItems)
}

function onProductSelect(index: number, product: any) {
  if (!product) return
  const newItems = [...props.items]
  newItems[index] = {
    ...newItems[index],
    codigo_principal: product.codigo,
    descripcion: product.descripcion,
    precio_unitario: Number(product.precio ?? 0),
    tarifa: Number(product.tarifa_iva ?? 15),
    unidad: newItems[index].unidad ?? 'UNI',
    producto_id: product.id,
  }
  emit('update:items', newItems)
}
</script>

<template>
  <div class="kvs-doc-grid">
    <!-- Cabecera de columnas -->
    <div class="kvs-doc-grid-head">
      <div class="kvs-dg-col kvs-dg-col--idx">#</div>
      <div class="kvs-dg-col kvs-dg-col--codigo">Código</div>
      <div class="kvs-dg-col kvs-dg-col--articulo">Artículo</div>
      <div v-if="showWarehouse" class="kvs-dg-col kvs-dg-col--bodega">Bodega</div>
      <div class="kvs-dg-col kvs-dg-col--unidad">Unidad</div>
      <div class="kvs-dg-col kvs-dg-col--iva">%IVA</div>
      <div class="kvs-dg-col kvs-dg-col--cant">Cant.</div>
      <div class="kvs-dg-col kvs-dg-col--precio">Costo/Precio s/IVA</div>
      <div class="kvs-dg-col kvs-dg-col--precioc">con IVA</div>
      <div v-if="showDiscount" class="kvs-dg-col kvs-dg-col--dcto">%Dcto</div>
      <div v-if="showDiscount" class="kvs-dg-col kvs-dg-col--dctoval">$Dcto</div>
      <div class="kvs-dg-col kvs-dg-col--subtotal">Subtotal</div>
      <div v-if="showSeries" class="kvs-dg-col kvs-dg-col--serie">Serie</div>
      <div class="kvs-dg-col kvs-dg-col--actions"></div>
    </div>

    <!-- Filas -->
    <div class="kvs-doc-grid-body">
      <div v-for="(item, i) in items" :key="i" class="kvs-doc-grid-row">
        <div class="kvs-dg-cell kvs-dg-col--idx">{{ i + 1 }}</div>
        <div class="kvs-dg-cell kvs-dg-col--codigo">
          <InputText
            :model-value="item.codigo_principal ?? ''"
            :disabled="readonly"
            size="small"
            @update:model-value="(v: string | undefined) => updateItem(i, 'codigo_principal', v ?? '')"
          />
        </div>
        <div class="kvs-dg-cell kvs-dg-col--articulo">
          <Select
            :model-value="item.producto_id"
            :options="productOptions"
            optionValue="id"
            optionLabel="descripcion"
            placeholder="Buscar..."
            filter
            size="small"
            :disabled="readonly"
            class="kvs-dg-art-select"
            @update:model-value="(v: any) => {
              const p = productOptions.find((x: any) => x.id === v)
              onProductSelect(i, p)
            }"
          />
        </div>
        <div v-if="showWarehouse" class="kvs-dg-cell kvs-dg-col--bodega">
          <Select
            :model-value="item.warehouse_id"
            :options="warehouseOptions"
            optionValue="id"
            optionLabel="nombre"
            placeholder="Bod."
            size="small"
            :disabled="readonly"
            @update:model-value="(v: any) => updateItem(i, 'warehouse_id', v)"
          />
        </div>
        <div class="kvs-dg-cell kvs-dg-col--unidad">
          <Select
            :model-value="item.unidad ?? 'UNI'"
            :options="unitOptions"
            optionLabel="label"
            optionValue="value"
            size="small"
            :disabled="readonly"
            @update:model-value="(v: string | undefined) => updateItem(i, 'unidad', v ?? '')"
          />
        </div>
        <div class="kvs-dg-cell kvs-dg-col--iva">
          <Select
            :model-value="Number(item.tarifa ?? 15)"
            :options="ivaOptions"
            optionLabel="label"
            optionValue="value"
            size="small"
            :disabled="readonly"
            @update:model-value="(v: number) => updateItem(i, 'tarifa', v)"
          />
        </div>
        <div class="kvs-dg-cell kvs-dg-col--cant">
          <InputNumber
            :model-value="Number(item.cantidad ?? 1)"
            :min="0"
            :minFractionDigits="2"
            :maxFractionDigits="4"
            :useGrouping="false"
            size="small"
            :disabled="readonly"
            @update:model-value="(v: number) => updateItem(i, 'cantidad', v)"
          />
        </div>
        <div class="kvs-dg-cell kvs-dg-col--precio">
          <InputNumber
            :model-value="Number(item.precio_unitario ?? 0)"
            mode="currency"
            currency="USD"
            size="small"
            :disabled="readonly"
            @update:model-value="(v: number) => updateItem(i, 'precio_unitario', v)"
          />
        </div>
        <div class="kvs-dg-cell kvs-dg-col--precioc">
          {{ money(
            Number(item.cantidad ?? 0) *
            Number(item.precio_unitario ?? 0) *
            (1 + Number(item.tarifa ?? 15) / 100)
          ) }}
        </div>
        <div v-if="showDiscount" class="kvs-dg-cell kvs-dg-col--dcto">
          <InputNumber
            :model-value="Number(item.pct_descuento ?? 0)"
            :min="0"
            :max="100"
            :useGrouping="false"
            size="small"
            :disabled="readonly"
            @update:model-value="(v: number) => updateItem(i, 'pct_descuento', v)"
          />
        </div>
        <div v-if="showDiscount" class="kvs-dg-cell kvs-dg-col--dctoval">
          {{ money(
            Number(item.cantidad ?? 0) *
            Number(item.precio_unitario ?? 0) *
            Number(item.pct_descuento ?? 0) / 100
          ) }}
        </div>
        <div class="kvs-dg-cell kvs-dg-col--subtotal">
          {{ money(
            Number(item.cantidad ?? 0) * Number(item.precio_unitario ?? 0) -
            Number(item.cantidad ?? 0) * Number(item.precio_unitario ?? 0) * Number(item.pct_descuento ?? 0) / 100
          ) }}
        </div>
        <div v-if="showSeries" class="kvs-dg-cell kvs-dg-col--serie">
          <Button
            v-if="item.series?.length"
            icon="pi pi-tag"
            size="small"
            text
            :title="'Series: ' + (item.series ?? []).join(', ')"
          />
          <Button
            v-else
            icon="pi pi-plus"
            size="small"
            text
            severity="secondary"
            title="Agregar serie"
            :disabled="readonly"
            @click="emit('scan-serie', i)"
          />
        </div>
        <div class="kvs-dg-cell kvs-dg-col--actions">
          <Button
            icon="pi pi-times"
            size="small"
            text
            severity="danger"
            :disabled="readonly"
            @click="emit('remove-item', i)"
          />
        </div>
      </div>

      <!-- Fila vacía -->
      <div v-if="!items.length" class="kvs-doc-grid-empty">
        Agregá ítems con el botón <b>+</b> o escaneá un código de barras.
      </div>
    </div>

    <!-- Footer de totales -->
    <div class="kvs-doc-grid-totals">
      <div class="kvs-dg-tot-row">
        <span class="kvs-dg-tot-label">Subtotal:</span>
        <span class="kvs-dg-tot-val">{{ money(totals.subtotal) }}</span>
      </div>
      <div class="kvs-dg-tot-row">
        <span class="kvs-dg-tot-label">S. Gravado 15%:</span>
        <span class="kvs-dg-tot-val">{{ money(totals.gravado15) }}</span>
      </div>
      <div v-if="Number(totals.gravado5)" class="kvs-dg-tot-row">
        <span class="kvs-dg-tot-label">S. Gravado 5%:</span>
        <span class="kvs-dg-tot-val">{{ money(totals.gravado5) }}</span>
      </div>
      <div v-if="Number(totals.base0)" class="kvs-dg-tot-row">
        <span class="kvs-dg-tot-label">S. IVA 0%:</span>
        <span class="kvs-dg-tot-val">{{ money(totals.base0) }}</span>
      </div>
      <div v-if="Number(totals.descuento)" class="kvs-dg-tot-row">
        <span class="kvs-dg-tot-label">Descuento:</span>
        <span class="kvs-dg-tot-val kvs-dg-tot-val--red">-{{ money(totals.descuento) }}</span>
      </div>
      <div class="kvs-dg-tot-row">
        <span class="kvs-dg-tot-label">IVA:</span>
        <span class="kvs-dg-tot-val">{{ money(totals.totalIva) }}</span>
      </div>
      <div class="kvs-dg-tot-row kvs-dg-tot-row--total">
        <span class="kvs-dg-tot-label">TOTAL:</span>
        <span class="kvs-dg-tot-val">{{ money(totals.total) }}</span>
      </div>
    </div>

    <!-- Botón agregar ítem -->
    <div class="kvs-doc-grid-add">
      <Button
        icon="pi pi-plus"
        size="small"
        label="Agregar ítem"
        text
        :disabled="readonly"
        @click="emit('add-item')"
      />
    </div>
  </div>
</template>

<style scoped>
.kvs-doc-grid {
  border: 1px solid #cfd6df;
  border-radius: 6px;
  overflow: hidden;
  font-size: 12px;
}

/* ── Cabecera ── */
.kvs-doc-grid-head {
  display: flex;
  background: linear-gradient(#3d8b8b, #2a6b6b);
  color: #fff;
  font-weight: 600;
  font-size: 11px;
  padding: 0;
}
.kvs-dg-col {
  padding: 5px 6px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── Anchos de columnas ── */
.kvs-dg-col--idx { width: 30px; text-align: center; flex-shrink: 0; }
.kvs-dg-col--codigo { width: 90px; flex-shrink: 0; }
.kvs-dg-col--articulo { flex: 2; min-width: 160px; }
.kvs-dg-col--bodega { width: 100px; flex-shrink: 0; }
.kvs-dg-col--unidad { width: 70px; flex-shrink: 0; }
.kvs-dg-col--iva { width: 65px; flex-shrink: 0; text-align: center; }
.kvs-dg-col--cant { width: 70px; flex-shrink: 0; text-align: right; }
.kvs-dg-col--precio { width: 110px; flex-shrink: 0; text-align: right; }
.kvs-dg-col--precioc { width: 90px; flex-shrink: 0; text-align: right; }
.kvs-dg-col--dcto { width: 55px; flex-shrink: 0; text-align: center; }
.kvs-dg-col--dctoval { width: 70px; flex-shrink: 0; text-align: right; }
.kvs-dg-col--subtotal { width: 90px; flex-shrink: 0; text-align: right; }
.kvs-dg-col--serie { width: 40px; flex-shrink: 0; text-align: center; }
.kvs-dg-col--actions { width: 30px; flex-shrink: 0; text-align: center; }

/* ── Filas ── */
.kvs-doc-grid-body {
  max-height: 320px;
  overflow-y: auto;
}
.kvs-doc-grid-row {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #eef1f5;
  padding: 0;
}
.kvs-doc-grid-row:nth-child(even) { background: #f8fafc; }
.kvs-doc-grid-row:hover { background: #eef6f6; }

.kvs-dg-cell {
  padding: 4px 6px;
  display: flex;
  align-items: center;
}
.kvs-dg-cell :deep(.p-inputtext),
.kvs-dg-cell :deep(.p-select) {
  width: 100%;
  font-size: 12px;
  padding: 3px 6px;
}
.kvs-dg-cell :deep(.p-inputnumber-input) {
  width: 100%;
  font-size: 12px;
  padding: 3px 6px;
}

/* ── Select de artículo (más ancho) ── */
.kvs-dg-art-select { width: 100%; }

/* ── Empty ── */
.kvs-doc-grid-empty {
  padding: 24px;
  text-align: center;
  color: #94a3b8;
  font-size: 12px;
}

/* ── Totales ── */
.kvs-doc-grid-totals {
  border-top: 2px solid #cfd6df;
  background: #f2f4f7;
  padding: 8px 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 4px 24px;
  justify-content: flex-end;
}
.kvs-dg-tot-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}
.kvs-dg-tot-label {
  color: #546e7a;
  font-weight: 500;
}
.kvs-dg-tot-val {
  font-weight: 600;
  min-width: 80px;
  text-align: right;
}
.kvs-dg-tot-val--red { color: #d93025; }
.kvs-dg-tot-row--total {
  border-top: 1px solid #b9c2cc;
  padding-top: 4px;
  font-size: 13px;
}
.kvs-dg-tot-row--total .kvs-dg-tot-val {
  font-size: 14px;
  font-weight: 800;
  color: #1f2733;
}

/* ── Agregar ítem ── */
.kvs-doc-grid-add {
  padding: 4px 8px;
  background: #f7f9fb;
  border-top: 1px solid #eef1f5;
}
</style>
