const palettes = {
  red: {
    card: 'border-red-200/80 bg-gradient-to-br from-red-50 via-white to-orange-50 shadow-md shadow-red-500/10 hover:border-red-300 hover:shadow-lg hover:shadow-red-500/15',
    icon: 'bg-gradient-to-br from-red-500 to-orange-500 text-white shadow-md shadow-red-500/30',
  },
  emerald: {
    card: 'border-emerald-200/80 bg-gradient-to-br from-emerald-50 via-white to-teal-50 shadow-md shadow-emerald-500/10 hover:border-emerald-300 hover:shadow-lg hover:shadow-emerald-500/15',
    icon: 'bg-gradient-to-br from-emerald-500 to-teal-500 text-white shadow-md shadow-emerald-500/30',
  },
  teal: {
    card: 'border-teal-200/80 bg-gradient-to-br from-teal-50 via-white to-cyan-50 shadow-md shadow-teal-500/10 hover:border-teal-300 hover:shadow-lg hover:shadow-teal-500/15',
    icon: 'bg-gradient-to-br from-teal-500 to-cyan-500 text-white shadow-md shadow-teal-500/30',
  },
  sky: {
    card: 'border-sky-200/80 bg-gradient-to-br from-sky-100 via-sky-50 to-blue-100 shadow-md shadow-sky-500/15 hover:border-sky-300 hover:shadow-lg hover:shadow-sky-500/20',
    icon: 'bg-gradient-to-br from-sky-500 to-blue-500 text-white shadow-md shadow-sky-500/30',
  },
  amber: {
    card: 'border-amber-200/80 bg-gradient-to-br from-amber-50 via-white to-yellow-50 shadow-md shadow-amber-500/10 hover:border-amber-300 hover:shadow-lg hover:shadow-amber-500/15',
    icon: 'bg-gradient-to-br from-amber-500 to-yellow-500 text-white shadow-md shadow-amber-500/30',
  },
  rose: {
    card: 'border-rose-200/80 bg-gradient-to-br from-rose-50 via-white to-pink-50 shadow-md shadow-rose-500/10 hover:border-rose-300 hover:shadow-lg hover:shadow-rose-500/15',
    icon: 'bg-gradient-to-br from-rose-500 to-pink-500 text-white shadow-md shadow-rose-500/30',
  },
  cyan: {
    card: 'border-cyan-200/80 bg-gradient-to-br from-cyan-50 via-white to-sky-50 shadow-md shadow-cyan-500/10 hover:border-cyan-300 hover:shadow-lg hover:shadow-cyan-500/15',
    icon: 'bg-gradient-to-br from-cyan-500 to-sky-500 text-white shadow-md shadow-cyan-500/25',
  },
  indigo: {
    card: 'border-indigo-200/80 bg-gradient-to-br from-indigo-50 via-white to-blue-50 shadow-md shadow-indigo-500/10 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-500/15',
    icon: 'bg-gradient-to-br from-indigo-500 to-blue-600 text-white shadow-md shadow-indigo-500/25',
  },
  fuchsia: {
    card: 'border-fuchsia-200/80 bg-gradient-to-br from-fuchsia-50 via-white to-pink-50 shadow-md shadow-fuchsia-500/10 hover:border-fuchsia-300 hover:shadow-lg hover:shadow-fuchsia-500/15',
    icon: 'bg-gradient-to-br from-fuchsia-500 to-pink-500 text-white shadow-md shadow-fuchsia-500/25',
  },
  slate: {
    card: 'border-slate-200/90 bg-gradient-to-br from-slate-50 via-white to-slate-100 shadow-sm hover:border-slate-300 hover:shadow-lg',
    icon: 'bg-gradient-to-br from-slate-600 to-slate-800 text-white shadow-md shadow-slate-500/20',
  },
}

/** One distinct palette per feature icon — no shared colors between cards. */
export const FEATURE_ACCENTS = {
  utensils: { tier: 'hero', badge: 'Base do delivery' },
  'shopping-bag': { tier: 'highlight', palette: 'red', badge: 'Ao vivo' },
  zap: { tier: 'highlight', palette: 'emerald', badge: 'Elogiado' },
  bookmark: { tier: 'highlight', palette: 'sky', badge: 'Cliente' },
  'message-circle': { tier: 'highlight', palette: 'teal', badge: 'Automático' },
  package: { tier: 'highlight', palette: 'amber', badge: 'Integração' },
  ticket: { tier: 'highlight', palette: 'rose', badge: 'Promoção' },
  'map-pin': { tier: 'highlight', palette: 'cyan', badge: 'Entrega' },
  'bar-chart': { tier: 'highlight', palette: 'indigo', badge: 'Dados' },
  sparkles: { tier: 'highlight', palette: 'fuchsia', badge: 'Dados' },
  users: { tier: 'highlight', palette: 'slate', badge: 'Time' },
}

export function getFeatureAccent(icon) {
  return FEATURE_ACCENTS[icon] || { tier: 'default', palette: 'slate' }
}

export function getFeaturePalette(paletteKey) {
  return palettes[paletteKey] || palettes.slate
}
