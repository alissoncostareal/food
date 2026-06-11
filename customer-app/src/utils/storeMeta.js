const formatMoney = (value) => Number(value || 0).toFixed(2).replace('.', ',');

export const buildDeliveryMetaLabel = (deliverySummary, deliveryFee = 0) => {
  if (deliverySummary?.mode === 'areas') {
    const min = Number(deliverySummary.min_fee ?? 0);
    const max = Number(deliverySummary.max_fee ?? 0);
    const count = Number(deliverySummary.count ?? 0);

    if (count > 0) {
      const areaLabel = count === 1 ? '1 bairro' : `${count} bairros`;

      if (min === 0 && max === 0) {
        return `Entrega grátis · ${areaLabel}`;
      }

      if (min === max) {
        return `Entrega R$ ${formatMoney(min)} · ${areaLabel}`;
      }

      return `Entrega a partir de R$ ${formatMoney(min)} · ${areaLabel}`;
    }

    if (min === 0 && max === 0) {
      return 'Entrega grátis';
    }

    if (min === max) {
      return `Entrega R$ ${formatMoney(min)}`;
    }

    return `Entrega a partir de R$ ${formatMoney(min)}`;
  }

  const fee = Number(deliveryFee || deliverySummary?.fee || 0);

  if (fee === 0) {
    return 'Entrega grátis';
  }

  return `Entrega R$ ${formatMoney(fee)}`;
};

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
