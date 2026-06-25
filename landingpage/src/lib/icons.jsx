import {
  BarChart3,
  Bookmark,
  Loader2,
  MapPin,
  MessageCircle,
  Package,
  ShoppingBag,
  Sparkles,
  TicketPercent,
  Users,
  UtensilsCrossed,
  Zap,
} from 'lucide-react'

const iconMap = {
  utensils: UtensilsCrossed,
  'shopping-bag': ShoppingBag,
  'message-circle': MessageCircle,
  package: Package,
  ticket: TicketPercent,
  'map-pin': MapPin,
  'bar-chart': BarChart3,
  sparkles: Sparkles,
  users: Users,
  zap: Zap,
  bookmark: Bookmark,
}

export function resolveFeatureIcon(name) {
  return iconMap[name] || Sparkles
}
