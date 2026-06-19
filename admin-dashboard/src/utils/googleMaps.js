export const getGoogleMapsApiKey = () => String(import.meta.env.VITE_GOOGLE_MAPS_API_KEY || '').trim()

export const isGoogleMapsEnabled = () => Boolean(getGoogleMapsApiKey())

const CITY_COORDINATES = {
  fortaleza: { lat: -3.7319, lng: -38.5267 },
  'juiz de fora': { lat: -21.7642, lng: -43.3496 },
  'sao paulo': { lat: -23.5505, lng: -46.6333 },
  'rio de janeiro': { lat: -22.9068, lng: -43.1729 }
}

let placesLoadPromise = null
let optionsConfigured = false
let authFailureMessage = null

export function getGoogleAuthFailureMessage() {
  return authFailureMessage
}

export function resetGoogleMapsLoader() {
  placesLoadPromise = null
  optionsConfigured = false
  authFailureMessage = null
}

if (typeof window !== 'undefined') {
  window.gm_authFailure = () => {
    authFailureMessage = 'Google Places bloqueou esta página. Libere o referrer no Google Cloud.'
    resetGoogleMapsLoader()
    window.dispatchEvent(new CustomEvent('google-maps-auth-failure'))
  }
}

export async function loadGooglePlaces() {
  if (!isGoogleMapsEnabled()) {
    throw new Error('Chave do Google Places não configurada.')
  }

  if (authFailureMessage) {
    throw new Error(authFailureMessage)
  }

  if (!placesLoadPromise) {
    placesLoadPromise = (async () => {
      const { setOptions, importLibrary } = await import('@googlemaps/js-api-loader')

      if (!optionsConfigured) {
        setOptions({
          key: getGoogleMapsApiKey(),
          v: 'weekly',
          language: 'pt-BR',
          region: 'BR'
        })
        optionsConfigured = true
      }

      await importLibrary('places')
    })().catch((error) => {
      placesLoadPromise = null
      throw error
    })
  }

  return placesLoadPromise
}

export function getDeliveryCityCenter(deliveryAreas = []) {
  const city = deliveryAreas.find((area) => area.city)?.city

  if (!city) {
    return null
  }

  const normalized = city
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()

  const coords = CITY_COORDINATES[normalized]

  if (!coords) {
    return { city, lat: null, lng: null }
  }

  return { city, lat: coords.lat, lng: coords.lng }
}

export function getAddressComponent(components, ...types) {
  for (const type of types) {
    const match = components?.find((component) => component.types?.includes(type))

    if (match?.long_name) {
      return match.long_name
    }
  }

  return ''
}

export function parseGooglePlace(place) {
  const components = place.address_components || []
  const route = getAddressComponent(components, 'route')
  const streetNumber = getAddressComponent(components, 'street_number')
  const districtCandidates = [
    getAddressComponent(components, 'sublocality_level_1'),
    getAddressComponent(components, 'sublocality'),
    getAddressComponent(components, 'neighborhood'),
    getAddressComponent(components, 'administrative_area_level_3')
  ].filter(Boolean)
  const district = districtCandidates[0] || ''
  const city = getAddressComponent(components, 'administrative_area_level_2', 'locality')
  const state = getAddressComponent(components, 'administrative_area_level_1')

  const latValue = place.geometry?.location?.lat
  const lngValue = place.geometry?.location?.lng
  const latitude = typeof latValue === 'function' ? latValue.call(place.geometry.location) : latValue
  const longitude = typeof lngValue === 'function' ? lngValue.call(place.geometry.location) : lngValue

  const formatted = place.formatted_address || place.name || ''
  const streetLine = route
    ? [route, streetNumber].filter(Boolean).join(', ')
    : formatted

  return {
    id: place.place_id || `google-${latitude}-${longitude}`,
    address: streetLine,
    address_number: streetNumber,
    district,
    district_candidates: [...new Set(districtCandidates)],
    city,
    state,
    latitude,
    longitude,
    label: formatted || streetLine,
    source: 'google'
  }
}

export function parseGoogleDistrictPlace(place) {
  const parsed = parseGooglePlace(place)
  const districtName = parsed.district || place.name || ''
  const city = parsed.city || getAddressComponent(place.address_components, 'locality', 'administrative_area_level_2')
  const label = parsed.label || [districtName, city].filter(Boolean).join(', ')

  return {
    id: place.place_id || parsed.id,
    district_name: districtName,
    city,
    state: parsed.state,
    latitude: parsed.latitude,
    longitude: parsed.longitude,
    label
  }
}

export function createPlacesAutocomplete(input, { lat = null, lng = null, types = null } = {}) {
  const options = {
    componentRestrictions: { country: 'br' },
    fields: ['address_components', 'geometry', 'formatted_address', 'place_id', 'name']
  }

  if (Array.isArray(types) && types.length > 0) {
    options.types = types
  }

  if (lat != null && lng != null && typeof google !== 'undefined' && google.maps?.LatLngBounds) {
    const delta = 0.15
    options.bounds = new google.maps.LatLngBounds(
      { lat: lat - delta, lng: lng - delta },
      { lat: lat + delta, lng: lng + delta }
    )
    options.strictBounds = false
  }

  return new google.maps.places.Autocomplete(input, options)
}
