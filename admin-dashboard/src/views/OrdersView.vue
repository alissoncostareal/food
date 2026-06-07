<script setup>
import { ref, onMounted, reactive, computed } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import api from '@/services/api'
import axios from 'axios'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
  ShoppingBag, Clock, CheckCircle, Truck, XCircle,
  ChevronRight, Printer, Loader2, ChefHat
} from 'lucide-vue-next'

const orders = ref([])
const loading = ref(true)
const filterStatus = ref('all')
const selectedOrder = ref(null)
const modalDetails = ref(false)
const storeId = ref(null)

const rejectModal = reactive({
  show: false,
  id: null,
  loading: false
})

const handlePrintOrder = async (orderId) => {
  if (!orderId) return

  try {
    const response = await api.get(`/merchant/orders/${orderId}/print`, {
      headers: {
        'Accept': 'text/html'
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

const toast = ref({ show: false, message: '', type: 'success' })

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const statusMap = {
  pending: { label: 'Pendente', color: 'bg-amber-100 text-amber-600', icon: Clock },
  preparing: { label: 'Na Cozinha', color: 'bg-orange-100 text-orange-600', icon: ChefHat },
  ready: { label: 'Pronto', color: 'bg-emerald-100 text-emerald-600', icon: CheckCircle },
  shipped: { label: 'Em Entrega', color: 'bg-blue-100 text-blue-600', icon: Truck },
  delivered: { label: 'Entregue', color: 'bg-slate-100 text-slate-500', icon: CheckCircle },
  canceled: { label: 'Cancelado', color: 'bg-red-100 text-red-600', icon: XCircle }
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

const setupRealtimeListener = () => {
  if (!storeId.value) return

  window.Pusher = Pusher

  window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
    forceTLS: true,
    authEndpoint: `${import.meta.env.VITE_API_BASE_URL}/broadcasting/auth`,
    authorizer: (channel) => {
      return {
        authorize: (socketId, callback) => {
          const token = localStorage.getItem('auth_token')

          axios.post(`${import.meta.env.VITE_API_BASE_URL}/broadcasting/auth`, {
            socket_id: socketId,
            channel_name: channel.name
          }, {
            headers: {
              Authorization: `Bearer ${token}`,
              Accept: 'application/json',
              'X-Socket-ID': socketId
            }
          })
            .then(response => callback(false, response.data))
            .catch(error => callback(true, error))
        }
      }
    }
  })

  window.Echo.private(`store.${storeId.value}`)
    .listen('.order.created', (e) => {
      if (!orders.value.some(o => o.id === e.order.id)) {
        orders.value.unshift(e.order)
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
      console.error('[Echo] Erro de autenticação no canal:', error)
    })
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

    showNotify('Status atualizado!')

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
  return orders.value.filter(o => o.status === filterStatus.value)
})

onMounted(fetchOrders)
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

        <div class="flex bg-slate-100 p-1 rounded-2xl">
          <button v-for="(val, key) in { all: 'Todos', pending: 'Pendentes', preparing: 'Cozinha' }" :key="key"
            @click="filterStatus = key" :class="[
              'px-4 py-2 rounded-xl text-xs font-black transition-all',
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
              statusMap[order.status].color
            ]">
              <component :is="statusMap[order.status].icon" size="24" />
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
                  {{ item.quantity }}x {{ item.product?.name }}{{ idx < order.items.length - 1 ? ', ' : '' }} </span>

                    <span class="text-red-500 ml-1">• R$ {{ order.total_amount }}</span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-4">
            <div class="hidden md:block text-right">
              <p class="text-xs font-black text-slate-400 uppercase tracking-widest">Status</p>
              <p class="font-bold text-slate-700">{{ statusMap[order.status].label }}</p>
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
                <div v-if="selectedOrder.status === 'delivered'" class="flex items-center gap-2 col-span-2">
                  <div class="h-px flex-1 bg-slate-100"></div>
                  <span
                    class="flex items-center gap-1.5 px-3 py-1 bg-emerald-50 text-emerald-600 rounded-full text-[10px] font-black uppercase tracking-wider border border-emerald-100">
                    <CheckCircle size="12" />
                    Pedido Entregue
                  </span>
                  <div class="h-px flex-1 bg-slate-100"></div>
                </div>

                <div v-if="selectedOrder.status === 'canceled'" class="flex items-center gap-2 col-span-2">
                  <div class="h-px flex-1 bg-slate-100"></div>
                  <span
                    class="flex items-center gap-1.5 px-3 py-1 bg-red-50 text-red-600 rounded-full text-[10px] font-black uppercase tracking-wider border border-red-100">
                    <XCircle size="12" />
                    Pedido Cancelado
                  </span>
                  <div class="h-px flex-1 bg-slate-100"></div>
                </div>

                <button v-if="selectedOrder.status === 'pending'" @click="acceptOrder(selectedOrder.id)"
                  class="col-span-2 bg-red-600 hover:bg-red-700 text-white p-5 rounded-2xl font-black flex items-center justify-center gap-3 transition-all active:scale-95 shadow-lg shadow-red-100">
                  <ChefHat size="24" />
                  <span class="text-lg uppercase">Aceitar e Iniciar Preparo</span>
                </button>

                <button v-if="['pending', 'preparing', 'ready'].includes(selectedOrder.status)"
                  @click="openRejectModal(selectedOrder.id)" :class="[
                    'p-4 rounded-2xl font-black transition-all active:scale-95 flex items-center justify-center gap-2',
                    selectedOrder.status === 'pending'
                      ? 'col-span-2 bg-slate-100 text-slate-400 hover:bg-red-500 hover:text-white'
                      : 'bg-red-50 text-red-400 hover:bg-red-100'
                  ]">
                  <XCircle size="20" />
                  {{ selectedOrder.status === 'pending' ? 'Recusar Pedido' : 'Cancelar Pedido' }}
                </button>

                <button v-if="selectedOrder.status === 'preparing'" @click="updateStatus(selectedOrder.id, 'ready')"
                  class="bg-orange-500 hover:bg-orange-600 text-white p-4 rounded-2xl font-black flex items-center justify-center gap-2 transition-all active:scale-95 shadow-lg shadow-orange-100">
                  <CheckCircle size="20" />
                  Pronto na Cozinha
                </button>

                <button v-if="selectedOrder.status === 'ready'" @click="updateStatus(selectedOrder.id, 'shipped')"
                  class="col-span-2 bg-blue-500 hover:bg-blue-600 text-white p-4 rounded-2xl font-black flex items-center justify-center gap-2 transition-all active:scale-95 shadow-lg shadow-blue-100">
                  <Truck size="20" />
                  Despachar Pedido
                </button>

                <button v-if="selectedOrder.status === 'shipped'" @click="updateStatus(selectedOrder.id, 'delivered')"
                  class="col-span-2 bg-slate-900 hover:bg-black text-white p-4 rounded-2xl font-black flex items-center justify-center gap-2 transition-all active:scale-95">
                  <CheckCircle size="20" />
                  Confirmar Entrega
                </button>
              </div>
            </div>

            <div v-if="selectedOrder?.observation"
              class="bg-amber-50 p-6 rounded-3xl border border-amber-200/70 shadow-sm text-left animate-in fade-in duration-300">
              <h3 class="text-xs font-black text-amber-600 uppercase mb-2 tracking-widest flex items-center gap-1.5">
                <Clock size="14" />
                Observação do Cliente
              </h3>
              <p class="text-sm font-bold text-amber-900 italic leading-relaxed">
                "{ selectedOrder.observation }"
              </p>
            </div>

            <div class="space-y-4">
              <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest">Itens do Pedido</h3>

              <div v-for="item in selectedOrder.items" :key="item.id"
                class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm transition-all hover:border-red-100">
                <div class="flex justify-between items-start mb-3">
                  <div class="flex items-center gap-4">
                    <div
                      class="w-12 h-12 bg-red-50 rounded-xl flex items-center justify-center font-black text-red-600 border border-red-100">
                      {{ item.quantity }}x
                    </div>

                    <div>
                      <p class="font-black text-slate-800 text-lg leading-tight">{{ item.product?.name }}</p>
                      <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">
                        Preço Unit: R$ {{ item.product?.price }}
                      </p>
                    </div>
                  </div>

                  <span
                    class="font-black text-slate-900 bg-slate-50 px-3 py-1 rounded-lg border border-slate-100 text-lg">
                    R$ {{ item.subtotal }}
                  </span>
                </div>

                <div v-if="item.options" class="ml-16 mb-3 space-y-2">
                  <div v-for="opt in (typeof item.options === 'string' ? JSON.parse(item.options) : item.options)"
                    :key="opt.name" class="flex flex-col gap-1">
                    <p class="text-[9px] font-black text-red-400 uppercase tracking-widest">{{ opt.group_name }}</p>

                    <div
                      class="flex justify-between items-center text-xs bg-slate-50 p-2 rounded-xl border border-slate-100">
                      <span class="font-bold text-slate-700">{{ opt.name }}</span>
                      <span v-if="opt.additional_price > 0" class="font-black text-red-500">
                        + R$ {{ opt.additional_price }}
                      </span>
                    </div>
                  </div>
                </div>

                <div v-if="item.observation" class="ml-16 p-3 bg-amber-50 rounded-xl border border-amber-100">
                  <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest mb-1">Observação do Item</p>
                  <p class="text-xs text-amber-700 font-bold italic leading-relaxed">"{{ item.observation }}"</p>
                </div>
              </div>
            </div>

            <div
              class="bg-gradient-to-br from-red-600 to-red-800 text-white p-7 rounded-[32px] shadow-xl relative overflow-hidden group">
              <Truck class="absolute -right-6 -bottom-6 text-white/10 group-hover:scale-110 transition-transform"
                size="140" />

              <div class="relative z-10">
                <div class="flex justify-between items-start mb-6">
                  <div>
                    <h3 class="text-[10px] font-black opacity-70 uppercase mb-1 tracking-widest">
                      Endereço de Entrega
                    </h3>
                    <p class="font-black text-xl leading-tight">{{ selectedOrder.address }}</p>
                  </div>

                  <div class="text-right">
                    <h3 class="text-[10px] font-black opacity-70 uppercase mb-1 tracking-widest">Região</h3>
                    <p class="font-black text-red-200 uppercase">
                      {{ selectedOrder.delivery_area?.district_name || selectedOrder.district || 'N/A' }}
                    </p>
                  </div>
                </div>

                <div class="pt-4 border-t border-white/20 flex justify-between items-center">
                  <span class="text-xs opacity-70 font-bold uppercase tracking-widest">Taxa de Entrega</span>
                  <span class="font-black text-lg">R$ {{ selectedOrder.delivery_fee }}</span>
                </div>

                <div v-if="selectedOrder.discount_amount > 0" class="pt-4 flex justify-between items-center text-red-200 border-t border-white/20">
                  <div class="flex flex-col">
                    <span class="text-xs font-bold uppercase tracking-widest">Desconto (Cupom)</span>
                    
                    <span v-if="selectedOrder.coupon" class="mt-1 inline-flex items-center px-2 py-0.5 rounded-md bg-red-900/50 text-[10px] font-black uppercase tracking-wider text-red-100 border border-red-800">
                      {{ selectedOrder.coupon.code ? selectedOrder.coupon.code : 'Cupom Aplicado' }}
                    </span>
                  </div>
                  <span class="font-black text-lg">- R$ {{ selectedOrder.discount_amount }}</span>
                </div>
              </div>
            </div>
          </div>

          <div class="p-8 bg-white border-t border-slate-100">
            <div class="flex justify-between items-center mb-5">
              <span class="text-slate-400 font-black uppercase tracking-widest text-xs">Total do Pedido</span>
              <span class="text-4xl font-black text-slate-900">R$ {{ selectedOrder?.total_amount }}</span>
            </div>

            <button @click="handlePrintOrder(selectedOrder.id)"
              class="w-full bg-slate-900 text-white py-5 rounded-2xl font-black flex items-center justify-center gap-3 transition-all hover:bg-black active:scale-95 shadow-lg shadow-slate-200">
              <Printer size="24" />
              <span class="text-lg">IMPRIMIR CUPOM</span>
            </button>
          </div>
        </div>
      </div>
    </transition>

    <transition name="fade">
      <div v-if="rejectModal.show" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="rejectModal.show = false"></div>

        <div
          class="relative bg-white w-full max-w-sm rounded-[40px] p-10 text-center shadow-2xl animate-in zoom-in duration-300">
          <div class="w-20 h-20 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6">
            <XCircle size="44" />
          </div>

          <h3 class="text-2xl font-black text-slate-900 mb-2 tracking-tight">Rejeitar Pedido?</h3>

          <p class="text-slate-500 mb-8 font-bold text-sm leading-relaxed">
            Tem certeza que deseja cancelar o pedido <b>#{{ rejectModal.id }}</b>? O cliente será notificado
            imediatamente.
          </p>

          <div class="grid grid-cols-1 gap-3">
            <button @click="handleRejectOrder" :disabled="rejectModal.loading"
              class="py-5 bg-red-500 text-white rounded-2xl font-black hover:bg-red-600 transition-all shadow-lg shadow-red-100 flex items-center justify-center">
              <Loader2 v-if="rejectModal.loading" class="animate-spin mr-2" size="20" />
              SIM, REJEITAR AGORA
            </button>

            <button @click="rejectModal.show = false"
              class="py-4 text-slate-400 font-black hover:text-slate-600 transition-all">
              Voltar
            </button>
          </div>
        </div>
      </div>
    </transition>
  </DashboardLayout>
</template>

<style scoped>
@keyframes slide-in {
  from {
    transform: translateX(100%);
  }

  to {
    transform: translateX(0);
  }
}

.animate-slide-in {
  animation: slide-in 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-fade-enter-active {
  transition: all 0.3s ease-out;
}

.slide-fade-leave-active {
  transition: all 0.3s cubic-bezier(1, 0.5, 0.8, 1);
}

.slide-fade-enter-from,
.slide-fade-leave-to {
  opacity: 0;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>