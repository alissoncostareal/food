export const featureRequiredPlan = {
  coupons: 'pro',
  delivery_areas: 'pro',
  dashboard_advanced: 'pro',
  whatsapp_auto: 'pro',
  whatsapp_bot: 'pro',
  team: 'premium',
  intelligence: 'premium',
  advanced_reports: 'premium',
  ifood_integration: 'premium',
  whatsapp_ai: 'premium'
}

export function requiredPlanLabelForFeature(featureKey) {
  const plan = featureRequiredPlan[featureKey]
  if (plan === 'premium') return 'Premium'
  if (plan === 'pro') return 'Pro'
  return null
}

export const featureLabels = {
  coupons: 'Cupons de desconto',
  dashboard_advanced: 'Dashboard avançado',
  intelligence: 'Inteligência de dados',
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

const CORE_PLAN_DEFAULTS = {
  pro: {
    coupons: true,
    dashboard_advanced: true,
    whatsapp_auto: true,
    whatsapp_bot: true,
    delivery_areas: true
  },
  premium: {
    coupons: true,
    dashboard_advanced: true,
    intelligence: true,
    whatsapp_auto: true,
    whatsapp_bot: true,
    whatsapp_ai: true,
    ifood_integration: true,
    advanced_reports: true,
    delivery_areas: true,
    team: true
  }
}

export function resolveEffectivePlanFeatures(plan) {
  if (!plan) return {}

  const features = Object.fromEntries(orderedFeatureKeys.map((key) => [key, false]))
  const stored = plan.features || {}

  for (const [key, enabled] of Object.entries(CORE_PLAN_DEFAULTS[plan.slug] || {})) {
    if (key in features) {
      features[key] = Boolean(enabled)
    }
  }

  for (const [key, enabled] of Object.entries(stored)) {
    if (key in features) {
      features[key] = Boolean(enabled)
    }
  }

  if (plan.slug === 'premium' && !Object.prototype.hasOwnProperty.call(stored, 'intelligence')) {
    features.intelligence = true
  }

  if (plan.slug === 'premium' && !Object.prototype.hasOwnProperty.call(stored, 'team')) {
    features.team = true
  }

  return features
}

export function storeHasPlanFeature(store, featureKey) {
  if (!featureKey) return true
  if (store?.has_active_subscription === false) return false

  const features = resolveEffectivePlanFeatures(store?.plan)
  return Boolean(features[featureKey])
}

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
      items.push(featureLabels.intelligence)
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
