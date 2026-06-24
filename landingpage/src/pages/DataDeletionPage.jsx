import { useEffect } from 'react'
import LegalPageLayout from '../components/LegalPageLayout.jsx'

const CONTACT_EMAIL = 'alisson.franciscocosta@gmail.com'
const PAGE_URL = 'https://partiumenu.com.br/exclusao-de-dados'

export default function DataDeletionPage() {
  useEffect(() => {
    document.title = 'Exclusão de Dados — PartiuMenu'
    document.querySelector('meta[name="description"]')?.setAttribute(
      'content',
      'Como solicitar a exclusão dos seus dados pessoais no PartiuMenu: lojistas, clientes finais e usuários de login social (Google/Facebook).'
    )
    document.querySelector('link[rel="canonical"]')?.setAttribute('href', PAGE_URL)
  }, [])

  return (
    <LegalPageLayout title="Exclusão de dados" lastUpdated="23 de junho de 2026">
      <section className="space-y-4">
        <p>
          Esta página explica como solicitar a <strong>exclusão dos seus dados pessoais</strong> tratados pelo{' '}
          <strong>PartiuMenu</strong> (“nós”, “plataforma”), em conformidade com a Lei Geral de Proteção de Dados
          (LGPD) e com os requisitos de aplicativos que utilizam login ou integrações da Meta (Facebook/WhatsApp).
        </p>
        <p>
          Para dúvidas gerais sobre privacidade, consulte também a nossa{' '}
          <a href="/privacidade" className="font-bold text-red-600 hover:text-red-700">
            Política de Privacidade
          </a>
          .
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">1. Quem pode solicitar</h2>
        <ul className="list-disc space-y-2 pl-5">
          <li>
            <strong>Lojistas e membros da equipe</strong> com conta no painel administrativo do PartiuMenu;
          </li>
          <li>
            <strong>Clientes finais</strong> que fizeram pedidos em cardápios digitais hospedados na plataforma;
          </li>
          <li>
            <strong>Usuários de login social</strong> (Google ou Facebook), quando utilizaram esses provedores para
            acessar o PartiuMenu.
          </li>
        </ul>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">2. Como solicitar a exclusão</h2>
        <p>Envie um e-mail para:</p>
        <p>
          <a href={`mailto:${CONTACT_EMAIL}?subject=Exclusão de dados — PartiuMenu`} className="font-bold text-red-600 hover:text-red-700">
            {CONTACT_EMAIL}
          </a>
        </p>
        <p>
          <strong>Assunto:</strong> Exclusão de dados — PartiuMenu
        </p>
        <p>Inclua no corpo da mensagem, quando possível:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>nome completo;</li>
          <li>e-mail e/ou telefone (com DDD) usados no cadastro ou nos pedidos;</li>
          <li>se for lojista: nome ou slug da loja no PartiuMenu;</li>
          <li>se aplicável: que você utilizou login com Facebook ou Google;</li>
          <li>breve descrição do que deseja excluir (conta completa, apenas dados de cliente, etc.).</li>
        </ul>
        <p>
          Lojistas com assinatura ativa podem, alternativamente, solicitar o encerramento da conta pelo painel
          administrativo (quando disponível) e confirmar por e-mail para concluirmos a exclusão dos dados
          associados.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">3. Quais dados podem ser excluídos</h2>
        <p>Conforme o tipo de uso, podemos eliminar ou anonimizar, entre outros:</p>
        <ul className="list-disc space-y-2 pl-5">
          <li>dados de cadastro (nome, e-mail, telefone, identificadores de login social);</li>
          <li>endereços de entrega e histórico de pedidos vinculados ao titular;</li>
          <li>configurações da loja, quando a conta do lojista for encerrada;</li>
          <li>tokens e metadados de integrações WhatsApp vinculados à conta solicitante;</li>
          <li>logs e registros que não precisem ser mantidos por obrigação legal.</li>
        </ul>
        <p>
          Dados agregados ou anonimizados que não permitam identificação do titular podem ser mantidos para
          estatísticas e segurança da plataforma.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">4. Prazo e confirmação</h2>
        <p>
          Confirmaremos o recebimento da solicitação por e-mail. Após validarmos sua identidade, processaremos a
          exclusão em até <strong>30 dias</strong>, salvo prazos legais ou contratuais que exijam retenção parcial
          (por exemplo: registros fiscais, comprovantes de pagamento ou defesa em processos).
        </p>
        <p>
          Quando a exclusão for concluída, informaremos por e-mail. Alguns backups podem levar tempo adicional para
          serem sobrescritos em rotinas técnicas de segurança.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">5. Dados tratados pela Meta (Facebook / WhatsApp)</h2>
        <p>
          Se você utilizou o <strong>login com Facebook</strong> ou interagiu com o PartiuMenu por{' '}
          <strong>WhatsApp Business</strong>, a Meta também pode tratar dados conforme suas próprias políticas.
        </p>
        <ul className="list-disc space-y-2 pl-5">
          <li>
            Para remover permissões concedidas ao aplicativo PartiuMenu na sua conta Facebook, acesse as configurações
            de aplicativos e sites da Meta e revogue o acesso ao app.
          </li>
          <li>
            Para dados armazenados diretamente pelo PartiuMenu em decorrência dessas integrações, utilize o
            procedimento de solicitação por e-mail descrito nesta página.
          </li>
        </ul>
        <p>
          Ao remover o app na Meta, você interrompe novos acessos via Facebook; a exclusão dos dados já armazenados
          em nossos sistemas depende da solicitação formal nesta página.
        </p>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-black text-slate-900">6. Controlador e contato</h2>
        <p>
          <strong>Controlador:</strong> PartiuMenu / Alisson Francisco da Costa Pereira
          <br />
          <strong>E-mail:</strong>{' '}
          <a href={`mailto:${CONTACT_EMAIL}`} className="font-bold text-red-600 hover:text-red-700">
            {CONTACT_EMAIL}
          </a>
          <br />
          <strong>Endereço:</strong> Rua Mateus Tavares, 18 — Fortaleza/CE — CEP 60349-490 — Brasil
          <br />
          <strong>URL desta página:</strong>{' '}
          <a href={PAGE_URL} className="font-bold text-red-600 hover:text-red-700">
            {PAGE_URL}
          </a>
        </p>
      </section>
    </LegalPageLayout>
  )
}
