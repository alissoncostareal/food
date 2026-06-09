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
  Zap
} from 'lucide-vue-next'

const router = useRouter()
const loading = ref(true)
const store = ref(null)
const plans = ref([])
const mercadoPago = ref(null)

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

const planPrice = computed(() => {
  const price = Number(currentPlan.value?.price || 0)

  return price.toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  })
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
  const currentPrice = Number(currentPlan.value?.price || 0)

  return plans.value
    .filter(plan => plan.is_active && Number(plan.price || 0) > currentPrice)
    .sort((a, b) => Number(a.price || 0) - Number(b.price || 0))
})

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
    <div class="mx-auto max-w-7xl space-y-6 px-4 pb-16">
      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">
            Plano e cobrança
          </p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-slate-950">
            Seu plano no PartiuMenu
          </h1>
          <p class="mt-2 max-w-2xl text-sm font-semibold leading-relaxed text-slate-500">
            Acompanhe sua mensalidade, seus recursos liberados e o que sua loja já está usando dentro do sistema.
          </p>
        </div>

        <button
          type="button"
          @click="router.push('/plans')"
          class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 py-3 text-sm font-black text-white transition hover:bg-slate-800"
        >
          Ver upgrades
          <ArrowRight size="16" />
        </button>
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

              <div class="rounded-2xl bg-slate-950 px-5 py-4 text-white lg:min-w-56">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Mensalidade</p>
                <p class="mt-1 text-3xl font-black">{{ planPrice }}</p>
                <p class="mt-1 text-xs font-bold text-slate-400">por mês</p>
              </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
              <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <CalendarClock class="text-red-600" size="22" />
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">Próximo vencimento</p>
                <p class="mt-1 text-sm font-black text-slate-800">{{ subscriptionEndLabel }}</p>
              </div>

              <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <WalletCards class="text-red-600" size="22" />
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">Pagamento</p>
                <p class="mt-1 text-sm font-black text-slate-800">Mercado Pago</p>
                <p class="mt-1 text-xs font-semibold" :class="mercadoPago?.configured ? 'text-emerald-600' : 'text-amber-600'">
                  {{ mercadoPago?.configured ? 'Credenciais configuradas' : 'Aguardando credenciais' }}
                </p>
              </div>

              <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                <ShieldCheck class="text-red-600" size="22" />
                <p class="mt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">Loja protegida</p>
                <p class="mt-1 text-sm font-black text-slate-800">Sem limite de pedidos</p>
                <p class="mt-1 text-xs font-semibold text-slate-500">O plano limita recursos, não vendas.</p>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
              <div>
                <h2 class="text-lg font-black text-slate-950">Uso do plano</h2>
                <p class="text-sm font-semibold text-slate-500">Veja quanto espaço sua operação ainda tem para crescer.</p>
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
                <h2 class="text-lg font-black text-slate-950">Recursos liberados</h2>
                <p class="text-sm font-semibold text-slate-500">Tudo que sua loja já pode usar hoje.</p>
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
                <span class="text-sm font-black text-emerald-800">{{ feature }}</span>
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
                <h2 class="text-lg font-black text-slate-950">Dados de pagamento</h2>
                <p class="text-sm font-semibold text-slate-500">
                  {{ mercadoPago?.configured ? 'Credenciais detectadas no backend.' : 'Preparado para receber as credenciais.' }}
                </p>
              </div>
            </div>

            <div class="mt-5 space-y-3">
              <div class="rounded-2xl bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Gateway planejado</p>
                <p class="mt-1 text-sm font-black text-slate-800">Mercado Pago {{ mercadoPago?.environment || 'sandbox' }}</p>
                <p class="mt-1 text-xs font-semibold text-slate-500">
                  Checkout, boleto, Pix e cartão podem entrar aqui quando a credencial estiver configurada.
                </p>
                <p v-if="mercadoPago?.missing?.length" class="mt-2 text-[11px] font-black uppercase tracking-wider text-amber-600">
                  Falta: {{ mercadoPago.missing.join(', ') }}
                </p>
              </div>

              <div class="rounded-2xl bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Cobrança atual</p>
                <p class="mt-1 text-sm font-black text-slate-800">
                  {{ store?.subscription_status === 'complimentary' ? 'Cortesia' : 'Mensalidade recorrente' }}
                </p>
                <p class="mt-1 text-xs font-semibold text-slate-500">
                  {{ store?.complimentary_reason || 'Os dados reais do método de pagamento aparecerão após a integração.' }}
                </p>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-red-100 bg-red-50 p-6">
            <div class="flex items-center gap-3">
              <Zap class="text-red-600" size="22" />
              <h2 class="text-lg font-black text-slate-950">Por que continuar?</h2>
            </div>

            <ul class="mt-4 space-y-3 text-sm font-bold leading-relaxed text-slate-700">
              <li>Pedidos ilimitados: sua mensalidade não cresce quando você vende mais.</li>
              <li>Cardápio sempre online no seu link próprio.</li>
              <li>Plano Pro libera cupons e áreas de entrega para vender melhor.</li>
              <li>Premium prepara automações avançadas para reduzir atendimento manual.</li>
            </ul>
          </div>

          <div v-if="nextPlans.length > 0" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center gap-3">
              <BadgeCheck class="text-red-600" size="22" />
              <h2 class="text-lg font-black text-slate-950">Próximo passo</h2>
            </div>

            <div class="mt-4 space-y-3">
              <div
                v-for="plan in nextPlans.slice(0, 2)"
                :key="plan.id"
                class="rounded-2xl border border-slate-100 bg-slate-50 p-4"
              >
                <p class="text-sm font-black text-slate-900">{{ plan.name }}</p>
                <p class="mt-1 text-xs font-semibold text-slate-500">{{ plan.description }}</p>
                <p class="mt-2 text-sm font-black text-red-600">
                  {{ Number(plan.price || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) }}/mês
                </p>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center gap-3">
              <ReceiptText class="text-red-600" size="22" />
              <h2 class="text-lg font-black text-slate-950">Notas úteis</h2>
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
