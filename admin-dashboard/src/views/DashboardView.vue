<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import axios from 'axios'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import {
  TrendingUp,
  DollarSign,
  Package,
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
  Utensils,
  CheckCircle,
  XCircle
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

const storeId = ref(null)
const realtimeInitialized = ref(false)
const router = useRouter()
const stats = ref(null)
const chartData = ref(null)
const topProducts = ref([])
const salesByWeekday = ref([])
const salesByHour = ref([])
const insights = ref([])
const operations = ref(null)
const hasPremiumDashboard = ref(null)
const isStoreOpen = ref(true)
const manualIsStoreOpen = ref(true)
const togglingStoreStatus = ref(false)
const loading = ref(true)

const toast = ref({ show: false, message: '', type: 'success' })

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || import.meta.env.VITE_API_URL || 'http://localhost:8000')
  .replace(/\/api\/v1\/?$/, '')
  .replace(/\/$/, '')

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

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }

  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const playOrderSound = () => {
  try {
    const audio = new Audio('/sounds/new-order.mp3')
    audio.volume = 0.8
    audio.play().catch(() => {})
  } catch (error) {
    console.warn('[Dashboard Sound Error]', error)
  }
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
        borderColor: '#ef4444',
        backgroundColor: 'rgba(239, 68, 68, 0.1)',
        fill: true,
        tension: 0.4,
        pointBackgroundColor: '#ef4444'
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

const dashboardCards = computed(() => {
  const baseCards = [
    {
      label: 'Vendas Hoje',
      val: stats.value?.today ? formatCurrency(stats.value.today.revenue) : 'R$ 0,00',
      icon: DollarSign,
      color: 'text-emerald-600',
      bg: 'bg-emerald-50',
      desc: `${stats.value?.today?.sales_count || 0} pedidos concluídos`
    },
    {
      label: 'Pedidos em aberto',
      val: stats.value?.pending_now ?? 0,
      icon: Package,
      color: 'text-orange-600',
      bg: 'bg-orange-50',
      desc: 'Aguardando ação'
    },
    {
      label: 'Faturamento Mensal',
      val: stats.value?.monthly_revenue ? formatCurrency(stats.value.monthly_revenue) : 'R$ 0,00',
      icon: Target,
      color: 'text-red-600',
      bg: 'bg-red-50',
      desc: 'Acumulado do mês'
    }
  ]

  if (hasPremiumDashboard.value !== true) {
    return baseCards
  }

  return [
    ...baseCards,
    {
      label: 'Ticket médio',
      val: stats.value?.average_ticket ? formatCurrency(stats.value.average_ticket) : 'R$ 0,00',
      icon: BarChart3,
      color: 'text-blue-600',
      bg: 'bg-blue-50',
      desc: `${stats.value?.monthly_orders_count || 0} pedidos no mês`
    },
    {
      label: 'Possíveis atrasos',
      val: operations.value?.delayed_orders ?? 0,
      icon: AlertTriangle,
      color: 'text-amber-600',
      bg: 'bg-amber-50',
      desc: `Pedidos acima de ${operations.value?.delay_threshold_minutes || 45} min`
    }
  ]
})

const fetchDashboardData = async (silent = false) => {
  if (!silent) loading.value = true

  try {
    const { data } = await api.get('/merchant/stats')

    stats.value = { ...data.stats }
    isStoreOpen.value = data.store?.is_open ?? false
    manualIsStoreOpen.value = data.store?.manual_is_open ?? data.store?.is_open ?? false
    hasPremiumDashboard.value = Boolean(data.store?.has_premium_dashboard)
    topProducts.value = [...(data.top_products || [])]
    salesByWeekday.value = [...(data.sales_by_weekday || [])]
    salesByHour.value = [...(data.sales_by_hour || [])]
    insights.value = [...(data.insights || [])]
    operations.value = data.operations || null
    storeId.value = data.store?.id

    if (data.chart) {
      chartData.value = formatChartData(data.chart)
    }
  } catch (error) {
    if (error.response?.status === 403) {
      router.push('/plans')
    }
  } finally {
    if (!silent) loading.value = false
  }
}

const setupRealtimeListener = async () => {
  if (realtimeInitialized.value) return

  try {
    const userResponse = await api.get('/me')

    if (!userResponse.data?.store?.id) {
      return
    }

    const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY
    const pusherCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER

    if (!pusherKey || !pusherCluster) {
      console.warn('[Dashboard Realtime] Pusher env vars ausentes.')
      return
    }

    storeId.value = userResponse.data.store.id
    realtimeInitialized.value = true

    window.Pusher = Pusher

    const token = localStorage.getItem('auth_token')

    if (window.Echo) {
      window.Echo.leave(`store.${storeId.value}`)
      window.Echo.disconnect()
    }

    window.Echo = new Echo({
      broadcaster: 'pusher',
      key: pusherKey,
      cluster: pusherCluster,
      forceTLS: (import.meta.env.VITE_PUSHER_SCHEME || 'https') === 'https',
      encrypted: true,
      enabledTransports: ['ws', 'wss'],
      authEndpoint: `${apiBaseUrl}/broadcasting/auth`,
      auth: {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json'
        }
      },
      authorizer: (channel) => {
        return {
          authorize: (socketId, callback) => {
            axios.post(`${apiBaseUrl}/broadcasting/auth`, {
              socket_id: socketId,
              channel_name: channel.name
            }, {
              headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json'
              }
            })
              .then(response => {
                callback(false, response.data)
              })
              .catch(error => {
                console.error('[Echo Dashboard Auth Error]', {
                  status: error.response?.status,
                  data: error.response?.data,
                  channel: channel.name
                })

                callback(true, error)
              })
          }
        }
      }
    })

    window.Echo.private(`store.${storeId.value}`)
      .listen('.order.created', async (e) => {
        await fetchDashboardData(true)
        playOrderSound()
        showNotify(`Novo pedido! #${e.order.id}`)
      })
      .listen('.order.updated', async (e) => {
        await fetchDashboardData(true)
        showNotify(`Pedido #${e.order.id} atualizado para ${getStatusLabel(e.order.status)}.`)
      })
      .error((error) => {
        console.error('[Echo Dashboard Error]', error)
      })
  } catch (error) {
    console.error('[Dashboard Realtime Setup Error]', error)
  }
}

const toggleStoreStatus = async () => {
  if (togglingStoreStatus.value) return

  try {
    togglingStoreStatus.value = true
    const { data } = await api.patch('/merchant/toggle-open')

    isStoreOpen.value = Boolean(data.is_open)
    manualIsStoreOpen.value = Boolean(data.manual_is_open)
    showNotify(data.message || 'Status da loja atualizado.')
  } catch (error) {
    showNotify('Não foi possível alterar o status da loja.', 'error')
  } finally {
    togglingStoreStatus.value = false
  }
}

onMounted(async () => {
  await fetchDashboardData()
  await setupRealtimeListener()
})
</script>

<template>
  <DashboardLayout>
    <div v-if="toast.show" class="fixed top-5 right-5 z-[100] animate-in slide-in-from-right">
      <div
        :class="[
          'px-6 py-3 rounded-2xl shadow-lg font-black text-white flex items-center gap-3',
          toast.type === 'success' ? 'bg-emerald-500' : 'bg-red-500'
        ]"
      >
        <CheckCircle v-if="toast.type === 'success'" />
        <XCircle v-else />
        {{ toast.message }}
      </div>
    </div>

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-2 duration-500 pb-10">
      <section
        class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm"
      >
        <div class="flex items-center gap-4">
          <div
            class="w-12 h-12 rounded-2xl bg-red-500 flex items-center justify-center text-white shadow-lg shadow-red-100"
          >
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
          {{ isStoreOpen ? 'Loja Online' : 'Loja Offline' }}
        </button>
      </section>

      <div v-if="loading" class="flex flex-col items-center justify-center py-20 text-red-500">
        <Loader2 class="animate-spin mb-4" size="48" />
        <p class="font-black animate-pulse">Analisando operação...</p>
      </div>

      <div v-else class="space-y-8">
        <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-5 gap-4">
          <div
            v-for="card in dashboardCards"
            :key="card.label"
            class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm hover:shadow-md transition-all"
          >
            <div class="flex items-center justify-between mb-4">
              <div :class="card.bg" class="p-3 rounded-2xl">
                <component :is="card.icon" :class="card.color" size="24" />
              </div>
              <ArrowUpRight class="text-slate-300" size="18" />
            </div>
            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">{{ card.label }}</p>
            <h3 class="text-2xl font-black text-slate-900 tracking-tight">{{ card.val }}</h3>
            <p class="text-xs font-bold text-slate-400 mt-1">{{ card.desc }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
          <div class="xl:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-8">
              <div>
                <h2 class="text-xl font-black text-slate-900">Fluxo de Vendas</h2>
                <p class="text-sm text-slate-500 font-bold">Performance nos últimos 7 dias</p>
              </div>
              <div class="bg-slate-50 px-3 py-1 rounded-lg text-xs font-black text-slate-500 border border-slate-100">
                Relatório Semanal
              </div>
            </div>

            <div class="h-80">
              <Line v-if="chartData" :data="chartData" :options="chartOptions" />
            </div>
          </div>

          <div class="bg-slate-950 text-white p-6 rounded-3xl shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-32 h-32 bg-red-500/20 rounded-full blur-2xl"></div>
            <div class="flex items-center gap-3 mb-6 relative z-10">
              <div class="p-2 bg-red-500 rounded-xl"><Lightbulb size="20" /></div>
              <h2 class="text-xl font-black">Inteligência</h2>
            </div>

            <div class="space-y-4 relative z-10">
              <div
                v-for="(insight, index) in insights"
                :key="index"
                class="bg-white/5 border border-white/10 p-4 rounded-2xl"
              >
                <p class="text-sm font-bold leading-relaxed text-slate-300">{{ insight }}</p>
              </div>

              <div v-if="insights.length === 0" class="text-sm font-bold text-slate-400">
                Sem insights no momento. Continue vendendo para gerar análises.
              </div>
            </div>

            <div v-if="hasPremiumDashboard !== true" class="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4">
              <div class="flex items-start gap-3">
                <Lock class="mt-0.5 text-red-300" size="18" />
                <div>
                  <p class="text-sm font-black">Dashboard premium bloqueado</p>
                  <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-400">
                    Libere horários de pico, dias fortes e alertas de atraso no plano Premium.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between">
              <h2 class="font-black text-lg text-slate-900">Últimos Pedidos</h2>
              <button @click="router.push('/orders')" class="text-red-600 text-sm font-black flex items-center gap-1 hover:gap-2 transition-all">
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
                    <div class="h-full bg-red-500 rounded-full" :style="{ width: `${Math.min(100, product.total_qty * 10)}%` }"></div>
                  </div>
                </div>
              </div>

              <div v-if="topProducts.length === 0" class="text-sm font-bold text-slate-400 text-center py-8">
                Nenhum produto vendido ainda.
              </div>
            </div>
          </div>
        </div>

        <div v-if="hasPremiumDashboard === true" class="grid grid-cols-1 xl:grid-cols-3 gap-8">
          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-5">
              <div>
                <h2 class="font-black text-lg text-slate-900">Dias mais fortes</h2>
                <p class="text-sm font-bold text-slate-400">Últimos 30 dias</p>
              </div>
              <BarChart3 class="text-red-600" size="22" />
            </div>

            <div class="space-y-4">
              <div v-for="day in salesByWeekday" :key="day.weekday">
                <div class="flex items-center justify-between text-sm font-black text-slate-700 mb-1">
                  <span>{{ day.label }}</span>
                  <span>{{ day.orders_count }} pedidos</span>
                </div>
                <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                  <div class="h-full bg-red-500 rounded-full" :style="{ width: `${Math.min(100, day.orders_count * 8)}%` }"></div>
                </div>
                <p class="mt-1 text-xs font-bold text-slate-400">{{ formatCurrency(day.revenue) }}</p>
              </div>
            </div>
          </div>

          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-5">
              <div>
                <h2 class="font-black text-lg text-slate-900">Horários de pico</h2>
                <p class="text-sm font-bold text-slate-400">Onde sua loja mais vende</p>
              </div>
              <Clock class="text-red-600" size="22" />
            </div>

            <div class="space-y-4">
              <div v-for="hour in salesByHour" :key="hour.hour" class="flex items-center justify-between rounded-2xl bg-slate-50 p-4">
                <div>
                  <p class="text-lg font-black text-slate-900">{{ hour.label }}</p>
                  <p class="text-xs font-bold text-slate-400">{{ hour.orders_count }} pedidos</p>
                </div>
                <p class="font-black text-red-600">{{ formatCurrency(hour.revenue) }}</p>
              </div>
            </div>
          </div>

          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-5">
              <div>
                <h2 class="font-black text-lg text-slate-900">Operação</h2>
                <p class="text-sm font-bold text-slate-400">Controle de atrasos</p>
              </div>
              <AlertTriangle class="text-amber-500" size="22" />
            </div>

            <div class="rounded-3xl bg-amber-50 border border-amber-100 p-5">
              <p class="text-[10px] font-black uppercase tracking-widest text-amber-600">Possíveis atrasos</p>
              <p class="mt-2 text-4xl font-black text-amber-700">{{ operations?.delayed_orders || 0 }}</p>
              <p class="mt-2 text-sm font-bold text-amber-700">
                Pedidos abertos há mais de {{ operations?.delay_threshold_minutes || 45 }} minutos.
              </p>
            </div>

            <button
              @click="router.push('/orders')"
              class="mt-5 w-full rounded-2xl bg-slate-950 py-3 text-sm font-black text-white hover:bg-slate-800 transition"
            >
              Ver pedidos em aberto
            </button>
          </div>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
