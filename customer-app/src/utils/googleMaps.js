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
  const districtCandidates = [
    getAddressComponent(components, 'sublocality_level_1'),
    getAddressComponent(components, 'sublocality'),
    getAddressComponent(components, 'neighborhood'),
    getAddressComponent(components, 'administrative_area_level_3'),
    getAddressComponent(components, 'political')
  ].filter(Boolean);
  const district = districtCandidates[0] || '';
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
    district_candidates: [...new Set(districtCandidates)],
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

export function geocodeQuery(query) {
  return new Promise((resolve, reject) => {
    const geocoder = new google.maps.Geocoder();

    geocoder.geocode(
      {
        address: query,
        componentRestrictions: { country: 'br' }
      },
      (results, status) => {
        if (status !== 'OK' || !results?.[0]) {
          reject(new Error(status || 'Geocoder failed'));
          return;
        }

        const result = results[0];
        const location = result.geometry.location;
        const bounds = result.geometry.viewport || result.geometry.bounds;

        resolve({
          lat: location.lat(),
          lng: location.lng(),
          bounds,
          label: result.formatted_address || query
        });
      }
    );
  });
}

export function createPlacesAutocomplete(input, { bounds = null, strictBounds = true } = {}) {
  const options = {
    componentRestrictions: { country: 'br' },
    fields: ['address_components', 'geometry', 'formatted_address', 'place_id', 'name']
  };

  if (bounds) {
    options.bounds = bounds;
    options.strictBounds = strictBounds;
  }

  return new google.maps.places.Autocomplete(input, options);
}

export async function resolveStoreMapContext({
  proximityLat = null,
  proximityLng = null,
  searchNear = '',
  deliveryAreas = []
}) {
  await loadGoogleMaps();

  const city = deliveryAreas.find((area) => area.city)?.city;
  const district = deliveryAreas.find((area) => area.district_name)?.district_name;

  let bounds = null;
  let centerLat = null;
  let centerLng = null;

  if (city) {
    try {
      const cityResult = await geocodeQuery(`${city}, Brasil`);
      bounds = cityResult.bounds;
      centerLat = cityResult.lat;
      centerLng = cityResult.lng;
    } catch {
      // segue com fallback abaixo
    }
  }

  if (proximityLat != null && proximityLng != null) {
    return {
      lat: Number(proximityLat),
      lng: Number(proximityLng),
      bounds,
      city,
      source: 'store_coordinates'
    };
  }

  if (centerLat != null && centerLng != null) {
    return {
      lat: centerLat,
      lng: centerLng,
      bounds,
      city,
      source: 'city_geocoded'
    };
  }

  const queries = [
    searchNear,
    district && city ? `${district}, ${city}, Brasil` : null
  ].filter(Boolean);

  for (const query of queries) {
    try {
      const result = await geocodeQuery(query);

      return {
        ...result,
        bounds: result.bounds || bounds,
        city,
        source: 'geocoded'
      };
    } catch {
      // tenta próxima query
    }
  }

  throw new Error('Não foi possível localizar a área da loja no mapa.');
}
