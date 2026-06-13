import { computed } from 'vue'

/**
 * Aviso de carência só após subscription_ends_at (7 dias para regularizar).
 */
export function buildPaymentGraceWarning(store) {
  if (!store?.is_within_payment_grace) return null

  const renewal = store.subscription_ends_at
  if (renewal && new Date(renewal).getTime() > Date.now()) return null

  const ends = store.payment_grace_ends_at
  if (!ends) {
    return 'Sua assinatura venceu. Regularize em até 7 dias para evitar bloqueio.'
  }

  const date = new Date(ends).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })
  return `Pagamento pendente — regularize até ${date} para manter o painel ativo.`
}

export function usePaymentGraceWarning(storeRef) {
  return computed(() => buildPaymentGraceWarning(storeRef.value))
}
