<script setup>
import { ref, onMounted, reactive, computed, onBeforeUnmount } from 'vue'
import api from '@/services/api'
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

const fetchOrders = async () => {
  loading.value = true

  try {
    const { data } = await api.get('/merchant/orders')
    orders.value = Array.isArray(data) ? data : (data.data || [])
  } catch (err) {
    console.error('Erro ao carregar:', err)
    showNotify(err.response?.data?.message || 'Erro ao carregar pedidos.', 'error')
  } finally {
    loading.value = false
  }
}

const handleRealtimeOrderCreated = (event) => {
  const order = event.detail?.order

  if (!order || orders.value.some(o => o.id === order.id)) return

  orders.value.unshift(order)
}

const handleRealtimeOrderUpdated = (event) => {
  const order = event.detail?.order

  if (!order) return

  const index = orders.value.findIndex(o => o.id === order.id)

  if (index !== -1) {
    orders.value[index] = { ...orders.value[index], ...order }
  }

  if (selectedOrder.value?.id === order.id) {
    selectedOrder.value = { ...selectedOrder.value, ...order }
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

onMounted(() => {
  window.addEventListener('partiumenu:order-created', handleRealtimeOrderCreated)
  window.addEventListener('partiumenu:order-updated', handleRealtimeOrderUpdated)
  fetchOrders()
})

onBeforeUnmount(() => {
  window.removeEventListener('partiumenu:order-created', handleRealtimeOrderCreated)
  window.removeEventListener('partiumenu:order-updated', handleRealtimeOrderUpdated)
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
  </DashboardLayout>
</template>
