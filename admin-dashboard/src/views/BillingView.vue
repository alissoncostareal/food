<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
  ArrowRight,
  BadgeCheck,
  CalendarClock,
  Check,
  CreditCard,
  Loader2,
  ReceiptText,
  ShieldCheck,
  Sparkles,
  TrendingUp,
  WalletCards,
  Zap,
  Crown,
  ExternalLink,
  XCircle,
  CheckCircle
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const checkoutLoading = ref(null)
const store = ref(null)
const plans = ref([])
const mercadoPago = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })

const featureLabels = {
  coupons: 'Cupons de desconto',
  dashboard_advanced: 'Dashboard avançado',
  whatsapp_auto: 'WhatsApp automático',
  whatsapp_bot: 'Robô de atendimento',
  whatsapp_ai: 'Atendimento com IA',
  ifood_integration: 'Integração iFood',
  advanced_reports: 'Relatórios avançados',
  delivery_areas: 'Áreas de entrega'
}

const statusLabels = {
  trial: 'Período de teste',
  active: 'Assinatura ativa',
  complimentary: 'Cortesia ativa',
  past_due: 'Pagamento pendente',
  canceled: 'Cancelada',
  suspended: 'Suspensa'
}

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const statusTone = computed(() => {
  const status = store.value?.subscription_status

  if (['active', 'trial', 'complimentary'].includes(status)) {
    return 'bg-emerald-50 text-emerald-700 border-emerald-100'
  }

  if (status === 'past_due') {
    return 'bg-amber-50 text-amber-700 border-amber-100'
  }

  return 'bg-red-50 text-red-700 border-red-100'
})

const currentPlan = computed(() => store.value?.plan || null)

const activePlans = computed(() => {
  return plans.value
    .filter(plan => plan.is_active)
    .sort((a, b) => Number(a.price || 0) - Number(b.price || 0))
})

const maxPlan = computed(() => {
  if (!activePlans.value.length) return null

  return [...activePlans.value].sort((a, b) => Number(b.price || 0) - Number(a.price || 0))[0]
})

const isCurrentMaxPlan = computed(() => {
  if (!currentPlan.value || !maxPlan.value) return false

  if (currentPlan.value.id && maxPlan.value.id) {
    return Number(currentPlan.value.id) === Number(maxPlan.value.id)
  }

  return Number(currentPlan.value.price || 0) >= Number(maxPlan.value.price || 0)
})

const subscriptionEndLabel = computed(() => {
  const rawDate = store.value?.subscription_status === 'complimentary'
    ? store.value?.complimentary_until
    : store.value?.subscription_ends_at

  if (!rawDate) {
    return store.value?.subscription_status === 'complimentary'
      ? 'Sem prazo definido'
      : 'Ainda não definido'
  }

  return new Date(rawDate).toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: 'long',
    year: 'numeric'
  })
})

const productsUsage = computed(() => store.value?.products_usage || null)

const productsUsagePercent = computed(() => {
  const usage = productsUsage.value

  if (!usage || usage.is_unlimited || !usage.limit) return 100

  return Math.min(100, Math.round((usage.current / usage.limit) * 100))
})

const enabledFeatures = computed(() => {
  return Object.entries(currentPlan.value?.features || {})
    .filter(([, enabled]) => Boolean(enabled))
    .map(([feature]) => featureLabels[feature] || feature)
})

const nextPlans = computed(() => {
  if (isCurrentMaxPlan.value) return []

  const currentPrice = Number(currentPlan.value?.price || 0)

  return activePlans.value
    .filter(plan => Number(plan.price || 0) > currentPrice)
    .sort((a, b) => Number(a.price || 0) - Number(b.price || 0))
})

const recommendedPlan = computed(() => {
  return nextPlans.value[0] || null
})

const handleGoToPlans = () => {
  if (isCurrentMaxPlan.value) {
    showNotify('Sua loja já está no plano mais completo.', 'success')
    return
  }

  router.push('/plans')
}

const startMercadoPagoCheckout = async (plan) => {
  if (!plan?.id || checkoutLoading.value) return

  checkoutLoading.value = plan.id

  try {
    const { data } = await api.post('/merchant/billing/mercado-pago/checkout', {
      plan_id: plan.id
    })

    const checkoutUrl =
      data.init_point ||
      data.sandbox_init_point ||
      data.checkout_url ||
      data.url

    if (!checkoutUrl) {
      showNotify('Checkout criado, mas o link de pagamento não foi retornado.', 'error')
      return
    }

    window.location.href = checkoutUrl
  } catch (error) {
    console.error('Erro ao iniciar checkout Mercado Pago:', error)

    const message =
      error.response?.data?.message ||
      error.response?.data?.error ||
      'Não foi possível iniciar o checkout do Mercado Pago.'

    showNotify(message, 'error')
  } finally {
    checkoutLoading.value = null
  }
}

const fetchBillingData = async () => {
  loading.value = true

  try {
    const [{ data: storeResponse }, { data: plansResponse }, { data: billingResponse }] = await Promise.all([
      api.get('/merchant/store'),
      api.get('/plans'),
      api.get('/merchant/billing/mercado-pago/status')
    ])

    store.value = storeResponse.data || storeResponse
    plans.value = Array.isArray(plansResponse) ? plansResponse : []
    mercadoPago.value = billingResponse.mercado_pago
  } catch (error) {
    console.error('Erro ao carregar plano e cobrança:', error)

    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      router.push('/login')
    }
  } finally {
    loading.value = false
  }
}

onMounted(fetchBillingData)
</script>

<template>
  <DashboardLayout>
    <div v-if="toast.show" class="fixed top-5 right-5 z-[100] animate-in slide-in-from-right">
      <div :class="[
        'px-6 py-3 rounded-2xl shadow-lg font-black text-white flex items-center gap-3',
        toast.type === 'success' ? 'bg-emerald-500' : 'bg-red-500'
      ]">
        <CheckCircle v-if="toast.type === 'success'" />
        <XCircle v-else />
        {{ toast.message }}
      </div>
    </div>

    <div class="mx-auto max-w-7xl space-y-6 px-4 pb-16">
      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">
            Plano e recursos
          </p>

          <h1 class="mt-1 text-3xl font-black tracking-tight text-slate-950">
            Sua loja no PartiuMenu
          </h1>

          <p class="mt-2 max-w-2xl text-sm font-semibold leading-relaxed text-slate-500">
            Acompanhe os recursos liberados, o uso do plano e as opções para evoluir sua operação.
          </p>
        </div>

        <button
          v-if="!isCurrentMaxPlan"
          type="button"
          @click="handleGoToPlans"
          class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 py-3 text-sm font-black text-white transition hover:bg-slate-800"
        >
          Ver upgrades
          <ArrowRight size="16" />
        </button>

        <div
          v-else
          class="inline-flex items-center justify-center gap-2 rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-3 text-sm font-black text-emerald-700"
        >
          <Crown size="16" />
          Plano máximo ativo
        </div>
      </div>

      <div v-if="loading" class="flex justify-center py-20 text-red-600">
        <Loader2 class="animate-spin" size="42" />
      </div>

      <div v-else class="grid gap-6 xl:grid-cols-[1.4fr_0.9fr]">
        <section class="space-y-6">
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <div class="flex flex-wrap items-center gap-3">
                  <h2 class="text-2xl font-black text-slate-950">
                    {{ currentPlan?.name || 'Sem plano ativo' }}
                  </h2>

                  <span :class="['rounded-full border px-3 py-1 text-[10px] font-black uppercase tracking-wider', statusTone]">
                    {{ statusLabels[store?.subscription_status] || store?.subscription_status || 'Sem status' }}
                  </span>
                </div>

                <p class="mt-2 max-w-xl text-sm font-semibold leading-relaxed text-slate-500">
                  {{ currentPlan?.description || 'Escolha um plano para manter sua loja vendendo com estabilidade.' }}
                </p>
              </div>

              <div
                v-if="isCurrentMaxPlan"
                class="rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-4 text-emerald-700 lg:min-w-56"
              >
                <Crown size="22" />
                <p class="mt-2 text-[10px] font-black uppercase tracking-[0.18em] text-emerald-600">
                  Melhor plano ativo
                </p>
                <p class="mt-1 text-sm font-black">
                  Sua loja já está com todos os recursos disponíveis.
                </p>
              </div>

              <div
                v-else
                class="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-red-700 lg:min-w-56"
              >
                <Sparkles size="22" />
                <p class="mt-2 text-[10px] font-black uppercase tracking-[0.18em] text-red-500">
                  Upgrade disponível
                </p>
                <p class="mt-1 text-sm font-black">
                  Libere mais recursos para sua loja crescer.
                </p>
              </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
              <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <CalendarClock class="text-red-600" size="22" />

                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">
                  Próximo ciclo
                </p>

                <p class="mt-1 text-sm font-black text-slate-800">
                  {{ subscriptionEndLabel }}
                </p>
              </div>

              <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <WalletCards class="text-red-600" size="22" />

                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">
                  Pagamento
                </p>

                <p class="mt-1 text-sm font-black text-slate-800">
                  Mercado Pago
                </p>

                <p class="mt-1 text-xs font-semibold" :class="mercadoPago?.configured ? 'text-emerald-600' : 'text-amber-600'">
                  {{ mercadoPago?.configured ? 'Ambiente configurado' : 'Ambiente de teste pendente' }}
                </p>
              </div>

              <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <ShieldCheck class="text-red-600" size="22" />

                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">
                  Loja protegida
                </p>

                <p class="mt-1 text-sm font-black text-slate-800">
                  Pedidos sem limite
                </p>

                <p class="mt-1 text-xs font-semibold text-slate-500">
                  O plano limita recursos, não vendas.
                </p>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
              <div>
                <h2 class="text-lg font-black text-slate-950">
                  Uso do plano
                </h2>

                <p class="text-sm font-semibold text-slate-500">
                  Veja quanto espaço sua operação ainda tem para crescer.
                </p>
              </div>

              <TrendingUp class="text-red-600" size="24" />
            </div>

            <div class="mt-5">
              <div class="flex items-center justify-between text-sm font-black text-slate-700">
                <span>Produtos cadastrados</span>

                <span>
                  {{ productsUsage?.current || 0 }}
                  <template v-if="productsUsage?.is_unlimited">/ ilimitado</template>
                  <template v-else>/ {{ productsUsage?.limit || 0 }}</template>
                </span>
              </div>

              <div class="mt-3 h-3 overflow-hidden rounded-full bg-slate-100">
                <div
                  class="h-full rounded-full bg-red-600 transition-all"
                  :style="{ width: `${productsUsagePercent}%` }"
                ></div>
              </div>

              <p class="mt-3 text-xs font-semibold text-slate-500">
                {{ productsUsage?.is_unlimited ? 'Seu plano atual permite cadastrar produtos sem limite definido.' : 'Quando chegar perto do limite, um upgrade evita travas na operação.' }}
              </p>
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center gap-3">
              <Sparkles class="text-red-600" size="22" />

              <div>
                <h2 class="text-lg font-black text-slate-950">
                  Recursos liberados
                </h2>

                <p class="text-sm font-semibold text-slate-500">
                  Tudo que sua loja já pode usar hoje.
                </p>
              </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
              <div
                v-for="feature in enabledFeatures"
                :key="feature"
                class="flex items-center gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3"
              >
                <div class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-600 text-white">
                  <Check size="15" />
                </div>

                <span class="text-sm font-black text-emerald-800">
                  {{ feature }}
                </span>
              </div>

              <p v-if="enabledFeatures.length === 0" class="text-sm font-semibold text-slate-500">
                Seu plano atual ainda não libera recursos avançados.
              </p>
            </div>
          </div>
        </section>

        <aside class="space-y-6">
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center gap-3">
              <CreditCard class="text-red-600" size="22" />

              <div>
                <h2 class="text-lg font-black text-slate-950">
                  Mercado Pago
                </h2>

                <p class="text-sm font-semibold text-slate-500">
                  {{ mercadoPago?.configured ? 'Ambiente pronto para testes de checkout.' : 'Configure as credenciais de teste no backend.' }}
                </p>
              </div>
            </div>

            <div class="mt-5 space-y-3">
              <div class="rounded-2xl bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                  Ambiente
                </p>

                <p class="mt-1 text-sm font-black text-slate-800">
                  Mercado Pago {{ mercadoPago?.environment || 'sandbox' }}
                </p>

                <p class="mt-1 text-xs font-semibold text-slate-500">
                  Use credenciais de teste para validar Pix, cartão e retorno de pagamento.
                </p>

                <p v-if="mercadoPago?.missing?.length" class="mt-2 text-[11px] font-black uppercase tracking-wider text-amber-600">
                  Falta: {{ mercadoPago.missing.join(', ') }}
                </p>
              </div>

              <div class="rounded-2xl bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                  Status da cobrança
                </p>

                <p class="mt-1 text-sm font-black text-slate-800">
                  {{ store?.subscription_status === 'complimentary' ? 'Cortesia ativa' : 'Assinatura em gerenciamento' }}
                </p>

                <p class="mt-1 text-xs font-semibold text-slate-500">
                  {{ store?.complimentary_reason || 'Os detalhes do método de pagamento ficam protegidos no gateway.' }}
                </p>
              </div>
            </div>
          </div>

          <div
            v-if="recommendedPlan && !isCurrentMaxPlan"
            class="rounded-2xl border border-red-100 bg-red-50 p-6"
          >
            <div class="flex items-center gap-3">
              <Zap class="text-red-600" size="22" />
              <h2 class="text-lg font-black text-slate-950">
                Próximo upgrade
              </h2>
            </div>

            <div class="mt-4 rounded-2xl border border-red-100 bg-white p-4">
              <p class="text-sm font-black text-slate-900">
                {{ recommendedPlan.name }}
              </p>

              <p class="mt-1 text-xs font-semibold text-slate-500">
                {{ recommendedPlan.description }}
              </p>

              <p class="mt-3 text-sm font-black text-red-600">
                {{ Number(recommendedPlan.price || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) }}/mês
              </p>

              <button
                type="button"
                @click="startMercadoPagoCheckout(recommendedPlan)"
                :disabled="checkoutLoading === recommendedPlan.id"
                class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 px-4 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                <Loader2 v-if="checkoutLoading === recommendedPlan.id" class="animate-spin" size="16" />
                <ExternalLink v-else size="16" />
                {{ checkoutLoading === recommendedPlan.id ? 'Abrindo checkout...' : 'Testar checkout Mercado Pago' }}
              </button>
            </div>
          </div>

          <div
            v-else
            class="rounded-2xl border border-emerald-100 bg-emerald-50 p-6"
          >
            <div class="flex items-center gap-3">
              <Crown class="text-emerald-600" size="22" />
              <h2 class="text-lg font-black text-slate-950">
                Plano completo
              </h2>
            </div>

            <p class="mt-3 text-sm font-bold leading-relaxed text-slate-700">
              Sua loja já está no maior plano disponível. Por isso, escondemos a tela de upgrades para evitar confusão.
            </p>
          </div>

          <div v-if="nextPlans.length > 0" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center gap-3">
              <BadgeCheck class="text-red-600" size="22" />
              <h2 class="text-lg font-black text-slate-950">
                Outras opções
              </h2>
            </div>

            <div class="mt-4 space-y-3">
              <div
                v-for="plan in nextPlans.slice(0, 2)"
                :key="plan.id"
                class="rounded-2xl border border-slate-100 bg-slate-50 p-4"
              >
                <p class="text-sm font-black text-slate-900">
                  {{ plan.name }}
                </p>

                <p class="mt-1 text-xs font-semibold text-slate-500">
                  {{ plan.description }}
                </p>

                <p class="mt-2 text-sm font-black text-red-600">
                  {{ Number(plan.price || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) }}/mês
                </p>

                <button
                  type="button"
                  @click="startMercadoPagoCheckout(plan)"
                  :disabled="checkoutLoading === plan.id"
                  class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <Loader2 v-if="checkoutLoading === plan.id" class="animate-spin" size="14" />
                  <ExternalLink v-else size="14" />
                  {{ checkoutLoading === plan.id ? 'Abrindo...' : 'Testar checkout' }}
                </button>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center gap-3">
              <ReceiptText class="text-red-600" size="22" />

              <h2 class="text-lg font-black text-slate-950">
                Notas úteis
              </h2>
            </div>

            <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-500">
              Quando Mercado Pago estiver ativo, esta página pode exibir histórico de faturas, segunda via, status do Pix/boleto e troca de cartão.
            </p>
          </div>
        </aside>
      </div>
    </div>
  </DashboardLayout>
</template>