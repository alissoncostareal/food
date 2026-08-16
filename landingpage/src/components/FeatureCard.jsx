import { resolveFeatureIcon } from '../lib/icons.jsx'
import { getFeatureAccent, getFeaturePalette } from '../lib/featureAccents.js'

const BADGE_STYLES = {
  red: { backgroundColor: '#dc2626', color: '#fff' },
  emerald: { backgroundColor: '#059669', color: '#fff' },
  teal: { backgroundColor: '#0d9488', color: '#fff' },
  sky: { backgroundColor: '#0284c7', color: '#fff' },
  amber: { backgroundColor: '#d97706', color: '#fff' },
  rose: { backgroundColor: '#e11d48', color: '#fff' },
  cyan: { backgroundColor: '#0891b2', color: '#fff' },
  indigo: { backgroundColor: '#4f46e5', color: '#fff' },
  fuchsia: { backgroundColor: '#c026d3', color: '#fff' },
  slate: { backgroundColor: '#0f172a', color: '#fff' },
}

export default function FeatureCard({ feature }) {
  const Icon = resolveFeatureIcon(feature.icon)
  const accent = getFeatureAccent(feature.icon)
  const palette = getFeaturePalette(accent.palette)
  const isHero = accent.tier === 'hero'
  const isHighlight = accent.tier === 'highlight'
  const badgeTone = accent.palette && BADGE_STYLES[accent.palette] ? accent.palette : 'slate'

  if (isHero) {
    return (
      <article className="group relative overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-red-600 via-red-500 to-orange-500 p-6 text-white shadow-xl shadow-red-600/25 transition duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-red-600/30 sm:col-span-2 sm:p-8 xl:col-span-2">
        <div className="pointer-events-none absolute -right-8 -top-8 h-40 w-40 rounded-full bg-white/10 blur-2xl" />
        <div className="pointer-events-none absolute -bottom-10 -left-6 h-32 w-32 rounded-full bg-orange-300/20 blur-2xl" />

        {accent.badge ? (
          <span className="relative inline-flex rounded-full bg-white/15 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-red-100 ring-1 ring-white/20">
            {accent.badge}
          </span>
        ) : null}

        <div className="relative mt-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-white/15 text-white ring-1 ring-white/25 backdrop-blur-sm">
          <Icon size={26} />
        </div>

        <h3 className="relative mt-5 text-2xl font-black sm:text-[1.65rem]">{feature.title}</h3>
        <p className="relative mt-3 max-w-lg text-sm font-medium leading-relaxed text-red-50/95 sm:text-base">
          {feature.description}
        </p>
      </article>
    )
  }

  return (
    <article
      className={`group relative rounded-[1.75rem] border p-6 transition duration-300 hover:-translate-y-1 ${
        isHighlight ? palette.card : `shadow-sm ${palette.card}`
      }`}
    >
      {isHighlight && accent.badge ? (
        <span
          className={`feature-pill feature-pill--${badgeTone} absolute right-4 top-4 z-10`}
          style={BADGE_STYLES[badgeTone]}
        >
          {accent.badge}
        </span>
      ) : null}

      <div className={`flex h-12 w-12 items-center justify-center rounded-2xl transition ${palette.icon}`}>
        <Icon size={22} />
      </div>

      <h3 className="mt-5 text-xl font-black text-slate-900">{feature.title}</h3>
      <p className="mt-2 text-sm font-medium leading-relaxed text-slate-500">{feature.description}</p>
    </article>
  )
}
