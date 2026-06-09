<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import {
  BadgeCheck,
  CalendarDays,
  CheckCircle,
  Edit3,
  Gift,
  LayoutDashboard,
  Loader2,
  LogOut,
  Save,
  Search,
  ShieldCheck,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()

const loading = ref(true)
const savingPlan = ref(null)
const savingStore = ref(null)
const activeTab = ref('plans')
const toast = ref({ show: false, message: '', type: 'success' })
const plans = ref([])
const stores = ref([])
const search = ref('')
const editingPlanId = ref(null)

const featureLabels = {
  coupons: 'Cupons',
  dashboard_advanced: 'Dashboard avançado',
  whatsapp_auto: 'WhatsApp automático',
  whatsapp_bot: 'Bot WhatsApp',
  whatsapp_ai: 'IA no WhatsApp',
  ifood_integration: 'Integração iFood',
  advanced_reports: 'Relatórios avançados',
  delivery_areas: 'Áreas de entrega'
}

const planForms = reactive({})
const courtesyForms = reactive({})

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3500)
}

const statusLabel = (status) => {
  const labels = {
    trial: 'Teste',
    active: 'Ativa',
    complimentary: 'Cortesia',
    past_due: 'Pendente',
    canceled: 'Cancelada',
    suspended: 'Suspensa'
  }

  return labels[status] || status
}

const statusClass = (status) => {
  if (['active', 'trial', 'complimentary'].includes(status)) {
    return 'bg-emerald-50 text-emerald-700 border-emerald-100'
  }

  if (status === 'suspended') {
    return 'bg-red-50 text-red-700 border-red-100'
  }

  return 'bg-amber-50 text-amber-700 border-amber-100'
}

const normalizedDate = (value) => {
  if (!value) return ''

  return String(value).slice(0, 10)
}

const hydratePlanForms = () => {
  plans.value.forEach((plan) => {
    planForms[plan.id] = {
      name: plan.name || '',
      slug: plan.slug || '',
      description: plan.description || '',
      price: Number(plan.price || 0),
      max_products: plan.max_products ?? '',
      is_unlimited: plan.max_products === null,
      is_active: Boolean(plan.is_active),
      features: { ...(plan.features || {}) }
    }
  })
}

const hydrateCourtesyForms = () => {
  stores.value.forEach((store) => {
    courtesyForms[store.id] = {
      plan_id: store.plan_id || '',
      complimentary_until: normalizedDate(store.complimentary_until),
      complimentary_reason: store.complimentary_reason || ''
    }
  })
}

const fetchData = async () => {
  loading.value = true

  try {
    const [{ data: plansResponse }, { data: storesResponse }] = await Promise.all([
      api.get('/super-admin/plans'),
      api.get('/super-admin/stores')
    ])

    plans.value = Array.isArray(plansResponse) ? plansResponse : []
    stores.value = storesResponse.data || []

    hydratePlanForms()
    hydrateCourtesyForms()
  } catch (error) {
    console.error(error)
    showNotify('Erro ao carregar dados do super admin.', 'error')

    if (error.response?.status === 401 || error.response?.status === 403) {
      router.push('/login')
    }
  } finally {
    loading.value = false
  }
}

const filteredStores = computed(() => {
  const term = search.value.trim().toLowerCase()

  if (!term) return stores.value

  return stores.value.filter((store) => {
    return [
      store.name,
      store.slug,
      store.user?.name,
      store.user?.email,
      store.subscription_status
    ].some(value => String(value || '').toLowerCase().includes(term))
  })
})

const updatePlan = async (plan) => {
  const form = planForms[plan.id]

  savingPlan.value = plan.id

  try {
    const payload = {
      name: form.name,
      slug: form.slug,
      description: form.description,
      price: form.price,
      max_products: form.is_unlimited ? null : Number(form.max_products || 0),
      is_active: form.is_active,
      features: form.features
    }

    const { data } = await api.put(`/super-admin/plans/${plan.id}`, payload)
    const updatedPlan = data.plan
    const index = plans.value.findIndex(item => item.id === plan.id)

    if (index !== -1) {
      plans.value[index] = updatedPlan
    }

    hydratePlanForms()
    editingPlanId.value = null
    showNotify('Plano atualizado.')
  } catch (error) {
    console.error(error)
    showNotify(error.response?.data?.message || 'Erro ao atualizar plano.', 'error')
  } finally {
    savingPlan.value = null
  }
}

const grantCourtesy = async (store) => {
  const form = courtesyForms[store.id]

  savingStore.value = store.id

  try {
    const { data } = await api.patch(`/super-admin/stores/${store.id}/courtesy`, {
      plan_id: form.plan_id || null,
      complimentary_until: form.complimentary_until || null,
      complimentary_reason: form.complimentary_reason || null
    })

    const index = stores.value.findIndex(item => item.id === store.id)

    if (index !== -1) {
      stores.value[index] = data.store
    }

    hydrateCourtesyForms()
    showNotify('Cortesia aplicada.')
  } catch (error) {
    console.error(error)
    showNotify(error.response?.data?.message || 'Erro ao aplicar cortesia.', 'error')
  } finally {
    savingStore.value = null
  }
}

const logout = () => {
  localStorage.removeItem('auth_token')
  localStorage.removeItem('user_name')
  localStorage.removeItem('user_role')
  router.push('/login')
}

onMounted(fetchData)
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 md:flex-row md:items-center md:justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-950 text-white">
            <ShieldCheck size="24" />
          </div>

          <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Super Admin</p>
            <h1 class="text-2xl font-black tracking-tight">Controle da Plataforma</h1>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            @click="router.push('/dashboard')"
            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-black text-slate-700 transition hover:bg-slate-100"
          >
            <LayoutDashboard size="16" />
            Painel lojista
          </button>

          <button
            type="button"
            @click="logout"
            class="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white transition hover:bg-slate-800"
          >
            <LogOut size="16" />
            Sair
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8">
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

      <div class="mb-6 flex gap-2 border-b border-slate-200">
        <button
          type="button"
          @click="activeTab = 'plans'"
          :class="[
            'inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-black transition',
            activeTab === 'plans'
              ? 'border-red-600 text-red-600'
              : 'border-transparent text-slate-500 hover:text-slate-900'
          ]"
        >
          <BadgeCheck size="16" />
          Planos
        </button>

        <button
          type="button"
          @click="activeTab = 'courtesies'"
          :class="[
            'inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-black transition',
            activeTab === 'courtesies'
              ? 'border-red-600 text-red-600'
              : 'border-transparent text-slate-500 hover:text-slate-900'
          ]"
        >
          <Gift size="16" />
          Cortesias
        </button>
      </div>

      <div v-if="loading" class="flex justify-center py-20 text-red-600">
        <Loader2 class="animate-spin" size="40" />
      </div>

      <section v-else-if="activeTab === 'plans'" class="grid gap-4 lg:grid-cols-3">
        <article
          v-for="plan in plans"
          :key="plan.id"
          class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
        >
          <div class="mb-5 flex items-start justify-between gap-3">
            <div>
              <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">{{ plan.slug }}</p>
              <h2 class="text-xl font-black">{{ plan.name }}</h2>
            </div>

            <button
              type="button"
              @click="editingPlanId = editingPlanId === plan.id ? null : plan.id"
              class="rounded-xl border border-slate-200 p-2 text-slate-600 transition hover:bg-slate-50"
              title="Editar plano"
            >
              <Edit3 size="16" />
            </button>
          </div>

          <div v-if="editingPlanId !== plan.id" class="space-y-4">
            <p class="text-sm font-semibold leading-relaxed text-slate-500">{{ plan.description || 'Sem descrição.' }}</p>
            <p class="text-3xl font-black">
              R$ {{ Number(plan.price || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}
            </p>
            <p class="text-sm font-bold text-slate-600">
              {{ plan.max_products === null ? 'Produtos ilimitados' : `Até ${plan.max_products} produtos` }}
            </p>

            <div class="flex flex-wrap gap-2">
              <span
                v-for="(enabled, feature) in plan.features"
                :key="feature"
                :class="[
                  'rounded-full border px-3 py-1 text-[10px] font-black uppercase',
                  enabled ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-400'
                ]"
              >
                {{ featureLabels[feature] || feature }}
              </span>
            </div>
          </div>

          <form v-else class="space-y-4" @submit.prevent="updatePlan(plan)">
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Nome</span>
                <input v-model="planForms[plan.id].name" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
              </label>

              <label class="space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Slug</span>
                <input v-model="planForms[plan.id].slug" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
              </label>
            </div>

            <label class="block space-y-1">
              <span class="text-[10px] font-black uppercase text-slate-400">Descrição</span>
              <textarea v-model="planForms[plan.id].description" rows="3" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold focus:border-red-500 focus:ring-red-500"></textarea>
            </label>

            <div class="grid gap-3 sm:grid-cols-2">
              <label class="space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Preço</span>
                <input v-model.number="planForms[plan.id].price" type="number" min="0" step="0.01" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
              </label>

              <label class="space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Limite de produtos</span>
                <input
                  v-model.number="planForms[plan.id].max_products"
                  type="number"
                  min="0"
                  :disabled="planForms[plan.id].is_unlimited"
                  class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold disabled:opacity-50 focus:border-red-500 focus:ring-red-500"
                />
              </label>
            </div>

            <div class="grid gap-2">
              <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                <input v-model="planForms[plan.id].is_unlimited" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                Produtos ilimitados
              </label>

              <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                <input v-model="planForms[plan.id].is_active" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                Plano ativo
              </label>
            </div>

            <div class="grid gap-2">
              <label
                v-for="(_, feature) in planForms[plan.id].features"
                :key="feature"
                class="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs font-black text-slate-700"
              >
                <input v-model="planForms[plan.id].features[feature]" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                {{ featureLabels[feature] || feature }}
              </label>
            </div>

            <button
              type="submit"
              :disabled="savingPlan === plan.id"
              class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:opacity-50"
            >
              <Loader2 v-if="savingPlan === plan.id" class="animate-spin" size="16" />
              <Save v-else size="16" />
              Salvar plano
            </button>
          </form>
        </article>
      </section>

      <section v-else class="space-y-4">
        <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:flex-row md:items-center md:justify-between">
          <div>
            <h2 class="text-lg font-black">Cortesias por loja</h2>
            <p class="text-sm font-semibold text-slate-500">Data vazia significa cortesia sem prazo.</p>
          </div>

          <div class="relative w-full md:max-w-sm">
            <Search class="absolute left-3 top-3 h-4 w-4 text-slate-400" />
            <input
              v-model="search"
              type="search"
              placeholder="Buscar loja, dono, e-mail..."
              class="w-full rounded-xl border-slate-200 bg-slate-50 py-2 pl-10 pr-3 text-sm font-bold focus:border-red-500 focus:ring-red-500"
            />
          </div>
        </div>

        <article
          v-for="store in filteredStores"
          :key="store.id"
          class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
        >
          <div class="grid gap-4 lg:grid-cols-[1.4fr_2fr] lg:items-center">
            <div>
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-lg font-black">{{ store.name }}</h3>
                <span :class="['rounded-full border px-2 py-1 text-[10px] font-black uppercase', statusClass(store.subscription_status)]">
                  {{ statusLabel(store.subscription_status) }}
                </span>
              </div>

              <p class="mt-1 text-sm font-semibold text-slate-500">
                /{{ store.slug }} · {{ store.user?.name || 'Sem dono' }} · {{ store.user?.email || 'sem e-mail' }}
              </p>

              <p class="mt-2 text-xs font-bold text-slate-400">
                Plano atual: {{ store.plan?.name || 'Sem plano' }}
              </p>
            </div>

            <form class="grid gap-3 md:grid-cols-[1fr_1fr_1.3fr_auto]" @submit.prevent="grantCourtesy(store)">
              <label class="space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Plano</span>
                <select v-model="courtesyForms[store.id].plan_id" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500">
                  <option value="">Manter atual</option>
                  <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                </select>
              </label>

              <label class="space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Até</span>
                <div class="relative">
                  <CalendarDays class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                  <input v-model="courtesyForms[store.id].complimentary_until" type="date" class="w-full rounded-xl border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                </div>
              </label>

              <label class="space-y-1">
                <span class="text-[10px] font-black uppercase text-slate-400">Motivo</span>
                <input v-model="courtesyForms[store.id].complimentary_reason" placeholder="Ex: parceiro, teste interno..." class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
              </label>

              <button
                type="submit"
                :disabled="savingStore === store.id"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 py-2 text-sm font-black text-white transition hover:bg-slate-800 disabled:opacity-50 md:self-end"
              >
                <Loader2 v-if="savingStore === store.id" class="animate-spin" size="16" />
                <Gift v-else size="16" />
                Aplicar
              </button>
            </form>
          </div>
        </article>
      </section>
    </main>
  </div>
</template>
