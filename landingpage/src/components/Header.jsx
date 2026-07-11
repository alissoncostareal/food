import { useEffect, useState } from 'react'
import { ArrowRight, ArrowUpRight, Menu, X } from 'lucide-react'
import { ADMIN_URL } from '../lib/constants.js'

export default function Header({ navLinks, ctaPrimaryText, ctaPrimaryUrl }) {
  const [menuOpen, setMenuOpen] = useState(false)

  useEffect(() => {
    document.body.style.overflow = menuOpen ? 'hidden' : ''

    return () => {
      document.body.style.overflow = ''
    }
  }, [menuOpen])

  useEffect(() => {
    const closeMenu = () => setMenuOpen(false)

    window.addEventListener('hashchange', closeMenu)

    return () => window.removeEventListener('hashchange', closeMenu)
  }, [])

  return (
    <>
      <header className="fixed inset-x-0 top-0 z-50 border-b border-slate-200/70 bg-white/75 backdrop-blur-md shadow-sm shadow-slate-900/5">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-5 py-3 sm:gap-4 sm:py-5">
          <a href="#" className="shrink-0" onClick={() => setMenuOpen(false)}>
            <img
              src="/logo-mobile.png"
              alt="PartiuMenu"
              className="h-14 w-auto max-w-[240px] object-contain object-left sm:hidden"
            />
            <img
              src="/logo-black.png"
              alt="PartiuMenu"
              className="hidden h-[3.5rem] w-auto max-w-[320px] object-contain object-left sm:block"
            />
          </a>

          <nav className="hidden items-center gap-1 md:flex">
            {navLinks.map((link) => (
              <a
                key={link.href}
                href={link.href}
                className="rounded-lg px-4 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
              >
                {link.label}
              </a>
            ))}
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
              href={ctaPrimaryUrl}
              className="hidden items-center gap-1.5 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-black text-white shadow-md shadow-red-500/25 transition hover:bg-red-500 sm:inline-flex"
            >
              {ctaPrimaryText}
              <ArrowRight size={15} />
            </a>

            <button
              type="button"
              aria-expanded={menuOpen}
              aria-label={menuOpen ? 'Fechar menu' : 'Abrir menu'}
              onClick={() => setMenuOpen((open) => !open)}
              className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 md:hidden"
            >
              {menuOpen ? <X size={20} /> : <Menu size={20} />}
            </button>
          </div>
        </div>
      </header>

      {menuOpen ? (
        <div className="fixed inset-0 z-40 md:hidden">
          <button
            type="button"
            aria-label="Fechar menu"
            className="absolute inset-0 bg-slate-900/20 backdrop-blur-[2px]"
            onClick={() => setMenuOpen(false)}
          />

          <div className="absolute inset-x-0 top-[5.5rem] border-b border-slate-200 bg-white px-5 py-6 shadow-xl shadow-slate-900/10 sm:top-[7.5rem]">
            <nav className="space-y-1">
              {navLinks.map((link) => (
                <a
                  key={link.href}
                  href={link.href}
                  onClick={() => setMenuOpen(false)}
                  className="flex items-center justify-between rounded-2xl px-4 py-3.5 text-base font-black text-slate-900 transition hover:bg-slate-100"
                >
                  {link.label}
                  <ArrowRight size={16} className="text-red-500" />
                </a>
              ))}
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
                href={ctaPrimaryUrl}
                onClick={() => setMenuOpen(false)}
                className="flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-4 py-3.5 text-sm font-black text-white shadow-lg shadow-red-600/25"
              >
                {ctaPrimaryText}
                <ArrowRight size={16} />
              </a>
            </div>
          </div>
        </div>
      ) : null}
    </>
  )
}
