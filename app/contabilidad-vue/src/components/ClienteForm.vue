<script setup lang="ts">
import { computed, ref } from 'vue'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import api from '../lib/api'

const props = defineProps<{
  modelValue: any
  readonly?: boolean
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: any]
  'sri-lookup': [value: { found: boolean; data?: any }]
}>()

const tipos = [
  { label: 'Cédula', value: '05' },
  { label: 'RUC', value: '04' },
  { label: 'Pasaporte', value: '06' },
  { label: 'Consumidor final', value: '07' },
]

const sriLoading = ref(false)
const sriHint = ref('')

const local = computed({
  get: () => props.modelValue ?? {},
  set: (v) => emit('update:modelValue', v),
})

const sriHintColor = computed(() => {
  if (!sriHint.value) return '#16a34a'
  if (sriHint.value.includes('Auto-completado')) return '#16a34a'
  if (sriHint.value.includes('No está') || sriHint.value.includes('Cargá')) return '#d97706'
  if (sriHint.value.includes('No se pudo')) return '#d93025'
  return '#d97706'
})

async function onSriLookup() {
  const id = local.value.identificacion
  if (!id || id.length < 10) return
  sriLoading.value = true
  sriHint.value = ''
  try {
    const res = await api.get('/sri/consulta?identificacion=' + id)
    if (res.data.encontrado) {
      local.value = {
        ...local.value,
        razon_social: res.data.razon_social ?? local.value.razon_social,
        nombre_comercial: res.data.nombre_comercial ?? local.value.nombre_comercial,
        tipo_identificacion: res.data.tipo_identificacion ?? local.value.tipo_identificacion,
      }
      sriHint.value = 'Auto-completado del SRI'
      emit('sri-lookup', { found: true, data: res.data })
    } else if (res.data.requiere_carga_manual) {
      sriHint.value = res.data.mensaje || 'No está en el padrón SRI. Cargá los datos a mano.'
      emit('sri-lookup', { found: false })
    }
  } catch {
    sriHint.value = 'No se pudo consultar el SRI. Cargá a mano.'
    emit('sri-lookup', { found: false })
  } finally {
    sriLoading.value = false
  }
}
</script>

<template>
  <div>
    <!-- Row 1: Identification type + Identification number -->
    <div class="kvs-row">
      <label class="kvs-lbl"><span class="req">*</span> Tipo ID:</label>
      <Select v-model="local.tipo_identificacion" :options="tipos" optionLabel="label" optionValue="value"
              :disabled="readonly" class="kvs-in" style="max-width:160px" />
      <label class="kvs-lbl" style="margin-left:12px;"><span class="req">*</span> Identificación:</label>
      <div class="kvs-in" style="display:flex; align-items:center; gap:6px;">
        <InputText v-model="local.identificacion" :disabled="readonly" @blur="onSriLookup" @keydown.enter.prevent="onSriLookup" style="flex:1" />
        <i v-if="sriLoading" class="pi pi-spin pi-spinner" style="color:#3d8b8b;" />
      </div>
    </div>

    <!-- SRI hint -->
    <div v-if="sriHint" class="kvs-row">
      <label class="kvs-lbl"></label>
      <small :style="{ color: sriHintColor }">{{ sriHint }}</small>
    </div>

    <!-- Row 2: Business name -->
    <div class="kvs-row">
      <label class="kvs-lbl"><span class="req">*</span> Razón social:</label>
      <InputText v-model="local.razon_social" :disabled="readonly" class="kvs-in" />
    </div>

    <!-- Row 3: Commercial name -->
    <div class="kvs-row">
      <label class="kvs-lbl">Nombre comercial:</label>
      <InputText v-model="local.nombre_comercial" :disabled="readonly" class="kvs-in" />
    </div>

    <!-- Row 4: Phone + Email (side by side) -->
    <div class="kvs-row">
      <label class="kvs-lbl">Teléfono:</label>
      <InputText v-model="local.telefono" :disabled="readonly" class="kvs-in" />
      <label class="kvs-lbl" style="margin-left:12px;">Email:</label>
      <InputText v-model="local.email" :disabled="readonly" class="kvs-in" />
    </div>

    <!-- Row 5: Email2 (backup) -->
    <div class="kvs-row">
      <label class="kvs-lbl">Correo (respaldo):</label>
      <InputText v-model="local.email2" :disabled="readonly" class="kvs-in" />
    </div>

    <!-- Row 6: Address -->
    <div class="kvs-row">
      <label class="kvs-lbl">Dirección:</label>
      <InputText v-model="local.direccion" :disabled="readonly" class="kvs-in" />
    </div>

    <!-- Row 7: Contact type checkboxes -->
    <div class="kvs-row">
      <label class="kvs-lbl">Tipo contacto:</label>
      <div style="display:flex; gap:16px;">
        <label style="display:flex; align-items:center; gap:6px; font-size:13px;">
          <Checkbox v-model="local.es_cliente" :binary="true" :disabled="readonly" /> Cliente
        </label>
        <label style="display:flex; align-items:center; gap:6px; font-size:13px;">
          <Checkbox v-model="local.es_proveedor" :binary="true" :disabled="readonly" /> Proveedor
        </label>
      </div>
    </div>
  </div>
</template>
