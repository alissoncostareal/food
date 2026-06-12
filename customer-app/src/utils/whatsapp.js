export const openWhatsAppUrl = (url) => {
  if (!url) {
    return false;
  }

  const link = document.createElement('a');
  link.href = url;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  document.body.appendChild(link);
  link.click();
  link.remove();

  return true;
};
