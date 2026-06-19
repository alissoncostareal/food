<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppToast from '@/components/ui/AppToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import DeliveryAreasMap from '@/components/DeliveryAreasMap.vue'
import DistrictAutocomplete from '@/components/DistrictAutocomplete.vue'
import FeatureAccessLoading from '@/components/auth/FeatureAccessLoading.vue'
import { useFeatureAccess } from '@/composables/useFeatureAccess'
import api from '@/services/api'
import {
  ArrowUpRight,
  Bike,
  CheckCircle,
  Clock,
  Loader2,
  Lock,
  MapPin,
  Pencil,
  Plus,
  Save,
  ToggleLeft,
  ToggleRight,
  Trash2,
  XCircle
} from 'lucide-vue-next'

const router = useRouter()
const areas = ref([])
const loading = ref(false)
const saving = ref(false)
const searchContext = ref({
  city: '',
  lat: null,
  lng: null
})
const apiLocked = ref(false)
const { isLoading: featureLoading, isLocked: planLocked, isUnlocked } = useFeatureAccess('delivery_areas')
const isLocked = computed(() => planLocked.value || apiLocked.value)
const editingId = ref(null)
const toast = ref({ show: false, message: '', type: 'success' })

const form = reactive({
  district_name: '',
  city: '',
  fee: 0,
  estimated_time: 40,
  is_active: true,
  latitude: null,
  longitude: null
})

const showNotify = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => {
    toast.value.show = false
  }, 3500)
}

const money = (value) => Number(value || 0).toLocaleString('pt-BR', {
  style: 'currency',
  currency: 'BRL'
})

const areaLabel = (area) => {
  if (!area?.district_name) return ''

  return area.city
    ? `${area.district_name}, ${area.city}`
    : area.district_name
}

const resetForm = () => {
  form.district_name = ''
  form.city = ''
  form.fee = 0
  form.estimated_time = 40
  form.is_active = true
  form.latitude = null
  form.longitude = null
  editingId.value = null
}

const fetchSearchContext = async () => {
  try {
    const { data } = await api.get('/merchant/delivery-areas/map-preview')
    const cityFromAreas = areas.value.find((area) => area.city)?.city
      || data.areas?.find((area) => area.city)?.city
      || ''

    searchContext.value = {
      city: cityFromAreas,
      lat: data.store?.latitude ?? null,
      lng: data.store?.longitude ?? null
    }
  } catch {
    searchContext.value = {
      city: areas.value.find((area) => area.city)?.city || '',
      lat: null,
      lng: null
    }
  }
}

const fetchAreas = async () => {
  loading.value = true

  try {
    apiLocked.value = false
    const { data } = await api.get('/merchant/delivery-areas')
    areas.value = data.data || data || []
    await fetchSearchContext()
  } catch (error) {
    if (error.response?.status === 403) {
      apiLocked.value = true
      areas.value = []
      return
    }

    showNotify('Erro ao carregar áreas de entrega.', 'error')
  } finally {
    loading.value = false
  }
}

watch(isUnlocked, (unlocked) => {
  if (unlocked) {
    fetchAreas()
  }
}, { immediate: true })

const pageLoading = computed(() => featureLoading.value || (isUnlocked.value && loading.value && !isLocked.value))

const editArea = (area) => {
  editingId.value = area.id
  form.district_name = area.district_name
  form.city = area.city || ''
  form.fee = Number(area.fee || 0)
  form.estimated_time = Number(area.estimated_time || 40)
  form.is_active = Boolean(area.is_active)
  form.latitude = area.latitude ?? null
  form.longitude = area.longitude ?? null
}

const onDistrictSelect = (item) => {
  form.district_name = item.district_name
  form.city = item.city || ''
  form.latitude = item.latitude ?? null
  form.longitude = item.longitude ?? null
}

const onDistrictManualInput = () => {
  form.city = ''
  form.latitude = null
  form.longitude = null
}

const saveArea = async () => {
  saving.value = true

  try {
    const payload = {
      district_name: form.district_name.trim(),
      city: form.city.trim() || null,
      fee: Number(form.fee || 0),
      estimated_time: Number(form.estimated_time || 0),
      is_active: form.is_active,
      latitude: form.latitude,
      longitude: form.longitude
    }

    if (editingId.value) {
      await api.put(`/merchant/delivery-areas/${editingId.value}`, payload)
      showNotify('Área atualizada.')
    } else {
      await api.post('/merchant/delivery-areas', payload)
      showNotify('Área criada.')
    }

    resetForm()
    await fetchAreas()
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao salvar área.', 'error')
  } finally {
    saving.value = false
  }
}

const toggleArea = async (area) => {
  try {
    await api.patch(`/merchant/delivery-areas/${area.id}/toggle`)
    await fetchAreas()
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao alterar status.', 'error')
  }
}

const removeArea = async (area) => {
  try {
    await api.delete(`/merchant/delivery-areas/${area.id}`)
    await fetchAreas()
    showNotify('Área removida.')
  } catch (error) {
    showNotify(error.response?.data?.message || 'Erro ao remover área.', 'error')
  }
}

</script>

<template>
    <AppToast :show="toast.show" :message="toast.message" :type="toast.type" />

    <div class="pm-page">
      <PageHeader
        eyebrow="Pro e Premium"
        title="Áreas de entrega"
        subtitle="Defina bairros ou regiões atendidas, taxa específica e prazo estimado. Se houver áreas ativas, pedidos fora delas são bloqueados."
      >
        <template #icon>
          <MapPin size="26" />
        </template>
      </PageHeader>

      <FeatureAccessLoading v-if="pageLoading && !isLocked" />

      <section v-else-if="isLocked" class="grid grid-cols-1 xl:grid-cols-[1.2fr_0.8fr] gap-6">
        <div class="bg-slate-950 rounded-3xl border border-slate-800 p-8 text-white shadow-xl relative overflow-hidden">
          <div class="relative z-10 max-w-2xl">
            <div class="w-12 h-12 rounded-2xl bg-red-500 flex items-center justify-center mb-5">
              <Lock size="22" />
            </div>

            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-red-300">Recurso bloqueado</p>
            <h2 class="mt-2 text-3xl font-black leading-tight">Controle exatamente onde sua loja entrega</h2>
            <p class="mt-4 text-sm font-semibold leading-relaxed text-slate-300">
              Libere áreas de entrega para evitar pedidos em bairros fora da operação e cobrar taxas diferentes por região.
            </p>

            <button
              type="button"
              @click="router.push('/billing')"
              class="mt-7 inline-flex items-center gap-2 rounded-2xl bg-red-600 px-6 py-4 text-sm font-black text-white transition-all hover:bg-red-700 active:scale-95"
            >
              Ver planos
              <ArrowUpRight size="18" />
            </button>
          </div>

          <Bike class="absolute -right-10 -bottom-10 text-white/5" size="190" />
        </div>

        <div class="grid gap-4">
          <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
            <Clock class="text-red-600 mb-4" size="28" />
            <h3 class="text-sm font-black text-slate-900">Prazos por região</h3>
            <p class="mt-2 text-xs font-bold leading-relaxed text-slate-500">Informe prazos diferentes para bairros próximos ou distantes.</p>
          </div>
          <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
            <MapPin class="text-red-600 mb-4" size="28" />
            <h3 class="text-sm font-black text-slate-900">Bloqueio automático</h3>
            <p class="mt-2 text-xs font-bold leading-relaxed text-slate-500">Clientes fora das áreas ativas recebem aviso antes de finalizar.</p>
          </div>
        </div>
      </section>

      <section v-else class="grid grid-cols-1 xl:grid-cols-[380px_1fr] gap-8">
        <form @submit.prevent="saveArea" class="pm-card p-6 space-y-4">
          <h2 class="text-lg font-black text-slate-900">{{ editingId ? 'Editar área' : 'Nova área' }}</h2>

          <label class="block">
            <span class="pm-label">Bairro ou região</span>
            <DistrictAutocomplete
              v-model="form.district_name"
              :city="form.city"
              :near-city="searchContext.city"
              :proximity-lat="searchContext.lat"
              :proximity-lng="searchContext.lng"
              required
              @select="onDistrictSelect"
              @manual-input="onDistrictManualInput"
            />
          </label>

          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="pm-label">Taxa</span>
              <input v-model.number="form.fee" type="number" min="0" step="0.01" required class="pm-input-sm mt-2" />
            </label>

            <label class="block">
              <span class="pm-label">Prazo min</span>
              <input v-model.number="form.estimated_time" type="number" min="1" required class="pm-input-sm mt-2" />
            </label>
          </div>

          <label class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3 text-sm font-black text-slate-700">
            Área ativa
            <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-red-600 focus:ring-red-500" />
          </label>

          <div class="grid gap-2">
            <button :disabled="saving" type="submit" class="pm-btn-solid w-full py-4">
              <Loader2 v-if="saving" class="animate-spin" size="16" />
              <Save v-else size="16" />
              {{ editingId ? 'Salvar área' : 'Criar área' }}
            </button>

            <button v-if="editingId" type="button" @click="resetForm" class="rounded-2xl px-4 py-3 text-xs font-black text-slate-400 hover:bg-slate-50">
              Cancelar edição
            </button>
          </div>
        </form>

        <div class="space-y-6">
          <DeliveryAreasMap
            :areas="areas"
            :highlight-district="form.district_name"
          />

          <div class="pm-card">
          <div v-if="areas.length === 0" class="p-16 text-center">
            <MapPin class="mx-auto mb-4 text-slate-200" size="48" />
            <p class="text-sm font-bold text-slate-400">Nenhuma área cadastrada. Sem áreas ativas, a loja usa a taxa fixa de entrega.</p>
          </div>

          <div v-else class="divide-y divide-slate-100">
            <article v-for="area in areas" :key="area.id" class="grid gap-4 p-5 md:grid-cols-[1fr_auto] md:items-center">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class=" font-black text-slate-900">{{ areaLabel(area) }}</h3>
                  <span :class="area.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400'" class="rounded-full px-3 py-1 text-[10px] font-black uppercase">
                    {{ area.is_active ? 'Ativa' : 'Pausada' }}
                  </span>
                </div>
                <p class="mt-1 text-sm font-bold text-slate-500">
                  {{ money(area.fee) }} · {{ area.estimated_time }} min
                </p>
              </div>

              <div class="flex gap-2">
                <button
                  @click="toggleArea(area)"
                  :class="[
                    'p-2.5 rounded-xl transition-all',
                    area.is_active
                      ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white'
                      : 'bg-slate-100 text-slate-500 hover:bg-slate-900 hover:text-white'
                  ]"
                >
                  <ToggleRight v-if="area.is_active" size="18" />
                  <ToggleLeft v-else size="18" />
                </button>
                <button @click="editArea(area)" class="pm-btn-icon-edit">
                  <Pencil size="18" />
                </button>
                <button @click="removeArea(area)" class="pm-btn-icon-delete">
                  <Trash2 size="18" />
                </button>
              </div>
            </article>
          </div>
        </div>
        </div>
      </section>
    </div>
</template>
