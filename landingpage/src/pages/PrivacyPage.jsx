import { useEffect } from 'react'
import LegalPageLayout from '../components/LegalPageLayout.jsx'

const CONTACT_EMAIL = 'alisson.franciscocosta@gmail.com'

export default function PrivacyPage() {
  useEffect(() => {
    document.title = 'Política de Privacidade — PartiuMenu'
    document.querySelector('meta[name="description"]')?.setAttribute(
      'content',
      'Política de Privacidade do PartiuMenu: como tratamos dados de lojistas, clientes finais, pedidos, WhatsApp e integrações.'
    )
    document.querySelector('link[rel="canonical"]')?.setAttribute('href', 'https://partiumenu.com.br/privacidade')
  }, [])

  return (
    <LegalPageLayout title="Política de Privacidade" lastUpdated="10 de junho de 2026">
      <section className="space-y-4">
        <p>
          Esta Política de Privacidade descreve como o <strong>PartiuMenu</strong> (“nós”, “plataforma”) coleta, usa,
          armazena e compartilha dados pessoais ao operar o site <strong>partiumenu.com.br</strong>, o painel
          administrativo, os cardápios digitais das lojas e integrações conectadas (WhatsApp, pagamentos, mapas e
          login social).
        </p>
        <p>
          Ao utilizar nossos serviços, você declara ter lido esta política. Em caso de dúvidas ou solicitações
          relacionadas à Lei Geral de Proteção de Dados (LGPD), entre em contato:{' '}
          <a href={`mailto:${CONTACT_EMAIL}`} className="font-bold text-red-600 hover:text-red-700">
            {CONTACT_EMAIL}
          </a>
          .
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">1. Quem somos</h2>
        <p>
          O PartiuMenu é uma plataforma de tecnologia para restaurantes, dark kitchens e estabelecimentos de alimentação,
          oferecendo cardápio digital, gestão de pedidos, integrações e comunicação com clientes.
        </p>
        <p>
          <strong>Controlador dos dados:</strong> PartiuMenu / Alisson Francisco da Costa Pereira
          <br />
          <strong>Contato do encarregado (DPO):</strong> {CONTACT_EMAIL}
          <br />
          <strong>Endereço:</strong> Rua Mateus Tavares, 18 — Fortaleza/CE — CEP 60349-490 — Brasil
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">2. Quais dados coletamos</h2>
        <p>Dependendo de como você usa a plataforma, podemos tratar:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>
            <strong>Lojistas e equipe:</strong> nome, e-mail, telefone, senha (armazenada de forma protegida),
            identificador de login social (Google), dados da loja, endereço, configurações e logs de acesso ao painel.
          </li>
          <li>
            <strong>Clientes finais (cardápio da loja):</strong> nome, telefone, e-mail (quando informado), endereço de
            entrega, itens do pedido, forma de pagamento escolhida e histórico de pedidos na loja.
          </li>
          <li>
            <strong>Comunicação WhatsApp:</strong> número de telefone, conteúdo de mensagens trocadas com a loja ou com
            a plataforma (incluindo códigos de verificação OTP), metadados de entrega e status de conexão do número.
          </li>
          <li>
            <strong>Pagamentos:</strong> dados necessários para processar cobranças de assinatura ou pedidos online
            (processados por provedores de pagamento; não armazenamos dados completos de cartão).
          </li>
          <li>
            <strong>Dados técnicos:</strong> endereço IP, tipo de navegador, identificadores de sessão, cookies
            essenciais e registros de erro para segurança e diagnóstico.
          </li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">3. Para que usamos os dados</h2>
        <ul className="list-disc space-y-2 pl-5">
          <li>Criar e administrar contas de lojistas e colaboradores;</li>
          <li>Receber, processar e exibir pedidos em tempo real;</li>
          <li>Enviar notificações de pedido e status por WhatsApp, quando habilitado;</li>
          <li>Autenticar usuários (e-mail/senha, código OTP ou login com Google);</li>
          <li>Processar assinaturas e pagamentos da plataforma;</li>
          <li>Melhorar segurança, prevenir fraudes e cumprir obrigações legais;</li>
          <li>Prestar suporte e responder solicitações dos titulares.</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">4. Bases legais (LGPD)</h2>
        <p>Tratamos dados pessoais com fundamento, conforme o caso, em:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>execução de contrato ou procedimentos preliminares (cadastro e uso da plataforma);</li>
          <li>legítimo interesse (segurança, melhoria do serviço e prevenção a abusos);</li>
          <li>cumprimento de obrigação legal ou regulatória;</li>
          <li>consentimento, quando exigido (ex.: comunicações opcionais ou integrações ativadas pelo titular).</li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">5. Compartilhamento com terceiros</h2>
        <p>Podemos compartilhar dados apenas quando necessário para operar o serviço, com provedores como:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>
            <strong>Meta (WhatsApp Business Platform):</strong> envio e recebimento de mensagens oficiais, quando a loja
            ou a plataforma utiliza essa integração;
          </li>
          <li>
            <strong>Google:</strong> login no painel administrativo e, quando configurado, serviços de mapas/endereço;
          </li>
          <li>
            <strong>Provedores de hospedagem, e-mail, pagamentos e infraestrutura</strong> contratados para operar a
            plataforma;
          </li>
          <li>
            <strong>Integrações escolhidas pelo lojista</strong> (ex.: iFood), conforme autorizado no painel.
          </li>
        </ul>
        <p>
          Esses parceiros tratam dados conforme seus próprios termos e políticas. Não vendemos dados pessoais a
          terceiros para fins de marketing.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">6. Retenção e segurança</h2>
        <p>
          Mantemos os dados pelo tempo necessário para cumprir as finalidades desta política, obrigações legais e
          resolução de disputas. Aplicamos medidas técnicas e organizacionais razoáveis para proteger as informações,
          incluindo criptografia de credenciais sensíveis, controle de acesso e registros de auditoria.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">7. Seus direitos</h2>
        <p>Nos termos da LGPD, você pode solicitar, quando aplicável:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>confirmação do tratamento e acesso aos dados;</li>
          <li>correção de dados incompletos ou desatualizados;</li>
          <li>anonimização, bloqueio ou eliminação de dados desnecessários;</li>
          <li>portabilidade e informação sobre compartilhamentos;</li>
          <li>revogação de consentimento e oposição a tratamentos baseados em legítimo interesse.</li>
        </ul>
        <p>
          Envie pedidos para{' '}
          <a href={`mailto:${CONTACT_EMAIL}`} className="font-bold text-red-600 hover:text-red-700">
            {CONTACT_EMAIL}
          </a>
          . Responderemos em prazo razoável, conforme a legislação.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">8. Cookies e tecnologias similares</h2>
        <p>
          Utilizamos cookies e armazenamento local essenciais para autenticação, preferências de sessão e funcionamento
          do painel. Você pode gerenciar cookies no navegador; a desativação pode limitar funcionalidades.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">9. Menores de idade</h2>
        <p>
          O PartiuMenu não é destinado a menores de 18 anos na condição de lojistas. Clientes finais podem fazer pedidos
          com dados de contato fornecidos por responsáveis; não coletamos intencionalmente dados de crianças.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">10. Alterações desta política</h2>
        <p>
          Podemos atualizar esta política para refletir mudanças legais ou de produto. A data da última atualização
          será revisada no topo desta página. O uso continuado após alterações indica ciência da versão vigente.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">11. Exclusão de dados</h2>
        <p>
          Para solicitar exclusão de conta ou dados pessoais tratados pelo PartiuMenu, envie e-mail para{' '}
          <a href={`mailto:${CONTACT_EMAIL}`} className="font-bold text-red-600 hover:text-red-700">
            {CONTACT_EMAIL}
          </a>{' '}
          com o assunto “Exclusão de dados — PartiuMenu”, informando o e-mail ou telefone cadastrado. Avaliaremos
          pedidos de lojistas, clientes e usuários de integrações conforme obrigações legais e contratuais de retenção.
        </p>
      </section>
    </LegalPageLayout>
  )
}
