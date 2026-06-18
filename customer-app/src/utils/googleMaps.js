export const getGoogleMapsApiKey = () => String(import.meta.env.VITE_GOOGLE_MAPS_API_KEY || '').trim();

export const isGoogleMapsEnabled = () => Boolean(getGoogleMapsApiKey());

let loadPromise = null;

export async function loadGoogleMaps() {
  if (!isGoogleMapsEnabled()) {
    throw new Error('Google Maps API key not configured');
  }

  if (!loadPromise) {
    loadPromise = import('@googlemaps/js-api-loader').then(({ Loader }) => {
      const loader = new Loader({
        apiKey: getGoogleMapsApiKey(),
        version: 'weekly',
        libraries: ['places', 'maps', 'geocoding'],
        language: 'pt-BR',
        region: 'BR'
      });

      return loader.load();
    });
  }

  return loadPromise;
}

export function getAddressComponent(components, ...types) {
  for (const type of types) {
    const match = components?.find((component) => component.types?.includes(type));

    if (match?.long_name) {
      return match.long_name;
    }
  }

  return '';
}

export function parseGooglePlace(place) {
  const components = place.address_components || [];
  const route = getAddressComponent(components, 'route');
  const streetNumber = getAddressComponent(components, 'street_number');
  const district = getAddressComponent(
    components,
    'sublocality_level_1',
    'sublocality',
    'neighborhood'
  );
  const city = getAddressComponent(components, 'administrative_area_level_2', 'locality');
  const state = getAddressComponent(components, 'administrative_area_level_1');

  const latValue = place.geometry?.location?.lat;
  const lngValue = place.geometry?.location?.lng;
  const latitude = typeof latValue === 'function' ? latValue.call(place.geometry.location) : latValue;
  const longitude = typeof lngValue === 'function' ? lngValue.call(place.geometry.location) : lngValue;

  const formatted = place.formatted_address || place.name || '';
  const streetLine = route
    ? [route, streetNumber].filter(Boolean).join(', ')
    : formatted;

  return {
    id: place.place_id || `google-${latitude}-${longitude}`,
    address: streetLine,
    address_number: streetNumber,
    district,
    city,
    state,
    latitude,
    longitude,
    label: formatted || streetLine,
    source: 'google'
  };
}

export function reverseGeocodeGoogle(latitude, longitude) {
  return new Promise((resolve, reject) => {
    const geocoder = new google.maps.Geocoder();

    geocoder.geocode(
      { location: { lat: Number(latitude), lng: Number(longitude) } },
      (results, status) => {
        if (status !== 'OK' || !results?.[0]) {
          reject(new Error(status || 'Geocoder failed'));
          return;
        }

        resolve(parseGooglePlace(results[0]));
      }
    );
  });
}

export function createPlacesAutocomplete(input, { proximityLat = null, proximityLng = null } = {}) {
  const options = {
    componentRestrictions: { country: 'br' },
    fields: ['address_components', 'geometry', 'formatted_address', 'place_id', 'name'],
    types: ['address']
  };

  const autocomplete = new google.maps.places.Autocomplete(input, options);

  if (proximityLat != null && proximityLng != null) {
    const center = { lat: Number(proximityLat), lng: Number(proximityLng) };
    const circle = new google.maps.Circle({
      center,
      radius: 25000
    });
    const bounds = circle.getBounds();

    if (bounds) {
      autocomplete.setBounds(bounds);
    }
  }

  return autocomplete;
}
