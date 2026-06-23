export const buildHoursMetaLabel = (store) => {
  const status = store?.opening_status;
  const isOpen = Boolean(status?.is_open ?? store?.is_open);

  if (isOpen) {
    if (status?.closes_at) {
      return `Aberto até ${status.closes_at}`;
    }

    if (status?.hours_hint) {
      return status.hours_hint;
    }

    return 'Aberto agora';
  }

  if (status?.hours_hint) {
    return status.hours_hint;
  }

  const nextLabel = status?.next_opening?.label || store?.next_opening?.label;

  if (nextLabel) {
    return `Abre ${nextLabel.toLowerCase()}`;
  }

  return status?.message || store?.status_message || 'Fechado';
};
