import api from '../services/api';
import { normalizeBrazilPhone } from './customerSession';

export const PAYMENT_POLL_FAST_MS = 350;
export const PAYMENT_POLL_MS = Number(import.meta.env.VITE_PAYMENTS_POLLING_MS || 700);
export const PAYMENT_POLL_BURST_COUNT = 200;

export const buildPaymentPollParams = (customerPhone) => ({
  phone: normalizeBrazilPhone(customerPhone),
});

export async function fetchOrderPaymentStatus(orderId, customerPhone) {
  const { data } = await api.get(`/checkout/orders/${orderId}/payment`, {
    params: buildPaymentPollParams(customerPhone),
  });

  return data;
}

export function startPaymentStatusPolling({
  orderId,
  customerPhone,
  onPaid,
  onTerminal,
  isActive = () => true,
}) {
  if (!orderId) {
    return () => {};
  }

  let cancelled = false;
  let timeoutId = null;
  let pollCount = 0;

  const clearScheduledPoll = () => {
    if (timeoutId) {
      clearTimeout(timeoutId);
      timeoutId = null;
    }
  };

  const scheduleNext = (delay) => {
    clearScheduledPoll();
    timeoutId = setTimeout(() => {
      void poll();
    }, delay);
  };

  const poll = async () => {
    if (cancelled || !isActive()) {
      return;
    }

    pollCount += 1;

    try {
      const data = await fetchOrderPaymentStatus(orderId, customerPhone);

      if (cancelled || !isActive()) {
        return;
      }

      const nextStatus = data?.payment?.status || data?.order?.payment_status;

      if (nextStatus === 'paid') {
        onPaid?.(data, { error: null });
        return;
      }

      if (nextStatus === 'expired' || nextStatus === 'failed') {
        onTerminal?.(data, nextStatus, { error: null });
        return;
      }

      onPaid?.(null, { error: null, data });
    } catch (error) {
      if (!cancelled && isActive()) {
        onPaid?.(null, {
          error,
          message: 'Não foi possível verificar o pagamento. Tentando novamente...',
        });
      }
    }

    if (cancelled || !isActive()) {
      return;
    }

    scheduleNext(pollCount < PAYMENT_POLL_BURST_COUNT ? PAYMENT_POLL_FAST_MS : PAYMENT_POLL_MS);
  };

  const handleVisibility = () => {
    if (document.visibilityState === 'visible' && !cancelled && isActive()) {
      clearScheduledPoll();
      void poll();
    }
  };

  const handleFocus = () => {
    if (!cancelled && isActive()) {
      clearScheduledPoll();
      void poll();
    }
  };

  document.addEventListener('visibilitychange', handleVisibility);
  window.addEventListener('focus', handleFocus);
  void poll();

  return () => {
    cancelled = true;
    clearScheduledPoll();
    document.removeEventListener('visibilitychange', handleVisibility);
    window.removeEventListener('focus', handleFocus);
  };
}
