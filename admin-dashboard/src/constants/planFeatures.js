export const featureLabels = {
  coupons: 'Cupons de desconto',
  dashboard_advanced: 'Dashboard avançado',
  intelligence: 'Inteligência com IA',
  whatsapp_auto: 'WhatsApp automático',
  whatsapp_bot: 'Bot WhatsApp',
  whatsapp_ai: 'IA no WhatsApp',
  delivery_areas: 'Áreas de entrega',
  advanced_reports: 'Relatório financeiro',
  ifood_integration: 'Integração iFood',
  team: 'Gestão de equipe'
}

export const orderedFeatureKeys = [
  'coupons',
  'delivery_areas',
  'dashboard_advanced',
  'intelligence',
  'whatsapp_auto',
  'whatsapp_bot',
  'whatsapp_ai',
  'advanced_reports',
  'ifood_integration',
  'team'
]

const premiumExtras = [
  'Importação de produtos por XML'
]

export function buildPlanHighlights(plan) {
  const features = plan.features || {}
  const items = [
    plan.max_products === null ? 'Produtos ilimitados' : `Até ${plan.max_products} produtos`,
    'Pedidos ilimitados'
  ]

  const maxStores = Number(plan.max_stores || 1)
  if (maxStores > 1) {
    items.push(`Até ${maxStores} lojas (matriz + filiais)`)
  }

  const maxTeam = plan.max_team_members
  if (maxTeam !== null && maxTeam !== undefined && Number(maxTeam) > 0) {
    items.push(`Até ${maxTeam} membros de equipe`)
  }

  for (const key of orderedFeatureKeys) {
    if (!features[key]) continue

    if (key === 'advanced_reports') {
      items.push('Relatório financeiro')
      continue
    }

    if (key === 'ifood_integration') {
      items.push('Integração iFood')
      continue
    }

    if (key === 'intelligence') {
      items.push('Inteligência com IA')
      continue
    }

    items.push(featureLabels[key] || key)
  }

  if (features.ifood_integration) {
    premiumExtras.forEach(item => {
      if (!items.includes(item)) items.push(item)
    })
  }

  return items
}

export function enabledFeatureLabels(features = {}) {
  return buildPlanHighlights({ features, max_products: null }).filter(
    item => !item.startsWith('Até ') && item !== 'Produtos ilimitados' && item !== 'Pedidos ilimitados'
  )
}

function featureLabelForKey(key) {
  if (key === 'advanced_reports') return 'Relatório financeiro'
  if (key === 'ifood_integration') return 'Integração iFood'
  if (key === 'intelligence') return 'Inteligência com IA'
  return featureLabels[key] || key
}

export function getMissingFromPlan(currentPlan, targetPlan) {
  if (!currentPlan || !targetPlan || currentPlan.id === targetPlan.id) return []

  const missing = []
  const currentFeatures = currentPlan.features || {}
  const targetFeatures = targetPlan.features || {}

  const currentMax = currentPlan.max_products
  const targetMax = targetPlan.max_products

  if (targetMax === null && currentMax !== null) {
    missing.push('Produtos ilimitados')
  } else if (
    targetMax !== null
    && currentMax !== null
    && targetMax > currentMax
  ) {
    missing.push(`Limite de ${targetMax} produtos`)
  }

  for (const key of orderedFeatureKeys) {
    if (targetFeatures[key] && !currentFeatures[key]) {
      missing.push(featureLabelForKey(key))
    }
  }

  if (targetFeatures.ifood_integration && !currentFeatures.ifood_integration) {
    premiumExtras.forEach(item => {
      if (!missing.includes(item)) missing.push(item)
    })
  }

  return missing
}

export function normalizePlanFeatures(features = {}) {
  return Object.fromEntries(
    orderedFeatureKeys.map((key) => [key, Boolean(features[key])])
  )
}
