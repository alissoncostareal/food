import React, { useEffect, useMemo, useRef, useState } from 'react';
import { MapPin, Search, Navigation, Loader2, ChevronDown, ChevronUp } from 'lucide-react';
import api from '../services/api';
import { hasStreetNumber, mergeStreetAddress, normalizeLocation, splitStreetAddress } from '../utils/streetAddress';
import { filterDeliveryAreas, formatCep, onlyCepDigits } from '../utils/cep';
import {
  createPlacesAutocomplete,
  getDeliveryCityCenter,
  getGoogleAuthFailureMessage,
  isGoogleMapsEnabled,
  loadGooglePlaces,
  parseGooglePlace,
  reverseGeocodeGoogle
} from '../utils/googleMaps';

const formatCurrency = (value) => {
  return Number(value || 0).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  });
};

const fieldClass =
  'w-full h-11 px-3.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:border-[var(--store-primary)] focus:ring-2 focus:ring-[var(--store-primary)]/10 transition-all placeholder:text-slate-400';

const labelClass = 'text-[11px] font-bold text-slate-500';

const RequiredMark = () => (
  <span className="text-[var(--store-primary)] ml-0.5" aria-hidden="true">*</span>
);

const formatDistrictLabel = (district, city) => {
  return [district, city].filter(Boolean).join(', ');
};

const formatDistrictItemLabel = (item) => {
  if (!item) return '';
  if (item.district_name) {
    return formatDistrictLabel(item.district_name, item.city);
  }
  return formatDistrictLabel(item.district, item.city);
};

const matchDeliveryArea = (deliveryAreas, district, city) => {
  if (!deliveryAreas.length) return null;

  const normalizedDistrict = normalizeLocation(district);
  const normalizedCity = normalizeLocation(city);

  if (!normalizedDistrict && !normalizedCity) return null;

  return deliveryAreas.find((area) => {
    const areaDistrict = normalizeLocation(area.district_name);
    const areaCity = normalizeLocation(area.city);

    const districtMatch = !normalizedDistrict
      || areaDistrict === normalizedDistrict
      || areaDistrict.includes(normalizedDistrict)
      || normalizedDistrict.includes(areaDistrict);

    if (!districtMatch) return false;

    if (areaCity && normalizedCity) {
      return areaCity === normalizedCity
        || areaCity.includes(normalizedCity)
        || normalizedCity.includes(areaCity);
    }

    return districtMatch;
  }) || null;
};

const findDeliveryAreaForSuggestion = (deliveryAreas, suggestion) => {
  if (!deliveryAreas.length || !suggestion) {
    return null;
  }

  const candidates = [
    ...(suggestion.district_candidates || []),
    suggestion.district,
    suggestion.city
  ].filter(Boolean);

  for (const candidate of candidates) {
    const match = matchDeliveryArea(deliveryAreas, candidate, suggestion.city);

    if (match) {
      return match;
    }
  }

  return matchDeliveryArea(deliveryAreas, suggestion.district, suggestion.city);
};

const pickBestAddressSuggestion = (results, deliveryAreas) => {
  if (!results?.length) return null;

  for (const result of results) {
    if (matchDeliveryArea(deliveryAreas, result.district, result.city)) {
      return result;
    }
  }

  return results[0];
};

const isCityInDeliveryScope = (suggestion, deliveryAreas, scopeCity) => {
  const expectedCity = scopeCity || deliveryAreas.find((area) => area.city)?.city;

  if (!expectedCity || !suggestion?.city) {
    return true;
  }

  const normalizedExpected = normalizeLocation(expectedCity);
  const normalizedActual = normalizeLocation(suggestion.city);

  return normalizedActual.includes(normalizedExpected)
    || normalizedExpected.includes(normalizedActual);
};

const formatServedAreasList = (deliveryAreas, limit = 8) => {
  const labels = deliveryAreas.map((area) => formatDistrictLabel(area.district_name, area.city));
  const unique = [...new Set(labels)];

  if (unique.length <= limit) {
    return unique.join(', ');
  }

  return `${unique.slice(0, limit).join(', ')} e mais ${unique.length - limit}`;
};

const buildOutOfDeliveryMessage = (suggestion, deliveryAreas, scopeCity) => {
  const served = formatServedAreasList(deliveryAreas);
  const district = suggestion?.district || 'este bairro';
  const city = suggestion?.city || '';
  const expectedCity = scopeCity || deliveryAreas.find((area) => area.city)?.city;

  if (expectedCity && city && !isCityInDeliveryScope(suggestion, deliveryAreas, expectedCity)) {
    return `Não entregamos em ${city}. Atendemos apenas ${expectedCity}: ${served}.`;
  }

  if (district && city) {
    return `Não entregamos em ${formatDistrictLabel(district, city)}. Bairros atendidos: ${served}.`;
  }

  return `Não entregamos neste endereço. Bairros atendidos: ${served}.`;
};

export default function AddressSection({
  values,
  onChange,
  deliveryAreas = [],
  deliveryAreasLoading = false,
  selectedDeliveryArea = null,
  deliveryFee = 0,
  showLocationButton = true,
  showDeliverySummary = true,
  required = false,
  autoSearch = true,
  searchNear = '',
  proximityLat = null,
  proximityLng = null,
  onMapsError
}) {
  const [googleReady, setGoogleReady] = useState(false);
  const [googleLoadError, setGoogleLoadError] = useState('');
  const wantsGoogleAddressFlow = isGoogleMapsEnabled() && autoSearch && deliveryAreas.length > 0;
  const useGooglePlaces = googleReady && isGoogleMapsEnabled();
  const regionFirstMode = autoSearch && deliveryAreas.length > 0 && !wantsGoogleAddressFlow;
  const addressEditingRef = useRef(false);
  const districtEditingRef = useRef(false);
  const selectingSuggestionRef = useRef(false);
  const numberInputRef = useRef(null);
  const streetInputRef = useRef(null);
  const autocompleteRef = useRef(null);

  const deliveryCity = useMemo(
    () => getDeliveryCityCenter(deliveryAreas),
    [deliveryAreas]
  );

  const [addressQuery, setAddressQuery] = useState(values.address || '');
  const [resolvedStreet, setResolvedStreet] = useState('');
  const [houseNumber, setHouseNumber] = useState('');
  const [streetResolved, setStreetResolved] = useState(false);
  const [districtQuery, setDistrictQuery] = useState(formatDistrictLabel(values.district, values.city));
  const [regionQuery, setRegionQuery] = useState('');
  const [addressSuggestions, setAddressSuggestions] = useState([]);
  const [districtSuggestions, setDistrictSuggestions] = useState([]);
  const [regionSuggestions, setRegionSuggestions] = useState([]);
  const [searchLoading, setSearchLoading] = useState(false);
  const [districtSearchLoading, setDistrictSearchLoading] = useState(false);
  const [mapsError, setMapsError] = useState('');
  const [districtSearchError, setDistrictSearchError] = useState('');
  const [locationLoading, setLocationLoading] = useState(false);
  const [selectedDistrictLabel, setSelectedDistrictLabel] = useState('');
  const [addressFocused, setAddressFocused] = useState(false);
  const [districtFocused, setDistrictFocused] = useState(false);
  const [regionFocused, setRegionFocused] = useState(false);

  const [showCepField, setShowCepField] = useState(false);
  const [cepQuery, setCepQuery] = useState('');
  const [cepLoading, setCepLoading] = useState(false);
  const [cepError, setCepError] = useState('');
  const [cepWarning, setCepWarning] = useState('');
  const [areaMatchWarning, setAreaMatchWarning] = useState('');
  const lastCepLookupRef = useRef('');
  const autoResolvePrefilledRef = useRef('');

  const geocodingParams = (query) => ({
    q: query,
    ...(searchNear ? { near: searchNear } : {}),
    ...(proximityLat != null && proximityLng != null
      ? { proximity_lat: proximityLat, proximity_lng: proximityLng }
      : {})
  });

  useEffect(() => {
    if (!isGoogleMapsEnabled()) {
      return undefined;
    }

    let cancelled = false;

    const handleAuthFailure = () => {
      if (!cancelled) {
        setGoogleReady(false);
        setGoogleLoadError(
          getGoogleAuthFailureMessage()
            || `Google Places bloqueou esta página. Libere o referrer: ${window.location.origin}/*`
        );
      }
    };

    window.addEventListener('google-maps-auth-failure', handleAuthFailure);

    loadGooglePlaces()
      .then(() => {
        if (!cancelled) {
          setGoogleReady(true);
          setGoogleLoadError('');
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setGoogleReady(false);
          setGoogleLoadError(error?.message || 'Google Places não carregou. Verifique a chave da API.');
        }
      });

    return () => {
      cancelled = true;
      window.removeEventListener('google-maps-auth-failure', handleAuthFailure);
    };
  }, []);

  useEffect(() => {
    if (!useGooglePlaces || !streetInputRef.current) {
      return undefined;
    }

    const autocomplete = createPlacesAutocomplete(streetInputRef.current, {
      lat: deliveryCity?.lat,
      lng: deliveryCity?.lng
    });

    autocompleteRef.current = autocomplete;

    const listener = autocomplete.addListener('place_changed', () => {
      const place = autocomplete.getPlace();

      if (!place?.geometry?.location) {
        return;
      }

      selectingSuggestionRef.current = true;
      applyAddressSuggestion(parseGooglePlace(place), { requireGoogle: true });
      setAddressFocused(false);
      setMapsError('');
      onMapsError?.('');

      setTimeout(() => {
        selectingSuggestionRef.current = false;
      }, 220);
    });

    return () => {
      google.maps.event.removeListener(listener);
      autocompleteRef.current = null;
    };
  }, [useGooglePlaces, deliveryCity, streetResolved]);

  const syncResolvedFromValues = (address, district) => {
    if (!autoSearch || regionFirstMode || !address?.trim()) {
      setStreetResolved(false);
      setResolvedStreet('');
      setHouseNumber('');
      return;
    }

    const parts = splitStreetAddress(address);

    if (district) {
      setStreetResolved(true);
      setResolvedStreet(parts.street);
      setHouseNumber(parts.number);
      setAddressQuery(address);
      return;
    }

    setStreetResolved(false);
    setResolvedStreet('');
    setHouseNumber('');
  };

  useEffect(() => {
    if (addressEditingRef.current) {
      addressEditingRef.current = false;
      return;
    }

    setAddressQuery(values.address || '');
    setAddressSuggestions([]);

    if (autoSearch && !regionFirstMode) {
      syncResolvedFromValues(values.address, values.district);
    }
  }, [values.address, values.district, autoSearch, regionFirstMode]);

  useEffect(() => {
    if (districtEditingRef.current) {
      districtEditingRef.current = false;
      return;
    }

    const label = formatDistrictLabel(values.district, values.city);

    if (values.district) {
      setSelectedDistrictLabel(label);
      setDistrictQuery(label);
    } else {
      setSelectedDistrictLabel('');
      setDistrictQuery('');
    }

    if (selectedDeliveryArea) {
      const regionLabel = formatDistrictLabel(selectedDeliveryArea.district_name, selectedDeliveryArea.city);
      setRegionQuery(regionLabel);
      setSelectedDistrictLabel(regionLabel);
    } else if (!values.district) {
      setRegionQuery('');
    }

    setDistrictSuggestions([]);
  }, [values.district, values.city, selectedDeliveryArea]);

  useEffect(() => {
    if (!autoSearch || deliveryAreasLoading || isGoogleMapsEnabled()) return;
    if (addressEditingRef.current || districtEditingRef.current) return;

    const address = String(values.address || '').trim();
    const district = String(values.district || '').trim();

    if (!address || !district || !hasStreetNumber(address)) return;

    const areasKey = deliveryAreas.map((area) => area.id).join(',');
    const token = `${address}|${district}|${values.city || ''}|${areasKey}|${values.delivery_area_id || ''}`;

    if (autoResolvePrefilledRef.current === token) return;

    const syncPrefilledUi = (area, districtName, cityName) => {
      setAddressQuery(address);

      const label = area
        ? formatDistrictLabel(area.district_name, area.city)
        : formatDistrictLabel(districtName, cityName);

      if (label) {
        setSelectedDistrictLabel(label);
        setRegionQuery(label);
        setDistrictQuery(label);
      }

      if (!regionFirstMode) {
        syncResolvedFromValues(address, districtName || district);
      }
    };

    if (deliveryAreas.length > 0 && values.delivery_area_id) {
      const area = deliveryAreas.find((item) => String(item.id) === String(values.delivery_area_id));
      syncPrefilledUi(area, district, values.city);
      autoResolvePrefilledRef.current = token;
      return;
    }

    let cancelled = false;

    (async () => {
      let nextValues = { ...values };
      let matchedArea = matchDeliveryArea(deliveryAreas, district, values.city);

      if (matchedArea) {
        nextValues = {
          ...nextValues,
          delivery_area_id: String(matchedArea.id),
          district: matchedArea.district_name || district,
          city: matchedArea.city || nextValues.city || ''
        };
      }

      if (!nextValues.latitude || !nextValues.longitude) {
        try {
          const { data } = await api.get('/geocoding/search', {
            params: geocodingParams(address)
          });

          if (cancelled) return;

          const best = pickBestAddressSuggestion(data.data || [], deliveryAreas)
            || (data.data || [])[0];

          if (best?.latitude != null && best?.longitude != null) {
            nextValues.latitude = best.latitude;
            nextValues.longitude = best.longitude;
          }

          if (!matchedArea && best) {
            const geoArea = matchDeliveryArea(deliveryAreas, best.district, best.city);

            if (geoArea) {
              matchedArea = geoArea;
              nextValues.delivery_area_id = String(geoArea.id);
              nextValues.district = geoArea.district_name || best.district || district;
              nextValues.city = geoArea.city || best.city || '';
            }
          }
        } catch {
          // mantém endereço salvo sem coordenadas
        }
      }

      if (cancelled) return;

      syncPrefilledUi(matchedArea, nextValues.district || district, nextValues.city || values.city);

      onChange({
        ...nextValues,
        address,
        district: nextValues.district || district,
        city: nextValues.city || values.city || ''
      });

      autoResolvePrefilledRef.current = token;
    })();

    return () => {
      cancelled = true;
    };
  }, [
    autoSearch,
    deliveryAreasLoading,
    deliveryAreas,
    regionFirstMode,
    values.address,
    values.district,
    values.city,
    values.delivery_area_id,
    values.latitude,
    values.longitude
  ]);

  useEffect(() => {
    if (regionFirstMode) {
      setAddressSuggestions([]);
      return;
    }

    if (useGooglePlaces) {
      setAddressSuggestions([]);
      return;
    }

    if (!autoSearch || !addressFocused || !addressQuery || addressQuery.length < 3) {
      setAddressSuggestions([]);
      return;
    }

    const timeout = setTimeout(async () => {
      setSearchLoading(true);

      try {
        const { data } = await api.get('/geocoding/search', {
          params: geocodingParams(addressQuery)
        });

        setAddressSuggestions(data.data || []);
        setMapsError('');
        onMapsError?.('');
      } catch (error) {
        setAddressSuggestions([]);
        const message = 'Busca automática indisponível. Preencha manualmente.';
        setMapsError(message);
        onMapsError?.(message);
      } finally {
        setSearchLoading(false);
      }
    }, 350);

    return () => clearTimeout(timeout);
  }, [addressQuery, addressFocused, autoSearch, onMapsError, searchNear, proximityLat, proximityLng, regionFirstMode, useGooglePlaces]);

  useEffect(() => {
    if (!regionFirstMode) {
      setRegionSuggestions([]);
      return;
    }

    if (!regionFocused) {
      setRegionSuggestions([]);
      return;
    }

    const term = regionQuery.trim();

    if (selectedDeliveryArea && term === formatDistrictLabel(selectedDeliveryArea.district_name, selectedDeliveryArea.city)) {
      setRegionSuggestions([]);
      return;
    }

    setRegionSuggestions(filterDeliveryAreas(deliveryAreas, term));
  }, [regionQuery, regionFocused, deliveryAreas, regionFirstMode, selectedDeliveryArea]);

  useEffect(() => {
    if (regionFirstMode || !autoSearch || deliveryAreas.length > 0) {
      if (regionFirstMode) {
        setDistrictSuggestions([]);
      }
      return;
    }

    if (!districtFocused || selectedDistrictLabel) {
      setDistrictSuggestions([]);
      return;
    }

    const searchTerm = districtQuery.includes(',')
      ? districtQuery.split(',')[0].trim()
      : districtQuery.trim();

    if (!searchTerm || searchTerm.length < 2) {
      setDistrictSuggestions([]);
      return;
    }

    const timeout = setTimeout(async () => {
      setDistrictSearchLoading(true);

      try {
        const { data } = await api.get('/geocoding/search', {
          params: {
            q: searchTerm,
            type: 'district',
            ...(searchNear ? { near: searchNear } : {})
          }
        });

        setDistrictSuggestions(data.data || []);
        setDistrictSearchError('');
      } catch (error) {
        setDistrictSuggestions([]);
        setDistrictSearchError('Busca indisponível. Digite o bairro manualmente.');
      } finally {
        setDistrictSearchLoading(false);
      }
    }, 300);

    return () => clearTimeout(timeout);
  }, [districtQuery, deliveryAreas.length, autoSearch, districtFocused, selectedDistrictLabel, searchNear, regionFirstMode]);

  const updateField = (key, value) => {
    onChange({ ...values, [key]: value });
  };

  const updateFullAddress = (street, number, extra = {}) => {
    onChange({
      ...values,
      address: mergeStreetAddress(street, number),
      ...extra
    });
  };

  const selectDeliveryArea = (area) => {
    if (!area) return;

    const label = formatDistrictLabel(area.district_name, area.city);

    setSelectedDistrictLabel(label);
    setRegionQuery(label);
    setDistrictQuery(label);
    setRegionSuggestions([]);
    setCepWarning('');
    setAreaMatchWarning('');

    onChange({
      ...values,
      delivery_area_id: String(area.id),
      district: area.district_name || '',
      city: area.city || ''
    });
  };

  const clearDeliveryArea = () => {
    autoResolvePrefilledRef.current = '';
    setSelectedDistrictLabel('');
    setRegionQuery('');
    setDistrictQuery('');
    setCepWarning('');
    setAreaMatchWarning('');

    onChange({
      ...values,
      delivery_area_id: '',
      district: '',
      city: ''
    });
  };

  const resetStreetSearch = () => {
    autoResolvePrefilledRef.current = '';
    setStreetResolved(false);
    setResolvedStreet('');
    setHouseNumber('');
    setAddressQuery('');
    setAddressSuggestions([]);
    setAreaMatchWarning('');

    onChange({
      ...values,
      address: '',
      latitude: '',
      longitude: '',
      delivery_area_id: '',
      district: '',
      city: ''
    });
  };

  const handleStreetInputChange = (value, { persistAddress = false } = {}) => {
    addressEditingRef.current = true;
    setAddressQuery(value);
    setMapsError('');
    setAreaMatchWarning('');

    if (wantsGoogleAddressFlow && streetResolved) {
      setStreetResolved(false);
      setResolvedStreet('');
      setHouseNumber('');
      setAreaMatchWarning('');
      setMapsError('');
      onChange({
        ...values,
        address: persistAddress ? value : '',
        latitude: '',
        longitude: '',
        delivery_area_id: '',
        district: '',
        city: ''
      });
      return;
    }

    if (persistAddress) {
      updateField('address', value);
    }
  };

  const handleNumberChange = (value) => {
    setHouseNumber(value);
    updateFullAddress(resolvedStreet, value);
  };

  const applyAddressSuggestion = (suggestion, { requireGoogle = false } = {}) => {
    if (requireGoogle && suggestion.source !== 'google') {
      return;
    }

    const scopeCity = deliveryCity?.city || deliveryAreas.find((area) => area.city)?.city;
    const typedParts = splitStreetAddress(addressQuery);
    const matchedArea = findDeliveryAreaForSuggestion(deliveryAreas, suggestion);
    const fullLine = (
      suggestion.address
      || mergeStreetAddress(suggestion.address || typedParts.street, suggestion.address_number || typedParts.number)
    ).trim();

    if (deliveryAreas.length > 0 && !matchedArea) {
      const message = buildOutOfDeliveryMessage(suggestion, deliveryAreas, scopeCity);

      setAreaMatchWarning(message);
      setMapsError(message);
      setStreetResolved(false);
      setResolvedStreet('');
      setHouseNumber('');
      setAddressQuery(fullLine);
      setAddressSuggestions([]);
      setSelectedDistrictLabel('');
      setDistrictQuery('');
      setRegionQuery('');

      onChange({
        ...values,
        address: fullLine,
        district: suggestion.district || '',
        city: suggestion.city || '',
        latitude: suggestion.latitude ?? '',
        longitude: suggestion.longitude ?? '',
        delivery_area_id: ''
      });

      return;
    }

    const district = matchedArea?.district_name || suggestion.district || values.district;
    const city = matchedArea?.city || suggestion.city || '';
    const districtLabel = formatDistrictLabel(district, city);
    const parts = splitStreetAddress(fullLine);

    setStreetResolved(true);
    setResolvedStreet(parts.street);
    setHouseNumber(parts.number);
    setAddressQuery(fullLine);
    setAddressSuggestions([]);
    setSelectedDistrictLabel(districtLabel);
    setDistrictQuery(districtLabel);
    setRegionQuery(districtLabel);
    setMapsError('');
    setAreaMatchWarning('');
    setCepWarning('');

    onChange({
      ...values,
      address: fullLine,
      district: district || '',
      city,
      latitude: suggestion.latitude,
      longitude: suggestion.longitude,
      delivery_area_id: matchedArea ? String(matchedArea.id) : ''
    });

    if (!parts.number && autoSearch) {
      setTimeout(() => numberInputRef.current?.focus(), 120);
    }
  };

  const resolveAddressOnBlur = () => {
    setTimeout(async () => {
      if (selectingSuggestionRef.current) return;

      setAddressFocused(false);

      if (isGoogleMapsEnabled() && useGooglePlaces) {
        return;
      }

      if (regionFirstMode || !autoSearch || !addressQuery.trim() || addressQuery.length < 3) {
        return;
      }

      if (streetResolved) return;

      try {
        const { data } = await api.get('/geocoding/search', {
          params: geocodingParams(addressQuery)
        });

        const best = pickBestAddressSuggestion(data.data || [], deliveryAreas);

        if (best) {
          applyAddressSuggestion(best);
        }
      } catch {
        // mantém preenchimento manual
      }
    }, 180);
  };

  const selectSuggestion = (suggestion) => {
    selectingSuggestionRef.current = true;
    applyAddressSuggestion(suggestion);
    setTimeout(() => {
      selectingSuggestionRef.current = false;
    }, 220);
  };

  const selectDistrictSuggestion = (item) => {
    const districtLabel = formatDistrictItemLabel(item);
    const matchedArea = matchDeliveryArea(
      deliveryAreas,
      item.district_name,
      item.city
    );

    setSelectedDistrictLabel(districtLabel);
    setDistrictQuery(districtLabel);
    setDistrictSuggestions([]);

    onChange({
      ...values,
      district: item.district_name || '',
      city: item.city || '',
      latitude: item.latitude ?? values.latitude,
      longitude: item.longitude ?? values.longitude,
      delivery_area_id: matchedArea ? String(matchedArea.id) : values.delivery_area_id
    });
  };

  const handleDistrictInput = (value) => {
    districtEditingRef.current = true;
    setSelectedDistrictLabel('');
    setDistrictQuery(value);

    const searchTerm = value.includes(',') ? value.split(',')[0].trim() : value.trim();

    onChange({
      ...values,
      district: searchTerm,
      city: '',
      delivery_area_id: ''
    });
  };

  const handleRegionInput = (value) => {
    setRegionQuery(value);
    setSelectedDistrictLabel('');
    setCepWarning('');

    onChange({
      ...values,
      delivery_area_id: '',
      district: '',
      city: ''
    });
  };

  const lookupCep = async (digits = onlyCepDigits(cepQuery)) => {
    if (digits.length !== 8) {
      setCepError('Informe um CEP válido com 8 dígitos.');
      return;
    }

    setCepLoading(true);
    setCepError('');
    setCepWarning('');

    try {
      const { data } = await api.get('/geocoding/cep', {
        params: { cep: digits }
      });

      const result = data.data;

      if (!result) {
        setCepError('CEP não encontrado.');
        lastCepLookupRef.current = '';
        return;
      }

      const matchedArea = matchDeliveryArea(deliveryAreas, result.district, result.city);
      const regionLabel = matchedArea
        ? formatDistrictLabel(matchedArea.district_name, matchedArea.city)
        : formatDistrictLabel(result.district, result.city);

      if (result.address) {
        setAddressQuery(result.address);
      }

      if (matchedArea) {
        setSelectedDistrictLabel(regionLabel);
        setRegionQuery(regionLabel);
        setDistrictQuery(regionLabel);
        setRegionSuggestions([]);
        setCepWarning('');
      } else if (deliveryAreas.length > 0 && result.district) {
        setSelectedDistrictLabel('');
        setRegionQuery('');
        setCepWarning(
          `O bairro "${result.district}" não está na lista de entrega. Selecione sua região manualmente.`
        );
      } else if (result.district) {
        setSelectedDistrictLabel(regionLabel);
        setDistrictQuery(regionLabel);
      }

      onChange({
        ...values,
        address: result.address || values.address,
        district: matchedArea?.district_name || result.district || values.district,
        city: matchedArea?.city || result.city || values.city,
        delivery_area_id: matchedArea ? String(matchedArea.id) : '',
        address_complement: values.address_complement || result.complement || ''
      });
    } catch (error) {
      lastCepLookupRef.current = '';
      setCepError(error.response?.data?.message || 'Não foi possível consultar o CEP.');
    } finally {
      setCepLoading(false);
    }
  };

  useEffect(() => {
    if (!showCepField) {
      return;
    }

    const digits = onlyCepDigits(cepQuery);

    if (digits.length !== 8) {
      lastCepLookupRef.current = '';
      return;
    }

    if (lastCepLookupRef.current === digits) {
      return;
    }

    const timeout = setTimeout(() => {
      lastCepLookupRef.current = digits;
      lookupCep(digits);
    }, 450);

    return () => clearTimeout(timeout);
  }, [cepQuery, showCepField]);

  const useCurrentLocation = () => {
    if (!navigator.geolocation) {
      return;
    }

    setLocationLoading(true);

    navigator.geolocation.getCurrentPosition(
      async (position) => {
        try {
          const { latitude, longitude } = position.coords;

          if (useGooglePlaces) {
            const suggestion = await reverseGeocodeGoogle(latitude, longitude);
            const matchedArea = findDeliveryAreaForSuggestion(deliveryAreas, suggestion);

            if (regionFirstMode) {
              if (matchedArea) {
                selectDeliveryArea(matchedArea);
              } else if (suggestion.district) {
                setCepWarning('Confirme sua região na lista — o GPS não bateu com uma área atendida.');
              }

              applyAddressSuggestion({
                ...suggestion,
                latitude,
                longitude,
                delivery_area_id: matchedArea ? String(matchedArea.id) : values.delivery_area_id
              });
            } else {
              applyAddressSuggestion({
                ...suggestion,
                latitude,
                longitude
              });
            }

            setMapsError('');
            return;
          }

          const { data } = await api.get('/geocoding/reverse', {
            params: {
              latitude,
              longitude
            }
          });

          const suggestion = data.data;
          const matchedArea = matchDeliveryArea(deliveryAreas, suggestion.district, suggestion.city);

          if (regionFirstMode) {
            if (suggestion.address) {
              setAddressQuery(suggestion.address);
            }

            if (matchedArea) {
              selectDeliveryArea(matchedArea);
            } else if (suggestion.district) {
              setCepWarning('Confirme sua região na lista — o GPS não bateu com uma área atendida.');
            }

            onChange({
              ...values,
              address: suggestion.address || values.address,
              district: matchedArea?.district_name || suggestion.district || values.district,
              city: matchedArea?.city || suggestion.city || values.city,
              delivery_area_id: matchedArea ? String(matchedArea.id) : values.delivery_area_id,
              latitude: position.coords.latitude,
              longitude: position.coords.longitude
            });
          } else {
            applyAddressSuggestion({
              ...suggestion,
              latitude: position.coords.latitude,
              longitude: position.coords.longitude
            });
          }

          setMapsError('');
        } catch (error) {
          onChange({
            ...values,
            latitude: position.coords.latitude,
            longitude: position.coords.longitude
          });
        } finally {
          setLocationLoading(false);
        }
      },
      () => {
        setLocationLoading(false);
      },
      {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 0
      }
    );
  };

  const headerHint = useMemo(() => {
    if (wantsGoogleAddressFlow) {
      if (googleLoadError) {
        return 'Digite rua e número e escolha uma sugestão na lista. Autocomplete do Google indisponível neste domínio.';
      }

      if (useGooglePlaces) {
        return 'Digite rua e número e escolha uma sugestão do Google — a região de entrega é detectada automaticamente.';
      }

      return 'Digite rua e número e escolha uma sugestão na lista.';
    }

    if (regionFirstMode) {
      return 'Escolha a região, informe rua e número. CEP é opcional para preencher mais rápido.';
    }

    if (autoSearch) {
      return 'Digite rua e número — o bairro aparece ao escolher a sugestão.';
    }

    return 'Confira ou atualize seu endereço padrão';
  }, [wantsGoogleAddressFlow, googleLoadError, useGooglePlaces, regionFirstMode, autoSearch]);

  const renderMatchedDeliveryArea = () => {
    if (!selectedDeliveryArea) {
      return null;
    }

    return (
      <div className="rounded-xl border border-slate-200 bg-white px-3.5 py-3">
        <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">
          Região de entrega
        </p>
        <p className="text-sm font-bold text-slate-900 mt-0.5">
          {formatDistrictLabel(selectedDeliveryArea.district_name, selectedDeliveryArea.city)}
          <span className="font-semibold text-slate-600">
            {' · Taxa '}
            {formatCurrency(selectedDeliveryArea.fee)}
          </span>
        </p>
        {!wantsGoogleAddressFlow && (
          <button
            type="button"
            onClick={clearDeliveryArea}
            className="mt-1 text-xs font-bold text-[var(--store-primary)] hover:underline"
          >
            Alterar região
          </button>
        )}
      </div>
    );
  };

  const renderDeliveryAreaSection = () => {
    if (deliveryAreas.length === 0) {
      return renderDistrictField();
    }

    if (wantsGoogleAddressFlow) {
      return null;
    }

    if (selectedDeliveryArea) {
      return renderMatchedDeliveryArea();
    }

    if (regionFirstMode) {
      return null;
    }

    return (
      <div className="space-y-1.5">
        <label className={labelClass}>
          Região
          {required && <RequiredMark />}
        </label>
        <select
          value={values.delivery_area_id || ''}
          onChange={(e) => {
            const area = deliveryAreas.find(item => String(item.id) === String(e.target.value));
            if (area) selectDeliveryArea(area);
            else clearDeliveryArea();
          }}
          required={required}
          className={fieldClass}
        >
          <option value="">Selecione a região</option>
          {deliveryAreas.map(area => (
            <option key={area.id} value={area.id}>
              {formatDistrictLabel(area.district_name, area.city)} · {formatCurrency(area.fee)}
            </option>
          ))}
        </select>
      </div>
    );
  };

  const renderRegionPicker = () => (
    <div className="space-y-1.5">
      <label className={labelClass}>
        Região / Bairro
        {required && <RequiredMark />}
      </label>

      {selectedDeliveryArea ? (
        <div className="rounded-xl border border-slate-200 bg-white px-3.5 py-3">
          <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">
            Região selecionada
          </p>
          <p className="text-sm font-bold text-slate-800 mt-0.5">
            {formatDistrictLabel(selectedDeliveryArea.district_name, selectedDeliveryArea.city)}
            <span className="font-semibold text-slate-500">
              {' · Taxa '}
              {formatCurrency(selectedDeliveryArea.fee)}
            </span>
          </p>
          <button
            type="button"
            onClick={clearDeliveryArea}
            className="mt-1 text-xs font-bold text-slate-500 hover:text-slate-700 hover:underline"
          >
            Alterar região
          </button>
        </div>
      ) : (
        <>
          <div className="relative min-w-0">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              value={regionQuery}
              onChange={(e) => handleRegionInput(e.target.value)}
              onFocus={() => setRegionFocused(true)}
              onBlur={() => {
                setTimeout(() => setRegionFocused(false), 150);
              }}
              required={required}
              className={`${fieldClass} pl-10 min-w-0`}
            />
          </div>

          {regionSuggestions.length > 0 && regionFocused && (
            <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
              {regionSuggestions.map(area => (
                <button
                  key={area.id}
                  type="button"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => selectDeliveryArea(area)}
                  className="w-full text-left px-3.5 py-3 hover:bg-slate-50 border-b border-slate-100 last:border-b-0"
                >
                  <p className="text-sm font-bold text-slate-800">
                    {formatDistrictLabel(area.district_name, area.city)}
                  </p>
                  <p className="text-xs font-semibold text-[var(--store-primary)] mt-0.5">
                    Taxa {formatCurrency(area.fee)} · ~{area.estimated_time} min
                  </p>
                </button>
              ))}
            </div>
          )}

          <p className="text-xs font-medium text-slate-500">
            Busque entre os bairros atendidos por esta loja.
          </p>
        </>
      )}
    </div>
  );

  const renderCepSection = () => (
    <div className="space-y-2">
      <button
        type="button"
        onClick={() => setShowCepField(current => !current)}
        className="flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-slate-700 hover:underline"
      >
        {showCepField ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
        {showCepField ? 'Ocultar CEP' : 'Tenho CEP (opcional)'}
      </button>

      {showCepField && (
        <div className="space-y-1.5 rounded-xl border border-slate-200 bg-white p-3.5">
          <label className={labelClass}>CEP</label>
          <div className="relative min-w-0">
            <input
              value={cepQuery}
              onChange={(e) => {
                setCepQuery(formatCep(e.target.value));
                setCepError('');
                setCepWarning('');
              }}
              inputMode="numeric"
              maxLength={9}
              className={fieldClass}
            />
            {cepLoading && (
              <Loader2 className="w-4 h-4 text-slate-400 absolute right-3.5 top-1/2 -translate-y-1/2 animate-spin" />
            )}
          </div>
          <p className="text-xs font-medium text-slate-400">
            Preenche rua e bairro automaticamente quando o CEP for encontrado.
          </p>
          {cepError && (
            <p className="text-xs font-medium text-amber-700">{cepError}</p>
          )}
          {cepWarning && (
            <p className="text-xs font-medium text-amber-700">{cepWarning}</p>
          )}
        </div>
      )}
    </div>
  );

  const renderGooglePlacesNotice = () => {
    if (!wantsGoogleAddressFlow || !googleLoadError) {
      return null;
    }

    return (
      <div className="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3">
        <p className="text-xs font-bold text-amber-900">Autocomplete do Google indisponível</p>
        <p className="text-xs font-medium text-amber-800 mt-1">{googleLoadError}</p>
        <p className="text-xs font-medium text-amber-700 mt-2 leading-relaxed">
          No Google Cloud, libere o referrer{' '}
          <span className="font-mono font-bold">{typeof window !== 'undefined' ? `${window.location.origin}/*` : ''}</span>
          {' '}e ative a <strong>Places API</strong>.
        </p>
      </div>
    );
  };

  const renderStreetField = () => {
    if (autoSearch && streetResolved) {
      return (
        <div className="rounded-xl border border-slate-200 bg-white p-3.5 space-y-3">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-[10px] font-black uppercase tracking-wide text-slate-500">Endereço selecionado</p>
              <p className="text-sm font-bold text-slate-900 mt-0.5">{values.address || addressQuery}</p>
              {values.district && (
                <p className="inline-flex mt-2 items-center rounded-full bg-slate-50 border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-700">
                  {formatDistrictLabel(values.district, values.city)}
                  {selectedDeliveryArea && (
                    <span className="text-slate-600 ml-1">
                      · {formatCurrency(selectedDeliveryArea.fee)}
                    </span>
                  )}
                </p>
              )}
            </div>
            <button
              type="button"
              onClick={resetStreetSearch}
              className="shrink-0 text-xs font-bold text-[var(--store-primary)] hover:underline"
            >
              Alterar
            </button>
          </div>

          {!houseNumber && (
            <div className="space-y-1.5">
              <label className={labelClass}>
                Número
                {required && <RequiredMark />}
              </label>
              <input
                ref={numberInputRef}
                value={houseNumber}
                onChange={(e) => handleNumberChange(e.target.value)}
                required={required}
                className={fieldClass}
              />
              <p className="text-xs font-medium text-slate-400">
                Informe o número — o mapa nem sempre traz essa informação.
              </p>
            </div>
          )}
        </div>
      );
    }

    if (regionFirstMode && !wantsGoogleAddressFlow) {
      return (
        <div className="space-y-1.5">
          <label className={labelClass}>
            Rua e número
            {required && <RequiredMark />}
          </label>
          <input
            ref={streetInputRef}
            value={addressQuery}
            onChange={(e) => handleStreetInputChange(e.target.value, { persistAddress: true })}
            onFocus={() => setAddressFocused(true)}
            required={required}
            className={fieldClass}
            autoComplete="off"
          />
          <p className="text-xs font-medium text-slate-400">
            {useGooglePlaces
              ? 'Selecione uma sugestão do Google Maps.'
              : 'Digite o endereço completo para o entregador.'}
          </p>
        </div>
      );
    }

    return (
      <div className="space-y-3">
        <div className="space-y-1.5">
          <label className={labelClass}>
            {autoSearch ? 'Rua e número' : 'Rua / Avenida (com número)'}
            {required && <RequiredMark />}
          </label>
          <div className="relative min-w-0">
            {autoSearch && (
              <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            )}
            <input
              ref={streetInputRef}
              value={addressQuery}
              onChange={(e) => handleStreetInputChange(e.target.value, { persistAddress: !autoSearch })}
              onFocus={() => setAddressFocused(true)}
              onBlur={resolveAddressOnBlur}
              required={required && !autoSearch}
              autoComplete="off"
              className={`${fieldClass} ${autoSearch ? 'pl-10' : ''} min-w-0`}
            />
            {searchLoading && (
              <Loader2 className="w-4 h-4 text-slate-400 absolute right-3.5 top-1/2 -translate-y-1/2 animate-spin" />
            )}
          </div>

          {mapsError && (
            <div className="rounded-xl border border-red-200 bg-red-50 px-3.5 py-3">
              <p className="text-[10px] font-black uppercase tracking-wide text-red-700">Fora da área de entrega</p>
              <p className="text-xs font-medium text-red-800 mt-1 leading-relaxed">{mapsError}</p>
            </div>
          )}

          {wantsGoogleAddressFlow && useGooglePlaces && !mapsError && (
            <p className="text-xs font-medium text-slate-400">
              Escolha uma sugestão do Google na lista.
            </p>
          )}

          {wantsGoogleAddressFlow && !useGooglePlaces && !googleLoadError && (
            <p className="text-xs font-medium text-slate-400">
              Escolha uma sugestão na lista ao digitar o endereço.
            </p>
          )}

          {(autoSearch && !wantsGoogleAddressFlow) && (
            <p className="text-xs font-medium text-slate-400">
              Escolha a sugestão com o bairro correto.
            </p>
          )}

          {autoSearch && (!wantsGoogleAddressFlow || !useGooglePlaces) && addressSuggestions.length > 0 && addressFocused && (
            <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
              {addressSuggestions.map(suggestion => {
                const typedParts = splitStreetAddress(addressQuery);

                return (
                  <button
                    key={suggestion.id}
                    type="button"
                    onMouseDown={() => {
                      selectingSuggestionRef.current = true;
                    }}
                    onClick={() => selectSuggestion(suggestion)}
                    className="w-full text-left px-3.5 py-3 hover:bg-slate-50 border-b border-slate-100 last:border-b-0"
                  >
                    <p className="text-sm font-bold text-slate-800">
                      {suggestion.address || mergeStreetAddress(suggestion.address, suggestion.address_number || typedParts.number)}
                    </p>
                    <p className="text-xs font-semibold text-[var(--store-primary)] mt-0.5">
                      {suggestion.district || 'Bairro não informado'}
                      {suggestion.city ? ` · ${suggestion.city}` : ''}
                    </p>
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {renderGooglePlacesNotice()}
      </div>
    );
  };

  const renderDistrictField = () => (
    <div className="space-y-1.5">
      <label className={labelClass}>
        Bairro
        {required && <RequiredMark />}
      </label>
      <div className="relative min-w-0">
        {autoSearch && (
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
        )}
        <input
          value={districtQuery}
          onChange={(e) => handleDistrictInput(e.target.value)}
          onFocus={() => setDistrictFocused(true)}
          onBlur={() => {
            setTimeout(() => setDistrictFocused(false), 150);
          }}
          required={required}
          className={`${fieldClass} ${autoSearch ? 'pl-10' : ''} min-w-0`}
        />
        {districtSearchLoading && (
          <Loader2 className="w-4 h-4 text-slate-400 absolute right-3.5 top-1/2 -translate-y-1/2 animate-spin" />
        )}
      </div>

      {districtSearchError && (
        <p className="text-xs font-medium text-amber-700">{districtSearchError}</p>
      )}

      {autoSearch && districtSuggestions.length > 0 && districtFocused && (
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          {districtSuggestions.map(item => (
            <button
              key={item.id}
              type="button"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => selectDistrictSuggestion(item)}
              className="w-full text-left px-3.5 py-3 hover:bg-slate-50 border-b border-slate-100 last:border-b-0"
            >
              <p className="text-sm font-bold text-slate-800">{item.district_name}</p>
              <p className="text-xs text-slate-500 mt-0.5">{item.label}</p>
            </button>
          ))}
        </div>
      )}
    </div>
  );

  return (
    <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 space-y-4">
      <div className="flex items-start gap-2.5">
        <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white border border-slate-200 text-[var(--store-primary)]">
          <MapPin size={16} />
        </div>
        <div>
          <h4 className="text-sm font-black text-slate-900">Endereço de entrega</h4>
          <p className="text-xs font-medium text-slate-500 mt-0.5">{headerHint}</p>
          {isGoogleMapsEnabled() && !googleReady && !googleLoadError && (
            <p className="text-xs font-medium text-slate-400 mt-1">Carregando autocomplete...</p>
          )}
        </div>
      </div>

      {regionFirstMode && renderRegionPicker()}
      {renderStreetField()}
      {renderCepSection()}
      {renderDeliveryAreaSection()}

      <div className="space-y-1.5">
        <label className={labelClass}>Complemento</label>
        <input
          value={values.address_complement || ''}
          onChange={(e) => updateField('address_complement', e.target.value)}
          className={fieldClass}
        />
      </div>

      {showLocationButton && !wantsGoogleAddressFlow && (
        <button
          type="button"
          onClick={useCurrentLocation}
          disabled={locationLoading}
          className="w-full h-10 rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 font-bold text-sm flex items-center justify-center gap-2 disabled:opacity-50"
        >
          {locationLoading ? <Loader2 className="animate-spin text-[var(--store-primary)]" size={16} /> : <Navigation size={16} />}
          Usar minha localização
        </button>
      )}

      {(values.latitude && values.longitude) && !wantsGoogleAddressFlow && (
        <div className="px-3.5 py-2.5 rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-xs font-bold flex items-center gap-2">
          <MapPin size={14} className="text-[var(--store-primary)]" />
          Localização capturada. Confira se o endereço está correto.
        </div>
      )}

      {showDeliverySummary && (
        <div className="rounded-xl bg-white border border-slate-200 px-3.5 py-3">
          <p className="text-[10px] font-black text-slate-400 uppercase tracking-wide">Taxa de entrega</p>
          <p className="text-sm font-black text-slate-900 mt-1">
            {deliveryAreasLoading
              ? 'Carregando regiões...'
              : deliveryFee === 0 ? 'A confirmar' : formatCurrency(deliveryFee)}
          </p>
          {selectedDeliveryArea && (
            <p className="text-xs font-semibold text-slate-500 mt-1">
              Prazo estimado: {selectedDeliveryArea.estimated_time} min
            </p>
          )}
        </div>
      )}
    </div>
  );
}
