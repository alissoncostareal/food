<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import api from '@/services/api'
import { Loader2, MapPin } from 'lucide-vue-next'

const props = defineProps({
  areas: {
    type: Array,
    default: () => []
  },
  highlightDistrict: {
    type: String,
    default: ''
  }
})

const mapRoot = ref(null)
const loading = ref(true)
const mapError = ref('')
const mapAreas = ref([])

let mapInstance = null
let markersLayer = null

const AREA_RADIUS_METERS = 1800

const areaLabel = (area) => {
  if (!area?.district_name) return ''

  return area.city
    ? `${area.district_name}, ${area.city}`
    : area.district_name
}

const activeAreas = computed(() =>
  (props.areas || []).filter(area => area?.district_name)
)

const mappedAreas = computed(() => {
  const byId = new Map(mapAreas.value.map(area => [area.id, area]))

  return activeAreas.value.map(area => ({
    ...area,
    ...(byId.get(area.id) || {})
  }))
})

const buildMarkerIcon = (color) =>
  L.divIcon({
    className: '',
    html: `<span style="display:flex;width:14px;height:14px;border-radius:9999px;background:${color};border:2px solid white;box-shadow:0 2px 8px rgba(15,23,42,.25)"></span>`,
    iconSize: [14, 14],
    iconAnchor: [7, 7]
  })

const renderMap = async () => {
  if (!mapRoot.value) return

  loading.value = true
  mapError.value = ''

  try {
    const { data } = await api.get('/merchant/delivery-areas/map-preview')
    mapAreas.value = data.areas || []

    if (!mapInstance) {
      mapInstance = L.map(mapRoot.value, {
        zoomControl: true,
        scrollWheelZoom: false
      })

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
      }).addTo(mapInstance)

      markersLayer = L.layerGroup().addTo(mapInstance)
    }

    markersLayer.clearLayers()

    const bounds = []
    const store = data.store || {}

    if (store.latitude && store.longitude) {
      const storeLatLng = [store.latitude, store.longitude]

      L.marker(storeLatLng, {
        icon: buildMarkerIcon('#dc2626')
      })
        .bindPopup(`<strong>Loja</strong><br>${store.name || 'Sua loja'}<br>${store.address || ''}`)
        .addTo(markersLayer)

      bounds.push(storeLatLng)
    }

    let plottedAreas = 0

    mappedAreas.value.forEach((area) => {
      if (!area.latitude || !area.longitude) return

      plottedAreas += 1

      const isHighlighted = props.highlightDistrict &&
        area.district_name?.toLowerCase() === props.highlightDistrict.toLowerCase()

      const color = !area.is_active
        ? '#94a3b8'
        : isHighlighted
          ? '#dc2626'
          : '#2563eb'

      const latLng = [area.latitude, area.longitude]

      L.circle(latLng, {
        radius: AREA_RADIUS_METERS,
        color,
        weight: 2,
        fillColor: color,
        fillOpacity: isHighlighted ? 0.22 : 0.14
      })
        .bindPopup(
          `<strong>${areaLabel(area)}</strong><br>${area.is_active ? 'Área ativa' : 'Pausada'}`
        )
        .addTo(markersLayer)

      L.marker(latLng, { icon: buildMarkerIcon(color) })
        .bindPopup(
          `<strong>${areaLabel(area)}</strong><br>${area.is_active ? 'Área ativa' : 'Pausada'}`
        )
        .addTo(markersLayer)

      bounds.push(latLng)
    })

    await nextTick()

    if (bounds.length > 1) {
      mapInstance.fitBounds(bounds, { padding: [36, 36], maxZoom: 14 })
    } else if (bounds.length === 1) {
      mapInstance.setView(bounds[0], 13)
    } else {
      mapInstance.setView([-14.235, -51.925], 4)
    }

    if (activeAreas.value.length > 0 && plottedAreas === 0) {
      mapError.value = 'Áreas cadastradas, mas não foi possível localizar no mapa. Confira o endereço da loja e o nome dos bairros.'
    } else if (activeAreas.value.length === 0) {
      mapError.value = 'Cadastre áreas de entrega para visualizar no mapa.'
    } else if (!store.latitude || !store.longitude) {
      mapError.value = 'Cadastre o endereço completo da loja para centralizar o mapa.'
    }

    setTimeout(() => mapInstance?.invalidateSize(), 150)
  } catch (error) {
    console.error('Erro ao carregar mapa de entrega:', error)
    const apiMessage = error.response?.data?.message || error.response?.data?.error

    if (error.response?.status === 403 && error.response?.data?.upgrade_required) {
      mapError.value = 'Assinatura inativa ou plano sem áreas de entrega. Verifique em Meu Plano.'
    } else if (apiMessage) {
      mapError.value = apiMessage
    } else {
      mapError.value = 'Não foi possível carregar o mapa agora.'
    }
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.areas, props.highlightDistrict],
  () => {
    renderMap()
  },
  { deep: true }
)

onMounted(renderMap)

onBeforeUnmount(() => {
  mapInstance?.remove()
  mapInstance = null
  markersLayer = null
})
</script>

<template>
  <div class="pm-card overflow-hidden">
    <div class="border-b border-slate-100 bg-slate-50/80 px-5 py-4">
      <div class="flex items-center gap-2">
        <MapPin size="18" class="text-red-600 shrink-0" />
        <div>
          <p class="text-sm font-black text-slate-900">Mapa de entrega</p>
          <p class="text-xs font-bold text-slate-500">
            Círculos aproximados das regiões atendidas com base no bairro cadastrado.
          </p>
        </div>
      </div>
    </div>

    <div class="relative h-[320px] bg-slate-100">
      <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-white/80">
        <Loader2 class="animate-spin text-red-600" size="28" />
      </div>

      <div ref="mapRoot" class="h-full w-full" />

      <p
        v-if="mapError && !loading"
        class="absolute bottom-3 left-3 right-3 rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800"
      >
        {{ mapError }}
      </p>
    </div>

    <div v-if="activeAreas.length" class="flex flex-wrap gap-2 border-t border-slate-100 px-4 py-3">
      <span
        v-for="area in activeAreas"
        :key="area.id"
        :class="[
          'rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wide border',
          area.is_active
            ? 'border-red-100 bg-red-50 text-red-700'
            : 'border-slate-200 bg-slate-50 text-slate-400'
        ]"
      >
        {{ areaLabel(area) }}
      </span>
    </div>
  </div>
</template>
