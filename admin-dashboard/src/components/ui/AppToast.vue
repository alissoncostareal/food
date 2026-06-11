<script setup>
import { CheckCircle, XCircle } from 'lucide-vue-next'

defineProps({
  show: { type: Boolean, default: false },
  message: { type: String, default: '' },
  type: { type: String, default: 'success' }
})
</script>

<template>
  <transition name="toast">
    <div
      v-if="show"
      class="fixed bottom-10 right-10 z-[100] flex max-w-sm items-center rounded-[2rem] border border-white/10 bg-gray-900 p-6 text-white shadow-2xl"
    >
      <div
        :class="[
          'mr-4 flex h-10 w-10 shrink-0 items-center justify-center rounded-full shadow-inner',
          type === 'success' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'
        ]"
      >
        <CheckCircle v-if="type === 'success'" size="24" />
        <XCircle v-else size="24" />
      </div>

      <span class="text-sm font-black tracking-tight">{{ message }}</span>
    </div>
  </transition>
</template>
