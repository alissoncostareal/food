import { SITE_URL } from './seo.js'

export const SEO_PAGES = [
  {
    path: '/partiu-menu',
    title: 'Partiu Menu — Cardápio Digital e Pedidos Online | PartiuMenu',
    description:
      'Procurando Partiu Menu? O PartiuMenu é a plataforma de cardápio digital para restaurantes: pedidos online, delivery, WhatsApp automático e painel simples sem comissão por pedido.',
    keywords:
      'partiu menu, partiumenu, partiu menu cardápio digital, partiu menu delivery, cardápio digital partiu menu',
    h1: 'Partiu Menu: cardápio digital para seu restaurante',
    eyebrow: 'Partiu Menu',
    intro:
      'Se você pesquisou “Partiu Menu”, encontrou o lugar certo: PartiuMenu é a plataforma oficial de cardápio digital, pedidos online e gestão de delivery para restaurantes e dark kitchens.',
    bullets: [
      'Mesma plataforma — PartiuMenu e Partiu Menu',
      'Site oficial: partiumenu.com.br',
      'Cadastro em minutos, sem comissão por pedido na sua loja',
      'WhatsApp automático, cupons, Pix e integração iFood',
    ],
    related: [
      { href: '/cardapio-digital-para-restaurantes', label: 'Cardápio digital para restaurantes' },
      { href: '/sistema-delivery-para-restaurantes', label: 'Sistema delivery para restaurantes' },
    ],
  },
  {
    path: '/cardapio-digital-para-restaurantes',
    title: 'Cardápio digital para restaurantes | PartiuMenu',
    description:
      'Cardápio digital para restaurantes com link próprio, pedidos online em tempo real, WhatsApp automático e painel fácil. Comece sem taxa por pedido.',
    keywords:
      'cardápio digital para restaurantes, menu digital restaurante, cardapio online restaurante, sistema para restaurante',
    h1: 'Cardápio digital para restaurantes',
    eyebrow: 'Restaurantes',
    intro:
      'Coloque seu cardápio no ar em minutos, receba pedidos ao vivo no painel e avise o cliente no WhatsApp — tudo sem depender só de marketplaces.',
    bullets: [
      'Link próprio para compartilhar no Instagram e WhatsApp',
      'Pedidos em tempo real com som e notificação',
      'Cupons, destaques no carrinho e áreas de entrega',
      'Integração iFood no plano Premium',
    ],
    related: [
      { href: '/sistema-delivery-para-restaurantes', label: 'Sistema delivery para restaurantes' },
      { href: '/cardapio-digital-para-pizzaria', label: 'Cardápio digital para pizzaria' },
    ],
  },
  {
    path: '/sistema-delivery-para-restaurantes',
    title: 'Sistema delivery para restaurantes | PartiuMenu',
    description:
      'Sistema de delivery para restaurantes com pedidos online, gestão de entrega, WhatsApp automático e relatórios. Plataforma simples para vender mais.',
    keywords:
      'sistema delivery para restaurantes, sistema de pedidos delivery, plataforma delivery restaurante, pedidos online restaurante',
    h1: 'Sistema delivery para restaurantes',
    eyebrow: 'Delivery',
    intro:
      'Organize pedidos de delivery e retirada em um só lugar: cardápio digital, painel ao vivo e comunicação automática com o cliente.',
    bullets: [
      'Retirada e entrega no mesmo fluxo de pedido',
      'Áreas de entrega e taxa por bairro',
      'Status do pedido com aviso no WhatsApp',
      'Sem comissão por pedido na sua loja online',
    ],
    related: [
      { href: '/cardapio-digital-para-restaurantes', label: 'Cardápio digital para restaurantes' },
      { href: '/cardapio-digital-para-hamburgueria', label: 'Cardápio digital para hamburgueria' },
    ],
  },
  {
    path: '/cardapio-digital-para-pizzaria',
    title: 'Cardápio digital para pizzaria | PartiuMenu',
    description:
      'Cardápio digital para pizzaria com sabores, bordas e complementos, pedidos online e WhatsApp automático. Ideal para pizzarias e delivery.',
    keywords:
      'cardápio digital pizzaria, cardapio online pizzaria, sistema para pizzaria, menu digital pizzaria',
    h1: 'Cardápio digital para pizzaria',
    eyebrow: 'Pizzarias',
    intro:
      'Monte sabores, tamanhos e adicionais com fotos, publique seu link e receba pedidos organizados — inclusive com integração iFood quando precisar.',
    bullets: [
      'Grupos de complementos com foto (bordas, bebidas, etc.)',
      'Destaques no carrinho para aumentar o ticket',
      'Pausar item esgotado em um clique',
      'Painel simples para a cozinha acompanhar pedidos',
    ],
    related: [
      { href: '/cardapio-digital-para-restaurantes', label: 'Cardápio digital para restaurantes' },
      { href: '/sistema-delivery-para-restaurantes', label: 'Sistema delivery para restaurantes' },
    ],
  },
  {
    path: '/cardapio-digital-para-hamburgueria',
    title: 'Cardápio digital para hamburgueria | PartiuMenu',
    description:
      'Cardápio digital para hamburgueria com combos, adicionais e fotos. Pedidos online, WhatsApp automático e gestão fácil no painel PartiuMenu.',
    keywords:
      'cardápio digital hamburgueria, cardapio online hamburgueria, sistema para hamburgueria, menu digital burger',
    h1: 'Cardápio digital para hamburgueria',
    eyebrow: 'Hamburguerias',
    intro:
      'Combos, molhos e extras com visual de app, link para redes sociais e pedidos caindo ao vivo no painel — feito para hamburgueria que vende no delivery.',
    bullets: [
      'Opcionais com foto para cada adicional',
      'Cupom de desconto no checkout',
      'Endereço salvo para o cliente voltar a pedir',
      'Integração iFood para quem também vende no app',
    ],
    related: [
      { href: '/cardapio-digital-para-restaurantes', label: 'Cardápio digital para restaurantes' },
      { href: '/sistema-delivery-para-restaurantes', label: 'Sistema delivery para restaurantes' },
    ],
  },
]

export function getSeoPageByPath(pathname) {
  const normalized = pathname.replace(/\/$/, '') || '/'

  return SEO_PAGES.find((page) => page.path === normalized) || null
}

export function getAllPublicPaths() {
  return [
    { loc: `${SITE_URL}/`, priority: '1.0', changefreq: 'weekly' },
    ...SEO_PAGES.map((page) => ({
      loc: `${SITE_URL}${page.path}`,
      priority: '0.9',
      changefreq: 'monthly',
    })),
    { loc: `${SITE_URL}/privacidade`, priority: '0.3', changefreq: 'yearly' },
    { loc: `${SITE_URL}/exclusao-de-dados`, priority: '0.3', changefreq: 'yearly' },
  ]
}
