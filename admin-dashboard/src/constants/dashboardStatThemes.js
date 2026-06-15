export const DASHBOARD_STAT_THEMES = {
  emerald: {
    card: 'bg-gradient-to-br from-emerald-50/90 via-white to-white shadow-sm hover:shadow-md',
    icon: 'bg-emerald-500 text-white shadow-sm shadow-emerald-500/20',
    value: 'text-emerald-700',
    desc: 'text-slate-500',
  },
  red: {
    card: 'bg-gradient-to-br from-red-50/80 via-white to-white shadow-sm hover:shadow-md',
    icon: 'bg-gradient-to-br from-red-500 to-orange-500 text-white shadow-sm shadow-red-500/20',
    value: 'text-slate-900',
    desc: 'text-slate-500',
  },
  amber: {
    card: 'bg-gradient-to-br from-amber-50/90 via-white to-white shadow-sm hover:shadow-md',
    icon: 'bg-amber-500 text-white shadow-sm shadow-amber-500/20',
    value: 'text-amber-700',
    desc: 'text-slate-500',
  },
  blue: {
    card: 'bg-gradient-to-br from-blue-50/80 via-white to-white shadow-sm hover:shadow-md',
    icon: 'bg-blue-500 text-white shadow-sm shadow-blue-500/20',
    value: 'text-blue-800',
    desc: 'text-slate-500',
  },
  orange: {
    card: 'bg-gradient-to-br from-orange-50/85 via-white to-white shadow-sm hover:shadow-md',
    icon: 'bg-orange-500 text-white shadow-sm shadow-orange-500/20',
    value: 'text-orange-700',
    desc: 'text-slate-500',
  },
}

export function getDashboardStatTheme(key) {
  return DASHBOARD_STAT_THEMES[key] || DASHBOARD_STAT_THEMES.blue
}

export function withDashboardStatTheme(card) {
  return {
    ...card,
    theme: getDashboardStatTheme(card.themeKey),
  }
}
