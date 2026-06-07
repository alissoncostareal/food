<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import axios from 'axios'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import {
  TrendingUp, Users, DollarSign, Package,
  Power, ChevronRight, ShoppingBag, Loader2,
  ArrowUpRight, Target, Trophy, Utensils,
  CheckCircle, XCircle
} from 'lucide-vue-next'

import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS, Title, Tooltip, Legend, LineElement,
  CategoryScale, LinearScale, PointElement, Filler
} from 'chart.js'

ChartJS.register(Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement, Filler)

const storeId = ref(null)
const realtimeInitialized = ref(false)
const router = useRouter()
const stats = ref(null)
const chartData = ref(null)
const topProducts = ref([])
const isStoreOpen = ref(true)
const loading = ref(true)

// Configuração do Gráfico
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
          return 'R$ ' + value.toLocaleString('pt-BR');
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

const formatCurrency = (value) => {
  const amount = Number(value) || 0
  return amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

const toast = ref({ show: false, message: '', type: 'success' })

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const setupRealtimeListener = async () => {
  if (realtimeInitialized.value) return

  try {
    const userResponse = await api.get('/me')

    if (!userResponse.data?.store?.id) {
      return
    }

    storeId.value = userResponse.data.store.id
    realtimeInitialized.value = true

    window.Pusher = Pusher

    const token = localStorage.getItem('auth_token')

    if (window.Echo) {
      window.Echo.leave(`store.${storeId.value}`)
    }

    window.Echo = new Echo({
      broadcaster: 'pusher',
      key: import.meta.env.VITE_PUSHER_APP_KEY,
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
      forceTLS: true,
      authEndpoint: `${import.meta.env.VITE_API_BASE_URL.split('/api/v1')[0]}/broadcasting/auth`,
      auth: {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json'
        }
      },
      authorizer: (channel) => {
        return {
          authorize: (socketId, callback) => {
            const authUrl = `${import.meta.env.VITE_API_BASE_URL.split('/api/v1')[0]}/broadcasting/auth`;
            axios.post(authUrl, {
              socket_id: socketId,
              channel_name: channel.name
            }, {
              headers: {
                get Authorization() {
                  const token = localStorage.getItem('access_token');
                  return token ? `Bearer ${token}` : '';
                },
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
    showNotify(`Novo pedido! #${e.order.id}`)
  })
  .listen('.order.updated', async (e) => {
    await fetchDashboardData(true)
    showNotify(`Pedido #${e.order.id} atualizado para ${e.order.status_label || e.order.status}`)
  })
  .error((error) => {
    console.error('[Echo Dashboard Error]', error)
  })
  } catch (error) {
    console.error('[Dashboard Realtime Setup Error]', error)
  }
}

const dashboardCards = computed(() => [
  {
    label: 'Vendas Hoje',
    val: stats.value?.today ? formatCurrency(stats.value.today.revenue) : 'R$ 0,00',
    icon: DollarSign, color: 'text-emerald-600', bg: 'bg-emerald-50',
    desc: `${stats.value?.today?.sales_count || 0} pedidos concluídos`
  },
  {
    label: 'Pendentes Agora',
    val: (stats.value && stats.value.pending_now !== undefined) ? stats.value.pending_now : 0,
    icon: Package, color: 'text-orange-600', bg: 'bg-orange-50',
    desc: 'Aguardando ação'
  },
  {
    label: 'Faturamento Mensal',
    val: stats.value?.monthly_revenue ? formatCurrency(stats.value.monthly_revenue) : 'R$ 0,00',
    icon: Target, color: 'text-red-600', bg: 'bg-red-50',
    desc: 'Acumulado do mês'
  }
])

const getStatusStyle = (status) => {
  const styles = {
    pending: 'bg-orange-100 text-orange-600',
    preparing: 'bg-amber-100 text-amber-600',
    ready: 'bg-red-100 text-red-600',
    delivered: 'bg-emerald-100 text-emerald-600',
    canceled: 'bg-slate-100 text-slate-400'
  }
  return styles[status] || 'bg-slate-100 text-slate-600'
}

const formatTime = (dateString) => {
  return new Date(dateString).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

const formatChartData = (chartData) => {
  return {
    labels: chartData.map(item =>
      new Date(item.date).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })
    ),
    datasets: [{
      label: 'Vendas R$',
      data: chartData.map(item => Math.round(Number(item.total))), // Adicionado Math.round
      borderColor: '#ef4444',
      backgroundColor: 'rgba(239, 68, 68, 0.1)',
      fill: true,
      tension: 0.4,
      pointBackgroundColor: '#ef4444'
    }]
  };
};

const fetchDashboardData = async (silent = false) => {
  if (!silent) loading.value = true;

  try {
    const { data } = await api.get('/merchant/stats');

    stats.value = { ...data.stats };
    isStoreOpen.value = data.store?.is_open ?? false;
    topProducts.value = [...(data.top_products || [])];
    storeId.value = data.store?.id;

    if (data.chart) {
      chartData.value = formatChartData(data.chart);
    }
  } catch (error) {
    if (error.response?.status === 403) router.push('/plans');
  } finally {
    if (!silent) loading.value = false;
  }
};

const toggleStoreStatus = async () => {
  try {
    isStoreOpen.value = !isStoreOpen.value
    await api.patch('/merchant/toggle-open')
  } catch (error) {
    isStoreOpen.value = !isStoreOpen.value
  }
}

onMounted(async () => {

  await fetchDashboardData();

  await setupRealtimeListener();

})
</script>

<template>
  <DashboardLayout>
    <div v-if="toast.show" class="fixed top-5 right-5 z-[100] animate-in slide-in-from-right">
  <div :class="['px-6 py-3 rounded-2xl shadow-lg font-black text-white flex items-center gap-3',
    toast.type === 'success' ? 'bg-emerald-500' : 'bg-red-500']">
    <CheckCircle v-if="toast.type === 'success'" />
    <XCircle v-else />
    {{ toast.message }}
  </div>
</div>
    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-2 duration-500 pb-10">

      <section
        class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
        <div class="flex items-center gap-4">
          <div
            class="w-12 h-12 rounded-2xl bg-red-500 flex items-center justify-center text-white shadow-lg shadow-red-100">
            <TrendingUp size="24" />
          </div>
          <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Painel Principal</h1>
            <p class="text-slate-500 text-xs font-bold uppercase tracking-widest">Acompanhe seu delivery</p>
          </div>
        </div>

        <button @click="toggleStoreStatus"
          :class="isStoreOpen ? 'bg-emerald-500 text-white border-emerald-600' : 'bg-slate-200 text-slate-600'"
          class="flex items-center gap-3 px-6 py-3 rounded-2xl font-black transition-all active:scale-95 shadow-md">
          <div :class="isStoreOpen ? 'bg-white text-emerald-500' : 'bg-slate-400 text-white'" class="p-1 rounded-full">
            <Power size="14" />
          </div>
          <span>{{ isStoreOpen ? 'LOJA ONLINE' : 'LOJA OFFLINE' }}</span>
        </button>
      </section>

      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="(card, i) in dashboardCards" :key="i"
          class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:border-red-200 transition-all group">
          <div :class="card.bg"
            class="w-10 h-10 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
            <component :is="card.icon" :class="card.color" size="20" />
          </div>
          <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest">{{ card.label }}</p>
          <h3 class="text-2xl font-black text-slate-900 mt-1">{{ card.val }}</h3>
          <p class="text-slate-500 text-xs mt-1 font-bold">{{ card.desc }}</p>
        </div>
      </section>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-8">
          <div class="bg-white p-8 rounded-3xl border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between mb-8">
              <h3 class="font-black text-slate-900 flex items-center gap-2">
                <ArrowUpRight size="20" class="text-red-500" />
                Vendas na Semana
              </h3>
            </div>
            <div class="h-[300px] w-full">
              <Line v-if="chartData" :key="JSON.stringify(chartData)" :data="chartData" :options="chartOptions" />
              <div v-else class="h-full flex items-center justify-center text-slate-300">
                <Loader2 class="animate-spin" />
              </div>
            </div>
          </div>

          <section class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-6 border-b border-slate-50 flex justify-between items-center bg-slate-50/30">
              <h3 class="font-black text-slate-900 flex items-center gap-2 text-sm uppercase tracking-widest">
                <ShoppingBag size="18" class="text-red-500" />
                Novos Pedidos
              </h3>
              <router-link to="/orders"
                class="text-red-500 text-xs font-black uppercase hover:underline flex items-center gap-1">
                Ver Painel de Pedidos
                <ChevronRight size="14" />
              </router-link>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-left border-collapse">
                <thead>
                  <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-50">
                    <th class="px-6 py-4">Ref</th>
                    <th class="px-6 py-4">Cliente</th>
                    <th class="px-6 py-4">Valor Total</th>
                    <th class="px-6 py-4">Status</th>
                    <th class="px-6 py-4">Hora</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                  <tr v-for="order in [...(stats?.recent_orders || [])]"
                    :key="`${order.id}-${order.updated_at || order.created_at}`"
                    class="hover:bg-orange-50/30 transition-colors group">
                    <td class="px-6 py-4 font-mono font-bold text-slate-400 text-xs">#{{ order.id }}</td>
                    <td class="px-6 py-4">
                      <div class="flex flex-col">
                        <span class="font-bold text-slate-700 text-sm">{{ order.customer_name }}</span>
                        <span class="text-[10px] text-slate-400 font-bold uppercase">{{ order.items_count }}
                          itens</span>
                      </div>
                    </td>
                    <td class="px-6 py-4 font-black text-slate-900 text-sm">{{ formatCurrency(order.total_amount) }}
                    </td>
                    <td class="px-6 py-4">
                      <span :class="getStatusStyle(order.status)"
                        class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-tight">
                        {{ order.status_label || order.status }}
                      </span>
                    </td>
                    <td class="px-6 py-4 text-xs font-bold text-slate-400">{{ formatTime(order.created_at) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <aside class="space-y-6">
          <div class="bg-white p-8 rounded-3xl border border-slate-200 shadow-sm">
            <h3 class="font-black text-slate-900 mb-6 text-xs uppercase tracking-widest flex items-center gap-2">
              <Trophy size="16" class="text-orange-500" /> Mais Vendidos
            </h3>
            <div class="space-y-6">
              <div v-for="(item, index) in topProducts" :key="index"
                class="flex items-center justify-between group cursor-default">
                <div class="flex items-center gap-3">
                  <div
                    class="w-8 h-8 rounded-lg bg-orange-50 flex items-center justify-center font-black text-orange-600 text-xs">
                    {{ index + 1 }}º
                  </div>
                  <span class="font-bold text-slate-700 text-sm group-hover:text-red-500 transition-colors">{{ item.name
                    }}</span>
                </div>
                <span class="text-[10px] font-black bg-red-50 text-red-600 px-3 py-1 rounded-full uppercase">
                  {{ item.total_qty }} un
                </span>
              </div>
            </div>
          </div>

          <div
            class="bg-gradient-to-br from-orange-500 to-red-600 rounded-3xl p-8 text-white relative overflow-hidden shadow-lg shadow-red-200 group">
            <div class="relative z-10">
              <div class="bg-white/20 w-10 h-10 rounded-xl flex items-center justify-center mb-4">
                <Utensils size="20" />
              </div>
              <h3 class="font-black text-lg mb-2 leading-tight">Melhore seu Cardápio</h3>
              <p class="text-red-50 text-xs leading-relaxed font-bold">
                Produtos com descrição detalhada vendem 24% mais. Revise seus textos agora!
              </p>
            </div>
            <ShoppingBag
              class="absolute -right-4 -bottom-4 text-white opacity-10 group-hover:rotate-12 transition-transform"
              size="120" />
          </div>
        </aside>
      </div>
    </div>
  </DashboardLayout>
</template>