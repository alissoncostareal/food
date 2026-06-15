<script setup>
import { ref, onMounted, onBeforeUnmount, reactive, computed, watch } from 'vue'
import api from '@/services/api'
import { getApiErrorMessage } from '@/utils/apiError'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useOnStoreSwitch } from '@/composables/useOnStoreSwitch'
import {
  Plus,
  Pencil,
  Trash2,
  Utensils,
  Image as ImageIcon,
  X,
  Loader2,
  CheckCircle,
  XCircle,
  LayoutGrid,
  FolderPlus,
  UtensilsCrossed,
  ListTree,
  Eye,
  EyeOff,
  Search,
  ChevronLeft,
  ChevronRight,
  ShoppingBag
} from 'lucide-vue-next'

const products = ref([])
const categories = ref([])
const currentStore = ref(null)
const loading = ref(true)
const errors = ref(null)

const searchTerm = ref('')
const statusFilter = ref('all')
const currentPage = ref(1)
const perPage = ref(10)

const toast = ref({ show: false, message: '', type: 'success' })

const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => {
    toast.value.show = false
  }, 4000)
}

const formatPrice = (value) =>
  Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

const modal = reactive({
  show: false,
  isEdit: false,
  saving: false,
  currentId: null
})

const deleteModal = reactive({
  show: false,
  id: null,
  type: 'product',
  loading: false
})

const createEmptyOptionItem = () => ({
  id: null,
  name: '',
  price: 0,
  image_url: null,
  local_preview: null,
  is_new_file: false,
  new_file_object: null
})

const getItemImageUrl = (item) => {
  if (typeof item.image_url === 'string' && item.image_url) return item.image_url
  if (typeof item.image === 'string' && item.image) return item.image
  if (typeof item.image_path === 'string' && item.image_path) return item.image_path
  if (typeof item.photo === 'string' && item.photo) return item.photo
  if (typeof item.image_url?.url === 'string' && item.image_url.url) return item.image_url.url
  return null
}

const optionsModal = reactive({
  show: false,
  saving: false,
  isEdit: false,
  currentGroupId: null,
  product: null,
  form: {
    name: '',
    min_selected: 0,
    max_selected: 1,
    items: [createEmptyOptionItem()]
  }
})

const form = reactive({
  name: '',
  description: '',
  price: '',
  product_category_id: '',
  image: null,
  local_preview: null,
  is_new_file: false
})

const showCategoryInput = ref(false)
const newCategoryName = ref('')
const catLoading = ref(false)

const normalizedSearch = computed(() => searchTerm.value.trim().toLowerCase())

const filteredProducts = computed(() => {
  return products.value.filter((product) => {
    const matchesStatus =
      statusFilter.value === 'all' ||
      (statusFilter.value === 'active' && product.is_active) ||
      (statusFilter.value === 'inactive' && !product.is_active)

    const haystack = [
      product.name,
      product.description,
      product.category?.name
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    const matchesSearch =
      !normalizedSearch.value ||
      haystack.includes(normalizedSearch.value)

    return matchesStatus && matchesSearch
  })
})

const totalPages = computed(() => {
  return Math.max(1, Math.ceil(filteredProducts.value.length / Number(perPage.value || 10)))
})

const paginatedProducts = computed(() => {
  const start = (currentPage.value - 1) * Number(perPage.value)
  const end = start + Number(perPage.value)

  return filteredProducts.value.slice(start, end)
})

const paginationStart = computed(() => {
  if (filteredProducts.value.length === 0) return 0
  return (currentPage.value - 1) * Number(perPage.value) + 1
})

const paginationEnd = computed(() => {
  return Math.min(currentPage.value * Number(perPage.value), filteredProducts.value.length)
})

const visiblePages = computed(() => {
  const pages = []
  const total = totalPages.value
  const current = currentPage.value

  const start = Math.max(1, current - 2)
  const end = Math.min(total, current + 2)

  for (let page = start; page <= end; page++) {
    pages.push(page)
  }

  return pages
})

watch([searchTerm, statusFilter, perPage], () => {
  currentPage.value = 1
})

watch(totalPages, (nextTotal) => {
  if (currentPage.value > nextTotal) {
    currentPage.value = nextTotal
  }
})

const goToPage = (page) => {
  currentPage.value = Math.min(Math.max(1, page), totalPages.value)
}

const fetchData = async () => {
  try {
    loading.value = true

    const [prodRes, catRes, storeRes] = await Promise.all([
      api.get('/merchant/products'),
      api.get('/merchant/categories'),
      api.get('/merchant/store')
    ])

    products.value = prodRes.data.data || prodRes.data
    categories.value = catRes.data.data || catRes.data
    currentStore.value = storeRes.data.data || storeRes.data
  } catch (error) {
    showNotify('Erro ao carregar dados.', 'error')
  } finally {
    loading.value = false
  }
}

const handleMainImageChange = (e) => {
  const file = e.target.files[0]

  if (file) {
    form.image = file
    form.local_preview = URL.createObjectURL(file)
    form.is_new_file = true
  }
}

const handleItemImageChange = (e, item) => {
  const file = e.target.files?.[0]

  if (file) {
    item.new_file_object = file
    item.local_preview = URL.createObjectURL(file)
    item.is_new_file = true
    item.image_url = item.local_preview
  }

  e.target.value = ''
}

const handleCreateCategory = async () => {
  if (!newCategoryName.value.trim()) return

  catLoading.value = true

  try {
    const { data } = await api.post('/merchant/categories', {
      name: newCategoryName.value.trim()
    })

    const saved = data.data || data

    categories.value.push(saved)
    form.product_category_id = saved.id
    newCategoryName.value = ''
    showCategoryInput.value = false

    showNotify('Categoria criada!')
  } catch (err) {
    showNotify('Erro ao criar categoria.', 'error')
  } finally {
    catLoading.value = false
  }
}

const openModal = async (product = null) => {
  errors.value = null
  showCategoryInput.value = false

  if (product) {
    modal.isEdit = true
    modal.currentId = product.id
    modal.show = true

    form.name = product.name
    form.description = product.description
    form.price = product.price
    form.product_category_id = product.product_category_id
    form.image = null
    form.local_preview = product.image || null
    form.is_new_file = false

    try {
      const { data } = await api.get(`/merchant/products/${product.id}`)
      const fullProduct = data.data || data

      form.name = fullProduct.name
      form.description = fullProduct.description
      form.price = fullProduct.price
      form.product_category_id = fullProduct.category?.id || fullProduct.product_category_id
      form.local_preview = fullProduct.image || null
      form.is_new_file = false
    } catch (err) {
      console.error('Erro ao buscar detalhes:', err)
    }
  } else {
    modal.isEdit = false
    modal.currentId = null

    form.name = ''
    form.description = ''
    form.price = ''
    form.product_category_id = ''
    form.image = null
    form.local_preview = null
    form.is_new_file = false

    modal.show = true
  }
}

const handleSubmit = async () => {
  modal.saving = true
  errors.value = null

  const formData = new FormData()

  formData.append('name', form.name)
  formData.append('description', form.description || '')
  formData.append('price', form.price)

  if (form.product_category_id) {
    formData.append('product_category_id', form.product_category_id)
  }

  if (form.image && form.image.size) {
    formData.append('image', form.image)
  }

  try {
    if (modal.isEdit) {
      await api.put(`/merchant/products/${modal.currentId}`, formData)
    } else {
      await api.post('/merchant/products', formData)
    }

    showNotify(modal.isEdit ? 'Item atualizado!' : 'Adicionado ao cardápio!')

    await fetchData()

    modal.show = false
  } catch (err) {
    if (err.response?.status === 422) {
      errors.value = err.response.data.errors
    }

    const msg = getApiErrorMessage(err, 'Erro ao salvar item.')
    showNotify(msg, 'error')
  } finally {
    modal.saving = false
  }
}

const handleToggleProductStatus = async (product) => {
  try {
    const { data } = await api.patch(`/merchant/products/${product.id}/toggle-status`)

    product.is_active = data.is_active

    const originalProduct = products.value.find((item) => item.id === product.id)

    if (originalProduct) {
      originalProduct.is_active = data.is_active
    }

    showNotify(
      data.is_active ? 'Produto ativado no cardápio.' : 'Produto marcado como esgotado.',
      'success'
    )
  } catch (err) {
    const msg = err.response?.data?.details || err.response?.data?.message || 'Erro ao alterar status do produto.'
    showNotify(msg, 'error')
  }
}

const handleToggleCartHighlight = async (product) => {
  try {
    const { data } = await api.patch(`/merchant/products/${product.id}/toggle-cart-highlight`)

    product.show_in_cart = data.show_in_cart
    product.cart_highlight_order = data.cart_highlight_order

    const originalProduct = products.value.find((item) => item.id === product.id)

    if (originalProduct) {
      originalProduct.show_in_cart = data.show_in_cart
      originalProduct.cart_highlight_order = data.cart_highlight_order
    }

    showNotify(data.message || 'Destaque do carrinho atualizado.', 'success')
  } catch (err) {
    const msg = err.response?.data?.message || err.response?.data?.error || 'Erro ao atualizar destaque do carrinho.'
    showNotify(msg, 'error')
  }
}

const openOptionsModal = async (product) => {
  resetOptionsForm()
  optionsModal.product = product
  optionsModal.show = true

  try {
    const { data } = await api.get(`/merchant/products/${product.id}`)
    optionsModal.product = data.data || data
  } catch (err) {
    console.error('Erro ao carregar detalhes do produto', err)
  }
}

const editOptionGroup = (group) => {
  optionsModal.isEdit = true
  optionsModal.currentGroupId = group.id
  optionsModal.form.name = group.name
  optionsModal.form.min_selected = group.min_selected
  optionsModal.form.max_selected = group.max_selected

  optionsModal.form.items = group.items.map((item) => {
    const savedImageUrl = getItemImageUrl(item)

    return {
      id: item.id,
      name: item.name,
      price: item.price,
      image_url: savedImageUrl,
      local_preview: savedImageUrl,
      is_new_file: false,
      new_file_object: null
    }
  })
}

const resetOptionsForm = () => {
  optionsModal.isEdit = false
  optionsModal.currentGroupId = null
  optionsModal.form = {
    name: '',
    min_selected: 0,
    max_selected: 1,
    items: [createEmptyOptionItem()]
  }
}

const handleSaveOptions = async () => {
  optionsModal.saving = true

  try {
    const formData = new FormData()

    formData.append('name', optionsModal.form.name)
    formData.append('min_selected', Number(optionsModal.form.min_selected))
    formData.append('max_selected', Number(optionsModal.form.max_selected))

    optionsModal.form.items.forEach((item, index) => {
      if (item.id) {
        formData.append(`items[${index}][id]`, item.id)
      }

      formData.append(`items[${index}][name]`, item.name)
      formData.append(`items[${index}][price]`, Number(item.price))

      if (item.is_new_file && item.new_file_object) {
        formData.append(`items[${index}][image_url]`, item.new_file_object)
        formData.append(`items[${index}][image]`, item.new_file_object)
      }
    })

    const url = optionsModal.isEdit
      ? `/merchant/products/${optionsModal.product.id}/option-groups/${optionsModal.currentGroupId}`
      : `/merchant/products/${optionsModal.product.id}/option-groups`

    if (optionsModal.isEdit) {
      await api.put(url, formData)
    } else {
      await api.post(url, formData)
    }

    showNotify(optionsModal.isEdit ? 'Grupo de opcionais atualizado!' : 'Opcionais salvos!')

    const { data } = await api.get(`/merchant/products/${optionsModal.product.id}`)
    optionsModal.product = data.data || data

    resetOptionsForm()
    await fetchData()
  } catch (err) {
    const responseErrors = err.response?.data?.errors
    const firstError = responseErrors ? Object.values(responseErrors)[0][0] : 'Erro ao salvar opcionais.'
    showNotify(firstError, 'error')
  } finally {
    optionsModal.saving = false
  }
}

const confirmDelete = (id, type = 'product') => {
  deleteModal.id = id
  deleteModal.type = type
  deleteModal.show = true
}

const handleDelete = async () => {
  deleteModal.loading = true

  try {
    if (deleteModal.type === 'product') {
      const { data } = await api.delete(`/merchant/products/${deleteModal.id}`)

      if (data.deleted === false) {
        const product = products.value.find((item) => item.id === deleteModal.id)

        if (product) {
          product.is_active = false
          product.show_in_cart = false
          product.cart_highlight_order = null
        }

        showNotify(data.message || 'Produto marcado como esgotado.')
      } else {
        products.value = products.value.filter((product) => product.id !== deleteModal.id)
        showNotify(data.message || 'Produto removido.')
      }
    } else {
      await api.delete(`/merchant/products/${optionsModal.product.id}/option-groups/${deleteModal.id}`)

      const { data } = await api.get(`/merchant/products/${optionsModal.product.id}`)
      optionsModal.product = data.data || data

      if (optionsModal.currentGroupId === deleteModal.id) {
        resetOptionsForm()
      }

      await fetchData()

      showNotify('Grupo removido.')
    }

    deleteModal.show = false
  } catch (err) {
    const msg = err.response?.data?.details || err.response?.data?.message || 'Erro ao remover item.'
    showNotify(msg, 'error')
  } finally {
    deleteModal.loading = false
  }
}

onMounted(() => {
  fetchData()
})

useOnStoreSwitch(fetchData)
</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <PageHeader
        title="Cardápio"
        subtitle="Gerencie produtos, preços e disponibilidade."
      >
        <template #icon>
          <UtensilsCrossed size="26" />
        </template>
        <template #actions>
          <button @click="openModal()" class="pm-btn-ghost">
            <Plus size="18" />
            Novo item
          </button>
        </template>
      </PageHeader>

      <div class="pm-card">
        <div class="pm-card-toolbar">
          <div class="flex flex-col lg:flex-row gap-3 lg:items-center justify-between">
            <div class="relative flex-1 max-w-xl">
              <input
                v-model="searchTerm"
                type="text"
                placeholder="Pesquisar por produto, descrição ou categoria..."
                class="pm-input"
              />
            </div>

            <div class="flex flex-col sm:flex-row gap-3">
              <select
                v-model="statusFilter"
                class="pm-select"
              >
                <option value="all">Todos os status</option>
                <option value="active">Disponíveis</option>
                <option value="inactive">Esgotados</option>
              </select>

              <select
                v-model.number="perPage"
                class="pm-select"
              >
                <option :value="5">5 por página</option>
                <option :value="10">10 por página</option>
                <option :value="20">20 por página</option>
                <option :value="50">50 por página</option>
              </select>
            </div>
          </div>

          <div class="mt-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs font-black text-gray-400 uppercase tracking-widest">
            <span>
              {{ filteredProducts.length }} produto(s) encontrado(s)
            </span>
            <span class="normal-case font-semibold text-slate-500">
              Destaque no carrinho: escolha até 12 itens para sugerir ao cliente na sacola.
            </span>

            <button
              v-if="searchTerm || statusFilter !== 'all'"
              @click="searchTerm = ''; statusFilter = 'all'"
              class="text-red-600 hover:text-red-700"
            >
              Limpar filtros
            </button>
          </div>
        </div>

        <div v-if="loading" class="pm-loading">
          <Loader2 class="animate-spin" size="32" />
        </div>

        <div v-else-if="products.length === 0" class="p-20 text-center">
          <Utensils class="mx-auto text-gray-200 mb-4" size="48" />
          <p class="text-gray-400 font-medium">Nenhum prato cadastrado.</p>
        </div>

        <div v-else-if="filteredProducts.length === 0" class="p-20 text-center">
          <Search class="mx-auto text-gray-200 mb-4" size="48" />
          <p class="text-gray-400 font-medium">Nenhum produto encontrado com esses filtros.</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead class="bg-slate-50/80 border-b border-slate-100 text-[11px] font-semibold text-slate-500 uppercase tracking-wide">
              <tr>
                <th class="px-6 py-4 font-semibold">Item</th>
                <th class="px-6 py-4 font-semibold">Categoria</th>
                <th class="px-6 py-4 font-semibold">Status</th>
                <th class="px-6 py-4 font-semibold">Carrinho</th>
                <th class="px-6 py-4 text-right font-semibold">Preço</th>
                <th class="px-6 py-4 text-right font-semibold">Ações</th>
              </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
              <tr v-for="product in paginatedProducts" :key="product.id" :class="[
                'group hover:bg-slate-50/80 transition-colors',
                !product.is_active ? 'opacity-70' : ''
              ]">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-4">
                    <div
                      class="w-14 h-14 bg-gray-100 rounded-2xl overflow-hidden border border-gray-100 flex-shrink-0 group-hover:border-red-200 transition-all shadow-sm">
                      <img v-if="product.image" :src="product.image" class="w-full h-full object-cover">
                      <ImageIcon v-else class="w-full h-full p-4 text-gray-300" />
                    </div>

                    <div>
                      <span class="block font-black text-gray-800 text-base">
                        {{ product.name }}
                      </span>

                      <span v-if="product.option_groups?.length"
                        class="text-[10px] text-red-600 font-black uppercase tracking-tighter">
                        {{ product.option_groups.length }} Grupo(s) de Opcionais
                      </span>
                    </div>
                  </div>
                </td>

                <td class="px-6 py-4">
                  <span class="inline-flex px-2.5 py-1 rounded-md text-xs font-medium bg-slate-100 text-slate-600">
                    {{ product.category?.name || 'Geral' }}
                  </span>
                </td>

                <td class="px-6 py-4">
                  <button
                    type="button"
                    @click="handleToggleProductStatus(product)"
                    :class="[
                      'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium transition-colors',
                      product.is_active
                        ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                        : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
                    ]"
                  >
                    <span :class="['h-1.5 w-1.5 rounded-full', product.is_active ? 'bg-emerald-500' : 'bg-slate-400']" />
                    {{ product.is_active ? 'Ativo' : 'Pausado' }}
                  </button>
                </td>

                <td class="px-6 py-4">
                  <button
                    type="button"
                    @click="handleToggleCartHighlight(product)"
                    :disabled="!product.is_active"
                    :class="[
                      'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed',
                      product.show_in_cart
                        ? 'bg-orange-50 text-orange-700 hover:bg-orange-100'
                        : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
                    ]"
                    :title="product.show_in_cart ? 'Remover dos destaques do carrinho' : 'Destacar no carrinho do cliente'"
                  >
                    <ShoppingBag size="12" />
                    {{ product.show_in_cart ? 'Destaque' : 'Normal' }}
                  </button>
                </td>

                <td class="px-6 py-4 text-right">
                  <span class="text-sm font-semibold text-slate-800 tabular-nums">
                    {{ formatPrice(product.price) }}
                  </span>
                </td>

                <td class="px-6 py-4 text-right">
                  <div class="inline-flex items-center gap-1 rounded-xl border border-slate-100 bg-white p-1 shadow-sm">
                    <button @click.stop="openOptionsModal(product)"
                      class="p-2 text-slate-500 hover:text-orange-600 hover:bg-orange-50 rounded-lg transition-colors"
                      title="Opcionais">
                      <ListTree size="16" />
                    </button>

                    <button @click="openModal(product)"
                      class="p-2 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition-colors"
                      title="Editar">
                      <Pencil size="16" />
                    </button>

                    <button @click="confirmDelete(product.id)"
                      class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                      title="Remover">
                      <Trash2 size="16" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <div class="px-6 py-5 border-t border-gray-100 bg-white flex flex-col md:flex-row md:items-center justify-between gap-4">
            <p class="text-xs font-black text-gray-400 uppercase tracking-widest">
              Mostrando {{ paginationStart }}-{{ paginationEnd }} de {{ filteredProducts.length }}
            </p>

            <div class="flex items-center justify-end gap-2">
              <button
                @click="goToPage(currentPage - 1)"
                :disabled="currentPage === 1"
                class="w-10 h-10 rounded-xl border border-gray-100 flex items-center justify-center text-gray-500 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <ChevronLeft size="18" />
              </button>

              <button
                v-for="page in visiblePages"
                :key="page"
                @click="goToPage(page)"
                :class="[
                  'w-10 h-10 rounded-xl text-xs font-black transition-all',
                  currentPage === page
                    ? 'bg-red-600 text-white shadow-lg shadow-red-100'
                    : 'border border-gray-100 text-gray-500 hover:bg-gray-50'
                ]"
              >
                {{ page }}
              </button>

              <button
                @click="goToPage(currentPage + 1)"
                :disabled="currentPage === totalPages"
                class="w-10 h-10 rounded-xl border border-gray-100 flex items-center justify-center text-gray-500 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <ChevronRight size="18" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <transition name="slide-fade">
      <div v-if="modal.show" class="fixed inset-0 z-[60] flex justify-end">
        <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" @click="modal.show = false"></div>

        <div
          class="relative w-full max-w-lg bg-white h-screen shadow-2xl flex flex-col p-8 animate-slide-in overflow-y-auto">
          <div class="flex justify-between items-center mb-8">
            <h2 class="text-2xl font-black text-gray-900">
              {{ modal.isEdit ? 'Editar Item' : 'Novo Item' }}
            </h2>

            <button @click="modal.show = false"
              class="p-2 bg-gray-50 rounded-full hover:bg-red-600 hover:text-white transition-all">
              <X size="20" />
            </button>
          </div>

          <form @submit.prevent="handleSubmit" class="space-y-6">
            <div class="space-y-1">
              <label class="text-xs font-black text-gray-400 uppercase">Nome do Prato</label>
              <input v-model="form.name" type="text"
                class="pm-input"
                >
              <p v-if="errors?.name" class="text-[10px] text-red-600 font-bold uppercase tracking-widest mt-1">
                {{ errors.name[0] }}
              </p>
            </div>

            <div class="space-y-1">
              <label class="text-xs font-black text-gray-400 uppercase">Descrição/Ingredientes</label>
              <textarea v-model="form.description"
                class="pm-textarea min-h-[120px]"
                placeholder="O que vem no prato?"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="text-xs font-black text-gray-400 uppercase">Preço (R$)</label>
                <input v-model="form.price" type="number" step="0.01"
                  class="pm-input">
              </div>

              <div>
                <label class="text-xs font-black text-gray-400 uppercase">Categoria</label>
                <div class="flex gap-2">
                  <select v-model="form.product_category_id"
                    class="pm-select flex-grow">
                    <option value="">Selecione...</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                      {{ cat.name }}
                    </option>
                  </select>

                  <button type="button" @click="showCategoryInput = !showCategoryInput"
                    class="bg-gray-900 text-white p-4 rounded-2xl hover:bg-red-600 transition-all shadow-sm">
                    <FolderPlus size="20" />
                  </button>
                </div>
              </div>
            </div>

            <transition name="slide-up">
              <div v-if="showCategoryInput"
                class="p-5 bg-gradient-to-br from-gray-900 to-black rounded-3xl shadow-xl shadow-gray-200">
                <label class="text-[10px] font-black text-white uppercase mb-2 block tracking-widest">
                  Nova Categoria
                </label>

                <div class="flex gap-2">
                  <input v-model="newCategoryName" type="text"
                    class="flex-grow px-4 py-3 rounded-xl text-sm font-bold outline-none bg-gray-800 text-white border border-gray-700"
                    placeholder="Nome da categoria">

                  <button @click.prevent="handleCreateCategory"
                    class="px-5 py-2 bg-red-600 text-white rounded-xl text-xs font-black active:scale-95 transition-transform">
                    OK
                  </button>
                </div>
              </div>
            </transition>

            <div class="space-y-1">
              <label class="text-xs font-black text-gray-400 uppercase">Imagem do Prato</label>

              <div @click="$refs.fileInput.click()" :class="[
                'border-2 border-dashed rounded-3xl p-6 flex flex-col items-center cursor-pointer transition-all group relative overflow-hidden min-h-[160px] justify-center',
                form.local_preview
                  ? (form.is_new_file ? 'border-emerald-500 bg-emerald-50/20' : 'border-gray-700 bg-gray-50')
                  : 'border-gray-200 text-gray-400 hover:border-red-600 hover:bg-red-50'
              ]">
                <template v-if="form.local_preview">
                  <img :src="form.local_preview"
                    class="absolute inset-0 w-full h-full object-cover opacity-10 group-hover:opacity-5 transition-opacity" />

                  <div class="z-10 flex flex-col items-center text-center">
                    <div
                      class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center mb-2 shadow-md border border-gray-100">
                      <ImageIcon size="26" :class="form.is_new_file ? 'text-emerald-500' : 'text-gray-700'" />
                    </div>

                    <span v-if="!form.is_new_file"
                      class="px-2 py-0.5 bg-gray-900 text-white text-[9px] font-black uppercase tracking-widest rounded-md mb-1 animate-fade-in">
                      Imagem Salva (Mantida)
                    </span>

                    <span v-else
                      class="px-2 py-0.5 bg-emerald-500 text-white text-[9px] font-black uppercase tracking-widest rounded-md mb-1 animate-fade-in">
                      Nova Imagem Selecionada
                    </span>

                    <span class="text-xs font-bold text-gray-500 uppercase tracking-wide">
                      {{ form.image ? form.image.name : 'Clique para alterar a foto atual' }}
                    </span>
                  </div>
                </template>

                <template v-else>
                  <div
                    class="w-16 h-16 bg-gray-50 rounded-2xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <ImageIcon size="32" class="text-gray-300 group-hover:text-red-600" />
                  </div>

                  <span class="text-xs font-black uppercase tracking-widest text-center">
                    Selecione uma foto do prato
                  </span>
                </template>

                <input type="file" ref="fileInput" @change="handleMainImageChange" class="hidden" accept="image/*">
              </div>
            </div>

            <button type="submit" :disabled="modal.saving"
              class="w-full bg-red-600 text-white py-5 rounded-[2rem] font-black text-lg hover:bg-red-700 transition-all shadow-xl shadow-red-100 active:scale-95 flex justify-center items-center">
              <Loader2 v-if="modal.saving" class="animate-spin mr-2" size="24" />
              {{ modal.isEdit ? 'SALVAR ALTERAÇÕES' : 'ADICIONAR AO CARDÁPIO' }}
            </button>
          </form>
        </div>
      </div>
    </transition>

    <transition name="slide-fade">
      <div v-if="optionsModal.show" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" @click="optionsModal.show = false"></div>

        <div
          class="relative bg-white w-full max-w-5xl rounded-[3rem] p-10 shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
          <div class="flex justify-between items-start mb-8">
            <div>
              <h3 class="text-3xl font-black text-gray-900">Configurar Opcionais</h3>
              <p class="text-gray-500 font-bold mt-1">
                Item:
                <span class="text-red-600 font-black px-2 py-1 bg-red-50 rounded-lg ml-1">
                  {{ optionsModal.product?.name }}
                </span>
              </p>
            </div>

            <button @click="optionsModal.show = false"
              class="p-3 bg-gray-50 rounded-full hover:bg-red-600 hover:text-white transition-all">
              <X size="24" />
            </button>
          </div>

          <div class="grid md:grid-cols-2 gap-10 overflow-y-auto pr-4">
            <div class="space-y-6">
              <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest flex items-center gap-2">
                <LayoutGrid size="16" class="text-red-600" />
                Grupos de Opcionais Ativos
              </h4>

              <div v-if="!optionsModal.product?.option_groups?.length"
                class="p-16 border-2 border-dashed border-gray-100 rounded-[2.5rem] text-center bg-gray-50/50">
                <ListTree class="mx-auto text-gray-200 mb-4" size="40" />
                <p class="text-gray-400 font-black uppercase text-[10px] tracking-widest">
                  Nenhum opcional cadastrado
                </p>
              </div>

              <div v-for="group in optionsModal.product?.option_groups" :key="group.id"
                class="p-6 bg-white rounded-3xl border border-gray-100 shadow-sm group relative hover:border-red-100 transition-all">
                <div class="flex justify-between items-center mb-4">
                  <div class="flex flex-col">
                    <span class="font-black text-gray-800 text-lg leading-tight uppercase tracking-tight">
                      {{ group.name }}
                    </span>
                    <span class="text-[10px] font-bold text-gray-400 uppercase">
                      Min: {{ group.min_selected }} • Max: {{ group.max_selected }}
                    </span>
                  </div>

                  <div class="flex gap-2">
                    <button @click="editOptionGroup(group)"
                      class="p-2 bg-gray-50 text-gray-500 hover:bg-gray-900 hover:text-white rounded-xl transition-all shadow-sm"
                      title="Editar Grupo">
                      <Pencil size="16" />
                    </button>

                    <button @click="confirmDelete(group.id, 'group')"
                      class="p-2 bg-red-50 text-red-600 hover:bg-red-600 hover:text-white rounded-xl transition-all shadow-sm"
                      title="Excluir Grupo">
                      <Trash2 size="16" />
                    </button>
                  </div>
                </div>

                <div class="flex flex-wrap gap-2">
                  <div v-for="item in group.items" :key="item.id"
                    class="text-xs bg-gray-50 border border-gray-100 px-3 py-1.5 rounded-xl font-black text-gray-600 flex items-center gap-2">
                    <img v-if="getItemImageUrl(item)" :src="getItemImageUrl(item)"
                      class="w-5 h-5 object-cover rounded-md border border-gray-200" />
                    {{ item.name }}
                    <span class="text-red-600 text-[10px]">+ R${{ item.price }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="space-y-6 bg-gray-50 p-8 rounded-[2.5rem] border border-gray-100 shadow-inner">
              <div class="flex justify-between items-center">
                <h4 class="text-xs font-black text-gray-900 uppercase tracking-widest flex items-center gap-2">
                  <Plus v-if="!optionsModal.isEdit" size="16" class="text-red-600" />
                  <Pencil v-else size="16" class="text-orange-500" />
                  {{ optionsModal.isEdit ? 'Editar Grupo Selecionado' : 'Criar Novo Grupo' }}
                </h4>

                <button v-if="optionsModal.isEdit" @click="resetOptionsForm"
                  class="text-[10px] font-black uppercase text-red-600 bg-red-50 px-2 py-1 rounded-md hover:bg-red-600 hover:text-white transition-all">
                  Cancelar Edição
                </button>
              </div>

              <div class="space-y-5">
                <div class="space-y-1">
                  <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">
                    Nome do Grupo
                  </label>
                  <input v-model="optionsModal.form.name" type="text"
                    class="w-full px-5 py-4 bg-white rounded-2xl border-2 border-transparent focus:border-red-600 outline-none font-bold shadow-sm transition-all">
                </div>

                <div class="grid grid-cols-2 gap-4">
                  <div class="space-y-1">
                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">
                      Mínimo
                    </label>
                    <input v-model="optionsModal.form.min_selected" type="number"
                      class="w-full px-5 py-4 bg-white rounded-2xl font-black outline-none border-2 border-transparent focus:border-red-600 shadow-sm transition-all">
                  </div>

                  <div class="space-y-1">
                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">
                      Máximo
                    </label>
                    <input v-model="optionsModal.form.max_selected" type="number"
                      class="w-full px-5 py-4 bg-white rounded-2xl font-black outline-none border-2 border-transparent focus:border-red-600 shadow-sm transition-all">
                  </div>
                </div>

                <div class="space-y-3">
                  <div class="flex justify-between items-center px-1">
                    <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">
                      Opções de Seleção
                    </span>

                    <button @click="optionsModal.form.items.push(createEmptyOptionItem())"
                      class="text-[10px] font-black bg-gray-900 text-white px-3 py-1 rounded-lg shadow-sm hover:bg-red-600 transition-all">
                      + Adicionar Opção
                    </button>
                  </div>

                  <div class="space-y-2">
                    <div v-for="(item, idx) in optionsModal.form.items" :key="idx"
                      class="flex gap-2 items-center animate-in slide-in-from-right duration-300">
                      <input v-model="item.name" type="text"
                        class="flex-grow px-4 py-3 bg-white rounded-xl text-sm font-bold outline-none shadow-sm"
                        placeholder="Nome">

                      <label :title="item.local_preview ? 'Foto selecionada' : 'Foto do opcional'" :class="[
                        'cursor-pointer relative overflow-hidden flex items-center justify-center w-12 h-12 rounded-xl border-2 transition-all shadow-sm group flex-shrink-0',
                        item.local_preview
                          ? 'border-red-600 bg-red-50 text-red-600 ring-2 ring-red-100'
                          : 'border-gray-100 bg-white hover:bg-red-50 hover:border-red-200'
                      ]">
                        <img v-if="item.local_preview" :src="item.local_preview"
                          class="absolute inset-0 w-full h-full object-cover" />

                        <div v-if="item.local_preview"
                          class="absolute inset-0 bg-red-950/0 group-hover:bg-red-950/10 transition-colors"></div>

                        <ImageIcon v-if="!item.local_preview" size="18"
                          class="text-gray-300 group-hover:text-red-600" />

                        <span v-if="item.local_preview" :class="[
                          'absolute bottom-0 right-0 left-0 text-[8px] font-bold text-center text-white py-0.5 leading-none uppercase',
                          item.is_new_file ? 'bg-emerald-500' : 'bg-red-600'
                        ]">
                          {{ item.is_new_file ? 'Novo' : 'Salva' }}
                        </span>

                        <input type="file" class="hidden" @change="(e) => handleItemImageChange(e, item)"
                          accept="image/*">
                      </label>

                      <input v-model="item.price" type="number" step="0.01"
                        class="w-24 px-3 py-3 bg-white rounded-xl text-sm font-black outline-none shadow-sm text-center text-red-600"
                        placeholder="0.00">

                      <button @click="optionsModal.form.items.splice(idx, 1)"
                        class="p-2 text-gray-300 hover:text-red-600 transition-colors">
                        <X size="18" />
                      </button>
                    </div>
                  </div>
                </div>

                <button @click="handleSaveOptions" :disabled="optionsModal.saving"
                  class="w-full bg-red-600 text-white py-5 rounded-2xl font-black hover:bg-black transition-all flex justify-center items-center shadow-xl active:scale-95 disabled:opacity-50 mt-4 uppercase tracking-widest text-sm">
                  <Loader2 v-if="optionsModal.saving" class="animate-spin mr-2" size="20" />
                  {{ optionsModal.saving ? 'PROCESSANDO...' : optionsModal.isEdit ? 'ATUALIZAR GRUPO' : 'SALVAR NOVO GRUPO' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </transition>

    <transition name="slide-fade">
      <div v-if="deleteModal.show" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-950/40 backdrop-blur-md" @click="deleteModal.show = false"></div>

        <div
          class="relative bg-white w-full max-w-md rounded-[3rem] p-10 shadow-2xl text-center border border-gray-100">
          <div
            class="w-20 h-20 bg-red-50 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-6 rotate-3 hover:rotate-0 transition-transform">
            <Trash2 size="40" />
          </div>

          <h3 class="text-2xl font-black text-gray-900 leading-tight">
            Remover {{ deleteModal.type === 'product' ? 'Produto' : 'Grupo' }}?
          </h3>

          <p class="text-gray-500 font-bold text-sm mt-3 leading-relaxed">
            <template v-if="deleteModal.type === 'product'">
              Se esse produto já tiver pedidos vinculados, ele será marcado como esgotado para preservar o histórico.
            </template>
            <template v-else>
              Essa ação não pode ser desfeita. Todos os dados vinculados serão perdidos permanentemente.
            </template>
          </p>

          <div class="flex flex-col gap-2 mt-8">
            <button @click="handleDelete" :disabled="deleteModal.loading"
              class="w-full py-5 bg-red-600 hover:bg-black text-white rounded-2xl font-black text-center transition-all flex justify-center items-center shadow-lg active:scale-95">
              <Loader2 v-if="deleteModal.loading" class="animate-spin mr-2" size="20" />
              {{ deleteModal.loading ? 'PROCESSANDO...' : 'SIM, REMOVER AGORA' }}
            </button>

            <button @click="deleteModal.show = false"
              class="w-full py-4 bg-transparent hover:bg-gray-50 rounded-2xl font-black text-gray-400 transition-all uppercase text-xs tracking-widest">
              Cancelar
            </button>
          </div>
        </div>
      </div>
    </transition>
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

.slide-up-enter-active {
  transition: all 0.3s ease-out;
}

.slide-up-enter-from {
  opacity: 0;
  transform: translateY(-10px);
}

.slide-fade-enter-active {
  transition: all 0.3s ease-out;
}

.slide-fade-enter-from {
  opacity: 0;
  transform: translateY(10px);
}
</style>