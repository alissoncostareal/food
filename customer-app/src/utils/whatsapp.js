import { onlyDigits } from './customerSession';

const MOBILE_UA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i;

export const isMobileDevice = () => MOBILE_UA.test(navigator.userAgent);

export const isValidWhatsAppUrl = (url) => {
  if (!url) {
    return false;
  }

  const trimmed = String(url).trim().toLowerCase();

  return trimmed.includes('wa.me/') || trimmed.includes('api.whatsapp.com/');
};

export const normalizeWhatsAppUrl = (url) => {
  if (!url) {
    return '';
  }

  const trimmed = String(url).trim();

  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed;
  }

  if (trimmed.startsWith('wa.me/')) {
    return `https://${trimmed}`;
  }

  return trimmed;
};

export const extractStoreWhatsAppPhone = (store) => {
  if (!store) {
    return '';
  }

  const direct = onlyDigits(store.whatsapp_number || '');

  if (direct) {
    return direct;
  }

  const connection = store.whatsapp;

  if (connection && typeof connection === 'object') {
    const fromConnection = onlyDigits(
      connection.whatsapp_number || connection.phone || connection.number || ''
    );

    if (fromConnection) {
      return fromConnection;
    }
  }

  if (typeof connection === 'string') {
    return onlyDigits(connection);
  }

  return onlyDigits(store.phone || '');
};

const paymentLabel = (method) => {
  switch (method) {
    case 'cash':
      return 'Dinheiro';
    case 'debit_card':
      return 'Cartão de débito';
    case 'credit_card':
      return 'Cartão de crédito';
    case 'pix':
      return 'Pix na entrega';
    case 'pix_online':
      return 'Pix online';
    case 'credit_card_online':
      return 'Cartão online';
    default:
      return 'Não informado';
  }
};

const formatMoney = (value) =>
  Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

export const buildWhatsAppUrlFromOrder = (store, order) => {
  if (!order) {
    return null;
  }

  const storeData = store || order.store;
  let phone = extractStoreWhatsAppPhone(storeData);

  if (!phone) {
    return null;
  }

  if (!phone.startsWith('55')) {
    phone = `55${phone}`;
  }

  const lines = [];
  const orderId = order.display_code || order.display_number || order.id;

  lines.push(`*Novo pedido #${orderId}*`, '');
  lines.push(`*Cliente:* ${order.customer_name || ''}`);
  lines.push(`*WhatsApp:* ${order.customer_phone || ''}`);
  lines.push(`*Tipo:* ${order.fulfillment_type === 'pickup' ? 'Retirada no local' : 'Entrega'}`);

  if (order.fulfillment_type === 'delivery') {
    lines.push(`*Endereço:* ${order.address || ''}`);

    if (order.district) {
      lines.push(`*Bairro:* ${order.district}`);
    }
  }

  lines.push(`*Pagamento:* ${paymentLabel(order.payment_method)}`);

  if (order.payment_method === 'cash' && order.change_for) {
    lines.push(`*Troco para:* ${formatMoney(order.change_for)}`);
  }

  if (order.observation) {
    lines.push(`*Obs. pedido:* ${order.observation}`);
  }

  lines.push('', '*Itens:*');

  (order.items || []).forEach((item) => {
    const productName = item.product?.name || item.name || 'Produto';
    lines.push(`${item.quantity}x ${productName} - ${formatMoney(item.subtotal ?? item.total ?? item.price)}`);

    let options = [];

    if (Array.isArray(item.options)) {
      options = item.options;
    } else if (typeof item.options === 'string' && item.options.trim() !== '') {
      try {
        options = JSON.parse(item.options);
      } catch {
        options = [];
      }
    }

    options.forEach((option) => {
      lines.push(`  + ${option.name} (${option.group_name || 'Adicional'}) ${formatMoney(option.additional_price)}`);
    });

    if (item.observation) {
      lines.push(`  Obs: ${item.observation}`);
    }
  });

  lines.push('');
  lines.push(`*Total:* ${formatMoney(order.total_amount)}`);

  return `https://wa.me/${phone}?text=${encodeURIComponent(lines.join('\n'))}`;
};

export const resolveWhatsAppUrl = (data, order, store) => {
  const candidates = [
    data?.whatsapp_url,
    order?.whatsapp_url,
  ]
    .map(normalizeWhatsAppUrl)
    .filter(isValidWhatsAppUrl);

  if (candidates.length > 0) {
    return candidates[0];
  }

  const storeWithPhone = store || order?.store || null;

  if (!extractStoreWhatsAppPhone(storeWithPhone) && data?.store_whatsapp_number) {
    const built = buildWhatsAppUrlFromOrder(
      { ...(storeWithPhone || {}), whatsapp_number: data.store_whatsapp_number },
      order
    );

    if (isValidWhatsAppUrl(built)) {
      return built;
    }
  }

  const built = buildWhatsAppUrlFromOrder(storeWithPhone, order);

  return isValidWhatsAppUrl(built) ? built : null;
};

export const redirectToWhatsApp = (url) => {
  const safeUrl = normalizeWhatsAppUrl(url);

  if (!isValidWhatsAppUrl(safeUrl)) {
    return false;
  }

  window.location.href = safeUrl;
  return true;
};

export const launchWhatsApp = (url) => {
  const safeUrl = normalizeWhatsAppUrl(url);

  if (!isValidWhatsAppUrl(safeUrl)) {
    return false;
  }

  try {
    const link = document.createElement('a');
    link.href = safeUrl;
    link.target = '_self';
    link.rel = 'noopener noreferrer';
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  } catch {
    // segue para navegação direta
  }

  window.location.href = safeUrl;
  return true;
};

export const openWhatsAppUrl = (url) => {
  const safeUrl = normalizeWhatsAppUrl(url);

  if (!isValidWhatsAppUrl(safeUrl)) {
    return false;
  }

  const link = document.createElement('a');
  link.href = safeUrl;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  link.style.display = 'none';
  document.body.appendChild(link);
  link.click();
  link.remove();

  return true;
};
