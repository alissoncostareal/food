import {
  BarChart3,
  Bell,
  CircleDollarSign,
  ShoppingBag,
  TrendingUp,
} from 'lucide-react'

const previewStats = [
  {
    label: 'Vendas hoje',
    value: 'R$ 2.840',
    delta: '+12%',
    valueTone: 'text-emerald-700',
    deltaTone: 'text-emerald-600 bg-emerald-500/10',
    card: 'bg-emerald-500/[0.07] ring-1 ring-emerald-500/15',
    icon: 'bg-emerald-500 text-white',
    Icon: TrendingUp,
  },
  {
    label: 'Pedidos',
    value: '47',
    delta: '+5',
    valueTone: 'text-slate-900',
    deltaTone: 'text-red-600 bg-red-500/10',
    card: 'bg-red-500/[0.07] ring-1 ring-red-500/15',
    icon: 'bg-gradient-to-br from-red-500 to-orange-500 text-white',
    Icon: ShoppingBag,
  },
  {
    label: 'Ticket médio',
    value: 'R$ 60,40',
    delta: '+8%',
    valueTone: 'text-amber-700',
    deltaTone: 'text-amber-600 bg-amber-500/10',
    card: 'bg-amber-500/[0.07] ring-1 ring-amber-500/15',
    icon: 'bg-amber-500 text-white',
    Icon: CircleDollarSign,
  },
]

const previewOrders = [
  {
    code: '#1842',
    customer: 'Maria S.',
    total: 'R$ 68,90',
    status: 'Novo',
    accent: 'bg-amber-500',
    badge: 'bg-amber-100 text-amber-800',
  },
  {
    code: '#1841',
    customer: 'João P.',
    total: 'R$ 42,00',
    status: 'Preparo',
    accent: 'bg-sky-500',
    badge: 'bg-sky-100 text-sky-800',
  },
  {
    code: '#1840',
    customer: 'Ana L.',
    total: 'R$ 91,50',
    status: 'Pronto',
    accent: 'bg-emerald-500',
    badge: 'bg-emerald-100 text-emerald-800',
  },
]

const previewTags = [
  { label: 'Pedidos ao vivo', className: 'border-red-400/20 bg-red-500/10 text-red-200' },
  { label: 'WhatsApp automático', className: 'border-emerald-400/20 bg-emerald-500/10 text-emerald-200' },
  { label: 'Cupons promo', className: 'border-rose-400/20 bg-rose-500/10 text-rose-200' },
]

export default function HeroPreview() {
  return (
    <div className="relative mx-auto w-full max-w-md lg:max-w-none">
      <div className="absolute -inset-6 rounded-[2.75rem] bg-gradient-to-br from-red-500/20 via-transparent to-orange-500/10 blur-3xl" />

      <div className="relative overflow-hidden rounded-[1.75rem] border border-white/10 bg-slate-900/50 p-1.5 shadow-2xl shadow-black/40 backdrop-blur-xl">
        <div className="flex items-center gap-3 rounded-t-[1.25rem] border-b border-white/5 bg-slate-950/60 px-4 py-3">
          <div className="flex items-center gap-1.5">
            <span className="h-2.5 w-2.5 rounded-full bg-red-500/80" />
            <span className="h-2.5 w-2.5 rounded-full bg-amber-400/80" />
            <span className="h-2.5 w-2.5 rounded-full bg-emerald-500/80" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-[11px] font-black text-slate-300">Painel PartiuMenu</p>
          </div>
          <div className="relative flex h-8 w-8 items-center justify-center rounded-lg bg-white/5 text-slate-400">
            <Bell size={14} />
            <span className="absolute -right-0.5 -top-0.5 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-red-500 text-[8px] font-black text-white">
              3
            </span>
          </div>
        </div>

        <div className="rounded-b-[1.25rem] bg-gradient-to-b from-slate-100/95 via-white/90 to-red-50/30 p-4 sm:p-5">
          <div className="mb-4 flex items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-red-500 to-orange-500 text-white shadow-md shadow-red-500/20">
                <TrendingUp size={16} />
              </div>
              <div>
                <p className="text-xs font-black text-slate-900">Resumo do dia</p>
                <p className="flex items-center gap-1.5 text-[10px] font-bold text-slate-500">
                  <span className="relative flex h-1.5 w-1.5">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                    <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  </span>
                  Ao vivo agora
                </p>
              </div>
            </div>
            <div className="hidden items-center gap-1 rounded-lg bg-violet-500/10 px-2 py-1 text-[10px] font-black text-violet-700 sm:flex">
              <BarChart3 size={12} />
              Hoje
            </div>
          </div>

          <div className="grid grid-cols-3 gap-2.5 sm:gap-3">
            {previewStats.map((stat) => (
              <div key={stat.label} className={`rounded-2xl p-3 ${stat.card}`}>
                <div className={`mb-2.5 flex h-7 w-7 items-center justify-center rounded-lg ${stat.icon}`}>
                  <stat.Icon size={13} />
                </div>
                <p className="text-[8px] font-black uppercase tracking-wider text-slate-500 sm:text-[9px]">{stat.label}</p>
                <p className={`mt-1 text-sm font-black leading-none sm:text-base ${stat.valueTone}`}>{stat.value}</p>
                <span className={`mt-1.5 inline-block rounded-md px-1.5 py-0.5 text-[9px] font-black ${stat.deltaTone}`}>
                  {stat.delta}
                </span>
              </div>
            ))}
          </div>

          <div className="mt-4 rounded-2xl bg-white/70 p-3 ring-1 ring-slate-900/5 backdrop-blur-sm sm:p-4">
            <p className="mb-3 text-[10px] font-black uppercase tracking-wider text-slate-500">Pedidos recentes</p>
            <div className="space-y-2">
              {previewOrders.map((order) => (
                <div
                  key={order.code}
                  className="flex items-center gap-3 rounded-xl bg-white px-3 py-2.5 shadow-sm ring-1 ring-slate-900/[0.04]"
                >
                  <div className={`h-9 w-1 shrink-0 rounded-full ${order.accent}`} />
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                    <ShoppingBag size={14} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2">
                      <p className="text-xs font-black text-slate-900">{order.code}</p>
                      <p className="text-[10px] font-black text-slate-700">{order.total}</p>
                    </div>
                    <div className="mt-0.5 flex items-center justify-between gap-2">
                      <p className="truncate text-[10px] font-bold text-slate-500">{order.customer}</p>
                      <span className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black uppercase ${order.badge}`}>
                        {order.status}
                      </span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      <div className="mt-4 flex flex-wrap justify-center gap-2 lg:justify-start">
        {previewTags.map((tag) => (
          <span
            key={tag.label}
            className={`inline-flex items-center rounded-full border px-3 py-1.5 text-[10px] font-black uppercase tracking-wide backdrop-blur-sm ${tag.className}`}
          >
            {tag.label}
          </span>
        ))}
      </div>
    </div>
  )
}
