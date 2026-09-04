export const ADMIN_URL = import.meta.env.VITE_ADMIN_URL || 'https://admin.partiumenu.com.br'

export const REGISTER_URL = `${ADMIN_URL.replace(/\/+$/, '')}/register`

export const DEMO_STORE_URL =
  import.meta.env.VITE_DEMO_STORE_URL || 'https://app.partiumenu.com.br/lojademo'
