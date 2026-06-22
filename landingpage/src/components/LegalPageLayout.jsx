import { ArrowLeft } from 'lucide-react'
import { ADMIN_URL } from '../lib/constants.js'

export default function LegalPageLayout({ title, lastUpdated, children }) {
  const year = new Date().getFullYear()

  return (
    <div className="min-h-screen bg-white text-slate-900">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-5 py-4">
          <a href="/" className="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-900">
            <ArrowLeft size={16} />
            Voltar ao site
          </a>
          <a href="/">
            <img src="/logo-black.png" alt="PartiuMenu" className="h-9 w-auto object-contain" />
          </a>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-5 py-12 sm:py-16">
        <p className="text-[11px] font-black uppercase tracking-[0.2em] text-red-600">Legal</p>
        <h1 className="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">{title}</h1>
        {lastUpdated ? (
          <p className="mt-3 text-sm font-medium text-slate-500">Última atualização: {lastUpdated}</p>
        ) : null}

        <div className="prose-legal mt-10 space-y-6 text-sm font-medium leading-relaxed text-slate-600 sm:text-base">
          {children}
        </div>
      </main>

      <footer className="border-t border-slate-200 bg-slate-50">
        <div className="mx-auto flex max-w-3xl flex-col gap-3 px-5 py-8 text-xs font-bold text-slate-500 sm:flex-row sm:items-center sm:justify-between">
          <p>© {year} PartiuMenu</p>
          <div className="flex flex-wrap gap-4">
            <a href="/privacidade" className="text-red-600 hover:text-red-700">
              Privacidade
            </a>
            <a href={ADMIN_URL} target="_blank" rel="noopener noreferrer" className="hover:text-slate-700">
              Painel do lojista
            </a>
            <a href="mailto:alisson.franciscocosta@gmail.com" className="hover:text-slate-700">
              Contato
            </a>
          </div>
        </div>
      </footer>
    </div>
  )
}
