import { onlyDigits } from './customerSession';

export const nationalPhoneDigits = (value) => {
  const digits = onlyDigits(value);

  if (digits.startsWith('55') && digits.length > 11) {
    return digits.slice(2, 13);
  }

  return digits.slice(0, 11);
};

export const formatBrazilPhoneInput = (value) => {
  const digits = nationalPhoneDigits(value);

  if (digits.length === 0) {
    return '';
  }

  if (digits.length <= 2) {
    return `(${digits}`;
  }

  if (digits.length <= 6) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
  }

  if (digits.length <= 10) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
  }

  return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
};

export const isValidBrazilPhone = (value) => {
  const digits = nationalPhoneDigits(value);

  return digits.length === 10 || digits.length === 11;
};
