export const onlyDigits = (value) => String(value || '').replace(/\D/g, '');

export const formatCardNumber = (value) => {
  const digits = onlyDigits(value).slice(0, 16);

  return digits.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
};

export const formatCpf = (value) => {
  const digits = onlyDigits(value).slice(0, 11);

  return digits
    .replace(/(\d{3})(\d)/, '$1.$2')
    .replace(/(\d{3})(\d)/, '$1.$2')
    .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
};

export const formatCardExpiry = (month, year) => {
  const monthDigits = onlyDigits(month).slice(0, 2);
  const yearDigits = onlyDigits(year).slice(0, 4);

  return { month: monthDigits, year: yearDigits };
};

export const formatCvv = (value) => onlyDigits(value).slice(0, 4);
