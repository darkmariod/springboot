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

<style scoped>
.kvs-module-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: linear-gradient(#3d8b8b, #2a6b6b);
  color: #fff;
  padding: 7px 14px;
  border-radius: 6px 6px 0 0;
  font-size: 12.5px;
}
.kvs-mh-left {
  display: flex;
  align-items: center;
  gap: 6px;
}
.kvs-mh-module {
  font-weight: 700;
  font-size: 13px;
  letter-spacing: 0.3px;
}
.kvs-mh-sub {
  font-weight: 400;
  opacity: 0.85;
}
.kvs-mh-right {
  display: flex;
  align-items: center;
  gap: 16px;
}
.kvs-mh-company {
  font-weight: 500;
}
.kvs-mh-ruc {
  margin-left: 8px;
  opacity: 0.75;
  font-size: 11.5px;
}
.kvs-mh-period {
  background: rgba(255,255,255,0.15);
  padding: 2px 8px;
  border-radius: 3px;
  font-size: 11.5px;
  font-weight: 500;
}
</style>
