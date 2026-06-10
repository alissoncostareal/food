<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/services/api'
import {
  TrendingUp,
  ShoppingBag,
  UtensilsCrossed,
  FolderTree,
  Settings,
  CreditCard,
  LogOut,
  Bell,
  Ticket,
  Lock,
  FileSpreadsheet,
  MapPin,
  CheckCircle,
  XCircle,
  Volume2,
  VolumeX
} from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()

const isHeaderLoading = ref(true)
const realtimeStoreId = ref(null)
const realtimeInitialized = ref(false)
const audioContext = ref(null)
const notificationToast = ref({ show: false, message: '', type: 'success' })

const newOrderSoundEnabled = ref(localStorage.getItem('partiumenu:new-order-sound-enabled') !== 'false')
const newOrderSoundUnlocked = ref(localStorage.getItem('partiumenu:new-order-sound-unlocked') === 'true')

const storeData = ref({
  name: '',
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

const upgradeModal = ref({
  show: false,
  title: '',
  message: ''
})

const pageTitle = computed(() => route.meta?.title || route.name || 'Painel')

const menuItems = [
  { name: 'Dashboard', path: '/dashboard', icon: TrendingUp },
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
    name: 'Relatórios',
    path: '/reports',
    icon: FileSpreadsheet,
    feature: 'advanced_reports',
    upgradeTitle: 'Relatórios avançados são Premium',
    upgradeMessage: 'Exporte vendas mensais, pagamentos, produtos vendidos e pedidos detalhados para facilitar o fechamento financeiro.'
  },
  {
    name: 'Áreas',
    path: '/delivery-areas',
    icon: MapPin,
    feature: 'delivery_areas',
    upgradeTitle: 'Áreas de entrega disponíveis no plano Pro',
    upgradeMessage: 'Defina bairros atendidos, taxas e prazos para bloquear pedidos fora da sua operação.'
  },
  { name: 'Meu Plano', path: '/billing', icon: CreditCard },
  { name: 'Configurações', path: '/settings', icon: Settings }
]

const hasFeature = (feature) => {
  if (!feature) return true

  if (isHeaderLoading.value) return true

  return Boolean(storeData.value?.plan?.features?.[feature])
}

const visiblePlanName = computed(() => {
  if (isHeaderLoading.value) return ''

  return storeData.value?.plan?.name || 'Sem plano'
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

  if (storeData.value.is_open) return 'Loja Aberta'

  return storeData.value.manual_is_open ? 'Fora do Horário' : 'Loja Fechada'
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

const unlockAudio = async () => {
  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext

    if (!AudioContext) return false

    if (!audioContext.value) {
      audioContext.value = new AudioContext()
    }

    if (audioContext.value.state === 'suspended') {
      await audioContext.value.resume()
    }

    const unlocked = audioContext.value.state === 'running'

    if (unlocked) {
      newOrderSoundUnlocked.value = true
      localStorage.setItem('partiumenu:new-order-sound-unlocked', 'true')
    }

    return unlocked
  } catch (error) {
    console.warn('[Layout Audio Unlock Error]', error)
    return false
  }
}

const activateNewOrderSound = async () => {
  newOrderSoundEnabled.value = true
  localStorage.setItem('partiumenu:new-order-sound-enabled', 'true')

  const unlocked = await unlockAudio()

  if (unlocked) {
    playNewOrderBeep()
    showNotificationToast('Som de novos pedidos ativado.')
  } else {
    showNotificationToast('Clique novamente para liberar o som no navegador.', 'error')
  }
}

const disableNewOrderSound = () => {
  newOrderSoundEnabled.value = false
  localStorage.setItem('partiumenu:new-order-sound-enabled', 'false')
  showNotificationToast('Som de novos pedidos desativado.', 'error')
}

const playNewOrderBeep = () => {
  try {
    if (!newOrderSoundEnabled.value) return

    unlockAudio()

    if (!audioContext.value || audioContext.value.state !== 'running') return

    const oscillator = audioContext.value.createOscillator()
    const gain = audioContext.value.createGain()
    const now = audioContext.value.currentTime

    oscillator.type = 'sine'
    oscillator.frequency.setValueAtTime(880, now)
    oscillator.frequency.setValueAtTime(660, now + 0.12)

    gain.gain.setValueAtTime(0.0001, now)
    gain.gain.exponentialRampToValueAtTime(0.35, now + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.35)

    oscillator.connect(gain)
    gain.connect(audioContext.value.destination)

    oscillator.start(now)
    oscillator.stop(now + 0.38)
  } catch (error) {
    console.warn('[Layout Beep Error]', error)
  }
}

const handleSoundSettingsUpdated = async (event) => {
  const enabled = Boolean(event.detail?.enabled)
  const shouldTest = Boolean(event.detail?.test)

  newOrderSoundEnabled.value = enabled
  localStorage.setItem('partiumenu:new-order-sound-enabled', enabled ? 'true' : 'false')

  if (!enabled) {
    disableNewOrderSound()
    return
  }

  const unlocked = await unlockAudio()

  if (shouldTest && unlocked) {
    playNewOrderBeep()
  }

  if (unlocked) {
    showNotificationToast('Som de novos pedidos ativado.')
  }
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
  router.push('/plans')
}

const handleMenuClick = (item) => {
  if (!hasFeature(item.feature)) {
    openUpgradeModal(item)
    return
  }

  router.push(item.path)
}

const setupGlobalRealtime = () => {
  if (!window.Echo || !realtimeStoreId.value || realtimeInitialized.value) return

  realtimeInitialized.value = true

  window.Echo.leave(`store.${realtimeStoreId.value}`)
  window.Echo.private(`store.${realtimeStoreId.value}`)
    .listen('.order.created', async (event) => {
      storeData.value.pending_count = Number(storeData.value.pending_count || 0) + 1
      playNewOrderBeep()
      showNotificationToast(`Novo pedido! #${event.order.id}`)
      window.dispatchEvent(new CustomEvent('partiumenu:order-created', { detail: event }))
    })
    .listen('.order.updated', async (event) => {
      await fetchStoreHeaderData(true)
      window.dispatchEvent(new CustomEvent('partiumenu:order-updated', { detail: event }))
    })
    .error((error) => {
      console.error('[Layout Echo Error]', error)
    })
}

const fetchStoreHeaderData = async (silent = false) => {
  if (!silent) isHeaderLoading.value = true

  try {
    const [{ data: statsResponse }, { data: storeResponse }] = await Promise.all([
      api.get('/merchant/stats'),
      api.get('/merchant/store')
    ])

    const store = storeResponse.data || storeResponse
    const statsStore = statsResponse.store || {}

    realtimeStoreId.value = statsStore.id || store.id || realtimeStoreId.value

    storeData.value = {
      name: statsStore.name || store.name || '',
      logo_url: statsStore.logo_url || store.logo_url || null,
      pending_count: statsStore.pending_count || 0,
      is_open: Boolean(statsStore.is_open ?? store.is_open_now),
      manual_is_open: Boolean(statsStore.manual_is_open ?? store.is_open),
      status_message: store.status_message || store.opening_status?.message || null,
      opening_status: store.opening_status || null,
      next_opening: store.next_opening || store.opening_status?.next_opening || null,
      plan: store.plan || null,
      products_usage: store.products_usage || null
    }

    setupGlobalRealtime()
  } catch (error) {
    console.error('Erro ao carregar dados do header:', error)

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
  localStorage.removeItem('auth_token')
  router.push('/login')
}

onMounted(() => {
  window.addEventListener('click', unlockAudio, { once: true })
  window.addEventListener('keydown', unlockAudio, { once: true })
  window.addEventListener('partiumenu:sound-settings-updated', handleSoundSettingsUpdated)
  fetchStoreHeaderData()
})

onBeforeUnmount(() => {
  window.removeEventListener('click', unlockAudio)
  window.removeEventListener('keydown', unlockAudio)
  window.removeEventListener('partiumenu:sound-settings-updated', handleSoundSettingsUpdated)

  if (window.Echo && realtimeStoreId.value) {
    window.Echo.leave(`store.${realtimeStoreId.value}`)
  }
})
</script>

<template>
  <div class="min-h-screen bg-orange-50/30 flex">
    <transition name="fade">
      <div v-if="notificationToast.show" class="fixed right-5 top-5 z-[120] animate-in slide-in-from-right">
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

    <aside class="w-64 bg-slate-950 text-slate-400 flex flex-col fixed h-full shadow-2xl z-30">
      <div class="p-6 flex items-center gap-3">
        <div class="w-10 h-10 bg-red-500 rounded-xl flex items-center justify-center text-white shadow-lg shadow-red-900/20">
          <UtensilsCrossed size="22" />
        </div>

        <span class="text-white font-black text-2xl tracking-tighter">
          Partiu<span class="text-red-500">Menu</span>
        </span>
      </div>

      <div class="mx-4 mb-3 p-4 bg-white/5 border border-white/10 rounded-2xl">
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

          <span
            v-if="!isHeaderLoading && storeData.plan?.slug"
            class="text-[10px] uppercase font-black px-2 py-1 rounded-full bg-red-500/10 text-red-400"
          >
            {{ storeData.plan.slug }}
          </span>
        </div>

        <div
          v-if="isHeaderLoading"
          class="h-3 w-32 rounded-full bg-white/10 animate-pulse mt-3"
          aria-label="Carregando uso de produtos"
        ></div>

        <p v-else-if="productsUsageLabel" class="text-[11px] mt-2 text-slate-400 font-bold">
          Produtos: {{ productsUsageLabel }}
        </p>
      </div>

      <nav class="flex-1 px-4 space-y-2 mt-2">
        <button
          v-for="item in menuItems"
          :key="item.path"
          type="button"
          @click="handleMenuClick(item)"
          class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-bold hover:text-white text-left"
          :class="[
            route.path === item.path
              ? 'bg-red-500 text-white shadow-lg shadow-red-500/40'
              : 'hover:bg-white/5',
            !hasFeature(item.feature) ? 'opacity-60' : ''
          ]"
        >
          <component
            :is="item.icon"
            size="20"
            :class="route.path === item.path ? 'text-white' : 'text-slate-500'"
          />

          <span class="flex-1">{{ item.name }}</span>

          <Lock
            v-if="!hasFeature(item.feature)"
            size="15"
            class="text-slate-500"
          />
        </button>
      </nav>

      <div class="mx-4 mb-3 rounded-2xl border border-white/10 bg-white/5 p-3">
        <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">
          Som de pedidos
        </p>

        <button
          v-if="newOrderSoundEnabled && newOrderSoundUnlocked"
          type="button"
          @click="disableNewOrderSound"
          class="mt-2 flex w-full items-center justify-between gap-2 rounded-xl bg-emerald-500/10 px-3 py-2 text-left text-emerald-400 transition-colors hover:bg-emerald-500/15"
        >
          <span class="flex items-center gap-2 text-xs font-black">
            <Volume2 size="15" />
            Ativado
          </span>
          <span class="text-[10px] font-bold text-emerald-300">desligar</span>
        </button>

        <button
          v-else-if="newOrderSoundEnabled"
          type="button"
          @click="activateNewOrderSound"
          class="mt-2 flex w-full items-center justify-between gap-2 rounded-xl bg-amber-500/10 px-3 py-2 text-left text-amber-300 transition-colors hover:bg-amber-500/15"
        >
          <span class="flex items-center gap-2 text-xs font-black">
            <Volume2 size="15" />
            Liberar som
          </span>
          <span class="text-[10px] font-bold text-amber-200">clicar</span>
        </button>

        <button
          v-else
          type="button"
          @click="activateNewOrderSound"
          class="mt-2 flex w-full items-center justify-between gap-2 rounded-xl bg-white/5 px-3 py-2 text-left text-slate-400 transition-colors hover:bg-white/10"
        >
          <span class="flex items-center gap-2 text-xs font-black">
            <VolumeX size="15" />
            Desativado
          </span>
          <span class="text-[10px] font-bold text-slate-500">ativar</span>
        </button>
      </div>

      <div class="p-4 border-t border-white/5">
        <button
          @click="handleLogout"
          class="flex items-center gap-3 px-4 py-3 w-full rounded-xl hover:bg-red-500/10 hover:text-red-500 transition-all font-bold"
        >
          <LogOut size="20" />
          <span>Sair</span>
        </button>
      </div>
    </aside>

    <main class="flex-1 ml-64">
      <header class="h-20 bg-white border-b border-slate-200 px-8 flex items-center justify-between sticky top-0 z-20">
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
            <div class="text-right hidden md:block min-w-[120px]">
              <div
                v-if="isHeaderLoading"
                class="h-4 w-28 rounded-full bg-slate-100 animate-pulse ml-auto"
                aria-label="Carregando loja"
              ></div>

              <p
                v-else
                class="text-sm font-black text-slate-800 leading-none"
              >
                {{ storeData.name }}
              </p>

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

            <div class="h-11 w-11 rounded-2xl bg-slate-100 border-2 border-slate-200 overflow-hidden shadow-sm hover:border-red-500 transition-colors cursor-pointer">
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

      <div class="p-8">
        <slot />
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
main {
  animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(5px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>