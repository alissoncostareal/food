<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import FeatureAccessLoading from '@/components/auth/FeatureAccessLoading.vue'
import { useFeatureAccess } from '@/composables/useFeatureAccess'
import {
  getInsightIcon,
  getInsightIconStyle,
  getInsightPriorityStyle,
  getInsightTypeLabel,
  getInsightTypeStyle,
  getRevenueTrendLabel,
  getRevenueTrendStyle,
  insightFilterOptions
} from '@/composables/useInsightPresentation'
import {
  AlertTriangle,
  BarChart3,
  CheckCircle,
  ChevronRight,
  Clock,
  Lightbulb,
  Loader2,
  Lock,
  RefreshCw,
  Sparkles,
  Target,
  TrendingUp,
  XCircle,
  Zap
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const refreshing = ref(false)
const insights = ref([])
const insightsMeta = ref(null)
const summary = ref(null)
const activeFilter = ref('all')
const toast = ref({ show: false, message: '', type: 'success' })

const { isLoading, isLocked, isUnlocked, refresh } = useFeatureAccess('intelligence')

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }

  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(Number(value || 0))
}

const insightsSourceLabel = computed(() => {
  if (!insightsMeta.value?.source) return null

  return insightsMeta.value.source === 'gemini' || insightsMeta.value.source === 'openai'
    ? 'Análise com IA'
    : 'Análise automática'
})

const formattedInsightsTime = computed(() => {
  if (!insightsMeta.value?.generated_at) return null

  return new Date(insightsMeta.value.generated_at).toLocaleString('pt-BR', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit'
  })
})

const filteredInsights = computed(() => {
  if (activeFilter.value === 'all') {
    return insights.value
  }

  return insights.value.filter((insight) => insight.type === activeFilter.value)
})

const summaryCards = computed(() => {
  if (!summary.value) return []

  const stats = summary.value.stats || {}

  return [
    {
      label: 'Faturamento do mês',
      value: formatCurrency(stats.monthly_revenue),
      desc: `${stats.monthly_orders_count || 0} pedidos`,
      icon: TrendingUp,
      color: 'text-red-600',
      bg: 'bg-red-50'
    },
    {
      label: 'Ticket médio',
      value: formatCurrency(stats.average_ticket),
      desc: 'Média por pedido',
      icon: Target,
      color: 'text-blue-600',
      bg: 'bg-blue-50'
    },
    {
      label: 'Melhor dia',
      value: summary.value.peak_weekday?.label || '—',
      desc: summary.value.peak_weekday
        ? `${summary.value.peak_weekday.orders_count} pedidos`
        : 'Sem dados ainda',
      icon: BarChart3,
      color: 'text-emerald-600',
      bg: 'bg-emerald-50'
    },
    {
      label: 'Horário de pico',
      value: summary.value.peak_hour?.label || '—',
      desc: summary.value.peak_hour
        ? formatCurrency(summary.value.peak_hour.revenue)
        : 'Sem dados ainda',
      icon: Clock,
      color: 'text-amber-600',
      bg: 'bg-amber-50'
    }
  ]
})

const fetchIntelligence = async ({ refresh = false, silent = false } = {}) => {
  if (!isUnlocked.value) return

  if (refresh) {
    refreshing.value = true
  } else if (!silent) {
    loading.value = true
  }

  try {
    const { data } = await api.get('/merchant/intelligence', {
      params: refresh ? { refresh: 1 } : {}
    })

    insights.value = [...(data.insights || [])]
    insightsMeta.value = data.meta || null
    summary.value = data.summary || null

    if (refresh) {
      showNotify('Novas dicas geradas com sucesso.')
    }
  } catch (error) {
    const message = error.response?.data?.message || 'Não foi possível carregar a inteligência da loja.'

    if (error.response?.status === 403) {
      router.push('/billing?upgrade=intelligence')
      return
    }

    showNotify(message, 'error')
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

const refreshInsights = async () => {
  if (refreshing.value) return
  await fetchIntelligence({ refresh: true, silent: true })
}

watch(isUnlocked, (unlocked) => {
  if (unlocked) {
    fetchIntelligence()
  }
}, { immediate: true })

onMounted(async () => {
  await refresh({ force: true })
})
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <FeatureAccessLoading v-if="isLoading" message="Verificando acesso à Inteligência..." />

    <div v-else-if="isLocked" class="max-w-2xl mx-auto py-16">
      <div class="rounded-3xl border border-red-100 bg-gradient-to-br from-red-50 via-white to-white p-10 text-center shadow-sm">
        <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-3xl bg-red-500 text-white shadow-lg shadow-red-100">
          <Lock size="28" />
        </div>
        <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-red-600">
          Recurso Premium
        </span>
        <h1 class="mt-4 text-2xl font-black text-slate-900">Inteligência com IA</h1>
        <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-500">
          Dicas personalizadas para vender mais — horários de pico, cardápio, operação e crescimento —
          geradas com base nos dados reais da sua loja.
        </p>

        <ul class="mt-6 space-y-2 text-left max-w-md mx-auto">
          <li class="flex items-start gap-2 text-sm font-bold text-slate-600">
            <Sparkles size="16" class="text-red-500 mt-0.5 shrink-0" />
            Análise com OpenAI dos seus pedidos e vendas
          </li>
          <li class="flex items-start gap-2 text-sm font-bold text-slate-600">
            <Sparkles size="16" class="text-red-500 mt-0.5 shrink-0" />
            Sugestões práticas por tema: vendas, horários, cardápio
          </li>
          <li class="flex items-start gap-2 text-sm font-bold text-slate-600">
            <Sparkles size="16" class="text-red-500 mt-0.5 shrink-0" />
            Botão para gerar novas dicas quando quiser
          </li>
        </ul>

        <button
          @click="router.push('/billing?upgrade=intelligence')"
          class="mt-8 inline-flex items-center gap-2 rounded-2xl bg-red-500 px-6 py-3 text-sm font-black text-white hover:bg-red-600 transition shadow-lg shadow-red-100"
        >
          Fazer upgrade para Premium
          <ChevronRight size="16" />
        </button>
      </div>
    </div>

    <div v-else class="pm-page">
      <section class="pm-page-header">
        <div class="flex items-start gap-4">
          <div class="pm-page-icon">
            <Lightbulb size="24" />
          </div>
          <div>
            <p class="pm-page-eyebrow">Inteligência</p>
            <h1 class="pm-page-title">Como vender mais</h1>
            <p class="pm-page-subtitle">
              Dicas práticas com base nos seus pedidos, horários e cardápio
            </p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <div
            v-if="insightsSourceLabel"
            class="inline-flex items-center gap-2 rounded-full border border-red-100 bg-red-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-red-600"
          >
            <Sparkles v-if="insightsMeta?.source === 'gemini' || insightsMeta?.source === 'openai'" size="12" />
            <Zap v-else size="12" class="text-amber-500" />
            {{ insightsSourceLabel }}
          </div>

          <button
            @click="refreshInsights"
            :disabled="refreshing || loading"
            class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-black text-slate-700 hover:border-red-200 hover:text-red-600 transition disabled:opacity-60"
          >
            <RefreshCw size="16" :class="{ 'animate-spin': refreshing }" />
            {{ refreshing ? 'Gerando...' : 'Gerar novas dicas' }}
          </button>
        </div>
      </section>

      <div v-if="loading" class="flex flex-col items-center justify-center py-20 text-red-500">
        <Loader2 class="animate-spin mb-4" size="48" />
        <p class="font-black animate-pulse">Analisando sua operação...</p>
      </div>

      <template v-else>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
          <div
            v-for="card in summaryCards"
            :key="card.label"
            class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm"
          >
            <div :class="card.bg" class="inline-flex p-2.5 rounded-xl mb-3">
              <component :is="card.icon" :class="card.color" size="18" />
            </div>
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ card.label }}</p>
            <p class="text-xl font-black text-slate-900 mt-1">{{ card.value }}</p>
            <p class="text-xs font-bold text-slate-400 mt-1">{{ card.desc }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div
            v-if="summary?.revenue_trend"
            class="rounded-3xl border p-5"
            :class="getRevenueTrendStyle(summary.revenue_trend)"
          >
            <p class="text-[10px] font-black uppercase tracking-widest opacity-80">Tendência semanal</p>
            <p class="mt-2 text-lg font-black">{{ getRevenueTrendLabel(summary.revenue_trend) }}</p>
            <p class="mt-1 text-xs font-bold opacity-80">
              {{ formatCurrency(summary.revenue_last_7_days) }} nos últimos 7 dias
            </p>
          </div>

          <div
            class="rounded-3xl border p-5"
            :class="(summary?.delayed_orders || 0) > 0 ? 'bg-amber-50 border-amber-100' : 'bg-emerald-50 border-emerald-100'"
          >
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Operação</p>
            <p class="mt-2 text-lg font-black text-slate-900">
              {{ (summary?.delayed_orders || 0) > 0 ? `${summary.delayed_orders} possível(is) atraso(s)` : 'Tudo em dia' }}
            </p>
            <p class="mt-1 text-xs font-bold text-slate-500">
              Limite de {{ summary?.delay_threshold_minutes || 45 }} min
            </p>
          </div>

          <div class="rounded-3xl border border-slate-200 bg-white p-5">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Cancelamentos (30 dias)</p>
            <p class="mt-2 text-lg font-black text-slate-900">{{ summary?.canceled_orders_30d || 0 }}</p>
            <p class="mt-1 text-xs font-bold text-slate-500">Pedidos cancelados no período</p>
          </div>
        </div>

        <section class="rounded-3xl border border-red-100 bg-gradient-to-br from-red-50/80 via-white to-white p-6 shadow-sm">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
            <div>
              <h2 class="text-xl font-black text-slate-900">Suas dicas</h2>
              <p class="text-sm font-bold text-slate-500 mt-1">
                Filtre por tema e aplique na operação do dia a dia
              </p>
            </div>

            <p v-if="formattedInsightsTime" class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
              Atualizado em {{ formattedInsightsTime }}
            </p>
          </div>

          <div class="flex flex-wrap gap-2 mb-5">
            <button
              v-for="filter in insightFilterOptions"
              :key="filter.id"
              @click="activeFilter = filter.id"
              :class="[
                'rounded-xl px-3 py-1.5 text-xs font-black transition',
                activeFilter === filter.id
                  ? 'bg-red-500 text-white shadow-lg shadow-red-100'
                  : 'bg-white border border-slate-200 text-slate-600 hover:border-red-200 hover:text-red-600'
              ]"
            >
              {{ filter.label }}
            </button>
          </div>

          <div v-if="filteredInsights.length === 0" class="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-red-100 text-red-600">
              <AlertTriangle size="20" />
            </div>
            <p class="text-sm font-black text-slate-700">Nenhuma dica neste filtro</p>
            <p class="mt-1 text-xs font-semibold text-slate-400">
              Tente outro tema ou gere novas dicas com o botão acima.
            </p>
          </div>

          <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <article
              v-for="(insight, index) in filteredInsights"
              :key="`${insight.title}-${index}`"
              class="rounded-2xl border p-4 shadow-sm transition hover:shadow-md"
              :class="getInsightPriorityStyle(insight.priority)"
            >
              <div class="flex items-start gap-3">
                <div class="rounded-xl p-2.5 shrink-0" :class="getInsightIconStyle(insight.type)">
                  <component :is="getInsightIcon(insight.type)" size="16" />
                </div>

                <div class="min-w-0 flex-1">
                  <p class="text-sm font-black text-slate-900 leading-snug">
                    {{ insight.title }}
                  </p>

                  <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                    {{ insight.description }}
                  </p>

                  <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span
                      v-if="insight.type"
                      class="inline-flex rounded-lg px-2 py-1 text-[10px] font-black uppercase tracking-wider"
                      :class="getInsightTypeStyle(insight.type)"
                    >
                      {{ getInsightTypeLabel(insight.type) }}
                    </span>

                    <span
                      v-if="insight.action"
                      class="inline-flex items-center gap-1 text-[10px] font-bold text-red-600"
                    >
                      {{ insight.action }}
                      <ChevronRight size="12" />
                    </span>
                  </div>
                </div>
              </div>
            </article>
          </div>
        </section>

        <div v-if="summary?.top_products?.length" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
          <div class="flex items-center justify-between mb-5">
            <div>
              <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Cardápio</p>
              <h2 class="text-lg font-black text-slate-900 mt-1">Produtos que mais vendem</h2>
            </div>
            <button
              @click="router.push('/products')"
              class="text-red-600 text-sm font-black flex items-center gap-1 hover:gap-2 transition-all"
            >
              Ver cardápio
              <ChevronRight size="16" />
            </button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            <div
              v-for="(product, index) in summary.top_products"
              :key="product.name"
              class="flex items-center gap-3 rounded-2xl bg-slate-50 p-4"
            >
              <div
                :class="index === 0 ? 'bg-amber-100 text-amber-600' : 'bg-white text-slate-500 border border-slate-200'"
                class="w-8 h-8 rounded-full flex items-center justify-center font-black text-xs shrink-0"
              >
                #{{ index + 1 }}
              </div>
              <div class="min-w-0">
                <p class="font-black text-slate-800 truncate">{{ product.name }}</p>
                <p class="text-xs font-bold text-slate-400">{{ product.total_qty }} unidades vendidas</p>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
</template>
