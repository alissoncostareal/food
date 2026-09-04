import { useEffect, useState } from 'react'
import { ArrowRight, ArrowUpRight, Menu, X } from 'lucide-react'
import { ADMIN_URL, DEMO_STORE_URL, REGISTER_URL } from '../lib/constants.js'

function scrollToHash(href) {
  if (!href?.startsWith('#')) return false

  const id = href.slice(1)
  const el = document.getElementById(id)

  if (!el) return false

  const header = document.querySelector('[data-landing-header]')
  // Só a altura do header: sem folga extra (evita faixa da seção anterior)
  const offset = Math.ceil(header?.getBoundingClientRect().height || 76)
  const top = el.getBoundingClientRect().top + window.scrollY - offset

  window.scrollTo({ top: Math.max(0, Math.round(top)), behavior: 'smooth' })
  history.pushState(null, '', href)

  return true
}

export default function Header({ navLinks }) {
  const [menuOpen, setMenuOpen] = useState(false)

  useEffect(() => {
    document.body.style.overflow = menuOpen ? 'hidden' : ''

    return () => {
      document.body.style.overflow = ''
    }
  }, [menuOpen])

  useEffect(() => {
    const syncHeaderOffset = () => {
      const header = document.querySelector('[data-landing-header]')
      if (!header) return
      document.documentElement.style.setProperty(
        '--header-offset',
        `${Math.ceil(header.getBoundingClientRect().height)}px`
      )
    }

    syncHeaderOffset()
    window.addEventListener('resize', syncHeaderOffset)

    return () => window.removeEventListener('resize', syncHeaderOffset)
  }, [menuOpen])

  const handleHashClick = (event, href) => {
    if (!href?.startsWith('#')) return

    event.preventDefault()
    setMenuOpen(false)

    // Espera o menu mobile fechar (body overflow) antes de medir o scroll
    window.requestAnimationFrame(() => {
      window.setTimeout(() => scrollToHash(href), 30)
    })
  }

  return (
    <>
      <header
        data-landing-header
        className="fixed inset-x-0 top-0 z-50 border-b border-slate-200 bg-white shadow-sm shadow-slate-900/5"
      >
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-5 py-3 sm:gap-4 sm:py-4">
          <a href="/" className="shrink-0" onClick={() => setMenuOpen(false)}>
            <img
              src="/logo-black.png"
              alt="PartiuMenu — cardápio digital"
              className="h-11 w-auto max-w-[200px] object-contain object-left sm:h-[3.25rem] sm:max-w-[280px]"
            />
          </a>

          <nav className="hidden items-center gap-1 lg:flex">
            {navLinks.map((link) => (
              <a
                key={link.href}
                href={link.href}
                onClick={(event) => handleHashClick(event, link.href)}
                className="rounded-lg px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
              >
                {link.label}
              </a>
            ))}
            <a
              href={DEMO_STORE_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="rounded-lg px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
            >
              Ver demo
            </a>
          </nav>

          <div className="flex items-center gap-2 sm:gap-2.5">
            <a
              href={ADMIN_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="hidden items-center gap-1 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm font-black text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 sm:inline-flex"
            >
              Entrar
              <ArrowUpRight size={14} />
            </a>

            <a
              href={REGISTER_URL}
              className="hidden items-center gap-1.5 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white shadow-md shadow-red-500/25 transition hover:bg-red-500 sm:inline-flex"
            >
              Criar conta
              <ArrowRight size={15} />
            </a>

            <button
              type="button"
              aria-expanded={menuOpen}
              aria-label={menuOpen ? 'Fechar menu' : 'Abrir menu'}
              onClick={() => setMenuOpen((open) => !open)}
              className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 lg:hidden"
            >
              {menuOpen ? <X size={20} /> : <Menu size={20} />}
            </button>
          </div>
        </div>
      </header>

      {menuOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button
            type="button"
            aria-label="Fechar menu"
            className="absolute inset-0 bg-slate-900/20"
            onClick={() => setMenuOpen(false)}
          />

          <div className="absolute inset-x-0 top-[4.75rem] border-b border-slate-200 bg-white px-5 py-6 shadow-xl shadow-slate-900/10 sm:top-[5.5rem]">
            <nav className="space-y-1">
              {navLinks.map((link) => (
                <a
                  key={link.href}
                  href={link.href}
                  onClick={(event) => handleHashClick(event, link.href)}
                  className="flex items-center justify-between rounded-2xl px-4 py-3.5 text-base font-black text-slate-900 transition hover:bg-slate-100"
                >
                  {link.label}
                  <ArrowRight size={16} className="text-red-500" />
                </a>
              ))}
              <a
                href={DEMO_STORE_URL}
                target="_blank"
                rel="noopener noreferrer"
                onClick={() => setMenuOpen(false)}
                className="flex items-center justify-between rounded-2xl px-4 py-3.5 text-base font-black text-slate-900 transition hover:bg-slate-100"
              >
                Ver cardápio demo
                <ArrowRight size={16} className="text-red-500" />
              </a>
            </nav>

            <div className="mt-6 space-y-3 border-t border-slate-200 pt-6">
              <a
                href={ADMIN_URL}
                target="_blank"
                rel="noopener noreferrer"
                onClick={() => setMenuOpen(false)}
                className="flex items-center justify-center gap-1.5 rounded-2xl border border-slate-200 bg-white px-4 py-3.5 text-sm font-black text-slate-700"
              >
                Entrar no painel
                <ArrowUpRight size={15} />
              </a>
              <a
                href={REGISTER_URL}
                onClick={() => setMenuOpen(false)}
                className="flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-4 py-3.5 text-sm font-black text-white shadow-lg shadow-red-600/25"
              >
                Criar conta grátis
                <ArrowRight size={16} />
              </a>
            </div>
          </div>
        </div>
      ) : null}
    </>
  )
}
