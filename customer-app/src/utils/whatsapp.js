import { onlyDigits } from './customerSession';

const MOBILE_UA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i;
const WHATSAPP_WINDOW_NAME = 'partiumenu_whatsapp';

export const isMobileDevice = () => MOBILE_UA.test(navigator.userAgent);

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
  if (!store || !order) {
    return null;
  }

  let phone = onlyDigits(store.whatsapp_number || store.whatsapp || '');

  if (!phone) {
    return null;
  }

  if (!phone.startsWith('55')) {
    phone = `55${phone}`;
  }

  const lines = [];
  const orderId = order.display_code || order.id;

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
  const fromApi = data?.whatsapp_url || order?.whatsapp_url || null;
  const normalized = normalizeWhatsAppUrl(fromApi);

  if (normalized) {
    return normalized;
  }

  return buildWhatsAppUrlFromOrder(store, order);
};

export const prepareWhatsAppWindow = () => {
  if (isMobileDevice()) {
    return null;
  }

  try {
    const win = window.open('about:blank', WHATSAPP_WINDOW_NAME);

    return win && !win.closed ? win : null;
  } catch {
    return null;
  }
};

export const closeWhatsAppWindow = (targetWindow) => {
  try {
    if (targetWindow && !targetWindow.closed) {
      targetWindow.close();
    }
  } catch {
    // ignore
  }
};

const openViaAnchor = (safeUrl) => {
  const link = document.createElement('a');
  link.href = safeUrl;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  link.style.display = 'none';
  document.body.appendChild(link);
  link.click();
  link.remove();
};

export const openWhatsAppUrl = (url, targetWindow = null) => {
  const safeUrl = normalizeWhatsAppUrl(url);

  if (!safeUrl) {
    return false;
  }

  if (isMobileDevice()) {
    window.location.assign(safeUrl);
    return true;
  }

  try {
    const reusedWindow = window.open(safeUrl, WHATSAPP_WINDOW_NAME);

    if (reusedWindow) {
      reusedWindow.opener = null;
      reusedWindow.focus?.();
      return true;
    }
  } catch {
    // tenta fallbacks abaixo
  }

  if (targetWindow && !targetWindow.closed) {
    try {
      targetWindow.opener = null;
      targetWindow.location.replace(safeUrl);
      targetWindow.focus?.();
      return true;
    } catch {
      closeWhatsAppWindow(targetWindow);
    }
  }

  try {
    openViaAnchor(safeUrl);
    return true;
  } catch {
    // fallback final
  }

  window.location.assign(safeUrl);
  return true;
};
