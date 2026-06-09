<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import {
  BadgeCheck,
  BarChart3,
  CheckCircle,
  Edit3,
  Gift,
  Loader2,
  LogOut,
  Save,
  Search,
  ShieldCheck,
  Store,
  Users,
  WalletCards,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()

const loading = ref(true)
const savingPlan = ref(null)
const savingStore = ref(null)
const activeTab = ref('overview')
const toast = ref({ show: false, message: '', type: 'success' })
const plans = ref([])
const stores = ref([])
const summary = ref(null)
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

const menuItems = [
  { key: 'overview', label: 'Visão geral', icon: BarChart3 },
  { key: 'stores', label: 'Lojas', icon: Store },
  { key: 'plans', label: 'Planos', icon: BadgeCheck },
  { key: 'courtesies', label: 'Cortesias', icon: Gift }
]

const planForms = reactive({})
const courtesyForms = reactive({})

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3500)
}

const formatCurrency = (value) => {
  return Number(value || 0).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  })
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

  return labels[status] || status || 'Sem status'
}

const statusClass = (status) => {
  if (['active', 'trial', 'complimentary'].includes(status)) {
    return 'bg-emerald-50 text-emerald-700 border-emerald-100'
  }

  if (status === 'suspended' || status === 'canceled') {
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
    const [{ data: plansResponse }, { data: storesResponse }, { data: summaryResponse }] = await Promise.all([
      api.get('/super-admin/plans'),
      api.get('/super-admin/stores'),
      api.get('/super-admin/summary')
    ])

    plans.value = Array.isArray(plansResponse) ? plansResponse : []
    stores.value = storesResponse.data || []
    summary.value = summaryResponse || null

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
      store.subscription_status,
      store.plan?.name
    ].some(value => String(value || '').toLowerCase().includes(term))
  })
})

const dashboardStats = computed(() => {
  const cards = summary.value?.cards
  const totalStores = cards?.total_stores ?? stores.value.length
  const activeStores = cards?.active_stores ?? stores.value.filter(store => ['active', 'trial', 'complimentary'].includes(store.subscription_status)).length
  const complimentaryStores = cards?.complimentary_stores ?? stores.value.filter(store => store.subscription_status === 'complimentary').length
  const attentionStores = cards?.attention_stores ?? stores.value.filter(store => ['suspended', 'canceled', 'past_due'].includes(store.subscription_status)).length
  const estimatedMrr = cards?.estimated_mrr ?? stores.value.reduce((total, store) => {
    if (!['active'].includes(store.subscription_status)) return total
    return total + Number(store.plan?.price || 0)
  }, 0)

  return [
    {
      label: 'Lojas cadastradas',
      value: totalStores,
      description: 'Total de operações na plataforma',
      icon: Store,
      tone: 'bg-red-50 text-red-600'
    },
    {
      label: 'Lojas ativas',
      value: activeStores,
      description: 'Ativas, em teste ou cortesia',
      icon: CheckCircle,
      tone: 'bg-emerald-50 text-emerald-600'
    },
    {
      label: 'Cortesias',
      value: complimentaryStores,
      description: 'Contas liberadas manualmente',
      icon: Gift,
      tone: 'bg-amber-50 text-amber-600'
    },
    {
      label: 'MRR estimado',
      value: formatCurrency(estimatedMrr),
      description: 'Somente assinaturas ativas',
      icon: WalletCards,
      tone: 'bg-slate-100 text-slate-700'
    },
    {
      label: 'Atenção',
      value: attentionStores,
      description: 'Pendentes, canceladas ou suspensas',
      icon: XCircle,
      tone: 'bg-red-50 text-red-600'
    }
  ]
})

const storesByPlan = computed(() => {
  if (summary.value?.stores_by_plan) return summary.value.stores_by_plan

  return plans.value.map((plan) => ({
    ...plan,
    stores_count: stores.value.filter(store => Number(store.plan_id) === Number(plan.id)).length
  }))
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
    await fetchData()
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
    await fetchData()
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
  <div class="min-h-screen bg-orange-50/30 text-slate-900">
    <aside class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-slate-950 text-slate-400 shadow-2xl">
      <div class="flex items-center gap-3 p-6">
        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-500 text-white shadow-lg shadow-red-900/20">
          <ShieldCheck size="22" />
        </div>

        <span class="text-2xl font-black tracking-tighter text-white">
          Partiu<span class="text-red-500">Menu</span>
        </span>
      </div>

      <div class="mx-4 mb-4 rounded-2xl border border-white/10 bg-white/5 p-4">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Super Admin</p>
        <p class="mt-1 text-sm font-black text-white">Controle da Plataforma</p>
        <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-500">
          Gerencie planos, lojas, cortesias e indicadores comerciais.
        </p>
      </div>

      <nav class="flex-1 space-y-2 px-4">
        <button
          v-for="item in menuItems"
          :key="item.key"
          type="button"
          @click="activeTab = item.key"
          class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-left font-bold transition-all"
          :class="activeTab === item.key
            ? 'bg-red-500 text-white shadow-lg shadow-red-500/40'
            : 'hover:bg-white/5 hover:text-white'
          "
        >
          <component :is="item.icon" size="20" :class="activeTab === item.key ? 'text-white' : 'text-slate-500'" />
          <span>{{ item.label }}</span>
        </button>
      </nav>

      <div class="border-t border-white/5 p-4">
        <button
          type="button"
          @click="logout"
          class="flex w-full items-center gap-3 rounded-xl px-4 py-3 font-bold transition-all hover:bg-red-500/10 hover:text-red-500"
        >
          <LogOut size="20" />
          <span>Sair</span>
        </button>
      </div>
    </aside>

    <main class="ml-64 min-h-screen">
      <header class="sticky top-0 z-20 flex h-20 items-center justify-between border-b border-slate-200 bg-white px-8">
        <div>
          <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Super Admin</p>
          <h1 class="text-xl font-black tracking-tight text-slate-800">
            {{ menuItems.find(item => item.key === activeTab)?.label || 'Painel' }}
          </h1>
        </div>

        <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2">
          <Users size="18" class="text-red-600" />
          <div>
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Operação</p>
            <p class="text-xs font-black text-slate-700">{{ summary?.cards?.total_stores || stores.length }} lojas monitoradas</p>
          </div>
        </div>
      </header>

      <div class="p-8">
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

        <div v-if="loading" class="flex justify-center py-20 text-red-600">
          <Loader2 class="animate-spin" size="40" />
        </div>

        <section v-else-if="activeTab === 'overview'" class="space-y-6">
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Visão geral</p>
                <h2 class="mt-1 text-3xl font-black tracking-tight text-slate-950">Resumo comercial do PartiuMenu</h2>
                <p class="mt-2 max-w-2xl text-sm font-semibold leading-relaxed text-slate-500">
                  Acompanhe lojas, planos e oportunidades para suporte, marketing e expansão da plataforma.
                </p>
              </div>
            </div>
          </div>

          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <article
              v-for="stat in dashboardStats"
              :key="stat.label"
              class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
              <div :class="['mb-4 flex h-11 w-11 items-center justify-center rounded-2xl', stat.tone]">
                <component :is="stat.icon" size="22" />
              </div>
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ stat.label }}</p>
              <p class="mt-1 text-2xl font-black text-slate-950">{{ stat.value }}</p>
              <p class="mt-1 text-xs font-semibold text-slate-500">{{ stat.description }}</p>
            </article>
          </div>

          <div class="grid gap-6 xl:grid-cols-[1fr_0.8fr]">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
              <div class="mb-5 flex items-center justify-between gap-4">
                <div>
                  <h2 class="text-lg font-black text-slate-950">Lojas por plano</h2>
                  <p class="text-sm font-semibold text-slate-500">Distribuição atual para decisões de marketing e upgrade.</p>
                </div>
                <BarChart3 class="text-red-600" size="24" />
              </div>

              <div class="space-y-4">
                <div v-for="plan in storesByPlan" :key="plan.id">
                  <div class="mb-2 flex items-center justify-between text-sm font-black text-slate-700">
                    <span>{{ plan.name }}</span>
                    <span>{{ plan.stores_count }}</span>
                  </div>
                  <div class="h-3 overflow-hidden rounded-full bg-slate-100">
                    <div
                      class="h-full rounded-full bg-red-600"
                      :style="{ width: `${stores.length ? Math.round((plan.stores_count / stores.length) * 100) : 0}%` }"
                    ></div>
                  </div>
                </div>
              </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
              <h2 class="text-lg font-black text-slate-950">Próximas melhorias</h2>
              <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">
                Para chegar no nível ideal de marketing e BI, o próximo passo é trazer total de pedidos, GMV/faturamento por loja,
                pedidos atrasados e eventos de pagamento para este painel.
              </p>
              <div class="mt-5 space-y-3 text-sm font-bold text-slate-600">
                <p>• Pedidos por loja e por período</p>
                <p>• Faturamento total processado</p>
                <p>• Exportação de relatório em planilha</p>
                <p>• Funil de trial para plano pago</p>
              </div>
            </section>
          </div>
        </section>

        <section v-else-if="activeTab === 'stores'" class="space-y-5">
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Lojas</p>
                <h2 class="mt-1 text-2xl font-black text-slate-950">Operações cadastradas</h2>
                <p class="mt-1 text-sm font-semibold text-slate-500">Busque lojas por nome, dono, email, plano ou status.</p>
              </div>

              <div class="relative w-full md:max-w-sm">
                <Search class="absolute left-4 top-3.5 text-slate-400" size="18" />
                <input
                  v-model="search"
                  type="text"
                  placeholder="Buscar loja, dono ou email"
                  class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm font-bold outline-none transition focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-100"
                >
              </div>
            </div>
          </div>

          <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="grid grid-cols-[1.1fr_1fr_0.8fr_0.8fr] gap-4 border-b border-slate-100 bg-slate-50 px-6 py-3 text-[10px] font-black uppercase tracking-widest text-slate-400">
              <span>Loja</span>
              <span>Responsável</span>
              <span>Plano</span>
              <span>Status</span>
            </div>

            <div v-if="filteredStores.length === 0" class="p-10 text-center text-sm font-bold text-slate-400">
              Nenhuma loja encontrada.
            </div>

            <div v-else class="divide-y divide-slate-100">
              <div
                v-for="store in filteredStores"
                :key="store.id"
                class="grid grid-cols-[1.1fr_1fr_0.8fr_0.8fr] gap-4 px-6 py-4 text-sm"
              >
                <div>
                  <p class="font-black text-slate-900">{{ store.name }}</p>
                  <p class="text-xs font-semibold text-slate-400">/{{ store.slug }}</p>
                </div>
                <div>
                  <p class="font-bold text-slate-700">{{ store.user?.name || 'Sem usuário' }}</p>
                  <p class="text-xs font-semibold text-slate-400">{{ store.user?.email || '-' }}</p>
                </div>
                <div>
                  <p class="font-black text-slate-800">{{ store.plan?.name || 'Sem plano' }}</p>
                  <p class="text-xs font-semibold text-slate-400">{{ formatCurrency(store.plan?.price || 0) }}/mês</p>
                </div>
                <div>
                  <span :class="['rounded-full border px-3 py-1 text-[10px] font-black uppercase', statusClass(store.subscription_status)]">
                    {{ statusLabel(store.subscription_status) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </section>

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
              <p class="text-3xl font-black">{{ formatCurrency(plan.price) }}</p>
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

              <label class="space-y-1 block">
                <span class="text-[10px] font-black uppercase text-slate-400">Descrição</span>
                <textarea v-model="planForms[plan.id].description" rows="3" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500"></textarea>
              </label>

              <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Preço</span>
                  <input v-model="planForms[plan.id].price" type="number" step="0.01" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                </label>

                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Limite produtos</span>
                  <input v-model="planForms[plan.id].max_products" :disabled="planForms[plan.id].is_unlimited" type="number" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold disabled:opacity-40 focus:border-red-500 focus:ring-red-500" />
                </label>
              </div>

              <label class="flex items-center gap-2 text-sm font-bold text-slate-600">
                <input v-model="planForms[plan.id].is_unlimited" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                Produtos ilimitados
              </label>

              <label class="flex items-center gap-2 text-sm font-bold text-slate-600">
                <input v-model="planForms[plan.id].is_active" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                Plano ativo
              </label>

              <div class="grid gap-2">
                <label v-for="(_, feature) in planForms[plan.id].features" :key="feature" class="flex items-center gap-2 text-xs font-bold text-slate-600">
                  <input v-model="planForms[plan.id].features[feature]" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
                  {{ featureLabels[feature] || feature }}
                </label>
              </div>

              <button
                type="submit"
                :disabled="savingPlan === plan.id"
                class="flex w-full items-center justify-center gap-2 rounded-2xl bg-red-600 px-4 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:opacity-60"
              >
                <Loader2 v-if="savingPlan === plan.id" class="animate-spin" size="16" />
                <Save v-else size="16" />
                Salvar plano
              </button>
            </form>
          </article>
        </section>

        <section v-else-if="activeTab === 'courtesies'" class="space-y-5">
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Cortesias</p>
                <h2 class="mt-1 text-2xl font-black text-slate-950">Liberar lojas manualmente</h2>
                <p class="mt-1 text-sm font-semibold text-slate-500">Use para clientes piloto, parceiros, demonstrações e suporte comercial.</p>
              </div>

              <div class="relative w-full md:max-w-sm">
                <Search class="absolute left-4 top-3.5 text-slate-400" size="18" />
                <input
                  v-model="search"
                  type="text"
                  placeholder="Buscar loja"
                  class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm font-bold outline-none transition focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-100"
                >
              </div>
            </div>
          </div>

          <div class="grid gap-4 xl:grid-cols-2">
            <article
              v-for="store in filteredStores"
              :key="store.id"
              class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
            >
              <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                  <p class="text-lg font-black text-slate-950">{{ store.name }}</p>
                  <p class="text-xs font-bold text-slate-400">{{ store.user?.email || 'sem email' }}</p>
                </div>
                <span :class="['rounded-full border px-3 py-1 text-[10px] font-black uppercase', statusClass(store.subscription_status)]">
                  {{ statusLabel(store.subscription_status) }}
                </span>
              </div>

              <div class="grid gap-3 md:grid-cols-3">
                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Plano</span>
                  <select v-model="courtesyForms[store.id].plan_id" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500">
                    <option value="">Manter atual</option>
                    <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                  </select>
                </label>

                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Até</span>
                  <input v-model="courtesyForms[store.id].complimentary_until" type="date" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                </label>

                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Motivo</span>
                  <input v-model="courtesyForms[store.id].complimentary_reason" placeholder="Cliente piloto" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                </label>
              </div>

              <button
                type="button"
                :disabled="savingStore === store.id"
                @click="grantCourtesy(store)"
                class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-black text-white transition hover:bg-slate-800 disabled:opacity-60"
              >
                <Loader2 v-if="savingStore === store.id" class="animate-spin" size="16" />
                <Gift v-else size="16" />
                Aplicar cortesia
              </button>
            </article>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>
