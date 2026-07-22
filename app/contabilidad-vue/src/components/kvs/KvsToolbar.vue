<script setup lang="ts">
/**
 * KvsToolbar — Barra de acciones estilo KVS.
 *
 * Props:
 *   buttons — [{ icon, label, action, severity?, outlined?, disabled?, loading? }]
 *   align   — 'start' | 'center' | 'end' (default: 'end')
 */
import Button from 'primevue/button'

interface ToolbarButton {
  icon?: string
  label?: string
  action: string
  severity?: string
  outlined?: boolean
  disabled?: boolean
  loading?: boolean
  visible?: boolean
  title?: string
}

defineProps<{
  buttons?: ToolbarButton[]
  align?: 'start' | 'center' | 'end'
}>()

const emit = defineEmits<{
  (e: 'action', name: string): void
}>()
</script>

<template>
  <div
    class="kvs-toolbar"
    :class="{
      'kvs-toolbar--start': align === 'start',
      'kvs-toolbar--center': align === 'center',
      'kvs-toolbar--end': align === 'end' || !align,
    }"
  >
    <slot />
    <template v-for="btn in (buttons ?? [])" :key="btn.action">
      <Button
        :icon="btn.icon"
        :label="btn.label"
        :severity="(btn.severity as any) ?? undefined"
        :outlined="btn.outlined"
        :disabled="btn.disabled"
        :loading="btn.loading"
        :title="btn.title"
        size="small"
        @click="emit('action', btn.action)"
      />
    </template>
  </div>
</template>

/* KVS classes are global in style.css — no scoped styles needed */
