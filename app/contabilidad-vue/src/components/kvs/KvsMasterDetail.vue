<script setup lang="ts">
/**
 * KvsMasterDetail — Layout maestro-detalle estilo KVS/KBS.
 *
 * Props:
 *   title          — título del listado izquierdo
 *   detailTitle    — título del panel de detalle
 *   rows           — array de registros
 *   loading        — boolean de carga
 *   selected       — registro seleccionado (v-model)
 *   tabs           — [{ key, label }] pestañas del detalle
 *   activeTab      — v-model de la pestaña activa
 *   searchFields   — [{ key, label, placeholder, width? }]
 *   filtrados      — lista filtrada (se computa fuera, no adentro del componente)
 *   detailCount    — total de registros
 *   listCount      — registros filtrados
 *   emptyMessage   — mensaje cuando no hay selección
 *
 * Slots:
 *   #search-extra  — contenido extra en la barra de búsqueda
 *   #list-columns  — Column de PrimeVue para la grilla del listado
 *   #tab-content   — contenido del tab activo
 *   #footer        — botones del footer (override del default)
 */

import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'

const props = defineProps<{
  title?: string
  detailTitle?: string
  rows?: any[]
  loading?: boolean
  selected?: any
  tabs?: { key: string; label: string }[]
  activeTab?: string
  searchFields?: { key: string; label: string; placeholder: string; width?: string }[]
  filters?: Record<string, string>
  detailCount?: number
  listCount?: number
  emptyMessage?: string
  msg?: { type: string; text: string } | null
}>()

const emit = defineEmits<{
  (e: 'update:selected', v: any): void
  (e: 'update:activeTab', v: string): void
  (e: 'update:filters', v: Record<string, string>): void
  (e: 'select', row: any): void
  (e: 'new'): void
}>()


</script>

<template>
  <div class="kvs-split">
    <!-- ══ Listado izquierdo ══ -->
    <section class="kvs-panel kvs-panel--list">
      <div class="kvs-panel-title">{{ title ?? 'Listado' }}</div>
      <div class="kvs-search">
        <span class="kvs-search-label">Búsqueda</span>
        <template v-for="f in (searchFields ?? [])" :key="f.key">
          <InputText
            :model-value="filters?.[f.key] ?? ''"
            :placeholder="f.placeholder"
            size="small"
            :style="{ width: f.width ?? '90px' }"
            @update:model-value="(v: string | undefined) => {
              const nf = { ...(filters ?? {}), [f.key]: v ?? '' }
              emit('update:filters', nf)
            }"
          />
        </template>
        <slot name="search-extra" />
        <Button icon="pi pi-plus" size="small" text @click="emit('new')" title="Nuevo registro" />
      </div>
      <slot name="list-columns" />
      <div class="kvs-panel-foot">
        Mostrando {{ listCount ?? rows?.length ?? 0 }} de {{ detailCount ?? rows?.length ?? 0 }}
      </div>
    </section>

    <!-- ══ Detalle derecho ══ -->
    <section class="kvs-panel kvs-panel--detail">
      <div class="kvs-panel-title">{{ detailTitle ?? 'Detalle' }}</div>

      <div v-if="!selected && !emptyMessage === false" class="kvs-empty">
        {{ emptyMessage ?? 'Elegí un registro del listado, o tocá + para crear uno nuevo.' }}
      </div>

      <template v-else>
        <div v-if="tabs?.length" class="kvs-tabs">
          <button
            v-for="t in tabs"
            :key="t.key"
            class="kvs-tab"
            :class="{ active: activeTab === t.key }"
            @click="emit('update:activeTab', t.key)"
          >
            {{ t.label }}
          </button>
        </div>

        <div class="kvs-tabbody">
          <Message v-if="msg" :severity="msg.type" :closable="false" style="margin-bottom:10px">
            {{ msg.text }}
          </Message>
          <slot name="tab-content" />
        </div>
        <slot name="footer" />
      </template>
    </section>
  </div>
</template>

/* KVS classes are global in style.css — no scoped styles needed */
