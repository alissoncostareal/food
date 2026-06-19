import Pusher from 'pusher-js';
import { fetchOrderPaymentStatus } from './paymentPolling';

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

  const handleConfirmed = async () => {
    try {
      const data = await fetchOrderPaymentStatus(orderId, customerPhone);
      onConfirmed?.(data);
    } catch {
      onConfirmed?.(null);
    }
  };

  channel.bind('payment.confirmed', handleConfirmed);
  channel.bind('payment.refunded', handleConfirmed);

  return () => {
    channel.unbind('payment.confirmed', handleConfirmed);
    channel.unbind('payment.refunded', handleConfirmed);
    client.unsubscribe(channelName);
  };
}

export function disconnectOrderPaymentRealtime() {
  if (sharedClient) {
    sharedClient.disconnect();
    sharedClient = null;
  }
}
