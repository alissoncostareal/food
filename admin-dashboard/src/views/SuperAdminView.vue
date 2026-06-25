<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { clearAuthSession } from '@/utils/authSession'
import { featureLabels, normalizePlanFeatures, orderedFeatureKeys } from '@/constants/planFeatures'
import SuperAdminLandingSection from '@/components/super-admin/SuperAdminLandingSection.vue'
import SuperAdminModulesSection from '@/components/super-admin/SuperAdminModulesSection.vue'
import SuperAdminWhatsappSection from '@/components/super-admin/SuperAdminWhatsappSection.vue'
import {
  AlertTriangle,
  BadgeCheck,
  BarChart3,
  Building2,
  Construction,
  CheckCircle,
  Edit3,
  Eye,
  EyeOff,
  Gift,
  Loader2,
  Lock,
  LogOut,
  MessageCircle,
  Save,
  Search,
  Settings,
  ShieldCheck,
  Store,
  Globe,
  Unlock,
  Users,
  WalletCards,
  Wallet,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()

const validSections = new Set(['overview', 'stores', 'plans', 'settings', 'modules', 'courtesies', 'landing', 'integration-logs', 'whatsapp'])

const loading = ref(true)
const savingPlan = ref(null)
const togglingPlanVisibility = ref(null)
const savingStore = ref(null)
const seedingDemoStore = ref(null)
const blockingStore = ref(null)
const panelToggleModal = ref({
  open: false,
  store: null,
  nextStatus: null,
  password: '',
  error: ''
})
const courtesyModal = ref({
  open: false,
  store: null,
  plan_id: '',
  complimentary_until: '',
  complimentary_reason: '',
  password: '',
  error: ''
})
const revokeCourtesyModal = ref({
  open: false,
  store: null,
  password: '',
  error: ''
})
const detachModal = ref({
  open: false,
  store: null,
  password: '',
  error: ''
})
const demoDashboardModal = ref({
  open: false,
  store: null,
  password: '',
  clear_existing: true,
  error: ''
})

const courtesyDurationPresets = [
  { label: '7 dias', days: 7 },
  { label: '14 dias', days: 14 },
  { label: '30 dias', days: 30 },
  { label: '60 dias', days: 60 },
  { label: '90 dias', days: 90 }
]
const toast = ref({ show: false, message: '', type: 'success' })
const plans = ref([])
const stores = ref([])
const summary = ref(null)
const search = ref('')
const editingPlanId = ref(null)
const platformSettings = ref([])
const paymentProviders = ref([])
const paymentProvidersForm = reactive({})
const demoStoreSlugs = ref(['lojademo'])
const settingsForm = reactive({})
const savingSettings = ref(false)
const integrationLogs = ref([])
const loadingIntegrationLogs = ref(false)
const integrationLogFilter = ref('')

const menuItems = [
  { key: 'overview', label: 'Visão geral', icon: BarChart3 },
  { key: 'stores', label: 'Lojas', icon: Store },
  { key: 'plans', label: 'Planos', icon: BadgeCheck },
  { key: 'settings', label: 'Configurações', icon: Settings },
  { key: 'modules', label: 'Módulos', icon: Construction },
  { key: 'landing', label: 'Landing page', icon: Globe },
  { key: 'whatsapp', label: 'WhatsApp', icon: MessageCircle },
  { key: 'integration-logs', label: 'Logs integração', icon: AlertTriangle },
  { key: 'courtesies', label: 'Cortesias', icon: Gift }
]

const activeTab = computed(() => {
  const section = String(route.params.section || 'overview')
  return validSections.has(section) ? section : 'overview'
})

const isActiveTab = (key) => activeTab.value === key

const planForms = reactive({})
const courtesyForms = reactive({})
const corePlanSlugs = ['trial', 'starter', 'pro', 'premium']

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
    trial: 'Em teste',
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

const storeHasPanelAccess = (store) =>
  Boolean(store.panel_access?.has_panel_access ?? store.has_active_subscription)

const panelAccessClass = (store) => {
  if (store.subscription_status === 'complimentary' && storeHasPanelAccess(store)) {
    return 'bg-violet-50 text-violet-700 border-violet-100'
  }

  if (storeHasPanelAccess(store)) {
    return 'bg-emerald-50 text-emerald-700 border-emerald-100'
  }

  if (store.panel_access?.blocked_reason === 'blocked_by_admin') {
    return 'bg-red-50 text-red-700 border-red-100'
  }

  return 'bg-amber-50 text-amber-700 border-amber-100'
}

const panelAccessLabel = (store) => {
  if (store.subscription_status === 'complimentary' && store.complimentary_until) {
    return `Cortesia até ${formatDate(store.complimentary_until)}`
  }

  if (storeHasPanelAccess(store)) {
    return 'Painel liberado'
  }

  return store.panel_access?.blocked_label || 'Painel bloqueado'
}

const isComplimentaryStore = (store) => store.subscription_status === 'complimentary'

const hadCourtesyStore = (store) =>
  store.subscription_status === 'past_due' && !store.pagarme_subscription_id

const canRevokeCourtesy = (store) => isComplimentaryStore(store) || hadCourtesyStore(store)

const isFilialStore = (store) => store.store_type === 'filial'

const storeTypeLabel = (store) => (isFilialStore(store) ? 'Filial' : 'Matriz')

const storeTypeClass = (store) => (
  isFilialStore(store)
    ? 'bg-sky-50 text-sky-700 border-sky-100'
    : 'bg-slate-100 text-slate-700 border-slate-200'
)

const courtesyStores = computed(() =>
  stores.value.filter(store => store.subscription_status === 'complimentary')
)

const assignablePlans = computed(() =>
  plans.value.filter(plan => plan.is_active && plan.slug !== 'trial')
)

const addDaysToDate = (days) => {
  const date = new Date()
  date.setDate(date.getDate() + days)
  return date.toISOString().slice(0, 10)
}

const panelAccessHint = (store) => {
  if (storeHasPanelAccess(store)) {
    return null
  }

  if (store.panel_access?.blocked_reason === 'subscription_expired' && store.subscription_ends_at) {
    return `Venceu em ${formatDate(store.subscription_ends_at)}. Libere o acesso para renovar por 30 dias.`
  }

  if (store.is_within_payment_grace && store.payment_grace_ends_at) {
    return `Período de tolerância até ${formatDate(store.payment_grace_ends_at)}`
  }

  if (store.subscription_status === 'active' && !storeHasPanelAccess(store)) {
    return 'Status administrativo ativo, mas o acesso expirou.'
  }

  return null
}

const isPanelToggleOn = (store) => storeHasPanelAccess(store)

const formatDate = (value) => {
  if (!value) return '—'

  const date = new Date(String(value).slice(0, 10) + 'T12:00:00')

  return date.toLocaleDateString('pt-BR')
}

const normalizedDate = (value) => {
  if (!value) return ''

  return String(value).slice(0, 10)
}

const isCorePlan = (plan) => corePlanSlugs.includes(plan?.slug)

const hydratePlanForms = () => {
  plans.value.forEach((plan) => {
    planForms[plan.id] = {
      name: plan.name || '',
      slug: plan.slug || '',
      description: plan.description || '',
      price: Number(plan.price || 0),
      launch_price: plan.launch_price ?? '',
      launch_slots: plan.launch_slots ?? '',
      launch_price_months: plan.launch_price_months ?? 12,
      max_products: plan.max_products ?? '',
      max_stores: plan.max_stores ?? 1,
      max_team_members: plan.max_team_members ?? 0,
      is_unlimited: plan.max_products === null,
      is_active: Boolean(plan.is_active),
      features: normalizePlanFeatures(plan.features || {})
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

const hydrateSettingsForm = () => {
  platformSettings.value.forEach((setting) => {
    settingsForm[setting.key] = setting.value
  })

  paymentProviders.value.forEach((provider) => {
    paymentProvidersForm[provider.key] = Boolean(provider.enabled)
  })
}

const fetchIntegrationLogs = async () => {
  loadingIntegrationLogs.value = true

  try {
    const params = { per_page: 50 }

    if (integrationLogFilter.value) {
      params.channel = integrationLogFilter.value
    }

    const { data } = await api.get('/super-admin/integration-errors', { params })
    integrationLogs.value = data.data || []
  } catch (error) {
    console.error(error)
    showNotify('Erro ao carregar logs de integração.', 'error')
  } finally {
    loadingIntegrationLogs.value = false
  }
}

watch(activeTab, (tab) => {
  if (tab === 'integration-logs') {
    fetchIntegrationLogs()
  }
})

const fetchData = async () => {
  loading.value = true

  try {
    const [
      { data: plansResponse },
      { data: storesResponse },
      { data: summaryResponse },
      { data: settingsResponse }
    ] = await Promise.all([
      api.get('/super-admin/plans'),
      api.get('/super-admin/stores', { params: { per_page: 100 } }),
      api.get('/super-admin/summary'),
      api.get('/super-admin/settings')
    ])

    plans.value = Array.isArray(plansResponse) ? plansResponse : []
    stores.value = storesResponse.data || []
    summary.value = summaryResponse || null
    platformSettings.value = settingsResponse.settings || []
    paymentProviders.value = settingsResponse.payment_providers || []
    demoStoreSlugs.value = settingsResponse.demo_store_slugs || ['lojademo']

    hydratePlanForms()
    hydrateCourtesyForms()
    hydrateSettingsForm()
  } catch (error) {
    console.error(error)
    showNotify('Erro ao carregar dados do super admin.', 'error')

    if (error.response?.status === 401) {
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

const statusDistribution = computed(() => summary.value?.status_distribution || [])
const summaryCards = computed(() => summary.value?.cards || {})

const updatePlan = async (plan) => {
  const form = planForms[plan.id]

  savingPlan.value = plan.id

  try {
    const payload = {
      name: form.name,
      slug: form.slug,
      description: form.description,
      price: form.price,
      launch_price: form.launch_price === '' ? null : Number(form.launch_price),
      launch_slots: form.launch_slots === '' ? null : Number(form.launch_slots),
      launch_price_months: Number(form.launch_price_months || 12),
      max_products: form.is_unlimited ? null : Number(form.max_products || 0),
      max_stores: Number(form.max_stores || 1),
      max_team_members: Number(form.max_team_members ?? 0),
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

const togglePlanVisibility = async (plan) => {
  togglingPlanVisibility.value = plan.id

  try {
    const { data } = await api.patch(`/super-admin/plans/${plan.id}/visibility`)
    const updatedPlan = data.plan
    const index = plans.value.findIndex(item => item.id === plan.id)

    if (index !== -1) {
      plans.value[index] = updatedPlan
    }

    hydratePlanForms()
    showNotify(data.message || (updatedPlan.is_visible ? 'Plano visível.' : 'Plano oculto.'))
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao alterar visibilidade do plano.', 'error')
  } finally {
    togglingPlanVisibility.value = null
  }
}

const updatePlatformSettings = async () => {
  savingSettings.value = true

  try {
    const payload = {}

    platformSettings.value.forEach((setting) => {
      payload[setting.key] = Number(settingsForm[setting.key] ?? setting.value)
    })

    payload.payment_providers_enabled = paymentProviders.value
      .filter((provider) => paymentProvidersForm[provider.key])
      .map((provider) => provider.key)

    const { data } = await api.put('/super-admin/settings', payload)
    platformSettings.value = platformSettings.value.map((setting) => ({
      ...setting,
      value: data.settings?.[setting.key] ?? settingsForm[setting.key]
    }))
    paymentProviders.value = data.payment_providers || paymentProviders.value
    hydrateSettingsForm()
    showNotify('Configurações salvas.')
  } catch (error) {
    console.error(error)
    showNotify(error.response?.data?.message || 'Erro ao salvar configurações.', 'error')
  } finally {
    savingSettings.value = false
  }
}

const openPanelToggleModal = (store) => {
  const enabling = !storeHasPanelAccess(store)

  panelToggleModal.value = {
    open: true,
    store,
    nextStatus: enabling ? 'active' : 'suspended',
    password: '',
    error: ''
  }
}

const closePanelToggleModal = () => {
  panelToggleModal.value = {
    open: false,
    store: null,
    nextStatus: null,
    password: '',
    error: ''
  }
}

const confirmPanelToggle = async () => {
  const { store, nextStatus, password } = panelToggleModal.value

  if (!store || !nextStatus) return

  if (!password.trim()) {
    panelToggleModal.value.error = 'Informe sua senha de super admin.'
    return
  }

  blockingStore.value = store.id
  panelToggleModal.value.error = ''

  try {
    const { data } = await api.patch(`/super-admin/stores/${store.id}/subscription`, {
      subscription_status: nextStatus,
      plan_id: store.plan_id || null,
      password
    })

    const index = stores.value.findIndex(item => item.id === store.id)

    if (index !== -1) {
      stores.value[index] = data.store
    }

    showNotify(data.message || (nextStatus === 'active' ? 'Painel liberado.' : 'Painel bloqueado.'))
    closePanelToggleModal()
  } catch (error) {
    console.error(error)

    const passwordError = error.response?.data?.errors?.password?.[0]

    panelToggleModal.value.error = passwordError
      || error.response?.data?.message
      || 'Erro ao atualizar acesso ao painel.'
  } finally {
    blockingStore.value = null
  }
}

const openCourtesyModal = (store) => {
  const existing = courtesyForms[store.id] || {}

  courtesyModal.value = {
    open: true,
    store,
    plan_id: existing.plan_id || store.plan_id || assignablePlans.value[0]?.id || '',
    complimentary_until: existing.complimentary_until || addDaysToDate(30),
    complimentary_reason: existing.complimentary_reason || '',
    password: '',
    error: ''
  }
}

const closeCourtesyModal = () => {
  courtesyModal.value = {
    open: false,
    store: null,
    plan_id: '',
    complimentary_until: '',
    complimentary_reason: '',
    password: '',
    error: ''
  }
}

const setCourtesyDuration = (days) => {
  courtesyModal.value.complimentary_until = addDaysToDate(days)
}

const confirmCourtesy = async () => {
  const { store, plan_id, complimentary_until, complimentary_reason, password } = courtesyModal.value

  if (!store) return

  if (!plan_id) {
    courtesyModal.value.error = 'Selecione o plano da cortesia.'
    return
  }

  if (!complimentary_until) {
    courtesyModal.value.error = 'Informe até quando a cortesia vale.'
    return
  }

  if (!password.trim()) {
    courtesyModal.value.error = 'Informe sua senha de super admin.'
    return
  }

  savingStore.value = store.id
  courtesyModal.value.error = ''

  try {
    const { data } = await api.patch(`/super-admin/stores/${store.id}/courtesy`, {
      plan_id,
      complimentary_until,
      complimentary_reason: complimentary_reason || null,
      password
    })

    const index = stores.value.findIndex(item => item.id === store.id)

    if (index !== -1) {
      stores.value[index] = data.store
    }

    hydrateCourtesyForms()
    showNotify(data.message || 'Cortesia aplicada.')
    closeCourtesyModal()
  } catch (error) {
    console.error(error)

    const passwordError = error.response?.data?.errors?.password?.[0]
    const planError = error.response?.data?.errors?.plan_id?.[0]
    const untilError = error.response?.data?.errors?.complimentary_until?.[0]

    courtesyModal.value.error = passwordError
      || planError
      || untilError
      || error.response?.data?.message
      || error.response?.data?.error
      || 'Erro ao aplicar cortesia.'
  } finally {
    savingStore.value = null
  }
}

const openRevokeCourtesyModal = (store) => {
  revokeCourtesyModal.value = {
    open: true,
    store,
    password: '',
    error: ''
  }
}

const closeRevokeCourtesyModal = () => {
  revokeCourtesyModal.value = {
    open: false,
    store: null,
    password: '',
    error: ''
  }
}

const confirmRevokeCourtesy = async () => {
  const { store, password } = revokeCourtesyModal.value

  if (!store) return

  if (!password.trim()) {
    revokeCourtesyModal.value.error = 'Informe sua senha de super admin.'
    return
  }

  savingStore.value = store.id
  revokeCourtesyModal.value.error = ''

  try {
    const { data } = await api.delete(`/super-admin/stores/${store.id}/courtesy`, {
      data: { password }
    })

    const index = stores.value.findIndex(item => item.id === store.id)

    if (index !== -1) {
      stores.value[index] = data.store
    }

    hydrateCourtesyForms()
    showNotify(data.message || 'Cortesia removida.')
    closeRevokeCourtesyModal()
  } catch (error) {
    console.error(error)

    revokeCourtesyModal.value.error = error.response?.data?.errors?.password?.[0]
      || error.response?.data?.message
      || error.response?.data?.error
      || 'Erro ao remover cortesia.'
  } finally {
    savingStore.value = null
  }
}

const openDetachModal = (store) => {
  detachModal.value = {
    open: true,
    store,
    password: '',
    error: ''
  }
}

const closeDetachModal = () => {
  detachModal.value = {
    open: false,
    store: null,
    password: '',
    error: ''
  }
}

const confirmDetachBranch = async () => {
  const { store, password } = detachModal.value

  if (!store) return

  if (!password.trim()) {
    detachModal.value.error = 'Informe sua senha de super admin.'
    return
  }

  savingStore.value = store.id
  detachModal.value.error = ''

  try {
    const { data } = await api.patch(`/super-admin/stores/${store.id}/detach-branch`, { password })

    const index = stores.value.findIndex(item => item.id === store.id)

    if (index !== -1) {
      stores.value[index] = data.store
    }

    showNotify(data.message || 'Filial desvinculada.')
    closeDetachModal()
  } catch (error) {
    console.error(error)

    detachModal.value.error = error.response?.data?.errors?.password?.[0]
      || error.response?.data?.message
      || error.response?.data?.error
      || 'Erro ao desvincular filial.'
  } finally {
    savingStore.value = null
  }
}

const openDemoDashboardModal = (store) => {
  demoDashboardModal.value = {
    open: true,
    store,
    password: '',
    clear_existing: true,
    error: ''
  }
}

const closeDemoDashboardModal = () => {
  demoDashboardModal.value = {
    open: false,
    store: null,
    password: '',
    clear_existing: true,
    error: ''
  }
}

const confirmSeedDemoDashboard = async () => {
  const { store, password, clear_existing } = demoDashboardModal.value

  if (!store) return

  if (!password.trim()) {
    demoDashboardModal.value.error = 'Informe sua senha de super admin.'
    return
  }

  seedingDemoStore.value = store.id
  demoDashboardModal.value.error = ''

  try {
    const { data } = await api.post(`/super-admin/stores/${store.id}/demo-dashboard`, {
      password,
      clear_existing
    })

    showNotify(data.message || 'Dashboard populado com pedidos demo.')
    closeDemoDashboardModal()
  } catch (error) {
    console.error(error)

    demoDashboardModal.value.error = error.response?.data?.errors?.password?.[0]
      || error.response?.data?.errors?.store?.[0]
      || error.response?.data?.message
      || error.response?.data?.error
      || 'Erro ao popular dashboard demo.'
  } finally {
    seedingDemoStore.value = null
  }
}

const logout = () => {
  clearAuthSession()
  router.push('/login')
}

onMounted(fetchData)

watch(
  () => route.params.section,
  (section) => {
    if (section && !validSections.has(String(section))) {
      router.replace('/super-admin/overview')
    }
  },
  { immediate: true }
)
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <aside class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-slate-950 text-slate-400 shadow-2xl">
      <div class="p-6">
        <img src="/logo-color.png" alt="PartiuMenu" class="h-10 w-auto max-w-[200px] object-contain" />
      </div>

      <div class="mx-4 mb-4 rounded-2xl border border-white/10 bg-white/5 p-4">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Super Admin</p>
        <p class="mt-1 text-sm font-black text-white">Controle da Plataforma</p>
        <p class="mt-2 text-[11px] font-bold leading-relaxed text-slate-500">
          Gerencie planos, lojas, cortesias e indicadores comerciais.
        </p>
      </div>

      <nav class="flex-1 space-y-2 px-4">
        <router-link
          v-for="item in menuItems"
          :key="item.key"
          :to="`/super-admin/${item.key}`"
          class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-left font-bold transition-all"
          :class="isActiveTab(item.key)
            ? 'bg-red-500 text-white shadow-lg shadow-red-500/40'
            : 'hover:bg-white/5 hover:text-white'
          "
        >
          <component :is="item.icon" size="20" :class="isActiveTab(item.key) ? 'text-white' : 'text-slate-500'" />
          <span>{{ item.label }}</span>
        </router-link>
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
                  Acompanhe lojas, planos, pedidos, faturamento e oportunidades para suporte, marketing e expansão.
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
              <h2 class="text-lg font-black text-slate-950">Funil e operação</h2>
              <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">
                Acompanhe conversão de status, pedidos recentes e faturamento processado no mês.
              </p>

              <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl bg-slate-50 p-4">
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Pedidos do mês</p>
                  <p class="mt-1 text-2xl font-black text-slate-950">{{ summaryCards.month_orders || 0 }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Receita do mês</p>
                  <p class="mt-1 text-2xl font-black text-slate-950">{{ formatCurrency(summaryCards.month_revenue) }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Últimos 30 dias</p>
                  <p class="mt-1 text-2xl font-black text-slate-950">{{ summaryCards.last_30_orders || 0 }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Lojas em teste</p>
                  <p class="mt-1 text-2xl font-black text-slate-950">{{ summaryCards.trial_stores || 0 }}</p>
                </div>
              </div>

              <div class="mt-5 space-y-3">
                <div v-for="item in statusDistribution" :key="item.status">
                  <div class="mb-1 flex items-center justify-between text-xs font-black text-slate-600">
                    <span>{{ item.label }}</span>
                    <span>{{ item.count }}</span>
                  </div>
                  <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div
                      class="h-full rounded-full bg-red-600"
                      :style="{ width: `${summaryCards.total_stores ? Math.max(5, Math.round((item.count / summaryCards.total_stores) * 100)) : 0}%` }"
                    ></div>
                  </div>
                </div>
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
                <p class="mt-1 text-sm font-semibold text-slate-500">
                  Assinatura e acesso ao painel são coisas diferentes. Filiais herdam plano e bloqueio da matriz — desvincule antes de restringir só a matriz.
                </p>
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

          <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold leading-relaxed text-slate-600">
            <strong class="text-slate-800">Matriz e filiais:</strong> bloquear a matriz bloqueia todas as filiais vinculadas.
            Se a matriz sair do projeto e a filial quiser continuar, use <strong class="text-sky-700">Tornar independente</strong> na filial antes de restringir a matriz.
          </div>

          <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="border-b border-slate-100 bg-slate-50 text-left text-[10px] font-black uppercase tracking-widest text-slate-400">
                  <tr>
                    <th class="px-4 py-3">Loja</th>
                    <th class="px-4 py-3">Tipo</th>
                    <th class="px-4 py-3">Responsável</th>
                    <th class="px-4 py-3">Plano</th>
                    <th class="px-4 py-3">Assinatura</th>
                    <th class="px-4 py-3">Acesso</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-if="filteredStores.length === 0">
                    <td colspan="7" class="px-4 py-10 text-center text-sm font-bold text-slate-400">
                      Nenhuma loja encontrada.
                    </td>
                  </tr>
                  <tr
                    v-for="store in filteredStores"
                    :key="store.id"
                    class="hover:bg-slate-50/80"
                  >
                    <td class="px-4 py-3 align-top">
                      <p class="font-black text-slate-900">{{ store.name }}</p>
                      <p class="text-xs font-semibold text-slate-400">/{{ store.slug }}</p>
                      <p
                        v-if="store.branches_count > 0"
                        class="mt-1 text-[10px] font-bold text-slate-500"
                      >
                        {{ store.branches_count }} filial(is)
                      </p>
                    </td>
                    <td class="px-4 py-3 align-top">
                      <span :class="['inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase', storeTypeClass(store)]">
                        {{ storeTypeLabel(store) }}
                      </span>
                      <p
                        v-if="store.parent_store"
                        class="mt-1 max-w-[120px] truncate text-[10px] font-bold text-slate-400"
                        :title="store.parent_store.name"
                      >
                        de {{ store.parent_store.name }}
                      </p>
                    </td>
                    <td class="px-4 py-3 align-top">
                      <p class="font-bold text-slate-700">{{ store.user?.name || 'Sem usuário' }}</p>
                      <p class="max-w-[180px] truncate text-xs font-semibold text-slate-400">{{ store.user?.email || '—' }}</p>
                    </td>
                    <td class="px-4 py-3 align-top">
                      <p class="font-black text-slate-800">{{ store.plan?.name || 'Sem plano' }}</p>
                      <p class="text-xs font-semibold text-slate-400">{{ formatCurrency(store.plan?.price || 0) }}/mês</p>
                    </td>
                    <td class="px-4 py-3 align-top">
                      <span :class="['inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase', statusClass(store.subscription_status)]">
                        {{ statusLabel(store.subscription_status) }}
                      </span>
                      <p
                        v-if="isComplimentaryStore(store) && store.complimentary_until"
                        class="mt-1 text-[10px] font-bold text-violet-600"
                      >
                        Cortesia até {{ formatDate(store.complimentary_until) }}
                      </p>
                    </td>
                    <td class="px-4 py-3 align-top">
                      <span :class="['inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase', panelAccessClass(store)]">
                        {{ panelAccessLabel(store) }}
                      </span>
                      <p
                        v-if="store.subscription_ends_at && !isComplimentaryStore(store)"
                        class="mt-1 text-[10px] font-bold text-slate-400"
                      >
                        Até {{ formatDate(store.subscription_ends_at) }}
                      </p>
                      <p
                        v-if="panelAccessHint(store)"
                        class="mt-1 max-w-[220px] text-[10px] font-semibold leading-relaxed text-slate-500"
                      >
                        {{ panelAccessHint(store) }}
                      </p>
                    </td>
                    <td class="px-4 py-3 align-top text-right">
                      <div class="inline-flex w-[178px] flex-col gap-1.5 text-left">
                        <button
                          type="button"
                          :disabled="seedingDemoStore === store.id"
                          class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-xl border border-amber-200 bg-amber-50 px-3 text-[10px] font-black uppercase text-amber-800 transition hover:bg-amber-100 disabled:opacity-50"
                          @click="openDemoDashboardModal(store)"
                        >
                          <Loader2 v-if="seedingDemoStore === store.id" class="animate-spin" size="12" />
                          <BarChart3 v-else size="12" />
                          Dashboard demo
                        </button>
                        <button
                          type="button"
                          :disabled="blockingStore === store.id"
                          class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-xl border px-3 text-[10px] font-black uppercase transition disabled:opacity-50"
                          :class="isPanelToggleOn(store)
                            ? 'border-red-600 bg-red-600 text-white hover:bg-red-700'
                            : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                          @click="openPanelToggleModal(store)"
                        >
                          <Loader2 v-if="blockingStore === store.id" class="animate-spin" size="12" />
                          <Lock v-else-if="isPanelToggleOn(store)" size="12" />
                          <Unlock v-else size="12" />
                          {{ isPanelToggleOn(store) ? 'Bloquear' : 'Liberar' }}
                        </button>
                        <button
                          type="button"
                          class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-xl border border-emerald-600 bg-emerald-600 px-3 text-[10px] font-black uppercase text-white transition hover:bg-emerald-700"
                          @click="openCourtesyModal(store)"
                        >
                          <Gift size="12" />
                          {{ isComplimentaryStore(store) ? 'Renovar' : 'Cortesia' }}
                        </button>
                        <button
                          v-if="isComplimentaryStore(store)"
                          type="button"
                          :disabled="savingStore === store.id"
                          class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-xl border border-violet-200 bg-violet-50 px-3 text-[10px] font-black uppercase text-violet-700 transition hover:bg-violet-100 disabled:opacity-50"
                          @click="openRevokeCourtesyModal(store)"
                        >
                          <XCircle size="12" />
                          Remover cortesia
                        </button>
                        <button
                          v-if="isFilialStore(store)"
                          type="button"
                          class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-xl border border-sky-200 bg-sky-50 px-3 text-[10px] font-black uppercase text-sky-700 transition hover:bg-sky-100"
                          @click="openDetachModal(store)"
                        >
                          <Building2 size="12" />
                          Independente
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section v-else-if="activeTab === 'plans'" class="space-y-4">
          <div class="rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-sm font-medium leading-relaxed text-amber-900">
            <p class="font-black">Visibilidade dos planos</p>
            <p class="mt-1">
              <strong>Visível:</strong> aparece na vitrine do lojista (billing/planos) e ativo na landing com botão de cadastro.
              <strong class="ml-1">Oculto:</strong> some da vitrine do lojista, mas continua na landing desativado (badge &quot;Em breve&quot;) para destacar o plano promocional de lançamento.
            </p>
          </div>

          <div class="grid gap-4 lg:grid-cols-3">
          <article
            v-for="plan in plans"
            :key="plan.id"
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
          >
            <div class="mb-5 flex items-start justify-between gap-3">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">{{ plan.slug }}</p>
                  <span
                    v-if="plan.is_visible === false"
                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-black uppercase text-amber-700"
                  >
                    Oculto · desativado na landing
                  </span>
                </div>
                <h2 class="text-xl font-black">{{ plan.name }}</h2>
              </div>

              <div class="flex items-center gap-2">
                <button
                  type="button"
                  :disabled="togglingPlanVisibility === plan.id"
                  class="rounded-xl border border-slate-200 p-2 text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                  :title="plan.is_visible === false ? 'Ativar na vitrine e na landing' : 'Ocultar da vitrine (landing mostra como indisponível)'"
                  @click="togglePlanVisibility(plan)"
                >
                  <Loader2 v-if="togglingPlanVisibility === plan.id" class="animate-spin" size="16" />
                  <EyeOff v-else-if="plan.is_visible === false" size="16" />
                  <Eye v-else size="16" />
                </button>

                <button
                  type="button"
                  @click="editingPlanId = editingPlanId === plan.id ? null : plan.id"
                  class="rounded-xl border border-slate-200 p-2 text-slate-600 transition hover:bg-slate-50"
                  title="Editar plano"
                >
                  <Edit3 size="16" />
                </button>
              </div>
            </div>

            <div v-if="editingPlanId !== plan.id" class="space-y-4">
              <p class="text-sm font-semibold leading-relaxed text-slate-500">{{ plan.description || 'Sem descrição.' }}</p>
              <p class="text-3xl font-black">{{ formatCurrency(plan.price) }}</p>
              <p
                v-if="plan.launch_offer_available && plan.launch_price"
                class="mt-1 text-sm font-bold text-amber-700"
              >
                Oferta fundador: {{ formatCurrency(plan.launch_price) }} · restam {{ plan.launch_slots_remaining }}/{{ plan.launch_slots }}
              </p>
              <p
                v-else-if="plan.launch_slots_remaining === 0 && plan.launch_price"
                class="mt-1 text-sm font-bold text-slate-500"
              >
                Oferta fundador encerrada · novos pagam {{ formatCurrency(plan.price) }}
              </p>
              <p class="text-sm font-bold text-slate-600">
                {{ plan.max_products === null ? 'Produtos ilimitados' : `Até ${plan.max_products} produtos` }}
              </p>
              <p class="text-sm font-bold text-slate-600">
                Até {{ plan.max_stores || 1 }} loja(s) · {{ plan.max_team_members ?? 0 }} membros de equipe
              </p>

              <div class="flex flex-wrap gap-2">
                <span
                  v-for="feature in orderedFeatureKeys"
                  :key="feature"
                  :class="[
                    'rounded-full border px-3 py-1 text-[10px] font-black uppercase',
                    plan.features?.[feature] ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-400'
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
                  <input
                    v-model="planForms[plan.id].slug"
                    :disabled="isCorePlan(plan)"
                    class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-60 focus:border-red-500 focus:ring-red-500"
                  />
                  <p v-if="isCorePlan(plan)" class="text-[10px] font-bold text-slate-400">
                    Slug interno protegido. Altere o nome exibido acima.
                  </p>
                </label>
              </div>

              <label class="space-y-1 block">
                <span class="text-[10px] font-black uppercase text-slate-400">Descrição</span>
                <textarea v-model="planForms[plan.id].description" rows="3" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500"></textarea>
              </label>

              <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Preço regular</span>
                  <input v-model="planForms[plan.id].price" type="number" step="0.01" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                  <p class="text-[10px] font-bold text-slate-400">Valor cobrado após encerrar a oferta fundador.</p>
                </label>

                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Preço fundador</span>
                  <input v-model="planForms[plan.id].launch_price" type="number" step="0.01" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                </label>
              </div>

              <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Vagas fundador</span>
                  <input v-model="planForms[plan.id].launch_slots" type="number" min="0" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                </label>

                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Meses no preço fundador</span>
                  <input v-model="planForms[plan.id].launch_price_months" type="number" min="1" max="36" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                </label>
              </div>

              <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Limite produtos</span>
                  <input v-model="planForms[plan.id].max_products" :disabled="planForms[plan.id].is_unlimited" type="number" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold disabled:opacity-40 focus:border-red-500 focus:ring-red-500" />
                </label>
              </div>

              <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Limite de lojas</span>
                  <input v-model="planForms[plan.id].max_stores" type="number" min="1" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                  <p class="text-[10px] font-bold text-slate-400">Matriz + filiais inclusas no plano.</p>
                </label>

                <label class="space-y-1">
                  <span class="text-[10px] font-black uppercase text-slate-400">Limite de equipe</span>
                  <input v-model="planForms[plan.id].max_team_members" type="number" min="0" class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500" />
                  <p class="text-[10px] font-bold text-slate-400">0 = sem equipe. Premium costuma ter valor maior.</p>
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

              <div class="grid gap-2 sm:grid-cols-2">
                <label v-for="feature in orderedFeatureKeys" :key="feature" class="flex items-center gap-2 text-xs font-bold text-slate-600">
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
          </div>
        </section>

        <section v-else-if="activeTab === 'settings'" class="space-y-5">
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div>
              <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Plataforma</p>
              <h2 class="mt-1 text-2xl font-black text-slate-950">Regras globais</h2>
              <p class="mt-1 text-sm font-semibold text-slate-500">
                Valores aplicados a todas as lojas. Alterações entram em vigor imediatamente.
              </p>
            </div>
          </div>

          <form
            class="max-w-2xl rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-5"
            @submit.prevent="updatePlatformSettings"
          >
            <label
              v-for="setting in platformSettings"
              :key="setting.key"
              class="block space-y-1"
            >
              <span class="text-[10px] font-black uppercase text-slate-400">{{ setting.label }}</span>
              <input
                v-model="settingsForm[setting.key]"
                :type="setting.type === 'decimal' ? 'number' : 'number'"
                :step="setting.type === 'decimal' ? '0.01' : '1'"
                :min="setting.min ?? 0"
                :max="setting.max ?? undefined"
                class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500"
              />
              <p v-if="setting.hint" class="text-[10px] font-bold text-slate-400">{{ setting.hint }}</p>
            </label>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-3">
              <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">Recebimentos</p>
                <h3 class="mt-1 text-sm font-black text-slate-900">Gateways disponíveis para lojistas</h3>
                <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">
                  Desmarque para ocultar um gateway em Recebimentos e no cartão online.
                  Lojas demo ({{ demoStoreSlugs.join(', ') }}) e lojas na lista de bypass em Módulos ignoram esta restrição.
                </p>
              </div>

              <div class="space-y-2">
                <label
                  v-for="provider in paymentProviders"
                  :key="provider.key"
                  class="flex items-start gap-3 rounded-xl border border-white bg-white px-3 py-3"
                >
                  <input
                    v-model="paymentProvidersForm[provider.key]"
                    type="checkbox"
                    class="mt-0.5 rounded border-slate-300 text-red-600 focus:ring-red-500"
                  >
                  <span>
                    <span class="block text-sm font-black text-slate-900">{{ provider.label }}</span>
                    <span class="mt-0.5 block text-xs font-semibold text-slate-500">{{ provider.description }}</span>
                    <span
                      v-if="provider.supports_credit_card"
                      class="mt-1 inline-flex rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-black uppercase text-violet-700"
                    >
                      Pix online + cartão
                    </span>
                    <span
                      v-else
                      class="mt-1 inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-black uppercase text-sky-700"
                    >
                      Pix online
                    </span>
                  </span>
                </label>
              </div>
            </div>

            <button
              type="submit"
              :disabled="savingSettings"
              class="flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:opacity-60"
            >
              <Loader2 v-if="savingSettings" class="animate-spin" size="16" />
              <Save v-else size="16" />
              Salvar configurações
            </button>
          </form>
        </section>

        <SuperAdminModulesSection
          v-else-if="activeTab === 'modules'"
          @notify="showNotify"
        />

        <SuperAdminLandingSection
          v-else-if="activeTab === 'landing'"
          @notify="showNotify"
        />

        <SuperAdminWhatsappSection
          v-else-if="activeTab === 'whatsapp'"
          @notify="showNotify"
        />

        <section v-else-if="activeTab === 'integration-logs'" class="space-y-5">
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Integrações</p>
                <h2 class="mt-1 text-2xl font-black text-slate-950">Logs técnicos</h2>
                <p class="mt-1 text-sm font-semibold text-slate-500">
                  Erros de WhatsApp, iFood e outros conectores. Use o código para cruzar com o que o lojista vê no painel.
                </p>
              </div>

              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="rounded-xl border px-3 py-2 text-xs font-black uppercase"
                  :class="integrationLogFilter === '' ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-200 bg-white text-slate-600'"
                  @click="integrationLogFilter = ''; fetchIntegrationLogs()"
                >
                  Todos
                </button>
                <button
                  type="button"
                  class="rounded-xl border px-3 py-2 text-xs font-black uppercase"
                  :class="integrationLogFilter === 'whatsapp' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-600'"
                  @click="integrationLogFilter = 'whatsapp'; fetchIntegrationLogs()"
                >
                  WhatsApp
                </button>
                <button
                  type="button"
                  class="rounded-xl border px-3 py-2 text-xs font-black uppercase"
                  :class="integrationLogFilter === 'ifood' ? 'border-red-600 bg-red-600 text-white' : 'border-slate-200 bg-white text-slate-600'"
                  @click="integrationLogFilter = 'ifood'; fetchIntegrationLogs()"
                >
                  iFood
                </button>
              </div>
            </div>

            <div v-if="loadingIntegrationLogs" class="flex justify-center py-16">
              <Loader2 class="animate-spin text-slate-400" size="32" />
            </div>

            <div v-else-if="!integrationLogs.length" class="mt-6 rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm font-semibold text-slate-500">
              Nenhum erro registrado ainda.
            </div>

            <div v-else class="mt-6 space-y-3">
              <article
                v-for="log in integrationLogs"
                :key="log.id"
                class="rounded-2xl border border-slate-200 bg-slate-50 p-4"
              >
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p class="font-mono text-xs font-black uppercase tracking-widest text-slate-500">
                      {{ log.channel }} · {{ log.action }}
                    </p>
                    <p class="mt-1 text-sm font-black text-slate-900">{{ log.public_message }}</p>
                    <p v-if="log.store" class="mt-1 text-xs font-bold text-slate-500">
                      Loja: {{ log.store.name }} (#{{ log.store.id }})
                    </p>
                  </div>
                  <div class="text-right">
                    <p class="font-mono text-xs font-black text-red-700">{{ log.error_ref }}</p>
                    <p class="mt-1 text-[10px] font-bold text-slate-400">
                      {{ log.created_at ? new Date(log.created_at).toLocaleString('pt-BR') : '—' }}
                    </p>
                  </div>
                </div>
                <pre class="mt-3 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs font-mono text-slate-100 whitespace-pre-wrap">{{ log.details }}</pre>
              </article>
            </div>
          </div>
        </section>

        <section v-else-if="activeTab === 'courtesies'" class="space-y-5">
          <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Cortesias</p>
                <h2 class="mt-1 text-2xl font-black text-slate-950">Acesso gratuito por tempo limitado</h2>
                <p class="mt-1 text-sm font-semibold text-slate-500">
                  Escolha o plano e a duração. Quando a cortesia acabar, a loja fica bloqueada até assinar e pagar.
                </p>
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

          <div class="grid gap-4 md:grid-cols-3">
            <article class="rounded-2xl border border-violet-100 bg-violet-50 p-5">
              <p class="text-[10px] font-black uppercase tracking-widest text-violet-600">Ativas agora</p>
              <p class="mt-1 text-3xl font-black text-violet-950">{{ courtesyStores.length }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 md:col-span-2">
              <p class="text-sm font-black text-slate-900">Como funciona</p>
                <p class="mt-1 text-sm font-semibold leading-relaxed text-slate-500">
                  Durante a cortesia a loja usa o plano escolhido sem cobrança. Ao encerrar, lojas que estavam em Trial voltam ao Trial (dados preservados). Demais ficam pendentes até assinar em Meu plano.
                </p>
            </article>
          </div>

          <div v-if="courtesyStores.length" class="space-y-3">
            <h3 class="text-sm font-black uppercase tracking-widest text-slate-400">Cortesias em andamento</h3>
            <div class="grid gap-4 xl:grid-cols-2">
              <article
                v-for="store in courtesyStores"
                :key="`courtesy-${store.id}`"
                class="rounded-2xl border border-violet-200 bg-white p-5 shadow-sm"
              >
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="text-lg font-black text-slate-950">{{ store.name }}</p>
                    <p class="text-xs font-bold text-slate-400">{{ store.user?.email || 'sem email' }}</p>
                  </div>
                  <span class="rounded-full border border-violet-100 bg-violet-50 px-3 py-1 text-[10px] font-black uppercase text-violet-700">
                    Até {{ formatDate(store.complimentary_until) }}
                  </span>
                </div>
                <p class="mt-3 text-sm font-bold text-slate-700">
                  Plano {{ store.plan?.name }} · {{ formatCurrency(store.plan?.price || 0) }}/mês após a cortesia
                </p>
                <p v-if="store.complimentary_reason" class="mt-1 text-xs font-semibold text-slate-500">
                  {{ store.complimentary_reason }}
                </p>
                <button
                  type="button"
                  class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-violet-600 px-4 py-3 text-sm font-black text-white transition hover:bg-violet-700"
                  @click="openCourtesyModal(store)"
                >
                  <Gift size="16" />
                  Renovar cortesia
                </button>
                <button
                  type="button"
                  :disabled="savingStore === store.id"
                  class="mt-2 flex w-full items-center justify-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-black text-red-700 transition hover:bg-red-100 disabled:opacity-50"
                  @click="openRevokeCourtesyModal(store)"
                >
                  <XCircle size="16" />
                  Remover cortesia
                </button>
              </article>
            </div>
          </div>

          <div class="space-y-3">
            <h3 class="text-sm font-black uppercase tracking-widest text-slate-400">Conceder cortesia</h3>
            <div class="grid gap-4 xl:grid-cols-2">
              <article
                v-for="store in filteredStores"
                :key="`grant-${store.id}`"
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
              >
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="text-lg font-black text-slate-950">{{ store.name }}</p>
                    <p class="text-xs font-bold text-slate-400">{{ store.user?.email || 'sem email' }}</p>
                  </div>
                  <span :class="['rounded-full border px-3 py-1 text-[10px] font-black uppercase', statusClass(store.subscription_status)]">
                    {{ statusLabel(store.subscription_status) }}
                  </span>
                </div>

                <p class="mt-3 text-sm font-semibold text-slate-500">
                  Plano atual: {{ store.plan?.name || 'Sem plano' }}
                  <span v-if="hadCourtesyStore(store)" class="text-amber-700">
                    · cortesia encerrada
                    <template v-if="store.subscription_status === 'trial'"> — voltou ao Trial</template>
                    <template v-else> — aguardando pagamento</template>
                  </span>
                </p>

                <div class="mt-4 flex flex-col gap-2">
                  <button
                    v-if="!canRevokeCourtesy(store)"
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-2xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm font-black text-violet-800 transition hover:bg-violet-100"
                    @click="openCourtesyModal(store)"
                  >
                    <Gift size="16" />
                    Configurar cortesia
                  </button>
                  <button
                    v-else
                    type="button"
                    :disabled="savingStore === store.id"
                    class="flex w-full items-center justify-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-black text-red-700 transition hover:bg-red-100 disabled:opacity-50"
                    @click="openRevokeCourtesyModal(store)"
                  >
                    <XCircle size="16" />
                    {{ isComplimentaryStore(store) ? 'Remover cortesia' : 'Voltar ao Trial' }}
                  </button>
                </div>
              </article>
            </div>
          </div>
        </section>
      </div>
    </main>

    <div
      v-if="panelToggleModal.open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm"
      @click.self="closePanelToggleModal"
    >
      <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="flex items-start gap-3">
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl"
            :class="panelToggleModal.nextStatus === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'"
          >
            <Unlock v-if="panelToggleModal.nextStatus === 'active'" size="20" />
            <Lock v-else size="20" />
          </div>

          <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Confirmação</p>
            <h3 class="mt-1 text-lg font-black text-slate-950">
              {{ panelToggleModal.nextStatus === 'active' ? 'Liberar acesso ao painel' : 'Bloquear acesso ao painel' }}
            </h3>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">
              <template v-if="panelToggleModal.nextStatus === 'active'">
                A loja <strong class="text-slate-800">{{ panelToggleModal.store?.name }}</strong> voltará a acessar o painel.
                Se a assinatura estiver expirada, será renovada por 30 dias.
              </template>
              <template v-else>
                A loja <strong class="text-slate-800">{{ panelToggleModal.store?.name }}</strong> ficará suspensa e não poderá usar o painel.
              </template>
            </p>
            <p
              v-if="panelToggleModal.nextStatus === 'suspended' && panelToggleModal.store?.branches_count > 0"
              class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800"
            >
              Esta matriz tem {{ panelToggleModal.store.branches_count }} filial(is). O bloqueio será aplicado a todas.
              Desvincule a filial antes se ela precisar continuar.
            </p>
          </div>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="confirmPanelToggle">
          <label class="block space-y-1">
            <span class="text-[10px] font-black uppercase text-slate-400">Sua senha de super admin</span>
            <input
              v-model="panelToggleModal.password"
              type="password"
              autocomplete="current-password"
              placeholder="Digite sua senha para confirmar"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none transition focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-100"
            >
          </label>

          <p v-if="panelToggleModal.error" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">
            {{ panelToggleModal.error }}
          </p>

          <div class="flex gap-3 pt-1">
            <button
              type="button"
              class="flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-black text-slate-600 transition hover:bg-slate-50"
              @click="closePanelToggleModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="blockingStore === panelToggleModal.store?.id"
              class="flex flex-1 items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-black text-white transition disabled:opacity-60"
              :class="panelToggleModal.nextStatus === 'active' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-red-600 hover:bg-red-700'"
            >
              <Loader2 v-if="blockingStore === panelToggleModal.store?.id" class="animate-spin" size="16" />
              Confirmar
            </button>
          </div>
        </form>
      </div>
    </div>

    <div
      v-if="courtesyModal.open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm"
      @click.self="closeCourtesyModal"
    >
      <div class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="flex items-start gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-violet-50 text-violet-600">
            <Gift size="20" />
          </div>

          <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-violet-600">Cortesia</p>
            <h3 class="mt-1 text-lg font-black text-slate-950">
              {{ courtesyModal.store?.name }}
            </h3>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">
              A loja usará o plano escolhido sem cobrança até a data final. Depois disso, precisará assinar para continuar.
            </p>
          </div>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="confirmCourtesy">
          <label class="block space-y-1">
            <span class="text-[10px] font-black uppercase text-slate-400">Plano durante a cortesia</span>
            <select
              v-model="courtesyModal.plan_id"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-100"
            >
              <option value="" disabled>Selecione um plano</option>
              <option v-for="plan in assignablePlans" :key="plan.id" :value="plan.id">
                {{ plan.name }} · {{ formatCurrency(plan.price) }}/mês
              </option>
            </select>
          </label>

          <div class="space-y-2">
            <span class="text-[10px] font-black uppercase text-slate-400">Duração</span>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="preset in courtesyDurationPresets"
                :key="preset.days"
                type="button"
                class="rounded-xl border px-3 py-1.5 text-xs font-black transition"
                :class="courtesyModal.complimentary_until === addDaysToDate(preset.days)
                  ? 'border-violet-300 bg-violet-50 text-violet-700'
                  : 'border-slate-200 bg-slate-50 text-slate-600 hover:border-violet-200'"
                @click="setCourtesyDuration(preset.days)"
              >
                {{ preset.label }}
              </button>
            </div>
            <input
              v-model="courtesyModal.complimentary_until"
              type="date"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-100"
            >
          </div>

          <label class="block space-y-1">
            <span class="text-[10px] font-black uppercase text-slate-400">Motivo (opcional)</span>
            <input
              v-model="courtesyModal.complimentary_reason"
              type="text"
              placeholder="Ex.: parceiro piloto, demo comercial"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-100"
            >
          </label>

          <label class="block space-y-1">
            <span class="text-[10px] font-black uppercase text-slate-400">Sua senha de super admin</span>
            <input
              v-model="courtesyModal.password"
              type="password"
              autocomplete="current-password"
              placeholder="Digite sua senha para confirmar"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none focus:border-violet-500 focus:bg-white focus:ring-2 focus:ring-violet-100"
            >
          </label>

          <p v-if="courtesyModal.error" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">
            {{ courtesyModal.error }}
          </p>

          <div class="flex gap-3 pt-1">
            <button
              type="button"
              class="flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-black text-slate-600 transition hover:bg-slate-50"
              @click="closeCourtesyModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="savingStore === courtesyModal.store?.id"
              class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-violet-600 px-4 py-3 text-sm font-black text-white transition hover:bg-violet-700 disabled:opacity-60"
            >
              <Loader2 v-if="savingStore === courtesyModal.store?.id" class="animate-spin" size="16" />
              Aplicar cortesia
            </button>
          </div>
        </form>
      </div>
    </div>

    <div
      v-if="revokeCourtesyModal.open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm"
      @click.self="closeRevokeCourtesyModal"
    >
      <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="flex items-start gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-red-50 text-red-600">
            <XCircle size="20" />
          </div>

          <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-600">Remover cortesia</p>
            <h3 class="mt-1 text-lg font-black text-slate-950">
              {{ revokeCourtesyModal.store?.name }}
            </h3>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">
              A cortesia será encerrada agora. O painel da loja ficará bloqueado até o dono assinar um plano em Meu plano.
            </p>
          </div>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="confirmRevokeCourtesy">
          <label class="block space-y-1">
            <span class="text-[10px] font-black uppercase text-slate-400">Sua senha de super admin</span>
            <input
              v-model="revokeCourtesyModal.password"
              type="password"
              autocomplete="current-password"
              placeholder="Digite sua senha para confirmar"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none transition focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-100"
            >
          </label>

          <p v-if="revokeCourtesyModal.error" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">
            {{ revokeCourtesyModal.error }}
          </p>

          <div class="flex gap-3 pt-1">
            <button
              type="button"
              class="flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-black text-slate-600 transition hover:bg-slate-50"
              @click="closeRevokeCourtesyModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="savingStore === revokeCourtesyModal.store?.id"
              class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-red-600 px-4 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:opacity-60"
            >
              <Loader2 v-if="savingStore === revokeCourtesyModal.store?.id" class="animate-spin" size="16" />
              Remover cortesia
            </button>
          </div>
        </form>
      </div>
    </div>

    <div
      v-if="demoDashboardModal.open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm"
      @click.self="closeDemoDashboardModal"
    >
      <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="flex items-start gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
            <BarChart3 size="20" />
          </div>

          <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-600">Marketing</p>
            <h3 class="mt-1 text-lg font-black text-slate-950">{{ demoDashboardModal.store?.name }}</h3>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">
              Cria pedidos fictícios dos últimos 7 dias com nomes genéricos para prints do dashboard.
              Não envia WhatsApp nem altera estoque. Pedidos ficam marcados como demo.
            </p>
          </div>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="confirmSeedDemoDashboard">
          <label class="flex items-start gap-2 text-sm font-bold text-slate-600">
            <input
              v-model="demoDashboardModal.clear_existing"
              type="checkbox"
              class="mt-0.5 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
            >
            Remover pedidos demo anteriores desta loja antes de criar novos
          </label>

          <label class="block space-y-1">
            <span class="text-[10px] font-black uppercase text-slate-400">Sua senha de super admin</span>
            <input
              v-model="demoDashboardModal.password"
              type="password"
              autocomplete="current-password"
              placeholder="Digite sua senha para confirmar"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none focus:border-amber-500 focus:bg-white focus:ring-2 focus:ring-amber-100"
            >
          </label>

          <p v-if="demoDashboardModal.error" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">
            {{ demoDashboardModal.error }}
          </p>

          <div class="flex gap-3 pt-1">
            <button
              type="button"
              class="flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-black text-slate-600 transition hover:bg-slate-50"
              @click="closeDemoDashboardModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="seedingDemoStore === demoDashboardModal.store?.id"
              class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-amber-600 px-4 py-3 text-sm font-black text-white transition hover:bg-amber-700 disabled:opacity-60"
            >
              <Loader2 v-if="seedingDemoStore === demoDashboardModal.store?.id" class="animate-spin" size="16" />
              Popular dashboard
            </button>
          </div>
        </form>
      </div>
    </div>

    <div
      v-if="detachModal.open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm"
      @click.self="closeDetachModal"
    >
      <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="flex items-start gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-sky-50 text-sky-600">
            <Building2 size="20" />
          </div>

          <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-sky-600">Filial independente</p>
            <h3 class="mt-1 text-lg font-black text-slate-950">{{ detachModal.store?.name }}</h3>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">
              A filial deixa de seguir a matriz
              <strong class="text-slate-800">{{ detachModal.store?.parent_store?.name }}</strong>
              e vira matriz própria com 7 dias de teste. Depois, o dono precisa assinar em Meu plano.
            </p>
          </div>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="confirmDetachBranch">
          <label class="block space-y-1">
            <span class="text-[10px] font-black uppercase text-slate-400">Sua senha de super admin</span>
            <input
              v-model="detachModal.password"
              type="password"
              autocomplete="current-password"
              placeholder="Digite sua senha para confirmar"
              class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold outline-none focus:border-sky-500 focus:bg-white focus:ring-2 focus:ring-sky-100"
            >
          </label>

          <p v-if="detachModal.error" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-700">
            {{ detachModal.error }}
          </p>

          <div class="flex gap-3 pt-1">
            <button
              type="button"
              class="flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-black text-slate-600 transition hover:bg-slate-50"
              @click="closeDetachModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="savingStore === detachModal.store?.id"
              class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-sky-600 px-4 py-3 text-sm font-black text-white transition hover:bg-sky-700 disabled:opacity-60"
            >
              <Loader2 v-if="savingStore === detachModal.store?.id" class="animate-spin" size="16" />
              Desvincular
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
