<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import {
  getInsightIcon,
  getInsightIconStyle,
  getInsightPriorityStyle,
  getInsightTypeLabel,
  getInsightTypeStyle
} from '@/composables/useInsightPresentation'
import { useOnStoreSwitch } from '@/composables/useOnStoreSwitch'
import { useIsMobileViewport } from '@/composables/useIsMobileViewport'
import MobileDashboardSummary from '@/components/mobile/MobileDashboardSummary.vue'
import { withDashboardStatTheme } from '@/constants/dashboardStatThemes'
import {
  TrendingUp,
  DollarSign,
  Power,
  ChevronRight,
  ShoppingBag,
  Loader2,
  ArrowUpRight,
  BarChart3,
  Lightbulb,
  AlertTriangle,
  Clock,
  Lock,
  Target,
  Trophy,
  CheckCircle,
  XCircle,
  Sparkles,
  Zap
} from 'lucide-vue-next'

import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  LineElement,
  CategoryScale,
  LinearScale,
  PointElement,
  Filler
} from 'chart.js'

ChartJS.register(Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement, Filler)

const router = useRouter()
const { isMobileViewport } = useIsMobileViewport()
const stats = ref(null)
const chartData = ref(null)
const topProducts = ref([])
const salesByWeekday = ref([])
const salesByHour = ref([])
const insights = ref([])
const insightsMeta = ref(null)
const operations = ref(null)
const hasPremiumDashboard = ref(null)
const hasIntelligence = ref(null)
const isStoreOpen = ref(true)
const manualIsStoreOpen = ref(true)
const withinScheduledHours = ref(true)
const openOutsideHours = ref(false)
const togglingStoreStatus = ref(false)
const loading = ref(true)
const refreshingRealtime = ref(false)

const toast = ref({ show: false, message: '', type: 'success' })

const orderStatusLabels = {
  pending: 'Pedido recebido',
  preparing: 'Em preparo',
  ready: 'Pronto para entrega',
  shipped: 'Saiu para entrega',
  delivered: 'Pedido entregue',
  canceled: 'Pedido cancelado'
}

const getStatusLabel = (status) => {
  return orderStatusLabels[status] || 'Status desconhecido'
}

const getStatusStyle = (status) => {
  const styles = {
    pending: 'bg-amber-100 text-amber-700',
    preparing: 'bg-orange-100 text-orange-700',
    ready: 'bg-emerald-100 text-emerald-700',
    shipped: 'bg-blue-100 text-blue-700',
    delivered: 'bg-slate-100 text-slate-600',
    canceled: 'bg-red-100 text-red-700'
  }

  return styles[status] || 'bg-slate-100 text-slate-600'
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

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }

  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const formatCurrency = (value) => {
  const amount = Number(value) || 0

  return amount.toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  })
}

const formatTime = (dateString) => {
  if (!dateString) return '--:--'

  return new Date(dateString).toLocaleTimeString('pt-BR', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const formatChartData = (chart) => {
  return {
    labels: chart.map(item =>
      new Date(item.date).toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: 'short'
      })
    ),
    datasets: [
      {
        label: 'Vendas R$',
        data: chart.map(item => Math.round(Number(item.total))),
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.12)',
        fill: true,
        tension: 0.4,
        pointBackgroundColor: '#10b981'
      }
    ]
  }
}

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    y: {
      beginAtZero: true,
      grid: { display: false },
      ticks: {
        font: { weight: 'bold' },
        precision: 0,
        callback: function (value) {
          return 'R$ ' + value.toLocaleString('pt-BR')
        }
      }
    },
    x: {
      grid: { display: false },
      ticks: { font: { weight: 'bold' } }
    }
  },
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (context) => ` Vendas: ${formatCurrency(context.raw)}`
      }
    }
  }
}

const peakWeekday = computed(() => salesByWeekday.value[0] || null)
const peakHour = computed(() => salesByHour.value[0] || null)
const previewInsights = computed(() => insights.value.slice(0, 2))

const dashboardCards = computed(() => {
  const baseCards = [
    {
      label: 'Vendas Hoje',
      val: stats.value?.today ? formatCurrency(stats.value.today.revenue) : 'R$ 0,00',
      icon: DollarSign,
      themeKey: 'emerald',
      desc: `${stats.value?.today?.sales_count || 0} pedidos concluídos`
    },
    {
      label: 'Pedidos em aberto',
      val: stats.value?.active_orders ?? stats.value?.pending_now ?? 0,
      icon: ShoppingBag,
      themeKey: 'red',
      desc: 'Aguardando ação'
    },
    {
      label: 'Faturamento Mensal',
      val: stats.value?.monthly_revenue ? formatCurrency(stats.value.monthly_revenue) : 'R$ 0,00',
      icon: Target,
      themeKey: 'blue',
      desc: 'Acumulado do mês'
    }
  ]

  if (hasPremiumDashboard.value !== true) {
    return baseCards.map(withDashboardStatTheme)
  }

  return [
    ...baseCards,
    {
      label: 'Ticket médio',
      val: stats.value?.average_ticket ? formatCurrency(stats.value.average_ticket) : 'R$ 0,00',
      icon: BarChart3,
      themeKey: 'amber',
      desc: `${stats.value?.monthly_orders_count || 0} pedidos no mês`
    },
    {
      label: 'Possíveis atrasos',
      val: operations.value?.delayed_orders ?? 0,
      icon: AlertTriangle,
      themeKey: 'orange',
      desc: `Pedidos acima de ${operations.value?.delay_threshold_minutes || 45} min`
    }
  ].map(withDashboardStatTheme)
})

const fetchDashboardData = async (silent = false) => {
  if (!silent) loading.value = true

  try {
    const { data } = await api.get('/merchant/stats')

    stats.value = { ...data.stats }
    isStoreOpen.value = data.store?.is_open ?? false
    manualIsStoreOpen.value = data.store?.manual_is_open ?? data.store?.is_open ?? false
    withinScheduledHours.value = data.store?.within_scheduled_hours ?? true
    openOutsideHours.value = Boolean(data.store?.open_outside_hours)
    hasPremiumDashboard.value = Boolean(data.store?.has_premium_dashboard)
    hasIntelligence.value = Boolean(data.store?.has_intelligence)
    topProducts.value = [...(data.top_products || [])]
    salesByWeekday.value = [...(data.sales_by_weekday || [])]
    salesByHour.value = [...(data.sales_by_hour || [])]
    insights.value = [...(data.insights || [])]
    insightsMeta.value = data.insights_meta || null
    operations.value = data.operations || null

    if (data.chart) {
      chartData.value = formatChartData(data.chart)
    }
  } catch (error) {
    if (error.response?.status === 403) {
      showNotify('Renove seu plano para acessar o dashboard.', 'error')
      router.push('/billing')
    }
  } finally {
    if (!silent) loading.value = false
  }
}

const refreshFromRealtime = async () => {
  if (refreshingRealtime.value) return

  refreshingRealtime.value = true

  try {
    await fetchDashboardData(true)
  } finally {
    refreshingRealtime.value = false
  }
}

const handleRealtimeOrderCreated = async (event) => {
  const order = event.detail?.order

  if (order) {
    const isPending = !order.status || order.status === 'pending'

    stats.value = {
      ...(stats.value || {}),
      pending_now: Number(stats.value?.pending_now || 0) + (isPending ? 1 : 0),
      active_orders: Number(stats.value?.active_orders || stats.value?.pending_now || 0) + 1,
      recent_orders: [order, ...(stats.value?.recent_orders || []).filter(item => item.id !== order.id)].slice(0, 5)
    }
  }

  await refreshFromRealtime()
}

const handleRealtimeOrderUpdated = async (event) => {
  const order = event.detail?.order

  if (order && stats.value?.recent_orders) {
    stats.value = {
      ...stats.value,
      recent_orders: stats.value.recent_orders.map(item => item.id === order.id ? { ...item, ...order } : item)
    }
  }

  await refreshFromRealtime()
}

const storeToggleLabel = computed(() => {
  if (!isStoreOpen.value) {
    return 'Loja Offline'
  }

  if (openOutsideHours.value || withinScheduledHours.value === false) {
    return 'Loja Online · fora do horário'
  }

  return 'Loja Online'
})

const toggleStoreStatus = async () => {
  if (togglingStoreStatus.value) return

  try {
    togglingStoreStatus.value = true
    const { data } = await api.patch('/merchant/toggle-open')

    isStoreOpen.value = Boolean(data.is_open)
    manualIsStoreOpen.value = Boolean(data.manual_is_open)
    withinScheduledHours.value = data.within_scheduled_hours ?? true
    openOutsideHours.value = Boolean(data.open_outside_hours)

    window.dispatchEvent(new CustomEvent('partiumenu:store-status-changed', {
      detail: {
        is_open: isStoreOpen.value,
        opening_status: data.opening_status || null,
      },
    }))

    showNotify(data.message || 'Status da loja atualizado.')
  } catch (error) {
    showNotify('Não foi possível alterar o status da loja.', 'error')
  } finally {
    togglingStoreStatus.value = false
  }
}

onMounted(async () => {
  window.addEventListener('partiumenu:order-created', handleRealtimeOrderCreated)
  window.addEventListener('partiumenu:order-updated', handleRealtimeOrderUpdated)
  await fetchDashboardData()
})

useOnStoreSwitch(() => fetchDashboardData(true))

onBeforeUnmount(() => {
  window.removeEventListener('partiumenu:order-created', handleRealtimeOrderCreated)
  window.removeEventListener('partiumenu:order-updated', handleRealtimeOrderUpdated)
})
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <MobileDashboardSummary
        v-if="isMobileViewport"
        :loading="loading"
        :dashboard-cards="dashboardCards"
        :chart-data="chartData"
        :chart-options="chartOptions"
        :peak-weekday="peakWeekday"
        :peak-hour="peakHour"
        :has-premium-dashboard="hasPremiumDashboard === true"
        :format-currency="formatCurrency"
      />

      <template v-else>
      <section class="pm-page-header">
        <div class="flex items-center gap-4">
          <div class="pm-page-icon">
            <TrendingUp size="24" />
          </div>

          <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Painel Principal</h1>
            <p class="text-slate-500 text-xs font-bold uppercase tracking-widest">Acompanhe seu delivery</p>
          </div>
        </div>

        <button
          @click="toggleStoreStatus"
          :disabled="togglingStoreStatus"
          :class="isStoreOpen ? 'bg-emerald-500 text-white border-emerald-600' : 'bg-slate-200 text-slate-600'"
          class="flex items-center gap-3 px-6 py-3 rounded-2xl font-black transition-all active:scale-95 shadow-md disabled:opacity-70 disabled:cursor-not-allowed"
        >
          <div
            :class="isStoreOpen ? 'bg-white text-emerald-500' : 'bg-slate-400 text-white'"
            class="w-6 h-6 rounded-full flex items-center justify-center"
          >
            <Loader2 v-if="togglingStoreStatus" size="14" class="animate-spin" />
            <Power v-else size="14" fill="currentColor" />
          </div>
          {{ storeToggleLabel }}
        </button>
      </section>

      <div v-if="loading" class="flex flex-col items-center justify-center py-20 text-slate-400">
        <Loader2 class="animate-spin mb-4" size="48" />
        <p class="font-black animate-pulse">Analisando operação...</p>
      </div>

      <div v-else class="space-y-8">
        <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-5 gap-4">
          <div
            v-for="card in dashboardCards"
            :key="card.label"
            :class="['p-5 rounded-3xl transition-all', card.theme.card]"
          >
            <div class="flex items-center justify-between mb-4">
              <div :class="['flex h-11 w-11 items-center justify-center rounded-2xl', card.theme.icon]">
                <component :is="card.icon" size="20" />
              </div>
              <ArrowUpRight class="text-slate-300" size="18" />
            </div>
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-1">{{ card.label }}</p>
            <h3 :class="['text-2xl font-black tracking-tight', card.theme.value]">{{ card.val }}</h3>
            <p :class="['text-xs font-bold mt-1', card.theme.desc]">{{ card.desc }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
          <div class="xl:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-5">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">Performance</p>
                <h2 class="text-xl font-black text-slate-900 mt-1">Fluxo de Vendas</h2>
                <p class="text-sm text-slate-500 font-bold">Últimos 7 dias</p>
              </div>
              <div class="bg-slate-50 px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 border border-slate-200">
                Semanal
              </div>
            </div>

            <div class="h-56 sm:h-64">
              <Line v-if="chartData" :data="chartData" :options="chartOptions" />
              <div v-else class="h-full flex items-center justify-center rounded-2xl bg-slate-50 border border-dashed border-slate-200">
                <p class="text-sm font-bold text-slate-400">Sem dados de vendas ainda.</p>
              </div>
            </div>
          </div>

          <div class="space-y-4">
            <div
              v-if="hasPremiumDashboard === true"
              class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm"
            >
              <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400 mb-4">Resumo rápido</p>

              <div class="space-y-3">
                <div v-if="peakWeekday" class="flex items-center gap-3 rounded-2xl border border-blue-100/60 bg-blue-50/40 p-4">
                  <div class="rounded-xl bg-blue-500 p-2.5 text-white shadow-sm shadow-blue-500/20">
                    <BarChart3 size="18" />
                  </div>
                  <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Melhor dia</p>
                    <p class="font-black text-slate-900">{{ peakWeekday.label }}</p>
                    <p class="text-xs font-bold text-slate-500">{{ peakWeekday.orders_count }} pedidos</p>
                  </div>
                </div>

                <div v-if="peakHour" class="flex items-center gap-3 rounded-2xl bg-slate-50 p-4">
                  <div class="p-2.5 rounded-xl bg-sky-50 text-sky-600">
                    <Clock size="18" />
                  </div>
                  <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Horário de pico</p>
                    <p class="font-black text-slate-900">{{ peakHour.label }}</p>
                    <p class="text-xs font-bold text-slate-500">{{ formatCurrency(peakHour.revenue) }}</p>
                  </div>
                </div>

                <div
                  class="flex items-center gap-3 rounded-2xl p-4 border"
                  :class="(operations?.delayed_orders || 0) > 0 ? 'bg-amber-50/40 border-amber-100/80' : 'bg-slate-50 border-slate-200/80'"
                >
                  <div
                    class="p-2.5 rounded-xl"
                    :class="(operations?.delayed_orders || 0) > 0 ? 'bg-amber-100 text-amber-600' : 'bg-emerald-50 text-emerald-600'"
                  >
                    <AlertTriangle size="18" />
                  </div>
                  <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Operação</p>
                    <p class="font-black text-slate-900">
                      {{ (operations?.delayed_orders || 0) > 0 ? `${operations.delayed_orders} possível(is) atraso(s)` : 'Tudo em dia' }}
                    </p>
                    <p class="text-xs font-bold text-slate-500">
                      Limite de {{ operations?.delay_threshold_minutes || 45 }} min
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div
              v-else
              class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
            >
              <div class="flex items-start gap-3">
                <div class="p-2.5 rounded-xl bg-slate-100 text-slate-600">
                  <Lock size="18" />
                </div>
                <div>
                  <p class="text-sm font-black text-slate-900">Dashboard premium</p>
                  <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">
                    Desbloqueie horários de pico, dias fortes, alertas de atraso e inteligência com IA.
                  </p>
                  <button
                    @click="router.push('/billing?upgrade=dashboard_advanced')"
                    class="mt-4 inline-flex items-center gap-1 rounded-xl bg-slate-900 px-4 py-2 text-xs font-black text-white hover:bg-slate-800 transition"
                  >
                    Ver planos
                    <ChevronRight size="14" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <section class="rounded-xl border border-slate-200/80 bg-white p-6 shadow-sm">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
            <div class="flex items-start gap-3">
              <div class="p-3 rounded-xl bg-amber-50 text-amber-600 shrink-0">
                <Lightbulb size="20" />
              </div>
              <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">Inteligência</p>
                <h2 class="text-lg font-black text-slate-900 mt-1">Dicas para vender mais</h2>
                <p class="text-sm font-bold text-slate-500 mt-0.5">
                  Resumo das principais recomendações da sua loja
                </p>
              </div>
            </div>

            <button
              v-if="hasIntelligence === true"
              @click="router.push('/intelligence')"
              class="inline-flex items-center gap-1 self-start rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition"
            >
              Ver todas as dicas
              <ChevronRight size="14" />
            </button>
          </div>

          <div v-if="hasIntelligence !== true" class="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 p-6 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
              <Lock size="22" />
            </div>
            <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-600">
              Recurso Premium
            </span>
            <p class="mt-3 text-sm font-black text-slate-900">Inteligência com IA no plano Premium</p>
            <p class="mt-1 text-xs font-semibold text-slate-500 max-w-md mx-auto">
              Dicas personalizadas para vender mais com base nos seus pedidos, horários e cardápio.
            </p>
            <button
              @click="router.push('/billing?upgrade=intelligence')"
              class="mt-4 inline-flex items-center gap-1 rounded-xl bg-slate-900 px-4 py-2 text-xs font-black text-white hover:bg-slate-800 transition"
            >
              Ver planos
              <ChevronRight size="14" />
            </button>
          </div>

          <div v-else-if="previewInsights.length === 0" class="rounded-2xl border border-dashed border-slate-200 bg-white p-6 text-center">
            <p class="text-sm font-black text-slate-700">Sem insights no momento</p>
            <p class="mt-1 text-xs font-semibold text-slate-400">
              Continue vendendo para gerar análises personalizadas.
            </p>
          </div>

          <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <article
              v-for="(insight, index) in previewInsights"
              :key="index"
              class="rounded-2xl border p-4 shadow-sm bg-white"
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

                  <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500 line-clamp-2">
                    {{ insight.description }}
                  </p>

                  <span
                    v-if="insight.type"
                    class="mt-3 inline-flex rounded-lg px-2 py-1 text-[10px] font-black uppercase tracking-wider"
                    :class="getInsightTypeStyle(insight.type)"
                  >
                    {{ getInsightTypeLabel(insight.type) }}
                  </span>
                </div>
              </div>
            </article>
          </div>

          <div
            v-if="hasIntelligence && insightsSourceLabel"
            class="mt-4 flex flex-wrap items-center justify-between gap-2"
          >
            <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
              <Sparkles v-if="insightsMeta?.source === 'gemini' || insightsMeta?.source === 'openai'" size="12" />
              <Zap v-else size="12" class="text-amber-500" />
              {{ insightsSourceLabel }}
            </div>

            <p v-if="formattedInsightsTime" class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
              Atualizado em {{ formattedInsightsTime }}
            </p>
          </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between">
              <h2 class="font-black text-lg text-slate-900">Últimos Pedidos</h2>
              <button @click="router.push('/orders')" class="text-slate-600 text-sm font-bold flex items-center gap-1 hover:gap-2 transition-all">
                Ver todos <ChevronRight size="16" />
              </button>
            </div>

            <div class="divide-y divide-slate-100">
              <div
                v-for="order in stats?.recent_orders"
                :key="order.id"
                class="p-4 flex items-center justify-between hover:bg-slate-50 transition-colors"
              >
                <div class="flex items-center gap-4">
                  <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400">
                    <ShoppingBag size="20" />
                  </div>
                  <div>
                    <p class="font-black text-slate-800">#{{ order.id }} - {{ order.customer_name }}</p>
                    <p class="text-xs font-bold text-slate-400">{{ order.items_count }} itens • {{ formatTime(order.created_at) }}</p>
                  </div>
                </div>
                <div class="text-right">
                  <p class="font-black text-slate-900">{{ formatCurrency(order.total_amount) }}</p>
                  <span :class="getStatusStyle(order.status)" class="text-[10px] font-black px-2 py-1 rounded-full uppercase">
                    {{ order.status_label || getStatusLabel(order.status) }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-6">
              <h2 class="font-black text-lg text-slate-900">Top Produtos</h2>
              <Trophy class="text-amber-500" size="24" />
            </div>

            <div class="space-y-5">
              <div
                v-for="(product, index) in topProducts"
                :key="product.name"
                class="flex items-center gap-4"
              >
                <div
                  :class="index === 0 ? 'bg-amber-100 text-amber-600' : 'bg-slate-100 text-slate-500'"
                  class="w-8 h-8 rounded-full flex items-center justify-center font-black text-xs"
                >
                  #{{ index + 1 }}
                </div>
                <div class="flex-1">
                  <div class="flex justify-between mb-1">
                    <span class="font-bold text-slate-700">{{ product.name }}</span>
                    <span class="text-sm font-black text-slate-900">{{ product.total_qty }} un.</span>
                  </div>
                  <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full bg-slate-500 rounded-full" :style="{ width: `${Math.min(100, product.total_qty * 10)}%` }"></div>
                  </div>
                </div>
              </div>

              <div v-if="topProducts.length === 0" class="text-sm font-bold text-slate-400 text-center py-8">
                Nenhum produto vendido ainda.
              </div>
            </div>
          </div>
        </div>

        <div v-if="hasPremiumDashboard === true" class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-5">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">Análise</p>
                <h2 class="font-black text-lg text-slate-900 mt-1">Dias mais fortes</h2>
                <p class="text-sm font-bold text-slate-400">Últimos 30 dias</p>
              </div>
              <div class="p-2.5 rounded-xl bg-violet-50 text-violet-600">
                <BarChart3 size="20" />
              </div>
            </div>

            <div class="space-y-4">
              <div v-for="day in salesByWeekday" :key="day.weekday">
                <div class="flex items-center justify-between text-sm font-black text-slate-700 mb-1">
                  <span>{{ day.label }}</span>
                  <span>{{ day.orders_count }} pedidos</span>
                </div>
                <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                  <div class="h-full bg-slate-500 rounded-full" :style="{ width: `${Math.min(100, day.orders_count * 8)}%` }"></div>
                </div>
                <p class="mt-1 text-xs font-bold text-slate-400">{{ formatCurrency(day.revenue) }}</p>
              </div>
            </div>
          </div>

          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-5">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">Análise</p>
                <h2 class="font-black text-lg text-slate-900 mt-1">Horários de pico</h2>
                <p class="text-sm font-bold text-slate-400">Onde sua loja mais vende</p>
              </div>
              <div class="p-2.5 rounded-xl bg-sky-50 text-sky-600">
                <Clock size="20" />
              </div>
            </div>

            <div class="space-y-4">
              <div v-for="hour in salesByHour" :key="hour.hour" class="flex items-center justify-between rounded-2xl bg-slate-50 p-4">
                <div>
                  <p class="text-lg font-black text-slate-900">{{ hour.label }}</p>
                  <p class="text-xs font-bold text-slate-400">{{ hour.orders_count }} pedidos</p>
                </div>
                <p class="font-black text-slate-800">{{ formatCurrency(hour.revenue) }}</p>
              </div>
            </div>
          </div>

          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 lg:col-span-2 xl:col-span-1">
            <div class="flex items-center justify-between mb-5">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400">Análise</p>
                <h2 class="font-black text-lg text-slate-900 mt-1">Operação</h2>
                <p class="text-sm font-bold text-slate-400">Controle de atrasos</p>
              </div>
              <div
                class="p-2.5 rounded-xl"
                :class="(operations?.delayed_orders || 0) > 0 ? 'bg-amber-100 text-amber-600' : 'bg-emerald-50 text-emerald-600'"
              >
                <AlertTriangle size="20" />
              </div>
            </div>

            <div
              class="rounded-3xl border p-5"
              :class="(operations?.delayed_orders || 0) > 0 ? 'bg-amber-50/40 border-amber-100/80' : 'bg-slate-50 border-slate-200/80'"
            >
              <p
                class="text-[10px] font-bold uppercase tracking-widest"
                :class="(operations?.delayed_orders || 0) > 0 ? 'text-amber-600' : 'text-slate-500'"
              >
                {{ (operations?.delayed_orders || 0) > 0 ? 'Atenção na cozinha' : 'Operação saudável' }}
              </p>
              <p
                class="mt-2 text-4xl font-black"
                :class="(operations?.delayed_orders || 0) > 0 ? 'text-amber-700' : 'text-slate-800'"
              >
                {{ operations?.delayed_orders || 0 }}
              </p>
              <p
                class="mt-2 text-sm font-bold"
                :class="(operations?.delayed_orders || 0) > 0 ? 'text-amber-700' : 'text-slate-600'"
              >
                {{
                  (operations?.delayed_orders || 0) > 0
                    ? `Pedidos abertos há mais de ${operations?.delay_threshold_minutes || 45} minutos.`
                    : 'Nenhum pedido acima do tempo limite no momento.'
                }}
              </p>
            </div>

            <button
              @click="router.push('/orders')"
              class="mt-5 w-full rounded-2xl bg-slate-900 py-3 text-sm font-black text-white hover:bg-slate-800 transition"
            >
              Ver pedidos em aberto
            </button>
          </div>
        </div>
      </div>
      </template>
    </div>
</template>
