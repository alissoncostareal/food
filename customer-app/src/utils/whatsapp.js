const MOBILE_UA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i;

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

export const prepareWhatsAppWindow = () => {
  if (isMobileDevice()) {
    return null;
  }

  try {
    const win = window.open('', 'partiumenu_whatsapp');

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

export const openWhatsAppUrl = (url, targetWindow = null) => {
  const safeUrl = normalizeWhatsAppUrl(url);

  if (!safeUrl) {
    return false;
  }

  if (targetWindow && !targetWindow.closed) {
    targetWindow.location.replace(safeUrl);
    targetWindow.focus?.();
    return true;
  }

  if (isMobileDevice()) {
    window.location.assign(safeUrl);
    return true;
  }

  const link = document.createElement('a');
  link.href = safeUrl;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  document.body.appendChild(link);
  link.click();
  link.remove();

  return true;
};
