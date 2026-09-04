export const SITE_URL = (import.meta.env.VITE_SITE_URL || 'https://partiumenu.com.br').replace(/\/+$/, '')

export const DEFAULT_TITLE = 'Cardápio Digital para Restaurantes e Delivery | PartiuMenu'

export const DEFAULT_DESCRIPTION =
  'Cardápio digital para restaurantes: pedidos online, Pix, cupons e WhatsApp automático. Crie sua conta, publique o menu e compartilhe o link — sem comissão por pedido e sem precisar falar com vendedor.'

export const DEFAULT_KEYWORDS =
  'cardápio digital, cardapio digital, cardápio digital para restaurantes, cardápio digital delivery, menu digital, cardápio online, sistema de pedidos online, partiumenu, partiu menu, delivery próprio, pedidos whatsapp'

export const BRAND_ALTERNATE_NAMES = ['Partiu Menu', 'partiu menu', 'PartiuMenu']

export const OG_IMAGE = `${SITE_URL}/og-image.png`

const HOME_FAQ = [
  {
    question: 'O que é um cardápio digital?',
    answer:
      'É o menu online do seu restaurante: o cliente abre o link no celular, escolhe os itens e finaliza o pedido. No PartiuMenu você publica categorias, fotos e preços e recebe tudo organizado no painel.',
  },
  {
    question: 'Preciso falar com um vendedor para começar?',
    answer:
      'Não. Você cria a conta sozinho, monta o cardápio digital e compartilha o link. Também pode testar a loja demo antes de cadastrar.',
  },
  {
    question: 'O PartiuMenu cobra comissão por pedido?',
    answer:
      'Não. O plano é mensal e você não paga comissão por pedido no cardápio digital próprio.',
  },
  {
    question: 'Em quanto tempo o cardápio digital fica no ar?',
    answer:
      'Em poucos minutos após o cadastro você já pode publicar produtos e enviar o link para Instagram, WhatsApp ou QR Code.',
  },
]

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
        alternateName: BRAND_ALTERNATE_NAMES,
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
          description: 'Comece criando sua conta — planos mensais sem comissão por pedido',
        },
        featureList: [
          'Cardápio digital',
          'Pedidos em tempo real',
          'WhatsApp automático',
          'Cupons de desconto',
          'Integração iFood',
        ],
      },
      {
        '@type': 'FAQPage',
        '@id': `${SITE_URL}/#faq`,
        mainEntity: HOME_FAQ.map((item) => ({
          '@type': 'Question',
          name: item.question,
          acceptedAnswer: {
            '@type': 'Answer',
            text: item.answer,
          },
        })),
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

export { HOME_FAQ }
