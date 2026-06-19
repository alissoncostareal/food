import Pusher from 'pusher-js';
import { fetchOrderPaymentStatus, isOrderPaymentPaid } from './paymentPolling';

let sharedClient = null;

const getPusherClient = () => {
  const key = import.meta.env.VITE_PUSHER_APP_KEY;
  const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER;

  if (!key || !cluster) {
    return null;
  }

  if (!sharedClient) {
    sharedClient = new Pusher(key, {
      cluster,
      forceTLS: (import.meta.env.VITE_PUSHER_SCHEME || 'https') === 'https',
      enabledTransports: ['ws', 'wss'],
      activityTimeout: 10000,
    });
  }

  return sharedClient;
};

export function subscribeToOrderPayment({ orderId, customerPhone, onConfirmed }) {
  const client = getPusherClient();

  if (!client || !orderId) {
    return () => {};
  }

  const channelName = `order-payment.${orderId}`;
  const channel = client.subscribe(channelName);
  let settled = false;

  const deliver = (data) => {
    if (settled || !isOrderPaymentPaid(data)) {
      return;
    }

    settled = true;
    onConfirmed?.(data);
  };

  const handleConfirmed = async (payload) => {
    if (settled) {
      return;
    }

    if (payload?.payment_status === 'paid' || payload?.order_id === orderId) {
      try {
        const data = await fetchOrderPaymentStatus(orderId, customerPhone);
        deliver(data);
        return;
      } catch {
        // Continua para nova tentativa via polling.
      }
    }

    try {
      const data = await fetchOrderPaymentStatus(orderId, customerPhone);
      deliver(data);
    } catch {
      // Polling de fallback continua ativo.
    }
  };

  channel.bind('payment.confirmed', handleConfirmed);

  channel.bind('pusher:subscription_succeeded', () => {
    void fetchOrderPaymentStatus(orderId, customerPhone)
      .then((data) => deliver(data))
      .catch(() => {});
  });

  const onConnected = () => {
    void fetchOrderPaymentStatus(orderId, customerPhone)
      .then((data) => deliver(data))
      .catch(() => {});
  };

  if (client.connection?.state === 'connected') {
    onConnected();
  } else {
    client.connection.bind('connected', onConnected);
  }

  return () => {
    settled = true;
    channel.unbind('payment.confirmed', handleConfirmed);
    client.connection.unbind('connected', onConnected);
    client.unsubscribe(channelName);
  };
}

export function disconnectOrderPaymentRealtime() {
  if (sharedClient) {
    sharedClient.disconnect();
    sharedClient = null;
  }
}
