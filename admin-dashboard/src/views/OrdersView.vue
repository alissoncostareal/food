<script setup>
import { ref, onMounted, reactive, computed, onBeforeUnmount } from 'vue'
import api from '@/services/api'
import AppToast from '@/components/ui/AppToast.vue'
import { useOnStoreSwitch } from '@/composables/useOnStoreSwitch'
import {
  ShoppingBag,
  Clock,
  CheckCircle,
  Truck,
  XCircle,
  ChevronRight,
  ChevronLeft,
  Printer,
  Loader2,
  ChefHat,
  X,
  User,
  Phone,
  MapPin,
  CreditCard,
  Package,
  PackageCheck,
  ClipboardList,
  MessageSquare,
  PlusCircle
} from 'lucide-vue-next'

const orders = ref([])
const loading = ref(true)
const filterStatus = ref('all')
const hasInitializedFilter = ref(false)
const knownActionablePendingCount = ref(null)
const statusCounts = ref({
  all: 0,
  pending: 0,
  pending_actionable: 0,
  preparing: 0,
  ready: 0,
  shipped: 0,
  delivered: 0,
  canceled: 0
})
const currentPage = ref(1)
const perPage = ref(15)
const paginationMeta = ref({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0
})
const selectedOrder = ref(null)
const modalDetails = ref(false)
const updatingStatus = ref(false)
const updatingAction = ref(null)
const printingOrder = ref(false)

const rejectModal = reactive({
  show: false,
  id: null,
  loading: false,
  isIfood: false,
  loadingReasons: false,
  reasons: [],
  selectedReason: ''
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

const isOrderFinished = (order) => {
  if (order?.is_finished) return true
  return ['delivered', 'canceled'].includes(normalizeOrderStatus(order?.status))
}

const isOrderStalePending = (order) => {
  if (normalizeOrderStatus(order?.status) !== 'pending') return false
  if (order?.needs_attention === false) return true
  if (order?.needs_attention === true) return false

  const createdAt = order?.created_at ? new Date(order.created_at).getTime() : 0
  const cutoff = Date.now() - (24 * 60 * 60 * 1000)

  return createdAt > 0 && createdAt < cutoff
}

const isOrderAwaitingPix = (order) => order?.payment_status === 'awaiting_payment'

const isOrderWaitingAcceptance = (order) => {
  return normalizeOrderStatus(order?.status) === 'pending'
    && !isOrderStalePending(order)
    && !isOrderAwaitingPix(order)
}

const getOrderCardClass = (order) => {
  if (isOrderFinished(order)) {
    return 'bg-slate-50 border-slate-100 opacity-55 hover:opacity-75 hover:border-slate-200'
  }

  if (isOrderStalePending(order)) {
    return 'bg-slate-50 border-slate-200 opacity-80 hover:opacity-100 hover:border-slate-300'
  }

  if (isOrderAwaitingPix(order)) {
    return 'bg-sky-50/70 border-sky-200 shadow-sm ring-1 ring-sky-100 hover:border-sky-300'
  }

  if (isOrderWaitingAcceptance(order)) {
    return 'bg-amber-50/70 border-amber-200 shadow-sm ring-1 ring-amber-100 hover:border-amber-300'
  }

  return 'bg-white border-slate-200 hover:border-red-200'
}

const getOrderTextClass = (order) => {
  if (isOrderFinished(order) || isOrderStalePending(order)) {
    return 'text-slate-400'
  }

  return 'text-slate-900'
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
    pix: 'Pix na entrega',
    pix_online: 'Pix online',
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
  if (item?.grouped_options && typeof item.grouped_options === 'object' && !Array.isArray(item.grouped_options)) {
    return Object.entries(item.grouped_options).flatMap(([groupName, options]) =>
      (Array.isArray(options) ? options : []).map((option) => ({
        ...option,
        group_name: option?.group_name || option?.groupName || groupName
      }))
    )
  }

  const rawOptions =
    item?.options_list ||
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

const ifoodOrderTypeLabels = {
  DELIVERY: 'Entrega iFood',
  TAKEOUT: 'Retirada',
  INDOOR: 'Consumo no local'
}

const ifoodDeliveredByLabels = {
  IFOOD: 'Entregue pelo iFood',
  MERCHANT: 'Entrega própria'
}

const getIfoodOrderTypeLabel = (order) => {
  if (!order?.ifood_order_type) return null
  return ifoodOrderTypeLabels[order.ifood_order_type] || order.ifood_order_type
}

const getIfoodDeliveredByLabel = (order) => {
  if (!order?.ifood_delivered_by) return null
  return ifoodDeliveredByLabels[order.ifood_delivered_by] || order.ifood_delivered_by
}

const closeDetails = () => {
  if (updatingStatus.value || rejectModal.loading) return
  modalDetails.value = false
}

const handlePrintOrder = async (orderId) => {
  if (!orderId || printingOrder.value) return

  printingOrder.value = true

  let iframe = null
  let printStarted = false

  const cleanupIframe = () => {
    if (iframe?.parentNode) {
      iframe.parentNode.removeChild(iframe)
    }
    iframe = null
  }

  try {
    const response = await api.get(`/merchant/orders/${orderId}/print`, {
      headers: {
        Accept: 'text/html'
      },
      responseType: 'text'
    })

    iframe = document.createElement('iframe')
    iframe.style.position = 'fixed'
    iframe.style.top = '0'
    iframe.style.left = '0'
    iframe.style.width = '0'
    iframe.style.height = '0'
    iframe.style.border = '0'
    document.body.appendChild(iframe)

    const printFrame = () => {
      if (printStarted || !iframe?.contentWindow) return

      printStarted = true
      iframe.contentWindow.focus()
      iframe.contentWindow.print()
    }

    iframe.onload = () => {
      const frameWindow = iframe.contentWindow

      if (frameWindow) {
        frameWindow.addEventListener('afterprint', cleanupIframe, { once: true })
      }

      setTimeout(printFrame, 300)
    }

    const doc = iframe.contentWindow.document
    doc.open()
    doc.write(response.data)
    doc.close()

    setTimeout(() => {
      if (!printStarted) {
        printFrame()
      }
    }, 1200)
  } catch (err) {
    cleanupIframe()
    console.error('Erro ao gerar impressão:', err)

    if (err.response?.status === 403) {
      showNotify('Você não tem permissão para imprimir este pedido.', 'error')
    } else {
      showNotify(
        err.response?.data?.details ||
          err.response?.data?.message ||
          'Não foi possível carregar o cupom.',
        'error'
      )
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

const syncPendingAlert = () => {
  const count = Number(statusCounts.value.pending_actionable ?? 0)

  knownActionablePendingCount.value = count

  window.dispatchEvent(new CustomEvent('partiumenu:pending-orders-sync', {
    detail: { count }
  }))
}

let ordersFetchSeq = 0
let ordersRefreshTimer = null
const recentRealtimeOrderIds = new Set()

const rememberRealtimeOrder = (orderId) => {
  if (!orderId) return

  recentRealtimeOrderIds.add(orderId)

  window.setTimeout(() => {
    recentRealtimeOrderIds.delete(orderId)
  }, 15000)
}

const mergeOrdersAfterFetch = (incoming, previous) => {
  const merged = new Map()

  for (const order of incoming) {
    merged.set(order.id, order)
  }

  for (const orderId of recentRealtimeOrderIds) {
    if (merged.has(orderId)) continue

    const cached = previous.find(item => item.id === orderId)

    if (cached) {
      merged.set(orderId, cached)
    }
  }

  return Array.from(merged.values()).sort((left, right) => {
    const leftTime = new Date(left.created_at || 0).getTime()
    const rightTime = new Date(right.created_at || 0).getTime()

    return rightTime - leftTime
  })
}

const ensureFilterForOrder = (order) => {
  const status = normalizeOrderStatus(order?.status || 'pending')

  if (status === 'pending' && !['all', 'pending'].includes(filterStatus.value)) {
    filterStatus.value = 'pending'
    currentPage.value = 1
    return true
  }

  return filterStatus.value === 'all' || filterStatus.value === status
}

const upsertRealtimeOrder = (order) => {
  if (!order?.id) return false

  if (!ensureFilterForOrder(order)) {
    return false
  }

  rememberRealtimeOrder(order.id)

  const next = [...orders.value]
  const index = next.findIndex(item => item.id === order.id)

  if (index === -1) {
    next.unshift(order)
  } else {
    next[index] = { ...next[index], ...order }
  }

  orders.value = next.slice(0, perPage.value)
  return true
}

const scheduleOrdersRefresh = (delayMs = 900) => {
  if (ordersRefreshTimer) {
    clearTimeout(ordersRefreshTimer)
  }

  ordersRefreshTimer = window.setTimeout(() => {
    ordersRefreshTimer = null
    fetchOrders({ silent: true })
  }, delayMs)
}

const normalizeOrdersResponse = (data) => {
  if (data?.meta && Array.isArray(data?.data)) {
    return {
      list: data.data,
      meta: data.meta
    }
  }

  if (Array.isArray(data?.data) && data.current_page !== undefined) {
    return {
      list: data.data,
      meta: {
        current_page: data.current_page,
        last_page: data.last_page,
        per_page: data.per_page,
        total: data.total,
        counts: data.counts ?? null
      }
    }
  }

  const list = Array.isArray(data) ? data : []

  return {
    list,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: list.length || perPage.value,
      total: list.length
    }
  }
}

const fetchOrders = async ({ silent = false } = {}) => {
  const fetchSeq = ++ordersFetchSeq

  if (!silent) loading.value = true

  try {
    const { data } = await api.get('/merchant/orders', {
      params: {
        page: currentPage.value,
        per_page: perPage.value,
        status: filterStatus.value
      }
    })

    if (fetchSeq !== ordersFetchSeq) return

    const { list, meta } = normalizeOrdersResponse(data)
    const previous = orders.value

    orders.value = mergeOrdersAfterFetch(list, previous)

    paginationMeta.value = {
      current_page: meta.current_page ?? 1,
      last_page: meta.last_page ?? 1,
      per_page: meta.per_page ?? perPage.value,
      total: meta.total ?? list.length
    }

    if (meta.counts) {
      statusCounts.value = { ...statusCounts.value, ...meta.counts }
    }

    syncPendingAlert()

    if (!hasInitializedFilter.value) {
      const shouldOpenPending = Number(statusCounts.value.pending_actionable ?? 0) > 0
      hasInitializedFilter.value = true

      if (shouldOpenPending && filterStatus.value !== 'pending') {
        filterStatus.value = 'pending'
        await fetchOrders({ silent: true })
        return
      }
    }
  } catch (err) {
    console.error('Erro ao carregar:', err)
    showNotify(err.response?.data?.message || 'Erro ao carregar pedidos.', 'error')
  } finally {
    if (!silent) loading.value = false
  }
}

const handleRealtimeOrderCreated = async (event) => {
  const order = event.detail?.order

  upsertRealtimeOrder(order)
  scheduleOrdersRefresh()
}

const handlePendingOrdersSync = (event) => {
  const nextCount = Number(event.detail?.count ?? 0)
  const previousCount = Number(statusCounts.value.pending_actionable ?? 0)
  const increased = Boolean(event.detail?.increased) || nextCount > previousCount

  if (!increased) return

  currentPage.value = 1

  if (filterStatus.value !== 'all' && filterStatus.value !== 'pending') {
    filterStatus.value = 'pending'
  }

  scheduleOrdersRefresh(250)
}

const handleRealtimeOrderUpdated = (event) => {
  const order = event.detail?.order

  if (!order) {
    scheduleOrdersRefresh()
    return
  }

  upsertRealtimeOrder(order)

  if (selectedOrder.value?.id === order.id) {
    selectedOrder.value = { ...selectedOrder.value, ...order }
  }

  scheduleOrdersRefresh()
}

const openRejectModal = async (orderId) => {
  if (updatingStatus.value || rejectModal.loading) return

  const order = orders.value.find(o => o.id === orderId) || selectedOrder.value
  const isIfood = order?.order_source === 'ifood' && order?.ifood_order_id

  rejectModal.id = orderId
  rejectModal.isIfood = Boolean(isIfood)
  rejectModal.reasons = []
  rejectModal.selectedReason = ''
  rejectModal.show = true

  if (!isIfood) return

  rejectModal.loadingReasons = true

  try {
    const { data } = await api.get(`/merchant/orders/${orderId}/ifood/cancellation-reasons`)
    rejectModal.reasons = data.reasons || []

    if (rejectModal.reasons.length === 1) {
      rejectModal.selectedReason = rejectModal.reasons[0].code
    }
  } catch (err) {
    showNotify(
      err.response?.data?.details || 'Não foi possível carregar motivos de cancelamento iFood.',
      'error'
    )
    rejectModal.show = false
  } finally {
    rejectModal.loadingReasons = false
  }
}

const handleRejectOrder = async () => {
  if (updatingStatus.value || rejectModal.loading || !rejectModal.id) return

  if (rejectModal.isIfood && !rejectModal.selectedReason) {
    showNotify('Selecione o motivo de cancelamento exigido pelo iFood.', 'error')
    return
  }

  rejectModal.loading = true

  try {
    const extra = rejectModal.isIfood
      ? { ifood_cancellation_reason: rejectModal.selectedReason }
      : {}

    await updateStatus(rejectModal.id, 'canceled', 'cancel', extra)
    rejectModal.show = false
    showNotify('Pedido cancelado e cliente notificado.', 'error')
  } catch (err) {
    showNotify(err.response?.data?.message || 'Erro ao cancelar pedido.', 'error')
  } finally {
    rejectModal.loading = false
  }
}

const acceptOrder = (orderId) => {
  updateStatus(orderId, 'preparing', 'prepare')
}

const updateStatus = async (orderId, newStatus, actionKey = newStatus, extraPayload = {}) => {
  if (!orderId || updatingStatus.value) return

  updatingStatus.value = true
  updatingAction.value = actionKey

  try {
    const { data } = await api.patch(`/merchant/orders/${orderId}/status`, {
      status: newStatus,
      ...extraPayload
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

    await fetchOrders({ silent: true })
  } catch (err) {
    const errorMsg =
      err.response?.data?.message ||
      err.response?.data?.details ||
      'Erro ao atualizar status.'
    showNotify(errorMsg, 'error')
    throw err
  } finally {
    updatingStatus.value = false
    updatingAction.value = null
  }
}

const openDetails = (order) => {
  selectedOrder.value = order
  modalDetails.value = true
}

const filteredOrders = computed(() => orders.value)

const getTabCount = (key) => {
  if (key === 'all') return statusCounts.value.all ?? 0
  return statusCounts.value[key] ?? 0
}

const paginationStart = computed(() => {
  if (paginationMeta.value.total === 0) return 0
  return (paginationMeta.value.current_page - 1) * paginationMeta.value.per_page + 1
})

const paginationEnd = computed(() => {
  return Math.min(
    paginationMeta.value.current_page * paginationMeta.value.per_page,
    paginationMeta.value.total
  )
})

const visiblePages = computed(() => {
  const pages = []
  const total = paginationMeta.value.last_page
  const current = paginationMeta.value.current_page
  const start = Math.max(1, current - 2)
  const end = Math.min(total, current + 2)

  for (let page = start; page <= end; page++) {
    pages.push(page)
  }

  return pages
})

const goToPage = (page) => {
  const next = Math.min(Math.max(1, page), paginationMeta.value.last_page)
  if (next !== currentPage.value) {
    currentPage.value = next
    fetchOrders()
  }
}

const changeFilter = (key) => {
  if (filterStatus.value === key) return

  filterStatus.value = key
  currentPage.value = 1
  fetchOrders()
}

const selectedOrderStatus = computed(() => normalizeOrderStatus(selectedOrder.value?.status))
const selectedOrderStatusInfo = computed(() => getStatusInfo(selectedOrder.value?.status))
const isSelectedOrderStalePending = computed(() =>
  selectedOrder.value ? isOrderStalePending(selectedOrder.value) : false
)

const canPrepare = computed(() =>
  selectedOrderStatus.value === 'pending' && !isSelectedOrderStalePending.value
)
const canMarkReady = computed(() => selectedOrderStatus.value === 'preparing')
const canShip = computed(() => selectedOrderStatus.value === 'ready')
const canDeliver = computed(() => selectedOrderStatus.value === 'shipped')
const canCancel = computed(() => !['canceled', 'delivered'].includes(selectedOrderStatus.value))

onMounted(() => {
  window.addEventListener('partiumenu:order-created', handleRealtimeOrderCreated)
  window.addEventListener('partiumenu:order-updated', handleRealtimeOrderUpdated)
  window.addEventListener('partiumenu:pending-orders-sync', handlePendingOrdersSync)
  fetchOrders()
})

useOnStoreSwitch(() => {
  hasInitializedFilter.value = false
  currentPage.value = 1
  filterStatus.value = 'all'
  recentRealtimeOrderIds.clear()
  fetchOrders()
})

onBeforeUnmount(() => {
  if (ordersRefreshTimer) {
    clearTimeout(ordersRefreshTimer)
    ordersRefreshTimer = null
  }

  window.removeEventListener('partiumenu:order-created', handleRealtimeOrderCreated)
  window.removeEventListener('partiumenu:order-updated', handleRealtimeOrderUpdated)
  window.removeEventListener('partiumenu:pending-orders-sync', handlePendingOrdersSync)
})
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <header class="pm-page-header">
        <div class="flex items-center gap-4">
          <div class="pm-page-icon">
            <ShoppingBag size="26" />
          </div>

          <div>
            <h1 class="pm-page-title">Pedidos</h1>
            <p class="pm-page-subtitle">Gerencie as vendas em tempo real.</p>
          </div>
        </div>

        <div class="flex bg-slate-100 p-1 rounded-2xl overflow-x-auto">
          <button
            v-for="(val, key) in statusFilters"
            :key="key"
            @click="changeFilter(key)"
            :disabled="updatingStatus"
            :class="[
              'px-4 py-2 rounded-xl text-xs font-black transition-all whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed',
              filterStatus === key
                ? 'bg-white shadow-sm text-red-500'
                : 'text-slate-500 hover:text-slate-700'
            ]">
            {{ val }}<span v-if="getTabCount(key) > 0" class="ml-1 opacity-80">({{ getTabCount(key) }})</span>
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
            'p-5 rounded-3xl border shadow-sm transition-all group flex items-center justify-between cursor-pointer',
            getOrderCardClass(order),
            updatingStatus ? 'opacity-90' : ''
          ]"
        >
          <div class="flex items-center gap-5">
            <div :class="[
              'w-14 h-14 rounded-2xl flex items-center justify-center transition-transform group-hover:scale-105',
              isOrderFinished(order) || isOrderStalePending(order)
                ? 'bg-slate-100 text-slate-400'
                : getStatusInfo(order.status).color
            ]">
              <component :is="getStatusInfo(order.status).icon" size="24" />
            </div>

            <div>
              <div class="flex items-center gap-2 flex-wrap">
                <span :class="['font-black text-lg', getOrderTextClass(order)]">
                  #{{ order.display_code || order.display_number || order.id.toString().padStart(4, '0') }}
                </span>

                <span
                  v-if="isOrderAwaitingPix(order)"
                  class="text-[10px] font-black uppercase px-2 py-1 bg-sky-100 rounded-lg text-sky-700"
                >
                  Aguardando pagamento
                </span>

                <span
                  v-if="isOrderWaitingAcceptance(order)"
                  class="text-[10px] font-black uppercase px-2 py-1 bg-amber-100 rounded-lg text-amber-700 animate-pulse"
                >
                  Aguardando aceite
                </span>

                <span
                  v-if="isOrderStalePending(order)"
                  class="text-[10px] font-black uppercase px-2 py-1 bg-slate-100 rounded-lg text-slate-500"
                >
                  Expirado
                </span>

                <span
                  v-if="order.order_source === 'ifood' && !isOrderFinished(order)"
                  class="text-[10px] font-black uppercase px-2 py-1 bg-red-50 rounded-lg text-red-600"
                >
                  iFood
                </span>

                <span
                  v-if="order.order_source === 'ifood' && isOrderFinished(order)"
                  class="text-[10px] font-black uppercase px-2 py-1 bg-slate-100 rounded-lg text-slate-400"
                >
                  iFood
                </span>

                <span
                  :class="[
                    'text-[10px] font-black uppercase px-2 py-1 rounded-lg',
                    isOrderFinished(order) || isOrderStalePending(order)
                      ? 'bg-slate-100 text-slate-400'
                      : 'bg-orange-50 text-orange-600'
                  ]"
                >
                  {{ order.fulfillment_type === 'pickup' ? 'Retirada' : 'Entrega' }}
                </span>

                <span
                  :class="[
                    'text-[10px] font-black uppercase px-2 py-1 rounded-lg flex items-center gap-1',
                    isOrderFinished(order) || isOrderStalePending(order)
                      ? 'bg-slate-100 text-slate-400'
                      : 'bg-slate-100 text-slate-500'
                  ]"
                >
                  <Clock size="12" />
                  {{ formatOrderDateTime(order.created_at) }}
                </span>
              </div>

              <div :class="['text-sm font-medium mt-1', isOrderFinished(order) || isOrderStalePending(order) ? 'text-slate-400' : 'text-slate-500']">
                <span
                  v-for="(item, idx) in order.items"
                  :key="item.id"
                  :class="isOrderFinished(order) || isOrderStalePending(order) ? 'text-slate-400 font-semibold' : 'text-slate-700 font-bold'"
                >
                  {{ item.quantity }}x {{ item.product?.name || item.observation || 'Item' }}{{ idx < order.items.length - 1 ? ', ' : '' }}
                </span>

                <span :class="isOrderFinished(order) || isOrderStalePending(order) ? 'text-slate-400 ml-1' : 'text-red-500 ml-1'">
                  • R$ {{ formatMoney(order.total_amount) }}
                </span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-4">
            <div class="hidden md:block text-right">
              <p class="text-xs font-black text-slate-400 uppercase tracking-widest">Status</p>
              <p :class="['font-bold', isOrderFinished(order) || isOrderStalePending(order) ? 'text-slate-400' : 'text-slate-700']">
                {{ getStatusInfo(order.status).label }}
              </p>
              <p class="text-[11px] font-bold text-slate-400 mt-1">
                {{ formatOrderTime(order.created_at) }}
              </p>
            </div>

            <ChevronRight class="text-slate-300 group-hover:text-red-500 transition-colors" />
          </div>
        </div>

        <div
          v-if="paginationMeta.total > paginationMeta.per_page"
          class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white border border-slate-200 rounded-2xl px-4 py-3"
        >
          <p class="text-sm font-semibold text-slate-500">
            Mostrando {{ paginationStart }}-{{ paginationEnd }} de {{ paginationMeta.total }}
          </p>

          <div class="flex items-center gap-2">
            <button
              type="button"
              @click="goToPage(currentPage - 1)"
              :disabled="currentPage === 1 || loading"
              class="p-2 rounded-xl border border-slate-200 text-slate-500 disabled:opacity-40 hover:bg-slate-50"
            >
              <ChevronLeft size="18" />
            </button>

            <button
              v-for="page in visiblePages"
              :key="page"
              type="button"
              @click="goToPage(page)"
              :disabled="loading"
              :class="[
                'min-w-9 px-3 py-2 rounded-xl text-xs font-black transition-all',
                currentPage === page
                  ? 'bg-red-500 text-white shadow-sm'
                  : 'text-slate-500 hover:bg-slate-50 border border-slate-200'
              ]"
            >
              {{ page }}
            </button>

            <button
              type="button"
              @click="goToPage(currentPage + 1)"
              :disabled="currentPage === paginationMeta.last_page || loading"
              class="p-2 rounded-xl border border-slate-200 text-slate-500 disabled:opacity-40 hover:bg-slate-50"
            >
              <ChevronRight size="18" />
            </button>
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
            class="absolute inset-0 bg-slate-900/30 backdrop-blur-[2px]"
            @click="closeDetails"
          />

          <transition name="slide-drawer" appear>
            <aside
              class="absolute right-0 top-0 h-full w-full max-w-xl bg-slate-50 shadow-xl flex flex-col border-l border-slate-200/80"
            >
              <header class="px-5 py-4 border-b border-slate-200/70 bg-white flex items-start justify-between gap-4">
                <div class="flex items-start gap-3 min-w-0">
                  <div :class="[
                    'w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0',
                    selectedOrderStatusInfo.color
                  ]">
                    <component :is="selectedOrderStatusInfo.icon" size="22" />
                  </div>

                  <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <h2 class="text-lg font-bold text-slate-900">
                        Pedido #{{ selectedOrder.display_code || selectedOrder.display_number || selectedOrder.id.toString().padStart(4, '0') }}
                      </h2>

                      <span :class="[
                        'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                        selectedOrderStatusInfo.color
                      ]">
                        {{ selectedOrderStatusInfo.shortLabel }}
                      </span>
                    </div>

                    <p class="text-xs font-medium text-slate-400 mt-1">
                      {{ formatOrderDateTime(selectedOrder.created_at) }}
                    </p>
                  </div>
                </div>

                <button
                  @click="closeDetails"
                  :disabled="updatingStatus || rejectModal.loading"
                  class="w-8 h-8 rounded-lg bg-slate-100 text-slate-400 hover:bg-slate-200 hover:text-slate-700 flex items-center justify-center transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <X size="17" />
                </button>
              </header>

              <section class="px-5 py-3 border-b border-slate-200/70 bg-white/80">
                <div
                  v-if="isSelectedOrderStalePending"
                  class="mb-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs font-medium text-slate-500"
                >
                  Este pedido passou da janela de aceite (24h). Você ainda pode ver os detalhes e imprimir, mas não é mais possível aceitá-lo.
                </div>

                <div class="mb-2 flex items-center justify-between gap-3">
                  <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">
                    Ações rápidas
                  </p>

                  <button
                    @click="handlePrintOrder(selectedOrder.id)"
                    :disabled="printingOrder || updatingStatus"
                    class="h-8 px-3 rounded-lg border border-slate-200 bg-white text-slate-700 font-bold text-xs flex items-center gap-1.5 hover:bg-slate-50 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
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
                    class="h-9 px-3.5 rounded-lg bg-red-500 text-white font-bold text-xs hover:bg-red-600 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'prepare'" class="animate-spin" size="14" />
                    {{ updatingAction === 'prepare' ? 'Aceitando...' : 'Aceitar pedido' }}
                  </button>

                  <button
                    v-if="canMarkReady"
                    @click="updateStatus(selectedOrder.id, 'ready', 'ready')"
                    :disabled="updatingStatus"
                    class="h-9 px-3.5 rounded-lg bg-red-500 text-white font-bold text-xs hover:bg-red-600 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'ready'" class="animate-spin" size="14" />
                    {{ updatingAction === 'ready' ? 'Salvando...' : 'Marcar pronto' }}
                  </button>

                  <button
                    v-if="canShip"
                    @click="updateStatus(selectedOrder.id, 'shipped', 'shipped')"
                    :disabled="updatingStatus"
                    class="h-9 px-3.5 rounded-lg bg-red-500 text-white font-bold text-xs hover:bg-red-600 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'shipped'" class="animate-spin" size="14" />
                    {{ updatingAction === 'shipped' ? 'Salvando...' : 'Saiu para entrega' }}
                  </button>

                  <button
                    v-if="canDeliver"
                    @click="updateStatus(selectedOrder.id, 'delivered', 'delivered')"
                    :disabled="updatingStatus"
                    class="h-9 px-3.5 rounded-lg bg-red-500 text-white font-bold text-xs hover:bg-red-600 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'delivered'" class="animate-spin" size="14" />
                    {{ updatingAction === 'delivered' ? 'Finalizando...' : 'Finalizar pedido' }}
                  </button>

                  <button
                    v-if="canCancel"
                    @click="openRejectModal(selectedOrder.id)"
                    :disabled="updatingStatus || rejectModal.loading"
                    class="h-9 px-3.5 rounded-lg bg-white text-red-500 border border-red-200 font-bold text-xs hover:bg-red-50 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Loader2 v-if="updatingAction === 'cancel' || rejectModal.loading" class="animate-spin" size="14" />
                    {{ updatingAction === 'cancel' || rejectModal.loading ? 'Cancelando...' : 'Cancelar' }}
                  </button>
                </div>
              </section>

              <div class="flex-1 overflow-y-auto p-5 space-y-4">
                <section class="grid sm:grid-cols-2 gap-3">
                  <div class="rounded-xl border border-slate-200/80 bg-white p-3.5">
                    <div class="flex items-center gap-2 mb-2">
                      <User size="15" class="text-slate-400" />
                      <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Cliente</h3>
                    </div>

                    <p class="font-bold text-slate-800">
                      {{ getCustomerName(selectedOrder) }}
                    </p>

                    <p class="text-xs font-medium text-slate-500 mt-1 flex items-center gap-1.5">
                      <Phone size="13" />
                      {{ getCustomerPhone(selectedOrder) }}
                    </p>
                  </div>

                  <div class="rounded-xl border border-slate-200/80 bg-white p-3.5">
                    <div class="flex items-center gap-2 mb-2">
                      <MapPin size="15" class="text-slate-400" />
                      <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Entrega</h3>
                    </div>

                    <p class="text-xs font-medium text-slate-600 leading-relaxed">
                      {{ getDeliveryAddress(selectedOrder) }}
                    </p>
                  </div>
                </section>

                <section
                  v-if="selectedOrder.order_source === 'ifood'"
                  class="rounded-xl border border-rose-100 bg-rose-50/50 p-3.5"
                >
                  <div class="flex items-center gap-2 mb-2">
                    <PackageCheck size="15" class="text-rose-500" />
                    <h3 class="text-xs font-bold uppercase tracking-wide text-rose-700">Pedido iFood</h3>
                  </div>

                  <div class="space-y-1.5 text-xs font-medium text-rose-900/75">
                    <p v-if="getIfoodOrderTypeLabel(selectedOrder)">
                      Tipo: {{ getIfoodOrderTypeLabel(selectedOrder) }}
                    </p>
                    <p v-if="getIfoodDeliveredByLabel(selectedOrder)">
                      Logística: {{ getIfoodDeliveredByLabel(selectedOrder) }}
                    </p>
                    <p v-if="selectedOrder.ifood_delivery_localizer && selectedOrder.ifood_delivered_by === 'MERCHANT'">
                      Cód. localizador: <span class="font-black">{{ selectedOrder.ifood_delivery_localizer }}</span>
                    </p>
                    <p v-if="selectedOrder.ifood_delivered_by === 'MERCHANT'" class="text-[11px] text-rose-600/80">
                      No iFood, entrega própria conclui automaticamente após o despacho. Finalizar aqui arquiva no PartiuMenu.
                    </p>
                    <p v-if="selectedOrder.ifood_confirmed_at">
                      Aceito no iFood: {{ formatOrderDateTime(selectedOrder.ifood_confirmed_at) }}
                    </p>
                  </div>
                </section>

                <section
                  v-if="getOrderObservation(selectedOrder)"
                  class="rounded-xl border border-amber-100/80 bg-amber-50/40 p-3.5"
                >
                  <div class="flex items-center gap-2 mb-1.5">
                    <MessageSquare size="15" class="text-amber-500" />
                    <h3 class="text-xs font-bold uppercase tracking-wide text-amber-700">Observação do pedido</h3>
                  </div>

                  <p class="text-xs font-medium text-amber-900/85">
                    {{ getOrderObservation(selectedOrder) }}
                  </p>
                </section>

                <section class="rounded-xl border border-slate-200/80 bg-white p-3.5">
                  <div class="mb-3 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                      <div
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-500">
                        <Package size="16" />
                      </div>

                      <div>
                        <h3 class="text-sm font-bold text-slate-800">Itens do pedido</h3>
                        <p class="text-[11px] font-medium text-slate-400">
                          Produtos, adicionais e observações
                        </p>
                      </div>
                    </div>

                    <span class="rounded-full bg-slate-50 px-2.5 py-0.5 text-[11px] font-bold text-slate-500 border border-slate-200">
                      {{ getOrderItems(selectedOrder).length }} item(ns)
                    </span>
                  </div>

                  <div class="space-y-3">
                    <div
                      v-for="item in getOrderItems(selectedOrder)"
                      :key="item.id || item.product_id || getItemName(item)"
                      class="rounded-xl border border-slate-100 bg-slate-50/60 p-3.5"
                    >
                      <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                          <p class="text-sm font-bold text-slate-900">
                            {{ item.quantity || 1 }}x {{ getItemName(item) }}
                          </p>

                          <p class="mt-0.5 text-[11px] font-medium text-slate-400">
                            Unitário R$ {{ formatMoney(getItemUnitPrice(item)) }}
                          </p>
                        </div>

                        <p
                          class="rounded-lg bg-white px-2.5 py-1 text-xs font-bold text-slate-700 whitespace-nowrap border border-slate-200">
                          R$ {{ formatMoney(getItemTotal(item)) }}
                        </p>
                      </div>

                      <div v-if="hasItemOptions(item)" class="mt-3 space-y-2">
                        <div
                          v-for="(options, groupName) in getGroupedItemOptions(item)"
                          :key="groupName"
                          class="rounded-lg border border-slate-200/70 bg-white p-2.5"
                        >
                          <div class="mb-1.5 flex items-center gap-1.5">
                            <PlusCircle size="13" class="text-slate-400" />
                            <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">
                              {{ groupName }}
                            </p>
                          </div>

                          <div class="space-y-1">
                            <div
                              v-for="(option, optionIndex) in options"
                              :key="`${groupName}-${optionIndex}`"
                              class="flex items-center justify-between gap-3 text-xs"
                            >
                              <span class="font-medium text-slate-600">
                                {{ getOptionName(option) }}
                              </span>

                              <span v-if="getOptionPrice(option) > 0" class="font-bold text-slate-700">
                                + R$ {{ formatMoney(getOptionPrice(option)) }}
                              </span>

                              <span v-else class="text-[11px] font-medium text-slate-400">
                                Incluso
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div
                        v-if="getItemObservation(item)"
                        class="mt-3 rounded-lg border border-amber-100/80 bg-amber-50/50 px-2.5 py-2"
                      >
                        <p class="text-[10px] font-bold uppercase tracking-wide text-amber-600">
                          Observação do item
                        </p>

                        <p class="mt-0.5 text-xs font-medium text-amber-900/85">
                          {{ getItemObservation(item) }}
                        </p>
                      </div>
                    </div>
                  </div>
                </section>

                <section class="rounded-xl border border-slate-200/80 bg-white p-3.5">
                  <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                      <div class="flex items-center gap-2 mb-2">
                        <CreditCard size="15" class="text-slate-400" />
                        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Pagamento</h3>
                      </div>

                      <p class="text-xs font-medium text-slate-600">
                        {{ getPaymentMethod(selectedOrder) }}
                      </p>

                      <p class="mt-1.5 text-lg font-bold text-slate-900">
                        R$ {{ formatMoney(selectedOrder.total_amount) }}
                      </p>
                    </div>

                    <div class="sm:border-l sm:border-slate-100 sm:pl-4">
                      <div class="flex items-center gap-2 mb-2">
                        <ClipboardList size="15" class="text-slate-400" />
                        <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Resumo</h3>
                      </div>

                      <div class="space-y-1.5 text-xs">
                        <div class="flex justify-between gap-3">
                          <span class="font-medium text-slate-500">Subtotal</span>
                          <span class="font-bold text-slate-800">
                            R$ {{ formatMoney(selectedOrder.subtotal_amount || selectedOrder.subtotal ||
                              selectedOrder.total_amount) }}
                          </span>
                        </div>

                        <div v-if="Number(selectedOrder.delivery_fee || 0) > 0" class="flex justify-between gap-3">
                          <span class="font-medium text-slate-500">Entrega</span>
                          <span class="font-bold text-slate-800">
                            R$ {{ formatMoney(selectedOrder.delivery_fee) }}
                          </span>
                        </div>

                        <div v-if="hasCouponDiscount(selectedOrder)" class="flex justify-between gap-3 text-emerald-600">
                          <span class="font-medium">
                            Cupom {{ getCouponCode(selectedOrder) }}
                          </span>
                          <span class="font-bold">
                            - R$ {{ formatMoney(selectedOrder.discount_amount) }}
                          </span>
                        </div>

                        <p v-if="hasCouponDiscount(selectedOrder) && getCouponDescription(selectedOrder)"
                          class="text-[11px] font-medium text-slate-400">
                          {{ getCouponDescription(selectedOrder) }}
                        </p>

                        <div class="pt-2 border-t border-slate-100 flex justify-between gap-3">
                          <span class="font-bold text-slate-800">Total</span>
                          <span class="font-bold text-red-500">
                            R$ {{ formatMoney(selectedOrder.total_amount) }}
                          </span>
                        </div>
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
            <template v-if="rejectModal.isIfood">
              Pedidos iFood exigem um motivo de cancelamento. A loja será sincronizada com o iFood.
            </template>
            <template v-else>
              Essa ação marcará o pedido como cancelado. O cliente poderá ser notificado conforme as integrações ativas.
            </template>
          </p>

          <div v-if="rejectModal.loadingReasons" class="mt-5 flex items-center gap-2 text-sm font-semibold text-slate-500">
            <Loader2 class="animate-spin" size="16" />
            Carregando motivos do iFood...
          </div>

          <div v-else-if="rejectModal.isIfood && rejectModal.reasons.length" class="mt-5">
            <label for="ifood-cancel-reason" class="text-[10px] font-black uppercase tracking-widest text-slate-400">
              Motivo de cancelamento (iFood)
            </label>
            <select
              id="ifood-cancel-reason"
              v-model="rejectModal.selectedReason"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-800 outline-none transition focus:border-red-300 focus:bg-white focus:ring-2 focus:ring-red-100"
            >
              <option value="" disabled>Selecione um motivo</option>
              <option
                v-for="reason in rejectModal.reasons"
                :key="reason.code"
                :value="reason.code"
              >
                {{ reason.description }}
              </option>
            </select>
          </div>

          <div class="mt-6 flex justify-end gap-2">
            <button
              @click="rejectModal.show = false"
              :disabled="rejectModal.loading || rejectModal.loadingReasons"
              class="h-11 px-4 rounded-xl bg-slate-100 text-slate-600 font-black text-sm hover:bg-slate-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            >
              Voltar
            </button>

            <button
              @click="handleRejectOrder"
              :disabled="rejectModal.loading || rejectModal.loadingReasons || updatingStatus || (rejectModal.isIfood && !rejectModal.selectedReason)"
              class="h-11 px-4 rounded-xl bg-red-600 text-white font-black text-sm hover:bg-red-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
            >
              <Loader2 v-if="rejectModal.loading || updatingAction === 'cancel'" class="animate-spin" size="16" />
              {{ rejectModal.loading || updatingAction === 'cancel' ? 'Cancelando...' : 'Confirmar cancelamento' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
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