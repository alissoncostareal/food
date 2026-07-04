import { useEffect, useState } from 'react';
import api from '../services/api';

const DEFAULT_CONFIG = {
  otp_login_enabled: true,
  guest_checkout_enabled: true,
  orders_history_requires_login: true,
  message: '',
};

export function useCustomerAuthConfig() {
  const [config, setConfig] = useState(DEFAULT_CONFIG);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    api.get('/customer/auth-config')
      .then(({ data }) => {
        if (!active) return;
        setConfig({ ...DEFAULT_CONFIG, ...data });
      })
      .catch(() => {
        if (!active) return;
        setConfig({
          ...DEFAULT_CONFIG,
          otp_login_enabled: false,
          orders_history_requires_login: false,
          message: 'Identifique-se no checkout com nome e WhatsApp.',
        });
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  return {
    loading,
    otpLoginEnabled: Boolean(config.otp_login_enabled),
    guestCheckoutEnabled: config.guest_checkout_enabled !== false,
    ordersHistoryRequiresLogin: Boolean(config.orders_history_requires_login),
    message: config.message || '',
  };
}
