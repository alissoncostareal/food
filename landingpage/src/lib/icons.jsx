import {
  BarChart3,
  Loader2,
  MapPin,
  MessageCircle,
  Package,
  ShoppingBag,
  Sparkles,
  TicketPercent,
  Users,
  UtensilsCrossed,
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
}

export function resolveFeatureIcon(name) {
  return iconMap[name] || Sparkles
}
