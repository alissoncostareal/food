import { mergeStreetAddress } from './streetAddress';

const LEGACY_STORAGE_KEY = '@fooddash:customer';
export const STORAGE_KEY = '@partiumenu:customer';

const LEGACY_CART_PREFIX = 'fooddash_cart_';
const CART_PREFIX = '@partiumenu:cart:';

export const cartStorageKey = (storeSlug) => `${CART_PREFIX}${storeSlug}`;

export const onlyDigits = (value) => String(value || '').replace(/\D/g, '');

export const safeJsonParse = (value) => {
  try {
    return value ? JSON.parse(value) : null;
  } catch {
    return null;
  }
};

export const migrateLegacyStorage = () => {
  if (typeof window === 'undefined') return;

  const legacyCustomer = localStorage.getItem(LEGACY_STORAGE_KEY);

  if (legacyCustomer && !localStorage.getItem(STORAGE_KEY)) {
    localStorage.setItem(STORAGE_KEY, legacyCustomer);
  }

  localStorage.removeItem(LEGACY_STORAGE_KEY);

  for (let i = localStorage.length - 1; i >= 0; i -= 1) {
    const key = localStorage.key(i);

    if (key?.startsWith(LEGACY_CART_PREFIX)) {
      const slug = key.slice(LEGACY_CART_PREFIX.length);
      const value = localStorage.getItem(key);
      const newKey = cartStorageKey(slug);

      if (value && !localStorage.getItem(newKey)) {
        localStorage.setItem(newKey, value);
      }

      localStorage.removeItem(key);
    }
  }
};

export const readCartFromStorage = (storeSlug) => {
  migrateLegacyStorage();

  const saved = localStorage.getItem(cartStorageKey(storeSlug))
    || localStorage.getItem(`${LEGACY_CART_PREFIX}${storeSlug}`);

  return safeJsonParse(saved) || [];
};

export const writeCartToStorage = (storeSlug, cart) => {
  if (!storeSlug) return;

  localStorage.setItem(cartStorageKey(storeSlug), JSON.stringify(cart));
};

export const getProfileFromResponse = (data) => {
  return data?.user || data?.customer || data?.data?.user || data?.data?.customer || null;
};

export const buildCustomerSession = (customer, fallback = {}) => {
  const name = customer?.name || customer?.customer_name || fallback.name || fallback.customer_name || '';
  const phone = customer?.phone || customer?.customer_phone || fallback.phone || fallback.customer_phone || '';
  const address = mergeStreetAddress(
    customer?.address || fallback.address || '',
    customer?.address_number || customer?.number || fallback.address_number || ''
  );

  return {
    name,
    customer_name: name,
    phone,
    customer_phone: phone,
    address,
    address_number: '',
    district: customer?.district || customer?.neighborhood || fallback.district || '',
    address_complement: customer?.address_complement || customer?.complement || fallback.address_complement || ''
  };
};

export const readLocalCustomer = () => {
  migrateLegacyStorage();

  const savedUser = safeJsonParse(localStorage.getItem('user'));
  const savedCustomer = safeJsonParse(localStorage.getItem(STORAGE_KEY));
  return buildCustomerSession(savedUser || savedCustomer, savedCustomer || {});
};

export const persistCustomerSession = (customer) => {
  migrateLegacyStorage();

  const session = buildCustomerSession(customer);
  const token = typeof window !== 'undefined' ? localStorage.getItem('token') : null;

  if (customer?.id && token) {
    localStorage.setItem('user', JSON.stringify({
      id: customer.id,
      name: session.name,
      phone: session.phone,
      email: customer.email,
      role: customer.role,
      address: session.address,
      address_number: null,
      district: session.district,
      address_complement: session.address_complement
    }));
  }

  localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
  window.dispatchEvent(new Event('customer-session-updated'));

  return session;
};

export const clearCustomerSession = () => {
  localStorage.removeItem('user');
  localStorage.removeItem('token');
  localStorage.removeItem(STORAGE_KEY);
  localStorage.removeItem(LEGACY_STORAGE_KEY);
  window.dispatchEvent(new Event('customer-session-updated'));
};

export const fetchCustomerProfile = async (api) => {
  const token = localStorage.getItem('token');

  if (!token) {
    return readLocalCustomer();
  }

  const { data } = await api.get('/customer/profile', {
    headers: { Authorization: `Bearer ${token}` }
  });

  const user = getProfileFromResponse(data);

  if (user) {
    return persistCustomerSession(user);
  }

  return readLocalCustomer();
};

export const lookupCustomerByPhone = async (api, phone) => {
  const digits = onlyDigits(phone);

  if (digits.length < 10) {
    return null;
  }

  try {
    const { data } = await api.post('/customers/whatsapp/show', { phone: digits });
    const user = getProfileFromResponse(data);

    if (user) {
      return persistCustomerSession(user);
    }
  } catch (err) {
    if (err.response?.status !== 404) {
      console.warn('Erro ao buscar cliente pelo WhatsApp:', err);
    }
  }

  return null;
};

export const saveCustomerProfile = async (api, payload) => {
  const token = localStorage.getItem('token');

  if (token) {
    const { data } = await api.put('/customer/profile', {
      name: payload.name || payload.customer_name,
      phone: onlyDigits(payload.phone || payload.customer_phone),
      address: payload.address,
      district: payload.district,
      address_complement: payload.address_complement
    }, {
      headers: { Authorization: `Bearer ${token}` }
    });

    const user = getProfileFromResponse(data);

    if (user) {
      return persistCustomerSession(user);
    }
  }

  return persistCustomerSession(buildCustomerSession(payload));
};

export const persistCheckoutCustomerSession = (form, orderResponse = null) => {
  const savedCustomer = getProfileFromResponse(orderResponse);
  const existing = readLocalCustomer();

  if (form.fulfillment_type !== 'delivery') {
    return persistCustomerSession({
      ...existing,
      name: form.customer_name,
      customer_name: form.customer_name,
      phone: form.customer_phone,
      customer_phone: form.customer_phone
    });
  }

  const deliveryPayload = {
    name: form.customer_name,
    customer_name: form.customer_name,
    phone: form.customer_phone,
    customer_phone: form.customer_phone,
    address: form.address,
    district: form.district,
    address_complement: form.address_complement
  };

  if (savedCustomer) {
    return persistCustomerSession(savedCustomer);
  }

  return persistCustomerSession(deliveryPayload);
};
