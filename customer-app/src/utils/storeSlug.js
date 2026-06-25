const TRACKING_QUERY_PARAMS = new Set([
  'igsh',
  'igshid',
  'fbclid',
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_content',
  'utm_term',
  'utm_id',
  'ref',
  'share',
]);

/**
 * Instagram e outros apps às vezes colam parâmetros no slug da URL
 * (ex.: /loja?igsh=xxx vira slug "loja?igsh=xxx" em alguns navegadores).
 */
export function normalizeStoreSlug(rawSlug) {
  if (!rawSlug) return '';

  let slug = String(rawSlug).trim().toLowerCase();

  for (let i = 0; i < 3; i += 1) {
    try {
      const decoded = decodeURIComponent(slug);

      if (decoded === slug) break;

      slug = decoded;
    } catch {
      break;
    }
  }

  slug = slug.split('?')[0].split('&')[0].split('#')[0];
  slug = slug.replace(/\/+$/, '');

  return slug;
}

export function cleanTrackingSearchParams(search = '') {
  if (!search || typeof window === 'undefined') {
    return '';
  }

  const params = new URLSearchParams(search.startsWith('?') ? search.slice(1) : search);

  TRACKING_QUERY_PARAMS.forEach((key) => params.delete(key));

  const next = params.toString();

  return next ? `?${next}` : '';
}

export function buildStorePath(slug, search = '') {
  const normalized = normalizeStoreSlug(slug);

  if (!normalized) return '/';

  return `/${normalized}${cleanTrackingSearchParams(search)}`;
}
