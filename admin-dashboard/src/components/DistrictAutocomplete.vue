<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api from '@/services/api'
import {
  createPlacesAutocomplete,
  getDeliveryCityCenter,
  isGoogleMapsEnabled,
  loadGooglePlaces,
  parseGoogleDistrictPlace
} from '@/utils/googleMaps'
import { Loader2, MapPin, Search } from 'lucide-vue-next'

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  },
  city: {
    type: String,
    default: ''
  },
  nearCity: {
    type: String,
    default: ''
  },
  proximityLat: {
    type: Number,
    default: null
  },
  proximityLng: {
    type: Number,
    default: null
  },
  required: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue', 'select', 'manual-input'])

const inputRef = ref(null)
const query = ref(props.modelValue)
const suggestions = ref([])
const loading = ref(false)
const showSuggestions = ref(false)
const searchError = ref('')
const selectedLabel = ref('')
const googleReady = ref(false)

let debounceTimer = null
let autocomplete = null
let placeListener = null

const googleMode = computed(() => isGoogleMapsEnabled())

const helperText = computed(() => {
  if (searchError.value) {
    return ''
  }

  if (googleReady.value) {
    return props.nearCity
      ? `Sugestões do Google Maps priorizando ${props.nearCity}.`
      : 'Sugestões do Google Maps para bairros e regiões.'
  }

  return 'Digite o nome do bairro para ver sugestões em todo o Brasil.'
})

const formatAreaLabel = (item) => {
  if (item?.label) return item.label
  return [item?.district_name, item?.city].filter(Boolean).join(', ')
}

const syncDisplay = (districtName, city = '') => {
  query.value = city ? `${districtName}, ${city}` : districtName
}

watch(
  () => [props.modelValue, props.city],
  ([districtName, city]) => {
    if (selectedLabel.value && districtName === props.modelValue) {
      return
    }

    if (districtName && city) {
      syncDisplay(districtName, city)
      return
    }

    if (districtName !== query.value) {
      query.value = districtName || ''
    }
  }
)

const fetchSuggestions = async (term) => {
  if (!term || term.length < 2) {
    suggestions.value = []
    searchError.value = ''
    return
  }

  loading.value = true
  searchError.value = ''

  try {
    const { data } = await api.get('/geocoding/search', {
      params: {
        q: term,
        type: 'district',
        near: props.nearCity || undefined
      }
    })

    suggestions.value = data.data || []
    showSuggestions.value = suggestions.value.length > 0
  } catch {
    suggestions.value = []
    searchError.value = 'Busca indisponível. Digite o bairro manualmente.'
  } finally {
    loading.value = false
  }
}

const onInput = (event) => {
  const value = event.target.value
  selectedLabel.value = ''
  query.value = value
  emit('manual-input')

  const searchTerm = value.includes(',') ? value.split(',')[0].trim() : value.trim()
  emit('update:modelValue', searchTerm)

  if (googleReady.value) {
    return
  }

  showSuggestions.value = true

  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    fetchSuggestions(searchTerm)
  }, 300)
}

const selectSuggestion = (item) => {
  selectedLabel.value = formatAreaLabel(item)
  query.value = selectedLabel.value
  emit('update:modelValue', item.district_name)
  suggestions.value = []
  showSuggestions.value = false
  emit('select', item)
}

const searchTermFromQuery = (value) => {
  if (!value) return ''
  return value.includes(',') ? value.split(',')[0].trim() : value.trim()
}

const onFocus = () => {
  if (googleReady.value) {
    return
  }

  if (suggestions.value.length > 0) {
    showSuggestions.value = true
    return
  }

  const term = searchTermFromQuery(query.value)
  if (term.length >= 2) {
    fetchSuggestions(term)
  }
}

const onBlur = () => {
  setTimeout(() => {
    showSuggestions.value = false
  }, 150)
}

const resolveAutocompleteCenter = () => {
  if (props.nearCity) {
    const center = getDeliveryCityCenter([{ city: props.nearCity }])
    if (center?.lat != null && center?.lng != null) {
      return center
    }
  }

  if (props.proximityLat != null && props.proximityLng != null) {
    return { lat: props.proximityLat, lng: props.proximityLng }
  }

  return null
}

const setupGoogleAutocomplete = async () => {
  if (!googleMode.value || !inputRef.value) {
    return false
  }

  try {
    await loadGooglePlaces()

    const center = resolveAutocompleteCenter()
    autocomplete = createPlacesAutocomplete(inputRef.value, {
      lat: center?.lat ?? null,
      lng: center?.lng ?? null,
      types: ['(regions)']
    })

    placeListener = autocomplete.addListener('place_changed', () => {
      const place = autocomplete.getPlace()
      if (!place?.geometry) {
        return
      }

      const item = parseGoogleDistrictPlace(place)
      if (!item.district_name) {
        return
      }

      selectSuggestion(item)
    })

    googleReady.value = true
    return true
  } catch (error) {
    searchError.value = error.message || 'Google Maps indisponível. Usando busca alternativa.'
    return false
  }
}

onBeforeUnmount(() => {
  clearTimeout(debounceTimer)
  placeListener?.remove?.()
  autocomplete = null
})

onMounted(async () => {
  if (props.modelValue && props.city) {
    syncDisplay(props.modelValue, props.city)
  }

  const googleActive = await setupGoogleAutocomplete()

  if (!googleActive && searchTermFromQuery(query.value).length >= 2) {
    fetchSuggestions(searchTermFromQuery(query.value))
  }
})
</script>

<template>
  <div class="relative">
    <div class="relative mt-2">
      <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input
        ref="inputRef"
        :value="query"
        type="text"
        autocomplete="off"
        :required="required"
        placeholder="Ex: Aldeota, Meireles, Vila Velha..."
        class="pm-input-sm pl-10 pr-10"
        @input="onInput"
        @focus="onFocus"
        @blur="onBlur"
      />
      <Loader2
        v-if="loading"
        class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-red-500"
      />
    </div>

    <p v-if="searchError" class="mt-2 text-xs font-bold text-amber-700">
      {{ searchError }}
    </p>

    <p v-else class="mt-2 text-[11px] font-semibold text-slate-400">
      {{ helperText }}
    </p>

    <div
      v-if="!googleReady && showSuggestions && suggestions.length > 0"
      class="absolute z-20 mt-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lg"
    >
      <button
        v-for="item in suggestions"
        :key="item.id"
        type="button"
        class="flex w-full items-start gap-3 border-b border-slate-100 px-4 py-3 text-left transition hover:bg-slate-50 last:border-b-0"
        @mousedown.prevent="selectSuggestion(item)"
      >
        <MapPin size="16" class="mt-0.5 shrink-0 text-red-500" />
        <span>
          <span class="block text-sm font-black text-slate-900">{{ item.district_name }}</span>
          <span class="mt-0.5 block text-xs font-semibold text-slate-500">{{ item.label }}</span>
        </span>
      </button>
    </div>
  </div>
</template>
