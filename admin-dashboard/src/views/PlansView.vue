<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import {
  ArrowRight,
  Check,
  CheckCircle,
  Crown,
  Loader2,
  ShieldCheck,
  Sparkles,
  Store,
  TrendingUp,
  XCircle,
  Zap
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(null)
const loadingPlans = ref(true)
const apiPlans = ref([])
const currentStore = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })

const featureLabels = {
  coupons: 'Cupons de desconto',
  dashboard_advanced: 'Dashboard avançado',
  whatsapp_auto: 'WhatsApp automático',
  whatsapp_bot: 'Bot de atendimento no WhatsApp',
  whatsapp_ai: 'IA para dúvidas frequentes',
  ifood_integration: 'Integração iFood',
  advanced_reports: 'Relatórios avançados',
  delivery_areas: 'Áreas de entrega'
}

const planVisuals = {
  starter: {
    icon: Store,
    accent: 'text-slate-700',
    ring: 'border-slate-200',
    badge: 'Operação inicial'
  },
  pro: {
    icon: Zap,
    accent: 'text-red-600',
    ring: 'border-red-200 ring-4 ring-red-100',
    badge: 'Mais indicado'
  },
  premium: {
    icon: Crown,
    accent: 'text-amber-600',
    ring: 'border-amber-200',
    badge: 'Máximo desempenho'
  }
}

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const money = (value) => {
  return Number(value || 0).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  })
}

const currentPlan = computed(() => currentStore.value?.plan || null)
const currentPlanPrice = computed(() => Number(currentPlan.value?.price || 0))
const highestPlanPrice = computed(() => {
  return Math.max(...apiPlans.value.map(plan => Number(plan.price || 0)), 0)
})

const isHighestPlan = computed(() => {
  return currentPlan.value && currentPlanPrice.value >= highestPlanPrice.value
})

const visiblePlans = computed(() => {
  return [...apiPlans.value]
    .filter(plan => plan.is_active)
    .sort((a, b) => Number(a.price || 0) - Number(b.price || 0))
    .map(plan => {
      const visual = planVisuals[plan.slug] || planVisuals.starter
      const price = Number(plan.price || 0)
      const isCurrent = currentPlan.value?.id === plan.id
      const isUpgrade = currentPlan.value ? price > currentPlanPrice.value : true
      const isDowngrade = currentPlan.value ? price < currentPlanPrice.value : false
      const enabledFeatures = Object.entries(plan.features || {})
        .filter(([, enabled]) => Boolean(enabled))
        .map(([feature]) => featureLabels[feature] || feature)

      return {
        ...plan,
        visual,
        isCurrent,
        isUpgrade,
        isDowngrade,
        featureList: [
          plan.max_products === null ? 'Produtos ilimitados' : `Até ${plan.max_products} produtos`,
          'Pedidos ilimitados por mês',
          ...enabledFeatures
        ]
      }
    })
})

const recommendationText = computed(() => {
  if (!currentPlan.value) {
    return 'Escolha um plano para liberar sua operação no painel.'
  }

  if (isHighestPlan.value) {
    return 'Você já está no plano mais completo. Foque em vender mais usando os recursos avançados.'
  }

  return 'Faça upgrade quando precisar de mais produtos, automações ou relatórios para crescer com menos trabalho manual.'
})

const fetchPlans = async () => {
  try {
    loadingPlans.value = true
    const [{ data: plansResponse }, { data: storeResponse }] = await Promise.all([
      api.get('/plans'),
      api.get('/merchant/store')
    ])

    apiPlans.value = Array.isArray(plansResponse) ? plansResponse : []
    currentStore.value = storeResponse.data || storeResponse
  } catch (error) {
    console.error('Erro ao carregar planos:', error)
    showNotify('Erro ao carregar planos.', 'error')

    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      router.push('/login')
    }
  } finally {
    loadingPlans.value = false
  }
}

const handleSubscribe = async (plan) => {
  if (plan.isCurrent || plan.isDowngrade) return

  loading.value = plan.id

  try {
    await api.post('/merchant/subscribe', { plan_id: plan.id })
    showNotify('Plano atualizado com sucesso.')

    setTimeout(() => {
      router.push('/billing')
    }, 1200)
  } catch (error) {
    console.error('Erro ao assinar plano:', error)
    showNotify(error.response?.data?.message || 'Erro ao processar alteração de plano.', 'error')
  } finally {
    loading.value = null
  }
}

onMounted(fetchPlans)
</script>

<template>
  <div class="min-h-screen bg-slate-50 px-4 py-10 font-sans text-slate-900">
    <div class="mx-auto max-w-7xl">
      <div v-if="toast.show" class="fixed right-5 top-5 z-50">
        <div
          :class="[
            'flex items-center gap-3 rounded-2xl px-5 py-3 text-sm font-black text-white shadow-xl',
            toast.type === 'success' ? 'bg-emerald-600' : 'bg-red-600'
          ]"
        >
          <CheckCircle v-if="toast.type === 'success'" size="18" />
          <XCircle v-else size="18" />
          {{ toast.message }}
        </div>
      </div>

      <header class="mb-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="grid gap-6 lg:grid-cols-[1.4fr_0.8fr] lg:items-center">
          <div>
            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-red-600">
              Planos PartiuMenu
            </p>
            <h1 class="mt-2 text-3xl font-black tracking-tight md:text-4xl">
              Escolha recursos para a próxima fase da sua loja
            </h1>
            <p class="mt-3 max-w-2xl text-sm font-semibold leading-relaxed text-slate-500">
              {{ recommendationText }}
            </p>
          </div>

          <div class="rounded-2xl bg-slate-950 p-5 text-white">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Seu plano atual</p>
            <p class="mt-2 text-2xl font-black">{{ currentPlan?.name || 'Sem plano' }}</p>
            <p class="mt-1 text-sm font-bold text-slate-300">
              {{ currentPlan ? `${money(currentPlan.price)}/mês` : 'Escolha um plano para ativar' }}
            </p>
            <button
              type="button"
              @click="router.push('/billing')"
              class="mt-4 inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-xs font-black text-slate-950 transition hover:bg-slate-100"
            >
              Ver cobrança
              <ArrowRight size="14" />
            </button>
          </div>
        </div>
      </header>

      <div v-if="loadingPlans" class="flex justify-center py-16 text-red-600">
        <Loader2 class="animate-spin" size="38" />
      </div>

      <div v-else class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <article
          v-for="plan in visiblePlans"
          :key="plan.id"
          :class="[
            'relative flex min-h-full flex-col rounded-3xl border bg-white p-6 shadow-sm transition-all',
            plan.isCurrent ? 'border-slate-950 ring-4 ring-slate-100' : plan.visual.ring,
            plan.isDowngrade ? 'opacity-75' : ''
          ]"
        >
          <div class="mb-5 flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
              <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100">
                <component :is="plan.visual.icon" :class="plan.visual.accent" size="25" />
              </div>
              <div>
                <h2 class="text-xl font-black">{{ plan.name }}</h2>
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                  {{ plan.visual.badge }}
                </p>
              </div>
            </div>

            <span
              v-if="plan.isCurrent"
              class="rounded-full bg-slate-950 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-white"
            >
              Atual
            </span>
          </div>

          <div class="mb-5">
            <div class="flex items-end gap-1">
              <span class="text-4xl font-black">{{ money(plan.price) }}</span>
              <span class="pb-1 text-sm font-bold text-slate-400">/mês</span>
            </div>
            <p class="mt-3 min-h-12 text-sm font-semibold leading-relaxed text-slate-500">
              {{ plan.description }}
            </p>
          </div>

          <ul class="mb-6 flex-1 space-y-3">
            <li v-for="feature in plan.featureList" :key="feature" class="flex items-start gap-3">
              <div class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <Check size="13" />
              </div>
              <span class="text-sm font-bold text-slate-650">{{ feature }}</span>
            </li>
          </ul>

          <button
            type="button"
            @click="handleSubscribe(plan)"
            :disabled="loading === plan.id || plan.isCurrent || plan.isDowngrade || isHighestPlan"
            :class="[
              'mt-auto inline-flex w-full items-center justify-center gap-2 rounded-2xl px-4 py-4 text-sm font-black transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-70',
              plan.isCurrent
                ? 'bg-slate-100 text-slate-500'
                : plan.isDowngrade
                  ? 'bg-slate-100 text-slate-400'
                  : 'bg-red-600 text-white shadow-lg shadow-red-100 hover:bg-red-700'
            ]"
          >
            <Loader2 v-if="loading === plan.id" class="animate-spin" size="18" />
            <template v-else-if="plan.isCurrent">
              <ShieldCheck size="18" />
              Plano atual
            </template>
            <template v-else-if="plan.isDowngrade">
              Alterar via suporte
            </template>
            <template v-else-if="isHighestPlan">
              <Sparkles size="18" />
              Você já tem o melhor plano
            </template>
            <template v-else>
              <TrendingUp size="18" />
              Fazer upgrade
            </template>
          </button>
        </article>
      </div>
    </div>
  </div>
</template>
