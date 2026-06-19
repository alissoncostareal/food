import { normalizeLocation } from './streetAddress';

export const formatDistrictLabel = (district, city) => {
  return [district, city].filter(Boolean).join(', ');
};

export const districtCandidates = (district) => {
  const value = String(district || '').trim();

  if (!value) {
    return [];
  }

  const candidates = [value];

  if (value.includes(',')) {
    const parts = value.split(',').map((part) => part.trim()).filter(Boolean);

    if (parts.length > 0) {
      candidates.push(parts[0], parts[parts.length - 1]);
    }
  }

  return [...new Set(candidates)];
};

const namesMatch = (left, right) => {
  const a = normalizeLocation(left);
  const b = normalizeLocation(right);

  if (!a || !b) {
    return false;
  }

  if (a === b) {
    return true;
  }

  return a.includes(b) || b.includes(a);
};

const districtMatchesArea = (area, district) => {
  if (!namesMatch(area.district_name, district)) {
    const combined = formatDistrictLabel(area.district_name, area.city);
    return namesMatch(combined, district);
  }

  return true;
};

const cityMatchesArea = (areaCity, inputCity) => {
  if (!areaCity || !inputCity) {
    return true;
  }

  return namesMatch(areaCity, inputCity);
};

export const matchDeliveryArea = (deliveryAreas, district, city) => {
  if (!deliveryAreas?.length) {
    return null;
  }

  for (const candidate of districtCandidates(district)) {
    const match = deliveryAreas.find((area) => {
      if (!districtMatchesArea(area, candidate)) {
        return false;
      }

      return cityMatchesArea(area.city, city);
    });

    if (match) {
      return match;
    }
  }

  return null;
};
