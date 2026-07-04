export const SITE_URL = (import.meta.env.VITE_SITE_URL || 'https://partiumenu.com.br').replace(/\/+$/, '')

export const DEFAULT_TITLE = 'Cardápio Digital para Restaurantes | PartiuMenu'

export const DEFAULT_DESCRIPTION =
  'Crie um cardápio digital para seu restaurante, receba pedidos online, organize delivery e retirada, use cupons, Pix e WhatsApp automático sem comissão por pedido.'

export const DEFAULT_KEYWORDS =
  'partiumenu, partiu menu, partiu menu cardápio digital, cardápio digital, cardapio digital, sistema para restaurante, pedidos online, delivery, cardápio online, menu digital, integração ifood'

export const BRAND_ALTERNATE_NAMES = ['Partiu Menu', 'partiu menu', 'PartiuMenu']

export const OG_IMAGE = `${SITE_URL}/og-image.png`

function setMeta(attribute, key, value) {
  if (!value) return

  let element = document.head.querySelector(`meta[${attribute}="${key}"]`)

  if (!element) {
    element = document.createElement('meta')
    element.setAttribute(attribute, key)
    document.head.appendChild(element)
  }

  element.setAttribute('content', value)
}

function setCanonical(url) {
  let element = document.head.querySelector('link[rel="canonical"]')

  if (!element) {
    element = document.createElement('link')
    element.setAttribute('rel', 'canonical')
    document.head.appendChild(element)
  }

  element.setAttribute('href', url)
}

export function buildSeoFromContent() {
  return { title: DEFAULT_TITLE, description: DEFAULT_DESCRIPTION }
}

export function applySeo({ title, description, url = SITE_URL, keywords = DEFAULT_KEYWORDS }) {
  document.title = title

  setMeta('name', 'description', description)
  setMeta('name', 'keywords', keywords)
  setMeta('name', 'robots', 'index, follow')

  setCanonical(url)

  setMeta('property', 'og:type', 'website')
  setMeta('property', 'og:site_name', 'PartiuMenu')
  setMeta('property', 'og:locale', 'pt_BR')
  setMeta('property', 'og:url', url)
  setMeta('property', 'og:title', title)
  setMeta('property', 'og:description', description)
  setMeta('property', 'og:image', OG_IMAGE)

  setMeta('name', 'twitter:card', 'summary_large_image')
  setMeta('name', 'twitter:title', title)
  setMeta('name', 'twitter:description', description)
  setMeta('name', 'twitter:image', OG_IMAGE)
}

export function injectStructuredData() {
  const payload = {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'WebSite',
        '@id': `${SITE_URL}/#website`,
        url: SITE_URL,
        name: 'PartiuMenu',
        description: DEFAULT_DESCRIPTION,
        inLanguage: 'pt-BR',
        publisher: { '@id': `${SITE_URL}/#organization` },
      },
      {
        '@type': 'Organization',
        '@id': `${SITE_URL}/#organization`,
        name: 'PartiuMenu',
        alternateName: BRAND_ALTERNATE_NAMES,
        url: SITE_URL,
        logo: `${SITE_URL}/logo-black.png`,
        description: DEFAULT_DESCRIPTION,
      },
      {
        '@type': 'SoftwareApplication',
        '@id': `${SITE_URL}/#software`,
        name: 'PartiuMenu',
        alternateName: BRAND_ALTERNATE_NAMES,
        applicationCategory: 'BusinessApplication',
        operatingSystem: 'Web',
        url: SITE_URL,
        description: DEFAULT_DESCRIPTION,
        offers: {
          '@type': 'Offer',
          price: '0',
          priceCurrency: 'BRL',
          description: 'Planos mensais para restaurantes e dark kitchens',
        },
        featureList: [
          'Cardápio digital',
          'Pedidos em tempo real',
          'WhatsApp automático',
          'Cupons de desconto',
          'Integração iFood',
        ],
      },
    ],
  }

  const scriptId = 'partiumenu-structured-data'
  let script = document.getElementById(scriptId)

  if (!script) {
    script = document.createElement('script')
    script.id = scriptId
    script.type = 'application/ld+json'
    document.head.appendChild(script)
  }

  script.textContent = JSON.stringify(payload)
}
