<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PagarMeCheckoutModal from '@/components/PagarMeCheckoutModal.vue'
import { enabledFeatureLabels, getMissingFromPlan } from '@/constants/planFeatures'
import { usePaymentGraceWarning } from '@/composables/usePaymentGraceWarning'
import {
  ArrowRight,
  Check,
  CheckCircle,
  CreditCard,
  Crown,
  Loader2,
  Lock,
  Sparkles,
  XCircle,
  Building2
} from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()
const loading = ref(true)
const store = ref(null)
const plans = ref([])
const pagarme = ref(null)
const checkoutOpen = ref(false)
const selectedPlan = ref(null)
const successBanner = ref('')
const toast = ref({ show: false, message: '', type: 'success' })

const statusLabels = {
  trial: 'Teste',
  active: 'Ativo',
  complimentary: 'Cortesia',
  past_due: 'Pendente',
  canceled: 'Cancelado',
  suspended: 'Suspenso'
}

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const money = (value) => Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

const currentPlan = computed(() => store.value?.plan || null)

const activePlans = computed(() =>
  plans.value
    .filter(p => p.is_active && p.is_visible !== false)
    .sort((a, b) => Number(a.price || 0) - Number(b.price || 0))
)

const isMaxPlan = computed(() => {
  if (isComplimentary.value) return false
  if (!currentPlan.value || !activePlans.value.length) return false
  return currentPlan.value.id === activePlans.value.at(-1)?.id
})

const highestPlan = computed(() => activePlans.value.at(-1) || null)

const missingFromHighest = computed(() => {
  if (!currentPlan.value || !highestPlan.value || isMaxPlan.value) return []
  return getMissingFromPlan(currentPlan.value, highestPlan.value)
})

const statusTone = computed(() => {
  const s = store.value?.subscription_status
  if (['active', 'trial', 'complimentary'].includes(s)) return 'bg-emerald-50 text-emerald-700 ring-emerald-100'
  if (s === 'past_due') return 'bg-orange-50 text-orange-700 ring-orange-100'
  return 'bg-red-50 text-red-700 ring-red-100'
})

const renewalLabel = computed(() => {
  const raw = store.value?.subscription_status === 'complimentary'
    ? store.value?.complimentary_until
    : store.value?.subscription_ends_at
  if (!raw) return '—'
  return new Date(raw).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' })
})

const productsUsage = computed(() => store.value?.products_usage || null)

const productsPercent = computed(() => {
  const u = productsUsage.value
  if (!u || u.is_unlimited || !u.limit) return 0
  return Math.min(100, Math.round((u.current / u.limit) * 100))
})

const storesUsage = computed(() => store.value?.stores_usage || null)

const storesPercent = computed(() => {
  const u = storesUsage.value
  if (!u || !u.limit) return 0
  return Math.min(100, Math.round((u.current / u.limit) * 100))
})

const enabledFeatures = computed(() =>
  enabledFeatureLabels(currentPlan.value?.features || {})
)

const nextPlan = computed(() => {
  if (isMaxPlan.value) return null
  const price = Number(currentPlan.value?.price || 0)
  return activePlans.value.find(p => Number(p.price) > price) || null
})

const billingEmail = computed(() => store.value?.billing_email || store.value?.user?.email || '')

const hasPaidSubscription = computed(() =>
  store.value?.subscription_status === 'active'
)

const isComplimentary = computed(() => store.value?.subscription_status === 'complimentary')
const isTrial = computed(() => store.value?.subscription_status === 'trial')

const billingAmountLabel = computed(() => {
  if (isComplimentary.value) return 'Cortesia'
  if (isTrial.value) return 'Período de teste'
  if (!currentPlan.value) return '—'
  return money(currentPlan.value.price)
})

const billingAmountHint = computed(() => {
  if (isComplimentary.value) {
    return store.value?.complimentary_until
      ? `Sem cobrança até ${renewalLabel.value}`
      : 'Sem cobrança no momento'
  }
  if (isTrial.value) return 'Sem cobrança durante o teste'
  if (hasPaidSubscription.value) return 'cobrado no cartão ao assinar e renovado todo mês'
  if (store.value?.subscription_status === 'canceled') return 'assinatura cancelada'
  return 'por mês · recorrente'
})

const showBillingAmount = computed(() =>
  Boolean(currentPlan.value) && !['canceled', 'suspended'].includes(store.value?.subscription_status)
)

const graceWarning = usePaymentGraceWarning(store)

const courtesyEndingSoon = computed(() => {
  if (!isComplimentary.value || !store.value?.complimentary_until) return null
  const end = new Date(store.value.complimentary_until)
  const days = Math.ceil((end.getTime() - Date.now()) / (1000 * 60 * 60 * 24))
  if (days < 0) return null
  if (days <= 14) return days
  return null
})

const courtesyExpired = computed(() =>
  store.value?.subscription_status === 'past_due' && Boolean(store.value?.complimentary_reason)
)

const courtesyPaymentPlan = computed(() => {
  if (isComplimentary.value && currentPlan.value) {
    const visibleCurrent = activePlans.value.find(plan => plan.id === currentPlan.value?.id)
    if (visibleCurrent) return visibleCurrent
    return activePlans.value[0] || currentPlan.value
  }

  return currentPlan.value || activePlans.value[0] || null
})

const applyUpgradeBanner = () => {
  const upgraded = route.query.upgraded
  if (!upgraded) return

  successBanner.value = `Plano ${upgraded} ativado com sucesso!`
  router.replace({ path: '/billing' })
}

const startCheckout = (plan) => {
  if (!plan?.id || !pagarme.value?.configured) {
    showNotify('Pagar.me não configurado.', 'error')
    return
  }
  selectedPlan.value = plan
  checkoutOpen.value = true
}

const handleCheckoutSuccess = async (data) => {
  const planName = data?.plan_name || selectedPlan.value?.name || 'novo plano'
  successBanner.value = `Plano ${planName} ativado com sucesso!`
  window.dispatchEvent(new CustomEvent('partiumenu:store-updated'))
  await fetchBillingData()
}

const fetchBillingData = async () => {
  loading.value = true
  try {
    const [{ data: storeResponse }, { data: plansResponse }, { data: billingResponse }] = await Promise.all([
      api.get('/merchant/store'),
      api.get('/plans'),
      api.get('/merchant/billing/pagarme/status')
    ])
    store.value = storeResponse.data || storeResponse
    plans.value = Array.isArray(plansResponse) ? plansResponse : []
    pagarme.value = billingResponse.pagarme
  } catch (error) {
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      router.push('/login')
      return
    }

    showNotify('Erro ao carregar dados do plano.', 'error')
  } finally {
    loading.value = false
  }
}

watch(() => route.query.upgraded, applyUpgradeBanner, { immediate: true })
onMounted(fetchBillingData)
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <div v-if="graceWarning" class="pm-alert-warning">
        {{ graceWarning }}
      </div>

      <div
        v-if="courtesyExpired"
        class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4"
      >
        <p class="text-sm font-black text-red-900">Sua cortesia encerrou</p>
        <p class="mt-1 text-sm font-bold text-red-800">
          Para continuar usando o plano {{ currentPlan?.name }}, assine agora em {{ money(currentPlan?.price) }}/mês.
        </p>
        <button
          v-if="courtesyPaymentPlan"
          type="button"
          class="mt-4 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-4 py-2.5 text-sm font-black text-white transition hover:bg-red-700"
          @click="startCheckout(courtesyPaymentPlan)"
        >
          <CreditCard size="15" />
          Assinar e continuar
        </button>
      </div>

      <div
        v-else-if="courtesyEndingSoon !== null"
        class="rounded-2xl border border-violet-200 bg-violet-50 px-5 py-4"
      >
        <p class="text-sm font-black text-violet-900">
          Cortesia termina em {{ courtesyEndingSoon === 0 ? 'hoje' : `${courtesyEndingSoon} dia(s)` }}
        </p>
        <p class="mt-1 text-sm font-bold text-violet-800">
          Depois de {{ renewalLabel }}, será necessário assinar o plano {{ currentPlan?.name }} ({{ money(currentPlan?.price) }}/mês) para manter o painel.
        </p>
        <button
          v-if="courtesyPaymentPlan"
          type="button"
          class="mt-4 inline-flex items-center gap-2 rounded-2xl bg-violet-600 px-4 py-2.5 text-sm font-black text-white transition hover:bg-violet-700"
          @click="startCheckout(courtesyPaymentPlan)"
        >
          <CreditCard size="15" />
          Assinar antes do fim
        </button>
      </div>

      <PageHeader
        title="Meu plano"
        subtitle="Cobrança no cartão ao assinar e renovação mensal via Pagar.me."
      >
        <template #icon>
          <CreditCard size="26" />
        </template>
        <template #actions>
          <button
            v-if="!isMaxPlan || isComplimentary"
            type="button"
            class="pm-btn-solid"
            @click="router.push('/plans')"
          >
            Ver planos
            <ArrowRight size="16" />
          </button>
        </template>
      </PageHeader>

      <div
        v-if="successBanner"
        class="flex items-center gap-3 rounded-3xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-emerald-900"
      >
        <CheckCircle class="flex-shrink-0 text-emerald-600" size="20" />
        <p class="text-sm font-bold">{{ successBanner }}</p>
      </div>

      <div v-if="loading" class="flex justify-center py-24 text-red-600">
        <Loader2 class="animate-spin" size="32" />
      </div>

      <div v-else class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)] xl:items-start">
        <div class="space-y-6">
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Plano atual</p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                  <h2 class="text-2xl font-black text-slate-900">{{ currentPlan?.name || 'Sem plano' }}</h2>
                  <span :class="['rounded-full px-2.5 py-0.5 text-xs font-black ring-1 ring-inset', statusTone]">
                    {{ statusLabels[store?.subscription_status] || store?.subscription_status }}
                  </span>
                </div>
                <p v-if="currentPlan?.description" class="mt-2 max-w-xl text-sm font-semibold text-slate-500">
                  {{ currentPlan.description }}
                </p>
              </div>

              <div v-if="showBillingAmount" class="text-right">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                  {{ hasPaidSubscription ? 'Você paga' : 'Valor do plano' }}
                </p>
                <p class="mt-1 text-3xl font-black text-slate-900">
                  {{ billingAmountLabel }}
                  <span v-if="hasPaidSubscription" class="text-sm font-bold text-slate-400">/mês</span>
                </p>
                <p class="text-sm font-bold text-slate-400">{{ billingAmountHint }}</p>
              </div>
            </div>

            <dl class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div class="rounded-2xl bg-slate-50 px-4 py-3">
                <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                  {{ isComplimentary || isTrial ? 'Válido até' : 'Próxima cobrança' }}
                </dt>
                <dd class="mt-1 font-black text-slate-800">{{ renewalLabel }}</dd>
              </div>
              <div v-if="hasPaidSubscription" class="rounded-2xl bg-slate-50 px-4 py-3">
                <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Valor mensal</dt>
                <dd class="mt-1 font-black text-slate-800">{{ money(currentPlan?.price) }}</dd>
              </div>
              <div class="rounded-2xl bg-slate-50 px-4 py-3">
                <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Produtos</dt>
                <dd class="mt-1 font-black text-slate-800">
                  {{ productsUsage?.current || 0 }}
                  <span class="font-bold text-slate-400">
                    / {{ productsUsage?.is_unlimited ? '∞' : productsUsage?.limit || '—' }}
                  </span>
                </dd>
              </div>
              <div v-if="storesUsage" class="rounded-2xl bg-slate-50 px-4 py-3">
                <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">Lojas / filiais</dt>
                <dd class="mt-1 font-black text-slate-800">
                  {{ storesUsage.current }}
                  <span class="font-bold text-slate-400">/ {{ storesUsage.limit }}</span>
                </dd>
                <dd
                  v-if="Number(storesUsage.extra_branch_monthly_price || 0) > 0"
                  class="mt-1 text-xs font-bold text-slate-500"
                >
                  Extra: {{ money(storesUsage.extra_branch_monthly_price) }}/mês
                </dd>
              </div>
              <div class="rounded-2xl bg-slate-50 px-4 py-3">
                <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">E-mail de cobrança</dt>
                <dd class="mt-1 truncate font-black text-slate-800">{{ billingEmail || '—' }}</dd>
              </div>
            </dl>

            <div v-if="productsUsage && !productsUsage.is_unlimited && productsUsage.limit" class="mt-6">
              <div class="flex justify-between text-xs font-bold text-slate-500">
                <span>Uso de produtos</span>
                <span>{{ productsUsage.current }} de {{ productsUsage.limit }}</span>
              </div>
              <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                  class="h-full rounded-full bg-slate-500 transition-all"
                  :style="{ width: `${productsPercent}%` }"
                />
              </div>
            </div>

            <div v-if="storesUsage && storesUsage.limit > 1" class="mt-4 rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
              <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-white text-red-600 shadow-sm">
                  <Building2 size="18" />
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-black text-slate-900">Filiais no Premium</p>
                  <p class="mt-0.5 text-xs font-semibold text-slate-500">
                    Matriz + filiais com cardápio e pedidos independentes.
                  </p>
                  <p
                    v-if="Number(storesUsage.extra_branch_monthly_price || 0) > 0"
                    class="mt-2 text-xs font-bold text-slate-600"
                  >
                    Filial extra além do plano:
                    <span class="font-black text-slate-900">{{ money(storesUsage.extra_branch_monthly_price) }}/mês</span>
                  </p>
                  <div class="mt-3 flex justify-between text-xs font-bold text-slate-500">
                    <span>Lojas cadastradas</span>
                    <span>{{ storesUsage.current }} de {{ storesUsage.limit }}</span>
                  </div>
                  <div class="mt-2 h-2 overflow-hidden rounded-full bg-white">
                    <div
                      class="h-full rounded-full bg-red-400 transition-all"
                      :style="{ width: `${storesPercent}%` }"
                    />
                  </div>
                  <button
                    v-if="storesUsage.can_create_branch"
                    type="button"
                    class="mt-3 text-xs font-black text-red-600 hover:text-red-700"
                    @click="router.push('/loja')"
                  >
                    Criar filial em Loja → Filiais
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <aside class="space-y-4 xl:sticky xl:top-6">
          <div
            v-if="isMaxPlan"
            class="rounded-3xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-6 text-center shadow-sm"
          >
            <Crown class="mx-auto text-emerald-600" size="28" />
            <p class="mt-3 font-black text-slate-900">Plano mais completo</p>
            <p class="mt-1 text-sm font-bold text-slate-500">Sua loja tem acesso a todos os recursos.</p>
          </div>

          <div
            v-else-if="missingFromHighest.length"
            class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
          >
            <div class="flex items-center gap-2">
              <Sparkles class="text-slate-500" size="18" />
              <h3 class="font-black text-slate-900">Recursos do {{ highestPlan?.name }}</h3>
            </div>
            <p class="mt-1 text-sm font-bold text-slate-500">
              O que você ainda não tem no plano atual.
            </p>

            <ul class="mt-4 space-y-2">
              <li
                v-for="item in missingFromHighest"
                :key="item"
                class="flex items-center gap-2.5 rounded-2xl border border-slate-100 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700"
              >
                <Lock size="14" class="flex-shrink-0 text-slate-400" />
                {{ item }}
              </li>
            </ul>

            <button
              type="button"
              class="mt-5 flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-900 py-3 text-sm font-black text-white transition-all hover:bg-slate-800 active:scale-95"
              @click="router.push('/plans')"
            >
              Ver plano {{ highestPlan?.name }}
              <ArrowRight size="15" />
            </button>
          </div>

          <div v-if="nextPlan" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Próximo passo</p>
            <p class="mt-1 text-lg font-black text-slate-900">{{ nextPlan.name }}</p>
            <p class="mt-1 text-sm font-bold text-slate-600">{{ nextPlan.description }}</p>
            <p class="mt-3 text-xl font-black text-slate-900">{{ money(nextPlan.price) }}/mês</p>

            <button
              type="button"
              class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-900 py-3 text-sm font-black text-white transition-all hover:bg-slate-800 active:scale-95"
              @click="startCheckout(nextPlan)"
            >
              <CreditCard size="15" />
              Fazer upgrade agora
            </button>
          </div>

          <div v-if="enabledFeatures.length" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="font-black text-slate-900">Recursos do seu plano</h3>
            <ul class="mt-4 grid gap-2">
              <li
                v-for="feature in enabledFeatures"
                :key="feature"
                class="flex items-center gap-2 rounded-2xl border border-slate-100 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700"
              >
                <Check size="14" class="flex-shrink-0 text-emerald-500" />
                {{ feature }}
              </li>
            </ul>
          </div>

          <div class="rounded-3xl border border-slate-200 bg-slate-50 p-5 shadow-sm">
            <p class="text-sm font-black text-slate-900">Cobrança recorrente</p>
            <p class="mt-2 text-xs font-bold leading-relaxed text-slate-500">
              Pagamentos processados pela Pagar.me. Alterações de plano entram em vigor após confirmação do cartão.
            </p>
            <p v-if="billingEmail" class="mt-3 text-xs font-bold text-slate-600">
              Faturas enviadas para <span class="text-slate-900">{{ billingEmail }}</span>
            </p>
            <button
              type="button"
              class="mt-4 pm-btn-ghost w-full justify-center text-sm"
              @click="router.push('/plans')"
            >
              Comparar planos
              <ArrowRight size="14" />
            </button>
          </div>
        </aside>
      </div>
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
