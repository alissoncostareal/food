export const moduleLabels = {
  dashboard: 'Dashboard',
  store: 'Loja',
  payments: 'Recebimentos',
  orders: 'Pedidos',
  products: 'Cardápio',
  categories: 'Categorias',
  coupons: 'Cupons',
  delivery_areas: 'Áreas de entrega',
  delivery_drivers: 'Entregadores',
  team: 'Equipe',
  reports: 'Relatórios',
  intelligence: 'Inteligência',
  import: 'Importação',
  whatsapp: 'WhatsApp',
  ifood: 'iFood',
  billing: 'Meu plano',
  settings: 'Configurações'
}

export const orderedModuleKeys = Object.keys(moduleLabels)

export const defaultMaintenanceMessage = 'Este módulo está em manutenção. Tente novamente em breve.'

export function getMaintenanceMessage(moduleMaintenance, moduleKey) {
  const entry = moduleMaintenance?.[moduleKey]
  if (!entry) return defaultMaintenanceMessage
  return entry.message?.trim() || defaultMaintenanceMessage
}

export function isModuleUnderMaintenance(moduleMaintenance, moduleKey) {
  if (!moduleKey) return false
  return Boolean(moduleMaintenance?.[moduleKey])
}
