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
  ChefHat,
  X,
  User,
  Phone,
  MapPin,
  CreditCard,
  Package,
  ClipboardList,
  MessageSquare,
  PlusCircle
} from 'lucide-vue-next'

const orders = ref([])
const loading = ref(true)
const filterStatus = ref('all')
const selectedOrder = ref(null)
const modalDetails = ref(false)
const updatingStatus = ref(false)
const updatingAction = ref(null)
const printingOrder = ref(false)

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

const getCustomerName = (order) => {
  return order?.customer?.name ||
    order?.customer_name ||
    order?.client_name ||
    order?.name ||
    'Cliente não informado'
}

const getCustomerPhone = (order) => {
  return order?.customer?.phone ||
    order?.customer_phone ||
    order?.phone ||
    order?.whatsapp ||
    'Telefone não informado'
}

const getDeliveryAddress = (order) => {
  const customer = order?.customer || {}

  const address =
    order?.delivery_address ||
    order?.address ||
    customer?.address ||
    ''

  const number =
    order?.delivery_number ||
    order?.address_number ||
    customer?.address_number ||
    ''

  const complement =
    order?.delivery_complement ||
    order?.address_complement ||
    customer?.address_complement ||
    ''

  const district =
    order?.delivery_district ||
    order?.district ||
    customer?.district ||
    ''

  const parts = [
    address,
    number ? `nº ${number}` : '',
    complement,
    district
  ].filter(Boolean)

  return parts.length ? parts.join(', ') : 'Endereço não informado'
}

const getPaymentMethod = (order) => {
  const value =
    order?.payment_method ||
    order?.payment_type ||
    order?.payment?.method ||
    order?.payment?.type ||
    'Não informado'

  const labels = {
    pix: 'Pix',
    cash: 'Dinheiro',
    dinheiro: 'Dinheiro',
    credit_card: 'Cartão de crédito',
    debit_card: 'Cartão de débito',
    card: 'Cartão',
    online: 'Pagamento online'
  }

  return labels[String(value).toLowerCase()] || value
}

const getOrderItems = (order) => {
  return Array.isArray(order?.items) ? order.items : []
}

const getItemName = (item) => {
  return item?.product?.name || item?.name || item?.product_name || 'Item'
}

const getItemUnitPrice = (item) => {
  return Number(item?.unit_price || item?.price || item?.product?.price || 0)
}

const getItemTotal = (item) => {
  const quantity = Number(item?.quantity || 1)
  const total = Number(item?.total || item?.subtotal || 0)

  if (total > 0) return total

  return quantity * getItemUnitPrice(item)
}

const parseItemOptions = (item) => {
  const rawOptions =
    item?.options ||
    item?.selected_options ||
    item?.customizations ||
    item?.additionals ||
    []

  if (!rawOptions) return []

  if (Array.isArray(rawOptions)) {
    return rawOptions.filter(Boolean)
  }

  if (typeof rawOptions === 'string') {
    try {
      const parsed = JSON.parse(rawOptions)

      if (Array.isArray(parsed)) {
        return parsed.filter(Boolean)
      }

      if (parsed && typeof parsed === 'object') {
        return Object.values(parsed).flat().filter(Boolean)
      }

      return []
    } catch (err) {
      return []
    }
  }

  if (rawOptions && typeof rawOptions === 'object') {
    return Object.values(rawOptions).flat().filter(Boolean)
  }

  return []
}

const getOptionName = (option) => {
  if (typeof option === 'string') return option

  return option?.name ||
    option?.label ||
    option?.title ||
    option?.option_name ||
    'Opção'
}

const getOptionGroupName = (option) => {
  if (typeof option === 'string') return 'Adicionais'

  return option?.group_name ||
    option?.group ||
    option?.category ||
    option?.option_group ||
    'Adicionais'
}

const getOptionPrice = (option) => {
  if (typeof option === 'string') return 0

  return Number(
    option?.additional_price ??
    option?.price ??
    option?.amount ??
    option?.value ??
    0
  )
}

const getGroupedItemOptions = (item) => {
  const options = parseItemOptions(item)

  return options.reduce((groups, option) => {
    const groupName = getOptionGroupName(option)

    if (!groups[groupName]) {
      groups[groupName] = []
    }

    groups[groupName].push(option)

    return groups
  }, {})
}

const hasItemOptions = (item) => {
  return parseItemOptions(item).length > 0
}

const getItemObservation = (item) => {
  return item?.observation ||
    item?.notes ||
    item?.note ||
    item?.customer_note ||
    null
}

const getOrderObservation = (order) => {
  return order?.observation ||
    order?.notes ||
    order?.customer_notes ||
    order?.customer_note ||
    null
}

const closeDetails = () => {
  if (updatingStatus.value || rejectModal.loading) return
  modalDetails.value = false
}

const handlePrintOrder = async (orderId) => {
  if (!orderId || printingOrder.value) return

  printingOrder.value = true

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
  } finally {
    setTimeout(() => {
      printingOrder.value = false
    }, 800)
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
  if (updatingStatus.value || rejectModal.loading) return

  rejectModal.id = orderId
  rejectModal.show = true
}

const handleRejectOrder = async () => {
  if (updatingStatus.value || rejectModal.loading || !rejectModal.id) return

  rejectModal.loading = true

  try {
    await updateStatus(rejectModal.id, 'canceled', 'cancel')
    rejectModal.show = false
    showNotify('Pedido cancelado e cliente notificado.', 'error')
  } catch (err) {
    showNotify('Erro ao cancelar pedido.', 'error')
  } finally {
    rejectModal.loading = false
  }
}

const acceptOrder = (orderId) => {
  updateStatus(orderId, 'preparing', 'prepare')
}

const updateStatus = async (orderId, newStatus, actionKey = newStatus) => {
  if (!orderId || updatingStatus.value) return

  updatingStatus.value = true
  updatingAction.value = actionKey

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
    throw err
  } finally {
    updatingStatus.value = false
    updatingAction.value = null
  }
}

const openDetails = (order) => {
  if (updatingStatus.value) return

  selectedOrder.value = order
  modalDetails.value = true
}

const filteredOrders = computed(() => {
  if (filterStatus.value === 'all') return orders.value
  return orders.value.filter(o => normalizeOrderStatus(o.status) === filterStatus.value)
})

const selectedOrderStatus = computed(() => normalizeOrderStatus(selectedOrder.value?.status))
const selectedOrderStatusInfo = computed(() => getStatusInfo(selectedOrder.value?.status))

const canPrepare = computed(() => selectedOrderStatus.value === 'pending')
const canMarkReady = computed(() => selectedOrderStatus.value === 'preparing')
const canShip = computed(() => selectedOrderStatus.value === 'ready')
const canDeliver = computed(() => selectedOrderStatus.value === 'shipped')
const canCancel = computed(() => !['canceled', 'delivered'].includes(selectedOrderStatus.value))

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
            :disabled="updatingStatus"
            :class="[
              'px-4 py-2 rounded-xl text-xs font-black transition-all whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed',
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
        <div
          v-for="order in filteredOrders"
          :key="order.id"
          @click="openDetails(order)"
          :class="[
            'bg-white p-5 rounded-3xl border border-slate-200 shadow-sm hover:border-red-200 transition-all group flex items-center justify-between',
            updatingStatus ? 'cursor-not-allowed opacity-80' : 'cursor-pointer'
          ]"
        >
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
                  {{ order.fulfillment_type === 'pickup' ? 'Retirada' : 'Entrega' }}
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

    <Teleport to="body">
      <transition name="fade">
        <div
          v-if="modalDetails && selectedOrder"
          class="fixed inset-0 z-[90]"
        >
          <div
            class="absolute inset-0 bg-slate-950/40 backdrop-blur-sm"
            @click="closeDetails"
          />

          <transition name="slide-drawer" appear>
            <aside
              class="absolute right-0 top-0 h-full w-full max-w-xl bg-white shadow-2xl flex flex-col"
            >
              <header class="px-6 py-5 border-b border-slate-100 flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div :class="[
                    'w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0',
                    selectedOrderStatusInfo.color
                  ]">
                    <component :is="selectedOrderStatusInfo.icon" size="24" />
                  </div>

                  <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <h2 class="text-xl font-black text-slate-900">
                        Pedido #{{ selectedOrder.id.toString().padStart(4, '0') }}
                      </h2>

                      <span :class="[
                        'px-2.5 py-1 rounded-full text-[10px] font-black uppercase',
                        selectedOrderStatusInfo.color
                      ]">
                        {{ selectedOrderStatusInfo.shortLabel }}
                      </span>
                    </div>

                    <p class="text-sm font-semibold text-slate-400 mt-1">
                      {{ formatOrderDateTime(selectedOrder.created_at) }}
                    </p>
                  </div>
                </div>

                <button
                  @click="closeDetails"
                  :disabled="updatingStatus || rejectModal.loading"
                  class="w-9 h-9 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-800 flex items-center justify-center transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <X size="18" />
                </button>
              </header>

              <section class="px-6 py-4 border-b border-slate-100 bg-white">
                <div class="mb-2 flex items-center justify-between gap-3">
                  <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">
                    Ações rápidas
                  </p>

                  <button
                    @click="handlePrintOrder(selectedOrder.id)"
                    :disabled="printingOrder || updatingStatus"
                    class="h-9 px-3 rounded-xl bg-slate-900 text-white font-black text-xs flex items-center gap-2 hover:bg-slate-800 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    <Loader2 v-if="printingOrder" class="animate-spin" size="14" />
                    <Printer v-else size="14" />
                    {{ printingOrder ? 'Abrindo...' : 'Imprimir' }}
                  </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                  <button
                    v-if="canPrepare"
                    @click="acceptOrder(selectedOrder.id)"
                    :disabled="updatingStatus"
                    class="h-10 px-4 rounded-xl bg-red-600 text-white font-black text-xs hover:bg-red-700 transition-colors shadow-sm shadow-red-100 disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'prepare'" class="animate-spin" size="14" />
                    {{ updatingAction === 'prepare' ? 'Aceitando...' : 'Aceitar pedido' }}
                  </button>

                  <button
                    v-if="canMarkReady"
                    @click="updateStatus(selectedOrder.id, 'ready', 'ready')"
                    :disabled="updatingStatus"
                    class="h-10 px-4 rounded-xl bg-red-600 text-white font-black text-xs hover:bg-red-700 transition-colors shadow-sm shadow-red-100 disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'ready'" class="animate-spin" size="14" />
                    {{ updatingAction === 'ready' ? 'Salvando...' : 'Marcar pronto' }}
                  </button>

                  <button
                    v-if="canShip"
                    @click="updateStatus(selectedOrder.id, 'shipped', 'shipped')"
                    :disabled="updatingStatus"
                    class="h-10 px-4 rounded-xl bg-red-600 text-white font-black text-xs hover:bg-red-700 transition-colors shadow-sm shadow-red-100 disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'shipped'" class="animate-spin" size="14" />
                    {{ updatingAction === 'shipped' ? 'Salvando...' : 'Saiu para entrega' }}
                  </button>

                  <button
                    v-if="canDeliver"
                    @click="updateStatus(selectedOrder.id, 'delivered', 'delivered')"
                    :disabled="updatingStatus"
                    class="h-10 px-4 rounded-xl bg-red-600 text-white font-black text-xs hover:bg-red-700 transition-colors shadow-sm shadow-red-100 disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'delivered'" class="animate-spin" size="14" />
                    {{ updatingAction === 'delivered' ? 'Finalizando...' : 'Finalizar pedido' }}
                  </button>

                  <button
                    v-if="canCancel"
                    @click="openRejectModal(selectedOrder.id)"
                    :disabled="updatingStatus || rejectModal.loading"
                    class="h-10 px-4 rounded-xl bg-red-50 text-red-600 border border-red-100 font-black text-xs hover:bg-red-100 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'cancel' || rejectModal.loading" class="animate-spin" size="14" />
                    {{ updatingAction === 'cancel' || rejectModal.loading ? 'Cancelando...' : 'Cancelar' }}
                  </button>
                </div>
              </section>

              <div class="flex-1 overflow-y-auto p-6 space-y-5">
                <section class="grid sm:grid-cols-2 gap-4">
                  <div class="rounded-2xl border border-slate-100 p-4">
                    <div class="flex items-center gap-2 mb-3">
                      <User size="17" class="text-red-500" />
                      <h3 class="text-sm font-black text-slate-900">Cliente</h3>
                    </div>

                    <p class="font-black text-slate-900">
                      {{ getCustomerName(selectedOrder) }}
                    </p>

                    <p class="text-sm font-semibold text-slate-500 mt-1 flex items-center gap-1.5">
                      <Phone size="14" />
                      {{ getCustomerPhone(selectedOrder) }}
                    </p>
                  </div>

                  <div class="rounded-2xl border border-slate-100 p-4">
                    <div class="flex items-center gap-2 mb-3">
                      <MapPin size="17" class="text-red-500" />
                      <h3 class="text-sm font-black text-slate-900">Entrega</h3>
                    </div>

                    <p class="text-sm font-semibold text-slate-600 leading-relaxed">
                      {{ getDeliveryAddress(selectedOrder) }}
                    </p>
                  </div>
                </section>

                <section
                  v-if="getOrderObservation(selectedOrder)"
                  class="rounded-2xl border border-amber-100 bg-amber-50 p-4"
                >
                  <div class="flex items-center gap-2 mb-2">
                    <MessageSquare size="17" class="text-amber-600" />
                    <h3 class="text-sm font-black text-amber-900">Observação do pedido</h3>
                  </div>

                  <p class="text-sm font-semibold text-amber-800">
                    {{ getOrderObservation(selectedOrder) }}
                  </p>
                </section>

                <section class="rounded-3xl border border-red-100 bg-red-50/70 p-4 shadow-sm">
                  <div class="mb-4 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                      <div
                        class="flex h-9 w-9 items-center justify-center rounded-2xl bg-red-600 text-white shadow-sm shadow-red-100">
                        <Package size="18" />
                      </div>

                      <div>
                        <h3 class="text-sm font-black text-slate-950">Itens do pedido</h3>
                        <p class="text-xs font-semibold text-red-400">
                          Produtos, adicionais e observações.
                        </p>
                      </div>
                    </div>

                    <span class="rounded-full bg-white px-3 py-1 text-xs font-black text-red-600 border border-red-100">
                      {{ getOrderItems(selectedOrder).length }} item(ns)
                    </span>
                  </div>

                  <div class="space-y-4">
                    <div
                      v-for="item in getOrderItems(selectedOrder)"
                      :key="item.id || item.product_id || getItemName(item)"
                      class="rounded-2xl border border-red-100 bg-white p-4 shadow-sm"
                    >
                      <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                          <p class="text-base font-black text-slate-950">
                            {{ item.quantity || 1 }}x {{ getItemName(item) }}
                          </p>

                          <p class="mt-1 text-xs font-semibold text-slate-400">
                            Unitário R$ {{ formatMoney(getItemUnitPrice(item)) }}
                          </p>
                        </div>

                        <p
                          class="rounded-xl bg-red-50 px-3 py-1.5 text-sm font-black text-red-600 whitespace-nowrap border border-red-100">
                          R$ {{ formatMoney(getItemTotal(item)) }}
                        </p>
                      </div>

                      <div v-if="hasItemOptions(item)" class="mt-4 space-y-3">
                        <div
                          v-for="(options, groupName) in getGroupedItemOptions(item)"
                          :key="groupName"
                          class="rounded-2xl border border-slate-100 bg-slate-50 p-3"
                        >
                          <div class="mb-2 flex items-center gap-2">
                            <PlusCircle size="14" class="text-red-500" />
                            <p class="text-[11px] font-black uppercase tracking-wide text-slate-500">
                              {{ groupName }}
                            </p>
                          </div>

                          <div class="space-y-1.5">
                            <div
                              v-for="(option, optionIndex) in options"
                              :key="`${groupName}-${optionIndex}`"
                              class="flex items-center justify-between gap-3 text-sm"
                            >
                              <span class="font-semibold text-slate-700">
                                {{ getOptionName(option) }}
                              </span>

                              <span v-if="getOptionPrice(option) > 0" class="font-black text-red-600">
                                + R$ {{ formatMoney(getOptionPrice(option)) }}
                              </span>

                              <span v-else class="text-xs font-bold text-slate-400">
                                Incluso
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div
                        v-if="getItemObservation(item)"
                        class="mt-4 rounded-2xl border border-amber-100 bg-amber-50 px-3 py-2.5"
                      >
                        <p class="text-[11px] font-black uppercase tracking-wide text-amber-700">
                          Observação do item
                        </p>

                        <p class="mt-1 text-sm font-semibold text-amber-900">
                          {{ getItemObservation(item) }}
                        </p>
                      </div>
                    </div>
                  </div>
                </section>

                <section class="grid sm:grid-cols-2 gap-4">
                  <div class="rounded-2xl border border-slate-100 p-4">
                    <div class="flex items-center gap-2 mb-3">
                      <CreditCard size="17" class="text-red-500" />
                      <h3 class="text-sm font-black text-slate-900">Pagamento</h3>
                    </div>

                    <p class="text-sm font-semibold text-slate-600">
                      {{ getPaymentMethod(selectedOrder) }}
                    </p>

                    <p class="mt-2 text-xl font-black text-slate-900">
                      R$ {{ formatMoney(selectedOrder.total_amount) }}
                    </p>
                  </div>

                  <div class="rounded-2xl border border-slate-100 p-4">
                    <div class="flex items-center gap-2 mb-3">
                      <ClipboardList size="17" class="text-red-500" />
                      <h3 class="text-sm font-black text-slate-900">Resumo</h3>
                    </div>

                    <div class="space-y-2 text-sm">
                      <div class="flex justify-between gap-3">
                        <span class="font-semibold text-slate-500">Subtotal</span>
                        <span class="font-black text-slate-900">
                          R$ {{ formatMoney(selectedOrder.subtotal_amount || selectedOrder.subtotal ||
                            selectedOrder.total_amount) }}
                        </span>
                      </div>

                      <div v-if="Number(selectedOrder.delivery_fee || 0) > 0" class="flex justify-between gap-3">
                        <span class="font-semibold text-slate-500">Entrega</span>
                        <span class="font-black text-slate-900">
                          R$ {{ formatMoney(selectedOrder.delivery_fee) }}
                        </span>
                      </div>

                      <div v-if="hasCouponDiscount(selectedOrder)" class="flex justify-between gap-3 text-emerald-600">
                        <span class="font-semibold">
                          Cupom {{ getCouponCode(selectedOrder) }}
                        </span>
                        <span class="font-black">
                          - R$ {{ formatMoney(selectedOrder.discount_amount) }}
                        </span>
                      </div>

                      <p v-if="hasCouponDiscount(selectedOrder) && getCouponDescription(selectedOrder)"
                        class="text-xs font-semibold text-slate-400">
                        {{ getCouponDescription(selectedOrder) }}
                      </p>

                      <div class="pt-2 border-t border-slate-100 flex justify-between gap-3">
                        <span class="font-black text-slate-900">Total</span>
                        <span class="font-black text-red-500">
                          R$ {{ formatMoney(selectedOrder.total_amount) }}
                        </span>
                      </div>
                    </div>
                  </div>
                </section>
              </div>
            </aside>
          </transition>
        </div>
      </transition>

      <div
        v-if="rejectModal.show"
        class="fixed inset-0 z-[110] flex items-center justify-center px-4 py-6"
      >
        <div
          class="absolute inset-0 bg-slate-950/50"
          @click="!rejectModal.loading && (rejectModal.show = false)"
        ></div>

        <div class="relative w-full max-w-md bg-white rounded-3xl shadow-2xl p-6">
          <div class="w-12 h-12 rounded-2xl bg-red-100 text-red-600 flex items-center justify-center mb-4">
            <XCircle size="26" />
          </div>

          <h2 class="text-xl font-black text-slate-900">Cancelar pedido?</h2>
          <p class="text-sm font-semibold text-slate-500 mt-2">
            Essa ação marcará o pedido como cancelado. O cliente poderá ser notificado conforme as integrações ativas.
          </p>

          <div class="mt-6 flex justify-end gap-2">
            <button
              @click="rejectModal.show = false"
              :disabled="rejectModal.loading"
              class="h-11 px-4 rounded-xl bg-slate-100 text-slate-600 font-black text-sm hover:bg-slate-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            >
              Voltar
            </button>

            <button
              @click="handleRejectOrder"
              :disabled="rejectModal.loading || updatingStatus"
              class="h-11 px-4 rounded-xl bg-red-600 text-white font-black text-sm hover:bg-red-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
            >
              <Loader2 v-if="rejectModal.loading || updatingAction === 'cancel'" class="animate-spin" size="16" />
              {{ rejectModal.loading || updatingAction === 'cancel' ? 'Cancelando...' : 'Confirmar cancelamento' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </DashboardLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-drawer-enter-active,
.slide-drawer-leave-active {
  transition: transform 0.25s ease;
}

.slide-drawer-enter-from,
.slide-drawer-leave-to {
  transform: translateX(100%);
}
</style>