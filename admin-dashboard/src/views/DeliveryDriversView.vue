<script setup>
import { computed, reactive, ref } from 'vue'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import {
  Bike,
  Loader2,
  Pencil,
  Phone,
  Plus,
  Save,
  ToggleLeft,
  ToggleRight,
  Trash2,
  UserRound
} from 'lucide-vue-next'
import api from '@/services/api'

const drivers = ref([])
const loading = ref(true)
const saving = ref(false)
const editingId = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })

const form = reactive({
  name: '',
  phone: '',
  is_active: true
})

const activeDrivers = computed(() => drivers.value.filter((driver) => driver.is_active))

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3500)
}

const resetForm = () => {
  form.name = ''
  form.phone = ''
  form.is_active = true
  editingId.value = null
}

const fetchDrivers = async () => {
  loading.value = true

  try {
    const { data } = await api.get('/merchant/delivery-drivers')
    drivers.value = data.data || data || []
  } catch {
    showNotify('Erro ao carregar entregadores.', 'error')
  } finally {
    loading.value = false
  }
}

const editDriver = (driver) => {
  editingId.value = driver.id
  form.name = driver.name || ''
  form.phone = driver.phone || ''
  form.is_active = Boolean(driver.is_active)
}

const saveDriver = async () => {
  if (!form.name.trim()) {
    showNotify('Informe o nome do entregador.', 'error')
    return
  }

  saving.value = true

  try {
    const payload = {
      name: form.name.trim(),
      phone: form.phone.trim() || null,
      is_active: form.is_active
    }

    if (editingId.value) {
      await api.put(`/merchant/delivery-drivers/${editingId.value}`, payload)
      showNotify('Entregador atualizado.')
    } else {
      await api.post('/merchant/delivery-drivers', payload)
      showNotify('Entregador criado.')
    }

    resetForm()
    await fetchDrivers()
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao salvar entregador.', 'error')
  } finally {
    saving.value = false
  }
}

const toggleDriver = async (driver) => {
  try {
    await api.patch(`/merchant/delivery-drivers/${driver.id}/toggle`)
    await fetchDrivers()
  } catch {
    showNotify('Erro ao alterar status do entregador.', 'error')
  }
}

const removeDriver = async (driver) => {
  if (!window.confirm(`Remover ${driver.name}?`)) return

  try {
    await api.delete(`/merchant/delivery-drivers/${driver.id}`)
    if (editingId.value === driver.id) {
      resetForm()
    }
    await fetchDrivers()
    showNotify('Entregador removido.')
  } catch {
    showNotify('Erro ao remover entregador.', 'error')
  }
}

fetchDrivers()
</script>

<template>
  <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

  <div class="pm-page">
    <PageHeader
      title="Entregadores"
      subtitle="Cadastre quem faz as entregas da loja. Sem login — só para controle interno nos pedidos."
    >
      <template #icon>
        <Bike size="26" />
      </template>
    </PageHeader>

    <section class="grid grid-cols-1 xl:grid-cols-[360px_1fr] gap-8">
      <form class="pm-card p-6 space-y-4" @submit.prevent="saveDriver">
        <h2 class="text-lg font-black text-slate-900">
          {{ editingId ? 'Editar entregador' : 'Novo entregador' }}
        </h2>

        <label class="block">
          <span class="pm-label">Nome</span>
          <input v-model="form.name" type="text" required class="pm-input-sm mt-2" placeholder="Ex: João Motoboy" />
        </label>

        <label class="block">
          <span class="pm-label">WhatsApp (opcional)</span>
          <input v-model="form.phone" type="text" class="pm-input-sm mt-2" placeholder="(85) 99999-9999" />
        </label>

        <label class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3 text-sm font-black text-slate-700">
          Ativo
          <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
        </label>

        <div class="grid gap-2">
          <button :disabled="saving" type="submit" class="pm-btn-solid w-full py-4">
            <Loader2 v-if="saving" class="animate-spin" size="16" />
            <Save v-else size="16" />
            {{ editingId ? 'Salvar entregador' : 'Adicionar entregador' }}
          </button>

          <button
            v-if="editingId"
            type="button"
            class="rounded-2xl px-4 py-3 text-xs font-black text-slate-400 hover:bg-slate-50"
            @click="resetForm"
          >
            Cancelar edição
          </button>
        </div>
      </form>

      <div class="pm-card">
        <div v-if="loading" class="pm-loading">
          <Loader2 class="animate-spin" size="32" />
        </div>

        <div v-else-if="drivers.length === 0" class="p-16 text-center">
          <Bike class="mx-auto mb-4 text-slate-200" size="48" />
          <p class="text-sm font-bold text-slate-400">Nenhum entregador cadastrado.</p>
        </div>

        <div v-else class="divide-y divide-slate-100">
          <article
            v-for="driver in drivers"
            :key="driver.id"
            class="grid gap-4 p-5 md:grid-cols-[1fr_auto] md:items-center"
          >
            <div>
              <div class="flex flex-wrap items-center gap-2">
                <UserRound size="16" class="text-red-500" />
                <h3 class="font-black text-slate-900">{{ driver.name }}</h3>
                <span
                  :class="driver.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400'"
                  class="rounded-full px-3 py-1 text-[10px] font-black uppercase"
                >
                  {{ driver.is_active ? 'Ativo' : 'Pausado' }}
                </span>
              </div>

              <p v-if="driver.phone" class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-slate-500">
                <Phone size="14" />
                {{ driver.phone }}
              </p>
            </div>

            <div class="flex gap-2">
              <button
                class="p-2.5 rounded-xl transition-all"
                :class="driver.is_active
                  ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white'
                  : 'bg-slate-100 text-slate-500 hover:bg-slate-900 hover:text-white'"
                @click="toggleDriver(driver)"
              >
                <ToggleRight v-if="driver.is_active" size="18" />
                <ToggleLeft v-else size="18" />
              </button>

              <button class="pm-btn-icon-edit" @click="editDriver(driver)">
                <Pencil size="18" />
              </button>

              <button class="pm-btn-icon-delete" @click="removeDriver(driver)">
                <Trash2 size="18" />
              </button>
            </div>
          </article>
        </div>
      </div>
    </section>

    <div v-if="activeDrivers.length > 0" class="pm-card p-5 mt-6">
      <p class="text-sm font-bold text-slate-600">
        {{ activeDrivers.length }} entregador{{ activeDrivers.length === 1 ? '' : 'es' }} ativo{{ activeDrivers.length === 1 ? '' : 's' }}.
        Ao marcar um pedido como “Saiu para entrega”, você pode escolher quem está levando.
      </p>
    </div>
  </div>
</template>
