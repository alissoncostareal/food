<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
  BarChart3,
  Clock,
  Loader2,
  Monitor,
  Sparkles,
  TrendingUp
} from 'lucide-vue-next'

const props = defineProps({
  loading: { type: Boolean, default: false },
  dashboardCards: { type: Array, default: () => [] },
  chartData: { type: Object, default: null },
  chartOptions: { type: Object, required: true },
  peakWeekday: { type: Object, default: null },
  peakHour: { type: Object, default: null },
  hasPremiumDashboard: { type: Boolean, default: false },
  formatCurrency: { type: Function, required: true }
})

const heroCard = computed(() => props.dashboardCards[0] || null)
const secondaryCards = computed(() => props.dashboardCards.slice(1))
</script>

<template>
  <div v-if="loading" class="flex flex-col items-center justify-center py-24 text-slate-400">
    <Loader2 class="mb-4 animate-spin" size="40" />
    <p class="text-sm font-black animate-pulse">Carregando resumo...</p>
  </div>

  <div v-else class="space-y-4 pb-6">
    <section
      v-if="heroCard"
      :class="['rounded-[1.75rem] p-6 shadow-sm', heroCard.theme.card]"
    >
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-500">
            {{ heroCard.label }}
          </p>
          <p :class="['mt-2 text-4xl font-black tracking-tight', heroCard.theme.value]">
            {{ heroCard.val }}
          </p>
          <p :class="['mt-2 text-sm font-bold', heroCard.theme.desc]">
            {{ heroCard.desc }}
          </p>
        </div>

        <div :class="['flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl shadow-sm', heroCard.theme.icon]">
          <component :is="heroCard.icon" size="22" />
        </div>
      </div>
    </section>

    <section v-if="secondaryCards.length" class="grid grid-cols-2 gap-3">
      <article
        v-for="card in secondaryCards"
        :key="card.label"
        :class="['rounded-[1.35rem] p-4 shadow-sm', card.theme.card]"
      >
        <div :class="['mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl shadow-sm', card.theme.icon]">
          <component :is="card.icon" size="18" />
        </div>
        <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">
          {{ card.label }}
        </p>
        <p :class="['mt-1 text-xl font-black tracking-tight', card.theme.value]">
          {{ card.val }}
        </p>
        <p :class="['mt-1 text-[11px] font-bold leading-snug', card.theme.desc]">
          {{ card.desc }}
        </p>
      </article>
    </section>

    <section class="overflow-hidden rounded-[1.75rem] border border-slate-200/80 bg-white p-5 shadow-sm">
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Performance</p>
          <h2 class="mt-1 text-lg font-black text-slate-900">Últimos 7 dias</h2>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-slate-500">
          Semanal
        </span>
      </div>

      <div class="h-52">
        <Line v-if="chartData" :data="chartData" :options="chartOptions" />
        <div
          v-else
          class="flex h-full items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50"
        >
          <p class="text-sm font-bold text-slate-400">Sem dados de vendas ainda.</p>
        </div>
      </div>
    </section>

    <section
      v-if="hasPremiumDashboard && (peakWeekday || peakHour)"
      class="rounded-[1.75rem] border border-slate-200/80 bg-white p-5 shadow-sm"
    >
      <div class="mb-4 flex items-center gap-2">
        <Sparkles size="16" class="text-red-500" />
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Destaques</p>
      </div>

      <div class="space-y-3">
        <div
          v-if="peakWeekday"
          class="flex items-center gap-3 rounded-2xl border border-blue-100/60 bg-blue-50/40 p-4"
        >
          <div class="rounded-xl bg-blue-500 p-2.5 text-white shadow-sm shadow-blue-500/20">
            <BarChart3 size="18" />
          </div>
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Melhor dia</p>
            <p class="font-black text-slate-900">{{ peakWeekday.label }}</p>
            <p class="text-xs font-bold text-slate-500">{{ peakWeekday.orders_count }} pedidos</p>
          </div>
        </div>

        <div
          v-if="peakHour"
          class="flex items-center gap-3 rounded-2xl border border-sky-100/70 bg-sky-50/40 p-4"
        >
          <div class="rounded-xl bg-sky-100/80 p-2.5 text-sky-600">
            <Clock size="18" />
          </div>
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-wider text-sky-600/80">Horário de pico</p>
            <p class="font-black text-slate-900">{{ peakHour.label }}</p>
            <p class="text-xs font-bold text-slate-500">{{ formatCurrency(peakHour.revenue) }}</p>
          </div>
        </div>
      </div>
    </section>

    <section class="rounded-[1.75rem] border border-slate-200 bg-gradient-to-br from-slate-900 to-slate-800 p-5 text-white shadow-lg">
      <div class="flex items-start gap-3">
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white/10">
          <Monitor size="20" />
        </div>
        <div>
          <p class="text-sm font-black">Painel completo no computador</p>
          <p class="mt-1.5 text-xs font-bold leading-relaxed text-slate-300">
            Pedidos, cardápio, configurações e relatórios detalhados ficam disponíveis no navegador do PC ou tablet.
          </p>
        </div>
      </div>
    </section>
  </div>
</template>
