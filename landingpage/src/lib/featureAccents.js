const palettes = {
  red: {
    card: 'border-red-200/80 bg-gradient-to-br from-red-50 via-white to-orange-50 shadow-md shadow-red-500/10 hover:border-red-300 hover:shadow-lg hover:shadow-red-500/15',
    icon: 'bg-gradient-to-br from-red-500 to-orange-500 text-white shadow-md shadow-red-500/30',
    badge: 'bg-red-600 text-white',
  },
  emerald: {
    card: 'border-emerald-200/80 bg-gradient-to-br from-emerald-50 via-white to-teal-50 shadow-md shadow-emerald-500/10 hover:border-emerald-300 hover:shadow-lg hover:shadow-emerald-500/15',
    icon: 'bg-gradient-to-br from-emerald-500 to-teal-500 text-white shadow-md shadow-emerald-500/30',
    badge: 'bg-emerald-600 text-white',
  },
  amber: {
    card: 'border-amber-200/80 bg-gradient-to-br from-amber-50 via-white to-orange-50 shadow-md shadow-amber-500/10 hover:border-amber-300 hover:shadow-lg hover:shadow-amber-500/15',
    icon: 'bg-gradient-to-br from-red-500 via-amber-500 to-orange-400 text-white shadow-md shadow-amber-500/30',
    badge: 'bg-amber-500 text-white',
  },
  rose: {
    card: 'border-rose-200/80 bg-gradient-to-br from-rose-50 via-white to-pink-50 shadow-md shadow-rose-500/10 hover:border-rose-300 hover:shadow-lg hover:shadow-rose-500/15',
    icon: 'bg-gradient-to-br from-rose-500 to-pink-500 text-white shadow-md shadow-rose-500/30',
    badge: 'bg-rose-600 text-white',
  },
  sky: {
    card: 'border-sky-300/90 bg-gradient-to-br from-sky-100 via-sky-50 to-cyan-100 shadow-md shadow-sky-500/20 hover:border-sky-400 hover:shadow-lg hover:shadow-sky-500/25',
    icon: 'bg-gradient-to-br from-sky-500 to-cyan-500 text-white shadow-md shadow-sky-500/35',
    badge: 'bg-sky-600 text-white',
  },
  violet: {
    card: 'border-violet-100 bg-white hover:border-violet-200 hover:shadow-lg hover:shadow-violet-500/5',
    icon: 'bg-violet-50 text-violet-600 group-hover:bg-violet-100',
  },
  purple: {
    card: 'border-purple-100 bg-white hover:border-purple-200 hover:shadow-lg hover:shadow-purple-500/5',
    icon: 'bg-purple-50 text-purple-600 group-hover:bg-purple-100',
  },
  slate: {
    card: 'border-slate-200/90 bg-white hover:border-slate-300 hover:shadow-lg',
    icon: 'bg-slate-100 text-slate-600 group-hover:bg-slate-200',
  },
}

export const FEATURE_ACCENTS = {
  utensils: { tier: 'hero', badge: 'Base do delivery' },
  'shopping-bag': { tier: 'highlight', palette: 'red', badge: 'Ao vivo' },
  zap: { tier: 'highlight', palette: 'emerald', badge: 'Elogiado' },
  bookmark: { tier: 'highlight', palette: 'sky', badge: 'Cliente' },
  'message-circle': { tier: 'highlight', palette: 'emerald', badge: 'Automático' },
  package: { tier: 'highlight', palette: 'amber', badge: 'Integração' },
  ticket: { tier: 'highlight', palette: 'rose', badge: 'Promoção' },
  'map-pin': { tier: 'default', palette: 'slate' },
  'bar-chart': { tier: 'default', palette: 'violet' },
  sparkles: { tier: 'default', palette: 'purple' },
  users: { tier: 'default', palette: 'slate' },
}

export function getFeatureAccent(icon) {
  return FEATURE_ACCENTS[icon] || { tier: 'default', palette: 'slate' }
}

export function getFeaturePalette(paletteKey) {
  return palettes[paletteKey] || palettes.slate
}
