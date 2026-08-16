import { ArrowRight } from 'lucide-react'
import { SEO_PAGES } from '../lib/seoPages.js'

const hubLinks = SEO_PAGES.filter((page) => page.path !== '/partiu-menu').slice(0, 5)

export default function SeoHubSection() {
  return (
    <section className="relative overflow-hidden border-t border-slate-100 bg-white px-5 py-14 sm:py-16" aria-label="Soluções PartiuMenu">
      <div className="pointer-events-none absolute inset-x-0 top-0 h-32 bg-gradient-to-b from-red-50/40 to-transparent" />
      <div className="relative mx-auto max-w-6xl">
        <p className="text-[11px] font-black uppercase tracking-[0.22em] text-orange-600">Cardápio digital</p>
        <h2 className="mt-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">
          Sistema de cardápio digital e delivery para restaurantes
        </h2>
        <p className="mt-4 max-w-2xl text-sm font-medium leading-relaxed text-slate-500 sm:text-base">
          Conheça as páginas com mais detalhes sobre cardápio digital, pedidos online e gestão de delivery no PartiuMenu.
        </p>

        <ul className="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {hubLinks.map((page) => (
            <li key={page.path}>
              <a
                href={page.path}
                className="group flex h-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm font-bold text-slate-700 shadow-sm transition hover:border-red-200 hover:text-red-600"
              >
                <span>{page.h1}</span>
                <ArrowRight size={16} className="shrink-0 text-slate-300 transition group-hover:text-red-500" />
              </a>
            </li>
          ))}
        </ul>
      </div>
    </section>
  )
}
