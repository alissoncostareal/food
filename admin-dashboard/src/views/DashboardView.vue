<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { 
  TrendingUp, Users, DollarSign, Package, 
  Power, ChevronRight, ShoppingBag, Loader2 
} from 'lucide-vue-next'

const router = useRouter()
const stats = ref(null)
const isStoreOpen = ref(true)
const loading = ref(true)

// Formatação dos cards via Computed para performance
const dashboardCards = computed(() => [
  { 
    label: 'Faturamento Total', 
    val: stats.value ? `R$ ${stats.value.total_revenue.toLocaleString('pt-BR')}` : 'R$ 0,00', 
    icon: DollarSign, color: 'text-emerald-600', bg: 'bg-emerald-50' 
  },
  { 
    label: 'Pedidos de Hoje', 
    val: stats.value?.orders_count || 0, 
    icon: Package, color: 'text-indigo-600', bg: 'bg-indigo-50' 
  },
  { 
    label: 'Clientes Ativos', 
    val: stats.value?.active_customers || 0, 
    icon: Users, color: 'text-orange-600', bg: 'bg-orange-50' 
  },
  { 
    label: 'Crescimento Mensal', 
    val: stats.value ? `${stats.value.growth}%` : '0%', 
    icon: TrendingUp, color: 'text-blue-600', bg: 'bg-blue-50' 
  },
])

const fetchStats = async () => {
  try {
    loading.value = true
    const { data } = await api.get('/store/stats')
    stats.value = data
  } catch (error) {
    // Se cair no seu middleware de active_subscription (403)
    if (error.response?.status === 403) {
      router.push('/plans')
    }
    console.error("Erro ao carregar estatísticas:", error)
  } finally {
    loading.value = false
  }
}

const toggleStoreStatus = async () => {
  try {
    // Mostra um feedback visual imediato (Optimistic UI)
    const originalStatus = isStoreOpen.value
    isStoreOpen.value = !isStoreOpen.value
    
    await api.patch('/store/toggle-open')
  } catch (error) {
    // Reverte se a API falhar
    isStoreOpen.value = !isStoreOpen.value
    alert("Não foi possível alterar o status da loja agora.")
  }
}

onMounted(fetchStats)
</script>

<template>
  <DashboardLayout>
    <div class="space-y-8 animate-in fade-in duration-500">
      
      <section class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Painel Operacional</h1>
          <p class="text-slate-500">Acompanhe seus indicadores em tempo real.</p>
        </div>
        
        <button 
          @click="toggleStoreStatus"
          :class="isStoreOpen 
            ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' 
            : 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100'"
          class="flex items-center gap-3 px-6 py-3 border rounded-xl font-bold transition-all active:scale-95 group"
        >
          <Power size="20" :class="isStoreOpen ? 'animate-pulse' : ''" />
          <span>{{ isStoreOpen ? 'LOJA ABERTA' : 'LOJA FECHADA' }}</span>
        </button>
      </section>

      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <template v-if="loading">
          <div v-for="i in 4" :key="i" class="h-32 bg-slate-100 animate-pulse rounded-2xl border border-slate-200"></div>
        </template>
        
        <template v-else>
          <div v-for="(card, i) in dashboardCards" :key="i" 
            class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm group hover:shadow-md hover:border-indigo-300 transition-all cursor-default">
            <div :class="card.bg" class="w-12 h-12 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
              <component :is="card.icon" :class="card.color" size="24" />
            </div>
            <p class="text-slate-500 text-sm font-medium">{{ card.label }}</p>
            <h3 class="text-2xl font-bold text-slate-900 mt-1">{{ card.val }}</h3>
          </div>
        </template>
      </section>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="font-bold text-slate-900 flex items-center gap-2">
              <ShoppingBag size="18" class="text-indigo-600" />
              Últimos Pedidos
            </h3>
            <router-link to="/orders" class="text-indigo-600 text-sm font-bold flex items-center gap-1 hover:text-indigo-700 transition-colors">
              Gerenciar tudo <ChevronRight size="16" />
            </router-link>
          </div>
          
          <div class="p-12 text-center">
            <div v-if="loading" class="flex justify-center">
              <Loader2 class="animate-spin text-slate-300" size="40" />
            </div>
            <div v-else class="opacity-40">
              <ShoppingBag size="48" class="mx-auto mb-3" />
              <p class="text-slate-500 font-medium">Nenhum pedido pendente no momento</p>
            </div>
          </div>
        </div>

        <aside class="space-y-6">
          <div class="bg-gradient-to-br from-indigo-600 to-violet-700 rounded-2xl shadow-lg p-6 text-white">
            <h3 class="font-bold text-lg mb-2">Dica de Performance</h3>
            <p class="text-indigo-100 text-sm leading-relaxed">
              Baseado no seu histórico, o horário de maior movimento hoje será às <span class="font-bold text-white underline">19:30</span>.
            </p>
          </div>
          
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <h3 class="font-bold text-slate-900 mb-4 text-sm uppercase tracking-wider">Suporte</h3>
            <button class="w-full py-3 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold transition-colors">
              Falar com Consultor
            </button>
          </div>
        </aside>
      </div>
    </div>
  </DashboardLayout>
</template>