import { ArrowRight, ArrowUpRight } from 'lucide-react'
import { ADMIN_URL } from '../lib/constants.js'

const productHighlights = [
  'Cardápio digital com link próprio',
  'Pedidos em tempo real',
  'Cupons de desconto no checkout',
]

export default function Footer({ navLinks, footerText, hero }) {
  const year = new Date().getFullYear()

  return (
    <footer className="border-t border-slate-200 bg-white text-slate-900">
      <div className="mx-auto max-w-6xl px-5 pb-8 pt-14 sm:pt-16">
        <div className="grid gap-12 lg:grid-cols-[1.35fr_0.85fr_0.85fr] lg:gap-10">
          <div>
            <a href="#" className="inline-block">
              <img
                src="/logo-black.png"
                alt="PartiuMenu"
                className="h-10 w-auto max-w-[200px] object-contain sm:h-12"
              />
            </a>
            <p className="mt-5 max-w-sm text-sm font-medium leading-relaxed text-slate-500">
              PartiuMenu — cardápio digital, pedidos ao vivo e integrações para restaurantes e dark kitchens em um painel
              simples.
            </p>

            <div className="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
              <a
                href={hero?.cta_primary_url || '#interesse'}
                className="inline-flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-5 py-3 text-sm font-black text-white shadow-lg shadow-red-600/25 transition hover:bg-red-500"
              >
                {hero?.cta_primary_text || 'Quero conhecer'}
                <ArrowRight size={15} />
              </a>
              <a
                href={ADMIN_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center justify-center gap-1.5 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
              >
                Acessar painel
                <ArrowUpRight size={15} />
              </a>
            </div>
          </div>

          <div>
            <p className="text-[11px] font-black uppercase tracking-[0.2em] text-red-600">Navegação</p>
            <ul className="mt-5 space-y-3">
              {navLinks.map((link) => (
                <li key={link.href}>
                  <a
                    href={link.href}
                    className="text-sm font-bold text-slate-600 transition hover:text-slate-900"
                  >
                    {link.label}
                  </a>
                </li>
              ))}
              <li>
                <a
                  href={ADMIN_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 text-sm font-bold text-slate-600 transition hover:text-slate-900"
                >
                  Entrar no painel
                  <ArrowUpRight size={14} />
                </a>
              </li>
            </ul>
          </div>

          <div>
            <p className="text-[11px] font-black uppercase tracking-[0.2em] text-red-600">Por que PartiuMenu</p>
            <ul className="mt-5 space-y-3">
              {productHighlights.map((item) => (
                <li key={item} className="flex items-start gap-3 text-sm font-medium text-slate-600">
                  <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-50 text-[11px] text-red-600">
                    ✓
                  </span>
                  {item}
                </li>
              ))}
            </ul>
          </div>
        </div>

        <div className="mt-12 flex flex-col gap-4 border-t border-slate-200 pt-8 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-xs font-bold text-slate-500">
            {footerText || `© ${year} PartiuMenu — tecnologia para delivery e restaurantes.`}
          </p>
          <div className="flex flex-wrap items-center gap-4 text-xs font-bold text-slate-400">
            <a href="/privacidade" className="transition hover:text-slate-600">
              Privacidade
            </a>
            <a href="/exclusao-de-dados" className="transition hover:text-slate-600">
              Exclusão de dados
            </a>
            <p>Feito para quem vive de delivery</p>
          </div>
        </div>
      </div>
    </footer>
  )
}
