import api from '../services/api';
import { normalizeBrazilPhone } from './customerSession';

export const PAYMENT_POLL_FAST_MS = Number(import.meta.env.VITE_PAYMENTS_POLL_FAST_MS || 2500);
export const PAYMENT_POLL_MS = Number(import.meta.env.VITE_PAYMENTS_POLLING_MS || 5000);
export const PAYMENT_RESUME_BURST_MS = 400;
export const PAYMENT_RESUME_BURST_COUNT = 8;

export const buildPaymentPollParams = (customerPhone) => ({
  phone: normalizeBrazilPhone(customerPhone),
});

export function getPaymentCheckErrorMessage(error) {
  if (error?.code === 'MISSING_PHONE') {
    return 'Telefone do pedido não encontrado. Feche e finalize novamente.';
  }

  if (error?.code === 'ECONNABORTED') {
    return 'A verificação demorou demais. Tente novamente.';
  }

  const status = error?.response?.status;

  if (status === 404) {
    return 'Não encontramos este pedido com o telefone informado. Feche e finalize novamente.';
  }

  if (status === 429) {
    return 'Muitas verificações seguidas. Aguarde alguns segundos e tente de novo.';
  }

  if (status >= 500) {
    return 'Servidor temporariamente indisponível. Tente novamente em instantes.';
  }

  if (!error?.response) {
    return 'Sem conexão com o servidor. Confira sua internet e tente novamente.';
  }

  return 'Não foi possível verificar agora. Tente novamente.';
}

export async function fetchOrderPaymentStatus(orderId, customerPhone) {
  const phone = normalizeBrazilPhone(customerPhone);

  if (!phone) {
    throw Object.assign(new Error('Telefone do pedido não informado.'), {
      code: 'MISSING_PHONE',
    });
  }

  const { data } = await api.get(`/checkout/orders/${orderId}/payment`, {
    params: {
      phone,
      _t: Date.now(),
    },
    timeout: 45_000,
  });

  return data;
}

export function resolveOrderPaymentStatus(data) {
  return data?.payment?.status || data?.order?.payment_status || null;
}

export function isOrderPaymentPaid(data) {
  if (!data) {
    return false;
  }

  const paymentStatus = resolveOrderPaymentStatus(data);

  return paymentStatus === 'paid' || paymentStatus === 'approved';
}

export function isTerminalPixPaymentStatus(data) {
  const paymentStatus = resolveOrderPaymentStatus(data);

  return paymentStatus === 'expired' || paymentStatus === 'failed';
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
  let rateLimitedUntil = 0;

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

  const scheduleNextPoll = (delayOverride) => {
    if (cancelled || !isActive()) {
      return;
    }

    clearPollTimer();

    const now = Date.now();
    const rateLimitDelay = Math.max(0, rateLimitedUntil - now);
    const baseDelay = pollCount < 24 ? PAYMENT_POLL_FAST_MS : PAYMENT_POLL_MS;
    const delay = Math.max(delayOverride ?? baseDelay, rateLimitDelay);

    pollTimerId = setTimeout(() => {
      void poll();
    }, delay);
  };

  const poll = async () => {
    if (cancelled || !isActive()) {
      return;
    }

    if (pollInFlight) {
      scheduleNextPoll();
      return;
    }

    pollInFlight = true;
    pollCount += 1;

    try {
      const data = await fetchOrderPaymentStatus(orderId, customerPhone);

      if (cancelled || !isActive()) {
        return;
      }

      const nextStatus = resolveOrderPaymentStatus(data);

      if (nextStatus === 'paid' || nextStatus === 'approved') {
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
        if (error?.response?.status === 429) {
          rateLimitedUntil = Date.now() + 15_000;
        }

        onPaid?.(null, {
          error,
          message: getPaymentCheckErrorMessage(error),
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

  scheduleNextPoll(0);

  return () => {
    cancelled = true;
    stopAll();
    document.removeEventListener('visibilitychange', resume);
    window.removeEventListener('focus', resume);
    window.removeEventListener('pageshow', resume);
  };
}
