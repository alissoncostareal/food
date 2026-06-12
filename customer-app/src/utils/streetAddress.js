export const mergeStreetAddress = (address, addressNumber) => {
  const street = String(address || '').trim();
  const number = String(addressNumber || '').trim();

  if (!street) return number;
  if (!number) return street;

  const escaped = number.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  if (new RegExp(`[,\\s]+${escaped}$`, 'i').test(street)) {
    return street;
  }

  return `${street}, ${number}`;
};

export const splitStreetAddress = (line) => {
  const trimmed = String(line || '').trim();

  if (!trimmed) {
    return { street: '', number: '', line: '' };
  }

  const match = trimmed.match(/^(.*?)[,\s]+((?:\d[\w\-/]*|s\/n|sn))$/iu);

  if (match) {
    const street = match[1].trim().replace(/,\s*$/, '');
    const number = match[2].trim().toUpperCase();

    return {
      street: street || trimmed,
      number,
      line: mergeStreetAddress(street || trimmed, number)
    };
  }

  return { street: trimmed, number: '', line: trimmed };
};

export const hasStreetNumber = (line) => Boolean(splitStreetAddress(line).number);

export const formatStreetLine = (...parts) => {
  return mergeStreetAddress(parts[0], parts[1]);
};

export const normalizeLocation = (value) => {
  return String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
};
