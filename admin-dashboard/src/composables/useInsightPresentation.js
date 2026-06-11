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
  timing: 'bg-blue-50 text-blue-700',
  menu: 'bg-amber-50 text-amber-700',
  operation: 'bg-red-50 text-red-700',
  growth: 'bg-violet-50 text-violet-700'
}

export const insightIconStyles = {
  sales: 'bg-emerald-100 text-emerald-600',
  timing: 'bg-blue-100 text-blue-600',
  menu: 'bg-amber-100 text-amber-600',
  operation: 'bg-red-100 text-red-600',
  growth: 'bg-violet-100 text-violet-600'
}

export const insightPriorityStyles = {
  high: 'border-red-200 bg-red-50/40',
  medium: 'border-slate-200 bg-white',
  low: 'border-slate-100 bg-white'
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
export const getInsightIconStyle = (type) => insightIconStyles[type] || 'bg-red-100 text-red-600'
export const getInsightPriorityStyle = (priority) => insightPriorityStyles[priority] || 'border-slate-200 bg-white'

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
  if (trend === 'up') return 'bg-emerald-50 text-emerald-700 border-emerald-100'
  if (trend === 'down') return 'bg-red-50 text-red-700 border-red-100'
  return 'bg-slate-50 text-slate-600 border-slate-100'
}
