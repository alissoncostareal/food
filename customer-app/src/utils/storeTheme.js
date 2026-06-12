export const DEFAULT_STORE_PRIMARY = '#EF4444';
export const DEFAULT_STORE_SECONDARY = '#1E293B';

const THEME_CACHE_PREFIX = '@partiumenu:store-theme:';

const safeJsonParse = (value) => {
  try {
    return value ? JSON.parse(value) : null;
  } catch {
    return null;
  }
};

export function readStoreThemeCache(slug) {
  if (!slug || typeof window === 'undefined') return null;

  return safeJsonParse(localStorage.getItem(`${THEME_CACHE_PREFIX}${slug}`));
}

export function writeStoreThemeCache(slug, store = {}) {
  if (!slug || typeof window === 'undefined' || !store.primary_color) return;

  localStorage.setItem(
    `${THEME_CACHE_PREFIX}${slug}`,
    JSON.stringify({
      primary_color: store.primary_color,
      secondary_color: store.secondary_color || DEFAULT_STORE_SECONDARY
    })
  );
}

export function applyStoreTheme(store = {}) {
  const primary = store.primary_color || DEFAULT_STORE_PRIMARY;
  const secondary = store.secondary_color || DEFAULT_STORE_SECONDARY;

  if (typeof document !== 'undefined') {
    document.documentElement.style.setProperty('--store-primary', primary);
    document.documentElement.style.setProperty('--store-secondary', secondary);
  }

  return { primary, secondary };
}

export function storeThemeStyle(store = {}) {
  if (!store?.primary_color) return undefined;

  return {
    '--store-primary': store.primary_color,
    '--store-secondary': store.secondary_color || DEFAULT_STORE_SECONDARY
  };
}

export const storeThemeClasses = {
  text: 'text-[var(--store-primary)]',
  textSoft: 'text-[var(--store-primary)]/90',
  bgSoft: 'bg-[var(--store-primary)]/10',
  bgSoftHover: 'hover:bg-[var(--store-primary)]/15',
  borderSoft: 'border-[var(--store-primary)]/20',
  borderStrong: 'border-[var(--store-primary)]/40',
  ring: 'focus:ring-[var(--store-primary)]/10 focus:border-[var(--store-primary)]',
  btn: 'bg-[var(--store-primary)] hover:brightness-90 text-white',
  textSecondary: 'text-[var(--store-secondary)]',
  bgSecondary: 'bg-[var(--store-secondary)]',
};
