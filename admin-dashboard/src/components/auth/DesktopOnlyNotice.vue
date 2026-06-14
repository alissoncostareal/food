<script setup>
import { Laptop, TrendingUp } from 'lucide-vue-next'
import { useRouter } from 'vue-router'

defineProps({
  pageTitle: { type: String, default: '' }
})

const router = useRouter()

const goToSummary = () => {
  router.push('/dashboard')
}
</script>

<template>
  <div class="mx-auto flex max-w-md flex-col items-center px-2 py-10 text-center">
    <div class="relative">
      <div class="absolute inset-0 rounded-[2rem] bg-red-500/20 blur-2xl" />
      <div class="relative flex h-20 w-20 items-center justify-center rounded-[1.75rem] bg-gradient-to-br from-slate-900 to-slate-700 text-white shadow-xl">
        <Laptop size="34" />
      </div>
    </div>

    <p
      v-if="pageTitle"
      class="mt-6 rounded-full bg-slate-200/80 px-3 py-1 text-[10px] font-black uppercase tracking-widest text-slate-500"
    >
      {{ pageTitle }}
    </p>

    <h2 class="mt-4 text-2xl font-black tracking-tight text-slate-900">
      Melhor no computador
    </h2>

    <p class="mt-3 max-w-sm text-sm font-bold leading-relaxed text-slate-500">
      No celular você acompanha só o resumo de números. Para gerenciar pedidos, cardápio e configurações,
      abra o painel no navegador do PC ou tablet.
    </p>

    <button
      type="button"
      class="mt-8 inline-flex w-full max-w-xs items-center justify-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white shadow-lg shadow-red-500/25 transition hover:bg-red-700 active:scale-[0.98]"
      @click="goToSummary"
    >
      <TrendingUp size="18" />
      Ver resumo
    </button>
  </div>
</template>
