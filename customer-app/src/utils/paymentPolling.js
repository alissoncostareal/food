import api from '../services/api';
import { normalizeBrazilPhone } from './customerSession';

export const PAYMENT_POLL_FAST_MS = 300;
export const PAYMENT_POLL_MS = Number(import.meta.env.VITE_PAYMENTS_POLLING_MS || 600);
export const PAYMENT_RESUME_BURST_MS = 150;
export const PAYMENT_RESUME_BURST_COUNT = 40;

export const buildPaymentPollParams = (customerPhone) => ({
  phone: normalizeBrazilPhone(customerPhone),
});

export async function fetchOrderPaymentStatus(orderId, customerPhone) {
  const { data } = await api.get(`/checkout/orders/${orderId}/payment`, {
    params: {
      ...buildPaymentPollParams(customerPhone),
      _t: Date.now(),
    },
    headers: {
      'Cache-Control': 'no-cache',
      Pragma: 'no-cache',
    },
  });

  return data;
}

export function isOrderPaymentPaid(data) {
  if (!data) {
    return false;
  }

  const paymentStatus = data?.payment?.status || data?.order?.payment_status;

  return paymentStatus === 'paid' || paymentStatus === 'approved';
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
  let pollTimerId = null;
  let burstIntervalId = null;
  let pollInFlight = false;
  let pollCount = 0;

  const clearBurst = () => {
    if (burstIntervalId) {
      clearInterval(burstIntervalId);
      burstIntervalId = null;
    }
  };

  const clearPollTimer = () => {
    if (pollTimerId) {
      clearTimeout(pollTimerId);
      pollTimerId = null;
    }
  };

  const stopAll = () => {
    clearPollTimer();
    clearBurst();
  };

  const scheduleNextPoll = () => {
    if (cancelled || !isActive()) {
      return;
    }

    clearPollTimer();

    const delay = pollCount < 120 ? PAYMENT_POLL_FAST_MS : PAYMENT_POLL_MS;

    pollTimerId = setTimeout(() => {
      void poll();
    }, delay);
  };

  const poll = async () => {
    if (cancelled || !isActive()) {
      return;
    }

    if (pollInFlight) {
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
    } catch (error) {
      if (!cancelled && isActive()) {
        onPaid?.(null, {
          error,
          message: 'Não foi possível verificar o pagamento. Tentando novamente...',
        });
      }
    } finally {
      pollInFlight = false;

      if (!cancelled && isActive()) {
        scheduleNextPoll();
      }
    }
  };

  const burstPoll = () => {
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

  const resume = () => {
    if (cancelled || !isActive()) {
      return;
    }

    burstPoll();
  };

  document.addEventListener('visibilitychange', resume);
  window.addEventListener('focus', resume);
  window.addEventListener('pageshow', resume);
  document.addEventListener('pointerdown', resume, { passive: true });
  document.addEventListener('touchstart', resume, { passive: true });
  document.addEventListener('touchend', resume, { passive: true });
  document.addEventListener('click', resume, { passive: true });

  burstPoll();

  return () => {
    cancelled = true;
    stopAll();
    document.removeEventListener('visibilitychange', resume);
    window.removeEventListener('focus', resume);
    window.removeEventListener('pageshow', resume);
    document.removeEventListener('pointerdown', resume);
    document.removeEventListener('touchstart', resume);
    document.removeEventListener('touchend', resume);
    document.removeEventListener('click', resume);
  };
}
