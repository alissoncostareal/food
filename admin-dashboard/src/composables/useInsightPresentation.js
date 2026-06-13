import {
  TrendingUp,
  Clock,
  UtensilsCrossed,
  AlertTriangle,
  Target,
  Lightbulb
} from 'lucide-vue-next'

export const insightTypeLabels = {
  sales: 'Vendas',
  timing: 'Horários',
  menu: 'Cardápio',
  operation: 'Operação',
  growth: 'Crescimento'
}

export const insightTypeStyles = {
  sales: 'bg-emerald-50 text-emerald-700',
  timing: 'bg-sky-50 text-sky-700',
  menu: 'bg-orange-50 text-orange-700',
  operation: 'bg-amber-50 text-amber-700',
  growth: 'bg-violet-50 text-violet-700'
}

export const insightIconStyles = {
  sales: 'bg-emerald-100 text-emerald-600',
  timing: 'bg-sky-100 text-sky-600',
  menu: 'bg-orange-100 text-orange-600',
  operation: 'bg-amber-100 text-amber-600',
  growth: 'bg-violet-100 text-violet-600'
}

export const insightPriorityStyles = {
  high: 'border-amber-200 bg-amber-50/50',
  medium: 'border-slate-200 bg-white',
  low: 'border-slate-100 bg-slate-50/60'
}

export const insightFilterActiveStyles = {
  all: 'bg-slate-800 text-white shadow-sm',
  sales: 'bg-emerald-600 text-white shadow-sm shadow-emerald-100',
  timing: 'bg-sky-600 text-white shadow-sm shadow-sky-100',
  menu: 'bg-orange-500 text-white shadow-sm shadow-orange-100',
  operation: 'bg-amber-500 text-white shadow-sm shadow-amber-100',
  growth: 'bg-violet-600 text-white shadow-sm shadow-violet-100'
}

export const summaryCardStyles = {
  revenue: { icon: 'bg-emerald-100 text-emerald-600', border: 'border-emerald-100/80' },
  ticket: { icon: 'bg-sky-100 text-sky-600', border: 'border-sky-100/80' },
  weekday: { icon: 'bg-violet-100 text-violet-600', border: 'border-violet-100/80' },
  hour: { icon: 'bg-amber-100 text-amber-600', border: 'border-amber-100/80' }
}

export const insightFilterOptions = [
  { id: 'all', label: 'Todas' },
  { id: 'sales', label: 'Vendas' },
  { id: 'timing', label: 'Horários' },
  { id: 'menu', label: 'Cardápio' },
  { id: 'operation', label: 'Operação' },
  { id: 'growth', label: 'Crescimento' }
]

export const getInsightTypeLabel = (type) => insightTypeLabels[type] || 'Insight'
export const getInsightTypeStyle = (type) => insightTypeStyles[type] || 'bg-slate-50 text-slate-600'
export const getInsightIconStyle = (type) => insightIconStyles[type] || 'bg-amber-100 text-amber-600'
export const getInsightPriorityStyle = (priority) => insightPriorityStyles[priority] || 'border-slate-200 bg-white'
export const getInsightFilterActiveStyle = (filterId) =>
  insightFilterActiveStyles[filterId] || insightFilterActiveStyles.all
export const getSummaryCardStyle = (key) =>
  summaryCardStyles[key] || { icon: 'bg-slate-100 text-slate-500', border: 'border-slate-200/80' }

export const getInsightIcon = (type) => {
  const icons = {
    sales: TrendingUp,
    timing: Clock,
    menu: UtensilsCrossed,
    operation: AlertTriangle,
    growth: Target
  }

  return icons[type] || Lightbulb
}

export const getRevenueTrendLabel = (trend) => {
  if (trend === 'up') return 'Receita em alta'
  if (trend === 'down') return 'Receita em queda'
  return 'Receita estável'
}

export const getRevenueTrendStyle = (trend) => {
  if (trend === 'up') return 'bg-emerald-50/60 text-emerald-700 border-emerald-100/80'
  if (trend === 'down') return 'bg-rose-50/60 text-rose-700 border-rose-100/80'
  return 'bg-white text-slate-600 border-slate-200/80'
}
