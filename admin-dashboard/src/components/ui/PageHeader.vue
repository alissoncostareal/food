<script setup>
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  eyebrow: { type: String, default: '' }
})
</script>

<template>
  <header class="pm-page-header">
    <div class="flex items-center gap-4 min-w-0">
      <div v-if="$slots.icon" class="pm-page-icon">
        <slot name="icon" />
      </div>

      <div class="min-w-0">
        <p v-if="eyebrow" class="pm-page-eyebrow">{{ eyebrow }}</p>
        <h1 class="pm-page-title">{{ title }}</h1>
        <p v-if="subtitle" class="pm-page-subtitle">{{ subtitle }}</p>
      </div>
    </div>

    <div v-if="$slots.actions" class="flex flex-col sm:flex-row gap-2 shrink-0">
      <slot name="actions" />
    </div>
  </header>
</template>
