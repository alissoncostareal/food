import {
  fetchOrderPaymentStatus,
  isOrderPaymentPaid,
  startPaymentStatusPolling,
} from './paymentPolling';
import { normalizeBrazilPhone } from './customerSession';
import { subscribeToOrderPayment } from './orderPaymentRealtime';

const sessionKey = (storeSlug) => `pix_checkout_${storeSlug}`;
const PIX_SYNC_MS = 500;

const safeParse = (value) => {
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
};

const subscribers = new Set();
const paidHandlers = new Map();
let syncLoopSlug = null;
let syncLoopTimerId = null;
let syncLoopResumeCleanup = null;
let activeWatcher = null;

const clearSyncLoopResumeListeners = () => {
  if (typeof syncLoopResumeCleanup === 'function') {
    syncLoopResumeCleanup();
    syncLoopResumeCleanup = null;
  }
};

const attachSyncLoopResumeListeners = (storeSlug) => {
  clearSyncLoopResumeListeners();

  const resume = () => {
    void syncPixCheckoutSession(storeSlug, { silent: true });
  };

  document.addEventListener('visibilitychange', resume);
  window.addEventListener('focus', resume);
  window.addEventListener('pageshow', resume);
  document.addEventListener('pointerdown', resume, { passive: true });
  document.addEventListener('touchstart', resume, { passive: true });
  document.addEventListener('touchend', resume, { passive: true });

  syncLoopResumeCleanup = () => {
    document.removeEventListener('visibilitychange', resume);
    window.removeEventListener('focus', resume);
    window.removeEventListener('pageshow', resume);
    document.removeEventListener('pointerdown', resume);
    document.removeEventListener('touchstart', resume);
    document.removeEventListener('touchend', resume);
  };
};

const notifyPixCheckoutUpdate = (storeSlug, session) => {
  subscribers.forEach((listener) => {
    try {
      listener(storeSlug, session);
    } catch {
      // Ignora falha de listener.
    }
  });

  if (session?.status === 'paid') {
    window.dispatchEvent(new CustomEvent('pix-checkout-paid', {
      detail: { storeSlug, session },
    }));
  }
};

const notifyPaidHandlers = (orderId, payload) => {
  const handler = paidHandlers.get(orderId);
  if (typeof handler === 'function') {
    try {
      handler(payload);
    } catch {
      // Ignora falha de listener.
    }
  }
};

export function registerPixPaidHandler(orderId, handler) {
  if (!orderId || typeof handler !== 'function') {
    return () => {};
  }

  paidHandlers.set(orderId, handler);

  const sessionSlug = syncLoopSlug;
  if (sessionSlug) {
    const session = readPixCheckoutSession(sessionSlug);
    if (session?.orderId === orderId && session.status === 'paid') {
      handler({
        order: session.order,
        payment: session.payment,
        whatsapp_url: session.whatsappUrl || session.order?.whatsapp_url,
      });
    }
  }

  return () => {
    if (paidHandlers.get(orderId) === handler) {
      paidHandlers.delete(orderId);
    }
  };
}

export function subscribePixCheckoutUpdates(listener) {
  subscribers.add(listener);

  return () => {
    subscribers.delete(listener);
  };
}

export function readPixCheckoutSession(storeSlug) {
  if (!storeSlug) return null;

  return safeParse(sessionStorage.getItem(sessionKey(storeSlug)));
}

export function writePixCheckoutSession(storeSlug, payload) {
  if (!storeSlug) return;

  const session = {
    ...payload,
    updatedAt: Date.now(),
  };

  sessionStorage.setItem(sessionKey(storeSlug), JSON.stringify(session));
  notifyPixCheckoutUpdate(storeSlug, session);
}

export function clearPixCheckoutSession(storeSlug) {
  if (!storeSlug) return;

  sessionStorage.removeItem(sessionKey(storeSlug));
  stopPixSyncLoop();
  notifyPixCheckoutUpdate(storeSlug, null);
}

export function saveAwaitingPixCheckout(storeSlug, { order, payment, customerPhone }) {
  writePixCheckoutSession(storeSlug, {
    status: 'awaiting',
    orderId: order?.id,
    customerPhone: normalizeBrazilPhone(customerPhone || order?.customer_phone),
    order,
    payment,
  });

  startPixSyncLoop(storeSlug);
}

export function savePaidPixCheckout(storeSlug, { order, payment, customerPhone, whatsappUrl }) {
  const session = {
    status: 'paid',
    orderId: order?.id,
    customerPhone: normalizeBrazilPhone(customerPhone || order?.customer_phone),
    order: {
      ...order,
      payment_status: 'paid',
      whatsapp_url: whatsappUrl || order?.whatsapp_url || null,
    },
    payment: {
      ...(payment || {}),
      status: 'paid',
    },
    whatsappUrl: whatsappUrl || order?.whatsapp_url || null,
  };

  writePixCheckoutSession(storeSlug, session);
  stopPixSyncLoop();

  notifyPaidHandlers(order?.id, {
    order: session.order,
    payment: session.payment,
    whatsapp_url: session.whatsappUrl,
  });
}

export function startPixSyncLoop(storeSlug) {
  if (!storeSlug) return;

  if (syncLoopSlug === storeSlug && syncLoopTimerId) {
    return;
  }

  stopPixSyncLoop();
  syncLoopSlug = storeSlug;
  attachSyncLoopResumeListeners(storeSlug);

  const tick = async () => {
    if (syncLoopSlug !== storeSlug) {
      return;
    }

    await syncPixCheckoutSession(storeSlug, { silent: true });

    if (syncLoopSlug === storeSlug) {
      syncLoopTimerId = setTimeout(tick, PIX_SYNC_MS);
    }
  };

  void tick();
}

export function stopPixSyncLoop() {
  if (syncLoopTimerId) {
    clearTimeout(syncLoopTimerId);
    syncLoopTimerId = null;
  }

  syncLoopSlug = null;
  clearSyncLoopResumeListeners();
}

const stopActiveWatcher = () => {
  if (typeof activeWatcher?.stop === 'function') {
    activeWatcher.stop();
  }

  activeWatcher = null;
};

export function stopPixPaymentWatcher() {
  stopActiveWatcher();
}

export function startPixPaymentWatcher({
  orderId,
  customerPhone,
  onPaid,
  onTerminal,
}) {
  if (!orderId) {
    return stopPixPaymentWatcher;
  }

  const phone = normalizeBrazilPhone(customerPhone);

  if (
    activeWatcher
    && activeWatcher.orderId === orderId
    && activeWatcher.customerPhone === phone
  ) {
    activeWatcher.onPaid = onPaid;
    activeWatcher.onTerminal = onTerminal;
    return stopPixPaymentWatcher;
  }

  stopActiveWatcher();

  let settled = false;

  const handlePaid = (data) => {
    if (settled || !isOrderPaymentPaid(data)) {
      return;
    }

    settled = true;
    stopActiveWatcher();
    onPaid?.(data);
  };

  const handleTerminal = (data, status) => {
    if (settled) {
      return;
    }

    settled = true;
    stopActiveWatcher();
    onTerminal?.(data, status);
  };

  const stopRealtime = subscribeToOrderPayment({
    orderId,
    customerPhone: phone,
    onConfirmed: handlePaid,
  });

  const stopPolling = startPaymentStatusPolling({
    orderId,
    customerPhone: phone,
    isActive: () => !settled,
    onPaid: (data, meta) => {
      if (meta?.error || !data) {
        return;
      }

      handlePaid(data);
    },
    onTerminal: (data, status) => {
      handleTerminal(data, status);
    },
  });

  const resume = () => {
    if (settled) {
      return;
    }

    void fetchOrderPaymentStatus(orderId, phone)
      .then((data) => {
        if (isOrderPaymentPaid(data)) {
          handlePaid(data);
        }
      })
      .catch(() => {});
  };

  document.addEventListener('visibilitychange', resume);
  window.addEventListener('focus', resume);
  window.addEventListener('pageshow', resume);
  document.addEventListener('pointerdown', resume, { passive: true });
  document.addEventListener('touchstart', resume, { passive: true });
  document.addEventListener('touchend', resume, { passive: true });

  activeWatcher = {
    orderId,
    customerPhone: phone,
    onPaid,
    onTerminal,
    stop: () => {
      settled = true;
      stopPolling();
      stopRealtime();
      document.removeEventListener('visibilitychange', resume);
      window.removeEventListener('focus', resume);
      window.removeEventListener('pageshow', resume);
      document.removeEventListener('pointerdown', resume);
      document.removeEventListener('touchstart', resume);
      document.removeEventListener('touchend', resume);
    },
  };

  resume();

  return stopPixPaymentWatcher;
}

export async function syncPixCheckoutSession(storeSlug, { silent = false } = {}) {
  const session = readPixCheckoutSession(storeSlug);

  if (!session?.orderId) {
    return session;
  }

  if (session.status === 'paid') {
    return session;
  }

  try {
    const data = await fetchOrderPaymentStatus(session.orderId, session.customerPhone);

    if (isOrderPaymentPaid(data)) {
      const order = {
        ...(data.order || session.order),
        payment_status: 'paid',
        whatsapp_url: data.whatsapp_url || data.order?.whatsapp_url || session.order?.whatsapp_url || null,
      };

      savePaidPixCheckout(storeSlug, {
        order,
        payment: data.payment,
        customerPhone: session.customerPhone,
        whatsappUrl: order.whatsapp_url,
      });

      return readPixCheckoutSession(storeSlug);
    }
  } catch {
    if (!silent) {
      // Mantém sessão aguardando.
    }
  }

  return session;
}
