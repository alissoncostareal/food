import { useEffect, useMemo, useState } from 'react'
import { ArrowRight, Loader2 } from 'lucide-react'
import Header from './components/Header.jsx'
import HeroPreview from './components/HeroPreview.jsx'
import FeatureCard from './components/FeatureCard.jsx'
import Footer from './components/Footer.jsx'
import LeadForm from './components/LeadForm.jsx'
import { fetchLandingContent, fetchLandingPlans } from './api'
import { ADMIN_URL } from './lib/constants.js'
import { applySeo, buildSeoFromContent, injectStructuredData } from './lib/seo.js'

const formatCurrency = (value) =>
  Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

const parsePlanPrice = (plan) => {
  const value = plan?.price

  if (typeof value === 'number' && Number.isFinite(value)) return value

  const parsed = Number(String(value ?? '').replace(',', '.'))

  return Number.isFinite(parsed) ? parsed : 0
}

function buildHeroTitleParts(hero) {
  if (!hero) return { before: '', highlight: '', after: '' }

  const full = `${hero.title} ${hero.highlight}`.trim()
  const highlight = hero.highlight || ''

  if (!highlight || !full.includes(highlight)) {
    return { before: hero.title, highlight: '', after: '' }
  }

  const index = full.indexOf(highlight)

  return {
    before: full.slice(0, index),
    highlight,
    after: full.slice(index + highlight.length),
  }
}

export default function App() {
  const [loading, setLoading] = useState(true)
  const [content, setContent] = useState(null)
  const [plans, setPlans] = useState([])
  const [loadError, setLoadError] = useState('')

  useEffect(() => {
    let active = true

    async function load() {
      try {
        const [landingResponse, plansResponse] = await Promise.all([
          fetchLandingContent(),
          fetchLandingPlans().catch(() => []),
        ])

        if (!active) return

        setContent(landingResponse.content)
        setPlans(Array.isArray(plansResponse) ? plansResponse : [])
      } catch {
        if (active) {
          setLoadError('Não foi possível carregar a página. Tente novamente em instantes.')
        }
      } finally {
        if (active) setLoading(false)
      }
    }

    load()

    return () => {
      active = false
    }
  }, [])

  const heroTitleParts = useMemo(() => buildHeroTitleParts(content?.hero), [content])

  const displayPlans = useMemo(
    () => [...plans].sort((a, b) => parsePlanPrice(a) - parsePlanPrice(b)),
    [plans]
  )

  const visiblePlansCount = useMemo(
    () => displayPlans.filter((plan) => plan.is_visible !== false).length,
    [displayPlans]
  )

  const isPlanAvailableOnLanding = (plan) => plan.is_visible !== false

  useEffect(() => {
    if (!content) return

    const seo = buildSeoFromContent(content)
    applySeo(seo)
    injectStructuredData(content)
  }, [content])

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-950 text-slate-400">
        <Loader2 className="animate-spin" size={40} />
      </div>
    )
  }

  if (loadError) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-950 px-6 text-center">
        <p className="max-w-md text-sm font-bold text-slate-400">{loadError}</p>
      </div>
    )
  }

  if (!content) return null

  const navLinks = [
    { href: '#recursos', label: 'Recursos' },
    ...(content.plans_section.show_plans && displayPlans.length ? [{ href: '#planos', label: 'Planos' }] : []),
    { href: '#interesse', label: 'Contato' },
  ]

  return (
    <div className="min-h-screen bg-white text-slate-900">
      <Header
        navLinks={navLinks}
        ctaPrimaryText={content.hero.cta_primary_text}
        ctaPrimaryUrl={content.hero.cta_primary_url}
      />

      <main>
      <section className="hero-banner relative isolate overflow-hidden px-5 pb-20 pt-32 text-white sm:pb-28 sm:pt-36" aria-label="Apresentação PartiuMenu">
        <div className="pointer-events-none absolute inset-0 hero-grid" />
        <div className="pointer-events-none absolute inset-0 hero-vignette" />
        <div className="pointer-events-none absolute inset-0">
          <div className="absolute -left-32 top-[-10%] h-[28rem] w-[28rem] rounded-full bg-red-500/20 blur-[100px]" />
          <div className="absolute left-[35%] top-[8%] h-64 w-64 rounded-full bg-orange-500/10 blur-[80px]" />
          <div className="absolute -right-16 top-[55%] h-80 w-80 rounded-full bg-red-600/12 blur-[90px]" />
          <div className="absolute right-[20%] top-[45%] h-48 w-48 rounded-full bg-rose-400/8 blur-[70px]" />
        </div>

        <div className="relative mx-auto grid max-w-6xl items-center gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16">
          <div className="max-w-2xl">
            <span className="inline-flex items-center rounded-full border border-red-400/25 bg-red-500/10 px-3 py-1 text-[11px] font-black uppercase tracking-[0.18em] text-red-200">
              {content.hero.eyebrow}
            </span>

            <p className="mt-4 text-sm font-bold uppercase tracking-[0.18em] text-red-200/90">
              PartiuMenu
            </p>

            <h1 className="mt-3 text-4xl font-black leading-[1.02] tracking-tight sm:text-5xl lg:text-[3.4rem]">
              {heroTitleParts.before}
              <span className="bg-gradient-to-r from-red-200 via-orange-100 to-white bg-clip-text text-transparent">
                {heroTitleParts.highlight}
              </span>
              {heroTitleParts.after}
            </h1>

            <p className="mt-6 text-base font-medium leading-relaxed text-slate-300 sm:text-lg">
              {content.hero.subtitle}
            </p>

            <div className="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
              <a
                href={content.hero.cta_primary_url}
                className="inline-flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-7 py-4 text-sm font-black text-white shadow-xl shadow-red-600/25 transition hover:bg-red-500"
              >
                {content.hero.cta_primary_text}
                <ArrowRight size={16} />
              </a>
              <a
                href={content.hero.cta_secondary_url}
                className="inline-flex items-center justify-center rounded-2xl border border-white/12 bg-white/5 px-7 py-4 text-sm font-black text-white transition hover:bg-white/10"
              >
                {content.hero.cta_secondary_text}
              </a>
            </div>
          </div>

          <HeroPreview />
        </div>
      </section>

      <section id="recursos" className="landing-anchor relative overflow-hidden bg-white px-5 py-20 sm:py-24">
        <div className="pointer-events-none absolute inset-0 bg-gradient-to-b from-red-50/50 via-white to-orange-50/35" />
        <div className="pointer-events-none absolute left-0 top-0 h-64 w-64 rounded-full bg-red-100/40 blur-3xl" />
        <div className="pointer-events-none absolute bottom-0 right-0 h-80 w-80 rounded-full bg-orange-100/35 blur-3xl" />

        <div className="relative mx-auto max-w-6xl">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-[11px] font-black uppercase tracking-[0.22em] text-red-600">Recursos</p>
            <h2 className="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">
              {content.features_section.title}
            </h2>
            <p className="mt-4 text-base font-medium leading-relaxed text-slate-500">
              {content.features_section.subtitle}
            </p>
          </div>

          <div className="mt-14 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            {content.features.map((feature) => (
              <FeatureCard key={feature.title} feature={feature} />
            ))}
          </div>
        </div>
      </section>

      {content.plans_section.show_plans && displayPlans.length > 0 ? (
        <section id="planos" className="landing-anchor border-y border-slate-100 bg-slate-50 px-5 py-20 sm:py-24">
          <div className="mx-auto max-w-6xl">
            <div className="mx-auto max-w-2xl text-center">
              <p className="text-[11px] font-black uppercase tracking-[0.22em] text-red-600">Planos</p>
              <h2 className="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">
                {content.plans_section.title}
              </h2>
              <p className="mt-4 text-base font-medium leading-relaxed text-slate-500">
                {content.plans_section.subtitle}
              </p>
            </div>

            <div className="mt-14 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
              {displayPlans.map((plan) => {
                const available = isPlanAvailableOnLanding(plan)
                const highlighted = available && (
                  plan.slug === 'premium'
                  || plan.name?.toLowerCase() === 'premium'
                  || visiblePlansCount === 1
                )

                return (
                  <article
                    key={plan.id}
                    className={`relative flex flex-col rounded-[1.75rem] border p-6 transition ${
                      !available
                        ? 'border-dashed border-slate-200 bg-slate-50 opacity-80'
                        : highlighted
                          ? 'border-red-200 bg-white shadow-md shadow-red-500/10 ring-1 ring-red-100 hover:-translate-y-1 hover:shadow-lg'
                          : 'border-slate-200 bg-white shadow-sm hover:-translate-y-1 hover:shadow-lg'
                    }`}
                  >
                    {highlighted ? (
                      <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-red-600 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-white">
                        Oferta de lançamento
                      </span>
                    ) : null}

                    {!available ? (
                      <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full border border-slate-200 bg-white px-3 py-1 text-[10px] font-black uppercase tracking-wider text-slate-500">
                        Em breve
                      </span>
                    ) : null}

                    <p className={`text-[11px] font-black uppercase tracking-widest ${available ? 'text-slate-400' : 'text-slate-400'}`}>
                      {plan.name}
                    </p>
                    <p className={`mt-4 text-4xl font-black tracking-tight ${available ? 'text-slate-900' : 'text-slate-400'}`}>
                      {formatCurrency(plan.price)}
                    </p>
                    <p className={`mt-1 text-sm font-bold ${available ? 'text-slate-400' : 'text-slate-300'}`}>por mês</p>
                    {plan.description ? (
                      <p className={`mt-4 flex-1 text-sm font-medium leading-relaxed ${available ? 'text-slate-500' : 'text-slate-400'}`}>
                        {plan.description}
                      </p>
                    ) : (
                      <div className="flex-1" />
                    )}

                    {available ? (
                      <a
                        href={`${ADMIN_URL}/register`}
                        className={`mt-6 inline-flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-black transition ${
                          highlighted
                            ? 'bg-red-600 text-white shadow-lg shadow-red-600/20 hover:bg-red-500'
                            : 'border border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50'
                        }`}
                      >
                        Começar agora
                        <ArrowRight size={15} />
                      </a>
                    ) : (
                      <p className="mt-6 rounded-2xl border border-dashed border-slate-200 bg-white px-4 py-3 text-center text-xs font-bold text-slate-400">
                        Indisponível no lançamento
                      </p>
                    )}
                  </article>
                )
              })}
            </div>
          </div>
        </section>
      ) : null}

      <section id="interesse" className="landing-anchor relative overflow-hidden bg-slate-950 px-5 py-20 text-white sm:py-24">
        <div className="pointer-events-none absolute inset-0">
          <div className="absolute left-1/2 top-0 h-64 w-64 -translate-x-1/2 rounded-full bg-red-600/20 blur-3xl" />
        </div>

        <div className="relative mx-auto grid max-w-6xl gap-10 lg:grid-cols-[0.95fr_1.05fr] lg:items-center lg:gap-14">
          <div>
            <p className="text-[11px] font-black uppercase tracking-[0.22em] text-red-300">Contato</p>
            <h2 className="mt-3 text-3xl font-black tracking-tight sm:text-4xl">{content.cta_section.title}</h2>
            <p className="mt-4 text-base font-medium leading-relaxed text-slate-300">
              {content.cta_section.subtitle}
            </p>

            <ul className="mt-8 space-y-3">
              {['Sem taxa por pedido', 'Setup guiado', 'Suporte humano'].map((item) => (
                <li key={item} className="flex items-center gap-3 text-sm font-bold text-slate-200">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-500/20 text-red-300">✓</span>
                  {item}
                </li>
              ))}
            </ul>
          </div>

          {content.lead_form.enabled ? (
            <div className="rounded-[2rem] border border-slate-200/10 bg-white p-6 text-slate-900 shadow-2xl sm:p-8">
              <h3 className="text-2xl font-black text-slate-950">{content.lead_form.title}</h3>
              <p className="mt-2 text-sm font-medium text-slate-500">{content.lead_form.subtitle}</p>
              <div className="mt-6">
                <LeadForm form={content.lead_form} />
              </div>
            </div>
          ) : null}
        </div>
      </section>

      </main>

      <Footer navLinks={navLinks} footerText={content.footer.text} hero={content.hero} />
    </div>
  )
}
