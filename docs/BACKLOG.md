# PartiuMenu — backlog de produto

Itens identificados em jun/2026. Referência para priorização; não é compromisso de sprint.

## Decisões de produto

- **Status do pedido para o cliente:** WhatsApp (não e-mail). Código de notificação por e-mail removido.
- **Novo pedido para o lojista:** som + tempo real (Echo/Pusher), não e-mail. `NewOrderReceived` removido.

## Prioridade alta

| Item | Situação | Notas |
|------|----------|-------|
| WhatsApp automático (status do pedido) | Implementado | Envia via Evolution na instância da loja quando conectada (Pro/Premium). Ver `OrderWhatsappNotifier`. |
| Pagamento online no app do cliente | Fase 2 implementada | Pix online: checkout + admin toggle + webhook. Rodar migrations e configurar Pagar.me. |

## Prioridade média

| Item | Situação | Notas |
|------|----------|-------|
| Push / PWA para lojista | Ausente | Pedidos dependem de browser aberto + Pusher. PWA + FCM seria alternativa barata ao app nativo. |
| Testes automatizados | Mínimo | Só example test no Laravel. |
| Documentação do projeto | Mínima | READMEs são template Vite. |
| Alinhar `PlanSeeder` com migrations | Drift | Preços/features divergem do que roda em produção. |

## Prioridade baixa

| Item | Situação | Notas |
|------|----------|-------|
| Bot / IA no WhatsApp | Planejado | Comentário no controller: “próxima fase”. Premium promete `whatsapp_bot` / `whatsapp_ai`. |
| Modo cozinha (KDS) | Ausente | — |
| App nativo white-label por loja | Ausente | Considerar add-on Premium+ quando houver demanda. |
| UI de notificações no admin | Ausente | API `/notifications` existe; painel não consome. |

## Mobile — decisão de produto

1. **Curto prazo:** PWA do painel merchant + push web.
2. **Médio prazo:** WhatsApp automático + PIX online no cliente.
3. **Longo prazo:** app nativo só como upsell (Premium+) com caso de uso claro (push confiável, impressora Bluetooth, etc.).
