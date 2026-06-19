import { buildDeliveryMetaLabel } from './storeMeta';

export function getDeliveryFeeEstimate(store, deliverySummary, { fulfillmentType = 'delivery', selectedArea = null } = {}) {
  if (fulfillmentType === 'pickup') {
    return {
      fee: 0,
      label: 'Retirada',
      isEstimate: false,
      totalUsesEstimate: false,
    };
  }

  if (selectedArea) {
    const fee = Number(selectedArea.fee ?? 0);

    return {
      fee,
      label: fee === 0 ? 'Grátis' : null,
      isEstimate: false,
      totalUsesEstimate: false,
    };
  }

  if (deliverySummary?.mode === 'areas') {
    const minFee = Number(deliverySummary.min_fee ?? 0);
    const maxFee = Number(deliverySummary.max_fee ?? minFee);
    const hasRange = minFee !== maxFee;

    return {
      fee: minFee,
      label: buildDeliveryMetaLabel(deliverySummary, store?.delivery_fee),
      isEstimate: hasRange,
      totalUsesEstimate: hasRange,
    };
  }

  const fee = Number(store?.delivery_fee ?? deliverySummary?.fee ?? 0);

  return {
    fee,
    label: fee === 0 ? 'Grátis' : null,
    isEstimate: false,
    totalUsesEstimate: false,
  };
}
