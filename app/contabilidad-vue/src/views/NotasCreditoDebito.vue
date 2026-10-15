<script setup lang="ts">
/**
 * Notas de crédito y débito en un solo módulo: dos pestañas que montan, sin cambios,
 * las pantallas de siempre (CreditNotes y NotaDebito). Cada una conserva su lista, su
 * formulario, sus atajos y sus anulaciones; aquí solo se agrupan.
 */
import { ref } from 'vue'
import CreditNotes from './CreditNotes.vue'
import NotaDebito from './NotaDebito.vue'

defineProps<{ titulo?: string }>()

const vistas = [
  { key: 'credito', label: 'Notas de crédito', componente: CreditNotes },
  { key: 'debito', label: 'Notas de débito', componente: NotaDebito },
]
const vista = ref('credito')
</script>

<template>
  <div class="ncd">
    <div class="kvs-tabs" role="tablist">
      <button v-for="v in vistas" :key="v.key" class="kvs-tab" :class="{ active: vista === v.key }"
              role="tab" :aria-selected="vista === v.key" @click="vista = v.key">{{ v.label }}</button>
    </div>
    <div class="ncd-body">
      <!-- KeepAlive: al cambiar de pestaña no se pierde el formulario a medias ni la lista cargada -->
      <KeepAlive>
        <component :is="vistas.find((v) => v.key === vista)!.componente" />
      </KeepAlive>
    </div>
  </div>
</template>

<style scoped>
.ncd { display: flex; flex-direction: column; height: 100%; }
.ncd-body { flex: 1; overflow: auto; }
</style>
