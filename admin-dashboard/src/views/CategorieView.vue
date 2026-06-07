<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import draggable from 'vuedraggable'
import api from '@/services/api'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
  Plus,
  Pencil,
  Trash2,
  X,
  Loader2,
  CheckCircle,
  XCircle,
  FolderTree,
  GripVertical,
  Save,
  AlertTriangle
} from 'lucide-vue-next'

const categories = ref([])
const loading = ref(true)
const errors = ref(null)
const orderChanged = ref(false)
const savingOrder = ref(false)

const toast = ref({ show: false, message: '', type: 'success' })
const showNotify = (msg, type = 'success') => {
  toast.value = { show: true, message: msg, type }
  setTimeout(() => toast.value.show = false, 4000)
}

const modal = reactive({
  show: false,
  isEdit: false,
  saving: false,
  currentId: null
})

const deleteModal = reactive({
  show: false,
  id: null,
  loading: false
})

const form = reactive({
  name: '',
  position: 0
})

const sortedCategories = computed(() => {
  return [...categories.value].sort((a, b) => {
    const posA = Number(a.position ?? 0)
    const posB = Number(b.position ?? 0)

    if (posA === posB) return Number(a.id) - Number(b.id)
    return posA - posB
  })
})

const normalizeCategories = (items) => {
  return [...items]
    .sort((a, b) => {
      const posA = Number(a.position ?? 0)
      const posB = Number(b.position ?? 0)

      if (posA === posB) return Number(a.id) - Number(b.id)
      return posA - posB
    })
    .map((category, index) => ({
      ...category,
      position: Number(category.position ?? index)
    }))
}

const fetchCategories = async () => {
  try {
    loading.value = true
    const { data } = await api.get('/merchant/categories')
    categories.value = normalizeCategories(data.data || data || [])
    orderChanged.value = false
  } catch (err) {
    showNotify('Erro ao carregar categorias.', 'error')
  } finally {
    loading.value = false
  }
}

const openModal = (category = null) => {
  errors.value = null

  if (category) {
    modal.isEdit = true
    modal.currentId = category.id
    form.name = category.name
    form.position = category.position ?? 0
  } else {
    modal.isEdit = false
    modal.currentId = null
    form.name = ''
    form.position = categories.value.length
  }

  modal.show = true
}

const closeModal = () => {
  modal.show = false
  errors.value = null
}

const handleSubmit = async () => {
  modal.saving = true
  errors.value = null

  try {
    const payload = {
      name: form.name,
      position: Number(form.position ?? categories.value.length)
    }

    if (modal.isEdit) {
      const { data } = await api.put(`/merchant/categories/${modal.currentId}`, payload)
      const saved = data.data || data

      categories.value = categories.value.map(category =>
        category.id === saved.id ? saved : category
      )

      showNotify('Categoria atualizada!')
    } else {
      const { data } = await api.post('/merchant/categories', payload)
      const saved = data.data || data

      categories.value.push(saved)
      categories.value = normalizeCategories(categories.value)

      showNotify('Categoria criada!')
    }

    await fetchCategories()
    closeModal()
  } catch (err) {
    if (err.response?.status === 422) {
      errors.value = err.response.data.errors
    }

    const msg = err.response?.data?.details || err.response?.data?.message || 'Erro ao salvar categoria.'
    showNotify(msg, 'error')
  } finally {
    modal.saving = false
  }
}

const confirmDelete = (id) => {
  deleteModal.id = id
  deleteModal.show = true
}

const handleDelete = async () => {
  deleteModal.loading = true

  try {
    await api.delete(`/merchant/categories/${deleteModal.id}`)

    categories.value = categories.value.filter(category => category.id !== deleteModal.id)
    categories.value = categories.value.map((category, index) => ({
      ...category,
      position: index
    }))

    deleteModal.show = false
    showNotify('Categoria removida.')

    if (categories.value.length > 0) {
      orderChanged.value = true
    }
  } catch (err) {
    const msg = err.response?.data?.details || err.response?.data?.message || 'Erro ao remover categoria.'
    showNotify(msg, 'error')
  } finally {
    deleteModal.loading = false
  }
}

const handleDragChange = () => {
  categories.value = categories.value.map((category, index) => ({
    ...category,
    position: index
  }))

  orderChanged.value = true
}

const saveOrder = async () => {
  savingOrder.value = true

  try {
    const payload = {
      categories: categories.value.map((category, index) => ({
        id: category.id,
        position: index
      }))
    }

    await api.put('/merchant/categories/reorder', payload)

    categories.value = categories.value.map((category, index) => ({
      ...category,
      position: index
    }))

    orderChanged.value = false
    showNotify('Ordem do cardápio atualizada!')
  } catch (err) {
    const msg = err.response?.data?.details || err.response?.data?.message || 'Erro ao atualizar ordem.'
    showNotify(msg, 'error')
  } finally {
    savingOrder.value = false
  }
}

onMounted(fetchCategories)
</script>

<template>
  <DashboardLayout>
    <div class="space-y-8 animate-in fade-in duration-500 pb-10">
      <header
        class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-red-100 shadow-sm"
      >
        <div class="flex items-center gap-4">
          <div
            class="w-12 h-12 bg-red-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-red-100"
          >
            <FolderTree size="28" />
          </div>
          <div>
            <h1 class="text-2xl font-black text-gray-900">Gerenciar Categorias</h1>
            <p class="text-gray-500 text-sm">
              Crie, edite e organize a ordem das categorias exibidas no cardápio.
            </p>
          </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-2">
          <button
            v-if="orderChanged"
            @click="saveOrder"
            :disabled="savingOrder"
            class="bg-gray-900 hover:bg-black text-white px-5 py-4 rounded-2xl font-bold flex items-center justify-center gap-2 transition-all active:scale-95 disabled:opacity-50"
          >
            <Loader2 v-if="savingOrder" class="animate-spin" size="20" />
            <Save v-else size="20" />
            Salvar Ordem
          </button>

          <button
            @click="openModal()"
            class="bg-red-600 hover:bg-red-700 text-white px-6 py-4 rounded-2xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-red-100 active:scale-95"
          >
            <Plus size="20" />
            Nova Categoria
          </button>
        </div>
      </header>

      <div
        v-if="orderChanged"
        class="bg-amber-50 border border-amber-100 text-amber-700 rounded-2xl px-5 py-4 flex items-center gap-3"
      >
        <AlertTriangle size="20" class="flex-shrink-0" />
        <p class="text-sm font-bold">
          A ordem foi alterada. Clique em “Salvar Ordem” para aplicar no cardápio do cliente.
        </p>
      </div>

      <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
        <div v-if="loading" class="p-20 flex justify-center text-red-600">
          <Loader2 class="animate-spin" size="32" />
        </div>

        <div v-else-if="categories.length === 0" class="p-20 text-center">
          <FolderTree class="mx-auto text-gray-200 mb-4" size="48" />
          <p class="text-gray-400 font-medium">Nenhuma categoria cadastrada.</p>
          <button
            @click="openModal()"
            class="mt-6 bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-2xl font-black text-sm transition-all"
          >
            Criar primeira categoria
          </button>
        </div>

        <div v-else>
          <div class="px-6 py-4 bg-gray-50 border-b border-gray-100">
            <div class="grid grid-cols-[56px_1fr_120px_120px] gap-4 items-center">
              <span class="text-xs font-black text-gray-400 uppercase tracking-widest">Ordem</span>
              <span class="text-xs font-black text-gray-400 uppercase tracking-widest">Categoria</span>
              <span class="text-xs font-black text-gray-400 uppercase tracking-widest text-center">Posição</span>
              <span class="text-xs font-black text-gray-400 uppercase tracking-widest text-right">Ações</span>
            </div>
          </div>

          <draggable
            v-model="categories"
            item-key="id"
            handle=".drag-handle"
            ghost-class="drag-ghost"
            chosen-class="drag-chosen"
            animation="180"
            @change="handleDragChange"
          >
            <template #item="{ element, index }">
              <div
                class="grid grid-cols-[56px_1fr_120px_120px] gap-4 items-center px-6 py-4 border-b border-gray-50 bg-white hover:bg-red-50/30 transition-colors group"
              >
                <div class="flex items-center">
                  <button
                    class="drag-handle w-10 h-10 rounded-xl bg-gray-50 text-gray-300 hover:text-red-600 hover:bg-red-50 flex items-center justify-center cursor-grab active:cursor-grabbing transition-all"
                    title="Arrastar para ordenar"
                  >
                    <GripVertical size="20" />
                  </button>
                </div>

                <div class="min-w-0">
                  <span class="block font-black text-gray-800 text-base truncate">
                    {{ element.name }}
                  </span>
                  <span class="text-[10px] text-gray-400 font-black uppercase tracking-widest">
                    Slug: {{ element.slug || 'sem-slug' }}
                  </span>
                </div>

                <div class="text-center">
                  <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 text-gray-600 text-xs font-black">
                    {{ index + 1 }}
                  </span>
                </div>

                <div class="flex justify-end gap-2">
                  <button
                    @click="openModal(element)"
                    class="p-2.5 bg-gray-100 text-gray-500 hover:bg-gray-900 hover:text-white rounded-xl transition-all"
                    title="Editar"
                  >
                    <Pencil size="18" />
                  </button>

                  <button
                    @click="confirmDelete(element.id)"
                    class="p-2.5 bg-red-50 text-red-600 hover:bg-red-600 hover:text-white rounded-xl transition-all"
                    title="Remover"
                  >
                    <Trash2 size="18" />
                  </button>
                </div>
              </div>
            </template>
          </draggable>
        </div>
      </div>
    </div>

    <transition name="slide-fade">
      <div v-if="modal.show" class="fixed inset-0 z-[60] flex justify-end">
        <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" @click="closeModal"></div>

        <div
          class="relative w-full max-w-lg bg-white h-screen shadow-2xl flex flex-col p-8 animate-slide-in overflow-y-auto"
        >
          <div class="flex justify-between items-center mb-8">
            <h2 class="text-2xl font-black text-gray-900">
              {{ modal.isEdit ? 'Editar Categoria' : 'Nova Categoria' }}
            </h2>

            <button
              @click="closeModal"
              class="p-2 bg-gray-50 rounded-full hover:bg-red-600 hover:text-white transition-all"
            >
              <X size="20" />
            </button>
          </div>

          <form @submit.prevent="handleSubmit" class="space-y-6">
            <div class="space-y-1">
              <label class="text-xs font-black text-gray-400 uppercase">
                Nome da Categoria
              </label>
              <input
                v-model="form.name"
                type="text"
                class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-bold transition-all"
                placeholder="Ex: Hambúrgueres"
              >
              <p
                v-if="errors?.name"
                class="text-[10px] text-red-600 font-bold uppercase tracking-widest mt-1"
              >
                {{ errors.name[0] }}
              </p>
            </div>

            <div class="space-y-1">
              <label class="text-xs font-black text-gray-400 uppercase">
                Posição
              </label>
              <input
                v-model="form.position"
                type="number"
                min="0"
                class="w-full px-5 py-4 bg-gray-50 border-2 border-transparent focus:border-red-600 focus:bg-white rounded-2xl outline-none font-black transition-all"
              >
              <p class="text-[11px] text-gray-400 font-bold mt-1">
                Você também pode alterar a posição arrastando a categoria na lista.
              </p>
            </div>

            <button
              type="submit"
              :disabled="modal.saving"
              class="w-full bg-red-600 text-white py-5 rounded-[2rem] font-black text-lg hover:bg-red-700 transition-all shadow-xl shadow-red-100 active:scale-95 flex justify-center items-center disabled:opacity-50"
            >
              <Loader2 v-if="modal.saving" class="animate-spin mr-2" size="24" />
              {{ modal.isEdit ? 'SALVAR ALTERAÇÕES' : 'CRIAR CATEGORIA' }}
            </button>
          </form>
        </div>
      </div>
    </transition>

    <transition name="toast">
      <div
        v-if="toast.show"
        class="fixed bottom-10 right-10 z-[100] flex items-center p-6 rounded-[2rem] shadow-2xl bg-gray-900 text-white border border-white/10"
      >
        <div
          :class="[
            'w-10 h-10 rounded-full flex items-center justify-center mr-4 shadow-inner',
            toast.type === 'success' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'
          ]"
        >
          <CheckCircle v-if="toast.type === 'success'" size="24" />
          <XCircle v-else size="24" />
        </div>
        <span class="text-sm font-black tracking-tight">{{ toast.message }}</span>
      </div>
    </transition>

    <transition name="slide-fade">
      <div v-if="deleteModal.show" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-gray-950/40 backdrop-blur-md" @click="deleteModal.show = false"></div>

        <div
          class="relative bg-white w-full max-w-md rounded-[3rem] p-10 shadow-2xl text-center border border-gray-100"
        >
          <div
            class="w-20 h-20 bg-red-50 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-6 rotate-3 hover:rotate-0 transition-transform"
          >
            <Trash2 size="40" />
          </div>

          <h3 class="text-2xl font-black text-gray-900 leading-tight">
            Remover Categoria?
          </h3>

          <p class="text-gray-500 font-bold text-sm mt-3 leading-relaxed">
            Essa ação não pode ser desfeita. Produtos vinculados podem ficar sem categoria.
          </p>

          <div class="flex flex-col gap-2 mt-8">
            <button
              @click="handleDelete"
              :disabled="deleteModal.loading"
              class="w-full py-5 bg-red-600 hover:bg-black text-white rounded-2xl font-black text-center transition-all flex justify-center items-center shadow-lg active:scale-95 disabled:opacity-50"
            >
              <Loader2 v-if="deleteModal.loading" class="animate-spin mr-2" size="20" />
              {{ deleteModal.loading ? 'EXCLUINDO...' : 'SIM, EXCLUIR AGORA' }}
            </button>

            <button
              @click="deleteModal.show = false"
              class="w-full py-4 bg-transparent hover:bg-gray-50 rounded-2xl font-black text-gray-400 transition-all uppercase text-xs tracking-widest"
            >
              Cancelar
            </button>
          </div>
        </div>
      </div>
    </transition>
  </DashboardLayout>
</template>

<style scoped>
@keyframes slide-in {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}

.animate-slide-in {
  animation: slide-in 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-fade-enter-active {
  transition: all 0.3s ease-out;
}

.slide-fade-enter-from {
  opacity: 0;
  transform: translateY(10px);
}

.toast-enter-active,
.toast-leave-active {
  transition: all 0.25s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(10px);
}

.drag-ghost {
  opacity: 0.45;
  background: #fef2f2;
}

.drag-chosen {
  background: #fff7f7;
}
</style>