<script setup>
import { ref, onMounted, reactive, computed, onBeforeUnmount } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import api from '@/services/api'
import axios from 'axios'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
  ShoppingBag,
  Clock,
  CheckCircle,
  Truck,
  XCircle,
  ChevronRight,
  Printer,
  Loader2,
  ChefHat
} from 'lucide-vue-next'

const orders = ref([])
const loading = ref(true)
const filterStatus = ref('all')
const selectedOrder = ref(null)
const modalDetails = ref(false)
const storeId = ref(null)
const realtimeInitialized = ref(false)

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || import.meta.env.VITE_API_URL || 'http://localhost:8000')
  .replace(/\/api\/v1\/?$/, '')
  .replace(/\/$/, '')

const rejectModal = reactive({
  show: false,
  id: null,
  loading: false
})

const toast = ref({ show: false, message: '', type: 'success' })

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const playNewOrderBeep = () => {
  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext

    if (!AudioContext) return

    const audioContext = new AudioContext()
    const oscillator = audioContext.createOscillator()
    const gain = audioContext.createGain()

    oscillator.type = 'sine'
    oscillator.frequency.setValueAtTime(880, audioContext.currentTime)
    oscillator.frequency.setValueAtTime(660, audioContext.currentTime + 0.12)

    gain.gain.setValueAtTime(0.0001, audioContext.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.35, audioContext.currentTime + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.35)

    oscillator.connect(gain)
    gain.connect(audioContext.destination)

    oscillator.start()
    oscillator.stop(audioContext.currentTime + 0.38)
  } catch (error) {
    console.warn('[Orders Beep Error]', error)
  }
}

const statusMap = {
  pending: {
    label: 'Pedido recebido',
    shortLabel: 'Recebido',
    color: 'bg-amber-100 text-amber-700',
    icon: Clock
  },
  preparing: {
    label: 'Em preparo',
    shortLabel: 'Preparo',
    color: 'bg-orange-100 text-orange-700',
    icon: ChefHat
  },
  ready: {
    label: 'Pronto para entrega',
    shortLabel: 'Pronto',
    color: 'bg-emerald-100 text-emerald-700',
    icon: CheckCircle
  },
  shipped: {
    label: 'Saiu para entrega',
    shortLabel: 'Em entrega',
    color: 'bg-blue-100 text-blue-700',
    icon: Truck
  },
  delivered: {
    label: 'Pedido entregue',
    shortLabel: 'Entregue',
    color: 'bg-slate-100 text-slate-600',
    icon: CheckCircle
  },
  canceled: {
    label: 'Pedido cancelado',
    shortLabel: 'Cancelado',
    color: 'bg-red-100 text-red-700',
    icon: XCircle
  }
}

const statusFilters = {
  all: 'Todos',
  pending: 'Recebidos',
  preparing: 'Em preparo',
  ready: 'Prontos',
  shipped: 'Em entrega',
  delivered: 'Entregues'
}

const normalizeOrderStatus = (status) => {
  const aliases = {
    confirmed: 'preparing',
    out_for_delivery: 'shipped',
    completed: 'delivered',
    cancelled: 'canceled'
  }

  return aliases[status] || status
}

const getStatusInfo = (status) => {
  return statusMap[normalizeOrderStatus(status)] || {
    label: 'Status desconhecido',
    shortLabel: 'Desconhecido',
    color: 'bg-slate-100 text-slate-500',
    icon: Clock
  }
}

const getCouponCode = (order) => {
  return order?.coupon?.code || order?.coupon_code || 'Cupom removido'
}

const getCouponDescription = (order) => {
  return order?.coupon?.description || order?.coupon_description || null
}

const hasCouponDiscount = (order) => {
  return Number(order?.discount_amount || 0) > 0
}

const handlePrintOrder = async (orderId) => {
  if (!orderId) return

  try {
    const response = await api.get(`/merchant/orders/${orderId}/print`, {
      headers: {
        Accept: 'text/html'
      },
      responseType: 'text'
    })

    const iframe = document.createElement('iframe')
    iframe.style.position = 'fixed'
    iframe.style.top = '0'
    iframe.style.left = '0'
    iframe.style.width = '0'
    iframe.style.height = '0'
    iframe.style.border = '0'
    document.body.appendChild(iframe)

    const doc = iframe.contentWindow.document
    doc.open()
    doc.write(response.data)
    doc.close()

    iframe.onload = () => {
      setTimeout(() => {
        iframe.contentWindow.focus()
        iframe.contentWindow.print()

        setTimeout(() => {
          document.body.removeChild(iframe)
        }, 500)
      }, 300)
    }
  } catch (err) {
    console.error('Erro ao gerar impressão:', err)

    if (err.response?.status === 403) {
      showNotify('Você não tem permissão para imprimir este pedido.', 'error')
    } else {
      showNotify('Não foi possível carregar o cupom.', 'error')
    }
  }
}

const formatOrderDate = (dateString) => {
  if (!dateString) return 'Data não informada'

  return new Date(dateString).toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  })
}

const formatOrderTime = (dateString) => {
  if (!dateString) return '--:--'

  return new Date(dateString).toLocaleTimeString('pt-BR', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const formatOrderDateTime = (dateString) => {
  if (!dateString) return 'Data não informada'
  return `${formatOrderDate(dateString)} às ${formatOrderTime(dateString)}`
}

const formatMoney = (value) => {
  const amount = Number(value) || 0

  return amount.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })
}

const setupRealtimeListener = () => {
  if (!storeId.value || realtimeInitialized.value) return

  const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY
  const pusherCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER

  if (!pusherKey || !pusherCluster) {
    console.warn('[Orders Realtime] Pusher env vars ausentes.')
    return
  }

  realtimeInitialized.value = true
  window.Pusher = Pusher

  const token = localStorage.getItem('auth_token')

  if (window.Echo) {
    window.Echo.leave(`store.${storeId.value}`)
    window.Echo.disconnect()
  }

  window.Echo = new Echo({
    broadcaster: 'pusher',
    key: pusherKey,
    cluster: pusherCluster,
    forceTLS: (import.meta.env.VITE_PUSHER_SCHEME || 'https') === 'https',
    encrypted: true,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${apiBaseUrl}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json'
      }
    },
    authorizer: (channel) => {
      return {
        authorize: (socketId, callback) => {
          axios.post(`${apiBaseUrl}/broadcasting/auth`, {
            socket_id: socketId,
            channel_name: channel.name
          }, {
            headers: {
              Authorization: `Bearer ${token}`,
              Accept: 'application/json'
            }
          })
            .then(response => callback(false, response.data))
            .catch(error => {
              console.error('[Orders Echo Auth Error]', {
                status: error.response?.status,
                data: error.response?.data,
                channel: channel.name
              })

              callback(true, error)
            })
        }
      }
    }
  })

  window.Echo.private(`store.${storeId.value}`)
    .listen('.order.created', (e) => {
      if (!orders.value.some(o => o.id === e.order.id)) {
        orders.value.unshift(e.order)
        playNewOrderBeep()
        showNotify(`Novo pedido! #${e.order.id}`)
      }
    })
    .listen('.order.updated', (e) => {
      const index = orders.value.findIndex(o => o.id === e.order.id)

      if (index !== -1) {
        orders.value[index] = { ...orders.value[index], ...e.order }
      }

      if (selectedOrder.value?.id === e.order.id) {
        selectedOrder.value = { ...selectedOrder.value, ...e.order }
      }
    })
    .error((error) => {
      console.error('[Orders Echo Error]', error)
    })
}

const fetchOrders = async () => {
  loading.value = true

  try {
    const userResponse = await api.get('/me')

    if (userResponse.data?.store?.id) {
      storeId.value = userResponse.data.store.id
      setupRealtimeListener()
    }

    const { data } = await api.get('/merchant/orders')
    orders.value = Array.isArray(data) ? data : (data.data || [])
  } catch (err) {
    console.error('Erro ao carregar:', err)
    showNotify(err.response?.data?.message || 'Erro ao carregar pedidos.', 'error')
  } finally {
    loading.value = false
  }
}

const openRejectModal = (orderId) => {
  rejectModal.id = orderId
  rejectModal.show = true
}

const handleRejectOrder = async () => {
  rejectModal.loading = true

  try {
    await updateStatus(rejectModal.id, 'canceled')
    rejectModal.show = false
    showNotify('Pedido cancelado e cliente notificado.', 'error')
  } catch (err) {
    showNotify('Erro ao cancelar pedido.', 'error')
  } finally {
    rejectModal.loading = false
  }
}

const acceptOrder = (orderId) => {
  updateStatus(orderId, 'preparing')
}

const updateStatus = async (orderId, newStatus) => {
  try {
    const { data } = await api.patch(`/merchant/orders/${orderId}/status`, {
      status: newStatus
    })

    showNotify(`Pedido atualizado para ${getStatusInfo(newStatus).label}.`)

    const index = orders.value.findIndex(o => o.id === orderId)

    if (index !== -1) {
      orders.value[index] = { ...orders.value[index], ...data.order }
    }

    if (selectedOrder.value?.id === orderId) {
      selectedOrder.value = { ...selectedOrder.value, ...data.order }

      if (['canceled', 'delivered'].includes(newStatus)) {
        modalDetails.value = false
      }
    }
  } catch (err) {
    const errorMsg = err.response?.data?.details || 'Erro ao atualizar status.'
    showNotify(errorMsg, 'error')
  }
}

const openDetails = (order) => {
  selectedOrder.value = order
  modalDetails.value = true
}

const filteredOrders = computed(() => {
  if (filterStatus.value === 'all') return orders.value
  return orders.value.filter(o => normalizeOrderStatus(o.status) === filterStatus.value)
})

const selectedOrderStatus = computed(() => normalizeOrderStatus(selectedOrder.value?.status))

onMounted(fetchOrders)

onBeforeUnmount(() => {
  if (window.Echo && storeId.value) {
    window.Echo.leave(`store.${storeId.value}`)
    window.Echo.disconnect()
  }
})
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

    <div class="space-y-8 animate-in fade-in duration-500 pb-10">
      <header
        class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
        <div class="flex items-center gap-4">
          <div
            class="w-12 h-12 bg-red-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-red-100">
            <ShoppingBag size="28" />
          </div>

          <div>
            <h1 class="text-2xl font-black text-slate-900">Pedidos</h1>
            <p class="text-slate-500 text-sm">Gerencie as vendas em tempo real.</p>
          </div>
        </div>

        <div class="flex bg-slate-100 p-1 rounded-2xl overflow-x-auto">
          <button
            v-for="(val, key) in statusFilters"
            :key="key"
            @click="filterStatus = key"
            :class="[
              'px-4 py-2 rounded-xl text-xs font-black transition-all whitespace-nowrap',
              filterStatus === key
                ? 'bg-white shadow-sm text-red-500'
                : 'text-slate-500 hover:text-slate-700'
            ]">
            {{ val }}
          </button>
        </div>
      </header>

      <div v-if="loading" class="p-20 flex justify-center text-red-500">
        <Loader2 class="animate-spin" size="32" />
      </div>

      <div v-else-if="filteredOrders.length === 0"
        class="p-20 text-center bg-white rounded-3xl border border-dashed border-slate-200">
        <ShoppingBag class="mx-auto text-slate-200 mb-4" size="48" />
        <p class="text-slate-500 font-medium">Nenhum pedido encontrado nesta categoria.</p>
      </div>

      <div v-else class="grid gap-4">
        <div v-for="order in filteredOrders" :key="order.id" @click="openDetails(order)"
          class="bg-white p-5 rounded-3xl border border-slate-200 shadow-sm hover:border-red-200 transition-all cursor-pointer group flex items-center justify-between">
          <div class="flex items-center gap-5">
            <div :class="[
              'w-14 h-14 rounded-2xl flex items-center justify-center transition-transform group-hover:scale-105',
              getStatusInfo(order.status).color
            ]">
              <component :is="getStatusInfo(order.status).icon" size="24" />
            </div>

            <div>
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-black text-slate-900 text-lg">
                  #{{ order.id.toString().padStart(4, '0') }}
                </span>

                <span class="text-[10px] font-black uppercase px-2 py-1 bg-orange-50 rounded-lg text-orange-600">
                  {{ order.type === 'sale' ? 'Entrega' : 'Aluguel' }}
                </span>

                <span
                  class="text-[10px] font-black uppercase px-2 py-1 bg-slate-100 rounded-lg text-slate-500 flex items-center gap-1">
                  <Clock size="12" />
                  {{ formatOrderDateTime(order.created_at) }}
                </span>
              </div>

              <div class="text-slate-500 text-sm font-medium mt-1">
                <span v-for="(item, idx) in order.items" :key="item.id" class="text-slate-700 font-bold">
                  {{ item.quantity }}x {{ item.product?.name }}{{ idx < order.items.length - 1 ? ', ' : '' }}
                </span>

                <span class="text-red-500 ml-1">• R$ {{ formatMoney(order.total_amount) }}</span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-4">
            <div class="hidden md:block text-right">
              <p class="text-xs font-black text-slate-400 uppercase tracking-widest">Status</p>
              <p class="font-bold text-slate-700">{{ getStatusInfo(order.status).label }}</p>
              <p class="text-[11px] font-bold text-slate-400 mt-1">
                {{ formatOrderTime(order.created_at) }}
              </p>
            </div>

            <ChevronRight class="text-slate-300 group-hover:text-red-500 transition-colors" />
          </div>
        </div>
      </div>
    </div>

    <transition name="slide-fade">
      <div v-if="modalDetails" class="fixed inset-0 z-[70] flex justify-end">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="modalDetails = false"></div>

        <div class="relative w-full max-w-xl bg-slate-50 h-screen shadow-2xl flex flex-col animate-slide-in">
          <div class="p-8 bg-white border-b border-slate-100 flex justify-between items-center">
            <div>
              <h2 class="text-2xl font-black text-slate-900">Pedido #{{ selectedOrder?.id }}</h2>

              <p class="text-red-500 text-sm font-bold uppercase tracking-tighter">
                Detalhes do Cliente e Itens
              </p>

              <p class="text-xs font-black text-slate-400 uppercase tracking-widest mt-1 flex items-center gap-1">
                <Clock size="13" />
                {{ formatOrderDateTime(selectedOrder?.created_at) }}
              </p>
            </div>

            <button @click="modalDetails = false"
              class="p-3 bg-slate-100 rounded-full hover:bg-red-500 hover:text-white transition-all shadow-sm">
              <XCircle size="24" />
            </button>
          </div>

          <div class="flex-grow overflow-y-auto p-8 space-y-8">
            <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100">
              <h3 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">Ações do Pedido</h3>

              <div class="grid grid-cols-2 gap-3">
                <div
                  v-if="selectedOrderStatus === 'delivered'"
                  class="col-span-2 rounded-2xl border border-emerald-100 bg-emerald-50 p-5"
                >
                  <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-lg shadow-emerald-100">
                      <CheckCircle size="24" />
                    </div>

                    <div>
                      <p class="text-sm font-black uppercase tracking-wider text-emerald-700">
                        Pedido entregue
                      </p>
                      <p class="mt-1 text-xs font-bold leading-relaxed text-emerald-700/80">
                        Este pedido foi finalizado. Você ainda pode imprimir o cupom ou consultar os dados do cliente e itens abaixo.
                      </p>
                    </div>
                  </div>
                </div>

                <div v-if="selectedOrderStatus === 'canceled'" class="flex items-center gap-2 col-span-2">
                  <div class="h-px flex-1 bg-slate-100"></div>
                  <span
                    class="flex items-center gap-1.5 px-3 py-1 bg-red-50 text-red-600 rounded-full text-[10px] font-black uppercase tracking-wider border border-red-100">
                    <XCircle size="12" />
                    Pedido cancelado
                  </span>
                  <div class="h-px flex-1 bg-slate-100"></div>
                </div>

                <button v-if="selectedOrderStatus === 'pending'" @click="acceptOrder(selectedOrder.id)"
                  class="col-span-2 bg-red-600 hover:bg-red-700 text-white p-5 rounded-2xl font-black flex items-center justify-center gap-3 transition-all active:scale-95 shadow-lg shadow-red-100">
                  <ChefHat size="24" />
                  <span class="text-lg uppercase">Aceitar pedido</span>
                </button>

                <button v-if="['pending', 'preparing', 'ready'].includes(selectedOrderStatus)"
                  @click="openRejectModal(selectedOrder.id)" :class="[
                    'p-4 rounded-2xl font-black transition-all active:scale-95 flex items-center justify-center gap-2',
                    selectedOrderStatus === 'pending'
                      ? 'col-span-2 bg-slate-100 text-slate-400 hover:bg-red-500 hover:text-white'
                      : 'bg-red-50 text-red-400 hover:bg-red-100'
                  ]">
                  <XCircle size="20" />
                  Cancelar
                </button>

                <button v-if="selectedOrderStatus === 'preparing'" @click="updateStatus(selectedOrder.id, 'ready')"
                  class="bg-emerald-500 text-white p-4 rounded-2xl font-black flex items-center justify-center gap-2 active:scale-95 transition-all">
                  <CheckCircle size="20" />
                  Marcar pronto
                </button>

                <button v-if="selectedOrderStatus === 'ready'" @click="updateStatus(selectedOrder.id, 'shipped')"
                  class="bg-blue-500 text-white p-4 rounded-2xl font-black flex items-center justify-center gap-2 active:scale-95 transition-all">
                  <Truck size="20" />
                  Saiu entrega
                </button>

                <button v-if="selectedOrderStatus === 'shipped'" @click="updateStatus(selectedOrder.id, 'delivered')"
                  class="col-span-2 bg-emerald-600 text-white p-4 rounded-2xl font-black flex items-center justify-center gap-2 active:scale-95 transition-all">
                  <CheckCircle size="20" />
                  Pedido entregue
                </button>

                <button
                  @click="handlePrintOrder(selectedOrder.id)"
                  class="col-span-2 border border-slate-200 bg-white p-4 rounded-2xl font-black text-slate-600 flex items-center justify-center gap-2 hover:bg-slate-50 transition"
                >
                  <Printer size="20" />
                  Imprimir cupom
                </button>
              </div>
            </div>

            <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100">
              <h3 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">Cliente</h3>
              <p class="font-black text-lg text-slate-900">{{ selectedOrder?.customer_name || selectedOrder?.user?.name || 'Cliente' }}</p>
              <p class="text-sm font-bold text-slate-500">{{ selectedOrder?.customer_phone || selectedOrder?.phone || 'Telefone não informado' }}</p>
              <p v-if="selectedOrder?.delivery_address" class="text-sm font-semibold text-slate-500 mt-2">{{ selectedOrder.delivery_address }}</p>
            </div>

            <div v-if="hasCouponDiscount(selectedOrder)" class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100">
              <h3 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">Cupom aplicado</h3>
              <div class="rounded-2xl bg-red-50 border border-red-100 p-4">
                <p class="font-black text-red-700">{{ getCouponCode(selectedOrder) }}</p>
                <p v-if="getCouponDescription(selectedOrder)" class="text-xs font-bold text-red-500 mt-1">{{ getCouponDescription(selectedOrder) }}</p>
                <p class="text-sm font-black text-red-700 mt-2">Desconto: R$ {{ formatMoney(selectedOrder?.discount_amount) }}</p>
              </div>
            </div>

            <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100">
              <h3 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">Itens</h3>
              <div class="space-y-3">
                <div v-for="item in selectedOrder?.items || []" :key="item.id" class="flex justify-between gap-4 text-sm">
                  <div>
                    <p class="font-black text-slate-800">{{ item.quantity }}x {{ item.product?.name || item.product_name || 'Item' }}</p>
                    <p v-if="item.notes" class="text-xs font-semibold text-slate-400">{{ item.notes }}</p>
                  </div>
                  <p class="font-black text-slate-900">R$ {{ formatMoney(item.total || item.price * item.quantity) }}</p>
                </div>
              </div>
            </div>

            <div class="bg-slate-950 text-white p-6 rounded-3xl shadow-sm">
              <div class="flex justify-between text-sm font-bold text-slate-300">
                <span>Subtotal</span>
                <span>R$ {{ formatMoney(selectedOrder?.subtotal || selectedOrder?.total_amount) }}</span>
              </div>
              <div v-if="hasCouponDiscount(selectedOrder)" class="flex justify-between text-sm font-bold text-red-300 mt-2">
                <span>Desconto</span>
                <span>- R$ {{ formatMoney(selectedOrder?.discount_amount) }}</span>
              </div>
              <div class="flex justify-between text-xl font-black mt-4 pt-4 border-t border-white/10">
                <span>Total</span>
                <span>R$ {{ formatMoney(selectedOrder?.total_amount) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </transition>

    <transition name="fade">
      <div v-if="rejectModal.show" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" @click="rejectModal.show = false"></div>
        <div class="relative w-full max-w-md rounded-3xl bg-white p-7 shadow-2xl">
          <h3 class="text-xl font-black text-slate-950">Cancelar pedido?</h3>
          <p class="mt-2 text-sm font-semibold text-slate-500">Essa ação marcará o pedido como cancelado.</p>
          <div class="mt-6 flex gap-3">
            <button @click="rejectModal.show = false" class="flex-1 rounded-2xl bg-slate-100 py-3 text-sm font-black text-slate-600">Voltar</button>
            <button @click="handleRejectOrder" :disabled="rejectModal.loading" class="flex-1 rounded-2xl bg-red-600 py-3 text-sm font-black text-white disabled:opacity-60">
              <Loader2 v-if="rejectModal.loading" class="mx-auto animate-spin" size="18" />
              <span v-else>Cancelar</span>
            </button>
          </div>
        </div>
      </div>
    </transition>
  </DashboardLayout>
</template>
