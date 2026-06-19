<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { Loader2, Save, Construction } from 'lucide-vue-next'

const emit = defineEmits(['notify'])

const loading = ref(true)
const saving = ref(false)
const modules = ref([])
const bypassStoreIds = ref([])
const stores = ref([])

const form = reactive({
  modules: {},
  bypass_store_ids: []
})

const bypassedStores = computed(() => {
  return stores.value.filter((store) => form.bypass_store_ids.includes(store.id))
})

const availableBypassStores = computed(() => {
  return stores.value.filter((store) => !form.bypass_store_ids.includes(store.id))
})

const hydrateForm = (payload) => {
  modules.value = payload.modules || []
  bypassStoreIds.value = payload.bypass_store_ids || []
  form.bypass_store_ids = [...bypassStoreIds.value]

  modules.value.forEach((module) => {
    form.modules[module.key] = {
      maintenance: Boolean(module.maintenance),
      message: module.message || ''
    }
  })
}

const fetchData = async () => {
  loading.value = true

  try {
    const [{ data: maintenanceResponse }, { data: storesResponse }] = await Promise.all([
      api.get('/super-admin/module-maintenance'),
      api.get('/super-admin/stores', { params: { per_page: 200 } })
    ])

    stores.value = storesResponse.data || []
    hydrateForm(maintenanceResponse)
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao carregar manutenção de módulos.', 'error')
  } finally {
    loading.value = false
  }
}

const addBypassStore = (storeId) => {
  const id = Number(storeId)
  if (!id || form.bypass_store_ids.includes(id)) return
  form.bypass_store_ids.push(id)
}

const removeBypassStore = (storeId) => {
  form.bypass_store_ids = form.bypass_store_ids.filter((id) => id !== storeId)
}

const save = async () => {
  saving.value = true

  try {
    const payload = {
      modules: modules.value.map((module) => ({
        key: module.key,
        maintenance: Boolean(form.modules[module.key]?.maintenance),
        message: form.modules[module.key]?.message || ''
      })),
      bypass_store_ids: form.bypass_store_ids
    }

    const { data } = await api.put('/super-admin/module-maintenance', payload)
    hydrateForm(data)
    emit('notify', data.message || 'Manutenção de módulos salva.')
  } catch (error) {
    emit('notify', error.response?.data?.message || 'Erro ao salvar manutenção de módulos.', 'error')
  } finally {
    saving.value = false
  }
}

onMounted(fetchData)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex items-start gap-4">
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
          <Construction size="22" />
        </div>
        <div>
          <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-600">Operação</p>
          <h2 class="mt-1 text-2xl font-black text-slate-950">Manutenção de módulos</h2>
          <p class="mt-1 text-sm font-semibold text-slate-500">
            Bloqueie módulos do painel para todas as lojas e defina lojas de teste que continuam com acesso.
          </p>
        </div>
      </div>
    </div>

    <div v-if="loading" class="rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm">
      <Loader2 class="mx-auto animate-spin text-slate-400" :size="28" />
      <p class="mt-3 text-sm font-bold text-slate-400">Carregando módulos...</p>
    </div>

    <form
      v-else
      class="space-y-5"
      @submit.prevent="save"
    >
      <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
        <div>
          <h3 class="text-sm font-black uppercase tracking-widest text-slate-400">Lojas com bypass</h3>
          <p class="mt-1 text-xs font-semibold text-slate-500">
            Essas lojas ignoram a manutenção e continuam usando os módulos normalmente.
          </p>
        </div>

        <div v-if="bypassedStores.length" class="flex flex-wrap gap-2">
          <button
            v-for="store in bypassedStores"
            :key="store.id"
            type="button"
            class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700"
            @click="removeBypassStore(store.id)"
          >
            {{ store.name }}
            <span class="text-emerald-500">×</span>
          </button>
        </div>

        <label class="block space-y-1">
          <span class="text-[10px] font-black uppercase text-slate-400">Adicionar loja de teste</span>
          <select
            class="w-full max-w-md rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-red-500 focus:ring-red-500"
            @change="addBypassStore($event.target.value); $event.target.value = ''"
          >
            <option value="">Selecione uma loja</option>
            <option
              v-for="store in availableBypassStores"
              :key="store.id"
              :value="store.id"
            >
              {{ store.name }} (/{{ store.slug }})
            </option>
          </select>
        </label>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <article
          v-for="module in modules"
          :key="module.key"
          class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="text-base font-black text-slate-900">{{ module.label }}</h3>
              <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">{{ module.key }}</p>
            </div>

            <label class="inline-flex items-center gap-2 text-xs font-black text-slate-600">
              <input
                v-model="form.modules[module.key].maintenance"
                type="checkbox"
                class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
              />
              Em manutenção
            </label>
          </div>

          <label
            v-if="form.modules[module.key]?.maintenance"
            class="mt-4 block space-y-1"
          >
            <span class="text-[10px] font-black uppercase text-slate-400">Mensagem para o lojista</span>
            <textarea
              v-model="form.modules[module.key].message"
              rows="3"
              maxlength="500"
              placeholder="Este módulo está em manutenção. Tente novamente em breve."
              class="w-full rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold focus:border-amber-500 focus:ring-amber-500"
            />
          </label>
        </article>
      </div>

      <button
        type="submit"
        :disabled="saving"
        class="flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white transition hover:bg-red-700 disabled:opacity-60"
      >
        <Loader2 v-if="saving" class="animate-spin" :size="16" />
        <Save v-else :size="16" />
        Salvar manutenção
      </button>
    </form>
  </section>
</template>
