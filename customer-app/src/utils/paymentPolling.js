import api from '../services/api';
import { normalizeBrazilPhone } from './customerSession';

export const PAYMENT_POLL_FAST_MS = 350;
export const PAYMENT_POLL_MS = Number(import.meta.env.VITE_PAYMENTS_POLLING_MS || 700);
export const PAYMENT_POLL_BURST_COUNT = 200;
export const PAYMENT_RESUME_BURST_MS = 200;
export const PAYMENT_RESUME_BURST_COUNT = 30;

export const buildPaymentPollParams = (customerPhone) => ({
  phone: normalizeBrazilPhone(customerPhone),
});

export async function fetchOrderPaymentStatus(orderId, customerPhone) {
  const { data } = await api.get(`/checkout/orders/${orderId}/payment`, {
    params: {
      ...buildPaymentPollParams(customerPhone),
      _t: Date.now(),
    },
  });

  return data;
}

export function isOrderPaymentPaid(data) {
  if (!data) {
    return false;
  }

  const paymentStatus = data?.payment?.status || data?.order?.payment_status;

  return paymentStatus === 'paid';
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
  let intervalId = null;
  let burstIntervalId = null;
  let pollInFlight = false;
  let pollCount = 0;

  const clearBurst = () => {
    if (burstIntervalId) {
      clearInterval(burstIntervalId);
      burstIntervalId = null;
    }
  };

  const stopInterval = () => {
    if (intervalId) {
      clearInterval(intervalId);
      intervalId = null;
    }
  };

  const stopAll = () => {
    stopInterval();
    clearBurst();
  };

  const poll = async () => {
    if (cancelled || !isActive() || pollInFlight) {
      return;
    }

    pollInFlight = true;
    pollCount += 1;

    try {
      const data = await fetchOrderPaymentStatus(orderId, customerPhone);

      if (cancelled || !isActive()) {
        return;
      }

      const nextStatus = data?.payment?.status || data?.order?.payment_status;

      if (nextStatus === 'paid') {
        stopAll();
        onPaid?.(data, { error: null });
        return;
      }

      if (nextStatus === 'expired' || nextStatus === 'failed') {
        stopAll();
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
    } finally {
      pollInFlight = false;
    }
  };

  const startInterval = () => {
    if (cancelled || intervalId) {
      return;
    }

    const delay = pollCount < PAYMENT_POLL_BURST_COUNT ? PAYMENT_POLL_FAST_MS : PAYMENT_POLL_MS;

    intervalId = setInterval(() => {
      if (cancelled || !isActive()) {
        return;
      }

      void poll();
    }, delay);
  };

  const resumePoll = () => {
    if (cancelled || !isActive()) {
      return;
    }

    void poll();

    clearBurst();

    let burstCount = 0;
    burstIntervalId = setInterval(() => {
      if (cancelled || !isActive()) {
        clearBurst();
        return;
      }

      burstCount += 1;
      void poll();

      if (burstCount >= PAYMENT_RESUME_BURST_COUNT) {
        clearBurst();
      }
    }, PAYMENT_RESUME_BURST_MS);
  };

  const handleVisibility = () => {
    if (document.visibilityState === 'visible') {
      resumePoll();
      startInterval();
      return;
    }

    stopInterval();
    clearBurst();
  };

  const handleResume = () => {
    if (document.visibilityState !== 'visible') {
      return;
    }

    resumePoll();
    startInterval();
  };

  document.addEventListener('visibilitychange', handleVisibility);
  window.addEventListener('focus', handleResume);
  window.addEventListener('pageshow', handleResume);
  document.addEventListener('pointerdown', handleResume, { passive: true });
  document.addEventListener('touchstart', handleResume, { passive: true });
  document.addEventListener('click', handleResume, { passive: true });

  if (document.visibilityState === 'visible') {
    resumePoll();
    startInterval();
  }

  return () => {
    cancelled = true;
    stopAll();
    document.removeEventListener('visibilitychange', handleVisibility);
    window.removeEventListener('focus', handleResume);
    window.removeEventListener('pageshow', handleResume);
    document.removeEventListener('pointerdown', handleResume);
    document.removeEventListener('touchstart', handleResume);
    document.removeEventListener('click', handleResume);
  };
}
