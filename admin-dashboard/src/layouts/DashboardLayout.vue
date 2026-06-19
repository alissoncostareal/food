<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'
import { clearCachedUser, fetchCurrentUser } from '@/composables/useFeatureAccess'
import { requiredPlanLabelForFeature } from '@/constants/planFeatures'
import { clearAuthSession } from '@/utils/authSession'
import { useNewOrderAlert } from '@/composables/useNewOrderAlert'
import { useIsMobileViewport } from '@/composables/useIsMobileViewport'
import DesktopOnlyNotice from '@/components/auth/DesktopOnlyNotice.vue'
import PwaInstallBanner from '@/components/PwaInstallBanner.vue'
import {
  TrendingUp,
  ShoppingBag,
  UtensilsCrossed,
  FolderTree,
  Store as StoreIcon,
  Settings,
  CreditCard,
  LogOut,
  Bell,
  Ticket,
  Lock,
  FileSpreadsheet,
  MapPin,
  Bike,
  Upload,
  PackageCheck,
  MessageCircle,
  Lightbulb,
  Users,
  Wallet,
  CheckCircle,
  XCircle,
  ChevronDown,
  Building2
} from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()
const { isMobileViewport } = useIsMobileViewport()

const isHeaderLoading = ref(true)
const realtimeStoreId = ref(null)
const notificationToast = ref({ show: false, message: '', type: 'success' })

const orderAlert = useNewOrderAlert(async () => {
  try {
    await api.patch('/merchant/preferences', { new_order_sound_unlocked: true })
  } catch {
    // preferência local já foi salva
  }
})

let lastKnownPendingCount = 0
let pendingCountInitialized = false
let pendingPollTimer = null
let headerPollIntervalMs = 12000
let activeRealtimeStoreId = null
let realtimeSubscribed = false
let globalListenersReady = false
let activeLayout = null
let layoutInstanceSeq = 0

const subscribeToStoreChannel = (storeId) => {
  const echo = window.PartiuMenuEcho?.initialize?.() || window.Echo

  if (!echo || !storeId) return

  if (realtimeSubscribed && activeRealtimeStoreId === storeId) {
    return
  }

  if (activeRealtimeStoreId && activeRealtimeStoreId !== storeId) {
    echo.leave(`store.${activeRealtimeStoreId}`)
  }

  activeRealtimeStoreId = storeId

  echo.leave(`store.${storeId}`)
  echo.private(`store.${storeId}`)
    .listen('.order.created', async (event) => {
      const order = event.order || {}
      const isPending = !order.status || order.status === 'pending'

      window.dispatchEvent(new CustomEvent('partiumenu:order-created', { detail: event }))

      await activeLayout?.fetchStoreHeaderData?.(true, {
        orderCode: order.display_code || order.display_number || order.ifood_display_id || order.id,
        showToast: isPending
      })
    })
    .listen('.order.updated', async (event) => {
      window.dispatchEvent(new CustomEvent('partiumenu:order-updated', { detail: event }))

      await activeLayout?.fetchStoreHeaderData?.(true)
    })
    .error((error) => {
      console.error('[Layout Echo Error]', error)
    })

  realtimeSubscribed = true
}

const teardownGlobalInfrastructure = () => {
  if (pendingPollTimer) {
    clearInterval(pendingPollTimer)
    pendingPollTimer = null
  }

  if (globalListenersReady) {
    window.removeEventListener('click', onGlobalUnlockAudio)
    window.removeEventListener('keydown', onGlobalUnlockAudio)
    window.removeEventListener('click', onGlobalCloseStoreSwitcher)
    window.removeEventListener('partiumenu:sound-settings-updated', onGlobalSoundSettingsUpdated)
    window.removeEventListener('partiumenu:store-updated', onGlobalStoreUpdated)
    window.removeEventListener('partiumenu:store-switched', onGlobalStoreUpdated)
    window.removeEventListener('partiumenu:store-status-changed', onGlobalStoreStatusChanged)
    window.removeEventListener('partiumenu:play-order-alert', onGlobalPlayOrderAlert)
    window.removeEventListener('partiumenu:pending-orders-sync', onGlobalPendingOrdersSync)
    globalListenersReady = false
  }

  activeLayout = null
  realtimeSubscribed = false
  activeRealtimeStoreId = null
}

const onGlobalUnlockAudio = () => activeLayout?.unlockAudio?.()
const onGlobalCloseStoreSwitcher = (event) => activeLayout?.closeStoreSwitcher?.(event)
const onGlobalSoundSettingsUpdated = (event) => activeLayout?.handleSoundSettingsUpdated?.(event)
const onGlobalStoreUpdated = () => activeLayout?.handleStoreUpdated?.()
const onGlobalStoreStatusChanged = (event) => activeLayout?.handleStoreStatusChanged?.(event)
const onGlobalPlayOrderAlert = () => activeLayout?.handlePlayOrderAlert?.()
const onGlobalPendingOrdersSync = (event) => activeLayout?.handlePendingOrdersSync?.(event)

const ensureGlobalInfrastructure = () => {
  if (globalListenersReady) return

  globalListenersReady = true

  window.addEventListener('click', onGlobalUnlockAudio)
  window.addEventListener('keydown', onGlobalUnlockAudio)
  window.addEventListener('click', onGlobalCloseStoreSwitcher)
  window.addEventListener('partiumenu:sound-settings-updated', onGlobalSoundSettingsUpdated)
  window.addEventListener('partiumenu:store-updated', onGlobalStoreUpdated)
  window.addEventListener('partiumenu:store-switched', onGlobalStoreUpdated)
  window.addEventListener('partiumenu:store-status-changed', onGlobalStoreStatusChanged)
  window.addEventListener('partiumenu:play-order-alert', onGlobalPlayOrderAlert)
  window.addEventListener('partiumenu:pending-orders-sync', onGlobalPendingOrdersSync)

  pendingPollTimer = setInterval(() => {
    activeLayout?.fetchStoreHeaderData?.(true)
  }, headerPollIntervalMs)
}

const restartHeaderPollTimer = () => {
  if (!pendingPollTimer) return
  clearInterval(pendingPollTimer)
  pendingPollTimer = setInterval(() => {
    activeLayout?.fetchStoreHeaderData?.(true)
  }, headerPollIntervalMs)
}

const storeData = ref({
  name: '',
  store_type: 'matriz',
  logo_url: null,
  pending_count: 0,
  is_open: null,
  manual_is_open: null,
  status_message: null,
  opening_status: null,
  next_opening: null,
  plan: null,
  products_usage: null
})

const userRole = ref(localStorage.getItem('user_role') || '')
const canManageTeam = ref(false)
const accessibleStores = ref([])
const currentStoreId = ref(null)
const switchingStore = ref(false)
const storeSwitcherOpen = ref(false)
const storeSwitcherRef = ref(null)

const closeStoreSwitcher = (event) => {
  if (!storeSwitcherOpen.value) return
  if (storeSwitcherRef.value && !storeSwitcherRef.value.contains(event.target)) {
    storeSwitcherOpen.value = false
  }
}

const upgradeModal = ref({
  show: false,
  title: '',
  message: ''
})

const pageTitle = computed(() => route.meta?.title || route.name || 'Painel')

const isMobileSummaryRoute = computed(() => {
  const normalizedPath = route.path.replace(/\/$/, '') || '/'

  return normalizedPath === '/dashboard' || normalizedPath === '/'
})

const showDesktopOnlyNotice = computed(() => {
  return isMobileViewport.value && !isMobileSummaryRoute.value
})

const menuItems = [
  { name: 'Dashboard', path: '/dashboard', icon: TrendingUp },
  { name: 'Loja', path: '/loja', icon: StoreIcon },
  { name: 'Recebimentos', path: '/payments', icon: Wallet, ownerOnly: true },
  { name: 'Pedidos', path: '/orders', icon: ShoppingBag },
  { name: 'Cardápio', path: '/products', icon: UtensilsCrossed },
  { name: 'Categorias', path: '/categories', icon: FolderTree },
  {
    name: 'Cupons',
    path: '/coupons',
    icon: Ticket,
    feature: 'coupons',
    upgradeTitle: 'Cupons disponíveis no plano Pro',
    upgradeMessage: 'Crie cupons de desconto para aumentar conversões e recuperar clientes. Faça upgrade para liberar esse recurso.'
  },
  {
    name: 'Áreas',
    path: '/delivery-areas',
    icon: MapPin,
    feature: 'delivery_areas',
    upgradeTitle: 'Áreas de entrega disponíveis no plano Pro',
    upgradeMessage: 'Defina bairros atendidos, taxas e prazos para bloquear pedidos fora da sua operação.'
  },
  {
    name: 'Entregadores',
    path: '/delivery-drivers',
    icon: Bike
  },
  {
    name: 'Equipe',
    path: '/team',
    icon: Users,
    ownerOnly: true,
    feature: 'team',
    upgradeTitle: 'Equipe — Premium',
    upgradeMessage: 'Convide funcionários com login próprio para operar matriz ou filial. Disponível no plano Premium.'
  },
  {
    name: 'Relatórios',
    path: '/reports',
    icon: FileSpreadsheet,
    feature: 'advanced_reports',
    upgradeTitle: 'Relatórios avançados são Premium',
    upgradeMessage: 'Exporte relatório financeiro, formas de pagamento, produtos vendidos e pedidos detalhados.'
  },
  {
    name: 'Inteligência',
    path: '/intelligence',
    icon: Lightbulb,
    feature: 'intelligence',
    upgradeTitle: 'Inteligência com IA — Premium',
    upgradeMessage: 'Dicas personalizadas com IA para vender mais: horários de pico, cardápio, operação e crescimento. Disponível no plano Premium.'
  },
  {
    name: 'Importação',
    path: '/import',
    icon: Upload,
    feature: 'ifood_integration',
    upgradeTitle: 'Importação de produtos disponível no Premium',
    upgradeMessage: 'Importe produtos por XML e conecte canais externos no plano Premium.'
  },
  {
    name: 'WhatsApp',
    path: '/integrations/whatsapp',
    icon: MessageCircle,
    feature: 'whatsapp_auto',
    upgradeTitle: 'WhatsApp automático — plano Pro',
    upgradeMessage: 'Conecte o número da loja, envie status de pedido e ative o bot no plano Pro.'
  },
  {
    name: 'iFood',
    path: '/integrations/ifood',
    icon: PackageCheck,
    feature: 'ifood_integration',
    upgradeTitle: 'Integração iFood disponível no Premium',
    upgradeMessage: 'Conecte catálogo, pedidos e eventos do iFood no plano Premium.'
  },
  { name: 'Meu Plano', path: '/billing', icon: CreditCard, ownerOnly: true },
  { name: 'Configurações', path: '/settings', icon: Settings }
]

const visibleMenuItems = computed(() => {
  return menuItems.filter((item) => {
    if (item.ownerOnly && !canManageTeam.value && userRole.value !== 'store_owner') return false
    if (item.path === '/billing' && userRole.value === 'store_staff') return false
    return true
  })
})

const hasFeature = (feature) => {
  if (!feature) return true

  if (isHeaderLoading.value) return true

  if (storeData.value?.has_active_subscription === false) {
    return false
  }

  const plan = storeData.value?.plan
  const features = { ...(plan?.features || {}) }

  if (plan?.slug === 'premium' && features.intelligence === undefined && feature === 'intelligence') {
    return true
  }

  if (plan?.slug === 'premium' && features.team === undefined && feature === 'team') {
    return true
  }

  return Boolean(features[feature])
}

const lockedFeaturePlanLabel = (feature) => {
  if (!feature || isHeaderLoading.value || hasFeature(feature)) return null
  return requiredPlanLabelForFeature(feature)
}

const visiblePlanName = computed(() => {
  if (isHeaderLoading.value) return ''

  const name = storeData.value?.plan?.name || 'Sem plano'

  if (storeData.value?.has_active_subscription === false) {
    return `${name} · inativo`
  }

  return name
})

const productsUsageLabel = computed(() => {
  if (isHeaderLoading.value) return null

  const usage = storeData.value?.products_usage

  if (!usage) return null

  if (usage.is_unlimited) {
    return `${usage.current} / ilimitado`
  }

  return `${usage.current} / ${usage.limit}`
})

const storeStatusLabel = computed(() => {
  if (isHeaderLoading.value || storeData.value.is_open === null) return ''

  if (storeData.value.status_message) {
    return storeData.value.status_message
  }

  if (storeData.value.is_open) {
    const withinHours = storeData.value.opening_status?.within_scheduled_hours

    if (withinHours === false) {
      return 'Aberta (fora do horário)'
    }

    return 'Loja Aberta'
  }

  if (storeData.value.opening_status?.hours_hint) {
    return storeData.value.opening_status.hours_hint
  }

  return 'Loja Fechada'
})

const storeInitial = computed(() => {
  return storeData.value.name?.charAt(0) || 'L'
})

const showNotificationToast = (message, type = 'success') => {
  notificationToast.value = { show: true, message, type }

  setTimeout(() => {
    notificationToast.value.show = false
  }, 4500)
}

const unlockAudio = () => {
  orderAlert.markUserGesture()
  orderAlert.ensureAudioContext()
}

const handlePlayOrderAlert = () => {
  orderAlert.notifyNewOrder()
}

const handlePendingOrdersSync = (event) => {
  const count = Number(event.detail?.count ?? storeData.value.pending_count ?? 0)
  storeData.value.pending_count = count

  if (event.detail?.increased) {
    return
  }

  orderAlert.syncPendingCount(count)
  pendingCountInitialized = true
  lastKnownPendingCount = count
}

const handlePendingCountChange = (nextCount, meta = {}) => {
  const count = Number(nextCount || 0)
  const increased = pendingCountInitialized && count > lastKnownPendingCount

  pendingCountInitialized = true
  lastKnownPendingCount = count
  orderAlert.syncPendingCount(count)

  if (increased) {
    orderAlert.notifyNewOrder()

    if (meta.showToast !== false) {
      const orderCode = meta.orderCode
      showNotificationToast(
        orderCode ? `Novo pedido! #${orderCode}` : `${count} pedido(s) aguardando aceite.`
      )
    }

    window.dispatchEvent(new CustomEvent('partiumenu:pending-orders-sync', {
      detail: { count, increased: true }
    }))
  }
}

const handleSoundSettingsUpdated = async (event) => {
  const enabled = Boolean(event.detail?.enabled)
  const shouldTest = Boolean(event.detail?.test)

  orderAlert.setEnabled(enabled)

  if (!enabled) {
    showNotificationToast('Som de novos pedidos desativado.', 'error')
    return
  }

  const unlocked = await orderAlert.ensureAudioContext()

  if (shouldTest && unlocked) {
    await orderAlert.playChime()
    showNotificationToast('Som de alerta reproduzido.')
  } else if (shouldTest && !unlocked) {
    showNotificationToast('Clique na página e teste de novo para liberar o áudio.', 'error')
  } else if (unlocked) {
    showNotificationToast('Som de novos pedidos ativado.')
  }

  orderAlert.syncPendingCount(Number(storeData.value.pending_count || 0))
}

const openUpgradeModal = (item) => {
  upgradeModal.value = {
    show: true,
    title: item.upgradeTitle || 'Recurso bloqueado',
    message: item.upgradeMessage || 'Este recurso não está disponível no seu plano atual.'
  }
}

const closeUpgradeModal = () => {
  upgradeModal.value.show = false
}

const goToPlans = () => {
  closeUpgradeModal()
  router.push('/billing')
}

const handleMenuClick = (item) => {
  if (!hasFeature(item.feature)) {
    openUpgradeModal(item)
    return
  }

  router.push(item.path)
}

const setupGlobalRealtime = () => {
  const storeId = currentStoreId.value || realtimeStoreId.value
  if (!storeId) return
  subscribeToStoreChannel(storeId)
}

const hasMultipleStores = computed(() => accessibleStores.value.length > 1)

const currentStoreLabel = computed(() => {
  const current = accessibleStores.value.find(s => s.id === currentStoreId.value)
  if (!current) return storeData.value.name
  return current.store_type === 'filial' ? `${current.name} (Filial)` : current.name
})

const switchStore = async (storeId) => {
  if (switchingStore.value || storeId === currentStoreId.value) {
    storeSwitcherOpen.value = false
    return
  }

  switchingStore.value = true

  try {
    await api.post('/merchant/stores/switch', { store_id: storeId })
    clearCachedUser()
    storeSwitcherOpen.value = false
    realtimeSubscribed = false
    activeRealtimeStoreId = null
    headerDataLoaded = false
    await fetchStoreHeaderData(true)
    window.dispatchEvent(new CustomEvent('partiumenu:store-switched'))
    showNotificationToast('Loja alternada.')
  } catch (error) {
    showNotificationToast(error.response?.data?.message || 'Erro ao alternar loja.', 'error')
  } finally {
    switchingStore.value = false
  }
}

let headerDataLoaded = false
let lastHeaderFetchErrorAt = 0

const isTransientFetchError = (error) => {
  if (!error?.response) return true
  return error.response.status >= 500
}

const fetchStoreHeaderData = async (silent = false, alertMeta = null) => {
  if (!silent && !headerDataLoaded) isHeaderLoading.value = true

  try {
    const { data: storeResponse } = await api.get('/merchant/store')
    const store = storeResponse.data || storeResponse

    let statsStore = {}

    try {
      const { data: statsResponse } = await api.get('/merchant/stats')
      statsStore = statsResponse.store || {}
    } catch (statsError) {
      if (statsError.response?.status !== 403) {
        throw statsError
      }
    }

    realtimeStoreId.value = statsStore.id || store.id || realtimeStoreId.value

    storeData.value = {
      name: statsStore.name || store.name || '',
      store_type: store.store_type || 'matriz',
      logo_url: statsStore.logo_url || store.logo_url || null,
      pending_count: statsStore.pending_count || 0,
      is_open: Boolean(statsStore.is_open ?? store.is_open_now),
      manual_is_open: Boolean(statsStore.manual_is_open ?? store.is_open),
      status_message: store.status_message || store.opening_status?.message || null,
      opening_status: store.opening_status || null,
      next_opening: store.next_opening || store.opening_status?.next_opening || null,
      plan: store.plan || null,
      products_usage: store.products_usage || null,
      has_active_subscription: store.has_active_subscription !== false
    }

    currentStoreId.value = store.id

    try {
      const { data: accessibleResponse } = await api.get('/merchant/stores/accessible')
      accessibleStores.value = Array.isArray(accessibleResponse.stores)
        ? accessibleResponse.stores
        : (accessibleResponse.stores?.data || [])
      currentStoreId.value = accessibleResponse.current_store_id || store.id
    } catch {
      accessibleStores.value = store?.id ? [store] : []
    }

    try {
      const user = await fetchCurrentUser({ force: silent })
      userRole.value = user?.role || localStorage.getItem('user_role') || ''
      canManageTeam.value = Boolean(user?.permissions?.can_manage_team)
    } catch {
      canManageTeam.value = false
    }

    setupGlobalRealtime()
    handlePendingCountChange(storeData.value.pending_count, alertMeta || {})
    headerDataLoaded = true
    headerPollIntervalMs = 12000
    restartHeaderPollTimer()
  } catch (error) {
    if (isTransientFetchError(error)) {
      headerPollIntervalMs = Math.min(headerPollIntervalMs * 2, 120000)
      restartHeaderPollTimer()

      if (!silent || Date.now() - lastHeaderFetchErrorAt > 60000) {
        console.warn('API indisponível — tentando de novo em breve.')
        lastHeaderFetchErrorAt = Date.now()
      }
    } else {
      console.error('Erro ao carregar dados do header:', error)
    }

    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      router.push('/login')
      return
    }
  } finally {
    if (!silent) isHeaderLoading.value = false
  }
}

const handleLogout = () => {
  if (window.Echo && activeRealtimeStoreId) {
    window.Echo.leave(`store.${activeRealtimeStoreId}`)
  }

  headerDataLoaded = false
  teardownGlobalInfrastructure()
  window.PartiuMenuEcho?.disconnect?.()
  clearAuthSession()
  router.push('/login')
}

const handleStoreStatusChanged = (event) => {
  const detail = event?.detail || {}

  storeData.value.is_open = Boolean(detail.is_open)

  if (detail.opening_status) {
    storeData.value.opening_status = detail.opening_status
    storeData.value.status_message = detail.opening_status.message || storeData.value.status_message
    storeData.value.next_opening = detail.opening_status.next_opening || storeData.value.next_opening
  }
}

const handleStoreUpdated = () => {
  clearCachedUser()
  fetchStoreHeaderData(true)
}

const loadUserPreferences = async () => {
  try {
    const { data } = await api.get('/merchant/preferences')
    orderAlert.applyPreferences(data.preferences || {})
  } catch {
    orderAlert.applyPreferences({
      new_order_sound_enabled: localStorage.getItem('partiumenu:new-order-sound-enabled') !== 'false',
      new_order_sound_unlocked: localStorage.getItem('partiumenu:new-order-sound-unlocked') === 'true'
    })
  }
}

let mountedInstanceId = null

onMounted(() => {
  mountedInstanceId = ++layoutInstanceSeq

  activeLayout = {
    instanceId: mountedInstanceId,
    fetchStoreHeaderData,
    handlePendingCountChange,
    handlePendingOrdersSync,
    handleSoundSettingsUpdated,
    handleStoreUpdated,
    handleStoreStatusChanged,
    handlePlayOrderAlert,
    unlockAudio,
    closeStoreSwitcher
  }

  ensureGlobalInfrastructure()
  loadUserPreferences()
  fetchStoreHeaderData()
})

onBeforeUnmount(() => {
  if (activeLayout?.instanceId === mountedInstanceId) {
    if (window.Echo && activeRealtimeStoreId) {
      window.Echo.leave(`store.${activeRealtimeStoreId}`)
    }

    realtimeSubscribed = false
    activeRealtimeStoreId = null
    activeLayout = null
  }
})
</script>

<template>
  <div class="min-h-screen bg-slate-100 md:bg-slate-50 flex">
    <transition name="fade">
      <div
        v-if="notificationToast.show"
        :class="[
          'fixed z-[120] animate-in slide-in-from-right',
          isMobileViewport ? 'left-4 right-4 top-4' : 'right-5 top-5'
        ]"
      >
        <div
          :class="[
            'px-6 py-3 rounded-2xl shadow-lg font-black text-white flex items-center gap-3',
            notificationToast.type === 'success' ? 'bg-emerald-500' : 'bg-red-500'
          ]"
        >
          <CheckCircle v-if="notificationToast.type === 'success'" size="20" />
          <XCircle v-else size="20" />
          {{ notificationToast.message }}
        </div>
      </div>
    </transition>

    <aside class="hidden md:flex w-64 bg-slate-950 text-slate-400 flex-col fixed h-full min-h-0 overflow-hidden shadow-2xl z-30">
      <div class="shrink-0 p-6">
        <img src="/logo-white.png" alt="PartiuMenu" class="h-14 w-full max-w-[208px] object-contain object-left" />
      </div>

      <div class="mx-4 mb-3 shrink-0 p-4 bg-white/5 border border-white/10 rounded-2xl">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">
          Plano atual
        </p>

        <div class="flex items-center justify-between mt-1 min-h-[24px]">
          <div
            v-if="isHeaderLoading"
            class="h-4 w-24 rounded-full bg-white/10 animate-pulse"
            aria-label="Carregando plano"
          ></div>

          <p
            v-else
            class="text-white font-black text-sm"
          >
            {{ visiblePlanName }}
          </p>
        </div>

        <div
          v-if="isHeaderLoading"
          class="h-3 w-32 rounded-full bg-white/10 animate-pulse mt-3"
          aria-label="Carregando uso de produtos"
        ></div>

        <p v-else-if="productsUsageLabel" class="text-[11px] mt-2 text-slate-400 font-bold">
          Produtos: {{ productsUsageLabel }}
        </p>

        <p
          v-if="!isHeaderLoading && storeData.name"
          class="text-[11px] mt-2 text-slate-500 font-bold leading-snug"
        >
          {{ storeData.name }}
          <span class="text-red-400/90">
            · {{ storeData.store_type === 'filial' ? 'Filial' : 'Matriz' }}
          </span>
        </p>
      </div>

      <nav class="min-h-0 flex-1 overflow-y-auto px-4 space-y-2 mt-2 pb-3">
        <button
          v-for="item in visibleMenuItems"
          :key="item.path"
          type="button"
          @click="handleMenuClick(item)"
          class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-bold hover:text-white text-left"
          :class="[
            route.path === item.path
              ? 'bg-red-500 text-white shadow-lg shadow-red-500/40'
              : 'hover:bg-white/5',
            !item.feature || isHeaderLoading || hasFeature(item.feature) ? '' : 'opacity-60'
          ]"
        >
          <component
            :is="item.icon"
            size="20"
            :class="route.path === item.path ? 'text-white' : 'text-slate-500'"
          />

          <span class="flex-1">{{ item.name }}</span>

          <span
            v-if="lockedFeaturePlanLabel(item.feature)"
            :class="[
              'rounded-md px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider shrink-0',
              lockedFeaturePlanLabel(item.feature) === 'Premium'
                ? 'bg-red-500/15 text-red-300'
                : 'bg-amber-500/15 text-amber-300'
            ]"
          >
            {{ lockedFeaturePlanLabel(item.feature) }}
          </span>

          <Lock
            v-if="item.feature && !isHeaderLoading && !hasFeature(item.feature)"
            size="15"
            class="text-slate-500 shrink-0"
          />
        </button>
      </nav>

      <div class="shrink-0 p-4 border-t border-white/5">
        <button
          @click="handleLogout"
          class="flex items-center gap-3 px-4 py-3 w-full rounded-xl hover:bg-red-500/10 hover:text-red-500 transition-all font-bold"
        >
          <LogOut size="20" />
          <span>Sair</span>
        </button>
      </div>
    </aside>

    <main class="flex-1 md:ml-64">
      <header
        v-if="isMobileViewport"
        class="sticky top-0 z-20 border-b border-white/5 bg-slate-950 px-4 pb-4 pt-[max(1rem,env(safe-area-inset-top))] text-white shadow-lg shadow-slate-950/20"
      >
        <div class="flex items-center justify-between gap-3">
          <div class="flex min-w-0 items-center gap-3">
            <div class="h-11 w-11 shrink-0 overflow-hidden rounded-2xl border border-white/10 bg-white/10 shadow-inner">
              <img
                v-if="!isHeaderLoading && storeData.logo_url"
                :src="storeData.logo_url"
                class="h-full w-full object-cover"
                :alt="storeData.name"
              >

              <div
                v-else-if="isHeaderLoading"
                class="h-full w-full animate-pulse bg-white/10"
                aria-label="Carregando logo"
              />

              <div
                v-else
                class="flex h-full w-full items-center justify-center text-sm font-black uppercase text-red-300"
              >
                {{ storeInitial }}
              </div>
            </div>

            <div class="min-w-0">
              <p
                v-if="isHeaderLoading"
                class="h-4 w-28 animate-pulse rounded-full bg-white/10"
                aria-label="Carregando loja"
              />

              <p v-else class="truncate text-sm font-black leading-tight">
                {{ storeData.name || 'Minha loja' }}
              </p>

              <div
                v-if="isHeaderLoading"
                class="mt-2 h-3 w-20 animate-pulse rounded-full bg-white/10"
                aria-label="Carregando status"
              />

              <p
                v-else
                class="mt-1 inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider"
                :class="storeData.is_open ? 'text-emerald-400' : 'text-red-300'"
              >
                <span
                  class="h-1.5 w-1.5 rounded-full"
                  :class="storeData.is_open ? 'bg-emerald-400 animate-pulse' : 'bg-red-300'"
                />
                {{ storeStatusLabel || 'Status da loja' }}
              </p>
            </div>
          </div>

          <button
            type="button"
            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white transition hover:bg-white/15 active:scale-95"
            aria-label="Sair"
            @click="handleLogout"
          >
            <LogOut size="18" />
          </button>
        </div>

        <div
          v-if="isMobileSummaryRoute"
          class="mt-4 flex items-center justify-between gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3"
        >
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Resumo mobile</p>
            <p class="truncate text-sm font-black text-white">Números da operação</p>
          </div>

          <span
            v-if="!isHeaderLoading && visiblePlanName"
            class="shrink-0 rounded-full bg-red-500/20 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-red-200"
          >
            {{ visiblePlanName }}
          </span>
        </div>

        <div
          v-else-if="showDesktopOnlyNotice"
          class="mt-4 rounded-2xl border border-amber-400/20 bg-amber-400/10 px-4 py-3"
        >
          <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-200/80">Somente desktop</p>
          <p class="truncate text-sm font-black text-white">{{ pageTitle }}</p>
        </div>
      </header>

      <header
        v-else
        class="h-20 bg-white border-b border-slate-200 px-8 flex items-center justify-between sticky top-0 z-20"
      >
        <h2 class="text-xl font-black text-slate-800 tracking-tight">
          {{ pageTitle }}
        </h2>

        <div class="flex items-center gap-4">
          <button
            class="p-2 text-slate-400 hover:text-red-500 transition-all relative group"
            @click="router.push('/orders')"
          >
            <Bell size="22" class="group-hover:scale-110 transition-transform" />

            <span
              v-if="storeData.pending_count > 0"
              class="absolute top-1 right-1 w-5 h-5 bg-red-600 text-white text-[10px] font-black rounded-full border-2 border-white flex items-center justify-center animate-bounce"
            >
              {{ storeData.pending_count }}
            </span>
          </button>

          <div class="flex items-center gap-3 pl-4 border-l border-slate-100">
            <div ref="storeSwitcherRef" class="text-right min-w-[140px] relative">
              <div
                v-if="isHeaderLoading"
                class="h-4 w-28 rounded-full bg-slate-100 animate-pulse ml-auto"
                aria-label="Carregando loja"
              ></div>

              <button
                v-else-if="hasMultipleStores"
                type="button"
                class="ml-auto flex items-center gap-1.5 text-sm font-black text-slate-800 leading-none hover:text-red-600 transition-colors"
                @click="storeSwitcherOpen = !storeSwitcherOpen"
              >
                <Building2 v-if="storeData.store_type === 'filial'" size="14" class="text-red-500 flex-shrink-0" />
                <span class="truncate max-w-[160px]">{{ currentStoreLabel }}</span>
                <ChevronDown size="14" class="flex-shrink-0" />
              </button>

              <p
                v-else
                class="text-sm font-black text-slate-800 leading-none"
              >
                {{ storeData.name }}
              </p>

              <div
                v-if="storeSwitcherOpen && hasMultipleStores"
                class="absolute right-0 top-full mt-2 z-50 w-56 rounded-2xl border border-slate-200 bg-white shadow-xl py-2 text-left"
              >
                <p class="px-4 py-1 text-[10px] font-black uppercase tracking-widest text-slate-400">Alternar loja</p>
                <button
                  v-for="item in accessibleStores"
                  :key="item.id"
                  type="button"
                  :disabled="switchingStore"
                  class="w-full px-4 py-2.5 text-left text-sm font-bold hover:bg-red-50 transition-colors flex items-center justify-between gap-2"
                  :class="item.id === currentStoreId ? 'text-red-600 bg-red-50/50' : 'text-slate-700'"
                  @click="switchStore(item.id)"
                >
                  <span class="truncate">{{ item.name }}</span>
                  <span class="text-[9px] font-black uppercase text-slate-400 flex-shrink-0">
                    {{ item.store_type === 'filial' ? 'Filial' : 'Matriz' }}
                  </span>
                </button>
              </div>

              <div
                v-if="isHeaderLoading"
                class="h-3 w-20 rounded-full bg-slate-100 animate-pulse ml-auto mt-2"
                aria-label="Carregando status da loja"
              ></div>

              <p
                v-else
                :class="[
                  'text-[10px] font-black uppercase tracking-widest mt-1',
                  storeData.is_open ? 'text-emerald-500' : 'text-red-600'
                ]"
              >
                {{ storeStatusLabel }}
              </p>
            </div>

            <div class="hidden md:block h-11 w-11 rounded-2xl bg-slate-100 border-2 border-slate-200 overflow-hidden shadow-sm hover:border-red-500 transition-colors cursor-pointer">
              <img
                v-if="!isHeaderLoading && storeData.logo_url"
                :src="storeData.logo_url"
                class="w-full h-full object-cover"
                :alt="storeData.name"
              >

              <div
                v-else-if="isHeaderLoading"
                class="w-full h-full bg-slate-200 animate-pulse"
                aria-label="Carregando logo"
              ></div>

              <div
                v-else
                class="w-full h-full flex items-center justify-center text-red-500 uppercase font-black text-sm"
              >
                {{ storeInitial }}
              </div>
            </div>
          </div>
        </div>
      </header>

      <div
        :class="[
          'md:p-8',
          isMobileViewport
            ? 'mx-auto w-full max-w-lg px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-4'
            : 'p-8'
        ]"
      >
        <PwaInstallBanner v-if="!isMobileViewport" />
        <DesktopOnlyNotice v-if="showDesktopOnlyNotice" :page-title="String(pageTitle)" />
        <router-view v-else />
      </div>
    </main>

    <transition name="fade">
      <div
        v-if="upgradeModal.show"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
      >
        <div
          class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm"
          @click="closeUpgradeModal"
        ></div>

        <div class="relative bg-white w-full max-w-md rounded-[2rem] p-8 shadow-2xl border border-slate-100">
          <div class="w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center mb-5">
            <Lock size="28" />
          </div>

          <h3 class="text-2xl font-black text-slate-900">
            {{ upgradeModal.title }}
          </h3>

          <p class="text-slate-500 font-bold text-sm leading-relaxed mt-3">
            {{ upgradeModal.message }}
          </p>

          <div class="mt-7 flex flex-col gap-2">
            <button
              @click="goToPlans"
              class="w-full bg-red-600 hover:bg-red-700 text-white py-4 rounded-2xl font-black transition-all"
            >
              Ver planos
            </button>

            <button
              @click="closeUpgradeModal"
              class="w-full py-4 rounded-2xl font-black text-slate-400 hover:bg-slate-50 transition-all"
            >
              Agora não
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
