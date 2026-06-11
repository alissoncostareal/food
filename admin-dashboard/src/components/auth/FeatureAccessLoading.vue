<script setup>
import { Loader2 } from 'lucide-vue-next'
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-4 py-20">
    <Loader2 class="h-10 w-10 animate-spin text-red-600" />
    <p class="text-sm font-bold text-slate-500">Carregando recurso...</p>
  </div>
</template>
