<script setup lang="ts">
/**
 * KvsModuleHeader — Cabecera de contexto: empresa + período + módulo.
 *
 * Props:
 *   moduleName  — nombre del módulo (ej: "Compras", "Ventas")
 *   company     — objeto empresa { ruc, razon_social }
 *   period      — período visible (ej: "Julio 2026")
 *   subtitle    — subtítulo opcional
 */
defineProps<{
  moduleName?: string
  company?: { ruc?: string; razon_social?: string } | null
  period?: string
  subtitle?: string
}>()
</script>

<template>
  <div class="kvs-module-header">
    <div class="kvs-mh-left">
      <span class="kvs-mh-module">{{ moduleName ?? 'Módulo' }}</span>
      <span v-if="subtitle" class="kvs-mh-sub"> — {{ subtitle }}</span>
    </div>
    <div class="kvs-mh-right">
      <span v-if="company" class="kvs-mh-company">
        {{ company.razon_social }}
        <span v-if="company.ruc" class="kvs-mh-ruc">RUC: {{ company.ruc }}</span>
      </span>
      <span v-if="period" class="kvs-mh-period">{{ period }}</span>
    </div>
  </div>
</template>

/* KVS classes are global in style.css — no scoped styles needed */
