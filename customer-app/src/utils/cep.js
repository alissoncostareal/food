export const onlyCepDigits = (value) => String(value || '').replace(/\D/g, '').slice(0, 8);

export const isValidCep = (value) => onlyCepDigits(value).length === 8;

export const formatCep = (value) => {
  const digits = onlyCepDigits(value);

  if (digits.length <= 5) {
    return digits;
  }

  return `${digits.slice(0, 5)}-${digits.slice(5)}`;
};

export const filterDeliveryAreas = (areas, query, limit = 8) => {
  if (!Array.isArray(areas) || areas.length === 0) {
    return [];
  }

  const term = String(query || '')
    .split(',')[0]
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  if (!term) {
    return areas.slice(0, limit);
  }

  return areas
    .filter((area) => {
      const district = String(area.district_name || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
      const city = String(area.city || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

      return district.includes(term)
        || city.includes(term)
        || `${district} ${city}`.includes(term);
    })
    .slice(0, limit);
};
