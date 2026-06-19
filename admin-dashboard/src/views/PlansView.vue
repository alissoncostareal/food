<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PagarMeCheckoutModal from '@/components/PagarMeCheckoutModal.vue'
import { buildPlanHighlights } from '@/constants/planFeatures'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  CheckCircle,
  CreditCard,
  Crown,
  Layers,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const loadingPlans = ref(true)
const apiPlans = ref([])
const currentStore = ref(null)
const pagarme = ref(null)
const checkoutOpen = ref(false)
const selectedPlan = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => { toast.value.show = false }, 4000)
}

const money = (value) => Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

const currentPlan = computed(() => currentStore.value?.plan || null)
const currentPlanPrice = computed(() => Number(currentPlan.value?.price || 0))

const isComplimentary = computed(() =>
  currentStore.value?.subscription_status === 'complimentary'
)

const activePlans = computed(() =>
  [...apiPlans.value]
    .filter(plan => plan.is_active && plan.is_visible !== false)
    .sort((a, b) => Number(a.price || 0) - Number(b.price || 0))
)

const isHighestPlan = computed(() => {
  if (isComplimentary.value) return false
  if (!currentPlan.value || !activePlans.value.length) return false
  const top = activePlans.value.at(-1)
  return currentPlan.value.id === top?.id
})

const visiblePlans = computed(() =>
  activePlans.value.map(plan => {
    const price = Number(plan.price || 0)
    const isCurrent = !isComplimentary.value && currentPlan.value?.id === plan.id
    const isDowngrade = !isComplimentary.value && currentPlan.value
      ? price < currentPlanPrice.value
      : false

    return {
      ...plan,
      isCurrent,
      isDowngrade,
      isRecommended: plan.slug === 'pro' && !isCurrent && !isDowngrade && !isComplimentary.value,
      highlights: buildPlanHighlights(plan)
    }
  })
)

const plansSubtitle = computed(() => {
  if (isComplimentary.value) {
    const until = currentStore.value?.complimentary_until
    const untilLabel = until
      ? new Date(until).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })
      : null

    return untilLabel
      ? `Você está em cortesia até ${untilLabel}. Escolha um plano para assinar antes ou depois do fim da cortesia.`
      : 'Você está em cortesia. Escolha um plano para assinar quando quiser.'
  }

  if (currentPlan.value) {
    return `Você está no ${currentPlan.value.name}. Escolha um plano superior para desbloquear mais recursos.`
  }

  return 'Escolha o plano ideal para sua loja.'
})

const billingEmail = computed(() => currentStore.value?.billing_email || currentStore.value?.user?.email || '')

const fetchPlans = async () => {
  try {
    loadingPlans.value = true
    const [{ data: plansResponse }, { data: storeResponse }, { data: billingResponse }] = await Promise.all([
      api.get('/plans'),
      api.get('/merchant/store'),
      api.get('/merchant/billing/pagarme/status')
    ])
    apiPlans.value = Array.isArray(plansResponse) ? plansResponse : []
    currentStore.value = storeResponse.data || storeResponse
    pagarme.value = billingResponse.pagarme
  } catch (error) {
    showNotify('Erro ao carregar planos.', 'error')
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      router.push('/login')
    }
  } finally {
    loadingPlans.value = false
  }
}

const startCheckout = (plan) => {
  if (!plan?.id || plan.isCurrent || plan.isDowngrade) return
  if (!pagarme.value?.configured) {
    showNotify('Pagar.me não configurado no backend.', 'error')
    return
  }
  selectedPlan.value = plan
  checkoutOpen.value = true
}

const handleCheckoutSuccess = async (data) => {
  const planName = data?.plan_name || selectedPlan.value?.name || 'novo plano'
  window.dispatchEvent(new CustomEvent('partiumenu:store-updated'))
  router.replace({
    path: '/billing',
    query: { upgraded: planName }
  })
}

onMounted(fetchPlans)
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <PageHeader
        title="Planos"
        :subtitle="plansSubtitle"
      >
        <template #icon>
          <Layers size="26" />
        </template>
        <template #actions>
          <button
            type="button"
            class="pm-btn-ghost"
            @click="router.push('/billing')"
          >
            <ArrowLeft size="16" />
            Meu plano
          </button>
        </template>
      </PageHeader>

      <div v-if="loadingPlans" class="grid gap-4 md:grid-cols-3">
        <div v-for="i in 3" :key="i" class="h-80 animate-pulse rounded-3xl bg-slate-100" />
      </div>

      <div
        v-else-if="isHighestPlan"
        class="rounded-3xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-10 text-center shadow-sm"
      >
        <Crown class="mx-auto text-emerald-600" size="32" />
        <h2 class="mt-4 text-xl font-black text-slate-900">Plano mais completo ativo</h2>
        <p class="mt-2 text-sm font-bold text-slate-500">Sua loja já tem todos os recursos disponíveis.</p>
        <button
          type="button"
          class="mt-6 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-6 py-3 text-sm font-black text-white transition-all hover:bg-slate-800 active:scale-95"
          @click="router.push('/billing')"
        >
          Voltar ao meu plano
          <ArrowRight size="16" />
        </button>
      </div>

      <div
        v-else-if="!visiblePlans.length"
        class="rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm"
      >
        <Layers class="mx-auto text-slate-300" size="32" />
        <h2 class="mt-4 text-xl font-black text-slate-900">Nenhum plano disponível no momento</h2>
        <p class="mt-2 text-sm font-bold text-slate-500">Entre em contato com o suporte para assinar.</p>
      </div>

      <div v-else class="grid gap-4 md:grid-cols-3">
        <article
          v-for="plan in visiblePlans"
          :key="plan.id"
          :class="[
            'flex flex-col rounded-3xl border bg-white p-6 shadow-sm',
            plan.isCurrent
              ? 'border-slate-900 ring-1 ring-slate-900'
              : plan.isRecommended
                ? 'border-slate-300 shadow-md'
                : 'border-slate-200',
            plan.isDowngrade ? 'opacity-50' : ''
          ]"
        >
          <div class="flex items-center justify-between gap-2">
            <h2 class="text-lg font-black text-slate-900">{{ plan.name }}</h2>
            <span
              v-if="plan.isCurrent"
              class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-slate-600"
            >
              Atual
            </span>
            <span
              v-else-if="plan.isRecommended"
              class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-slate-600"
            >
              Popular
            </span>
          </div>

          <p class="mt-3 text-3xl font-black text-slate-900">
            {{ money(plan.price) }}
            <span class="text-sm font-bold text-slate-400">/mês</span>
          </p>

          <p class="mt-2 text-sm font-bold text-slate-500">{{ plan.description }}</p>

          <ul class="mt-5 flex-1 space-y-2 border-t border-slate-100 pt-5">
            <li v-for="item in plan.highlights" :key="item" class="flex items-center gap-2 text-sm font-bold text-slate-600">
              <Check size="14" class="flex-shrink-0 text-emerald-500" />
              {{ item }}
            </li>
          </ul>

          <button
            type="button"
            :disabled="plan.isCurrent || plan.isDowngrade"
            :class="[
              'mt-6 w-full rounded-2xl py-3 text-sm font-black transition-all active:scale-95',
              plan.isCurrent || plan.isDowngrade
                ? 'cursor-not-allowed bg-slate-100 text-slate-400'
                : 'bg-slate-900 text-white hover:bg-slate-800'
            ]"
            @click="startCheckout(plan)"
          >
            <span v-if="plan.isCurrent">Seu plano</span>
            <span v-else-if="plan.isDowngrade">Via suporte</span>
            <span v-else-if="isComplimentary" class="inline-flex items-center justify-center gap-2">
              <CreditCard size="15" />
              Assinar este plano
            </span>
            <span v-else class="inline-flex items-center justify-center gap-2">
              <CreditCard size="15" />
              Assinar
            </span>
          </button>
        </article>
      </div>

      <p v-if="!loadingPlans && visiblePlans.length" class="text-center text-xs font-bold text-slate-400">
        Cobrança no cartão ao assinar · renovação mensal via Pagar.me · Cancele quando quiser
      </p>
    </div>

    <PagarMeCheckoutModal
      v-model:open="checkoutOpen"
      :plan="selectedPlan"
      :pagarme="pagarme"
      :default-email="billingEmail"
      @success="handleCheckoutSuccess"
      @error="message => showNotify(message, 'error')"
    />
</template>
