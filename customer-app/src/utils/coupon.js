export const calculateCouponDiscount = (couponData, currentSubtotal) => {
  if (!couponData || currentSubtotal <= 0) return 0;

  const minOrderAmount = Number(couponData.min_order_amount || 0);

  if (minOrderAmount > 0 && currentSubtotal < minOrderAmount) {
    return 0;
  }

  let discount = 0;

  if (couponData.type === 'percentage') {
    discount = currentSubtotal * (Number(couponData.value || 0) / 100);

    if (couponData.max_discount_amount !== null && couponData.max_discount_amount !== undefined) {
      discount = Math.min(discount, Number(couponData.max_discount_amount));
    }
  } else {
    discount = Number(couponData.value || couponData.discount_amount || 0);
  }

  return Math.min(discount, currentSubtotal);
};
