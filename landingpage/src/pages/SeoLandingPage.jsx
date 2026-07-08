import { useEffect } from 'react'
import { ArrowRight, Check } from 'lucide-react'
import { ADMIN_URL } from '../lib/constants.js'
import { applySeo, SITE_URL } from '../lib/seo.js'

export default function SeoLandingPage({ page }) {
  useEffect(() => {
    if (!page) return

    applySeo({
      title: page.title,
      description: page.description,
      url: `${SITE_URL}${page.path}`,
      keywords: page.keywords,
    })

    const scriptId = 'partiumenu-seo-structured-data'
    let script = document.getElementById(scriptId)

    if (!script) {
      script = document.createElement('script')
      script.id = scriptId
      script.type = 'application/ld+json'
      document.head.appendChild(script)
    }

    const structured = {
      '@context': 'https://schema.org',
      '@graph': [
        {
          '@type': 'WebPage',
          name: page.title,
          description: page.description,
          url: `${SITE_URL}${page.path}`,
          inLanguage: 'pt-BR',
          isPartOf: { '@id': `${SITE_URL}/#website` },
          about: {
            '@type': 'SoftwareApplication',
            name: 'PartiuMenu',
            applicationCategory: 'BusinessApplication',
            operatingSystem: 'Web',
          },
        },
      ],
    }

    if (page.faq?.length) {
      structured['@graph'].push({
        '@type': 'FAQPage',
        mainEntity: page.faq.map((item) => ({
          '@type': 'Question',
          name: item.question,
          acceptedAnswer: {
            '@type': 'Answer',
            text: item.answer,
          },
        })),
      })
    }

    script.textContent = JSON.stringify(structured)
  }, [page])

  if (!page) return null

  return (
    <div className="min-h-screen bg-white text-slate-900">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-4">
          <a href="/">
            <img src="/logo-black.png" alt="PartiuMenu" className="h-9 w-auto object-contain" />
          </a>
          <a
            href={`${ADMIN_URL}/register`}
            className="inline-flex items-center gap-2 rounded-2xl bg-red-600 px-4 py-2.5 text-sm font-black text-white transition hover:bg-red-500"
          >
            Criar conta grátis
            <ArrowRight size={15} />
          </a>
        </div>
      </header>

      <main>
        <section className="hero-banner relative isolate overflow-hidden px-5 pb-16 pt-14 text-white sm:pb-20 sm:pt-16">
          <div className="pointer-events-none absolute inset-0 hero-grid" />
          <div className="pointer-events-none absolute inset-0 hero-vignette" />
          <div className="relative mx-auto max-w-5xl">
            <p className="text-[11px] font-black uppercase tracking-[0.2em] text-red-200">{page.eyebrow}</p>
            <h1 className="mt-4 max-w-3xl text-4xl font-black leading-tight tracking-tight sm:text-5xl">
              {page.h1}
            </h1>
            <p className="mt-5 max-w-2xl text-base font-medium leading-relaxed text-slate-300 sm:text-lg">
              {page.intro}
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <a
                href={`${ADMIN_URL}/register`}
                className="inline-flex items-center justify-center gap-2 rounded-2xl bg-red-600 px-7 py-4 text-sm font-black text-white shadow-xl shadow-red-600/25 transition hover:bg-red-500"
              >
                Começar agora
                <ArrowRight size={16} />
              </a>
              <a
                href="/#interesse"
                className="inline-flex items-center justify-center rounded-2xl border border-white/12 bg-white/5 px-7 py-4 text-sm font-black text-white transition hover:bg-white/10"
              >
                Falar com a equipe
              </a>
            </div>
          </div>
        </section>

        <section className="px-5 py-16 sm:py-20">
          <div className="mx-auto grid max-w-5xl gap-10 lg:grid-cols-[1fr_1fr] lg:items-start">
            <div>
              <h2 className="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">
                Por que usar o PartiuMenu
              </h2>
              <p className="mt-4 text-base font-medium leading-relaxed text-slate-500">
                Plataforma pensada para quem vende comida todos os dias: configure o cardápio, compartilhe o link e
                acompanhe os pedidos sem complicação.
              </p>
            </div>
            <ul className="space-y-4">
              {page.bullets.map((item) => (
                <li key={item} className="flex items-start gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                  <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <Check size={14} strokeWidth={3} />
                  </span>
                  <span className="text-sm font-bold leading-relaxed text-slate-700">{item}</span>
                </li>
              ))}
            </ul>
          </div>
        </section>

        {page.sections?.length ? (
          <section className="border-t border-slate-100 bg-white px-5 py-16 sm:py-20">
            <div className="mx-auto max-w-5xl space-y-12">
              {page.sections.map((section) => (
                <article key={section.title}>
                  <h2 className="text-xl font-black tracking-tight text-slate-950 sm:text-2xl">{section.title}</h2>
                  <p className="mt-4 text-base font-medium leading-relaxed text-slate-600">{section.body}</p>
                </article>
              ))}
            </div>
          </section>
        ) : null}

        {page.faq?.length ? (
          <section className="border-t border-slate-100 bg-slate-50 px-5 py-16 sm:py-20">
            <div className="mx-auto max-w-5xl">
              <h2 className="text-2xl font-black tracking-tight text-slate-950">Perguntas frequentes</h2>
              <dl className="mt-8 space-y-6">
                {page.faq.map((item) => (
                  <div key={item.question} className="rounded-2xl border border-slate-200 bg-white p-5">
                    <dt className="text-sm font-black text-slate-900">{item.question}</dt>
                    <dd className="mt-2 text-sm font-medium leading-relaxed text-slate-600">{item.answer}</dd>
                  </div>
                ))}
              </dl>
            </div>
          </section>
        ) : null}

        {page.related?.length ? (
          <section className="border-t border-slate-100 bg-slate-50 px-5 py-12">
            <div className="mx-auto max-w-5xl">
              <h2 className="text-lg font-black text-slate-950">Veja também</h2>
              <ul className="mt-4 flex flex-wrap gap-3">
                {page.related.map((link) => (
                  <li key={link.href}>
                    <a
                      href={link.href}
                      className="inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-red-200 hover:text-red-600"
                    >
                      {link.label}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          </section>
        ) : null}
      </main>

      <footer className="border-t border-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl flex-col gap-3 px-5 py-8 text-xs font-bold text-slate-500 sm:flex-row sm:items-center sm:justify-between">
          <p>© {new Date().getFullYear()} PartiuMenu</p>
          <div className="flex flex-wrap gap-4">
            <a href="/" className="hover:text-slate-700">
              Início
            </a>
            <a href="/privacidade" className="hover:text-slate-700">
              Privacidade
            </a>
            <a href="/exclusao-de-dados" className="hover:text-slate-700">
              Exclusão de dados
            </a>
          </div>
        </div>
      </footer>
    </div>
  )
}
