export function getDeliveryFeeEstimate(store, deliverySummary, { fulfillmentType = 'delivery', selectedArea = null } = {}) {
  if (fulfillmentType === 'pickup') {
    return {
      fee: 0,
      label: 'Retirada',
      totalUsesEstimate: false,
    };
  }

  if (selectedArea) {
    const fee = Number(selectedArea.fee ?? 0);

    return {
      fee,
      label: fee === 0 ? 'Grátis' : null,
      totalUsesEstimate: false,
    };
  }

  if (deliverySummary?.mode === 'areas') {
    return {
      fee: 0,
      label: 'A calcular',
      totalUsesEstimate: true,
    };
  }

  const fee = Number(store?.delivery_fee ?? deliverySummary?.fee ?? 0);

  return {
    fee,
    label: fee === 0 ? 'Grátis' : null,
    totalUsesEstimate: false,
  };
}
